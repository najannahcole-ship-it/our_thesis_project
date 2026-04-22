<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: index.php');
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$clerkName = $_SESSION['full_name'] ?? 'Inventory Clerk';

// ─── AJAX: Fetch inventory table ─────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'fetch_inventory') {
    header('Content-Type: application/json');

    $search   = trim($_GET['search']   ?? '');
    $category = trim($_GET['category'] ?? '');

    $where  = [];
    $types  = '';
    $params = [];

    if ($search !== '') {
        $where[]  = "(p.name LIKE ? OR p.category LIKE ?)";
        $types   .= 'ss';
        $like     = "%$search%";
        $params[] = &$like;
        $params[] = &$like;
    }
    if ($category !== '') {
        $where[]  = "p.category = ?";
        $types   .= 's';
        $params[] = &$category;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT
            p.id, p.name, p.category, p.unit, p.stock_qty, p.status,
            b.batch_number, b.received_date, b.expiry_date, b.batch_qty, b.remaining_qty
        FROM products p
        LEFT JOIN inventory_batches b ON b.product_id = p.id
            AND b.id = (
                SELECT id FROM inventory_batches
                WHERE product_id = p.id AND remaining_qty > 0
                ORDER BY received_date ASC
                LIMIT 1
            )
        $whereSql
        ORDER BY p.category, p.name
    ";

    $stmt = $conn->prepare($sql);
    if ($types && $params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $today = new DateTime();
    $soon  = (new DateTime())->modify('+7 days');

    foreach ($rows as &$row) {
        $stock = (int) $row['stock_qty'];
        if ($row['expiry_date']) {
            $exp = new DateTime($row['expiry_date']);
            if      ($exp < $today) $row['stock_status'] = 'expired';
            elseif  ($exp <= $soon) $row['stock_status'] = 'expiring_soon';
            elseif  ($stock <= 5)   $row['stock_status'] = 'critical';
            elseif  ($stock <= 20)  $row['stock_status'] = 'low';
            else                    $row['stock_status'] = 'good';
            $row['expiry_label']   = $exp->format('M d, Y');
        } else {
            if     ($stock <= 5)  $row['stock_status'] = 'critical';
            elseif ($stock <= 20) $row['stock_status'] = 'low';
            else                  $row['stock_status'] = 'good';
            $row['expiry_label']  = null;
        }
        $row['received_label'] = $row['received_date']
            ? (new DateTime($row['received_date']))->format('M d, Y')
            : null;
    }
    unset($row);

    echo json_encode(['success' => true, 'data' => $rows]);
    exit();
}

// ─── AJAX: Fetch stats ────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'fetch_stats') {
    header('Content-Type: application/json');

    $soon = date('Y-m-d', strtotime('+7 days'));

    $total    = $conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0];
    $low      = $conn->query("SELECT COUNT(*) FROM products WHERE stock_qty > 5 AND stock_qty <= 20")->fetch_row()[0];
    $critical = $conn->query("SELECT COUNT(*) FROM products WHERE stock_qty <= 5")->fetch_row()[0];
    $expiring = $conn->query("SELECT COUNT(DISTINCT product_id) FROM inventory_batches WHERE remaining_qty > 0 AND expiry_date IS NOT NULL AND expiry_date <= '$soon'")->fetch_row()[0];
    $expired  = $conn->query("SELECT COUNT(DISTINCT product_id) FROM inventory_batches WHERE remaining_qty > 0 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetch_row()[0];

    echo json_encode([
        'success'  => true,
        'total'    => (int) $total,
        'low'      => (int) $low,
        'critical' => (int) $critical,
        'expiring' => (int) $expiring,
        'expired'  => (int) $expired,
    ]);
    exit();
}

// ─── AJAX: Fetch all batches for a product ───────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'fetch_batches') {
    header('Content-Type: application/json');

    $product_id = (int)($_GET['product_id'] ?? 0);
    if (!$product_id) { echo json_encode(['success' => false]); exit(); }

    // inventory_batches (original batch records)
    $stmt = $conn->prepare("
        SELECT b.*, p.name AS product_name, p.unit
        FROM inventory_batches b
        JOIN products p ON p.id = b.product_id
        WHERE b.product_id = ?
        ORDER BY b.received_date ASC
    ");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // stock_receipts: additions from receiving module
    $stmt2 = $conn->prepare("
        SELECT r.id, r.batch_number, r.quantity, r.unit, r.arrival_date AS event_date,
               r.mfg_date, r.expiry_date, r.qc_notes,
               u.full_name AS done_by,
               'receipt' AS event_type
        FROM stock_receipts r
        LEFT JOIN users u ON u.user_id = r.recorded_by
        WHERE r.product_id = ?
        ORDER BY r.arrival_date DESC
    ");
    $stmt2->bind_param("i", $product_id);
    $stmt2->execute();
    $receipts = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    // stock_adjustments: additions and deductions
    $stmt3 = $conn->prepare("
        SELECT a.id, a.adj_type, a.quantity, a.reason_code, a.notes, a.adjusted_at AS event_date,
               p.unit,
               u.full_name AS done_by,
               'adjustment' AS event_type
        FROM stock_adjustments a
        LEFT JOIN products p ON p.id = a.product_id
        LEFT JOIN users    u ON u.user_id = a.adjusted_by
        WHERE a.product_id = ?
        ORDER BY a.adjusted_at DESC
    ");
    $stmt3->bind_param("i", $product_id);
    $stmt3->execute();
    $adjustments = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt3->close();

    echo json_encode([
        'success'     => true,
        'batches'     => $batches,
        'receipts'    => $receipts,
        'adjustments' => $adjustments,
    ]);
    exit();
}

// ─── Categories for filter dropdown ──────────────────────────────────────────
$categories = array_column(
    $conn->query("SELECT DISTINCT category FROM products ORDER BY category")->fetch_all(MYSQLI_ASSOC),
    'category'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory — Top Juan Inc.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --background: #f7f3f0;
            --foreground: #2d241e;
            --sidebar-bg: #fdfaf7;
            --card-border: #eeeae6;
            --primary: #5c4033;
            --primary-light: #8b5e3c;
            --accent: #d25424;
            --muted: #8c837d;
            --success: #059669;
            --warning: #d97706;
            --error: #dc2626;
            --sidebar-width: 280px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--background); color: var(--foreground); display: flex; min-height: 100vh; }

        /* ── Sidebar ── */
        aside { width: var(--sidebar-width); background: var(--sidebar-bg); border-right: 1px solid var(--card-border); padding: 2rem 1.5rem; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 10; }
        .logo-container { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2.5rem; }
        .logo-icon { width: 40px; height: 40px; background: var(--primary); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; }
        .logo-text h1 { font-family: 'Fraunces', serif; font-size: 1.25rem; line-height: 1; }
        .logo-text span { font-size: 0.75rem; color: var(--muted); font-weight: 500; }
        .menu-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); margin-bottom: 1rem; font-weight: 700; }
        nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
        .nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.875rem 1rem; border-radius: 12px; text-decoration: none; color: var(--muted); font-weight: 500; font-size: 0.95rem; transition: all 0.2s; }
        .nav-item i { width: 20px; height: 20px; stroke-width: 2px; }
        .nav-item:hover { color: var(--primary); background: rgba(92,64,51,0.05); }
        .nav-item.active { background: var(--primary); color: white; }
        .user-profile { margin-top: auto; background: white; border: 1px solid var(--card-border); padding: 1rem; border-radius: 16px; display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; }
        .avatar i { color: var(--muted); }
        .user-meta h4 { font-size: 0.85rem; font-weight: 700; }
        .user-meta p { font-size: 0.75rem; color: var(--muted); }
        .sign-out { display: flex; align-items: center; gap: 0.5rem; text-decoration: none; color: var(--muted); font-size: 0.9rem; padding: 0.5rem; transition: color 0.2s; }
        .sign-out:hover { color: var(--accent); }

        /* ── Main ── */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 2.5rem 3rem; }
        .page-header { margin-bottom: 2.5rem; }
        .page-header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .page-header p { color: var(--muted); font-size: 1rem; }

        /* ── Stat cards ── */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 20px; border: 1px solid var(--card-border); }
        .stat-card .label { font-size: 0.85rem; color: var(--muted); margin-bottom: 0.5rem; }
        .stat-card .value { font-family: 'Fraunces', serif; font-size: 1.75rem; font-weight: 700; }
        .stat-card .sub { font-size: 0.75rem; margin-top: 0.5rem; color: var(--muted); display: flex; align-items: center; gap: 0.3rem; }
        .skeleton { background: #e9e5e1; border-radius: 6px; animation: shimmer 1.4s infinite; display: inline-block; }
        @keyframes shimmer { 0%,100%{opacity:1} 50%{opacity:0.45} }

        /* ── Controls ── */
        .controls { display: flex; gap: 1rem; margin-bottom: 1.5rem; align-items: center; }
        .search-wrap { position: relative; flex: 1; }
        .search-wrap i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--muted); width: 18px; pointer-events: none; }
        .search-wrap input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.75rem; border-radius: 12px; border: 1px solid var(--card-border); background: white; font-family: inherit; font-size: 0.9rem; outline: none; }
        .search-wrap input:focus { border-color: var(--accent); }
        select.filter-select { padding: 0.75rem 1rem; border-radius: 12px; border: 1px solid var(--card-border); background: white; font-family: inherit; font-size: 0.9rem; outline: none; cursor: pointer; }
        select.filter-select:focus { border-color: var(--accent); }

        /* ── Table card ── */
        .card { background: white; border: 1px solid var(--card-border); border-radius: 20px; padding: 1.5rem; margin-bottom: 2rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }
        .row-count { font-size: 0.82rem; color: var(--muted); }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 0.875rem 1rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); white-space: nowrap; }
        td { padding: 1rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border); vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: rgba(92,64,51,0.018); }

        /* ── Status pills ── */
        .pill { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; white-space: nowrap; }
        .pill-good     { background: #ecfdf5; color: #059669; }
        .pill-low      { background: #fffbeb; color: #d97706; }
        .pill-critical { background: #fef2f2; color: #dc2626; }
        .pill-expired  { background: #fef2f2; color: #dc2626; border: 1px solid rgba(220,38,38,0.2); }
        .pill-expiring { background: #fff7ed; color: #ea580c; }

        /* ── Batch chip ── */
        .batch-chip { font-size: 0.76rem; background: var(--background); border-radius: 8px; padding: 0.5rem 0.75rem; margin-top: 0.25rem; line-height: 1.7; color: var(--muted); }
        .batch-chip.warn    { background: #fef2f2; color: #b91c1c; }
        .batch-chip.caution { background: #fff7ed; color: #c2410c; }

        /* ── Icon button ── */
        .btn-icon { width: 34px; height: 34px; border-radius: 9px; border: 1px solid var(--card-border); background: white; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: var(--muted); transition: all 0.2s; }
        .btn-icon:hover { border-color: var(--primary); color: var(--primary); background: rgba(92,64,51,0.04); }

        /* ── Batch Modal ── */
        .overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 100; }
        .modal { background: white; width: 100%; max-width: 660px; border-radius: 24px; padding: 2.5rem; max-height: 88vh; overflow-y: auto; }
        .modal-title { font-family: 'Fraunces', serif; font-size: 1.4rem; margin-bottom: 0.4rem; }
        .modal-sub { color: var(--muted); font-size: 0.88rem; margin-bottom: 1.75rem; }

        .batch-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .batch-table th { background: var(--background); padding: 0.6rem 0.85rem; text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
        .batch-table td { padding: 0.7rem 0.85rem; border-bottom: 1px solid var(--card-border); }
        .batch-table tr:last-child td { border-bottom: none; }
        .fifo-tag { font-size: 0.68rem; background: #ecfdf5; color: #059669; padding: 0.15rem 0.5rem; border-radius: 99px; font-weight: 700; margin-left: 0.4rem; vertical-align: middle; }
        .move-badge { font-size: 0.78rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 6px; white-space: nowrap; }
        .move-add { background: #ecfdf5; color: #059669; }
        .move-sub { background: #fef2f2; color: #dc2626; }

        .modal-footer { margin-top: 1.75rem; text-align: right; }
        .btn-close { background: var(--background); border: none; padding: 0.7rem 1.5rem; border-radius: 12px; font-family: inherit; font-weight: 600; cursor: pointer; font-size: 0.9rem; }
        .btn-close:hover { background: #ece8e4; }

        /* ── Toast ── */
        #toast { position: fixed; bottom: 2rem; right: 2rem; padding: 0.9rem 1.4rem; border-radius: 14px; font-size: 0.88rem; font-weight: 500; display: none; z-index: 999; color: white; box-shadow: 0 4px 20px rgba(0,0,0,0.18); }
        #toast.error { background: #991b1b; }
    </style>
</head>
<body>

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
        <a href="clerk-dashboard.php"  class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="clerk-orders.php"     class="nav-item"><i data-lucide="clipboard-list"></i> Order Monitoring</a>
        <a href="clerk-inventory.php"  class="nav-item active"><i data-lucide="boxes"></i> Inventory</a>
        <a href="clerk-receiving.php"  class="nav-item"><i data-lucide="download"></i> Stock Receiving</a>
        <a href="clerk-adjustment.php" class="nav-item"><i data-lucide="edit-3"></i> Stock Adjustment</a>
        <a href="clerk-reports.php"    class="nav-item"><i data-lucide="bar-chart-3"></i> Reports</a>
    </nav>

    <div class="user-profile">
        <div class="avatar"><i data-lucide="user"></i></div>
        <div class="user-meta">
            <h4><?= htmlspecialchars($clerkName) ?></h4>
            <p>Inventory Clerk</p>
        </div>
    </div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
</aside>

<main>
    <div class="page-header">
        <h2>Inventory</h2>
        <p>Real-time stock levels, batch tracking, and expiry monitoring</p>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <p class="label">Total Products</p>
            <div class="value" id="stat-total"><span class="skeleton" style="width:50px;height:28px;">&nbsp;</span></div>
            <p class="sub"><i data-lucide="package" style="width:13px;height:13px;"></i> All tracked ingredients</p>
        </div>
        <div class="stat-card">
            <p class="label">Low Stock</p>
            <div class="value" id="stat-low" style="color:var(--warning);"><span class="skeleton" style="width:40px;height:28px;">&nbsp;</span></div>
            <p class="sub" id="stat-low-sub"><i data-lucide="alert-triangle" style="width:13px;height:13px;color:var(--warning);"></i> Items below 20 units</p>
        </div>
        <div class="stat-card">
            <p class="label">Expiry Alerts</p>
            <div class="value" id="stat-expiry" style="color:var(--error);"><span class="skeleton" style="width:40px;height:28px;">&nbsp;</span></div>
            <p class="sub" id="stat-expiry-sub"><i data-lucide="clock" style="width:13px;height:13px;color:var(--error);"></i> Expiring within 7 days</p>
        </div>
        <div class="stat-card">
            <p class="label">Critical Stock</p>
            <div class="value" id="stat-critical" style="color:var(--error);"><span class="skeleton" style="width:40px;height:28px;">&nbsp;</span></div>
            <p class="sub"><i data-lucide="alert-circle" style="width:13px;height:13px;color:var(--error);"></i> Items at 5 or below</p>
        </div>
    </div>

    <!-- Controls -->
    <div class="controls">
        <div class="search-wrap">
            <i data-lucide="search"></i>
            <input type="text" id="searchInput" placeholder="Search by item name or category…">
        </div>
        <select id="categoryFilter" class="filter-select">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header">
            <h3>Stock Levels &amp; Batches (FIFO)</h3>
            <span class="row-count" id="rowCount"></span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Oldest Active Batch</th>
                    <th>Status</th>
                    <th>All Batches</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <tr><td colspan="6" style="padding:2.5rem;text-align:center;color:var(--muted);">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</main>

<!-- ── Batch History Modal ── -->
<div class="overlay" id="batchOverlay">
    <div class="modal">
        <p class="modal-title" id="batchTitle">Batch History</p>
        <p class="modal-sub">All received batches sorted oldest-first. The row tagged <strong>FIFO NEXT</strong> is currently being consumed.</p>
        <div id="batchContent"></div>
        <div class="modal-footer">
            <button class="btn-close" onclick="closeBatchModal()">Close</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
    lucide.createIcons();

    let searchTimer = null;

    function esc(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Stats ─────────────────────────────────────────────────────────────────
    async function loadStats() {
        try {
            const d = await fetch('clerk-inventory.php?action=fetch_stats').then(r => r.json());
            if (!d.success) return;
            document.getElementById('stat-total').textContent    = d.total;
            document.getElementById('stat-low').textContent      = d.low;
            document.getElementById('stat-expiry').textContent   = d.expiring;
            document.getElementById('stat-critical').textContent = d.critical;
            lucide.createIcons();
        } catch(e) { console.error(e); }
    }

    // ── Inventory table ───────────────────────────────────────────────────────
    async function loadInventory() {
        const search   = document.getElementById('searchInput').value.trim();
        const category = document.getElementById('categoryFilter').value;
        const tbody    = document.getElementById('tableBody');

        tbody.innerHTML = `<tr><td colspan="6" style="padding:2rem;text-align:center;color:var(--muted);">Loading…</td></tr>`;

        try {
            const params = new URLSearchParams({ action: 'fetch_inventory', search, category });
            const d      = await fetch('clerk-inventory.php?' + params).then(r => r.json());

            if (!d.success) {
                tbody.innerHTML = `<tr><td colspan="6" style="padding:2rem;text-align:center;color:var(--error);">Error loading data.</td></tr>`;
                return;
            }

            document.getElementById('rowCount').textContent = d.data.length + ' item(s)';

            if (!d.data.length) {
                tbody.innerHTML = `<tr><td colspan="6" style="padding:2.5rem;text-align:center;color:var(--muted);">No items match your search.</td></tr>`;
                return;
            }

            const pillMap = {
                good:          `<span class="pill pill-good">Good</span>`,
                low:           `<span class="pill pill-low">Low Stock</span>`,
                critical:      `<span class="pill pill-critical">Critical</span>`,
                expired:       `<span class="pill pill-expired">Expired</span>`,
                expiring_soon: `<span class="pill pill-expiring">Expiring Soon</span>`,
            };

            tbody.innerHTML = d.data.map(r => {
                const statusHtml = pillMap[r.stock_status] ?? pillMap.good;

                let batchHtml = `<span style="color:var(--muted);font-size:0.8rem;">No batch data</span>`;
                if (r.batch_number) {
                    const isExpired = r.stock_status === 'expired';
                    const isSoon    = r.stock_status === 'expiring_soon';
                    const cls       = isExpired ? 'warn' : (isSoon ? 'caution' : '');
                    const expLine   = r.expiry_label
                        ? `<div>${isExpired ? '⚠️ Expired' : (isSoon ? '⏰ Expires' : 'Expires')}: <strong>${esc(r.expiry_label)}</strong></div>`
                        : `<div>Expiry: N/A</div>`;
                    batchHtml = `
                        <div class="batch-chip ${cls}">
                            <div>Batch: <strong>${esc(r.batch_number)}</strong></div>
                            <div>Received: ${esc(r.received_label ?? '—')}</div>
                            ${expLine}
                            <div>Remaining: ${parseFloat(r.remaining_qty ?? 0).toLocaleString()} ${esc(r.unit)}</div>
                        </div>`;
                }

                return `
                <tr>
                    <td>
                        <div style="font-weight:700;">${esc(r.name)}</div>
                        <div style="font-size:0.75rem;color:var(--muted);">ID: ${r.id}</div>
                    </td>
                    <td>${esc(r.category)}</td>
                    <td><strong>${parseFloat(r.stock_qty).toLocaleString()} ${esc(r.unit)}</strong></td>
                    <td>${batchHtml}</td>
                    <td>${statusHtml}</td>
                    <td>
                        <button class="btn-icon" title="View all batches"
                            onclick="openBatchModal(${r.id}, '${esc(r.name).replace(/'/g,"\\'")}')">
                            <i data-lucide="layers" style="width:16px;height:16px;"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');

            lucide.createIcons();
        } catch(e) {
            console.error(e);
            tbody.innerHTML = `<tr><td colspan="6" style="padding:2rem;text-align:center;color:var(--error);">Failed to load inventory.</td></tr>`;
        }
    }

    // ── Search / filter ───────────────────────────────────────────────────────
    document.getElementById('searchInput').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadInventory, 280);
    });
    document.getElementById('categoryFilter').addEventListener('change', loadInventory);

    // ── Batch Modal ───────────────────────────────────────────────────────────
    async function openBatchModal(productId, productName) {
        document.getElementById('batchTitle').textContent  = productName + ' — Batch History';
        document.getElementById('batchContent').innerHTML = '<p style="color:var(--muted);padding:1rem 0;">Loading…</p>';
        document.getElementById('batchOverlay').style.display = 'flex';

        try {
            const d = await fetch(`clerk-inventory.php?action=fetch_batches&product_id=${productId}`).then(r => r.json());

            if (!d.success) {
                document.getElementById('batchContent').innerHTML =
                    '<p style="color:var(--muted);padding:1rem 0;">No records found for this item.</p>';
                return;
            }

            const today = new Date();
            let firstActive = true;
            let html = '';

            // ── Section 1: Inventory Batches ──────────────────────────────
            if (d.batches && d.batches.length) {
                const batchRows = d.batches.map(b => {
                    const exp     = b.expiry_date ? new Date(b.expiry_date) : null;
                    const expTxt  = exp ? exp.toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}) : 'N/A';
                    const isExp   = exp && exp < today;
                    const recTxt  = b.received_date
                        ? new Date(b.received_date).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'})
                        : '—';
                    const isActive    = parseFloat(b.remaining_qty) > 0 && firstActive;
                    if (isActive) firstActive = false;
                    const fifoTag     = isActive ? '<span class="fifo-tag">FIFO NEXT</span>' : '';
                    const expStyle    = isExp ? 'color:#b91c1c;font-weight:700;' : '';
                    const remainStyle = parseFloat(b.remaining_qty) === 0 ? 'color:var(--muted);text-decoration:line-through;' : '';

                    return `<tr>
                        <td>${esc(b.batch_number ?? '—')}${fifoTag}</td>
                        <td>${recTxt}</td>
                        <td style="${expStyle}">${expTxt}${isExp ? ' ⚠️' : ''}</td>
                        <td>${parseFloat(b.batch_qty ?? 0).toLocaleString()} ${esc(b.unit)}</td>
                        <td style="${remainStyle}">${parseFloat(b.remaining_qty ?? 0).toLocaleString()} ${esc(b.unit)}</td>
                    </tr>`;
                }).join('');

                html += `
                    <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);font-weight:700;margin-bottom:0.6rem;">Inventory Batches</p>
                    <table class="batch-table" style="margin-bottom:2rem;">
                        <thead><tr>
                            <th>Batch #</th><th>Received</th><th>Expiry</th><th>Total Qty</th><th>Remaining</th>
                        </tr></thead>
                        <tbody>${batchRows}</tbody>
                    </table>`;
            }

            // ── Section 2: Stock Movement History ────────────────────────
            // Merge receipts and adjustments into one timeline
            const movements = [];

            (d.receipts || []).forEach(r => movements.push({
                date:   r.event_date,
                type:   'receipt',
                sign:   '+',
                qty:    parseFloat(r.quantity),
                unit:   r.unit,
                label:  'New Stock',
                detail: r.batch_number ? 'Batch: ' + r.batch_number : '',
                by:     r.done_by || '—',
                cls:    'move-add',
            }));

            (d.adjustments || []).forEach(a => movements.push({
                date:   a.event_date,
                type:   'adjustment',
                sign:   a.adj_type === 'add' ? '+' : '−',
                qty:    parseFloat(a.quantity),
                unit:   a.unit,
                label:  a.adj_type === 'add' ? 'Stock Added' : 'Stock Deducted',
                detail: a.reason_code,
                by:     a.done_by || '—',
                cls:    a.adj_type === 'add' ? 'move-add' : 'move-sub',
            }));

            // Sort newest first
            movements.sort((a, b) => new Date(b.date) - new Date(a.date));

            if (movements.length) {
                const moveRows = movements.map(m => {
                    const dateTxt = new Date(m.date).toLocaleString('en-PH', {
                        year:'numeric', month:'short', day:'numeric',
                        hour:'numeric', minute:'2-digit'
                    });
                    return `<tr>
                        <td style="white-space:nowrap;color:var(--muted);font-size:0.8rem;">${dateTxt}</td>
                        <td><span class="move-badge ${m.cls}">${m.sign}${m.qty.toLocaleString()} ${esc(m.unit)}</span></td>
                        <td><strong>${esc(m.label)}</strong></td>
                        <td style="color:var(--muted);font-size:0.82rem;">${esc(m.detail)}</td>
                        <td style="color:var(--muted);font-size:0.82rem;">${esc(m.by)}</td>
                    </tr>`;
                }).join('');

                html += `
                    <p style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);font-weight:700;margin-bottom:0.6rem;">Stock Movement History</p>
                    <table class="batch-table">
                        <thead><tr>
                            <th>Date &amp; Time</th><th>Change</th><th>Type</th><th>Reason / Batch</th><th>By</th>
                        </tr></thead>
                        <tbody>${moveRows}</tbody>
                    </table>`;
            } else if (!d.batches || !d.batches.length) {
                html = '<p style="color:var(--muted);padding:1rem 0;">No records found for this item.</p>';
            }

            document.getElementById('batchContent').innerHTML = html;
            lucide.createIcons();

        } catch(e) {
            document.getElementById('batchContent').innerHTML = '<p style="color:var(--error);">Failed to load batches.</p>';
        }
    }

    function closeBatchModal() {
        document.getElementById('batchOverlay').style.display = 'none';
    }

    window.addEventListener('click', e => {
        if (e.target.id === 'batchOverlay') closeBatchModal();
    });

    // ── Init ──────────────────────────────────────────────────────────────────
    loadStats();
    loadInventory();
</script>
</body>
</html>