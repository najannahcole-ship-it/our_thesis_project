<?php
// ================================================================
// clerk-receiving.php  —  Stock Receiving Module (Internal Supplier)
// Requires: db.php in the same directory
// ================================================================

session_start();

// 1. Auth guard — Inventory Clerk only (role_id = 3)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: index.php');
    exit();
}

// 2. No-cache headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once __DIR__ . '/db.php';  // $conn available after this

$clerkName   = $_SESSION['full_name'] ?? 'Inventory Clerk';
$clerkUserId = $_SESSION['user_id'];

// ----------------------------------------------------------------
// AJAX handler — POST requests return JSON
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    header('Content-Type: application/json');

    $action = $_POST['action'];

    // ---- Record a new receipt ----
    if ($action === 'record_receipt') {

        $batchNumber  = trim($_POST['batch_number']   ?? '');
        $productId    = (int) ($_POST['product_id']   ?? 0);
        $quantity     = (float)($_POST['quantity']    ?? 0);
        $unit         = trim($_POST['unit']           ?? '');
        $arrivalDate  = trim($_POST['arrival_date']   ?? '');
        $mfgDate      = trim($_POST['mfg_date']       ?? '') ?: null;
        $expiryDate   = trim($_POST['expiry_date']    ?? '') ?: null;
        $qcNotes      = trim($_POST['qc_notes']       ?? '') ?: null;

        // Basic validation
        $errors = [];
        if (!$batchNumber)  $errors[] = 'Batch number is required.';
        if (!$productId)    $errors[] = 'Please select an item.';
        if ($quantity <= 0) $errors[] = 'Quantity must be greater than 0.';
        if (!$unit)         $errors[] = 'Unit is required.';
        if (!$arrivalDate)  $errors[] = 'Arrival date/time is required.';

        if ($errors) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit();
        }

        // Duplicate batch-number guard
        $chk = $conn->prepare("SELECT id FROM stock_receipts WHERE batch_number = ? LIMIT 1");
        $chk->bind_param("s", $batchNumber);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            echo json_encode(['success' => false, 'errors' => ["Batch number '$batchNumber' already exists."]]);
            $chk->close();
            exit();
        }
        $chk->close();

        // Insert receipt
        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("
                INSERT INTO stock_receipts
                    (batch_number, product_id, quantity, unit, source_type,
                     arrival_date, mfg_date, expiry_date, qc_notes, recorded_by)
                VALUES (?, ?, ?, ?, 'Internal', ?, ?, ?, ?, ?)
            ");
            $ins->bind_param("sidsssssi",
                $batchNumber, $productId, $quantity, $unit,
                $arrivalDate, $mfgDate, $expiryDate, $qcNotes, $clerkUserId
            );
            $ins->execute();
            $receiptId = $conn->insert_id;
            $ins->close();

            // Update products.stock_qty
            $upd = $conn->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
            $upd->bind_param("di", $quantity, $productId);
            $upd->execute();
            $upd->close();

            $conn->commit();

            echo json_encode([
                'success'    => true,
                'receipt_id' => $receiptId,
                'message'    => 'Receipt recorded and inventory updated successfully.'
            ]);
        } catch (\Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'errors' => ['Database error: ' . $e->getMessage()]]);
        }
        exit();
    }

    // ---- Fetch single receipt detail ----
    if ($action === 'get_receipt') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT r.*, p.name AS product_name, u.full_name AS recorded_by_name
            FROM stock_receipts r
            LEFT JOIN products p ON p.id = r.product_id
            LEFT JOIN users    u ON u.user_id = r.recorded_by
            WHERE r.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode($row ?: ['error' => 'Not found']);
        exit();
    }

    echo json_encode(['error' => 'Unknown action']);
    exit();
}

// ----------------------------------------------------------------
// Load data for the page
// ----------------------------------------------------------------

// All active products for the dropdown
$products = [];
$res = $conn->query("
    SELECT id, name, unit, category
    FROM products
    WHERE status = 'available'
    ORDER BY category, name
");
while ($row = $res->fetch_assoc()) $products[] = $row;

// Recent receipts (latest 20, Internal only)
$recentReceipts = [];
$res = $conn->query("
    SELECT r.id, r.batch_number, r.quantity, r.unit,
           r.arrival_date, r.mfg_date, r.expiry_date,
           p.name AS product_name,
           u.full_name AS recorded_by_name
    FROM stock_receipts r
    LEFT JOIN products p ON p.id = r.product_id
    LEFT JOIN users    u ON u.user_id = r.recorded_by
    WHERE r.source_type = 'Internal'
    ORDER BY r.arrival_date DESC
    LIMIT 20
");
while ($row = $res->fetch_assoc()) $recentReceipts[] = $row;

// Summary stats
$totalToday   = $conn->query("SELECT COUNT(*) FROM stock_receipts WHERE DATE(arrival_date) = CURDATE() AND source_type = 'Internal'")->fetch_row()[0];
$totalMonth   = $conn->query("SELECT COUNT(*) FROM stock_receipts WHERE MONTH(arrival_date) = MONTH(CURDATE()) AND YEAR(arrival_date) = YEAR(CURDATE()) AND source_type = 'Internal'")->fetch_row()[0];
$totalAllTime = $conn->query("SELECT COUNT(*) FROM stock_receipts WHERE source_type = 'Internal'")->fetch_row()[0];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Receiving - Top Juan Inc.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --background:           #f7f3f0;
            --foreground:           #2d241e;
            --sidebar-bg:           #fdfaf7;
            --card:                 #ffffff;
            --card-border:          #eeeae6;
            --primary:              #5c4033;
            --primary-light:        #8b5e3c;
            --accent:               #d25424;
            --muted:                #8c837d;
            --status-ok-bg:         #ecfdf5;
            --status-ok-text:       #059669;
            --status-warn-bg:       #fffbeb;
            --status-warn-text:     #b45309;
            --status-danger-bg:     #fef2f2;
            --status-danger-text:   #dc2626;
            --radius:               16px;
            --sidebar-width:        280px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--background);
            color: var(--foreground);
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ─────────────────────────────────────────── */
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
            display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2.5rem;
        }
        .logo-icon {
            width: 40px; height: 40px;
            background: var(--primary); border-radius: 10px;
            display: flex; align-items: center; justify-content: center; color: white;
        }
        .logo-text h1 { font-family: 'Fraunces', serif; font-size: 1.25rem; line-height: 1; }
        .logo-text span { font-size: 0.75rem; color: var(--muted); font-weight: 500; }
        .menu-label {
            font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em;
            color: var(--muted); margin-bottom: 1rem; font-weight: 700;
        }
        nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
        .nav-item {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.875rem 1rem; border-radius: 12px;
            text-decoration: none; color: var(--muted); font-weight: 500; font-size: 0.95rem;
            transition: all 0.2s;
        }
        .nav-item i { width: 20px; height: 20px; stroke-width: 2px; }
        .nav-item:hover { color: var(--primary); background: rgba(92,64,51,0.05); }
        .nav-item.active { background: var(--primary); color: white; }
        .user-profile {
            margin-top: auto;
            background: white; border: 1px solid var(--card-border);
            padding: 1rem; border-radius: 16px;
            display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;
        }
        .avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: #e5e7eb; overflow: hidden;
            display: flex; align-items: center; justify-content: center;
        }
        .avatar i { color: var(--muted); }
        .user-meta h4 { font-size: 0.85rem; font-weight: 700; }
        .user-meta p  { font-size: 0.75rem; color: var(--muted); }
        .sign-out {
            display: flex; align-items: center; gap: 0.5rem;
            text-decoration: none; color: var(--muted); font-size: 0.9rem;
            padding: 0.5rem; transition: color 0.2s;
        }
        .sign-out:hover { color: var(--accent); }

        /* ── Main ────────────────────────────────────────────── */
        main {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 2.5rem 3rem;
        }
        .header {
            display: flex; justify-content: space-between; align-items: flex-end;
            margin-bottom: 2.5rem;
        }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p  { color: var(--muted); font-size: 1rem; }

        /* ── Stats row ───────────────────────────────────────── */
        .stats-row {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white; border: 1px solid var(--card-border);
            border-radius: 20px; padding: 1.5rem;
        }
        .stat-card .label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 700; margin-bottom: 0.5rem; }
        .stat-card .value { font-family: 'Fraunces', serif; font-size: 2.25rem; color: var(--primary); }

        /* ── Tabs ────────────────────────────────────────────── */
        .tabs {
            display: flex; gap: 2rem;
            border-bottom: 1px solid var(--card-border); margin-bottom: 2rem;
        }
        .tab {
            padding: 1rem 0.5rem; font-weight: 600; color: var(--muted);
            cursor: pointer; position: relative; text-decoration: none;
            background: none; border: none; font-family: inherit; font-size: 0.95rem;
        }
        .tab.active { color: var(--primary); }
        .tab.active::after {
            content: ''; position: absolute; bottom: -1px; left: 0; right: 0;
            height: 2px; background: var(--primary);
        }

        /* ── Card / Form ─────────────────────────────────────── */
        .card {
            background: white; border: 1px solid var(--card-border);
            border-radius: 24px; padding: 2rem; margin-bottom: 2rem;
        }
        .card-title {
            font-family: 'Fraunces', serif; font-size: 1.5rem;
            margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;
        }
        .form-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 1.5rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .form-group.full-width { grid-column: span 2; }
        .form-group label {
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--muted);
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.875rem 1rem; border-radius: 12px;
            border: 1px solid var(--card-border);
            font-family: inherit; font-size: 0.95rem; background: #faf9f8;
            transition: all 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none; border-color: var(--accent); background: white;
            box-shadow: 0 0 0 4px rgba(210,84,36,0.05);
        }
        .qty-row { display: flex; gap: 0.5rem; }
        .qty-row input  { flex: 1; }
        .qty-row select { width: 110px; }

        .btn-group { display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem; }
        .btn {
            padding: 0.875rem 2rem; border-radius: 12px; font-weight: 600;
            cursor: pointer; transition: all 0.2s;
            display: flex; align-items: center; gap: 0.5rem; font-family: inherit;
        }
        .btn-outline { background: white; border: 1px solid var(--card-border); color: var(--foreground); }
        .btn-outline:hover { background: var(--background); }
        .btn-primary { background: var(--primary); color: white; border: none; }
        .btn-primary:hover { background: var(--primary-light); transform: translateY(-1px); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── Alert / Toast ───────────────────────────────────── */
        .alert-info {
            background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;
            padding: 1rem; border-radius: 12px;
            display: flex; gap: 0.75rem; margin-bottom: 1.5rem; font-size: 0.9rem;
        }
        #toast {
            position: fixed; bottom: 2rem; right: 2rem; z-index: 999;
            padding: 1rem 1.5rem; border-radius: 14px;
            font-weight: 600; font-size: 0.9rem;
            display: flex; align-items: center; gap: 0.75rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            transform: translateY(100px); opacity: 0;
            transition: all 0.35s cubic-bezier(0.34,1.56,0.64,1);
            pointer-events: none;
        }
        #toast.show { transform: translateY(0); opacity: 1; }
        #toast.success { background: var(--status-ok-bg); color: var(--status-ok-text); border: 1px solid #a7f3d0; }
        #toast.error   { background: var(--status-danger-bg); color: var(--status-danger-text); border: 1px solid #fca5a5; }

        /* ── Error list ──────────────────────────────────────── */
        #errorList {
            display: none; background: var(--status-danger-bg);
            border: 1px solid #fca5a5; color: var(--status-danger-text);
            padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; font-size: 0.9rem;
        }
        #errorList ul { padding-left: 1.25rem; }

        /* ── Table ───────────────────────────────────────────── */
        .recent-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .recent-table th {
            text-align: left; padding: 1rem;
            font-size: 0.75rem; text-transform: uppercase; color: var(--muted);
            border-bottom: 1px solid var(--card-border);
        }
        .recent-table td {
            padding: 1rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
        }
        .recent-table tr:last-child td { border-bottom: none; }
        .recent-table tr:hover td { background: var(--background); }
        .status-badge {
            padding: 0.25rem 0.75rem; border-radius: 99px;
            font-size: 0.75rem; font-weight: 600;
            background: var(--status-ok-bg); color: var(--status-ok-text);
        }
        .batch-code { font-weight: 700; font-family: monospace; }
        .empty-state {
            text-align: center; padding: 3rem;
            color: var(--muted); font-size: 0.95rem;
        }
        .action-btn {
            background: none; border: none; color: var(--muted); cursor: pointer;
            padding: 0.35rem; border-radius: 8px; transition: all 0.15s;
        }
        .action-btn:hover { color: var(--primary); background: rgba(92,64,51,0.07); }

        /* ── Modal ───────────────────────────────────────────── */
        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            z-index: 100; align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: white; border-radius: 24px; padding: 2.5rem;
            width: 100%; max-width: 560px; position: relative;
            box-shadow: 0 24px 60px rgba(0,0,0,0.15);
        }
        .modal-close {
            position: absolute; top: 1.25rem; right: 1.25rem;
            background: none; border: none; cursor: pointer; color: var(--muted);
        }
        .modal h3 { font-family: 'Fraunces', serif; font-size: 1.5rem; margin-bottom: 1.5rem; }
        .detail-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 1rem; }
        .detail-item .label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); margin-bottom: 0.25rem; }
        .detail-item .value { font-size: 0.95rem; }
        .detail-item.full { grid-column: span 2; }

        /* ── Coming soon tab content ─────────────────────────── */
        .coming-soon {
            text-align: center; padding: 4rem 2rem; color: var(--muted);
        }
        .coming-soon i { margin-bottom: 1rem; opacity: 0.4; }
        .coming-soon h3 { font-family: 'Fraunces', serif; font-size: 1.5rem; margin-bottom: 0.5rem; color: var(--foreground); }
    </style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────────────────────── -->
<aside>
    <div class="logo-container">
        <div class="logo-icon"><i data-lucide="coffee"></i></div>
        <div class="logo-text">
            <h1>Top Juan</h1>
            <span>Clerk Portal</span>
        </div>
    </div>

    <div class="menu-label">Menu</div>
    <nav>
        <a href="clerk-dashboard.php"   class="nav-item" data-testid="link-clerk-dashboard">
            <i data-lucide="layout-dashboard"></i> Dashboard
        </a>
        <a href="clerk-orders.php"       class="nav-item" data-testid="link-order-monitoring">
            <i data-lucide="clipboard-list"></i> Order Monitoring
        </a>
        <a href="clerk-inventory.php"   class="nav-item" data-testid="link-clerk-inventory">
            <i data-lucide="boxes"></i> Inventory
        </a>
        <a href="clerk-receiving.php"   class="nav-item active" data-testid="link-stock-receiving">
            <i data-lucide="download"></i> Stock Receiving
        </a>
        <a href="clerk-adjustment.php"  class="nav-item" data-testid="link-stock-adjustment">
            <i data-lucide="edit-3"></i> Stock Adjustment
        </a>
        <a href="clerk-reports.php"     class="nav-item" data-testid="link-reports">
            <i data-lucide="bar-chart-3"></i> Reports
        </a>
    </nav>

    <div class="user-profile">
        <div class="avatar"><i data-lucide="user"></i></div>
        <div class="user-meta">
            <h4 data-testid="text-username"><?= htmlspecialchars($clerkName) ?></h4>
            <p>Inventory Clerk</p>
        </div>
    </div>
    <a href="logout.php" class="sign-out" data-testid="button-logout">
        <i data-lucide="log-out"></i> Sign Out
    </a>
</aside>

<!-- ── Main ─────────────────────────────────────────────────── -->
<main>
    <div class="header">
        <div>
            <h2 data-testid="text-page-title">Stock Receiving</h2>
            <p>Record and track incoming stock from manufacturing and internal sources</p>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="label">Receipts Today</div>
            <div class="value"><?= $totalToday ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Receipts This Month</div>
            <div class="value"><?= $totalMonth ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Total Receipts (All-Time)</div>
            <div class="value"><?= $totalAllTime ?></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab active" data-testid="tab-internal-supplier">
            Internal Supplier (Manufacturing)
        </button>

    </div>

    <!-- ── Internal Tab ──────────────────────────────────────── -->
    <div id="tab-internal">

        <!-- Form -->
        <div class="card" data-testid="card-internal-receiving-form">
            <div class="card-title">
                <i data-lucide="factory"></i>
                Internal Manufacturing Receipt
            </div>

            <div class="alert-info">
                <i data-lucide="info" size="20"></i>
                <p>Use this form to record stock received from the internal production unit. Batch numbers must match production logs for traceability.</p>
            </div>

            <!-- Error list (shown on validation fail) -->
            <div id="errorList" role="alert">
                <strong>Please fix the following errors:</strong>
                <ul id="errorItems"></ul>
            </div>

            <form id="receivingForm" novalidate>

                <div class="form-grid">

                    <div class="form-group">
                        <label for="batch_number">Production Batch Number *</label>
                        <input id="batch_number" name="batch_number" type="text"
                               placeholder="e.g., MFG-2026-0303-01"
                               data-testid="input-batch-number" required>
                    </div>

                    <div class="form-group">
                        <label for="product_id">Item Received *</label>
                        <select id="product_id" name="product_id"
                                data-testid="select-received-item" required>
                            <option value="">Select manufactured item…</option>
                            <?php
                            $lastCat = '';
                            foreach ($products as $p):
                                if ($p['category'] !== $lastCat):
                                    if ($lastCat !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($p['category']) . '">';
                                    $lastCat = $p['category'];
                                endif;
                            ?>
                            <option value="<?= $p['id'] ?>"
                                    data-unit="<?= htmlspecialchars($p['unit']) ?>">
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                            <?php endforeach; if ($lastCat) echo '</optgroup>'; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="quantity">Quantity Received *</label>
                        <div class="qty-row">
                            <input id="quantity" name="quantity" type="number"
                                   placeholder="0" min="0.01" step="0.01"
                                   data-testid="input-received-qty" required>
                            <select id="unit" name="unit" data-testid="select-unit">
                                <option value="g">g</option>
                                <option value="kg">kg</option>
                                <option value="ml">ml</option>
                                <option value="L">L</option>
                                <option value="pcs">pcs</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="mfg_date">Manufacturing Date</label>
                        <input id="mfg_date" name="mfg_date" type="date"
                               data-testid="input-mfg-date">
                    </div>

                    <div class="form-group">
                        <label for="expiry_date">Expiry Date</label>
                        <input id="expiry_date" name="expiry_date" type="date"
                               data-testid="input-expiry-date">
                    </div>

                    <div class="form-group full-width">
                        <label for="qc_notes">Quality Control Notes / Condition</label>
                        <textarea id="qc_notes" name="qc_notes" rows="3"
                                  placeholder="Describe the condition of received stock, seal integrity, etc."
                                  data-testid="textarea-qc-notes"></textarea>
                    </div>

                </div><!-- /form-grid -->

                <div class="btn-group">
                    <button type="reset" class="btn btn-outline"
                            data-testid="button-reset-form"
                            onclick="clearErrors()">
                        Clear Form
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitBtn"
                            data-testid="button-record-receipt">
                        <i data-lucide="check-circle"></i> Record Receipt
                    </button>
                </div>

            </form>
        </div><!-- /card form -->

        <!-- Recent Receipts Table -->
        <div class="card" data-testid="card-recent-receipts">
            <div class="card-title" style="font-size:1.25rem;">
                Recent Internal Arrivals
            </div>
            <table class="recent-table" id="receiptsTable">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Item Name</th>
                        <th>Qty Received</th>
                        <th>Expiry Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="receiptsBody">
                <?php if (empty($recentReceipts)): ?>
                    <tr id="emptyRow">
                        <td colspan="7">
                            <div class="empty-state">
                                <i data-lucide="inbox" size="48" style="display:block;margin:0 auto 0.75rem;"></i>
                                No receipts recorded yet. Use the form above to add the first one.
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($recentReceipts as $r): ?>
                    <tr data-id="<?= $r['id'] ?>">
                        <td><span class="batch-code"><?= htmlspecialchars($r['batch_number']) ?></span></td>
                        <td><?= htmlspecialchars($r['product_name']) ?></td>
                        <td><?= htmlspecialchars($r['quantity'] + 0) ?> <?= htmlspecialchars($r['unit']) ?></td>
                        <td><?= date('M d, Y g:i A', strtotime($r['arrival_date'])) ?></td>
                        <td><?= $r['expiry_date'] ? date('M d, Y', strtotime($r['expiry_date'])) : '—' ?></td>
                        <td><span class="status-badge">Recorded</span></td>
                        <td>
                            <button class="action-btn" title="View Details"
                                    onclick="viewReceipt(<?= $r['id'] ?>)">
                                <i data-lucide="eye" size="18"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /tab-internal -->



</main><!-- /main -->

<!-- ── Detail Modal ──────────────────────────────────────────── -->
<div class="modal-overlay" id="detailModal" onclick="closeModal(event)">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <button class="modal-close" onclick="document.getElementById('detailModal').classList.remove('open')">
            <i data-lucide="x" size="22"></i>
        </button>
        <h3 id="modalTitle">Receipt Details</h3>
        <div class="detail-grid" id="modalBody">
            <p style="color:var(--muted)">Loading…</p>
        </div>
    </div>
</div>

<!-- ── Toast ─────────────────────────────────────────────────── -->
<div id="toast" role="status" aria-live="polite"></div>

<script>
lucide.createIcons();

// ── Set arrival datetime to now ──────────────────────────────
(function () {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('arrival_date').value = now.toISOString().slice(0, 16);
})();

// ── Auto-fill unit when item is selected ─────────────────────
document.getElementById('product_id').addEventListener('change', function () {
    const opt  = this.options[this.selectedIndex];
    const unit = opt.dataset.unit;
    if (unit) {
        const sel = document.getElementById('unit');
        // Try to find the matching option; if not found, leave as-is
        for (let i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === unit) { sel.selectedIndex = i; break; }
        }
    }
});

// ── Toast ────────────────────────────────────────────────────
let toastTimer;
function showToast(msg, type = 'success') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className   = 'show ' + type;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = ''; }, 4000);
}

// ── Error display ─────────────────────────────────────────────
function showErrors(errors) {
    const box   = document.getElementById('errorList');
    const items = document.getElementById('errorItems');
    items.innerHTML = errors.map(e => `<li>${e}</li>`).join('');
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function clearErrors() {
    document.getElementById('errorList').style.display = 'none';
    document.getElementById('errorItems').innerHTML = '';
}

// ── Form submit ───────────────────────────────────────────────
document.getElementById('receivingForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    clearErrors();

    const btn  = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2"></i> Saving…';
    lucide.createIcons();

    const fd = new FormData(this);
    fd.append('action', 'record_receipt');

    try {
        const res  = await fetch('clerk-receiving.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            showToast('✓ Receipt recorded & inventory updated!', 'success');

            // Capture product name BEFORE reset clears the dropdown
            const selEl = document.getElementById('product_id');
            const prodName = selEl.options[selEl.selectedIndex]?.text ?? '—';

            this.reset();
            // Reset arrival date
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('arrival_date').value = now.toISOString().slice(0, 16);

            // Prepend new row to table
            prependReceiptRow(data.receipt_id, fd, prodName);
        } else {
            showErrors(data.errors || ['An unknown error occurred.']);
            showToast('Please fix the errors above.', 'error');
        }
    } catch (err) {
        showErrors(['Network error: ' + err.message]);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="check-circle"></i> Record Receipt';
        lucide.createIcons();
    }
});

// ── Prepend new row into table without reload ─────────────────
function prependReceiptRow(id, fd, prodName) {
    const tbody   = document.getElementById('receiptsBody');
    const emptyRow = document.getElementById('emptyRow');
    if (emptyRow) emptyRow.remove();

    const qty      = fd.get('quantity');
    const unit     = fd.get('unit');
    const batch    = fd.get('batch_number');
    const arrival  = new Date(fd.get('arrival_date'));
    const expiry   = fd.get('expiry_date') ? new Date(fd.get('expiry_date')) : null;

    const fmtDate = (d) => d.toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' });
    const fmtDT   = (d) => d.toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' })
                          + ' ' + d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });

    const tr = document.createElement('tr');
    tr.dataset.id = id;
    tr.innerHTML = `
        <td><span class="batch-code">${batch}</span></td>
        <td>${prodName}</td>
        <td>${qty} ${unit}</td>
        <td>${fmtDT(arrival)}</td>
        <td>${expiry ? fmtDate(expiry) : '—'}</td>
        <td><span class="status-badge">Recorded</span></td>
        <td>
            <button class="action-btn" title="View Details" onclick="viewReceipt(${id})">
                <i data-lucide="eye" size="18"></i>
            </button>
        </td>`;

    tbody.insertBefore(tr, tbody.firstChild);
    lucide.createIcons();
}

// ── Modal: view receipt detail ────────────────────────────────
async function viewReceipt(id) {
    const modal = document.getElementById('detailModal');
    const body  = document.getElementById('modalBody');
    body.innerHTML = '<p style="color:var(--muted)">Loading…</p>';
    modal.classList.add('open');

    const fd = new FormData();
    fd.append('action', 'get_receipt');
    fd.append('id', id);

    try {
        const res  = await fetch('clerk-receiving.php', { method: 'POST', body: fd });
        const d    = await res.json();

        if (d.error) { body.innerHTML = `<p style="color:var(--status-danger-text)">${d.error}</p>`; return; }

        const fmt  = (v) => v ?? '—';
        const fmtD = (v) => v ? new Date(v).toLocaleDateString('en-US',{month:'long',day:'2-digit',year:'numeric'}) : '—';
        const fmtDT= (v) => v ? new Date(v).toLocaleString('en-US',{month:'long',day:'2-digit',year:'numeric',hour:'numeric',minute:'2-digit'}) : '—';

        body.innerHTML = `
            <div class="detail-item"><div class="label">Batch Number</div><div class="value">${fmt(d.batch_number)}</div></div>
            <div class="detail-item"><div class="label">Item</div><div class="value">${fmt(d.product_name)}</div></div>
            <div class="detail-item"><div class="label">Quantity</div><div class="value">${fmt(d.quantity)} ${fmt(d.unit)}</div></div>
            <div class="detail-item"><div class="label">Source</div><div class="value">${fmt(d.source_type)}</div></div>
            <div class="detail-item"><div class="label">Arrival Date</div><div class="value">${fmtDT(d.arrival_date)}</div></div>
            <div class="detail-item"><div class="label">Manufacturing Date</div><div class="value">${fmtD(d.mfg_date)}</div></div>
            <div class="detail-item"><div class="label">Expiry Date</div><div class="value">${fmtD(d.expiry_date)}</div></div>
            <div class="detail-item"><div class="label">Recorded By</div><div class="value">${fmt(d.recorded_by_name)}</div></div>
            <div class="detail-item full"><div class="label">QC Notes</div><div class="value">${fmt(d.qc_notes)}</div></div>
        `;
    } catch (err) {
        body.innerHTML = `<p style="color:var(--status-danger-text)">Failed to load: ${err.message}</p>`;
    }
}

// Close modal when clicking outside
function closeModal(e) {
    if (e.target === document.getElementById('detailModal')) {
        document.getElementById('detailModal').classList.remove('open');
    }
}
</script>
</body>
</html>