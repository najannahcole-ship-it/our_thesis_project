<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: index.php');
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$adminName = $_SESSION['full_name'] ?? 'System Admin';

// ── DATABASE ─────────────────────────────────────────────
$conn = new mysqli(
    getenv("DB_HOST"),
    getenv("DB_USER"),
    getenv("DB_PASSWORD"),
    getenv("DB_NAME"),
    (int)getenv("DB_PORT")
);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// ── AJAX: Product detail modal ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'product_detail') {
    header('Content-Type: application/json');
    $product_id = intval($_GET['product_id']);

    // Stock received by clerk (stock_receipts)
    $stmt1 = $conn->prepare("
        SELECT sr.id, sr.batch_number, sr.quantity, sr.unit,
               sr.source_type, sr.arrival_date, sr.mfg_date, sr.expiry_date,
               sr.qc_notes, sr.created_at,
               p.name AS product_name, p.unit AS product_unit,
               u.full_name AS clerk_name
        FROM stock_receipts sr
        LEFT JOIN products p ON p.id = sr.product_id
        LEFT JOIN users u ON u.user_id = sr.recorded_by
        WHERE sr.product_id = ?
        ORDER BY sr.created_at DESC
        LIMIT 30
    ");
    $stmt1->bind_param("i", $product_id);
    $stmt1->execute();
    $receipts = $stmt1->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt1->close();

    // Stock adjustments by clerk (role_id = 3)
    $stmt2 = $conn->prepare("
        SELECT sa.id, sa.adj_type, sa.quantity, sa.reason_code, sa.notes,
               sa.adjusted_at,
               p.name AS product_name, p.unit AS product_unit,
               u.full_name AS clerk_name
        FROM stock_adjustments sa
        LEFT JOIN products p ON p.id = sa.product_id
        LEFT JOIN users u ON u.user_id = sa.adjusted_by
        WHERE sa.product_id = ?
          AND u.role_id = 3
        ORDER BY sa.adjusted_at DESC
        LIMIT 30
    ");
    $stmt2->bind_param("i", $product_id);
    $stmt2->execute();
    $adjustments = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    echo json_encode([
        'receipts'    => $receipts,
        'adjustments' => $adjustments,
    ]);
    $conn->close();
    exit();
}

// ── STATS ────────────────────────────────────────────────
define('LOW_THRESHOLD', 20);

$total_skus   = ($conn->query("SELECT COUNT(*) c FROM products")->fetch_assoc()['c'] ?? 0);
$low_stock    = ($conn->query("SELECT COUNT(*) c FROM products WHERE stock_qty > 0 AND stock_qty <= " . LOW_THRESHOLD)->fetch_assoc()['c'] ?? 0);
$out_of_stock = ($conn->query("SELECT COUNT(*) c FROM products WHERE stock_qty = 0")->fetch_assoc()['c'] ?? 0);
$total_value  = $conn->query("SELECT SUM(price * stock_qty) v FROM products")->fetch_assoc()['v'] ?? 0;

// Expiry tracking removed from inventory_batches
$expiring_soon = 0;
$expired_count = 0;

// ── PRODUCTS with shelf life ──────────────────────────────
$products_res = $conn->query("
    SELECT p.*,
           sl.min_months, sl.max_months
    FROM products p
    LEFT JOIN shelf_life sl ON sl.category = p.category
    ORDER BY p.category, p.name
");

// ── RECENT CLERK STOCK MOVEMENTS (role_id = 3 only) ─────────
$movements_res = $conn->query("
    SELECT il.action, il.quantity, il.unit, il.remarks, il.created_at,
           p.name AS product_name, p.category,
           u.full_name AS clerk_name
    FROM inventory_logs il
    LEFT JOIN products p ON p.id = il.product_id
    LEFT JOIN users    u ON u.user_id = il.created_by
    WHERE u.role_id = 3
    ORDER BY il.created_at DESC
    LIMIT 10
");

// Also pull recent stock adjustments by clerks only
$adjustments_res = $conn->query("
    SELECT sa.adj_type, sa.quantity, sa.reason_code, sa.notes, sa.adjusted_at,
           p.name AS product_name, p.unit,
           u.full_name AS clerk_name
    FROM stock_adjustments sa
    LEFT JOIN products p ON p.id = sa.product_id
    LEFT JOIN users    u ON u.user_id = sa.adjusted_by
    WHERE u.role_id = 3
    ORDER BY sa.adjusted_at DESC
    LIMIT 10
");

// ── SHELF LIFE TABLE ─────────────────────────────────────
$shelf_life_res = $conn->query("SELECT * FROM shelf_life ORDER BY category");

// ── DISTINCT CATEGORIES for filter ───────────────────────
$cat_res    = $conn->query("SELECT DISTINCT category FROM products ORDER BY category");
$categories = [];
while ($r = $cat_res->fetch_assoc()) { $categories[] = $r['category']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Top Juan Inc.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --background: #f7f3f0; --foreground: #2d241e; --sidebar-bg: #fdfaf7;
            --card: #ffffff; --card-border: #eeeae6; --primary: #5c4033;
            --primary-light: #8b5e3c; --accent: #d25424; --muted: #8c837d;
            --success: #059669; --warning: #d97706; --error: #dc2626;
            --radius: 16px; --sidebar-width: 280px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background-color: var(--background); color: var(--foreground); display: flex; min-height: 100vh; }

        /* Sidebar */
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
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb; overflow: hidden; display: flex; align-items: center; justify-content: center; }
        .avatar i { color: var(--muted); }
        .user-meta h4 { font-size: 0.85rem; font-weight: 700; }
        .user-meta p { font-size: 0.75rem; color: var(--muted); }
        .sign-out { display: flex; align-items: center; gap: 0.5rem; text-decoration: none; color: var(--muted); font-size: 0.9rem; padding: 0.5rem; transition: color 0.2s; }
        .sign-out:hover { color: var(--accent); }

        /* Main */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 2.5rem 3rem; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2.5rem; }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p { color: var(--muted); font-size: 1rem; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 1.25rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.25rem 1.5rem; border-radius: 20px; border: 1px solid var(--card-border); }
        .stat-card .label { font-size: 0.82rem; color: var(--muted); margin-bottom: 0.4rem; }
        .stat-card .value { font-family: 'Fraunces', serif; font-size: 1.6rem; font-weight: 700; }
        .stat-card .trend { font-size: 0.72rem; margin-top: 0.4rem; display: flex; align-items: center; gap: 0.25rem; color: var(--muted); }
        .trend.up { color: var(--success); } .trend.warn { color: var(--warning); } .trend.down { color: var(--error); }

        /* Controls */
        .controls { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .search-box { position: relative; flex: 1; min-width: 200px; }
        .search-box i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--muted); width: 18px; }
        .search-box input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.75rem; border-radius: 12px; border: 1px solid var(--card-border); background: white; font-family: inherit; font-size: 0.9rem; outline: none; }
        .search-box input:focus { border-color: var(--primary); }
        .filter-select { padding: 0.75rem 1rem; border-radius: 12px; border: 1px solid var(--card-border); background: white; font-family: inherit; font-size: 0.9rem; min-width: 160px; cursor: pointer; color: var(--foreground); outline: none; }

        /* Card */
        .card { background: white; border: 1px solid var(--card-border); border-radius: 20px; padding: 2rem; margin-bottom: 2rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }
        .card-sub { font-size: 0.8rem; color: var(--muted); }

        /* Two-column bottom layout */
        .bottom-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }

        /* Table */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 0.875rem 1rem; font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); white-space: nowrap; }
        td { padding: 1rem; font-size: 0.88rem; border-bottom: 1px solid var(--card-border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: rgba(92,64,51,0.015); }

        .stock-indicator { display: flex; align-items: center; gap: 0.75rem; }
        .stock-bar-bg { flex: 1; height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden; max-width: 80px; }
        .stock-bar-fill { height: 100%; border-radius: 3px; }

        /* Pills */
        .pill { display: inline-block; padding: 0.22rem 0.65rem; border-radius: 99px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
        .status-good     { background: #ecfdf5; color: #059669; }
        .status-low      { background: #fffbeb; color: #d97706; }
        .status-critical { background: #fef2f2; color: #dc2626; }
        .pill-expired    { background: #fef2f2; color: #dc2626; }
        .pill-expiring   { background: #fff7ed; color: #c2410c; }
        .pill-fresh      { background: #ecfdf5; color: #059669; }
        .pill-nobatch    { background: #f3f4f6; color: #6b7280; }
        .pill-add        { background: #ecfdf5; color: #059669; }
        .pill-deduct     { background: #fef2f2; color: #dc2626; }
        .pill-adjust     { background: #eff6ff; color: #1d4ed8; }

        /* Shelf life badge */
        .shelf-badge { font-size: 0.75rem; color: var(--muted); background: var(--background); padding: 0.2rem 0.55rem; border-radius: 6px; white-space: nowrap; }

        /* Expiry chip */



        .btn-action { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--card-border); background: white; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--muted); transition: all 0.2s; font-family: inherit; }
        .btn-action:hover { border-color: var(--primary); color: var(--primary); background: rgba(92,64,51,0.04); }

        /* Activity feed */
        .activity-item { display: flex; align-items: flex-start; gap: 0.875rem; padding: 0.875rem 0; border-bottom: 1px solid var(--card-border); }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
        .activity-icon.add     { background: #ecfdf5; color: #059669; }
        .activity-icon.deduct  { background: #fef2f2; color: #dc2626; }
        .activity-icon.adjust  { background: #eff6ff; color: #1d4ed8; }
        .activity-icon.usage   { background: rgba(217,119,6,0.1); color: var(--warning); }
        .activity-info { flex: 1; min-width: 0; }
        .activity-info .prod   { font-weight: 700; font-size: 0.875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .activity-info .meta   { font-size: 0.775rem; color: var(--muted); margin-top: 0.15rem; }
        .activity-right { text-align: right; flex-shrink: 0; }
        .activity-qty  { font-weight: 700; font-size: 0.875rem; }
        .activity-time { font-size: 0.72rem; color: var(--muted); margin-top: 0.15rem; }

        /* Shelf life table */
        .shelf-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .shelf-table th { background: var(--background); padding: 0.6rem 1rem; text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
        .shelf-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--card-border); }
        .shelf-table tr:last-child td { border-bottom: none; }
        .shelf-range { display: flex; align-items: center; gap: 0.5rem; }
        .shelf-bar { height: 8px; border-radius: 4px; background: linear-gradient(to right, #059669, #d97706); flex: 1; max-width: 120px; border-radius: 99px; }

        /* Modal */
        .modal-backdrop { position: fixed; inset: 0; background: rgba(45,36,30,0.6); backdrop-filter: blur(4px); display: none; justify-content: center; align-items: center; z-index: 100; }
        .modal-backdrop.active { display: flex; }
        .modal { background: white; border-radius: 24px; width: 100%; max-width: 680px; padding: 2.5rem; box-shadow: 0 20px 40px rgba(0,0,0,0.1); max-height: 86vh; display: flex; flex-direction: column; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-shrink: 0; }
        .modal-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }
        .close-modal { background: none; border: none; cursor: pointer; color: var(--muted); width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .close-modal:hover { background: var(--background); }
        .modal-tabs { display: flex; gap: 0.4rem; margin-bottom: 1.25rem; flex-shrink: 0; }
        .modal-tab { padding: 0.4rem 0.9rem; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; color: var(--muted); border: none; background: var(--background); font-family: inherit; transition: all 0.18s; }
        .modal-tab.active { background: var(--primary); color: white; }
        .modal-body { overflow-y: auto; flex: 1; }
        .modal-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 0.75rem 0; border-bottom: 1px solid var(--card-border); }
        .modal-row:last-child { border-bottom: none; }
        .empty-state { text-align: center; color: var(--muted); padding: 2rem; font-size: 0.9rem; }

        @media (max-width: 1400px) { .stats-grid { grid-template-columns: repeat(3,1fr); } }
        @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2,1fr); } .bottom-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <aside>
        <div class="logo-container">
            <div class="logo-icon"><i data-lucide="coffee"></i></div>
            <div class="logo-text"><h1>Top Juan</h1><span>Admin Portal</span></div>
        </div>
        <div class="menu-label">Menu</div>
        <nav>
            <a href="admin-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
            <a href="admin-orders.php" class="nav-item"><i data-lucide="clipboard-list"></i> Order Request</a>
            <a href="admin-usage.php" class="nav-item"><i data-lucide="activity"></i> Item Usage</a>
            <a href="admin-maintenance.php" class="nav-item"><i data-lucide="settings-2"></i> Maintenance</a>
            <a href="admin-inventory.php" class="nav-item active"><i data-lucide="boxes"></i> Inventory</a>
            <a href="admin-returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i> Return and Refund</a>
            <a href="admin-delivery.php" class="nav-item"><i data-lucide="truck"></i> Delivery</a>
            <a href="admin-reports.php" class="nav-item"><i data-lucide="bar-chart-3"></i> Report</a>
        </nav>
        <div class="user-profile">
            <div class="avatar"><i data-lucide="user-cog"></i></div>
            <div class="user-meta">
                <h4><?= htmlspecialchars($adminName) ?></h4>
                <p>System Administrator</p>
            </div>
        </div>
        <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
    </aside>

    <main>
        <div class="header">
            <div>
                <h2>Inventory Management</h2>
                <p>Real-time monitoring and stock level analysis</p>
            </div>
        </div>

        <!-- STATS (4 cards) -->
        <div class="stats-grid">
            <div class="stat-card">
                <p class="label">Total SKUs</p>
                <div class="value"><?= number_format($total_skus) ?></div>
                <p class="trend up"><i data-lucide="package" size="13"></i> Active products</p>
            </div>
            <div class="stat-card">
                <p class="label">Low Stock</p>
                <div class="value" style="color:var(--warning);"><?= number_format($low_stock) ?></div>
                <p class="trend warn"><i data-lucide="alert-triangle" size="13"></i> Below <?= LOW_THRESHOLD ?> units</p>
            </div>
            <div class="stat-card">
                <p class="label">Out of Stock</p>
                <div class="value" style="color:var(--error);"><?= number_format($out_of_stock) ?></div>
                <p class="trend down"><i data-lucide="x-circle" size="13"></i> Critical priority</p>
            </div>
            <div class="stat-card">
                <p class="label">Total Inventory Value</p>
                <div class="value">₱<?= number_format($total_value, 0) ?></div>
                <p class="trend up"><i data-lucide="trending-up" size="13"></i> Stock valuation</p>
            </div>
        </div>

        <!-- CONTROLS -->
        <div class="controls">
            <div class="search-box">
                <i data-lucide="search"></i>
                <input type="text" id="search-input" placeholder="Search by product name or category..." oninput="filterTable()">
            </div>
            <select class="filter-select" id="category-filter" onchange="filterTable()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="filter-select" id="status-filter" onchange="filterTable()">
                <option value="">All Statuses</option>
                <option value="good">Good Stock</option>
                <option value="low">Low Stock</option>
                <option value="critical">Out of Stock</option>
            </select>
        </div>

        <!-- STOCK TABLE -->
        <div class="card">
            <div class="card-header">
                <h3>Stock Overview</h3>
                <span id="row-count" class="card-sub"></span>
            </div>
            <table id="inventory-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Unit</th>
                        <th>Price</th>
                        <th>Stock Qty</th>
                        <th>Shelf Life</th>
                        <th>Status</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $today = new DateTime();
                $soon  = (new DateTime())->modify('+30 days');

                if ($products_res && $products_res->num_rows > 0):
                    while ($p = $products_res->fetch_assoc()):
                        $qty = intval($p['stock_qty']);

                        if ($qty === 0) { $sc = 'status-critical'; $sl = 'Out of Stock'; $ss = 'critical'; $bc = 'var(--error)'; $bp = 2; }
                        elseif ($qty <= LOW_THRESHOLD) { $sc = 'status-low'; $sl = 'Low Stock'; $ss = 'low'; $bc = 'var(--warning)'; $bp = max(5, min(40, ($qty / LOW_THRESHOLD) * 40)); }
                        else { $sc = 'status-good'; $sl = 'Good'; $ss = 'good'; $bc = 'var(--success)'; $bp = min(100, ($qty / 200) * 100); }

                        $shelfLabel = '—';
                        if (!empty($p['min_months']) && !empty($p['max_months'])) {
                            $shelfLabel = $p['min_months'] == $p['max_months'] ? $p['min_months'] . ' mo' : $p['min_months'] . '–' . $p['max_months'] . ' mo';
                        }

                        
                        
                ?>
                    <tr data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                        data-category="<?= htmlspecialchars($p['category']) ?>"
                        data-status="<?= $ss ?>">
                        <td>
                            <div style="font-weight:700;"><?= htmlspecialchars($p['name']) ?></div>
                            <div style="font-size:0.72rem;color:var(--muted);">SKU: <?= htmlspecialchars($p['product_code'] ?? '—') ?></div>
                        </td>
                        <td><?= htmlspecialchars($p['category']) ?></td>
                        <td><?= htmlspecialchars($p['unit']) ?></td>
                        <td>₱<?= number_format($p['price'], 2) ?></td>
                        <td>
                            <div class="stock-indicator">
                                <div class="stock-bar-bg"><div class="stock-bar-fill" style="width:<?= $bp ?>%;background:<?= $bc ?>;"></div></div>
                                <span style="font-weight:600;"><?= $qty ?></span>
                            </div>
                        </td>
                        <td><?php if (!empty($p['min_months'])): ?><span class="shelf-badge"><?= $shelfLabel ?></span><?php else: ?><span style="color:var(--muted);font-size:0.8rem;">—</span><?php endif; ?></td>
                        <td><span class="pill <?= $sc ?>"><?= $sl ?></span></td>
                        <td>
                            <button class="btn-action" title="View Detail" onclick="viewDetail(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">
                                <i data-lucide="layers" size="15"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="9" class="empty-state">No products found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- BOTTOM GRID: clerk activity + shelf life reference -->
        <div class="bottom-grid">
            <!-- Clerk Stock Activity -->
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <h3>Clerk Stock Activity</h3>
                    <span class="card-sub">Latest 10 movements</span>
                </div>
                <?php
                $feed = [];
                if ($movements_res) { while ($m = $movements_res->fetch_assoc()) { $feed[] = ['type' => $m['action'], 'product' => $m['product_name'], 'qty' => $m['quantity'], 'unit' => $m['unit'], 'by' => $m['clerk_name'] ?? 'Clerk', 'note' => $m['remarks'] ?? '', 'time' => $m['created_at']]; } }
                if ($adjustments_res) { while ($a = $adjustments_res->fetch_assoc()) { $feed[] = ['type' => $a['adj_type'] === 'add' ? 'add' : 'deduct', 'product' => $a['product_name'], 'qty' => $a['quantity'], 'unit' => $a['unit'], 'by' => $a['clerk_name'] ?? 'Clerk', 'note' => $a['reason_code'] ?? '', 'time' => $a['adjusted_at']]; } }
                usort($feed, fn($a,$b) => strtotime($b['time']) - strtotime($a['time']));
                $feed = array_slice($feed, 0, 10);
                ?>
                <?php if (empty($feed)): ?>
                    <p class="empty-state">No clerk stock activity yet.</p>
                <?php else: foreach ($feed as $f):
                    $iconClass = $f['type'] === 'add' ? 'add' : ($f['type'] === 'adjust' ? 'adjust' : 'deduct');
                    $icon = $f['type'] === 'add' ? 'plus' : ($f['type'] === 'adjust' ? 'edit-3' : 'minus');
                    $sign = $f['type'] === 'add' ? '+' : '−';
                    $qtyColor = $f['type'] === 'add' ? 'var(--success)' : 'var(--error)';
                    $timeLabel = (new DateTime($f['time']))->format('M j, g:i A');
                ?>
                <div class="activity-item">
                    <div class="activity-icon <?= $iconClass ?>"><i data-lucide="<?= $icon ?>" size="16"></i></div>
                    <div class="activity-info">
                        <div class="prod"><?= htmlspecialchars($f['product'] ?? '—') ?></div>
                        <div class="meta">by <?= htmlspecialchars($f['by']) ?><?= $f['note'] ? ' · ' . htmlspecialchars($f['note']) : '' ?></div>
                    </div>
                    <div class="activity-right">
                        <div class="activity-qty" style="color:<?= $qtyColor ?>;"><?= $sign . number_format($f['qty'], 1) ?> <?= htmlspecialchars($f['unit'] ?? '') ?></div>
                        <div class="activity-time"><?= $timeLabel ?></div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Shelf Life Reference -->
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <h3>Shelf Life Reference</h3>
                    <span class="card-sub">Per category</span>
                </div>
                <table class="shelf-table">
                    <thead><tr><th>Category</th><th>Min</th><th>Max</th><th>Visual</th></tr></thead>
                    <tbody>
                    <?php if ($shelf_life_res && $shelf_life_res->num_rows > 0): while ($sl = $shelf_life_res->fetch_assoc()): ?>
                        <tr>
                            <td style="font-weight:600;"><?= htmlspecialchars($sl['category']) ?></td>
                            <td><?= $sl['min_months'] ?> mo</td>
                            <td><?= $sl['max_months'] ?> mo</td>
                            <td><div style="display:flex;align-items:center;gap:.5rem;"><div style="height:8px;border-radius:99px;background:linear-gradient(to right,#059669,#d97706);width:<?= min(120, ($sl['max_months'] / 24) * 120) ?>px;"></div><span style="font-size:.72rem;color:var(--muted);"><?= $sl['min_months'] ?>–<?= $sl['max_months'] ?> mo</span></div></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="4" class="empty-state">No shelf life data.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- PRODUCT DETAIL MODAL -->
    <div id="detail-modal" class="modal-backdrop">
        <div class="modal" style="max-width:900px;">
            <div class="modal-header">
                <h3 id="modal-title">Product Detail</h3>
                <button class="close-modal" onclick="closeModal()"><i data-lucide="x" size="18"></i></button>
            </div>
            <div class="modal-tabs">
                <button class="modal-tab active" onclick="switchModalTab('movements', this)">Clerk Movements</button>
            </div>
            <div class="modal-body" id="modal-body">
                <p class="empty-state">Loading...</p>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        let detailData = {};

        function updateRowCount() {
            const total   = document.querySelectorAll('#inventory-table tbody tr[data-name]').length;
            const visible = document.querySelectorAll('#inventory-table tbody tr[data-name]:not([style*="none"])').length;
            document.getElementById('row-count').textContent = visible === total ? `${total} products` : `${visible} of ${total} products`;
        }
        updateRowCount();

        function filterTable() {
            const search   = document.getElementById('search-input').value.toLowerCase();
            const category = document.getElementById('category-filter').value;
            const status   = document.getElementById('status-filter').value;
            document.querySelectorAll('#inventory-table tbody tr[data-name]').forEach(row => {
                const matchName     = row.dataset.name.includes(search) || row.dataset.category.toLowerCase().includes(search);
                const matchCategory = !category || row.dataset.category === category;
                const matchStatus   = !status   || row.dataset.status   === status;
                row.style.display   = (matchName && matchCategory && matchStatus) ? '' : 'none';
            });
            updateRowCount();
        }

        function esc(s) {
            return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
        function fmtDate(d) {
            if (!d) return '—';
            return new Date(d).toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'});
        }
        function fmtDateTime(d) {
            if (!d) return '—';
            return new Date(d).toLocaleString('en-PH', {month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
        }

        async function viewDetail(id, name) {
            document.getElementById('modal-title').textContent = name;
            document.getElementById('modal-body').innerHTML = '<p class="empty-state">Loading...</p>';
            document.getElementById('detail-modal').classList.add('active');
            // Reset to first tab
            document.querySelectorAll('.modal-tab').forEach((t,i) => t.classList.toggle('active', i===0));

            try {
                const d = await fetch(`admin-inventory.php?ajax=product_detail&product_id=${id}`).then(r => r.json());
                detailData = d;
                renderModalTab('movements');
            } catch(e) {
                document.getElementById('modal-body').innerHTML = '<p class="empty-state" style="color:var(--error);">Failed to load data.</p>';
            }
        }

        function switchModalTab(tab, el) {
            document.querySelectorAll('.modal-tab').forEach(t => t.classList.remove('active'));
            el.classList.add('active');
            renderModalTab(tab);
        }

        function renderModalTab(tab) {
            const body = document.getElementById('modal-body');

            if (tab === 'movements') {
                const receipts    = detailData.receipts    ?? [];
                const adjustments = detailData.adjustments ?? [];
                const hasData     = receipts.length || adjustments.length;

                if (!hasData) {
                    body.innerHTML = '<p class="empty-state">No clerk stock activity recorded for this product.</p>';
                    return;
                }

                const thStyle = 'padding:.5rem .9rem;background:var(--background);text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);';
                const tdBase  = 'padding:.75rem .9rem;font-size:.84rem;vertical-align:middle;';

                // ── Section 1: Stock Received ──────────────────────────────
                let html = '';

                if (receipts.length) {
                    html += '<div style="margin-bottom:1.5rem;">'
                          + '<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;">'
                          + '<span style="width:8px;height:8px;border-radius:50%;background:#059669;display:inline-block;"></span>'
                          + '<span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#059669;">Stock Received</span>'
                          + '<span style="font-size:.72rem;color:var(--muted);">(' + receipts.length + ' record' + (receipts.length !== 1 ? 's' : '') + ')</span>'
                          + '</div>'
                          + '<div style="border:1px solid var(--card-border);border-radius:12px;overflow:hidden;">'
                          + '<table style="width:100%;border-collapse:collapse;">'
                          + '<thead><tr>'
                          + '<th style="' + thStyle + '">Item</th>'
                          + '<th style="' + thStyle + '">Clerk</th>'
                          + '<th style="' + thStyle + '">Batch #</th>'
                          + '<th style="' + thStyle + '">Qty Received</th>'
                          + '<th style="' + thStyle + '">Arrival Date</th>'
                          + '<th style="' + thStyle + '">Expiry</th>'
                          + '<th style="' + thStyle + '">QC Notes</th>'
                          + '</tr></thead><tbody>';

                    receipts.forEach(function(r, i) {
                        const borderBottom = i < receipts.length - 1 ? 'border-bottom:1px solid var(--card-border);' : '';
                        const td = tdBase + borderBottom;
                        const expLabel = r.expiry_date ? fmtDate(r.expiry_date) : '\u2014';
                        const expColor = r.expiry_date && new Date(r.expiry_date) < new Date() ? 'color:#dc2626;font-weight:600;' : '';
                        html += '<tr>'
                              + '<td style="' + td + 'font-weight:600;">' + esc(r.product_name || '\u2014') + '<br><span style="font-size:.72rem;color:var(--muted);font-weight:400;">' + esc(r.unit || r.product_unit || '') + '</span></td>'
                              + '<td style="' + td + '"><div style="font-weight:600;">' + esc(r.clerk_name || '\u2014') + '</div><div style="font-size:.72rem;color:var(--muted);">Inventory Clerk</div></td>'
                              + '<td style="' + td + 'color:var(--muted);font-family:monospace;font-size:.8rem;">' + esc(r.batch_number || '\u2014') + '</td>'
                              + '<td style="' + td + '"><span style="font-weight:700;color:#059669;font-size:1rem;">+' + parseFloat(r.quantity).toLocaleString() + '</span> <span style="font-size:.78rem;color:var(--muted);">' + esc(r.unit || r.product_unit || '') + '</span></td>'
                              + '<td style="' + td + 'color:var(--muted);">' + fmtDate(r.arrival_date || r.created_at) + '</td>'
                              + '<td style="' + td + expColor + '">' + expLabel + '</td>'
                              + '<td style="' + td + 'color:var(--muted);font-size:.78rem;">' + esc(r.qc_notes || '\u2014') + '</td>'
                              + '</tr>';
                    });

                    html += '</tbody></table></div></div>';
                }

                // ── Section 2: Stock Adjustments ───────────────────────────
                if (adjustments.length) {
                    html += '<div>'
                          + '<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;">'
                          + '<span style="width:8px;height:8px;border-radius:50%;background:#1d4ed8;display:inline-block;"></span>'
                          + '<span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#1d4ed8;">Stock Adjustments</span>'
                          + '<span style="font-size:.72rem;color:var(--muted);">(' + adjustments.length + ' record' + (adjustments.length !== 1 ? 's' : '') + ')</span>'
                          + '</div>'
                          + '<div style="border:1px solid var(--card-border);border-radius:12px;overflow:hidden;">'
                          + '<table style="width:100%;border-collapse:collapse;">'
                          + '<thead><tr>'
                          + '<th style="' + thStyle + '">Item</th>'
                          + '<th style="' + thStyle + '">Clerk</th>'
                          + '<th style="' + thStyle + '">Type</th>'
                          + '<th style="' + thStyle + '">Quantity</th>'
                          + '<th style="' + thStyle + '">Reason</th>'
                          + '<th style="' + thStyle + '">Notes</th>'
                          + '<th style="' + thStyle + '">Date</th>'
                          + '</tr></thead><tbody>';

                    adjustments.forEach(function(a, i) {
                        const borderBottom = i < adjustments.length - 1 ? 'border-bottom:1px solid var(--card-border);' : '';
                        const td = tdBase + borderBottom;
                        const isAdd    = a.adj_type === 'add';
                        const sign     = isAdd ? '+' : '\u2212';
                        const qtyColor = isAdd ? '#059669' : '#dc2626';
                        const typePill = isAdd
                            ? '<span style="font-size:.7rem;background:#ecfdf5;color:#059669;padding:.15rem .55rem;border-radius:99px;font-weight:700;">Add</span>'
                            : '<span style="font-size:.7rem;background:#fef2f2;color:#dc2626;padding:.15rem .55rem;border-radius:99px;font-weight:700;">Subtract</span>';
                        html += '<tr>'
                              + '<td style="' + td + 'font-weight:600;">' + esc(a.product_name || '\u2014') + '<br><span style="font-size:.72rem;color:var(--muted);font-weight:400;">' + esc(a.product_unit || '') + '</span></td>'
                              + '<td style="' + td + '"><div style="font-weight:600;">' + esc(a.clerk_name || '\u2014') + '</div><div style="font-size:.72rem;color:var(--muted);">Inventory Clerk</div></td>'
                              + '<td style="' + td + '">' + typePill + '</td>'
                              + '<td style="' + td + '"><span style="font-weight:700;color:' + qtyColor + ';font-size:1rem;">' + sign + parseFloat(a.quantity).toLocaleString() + '</span> <span style="font-size:.78rem;color:var(--muted);">' + esc(a.product_unit || '') + '</span></td>'
                              + '<td style="' + td + 'font-size:.82rem;">' + esc(a.reason_code || '\u2014') + '</td>'
                              + '<td style="' + td + 'color:var(--muted);font-size:.78rem;">' + esc(a.notes || '\u2014') + '</td>'
                              + '<td style="' + td + 'color:var(--muted);">' + fmtDateTime(a.adjusted_at) + '</td>'
                              + '</tr>';
                    });

                    html += '</tbody></table></div></div>';
                }

                body.innerHTML = html;
            }
        }

        function closeModal() { document.getElementById('detail-modal').classList.remove('active'); }
        document.getElementById('detail-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
    </script>
</body>
</html>
<?php $conn->close(); ?>