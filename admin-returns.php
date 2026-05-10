<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: index.php');
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/db.php';

$adminName   = $_SESSION['full_name'] ?? 'System Admin';
$adminUserId = $_SESSION['user_id'];

// ── AJAX handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── Get single return detail ──────────────────────────────
    if ($action === 'get_return') {
        $id = (int)($_POST['id'] ?? 0);

        // Fetch the primary row
        $stmt = $conn->prepare("
            SELECT r.*, f.branch_name,
                   COALESCE(f.franchisee_name, uf.full_name, '—') AS franchisee_name,
                   o.po_number, o.assigned_encoder_id,
                   enc.full_name AS encoder_name
            FROM returns r
            LEFT JOIN franchisees f  ON f.id         = r.franchisee_id
            LEFT JOIN users      uf  ON uf.user_id   = f.user_id
            LEFT JOIN orders      o  ON o.id          = r.order_id
            LEFT JOIN users      enc ON enc.user_id  = o.assigned_encoder_id
            WHERE r.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) { echo json_encode(['error' => 'Not found']); exit(); }

        // Fetch ALL items in the same batch (same franchisee + order + within same minute)
        $batchMinute = floor(strtotime($row['submitted_at']) / 60);
        $tsFrom = date('Y-m-d H:i:s', $batchMinute * 60);
        $tsTo   = date('Y-m-d H:i:s', ($batchMinute + 1) * 60);

        $sibling = $conn->prepare("
            SELECT id, item_name, reason
            FROM returns
            WHERE franchisee_id = ?
              AND (order_id = ? OR (order_id IS NULL AND ? IS NULL))
              AND submitted_at >= ? AND submitted_at < ?
            ORDER BY id ASC
        ");
        $sibling->bind_param("iiiss", $row['franchisee_id'], $row['order_id'], $row['order_id'], $tsFrom, $tsTo);
        $sibling->execute();
        $siblings = $sibling->get_result()->fetch_all(MYSQLI_ASSOC);
        $sibling->close();

        $row['batch_items'] = $siblings ?: [['id' => $row['id'], 'item_name' => $row['item_name'], 'reason' => $row['reason']]];

        echo json_encode($row);
        exit();
    }

    // ── Update return status (Approve / Reject) ───────────────
    if ($action === 'update_status') {
        $id         = (int)   ($_POST['id']      ?? 0);
        $newStatus  = trim($_POST['status']       ?? '');
        $remarks    = trim($_POST['remarks']      ?? '');
        $resMethod  = trim($_POST['res_method']   ?? '');

        $allowed = ['Approved', 'Rejected', 'Resolved'];
        if (!$id || !in_array($newStatus, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid request.']);
            exit();
        }
        if (!$remarks) {
            echo json_encode(['success' => false, 'error' => 'Admin remarks are required.']);
            exit();
        }

        $resolvedAt = in_array($newStatus, ['Approved', 'Rejected', 'Resolved']) ? date('Y-m-d H:i:s') : null;

        $notes = $resMethod ? "[$resMethod] $remarks" : $remarks;

        // If GCash/Bank refund and approved, look up the encoder and append forwarding note
        if ($newStatus === 'Approved' && $resMethod === 'gcash') {
            // Get the order's assigned encoder for this return
            $chk = $conn->prepare("
                SELECT u.full_name, u.email
                FROM returns r
                JOIN orders o ON o.id = r.order_id
                JOIN users  u ON u.user_id = o.assigned_encoder_id
                WHERE r.id = ? AND o.assigned_encoder_id IS NOT NULL
            ");
            $chk->bind_param("i", $id);
            $chk->execute();
            $encRow = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($encRow) {
                $notes .= ' | Forwarded to Encoder: ' . $encRow['full_name'] . ' for GCash/Bank Transfer Refund processing.';
            }
        }

        $stmt = $conn->prepare("
            UPDATE returns
            SET status = ?, notes = ?, resolved_at = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sssi", $newStatus, $notes, $resolvedAt, $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            echo json_encode(['success' => true, 'message' => "Return #$id has been $newStatus."]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No record updated. It may already be processed.']);
        }
        exit();
    }

    echo json_encode(['error' => 'Unknown action']);
    exit();
}

// ── Stats ────────────────────────────────────────────────────
$statPending  = $conn->query("SELECT COUNT(*) FROM returns WHERE status = 'Pending'")->fetch_row()[0];
$statReview   = $conn->query("SELECT COUNT(*) FROM returns WHERE status = 'Approved'")->fetch_row()[0];
$statResolved = $conn->query("SELECT COUNT(*) FROM returns WHERE status = 'Resolved'")->fetch_row()[0];
$statRejected = $conn->query("SELECT COUNT(*) FROM returns WHERE status = 'Rejected'")->fetch_row()[0];

// ── Returns list ─────────────────────────────────────────────
$filter = $_GET['status'] ?? '';
$whereClause = $filter ? "WHERE r.status = '" . $conn->real_escape_string($filter) . "'" : '';

// Fetch all return rows
$rawReturns = [];
$res = $conn->query("
    SELECT r.id, r.order_id, r.franchisee_id, r.item_name, r.reason, r.status,
           r.submitted_at, r.resolved_at, r.notes,
           f.branch_name,
           COALESCE(f.franchisee_name, uf.full_name, '—') AS franchisee_name,
           o.po_number
    FROM returns r
    LEFT JOIN franchisees f  ON f.id       = r.franchisee_id
    LEFT JOIN users      uf  ON uf.user_id = f.user_id
    LEFT JOIN orders      o  ON o.id       = r.order_id
    $whereClause
    ORDER BY
        CASE r.status WHEN 'Pending' THEN 1 WHEN 'Approved' THEN 2 WHEN 'Resolved' THEN 3 ELSE 4 END,
        r.submitted_at DESC,
        r.franchisee_id ASC,
        r.order_id ASC
");
while ($row = $res->fetch_assoc()) $rawReturns[] = $row;

// Group into batches: same franchisee + order + within same minute = one submission
$batches = [];
foreach ($rawReturns as $r) {
    $key = $r['franchisee_id'] . '_' . ($r['order_id'] ?? 'none') . '_' . floor(strtotime($r['submitted_at']) / 60);
    if (!isset($batches[$key])) {
        $batches[$key] = [
            'ids'             => [],
            'franchisee_name'=> $r['franchisee_name'],
            'branch_name'    => $r['branch_name'],
            'po_number'      => $r['po_number'],
            'submitted_at'   => $r['submitted_at'],
            'resolved_at'    => $r['resolved_at'],
            'status'         => $r['status'],
            'notes'          => $r['notes'],
            'items'          => [],
        ];
    }
    $batches[$key]['ids'][]   = $r['id'];
    $batches[$key]['items'][] = ['name' => $r['item_name'], 'reason' => $r['reason']];
    // Surface the "earliest" status so pending beats resolved
    $rank = ['Pending'=>0,'Approved'=>1,'Resolved'=>2,'Rejected'=>3];
    if (($rank[$r['status']] ?? 9) < ($rank[$batches[$key]['status']] ?? 9)) {
        $batches[$key]['status'] = $r['status'];
    }
}
$batches = array_values($batches);
$returns = $batches; // keep $returns name for empty check below
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returns & Refunds - Top Juan Inc.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --background: #f7f3f0;
            --foreground: #2d241e;
            --sidebar-bg: #fdfaf7;
            --card: #ffffff;
            --card-border: #eeeae6;
            --primary: #5c4033;
            --primary-light: #8b5e3c;
            --accent: #d25424;
            --muted: #8c837d;
            --status-review-bg: #fffbeb;
            --status-review-text: #b45309;
            --status-pickup-bg: #f0fdf4;
            --status-pickup-text: #166534;
            --radius: 16px;
            --sidebar-width: 280px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--background);
            color: var(--foreground);
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        aside {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            padding: 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 10;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 2.5rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .logo-text h1 {
            font-family: 'Fraunces', serif;
            font-size: 1.25rem;
            line-height: 1;
        }
        .logo-text span {
            font-size: 0.75rem;
            color: var(--muted);
            font-weight: 500;
        }

        .menu-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            margin-bottom: 1rem;
            font-weight: 700;
        }

        nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            border-radius: 12px;
            text-decoration: none;
            color: var(--muted);
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .nav-item i { width: 20px; height: 20px; stroke-width: 2px; }
        .nav-item:hover { color: var(--primary); background: rgba(92, 64, 51, 0.05); }
        .nav-item.active {
            background: var(--primary);
            color: white;
        }

        .user-profile {
            margin-top: auto;
            background: white;
            border: 1px solid var(--card-border);
            padding: 1rem;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e5e7eb;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .avatar i { color: var(--muted); }
        
        .user-meta h4 { font-size: 0.85rem; font-weight: 700; }
        .user-meta p { font-size: 0.75rem; color: var(--muted); }

        .sign-out {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--muted);
            font-size: 0.9rem;
            padding: 0.5rem;
            transition: color 0.2s;
        }
        .sign-out:hover { color: var(--accent); }
/* Main Content */

        main {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 2.5rem 3rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
        }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p { color: var(--muted); font-size: 1rem; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 20px;
            border: 1px solid var(--card-border);
        }
        .stat-card .label { font-size: 0.85rem; color: var(--muted); margin-bottom: 0.5rem; }
        .stat-card .value { font-family: 'Fraunces', serif; font-size: 1.75rem; font-weight: 700; }

        .card {
            background: white;
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.5rem; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); }
        td { padding: 1.25rem 1rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border); }
        
        .status-pill {
            padding: 0.25rem 0.75rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .status-pending { background: #fffbeb; color: #d97706; }
        .status-reviewing { background: #eff6ff; color: #2563eb; }
        .status-approved { background: #ecfdf5; color: #059669; }
        .status-rejected { background: #fef2f2; color: #dc2626; }
        .status-resolved { background: #f9fafb; color: #6b7280; border: 1px solid #e5e7eb; }

        .btn-action {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            background: white;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
        }
        .btn-action:hover { background: var(--primary); color: white; border-color: var(--primary); }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(45, 36, 30, 0.4);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 100;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: white;
            width: 100%;
            max-width: 1100px;
            max-height: 95vh;
            border-radius: 24px;
            overflow-y: auto;
            position: relative;
            padding: 2.5rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .modal-header {
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .modal-header h2 { font-family: 'Fraunces', serif; font-size: 1.75rem; margin-bottom: 0.5rem; }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted);
            padding: 0.5rem;
            border-radius: 50%;
            transition: background 0.2s;
        }
        .modal-close:hover { background: #f3f4f6; }

        .modal-body {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 2.5rem;
        }

        .section-title {
            font-family: 'Fraunces', serif;
            font-size: 1.1rem;
            margin-bottom: 1.25rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-group {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .field { margin-bottom: 1rem; }
        .field-label { font-size: 0.75rem; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.35rem; display: block; }
        .field-value { font-size: 0.95rem; font-weight: 500; color: var(--foreground); }
        .field-value.reason { background: #f9fafb; padding: 1.25rem; border-radius: 12px; border: 1px solid var(--card-border); line-height: 1.6; font-size: 0.9rem; }

        .evidence-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .evidence-card {
            border: 1px solid var(--card-border);
            border-radius: 12px;
            overflow: hidden;
            background: #f3f4f6;
            cursor: pointer;
            transition: all 0.2s;
        }
        .evidence-card:hover { transform: translateY(-3px); border-color: var(--accent); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .evidence-preview {
            aspect-ratio: 4/3;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e5e7eb;
            color: var(--muted);
        }
        .evidence-label {
            padding: 0.75rem;
            font-size: 0.7rem;
            font-weight: 600;
            background: white;
            text-align: center;
            border-top: 1px solid var(--card-border);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Sidebar in Modal (Timeline & Actions) */
        .modal-aside {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .timeline {
            position: relative;
            padding-left: 1.5rem;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 5px;
            top: 5px;
            bottom: 5px;
            width: 2px;
            background: var(--card-border);
        }
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .timeline-dot {
            position: absolute;
            left: -21px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--muted);
            border: 2px solid white;
        }
        .timeline-dot.active { background: var(--primary); box-shadow: 0 0 0 4px rgba(56, 44, 36, 0.1); }
        .timeline-content h5 { font-size: 0.85rem; font-weight: 700; margin-bottom: 0.15rem; }
        .timeline-content p { font-size: 0.75rem; color: var(--muted); }

        .resolution-box {
            background: #fdfaf7;
            padding: 1.75rem;
            border-radius: 20px;
            border: 1px solid var(--card-border);
        }
        .resolution-box h4 { font-family: 'Fraunces', serif; margin-bottom: 1.25rem; font-size: 1.05rem; }

        select, textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            font-family: inherit;
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
            background: white;
            transition: border-color 0.2s;
        }
        select:focus, textarea:focus { outline: none; border-color: var(--primary); }
        textarea { height: 140px; resize: none; }

        .modal-footer {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .btn {
            width: 100%;
            padding: 1rem;
            border-radius: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            font-size: 0.95rem;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(56, 44, 36, 0.15); box-shadow: 0 4px 12px rgba(56, 44, 36, 0.15); }
        .btn-secondary { background: #f3f4f6; color: var(--foreground); }
        .btn-secondary:hover { background: #e5e7eb; }
        .btn-outline-error { background: transparent; color: var(--error); border: 2px solid var(--error); }
        .btn-outline-error:hover { background: #fef2f2; }
    </style>
</head>
<body>
    <aside>
        <div class="logo-container">
            <div class="logo-icon"><i data-lucide="coffee"></i></div>
            <div class="logo-text">
                <h1>Top Juan</h1>
                <span>Admin Portal</span>
            </div>
        </div>

        <div class="menu-label">Menu</div>
        <nav>
            <a href="admin-dashboard.php" class="nav-item" data-testid="link-dashboard"><i data-lucide="layout-dashboard"></i> Dashboard</a>
            <a href="admin-orders.php" class="nav-item" data-testid="link-orders"><i data-lucide="clipboard-list"></i> Order Request</a>
            <a href="admin-usage.php" class="nav-item" data-testid="link-usage"><i data-lucide="activity"></i> Item Usage</a>
            <a href="admin-maintenance.php" class="nav-item" data-testid="link-maintenance"><i data-lucide="settings-2"></i> Maintenance</a>
            <a href="admin-inventory.php" class="nav-item" data-testid="link-inventory"><i data-lucide="boxes"></i> Inventory</a>
            <a href="admin-returns.php" class="nav-item active" data-testid="link-returns"><i data-lucide="rotate-ccw"></i> Return and Refund</a>
            <a href="admin-delivery.php" class="nav-item" data-testid="link-delivery"><i data-lucide="truck"></i> Delivery</a>
            <a href="admin-reports.php" class="nav-item" data-testid="link-reports"><i data-lucide="bar-chart-3"></i> Report</a>
        </nav>

        <div class="user-profile">
            <div class="avatar"><i data-lucide="user-cog"></i></div>
            <div class="user-meta">
                <h4 data-testid="text-username"><?= htmlspecialchars($adminName) ?></h4>
                <p>System Administrator</p>
            </div>
        </div>
        <a href="logout.php" class="sign-out" data-testid="button-logout"><i data-lucide="log-out"></i> Sign Out</a>
    </aside>

    <main>
        <div class="header">
            <div>
                <h2 data-testid="text-page-title">Returns & Refunds</h2>
                <p>Review and process franchisee return requests with evidence assessment</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card" data-testid="card-pending-returns">
                <p class="label">Pending Requests</p>
                <div class="value"><?= $statPending ?></div>
            </div>
            <div class="stat-card" data-testid="card-reviewing">
                <p class="label">Approved</p>
                <div class="value" style="color:#059669;"><?= $statReview ?></div>
            </div>
            <div class="stat-card" data-testid="card-total-refunded">
                <p class="label">Resolved</p>
                <div class="value" style="color:#6b7280;"><?= $statResolved ?></div>
            </div>
            <div class="stat-card" data-testid="card-return-rate">
                <p class="label">Rejected</p>
                <div class="value" style="color:#dc2626;"><?= $statRejected ?></div>
            </div>
        </div>

        <!-- Status Filter Tabs -->
        <div style="display:flex;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap;">
            <?php
            $tabs = ['' => 'All', 'Pending' => 'Pending', 'Approved' => 'Approved', 'Resolved' => 'Resolved', 'Rejected' => 'Rejected'];
            foreach ($tabs as $val => $label):
                $active = ($filter === $val) ? 'background:var(--primary);color:white;border-color:var(--primary);' : '';
            ?>
            <a href="admin-returns.php<?= $val ? '?status='.urlencode($val) : '' ?>"
               style="padding:0.5rem 1.1rem;border-radius:99px;border:1px solid var(--card-border);font-size:0.85rem;font-weight:600;text-decoration:none;color:var(--foreground);<?= $active ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="card" data-testid="card-returns-table">
            <div class="card-header">
                <h3>Request Queue <?= $filter ? '— '.$filter : '' ?></h3>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Franchisee</th>
                        <th>Items & Reasons</th>
                        <th>PO Number</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($batches)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:3rem;">No return requests found.</td></tr>
                <?php else: foreach ($batches as $b):
                    $firstId     = $b['ids'][0];
                    $statusClass = match($b['status']) {
                        'Pending'  => 'status-pending',
                        'Approved' => 'status-approved',
                        'Resolved' => 'status-resolved',
                        'Rejected' => 'status-rejected',
                        default    => 'status-pending'
                    };
                    $statusIcon = match($b['status']) {
                        'Pending'  => 'clock',
                        'Approved' => 'check-circle',
                        'Resolved' => 'check-check',
                        'Rejected' => 'x-circle',
                        default    => 'clock'
                    };
                    $actionLabel = in_array($b['status'], ['Pending','Approved']) ? 'Process' : 'View';
                    $actionIcon  = in_array($b['status'], ['Pending','Approved']) ? 'clipboard-check' : 'eye';
                ?>
                    <tr data-id="<?= $firstId ?>">
                        <td>
                            <strong>#<?= str_pad($firstId, 4, '0', STR_PAD_LEFT) ?></strong><br>
                            <small style="color:var(--muted)"><?= date('M d, Y', strtotime($b['submitted_at'])) ?></small>
                        </td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($b['franchisee_name'] ?? '—') ?></div>
                            <div style="font-size:.78rem;color:var(--muted);"><?= htmlspecialchars($b['branch_name'] ?? '') ?></div>
                        </td>
                        <td>
                            <?php foreach ($b['items'] as $item): ?>
                            <div style="font-size:.85rem;padding:.15rem 0;display:flex;align-items:center;gap:.4rem;">
                                <span style="font-weight:600;"><?= htmlspecialchars($item['name']) ?></span>
                                <span style="color:var(--muted);font-size:.75rem;">— <?= htmlspecialchars($item['reason']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </td>
                        <td><?= $b['po_number'] ? htmlspecialchars($b['po_number']) : '—' ?></td>
                        <td><span class="status-pill <?= $statusClass ?>">
                            <i data-lucide="<?= $statusIcon ?>" size="14"></i> <?= $b['status'] ?>
                        </span></td>
                        <td>
                            <button class="btn-action" onclick="openModal(<?= $firstId ?>)">
                                <i data-lucide="<?= $actionIcon ?>" size="16"></i> <?= $actionLabel ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Detailed Review Modal -->
    <div class="modal-overlay" id="reviewModal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 id="modalTitle">Process Return Request</h2>
                    <p id="modalSubtitle">#RET-901 • Juan Coffee - Makati</p>
                </div>
                <button class="modal-close" onclick="closeModal()"><i data-lucide="x"></i></button>
            </div>

            <div class="modal-body">
                <div class="main-content">
                    <section class="info-group">
                        <div class="field">
                            <span class="field-label">Franchise Location</span>
                            <div class="field-value" id="modalFranchise">—</div>
                        </div>
                        <div class="field">
                            <span class="field-label">Submitted At</span>
                            <div class="field-value" id="modalSubmitted">—</div>
                        </div>
                        <div class="field">
                            <span class="field-label">Item(s) for Return</span>
                            <div id="modalItemsList">—</div>
                        </div>
                        <div class="field">
                            <span class="field-label">PO Number</span>
                            <div class="field-value" id="modalPO" style="font-weight:700;">—</div>
                        </div>
                    </section>

                    <section class="form-section">
                        <h4 class="section-title"><i data-lucide="image" size="18"></i> Franchisee Receipt Photo</h4>
                        <div id="modalReceiptWrap" style="display:none;">
                            <div id="modalReceiptNumber" style="font-size:.8rem;color:var(--muted);margin-bottom:.5rem;"></div>
                            <img id="modalReceiptImg" src="" alt="Receipt Photo"
                                style="width:100%;max-height:220px;object-fit:contain;border-radius:10px;border:1px solid var(--card-border);cursor:zoom-in;background:#f9fafb;"
                                onclick="window.open(this.src,'_blank')">
                            <div style="font-size:.72rem;color:var(--muted);margin-top:.35rem;">Click image to open full size</div>
                        </div>
                        <div id="modalReceiptNone" style="font-size:.85rem;color:var(--muted);font-style:italic;">No receipt photo attached.</div>
                    </section>

                    <section class="form-section">
                        <h4 class="section-title"><i data-lucide="message-square" size="18"></i> Additional Notes</h4>
                        <div class="field-value reason" id="modalNotes" style="color:var(--muted);font-style:italic;">No additional notes.</div>
                    </section>
                </div>

                <div class="modal-aside">
                    <section>
                        <h4 class="section-title"><i data-lucide="history" size="18"></i> Case Progress</h4>
<div class="timeline" id="modalTimeline"></div>
                    </section>

                    <section class="resolution-box">
                        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.5rem;">
                            <div style="width:32px;height:32px;background:var(--primary);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i data-lucide="clipboard-check" size="16" style="color:white;"></i>
                            </div>
                            <h4 style="font-family:'Fraunces',serif;font-size:1.05rem;margin:0;">Fulfillment & Resolution</h4>
                        </div>

                        <label class="field-label">Resolution Method</label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:1.25rem;">
                            <label id="opt-replacement" onclick="selectResMethod('replacement')" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:1rem;border:2px solid var(--primary);border-radius:12px;cursor:pointer;background:rgba(92,64,51,.05);text-align:center;">
                                <div style="width:36px;height:36px;background:var(--primary);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i data-lucide="package" size="16" style="color:white;"></i>
                                </div>
                                <div>
                                    <div style="font-weight:700;font-size:.85rem;color:var(--foreground);">Replacement</div>
                                    <div style="font-size:.72rem;color:var(--muted);">Send new product</div>
                                </div>
                            </label>
                            <label id="opt-gcash" onclick="selectResMethod('gcash')" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:1rem;border:2px solid var(--card-border);border-radius:12px;cursor:pointer;background:white;text-align:center;">
                                <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i data-lucide="banknote" size="16" style="color:#1d4ed8;"></i>
                                </div>
                                <div>
                                    <div style="font-weight:700;font-size:.85rem;color:var(--foreground);">GCash/Bank Refund</div>
                                    <div style="font-size:.72rem;color:var(--muted);">Transfer refund</div>
                                </div>
                            </label>
                        </div>
                        <input type="hidden" id="resMethod" value="replacement">

                        <div id="encoderNotice" style="display:none;margin-bottom:1.25rem;background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #93c5fd;border-radius:12px;padding:1rem 1.125rem;">
                            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.35rem;">
                                <i data-lucide="send" size="14" style="color:#1d4ed8;flex-shrink:0;"></i>
                                <span style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#1d4ed8;">Forwarding to Encoder</span>
                            </div>
                            <div style="font-size:.875rem;color:#1e40af;font-weight:600;" id="encoderNameSpan">—</div>
                            <div style="font-size:.78rem;color:#3b82f6;margin-top:.15rem;">Will process the GCash/Bank Transfer Refund</div>
                        </div>

                        <label class="field-label">Admin Remarks & Instructions</label>
                        <textarea id="adminRemarks" rows="4" placeholder="Enter your reasoning or specific instructions..." style="resize:none;"></textarea>

                        <div id="modalActionFooter" style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:.25rem;">
                            <button class="btn btn-primary" onclick="handleAction('approve')" style="justify-content:center;align-items:center;">
                                <i data-lucide="check-circle" size="16"></i> Approve
                            </button>
                            <button class="btn btn-outline-error" onclick="handleAction('reject')" style="justify-content:center;align-items:center;">
                                <i data-lucide="x-circle" size="16"></i> Reject
                            </button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        let currentReturnId  = null;
        let currentStatus    = null;
        let currentEncoderName = null;

        function selectResMethod(val) {
            document.getElementById('resMethod').value = val;
            const opts = { replacement: 'opt-replacement', gcash: 'opt-gcash' };
            Object.entries(opts).forEach(([k, id]) => {
                const el = document.getElementById(id);
                if (!el) return;
                if (k === val) {
                    el.style.border     = '2px solid var(--primary)';
                    el.style.background = 'rgba(92,64,51,.05)';
                } else {
                    el.style.border     = '2px solid var(--card-border)';
                    el.style.background = 'white';
                }
            });
            onResMethodChange(val);
        }

        function onResMethodChange(val) {
            const notice = document.getElementById('encoderNotice');
            if (val === 'gcash' && currentEncoderName) {
                document.getElementById('encoderNameSpan').textContent = currentEncoderName;
                notice.style.display = 'block';
            } else {
                notice.style.display = 'none';
            }
        }

        // ── Open modal & fetch data ───────────────────────────
        async function openModal(id) {
            currentReturnId = id;
            document.getElementById('modalSubtitle').textContent = `#${String(id).padStart(4,'0')} • Loading…`;
            document.getElementById('reviewModal').classList.add('active');

            // Reset fields
            ['modalFranchise','modalPO','modalSubmitted','modalNotes'].forEach(el => {
                document.getElementById(el).textContent = 'Loading…';
            });
            document.getElementById('modalItemsList').innerHTML = '<span style="color:var(--muted);">Loading…</span>';

            const fd = new FormData();
            fd.append('action', 'get_return');
            fd.append('id', id);

            try {
                const res  = await fetch('admin-returns.php', { method: 'POST', body: fd });
                const d    = await res.json();
                if (d.error) { alert('Error: ' + d.error); closeModal(); return; }

                currentStatus = d.status;

                const fmtDT = v => v ? new Date(v).toLocaleString('en-US',{month:'short',day:'2-digit',year:'numeric',hour:'numeric',minute:'2-digit'}) : '—';

                document.getElementById('modalSubtitle').textContent    = `#${String(d.id).padStart(4,'0')} • ${d.franchisee_name ?? d.branch_name ?? '—'}`;
                document.getElementById('modalFranchise').textContent   = (d.franchisee_name ?? '—') + (d.branch_name ? ' — ' + d.branch_name : '');

                // Store encoder for forwarding notice
                currentEncoderName = d.encoder_name ?? null;
                document.getElementById('encoderNotice').style.display = 'none';
                selectResMethod('replacement');
                // Items list — one row per item with its reason
                const itemsList = document.getElementById('modalItemsList');
                if (d.batch_items && d.batch_items.length > 0) {
                    itemsList.innerHTML = d.batch_items.map((it, i) => `
                        <div style="display:flex;flex-direction:column;padding:.6rem .75rem;background:#f9fafb;border-radius:8px;border:1px solid var(--card-border);${i > 0 ? 'margin-top:.5rem;' : ''}">
                            <div style="font-weight:600;font-size:.9rem;">${it.item_name ?? '—'}</div>
                            <div style="font-size:.78rem;color:var(--muted);margin-top:.2rem;">
                                <span style="background:#fef3c7;color:#92400e;border-radius:4px;padding:.1rem .4rem;font-weight:600;font-size:.72rem;">${it.reason ?? '—'}</span>
                            </div>
                        </div>`).join('');
                } else {
                    itemsList.innerHTML = '<span style="color:var(--muted);">—</span>';
                }
                document.getElementById('modalPO').textContent          = d.po_number   ?? '—';
                document.getElementById('modalSubmitted').textContent   = fmtDT(d.submitted_at);
                document.getElementById('modalNotes').textContent       = d.notes       || 'No additional notes.';

                // Receipt photo
                const receiptWrap = document.getElementById('modalReceiptWrap');
                const receiptNone = document.getElementById('modalReceiptNone');
                if (d.receipt_photo) {
                    document.getElementById('modalReceiptImg').src = d.receipt_photo;
                    document.getElementById('modalReceiptNumber').textContent = d.receipt_number ? 'Receipt #: ' + d.receipt_number : '';
                    receiptWrap.style.display = 'block';
                    receiptNone.style.display = 'none';
                } else {
                    receiptWrap.style.display = 'none';
                    receiptNone.style.display = 'block';
                }

                // Timeline
                const tl = document.getElementById('modalTimeline');
                const tlItems = [
                    { label: 'Request Submitted',   detail: fmtDT(d.submitted_at), active: true },
                    { label: d.status === 'Approved' ? 'Approved' : d.status === 'Rejected' ? 'Rejected' : 'Awaiting Decision',
                      detail: d.resolved_at ? fmtDT(d.resolved_at) : 'Pending', active: !!d.resolved_at },
                ];
                tl.innerHTML = tlItems.map(item => `
                    <div class="timeline-item">
                        <div class="timeline-dot ${item.active ? 'active' : ''}"></div>
                        <div class="timeline-content">
                            <h5>${item.label}</h5>
                            <p>${item.detail}</p>
                        </div>
                    </div>`).join('');

                // Show/hide action buttons based on status
                const footer = document.getElementById('modalActionFooter');
                if (footer) {
                    footer.style.display = ['Resolved','Rejected'].includes(d.status) ? 'none' : 'grid';
                }

                lucide.createIcons();
            } catch (err) {
                alert('Network error: ' + err.message);
                closeModal();
            }
        }

        function closeModal() {
            document.getElementById('reviewModal').classList.remove('active');
            currentReturnId = null;
        }

        // ── Approve / Reject ──────────────────────────────────
        async function handleAction(type) {
            if (!currentReturnId) return;

            const remarks   = document.getElementById('adminRemarks').value.trim();
            const resMethod = document.getElementById('resMethod').value;

            if (!remarks) {
                alert('Please provide admin remarks before submitting.');
                document.getElementById('adminRemarks').focus();
                return;
            }

            const newStatus = type === 'approve' ? 'Approved' : 'Rejected';
            if (!confirm(`Are you sure you want to ${type} this return request?`)) return;

            const fd = new FormData();
            fd.append('action',     'update_status');
            fd.append('id',         currentReturnId);
            fd.append('status',     newStatus);
            fd.append('remarks',    remarks);
            fd.append('res_method', resMethod);

            try {
                const res  = await fetch('admin-returns.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    alert(data.message);
                    closeModal();
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Network error: ' + err.message);
            }
        }

        // Close on overlay click
        document.getElementById('reviewModal').onclick = (e) => {
            if (e.target === document.getElementById('reviewModal')) closeModal();
        };
    </script>
</body>
</html>