<?php
session_start();

// 1. Auth guard — Inventory Clerk only (role_id = 3)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: index.php');
    exit();
}

// 2. No-cache headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/db.php';

// Auto-add missing columns to inventory_batches if not yet present
$conn->query("ALTER TABLE `inventory_batches` ADD COLUMN IF NOT EXISTS `expiry_date` DATE NULL");
$conn->query("ALTER TABLE `inventory_batches` ADD COLUMN IF NOT EXISTS `received_date` DATE NULL");
$conn->query("ALTER TABLE `inventory_batches` ADD COLUMN IF NOT EXISTS `batch_number` VARCHAR(100) NULL");
$conn->query("ALTER TABLE `inventory_batches` ADD COLUMN IF NOT EXISTS `remaining_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
$conn->query("ALTER TABLE `inventory_batches` ADD COLUMN IF NOT EXISTS `batch_qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00");

$clerkName   = $_SESSION['full_name'] ?? 'Inventory Clerk';
$clerkUserId = $_SESSION['user_id'];

// ── AJAX: stat card detail modals ────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    switch ($_GET['ajax']) {

        case 'low_stock':
            $rows = [];
            $r = $conn->query("
                SELECT name, category, stock_qty, unit, low_stock_threshold
                FROM products
                WHERE status = 'available' AND stock_qty <= low_stock_threshold
                ORDER BY stock_qty ASC
            ");
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            echo json_encode($rows); break;

        case 'received_today':
            $rows = [];
            $r = $conn->query("
                SELECT p.name AS product_name, p.unit,
                       sr.quantity, sr.batch_number, sr.created_at,
                       u.full_name AS clerk_name
                FROM stock_receipts sr
                LEFT JOIN products p ON p.id = sr.product_id
                LEFT JOIN users    u ON u.user_id = sr.recorded_by
                WHERE DATE(sr.created_at) = CURDATE()
                ORDER BY sr.created_at DESC
            ");
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            echo json_encode($rows); break;

        case 'adjustments':
            $rows = [];
            $r = $conn->query("
                SELECT p.name AS product_name, p.unit,
                       sa.adj_type, sa.quantity, sa.reason_code, sa.notes, sa.adjusted_at,
                       u.full_name AS clerk_name
                FROM stock_adjustments sa
                LEFT JOIN products p ON p.id = sa.product_id
                LEFT JOIN users    u ON u.user_id = sa.adjusted_by
                WHERE MONTH(sa.adjusted_at) = MONTH(CURDATE()) AND YEAR(sa.adjusted_at) = YEAR(CURDATE())
                ORDER BY sa.adjusted_at DESC
            ");
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            echo json_encode($rows); break;

        case 'expiring':
            $rows = [];
            $r = $conn->query("
                SELECT p.name AS product_name, p.unit,
                       ib.batch_number, ib.remaining_qty, ib.expiry_date,
                       DATEDIFF(ib.expiry_date, CURDATE()) AS days_left
                FROM inventory_batches ib
                LEFT JOIN products p ON p.id = ib.product_id
                WHERE ib.remaining_qty > 0
                  AND ib.expiry_date IS NOT NULL
                  AND ib.expiry_date >= CURDATE()
                  AND ib.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                ORDER BY ib.expiry_date ASC
            ");
            if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
            echo json_encode($rows); break;

        default:
            echo json_encode([]);
    }
    $conn->close(); exit();
}

// ── Stat: Orders awaiting clerk action (Processing=2, Ready=3)
$res = $conn->query("SELECT COUNT(*) FROM orders WHERE status_step IN (1, 2, 3)");
$pendingFulfillment = $res ? $res->fetch_row()[0] : 0;

// ── Stat: Low stock (stock_qty <= low_stock_threshold per product)
$res = $conn->query("SELECT COUNT(*) FROM products WHERE status = 'available' AND stock_qty <= low_stock_threshold");
$lowStockCount = $res ? $res->fetch_row()[0] : 0;

// ── Stat: Stock units received today (use created_at, arrival_date may be NULL)
$res = $conn->query("SELECT COALESCE(SUM(quantity), 0) FROM stock_receipts WHERE DATE(created_at) = CURDATE()");
$receivedToday = $res ? (int)$res->fetch_row()[0] : 0;

// ── Stat: Adjustments logged this month
$res = $conn->query("SELECT COUNT(*) FROM stock_adjustments WHERE MONTH(adjusted_at) = MONTH(CURDATE()) AND YEAR(adjusted_at) = YEAR(CURDATE())");
$adjustmentsMonth = $res ? $res->fetch_row()[0] : 0;

// ── Stat: Products expiring within 7 days (from inventory_batches — proper expiry table)
$res = $conn->query("SELECT COUNT(DISTINCT product_id) FROM inventory_batches WHERE remaining_qty > 0 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$expiringSoon = $res ? $res->fetch_row()[0] : 0;

// ── Recent inventory changes: inventory_logs (clerk actions only) + adjustments, latest 8
$recentChanges = [];
$res = $conn->query("
    SELECT 'receipt' AS source_type, p.name AS product_name, il.quantity, p.unit,
           il.created_at AS changed_at, u.full_name AS clerk_name, il.remarks AS reason_code
    FROM inventory_logs il
    LEFT JOIN products p ON p.id = il.product_id
    LEFT JOIN users    u ON u.user_id = il.created_by
    WHERE il.action = 'add' AND u.role_id = 3
    UNION ALL
    SELECT 'adjustment', p.name, sa.quantity, p.unit,
           sa.adjusted_at, u.full_name, sa.reason_code
    FROM stock_adjustments sa
    LEFT JOIN products p ON p.id = sa.product_id
    LEFT JOIN users    u ON u.user_id = sa.adjusted_by
    WHERE u.role_id = 3
    ORDER BY changed_at DESC
    LIMIT 8
");
if ($res) while ($row = $res->fetch_assoc()) $recentChanges[] = $row;

// ── Critical low stock alerts (per product threshold)
$criticalStock = [];
$res = $conn->query("
    SELECT name, stock_qty, unit, low_stock_threshold FROM products
    WHERE status = 'available' AND stock_qty <= low_stock_threshold
    ORDER BY stock_qty ASC LIMIT 4
");
if ($res) while ($row = $res->fetch_assoc()) $criticalStock[] = $row;

// ── Expiry alerts: batches expiring within 30 days with remaining qty
$expiryAlerts = [];
$res = $conn->query("
    SELECT p.name AS product_name, ib.expiry_date, ib.batch_number,
           DATEDIFF(ib.expiry_date, CURDATE()) AS days_left
    FROM inventory_batches ib
    LEFT JOIN products p ON p.id = ib.product_id
    WHERE ib.remaining_qty > 0
      AND ib.expiry_date IS NOT NULL
      AND ib.expiry_date >= CURDATE()
      AND ib.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY ib.expiry_date ASC LIMIT 4
");
if ($res) while ($row = $res->fetch_assoc()) $expiryAlerts[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clerk Dashboard - Top Juan Inc.</title>
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
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --info: #3b82f6;
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
            align-items: flex-start;
            margin-bottom: 2.5rem;
        }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p { color: var(--muted); font-size: 1rem; }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 2.5rem;
        }
        .action-card {
            background: white;
            padding: 1.25rem;
            border-radius: 16px;
            border: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: var(--foreground);
            transition: transform 0.2s, box-shadow 0.2s;
            flex: 1;
        }
        .action-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .action-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--background);
            color: var(--primary);
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        .summary-card {
            background: white;
            border: 1px solid var(--card-border);
            padding: 1.75rem;
            border-radius: 20px;
            position: relative;
            cursor: pointer;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .summary-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        .summary-card .icon-badge {
            position: absolute;
            top: 1.75rem;
            right: 1.75rem;
            color: var(--muted);
        }
        .summary-card .label { font-size: 0.9rem; color: var(--muted); margin-bottom: 0.5rem; font-weight: 500; }
        .summary-card .value { font-size: 2rem; font-weight: 700; font-family: 'Fraunces', serif; }
        .summary-card .subtext { font-size: 0.8rem; color: var(--muted); margin-top: 0.5rem; }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 1.5rem;
        }

        .card {
            background: white;
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }

        /* Stock Alerts */
        .alert-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 12px;
            background: var(--sidebar-bg);
            margin-bottom: 0.75rem;
            border-left: 4px solid transparent;
        }
        .alert-critical { border-left-color: var(--danger); background: #fef2f2; }
        .alert-warning { border-left-color: var(--warning); background: #fffbeb; }
        
        .alert-info h4 { font-size: 0.9rem; margin-bottom: 0.15rem; }
        .alert-info p { font-size: 0.8rem; color: var(--muted); }
        .stock-level { margin-left: auto; text-align: right; }
        .stock-level span { display: block; font-weight: 700; font-size: 1rem; }
        .stock-level small { font-size: 0.7rem; color: var(--muted); }

        /* Recent Changes Table */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1rem; font-size: 0.8rem; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--card-border); }
        td { padding: 1.25rem 1rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border); }

        .type-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-in { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .badge-out { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .badge-adj { background: rgba(59, 130, 246, 0.1); color: var(--info); }

        /* Modal */
        .modal-overlay { position:fixed; inset:0; background:rgba(45,36,30,.55); backdrop-filter:blur(4px); display:none; align-items:center; justify-content:center; z-index:200; }
        .modal-overlay.open { display:flex; }
        .modal-box { background:white; border-radius:24px; width:100%; max-width:620px; max-height:82vh; display:flex; flex-direction:column; padding:2.5rem; box-shadow:0 20px 50px rgba(0,0,0,.12); }
        .modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-shrink:0; }
        .modal-head h3 { font-family:'Fraunces',serif; font-size:1.3rem; }
        .modal-close { background:none; border:none; cursor:pointer; color:var(--muted); width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; }
        .modal-close:hover { background:var(--background); }
        .modal-body { overflow-y:auto; flex:1; }
        .modal-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .modal-table th { padding:.6rem 1rem; background:var(--background); text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
        .modal-table td { padding:.8rem 1rem; border-bottom:1px solid var(--card-border); vertical-align:middle; }
        .modal-table tr:last-child td { border-bottom:none; }
        .modal-empty { text-align:center; color:var(--muted); padding:2.5rem; font-size:.9rem; }

        @media (max-width: 1200px) {
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .content-grid { grid-template-columns: 1fr; }
            .quick-actions { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <aside>
        <div class="logo-container">
            <div class="logo-icon"><i data-lucide="coffee"></i></div>
            <div class="logo-text">
                <h1>Top Juan</h1>
                <span>Inventory Portal</span>
            </div>
        </div>

        <div class="menu-label">Menu</div>
        <nav>
            <a href="clerk-dashboard.php" class="nav-item active" data-testid="link-clerk-dashboard"><i data-lucide="layout-dashboard"></i> Dashboard</a>
            <a href="clerk-orders.php" class="nav-item" data-testid="link-order-requests"><i data-lucide="clipboard-list"></i> Order Monitoring</a>
            <a href="clerk-inventory.php" class="nav-item" data-testid="link-inventory"><i data-lucide="boxes"></i> Inventory</a>
            <a href="clerk-receiving.php" class="nav-item" data-testid="link-stock-receiving"><i data-lucide="download"></i> Stock Receiving</a>
            <a href="clerk-adjustment.php" class="nav-item" data-testid="link-stock-adjustment"><i data-lucide="edit-3"></i> Stock Adjustment</a>
            <a href="clerk-reports.php" class="nav-item" data-testid="link-reports"><i data-lucide="bar-chart-3"></i> Report</a>
        </nav>

        <div class="user-profile">
            <div class="avatar"><i data-lucide="user"></i></div>
            <div class="user-meta">
                <h4 data-testid="text-username"><?= htmlspecialchars($clerkName) ?></h4>
                <p>Inventory Clerk</p>
            </div>
        </div>
        <a href="logout.php" class="sign-out" data-testid="button-logout"><i data-lucide="log-out"></i> Sign Out</a>
    </aside>

    <main>
        <div class="header">
            <div>
                <h2 data-testid="text-page-title">Inventory Dashboard</h2>
                <p>Proactive management and real-time stock monitoring</p>
            </div>
            <div style="text-align: right;">
                <p id="current-date" style="font-weight: 600; color: var(--primary)"></p>
            </div>
        </div>

        <div class="quick-actions">
            <a href="clerk-receiving.php" class="action-card" data-testid="card-action-receiving">
                <div class="action-icon"><i data-lucide="plus-circle"></i></div>
                <div>
                    <h4 style="font-size: 0.95rem;">Receive Stock</h4>
                    <p style="font-size: 0.75rem; color: var(--muted);">Log incoming items</p>
                </div>
            </a>
            <a href="clerk-adjustment.php" class="action-card" data-testid="card-action-issuance">
                <div class="action-icon"><i data-lucide="minus-circle"></i></div>
                <div>
                    <h4 style="font-size: 0.95rem;">Issue Stock</h4>
                    <p style="font-size: 0.75rem; color: var(--muted);">Branch requirements</p>
                </div>
            </a>
            <a href="clerk-inventory.php" class="action-card" data-testid="card-action-audit">
                <div class="action-icon"><i data-lucide="clipboard-check"></i></div>
                <div>
                    <h4 style="font-size: 0.95rem;">Physical Audit</h4>
                    <p style="font-size: 0.75rem; color: var(--muted);">Start stock count</p>
                </div>
            </a>
        </div>

        <div class="summary-grid">
            <div class="summary-card" data-testid="card-low-stock-alerts" onclick="openModal('low_stock','Low Stock Alerts')">
                <i data-lucide="alert-triangle" class="icon-badge" style="color: var(--danger)"></i>
                <p class="label">Low Stock Alerts</p>
                <div class="value" data-testid="text-low-stock-count"><?= $lowStockCount ?></div>
                <p class="subtext">Items below threshold</p>
            </div>
            <div class="summary-card" data-testid="card-received-today" onclick="openModal('received_today','Received Today')">
                <i data-lucide="package-check" class="icon-badge" style="color: var(--success)"></i>
                <p class="label">Received Today</p>
                <div class="value" data-testid="text-received-count"><?= number_format($receivedToday, 0) ?></div>
                <p class="subtext">Stock units logged</p>
            </div>
            <div class="summary-card" data-testid="card-pending-adjustments" onclick="openModal('adjustments','Adjustments This Month')">
                <i data-lucide="git-pull-request" class="icon-badge" style="color: var(--warning)"></i>
                <p class="label">Adjustments</p>
                <div class="value" data-testid="text-pending-adj"><?= $adjustmentsMonth ?></div>
                <p class="subtext">This month</p>
            </div>
            <div class="summary-card" data-testid="card-expiring-soon" onclick="openModal('expiring','Expiring Within 7 Days')">
                <i data-lucide="calendar-x" class="icon-badge" style="color: var(--accent)"></i>
                <p class="label">Expiring Soon</p>
                <div class="value" data-testid="text-expiring-count"><?= $expiringSoon ?></div>
                <p class="subtext">Next 7 days</p>
            </div>
        </div>

        <div class="content-grid">
            <div class="card" data-testid="card-recent-inventory-changes">
                <div class="card-header">
                    <h3>Recent Inventory Changes</h3>
                    <a href="clerk-receiving.php" style="font-size: 0.85rem; color: var(--accent); font-weight: 600; text-decoration: none;">View All</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Clerk</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentChanges)): ?>
                        <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:2rem;">No inventory changes yet.</td></tr>
                        <?php else: foreach ($recentChanges as $i => $row):
                            $isReceipt = $row['source_type'] === 'receipt';
                            $badgeClass = $isReceipt ? 'badge-in' : 'badge-adj';
                            $badgeLabel = $isReceipt ? 'Stock In' : 'Adjustment';
                            $sign       = $isReceipt ? '+' : '−';
                            $qty        = (int)$row['quantity'];
                            $unit       = htmlspecialchars($row['unit'] ?? '');
                            $clerk      = htmlspecialchars($row['clerk_name'] ?? '—');
                            $ts         = strtotime($row['changed_at']);
                            $today      = date('Y-m-d');
                            $yesterday  = date('Y-m-d', strtotime('-1 day'));
                            $rowDate    = date('Y-m-d', $ts);
                            if ($rowDate === $today)         $timeLabel = date('g:i A', $ts);
                            elseif ($rowDate === $yesterday) $timeLabel = 'Yesterday';
                            else                             $timeLabel = date('M d', $ts);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['product_name'] ?? '—') ?></strong></td>
                            <td><span class="type-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                            <td><?= $sign . $qty . ' ' . $unit ?></td>
                            <td><?= $clerk ?></td>
                            <td><?= $timeLabel ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card" data-testid="card-critical-alerts">
                <div class="card-header">
                    <h3>Critical Stock & Expiry</h3>
                </div>
                <div class="alerts-list">
                    <?php foreach ($criticalStock as $item): ?>
                    <div class="alert-item alert-critical">
                        <div class="alert-info">
                            <h4><?= htmlspecialchars($item['name']) ?></h4>
                            <p>Below threshold (<?= $item['low_stock_threshold'] ?>)</p>
                        </div>
                        <div class="stock-level">
                            <span style="color: var(--danger);"><?= $item['stock_qty'] . ' ' . htmlspecialchars($item['unit']) ?></span>
                            <small>Needs restocking</small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php foreach ($expiryAlerts as $item): ?>
                    <div class="alert-item <?= $item['days_left'] <= 7 ? 'alert-critical' : 'alert-warning' ?>">
                        <div class="alert-info">
                            <h4><?= htmlspecialchars($item['product_name']) ?></h4>
                            <p>Expires in <?= $item['days_left'] ?> day<?= $item['days_left'] != 1 ? 's' : '' ?></p>
                        </div>
                        <div class="stock-level">
                            <span style="color: <?= $item['days_left'] <= 7 ? 'var(--danger)' : 'var(--warning)' ?>;"><?= date('M d, Y', strtotime($item['expiry_date'])) ?></span>
                            <small>Batch #<?= htmlspecialchars($item['batch_number']) ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($criticalStock) && empty($expiryAlerts)): ?>
                    <div style="text-align:center;color:var(--muted);padding:2rem;font-size:0.9rem;">
                        <i data-lucide="check-circle" style="display:block;margin:0 auto 0.5rem;"></i>
                        All stock levels are healthy.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

<!-- Stat Detail Modal -->
<div id="statModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="modalTitle">Details</h3>
            <button class="modal-close" id="modalCloseBtn"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
        </div>
        <div class="modal-body" id="modalBody">
            <p class="modal-empty">Loading…</p>
        </div>
    </div>
</div>

    <script>
        lucide.createIcons();
        document.getElementById('current-date').textContent = new Date().toLocaleDateString('en-US', {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
        });

        // ── Modal ──────────────────────────────────────────────
        function closeModal() {
            document.getElementById('statModal').classList.remove('open');
        }
        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        document.getElementById('statModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        function esc(s) {
            return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
        function fmtDate(d) {
            if (!d) return '—';
            return new Date(d).toLocaleDateString('en-PH', {month:'short', day:'numeric', year:'numeric'});
        }
        function fmtDT(d) {
            if (!d) return '—';
            return new Date(d).toLocaleString('en-PH', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit'});
        }

        async function openModal(type, title) {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalBody').innerHTML = '<p class="modal-empty">Loading…</p>';
            document.getElementById('statModal').classList.add('open');

            try {
                const data = await fetch('clerk-dashboard.php?ajax=' + type).then(r => r.json());

                if (!data.length) {
                    document.getElementById('modalBody').innerHTML = '<p class="modal-empty">No records found.</p>';
                    return;
                }

                let html = '';

                if (type === 'low_stock') {
                    html = '<table class="modal-table"><thead><tr><th>Product</th><th>Category</th><th>Stock</th><th>Threshold</th></tr></thead><tbody>';
                    html += data.map(r => `<tr>
                        <td style="font-weight:600;">${esc(r.name)}</td>
                        <td style="color:var(--muted);">${esc(r.category)}</td>
                        <td style="color:var(--danger);font-weight:700;">${r.stock_qty} ${esc(r.unit)}</td>
                        <td style="color:var(--muted);">${r.low_stock_threshold}</td>
                    </tr>`).join('');
                    html += '</tbody></table>';

                } else if (type === 'received_today') {
                    html = '<table class="modal-table"><thead><tr><th>Product</th><th>Batch #</th><th>Qty</th><th>Recorded By</th><th>Time</th></tr></thead><tbody>';
                    html += data.map(r => `<tr>
                        <td style="font-weight:600;">${esc(r.product_name)}</td>
                        <td style="font-family:monospace;font-size:.82rem;">${esc(r.batch_number)}</td>
                        <td style="font-weight:700;color:var(--success);">+${parseInt(r.quantity)} ${esc(r.unit)}</td>
                        <td style="color:var(--muted);">${esc(r.clerk_name ?? '—')}</td>
                        <td style="color:var(--muted);font-size:.82rem;">${fmtDT(r.created_at)}</td>
                    </tr>`).join('');
                    html += '</tbody></table>';

                } else if (type === 'adjustments') {
                    html = '<table class="modal-table"><thead><tr><th>Product</th><th>Type</th><th>Qty</th><th>Reason</th><th>By</th><th>Date</th></tr></thead><tbody>';
                    html += data.map(r => {
                        const isAdd = r.adj_type === 'add';
                        const color = isAdd ? 'var(--success)' : 'var(--danger)';
                        const sign  = isAdd ? '+' : '−';
                        const badge = isAdd
                            ? '<span style="background:#ecfdf5;color:#059669;padding:.15rem .55rem;border-radius:99px;font-size:.72rem;font-weight:700;">Add</span>'
                            : '<span style="background:#fef2f2;color:#dc2626;padding:.15rem .55rem;border-radius:99px;font-size:.72rem;font-weight:700;">Subtract</span>';
                        return `<tr>
                            <td style="font-weight:600;">${esc(r.product_name)}</td>
                            <td>${badge}</td>
                            <td style="font-weight:700;color:${color};">${sign}${parseInt(r.quantity)} ${esc(r.unit)}</td>
                            <td style="color:var(--muted);font-size:.82rem;">${esc(r.reason_code ?? '—')}</td>
                            <td style="color:var(--muted);">${esc(r.clerk_name ?? '—')}</td>
                            <td style="color:var(--muted);font-size:.82rem;">${fmtDate(r.adjusted_at)}</td>
                        </tr>`;
                    }).join('');
                    html += '</tbody></table>';

                } else if (type === 'expiring') {
                    html = '<table class="modal-table"><thead><tr><th>Product</th><th>Batch #</th><th>Remaining</th><th>Expiry Date</th><th>Days Left</th></tr></thead><tbody>';
                    html += data.map(r => {
                        const urgent = r.days_left <= 3;
                        const color  = urgent ? 'var(--danger)' : 'var(--warning)';
                        return `<tr>
                            <td style="font-weight:600;">${esc(r.product_name)}</td>
                            <td style="font-family:monospace;font-size:.82rem;">${esc(r.batch_number)}</td>
                            <td>${parseInt(r.remaining_qty)} ${esc(r.unit)}</td>
                            <td style="color:${color};font-weight:700;">${fmtDate(r.expiry_date)}</td>
                            <td style="color:${color};font-weight:700;">${r.days_left} day${r.days_left != 1 ? 's' : ''}</td>
                        </tr>`;
                    }).join('');
                    html += '</tbody></table>';
                }

                document.getElementById('modalBody').innerHTML = html;
                lucide.createIcons();
            } catch(e) {
                document.getElementById('modalBody').innerHTML = '<p class="modal-empty" style="color:var(--danger);">Failed to load data.</p>';
            }
        }
    </script>
</body>
</html>