<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: index.php');
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/db.php';

$clerkName   = $_SESSION['full_name'] ?? 'Inventory Clerk';
$clerkUserId = $_SESSION['user_id'];

// ── Filter inputs ────────────────────────────────────────────
$dateRange = $_GET['date_range'] ?? '30';   // 7, 30, 90
$category  = $_GET['category']   ?? '';     // '' = all

// Compute date boundaries
switch ($dateRange) {
    case '7':  $dateFrom = date('Y-m-d', strtotime('-7 days'));  $rangeLabel = 'Last 7 Days';  break;
    case '90': $dateFrom = date('Y-m-d', strtotime('-90 days')); $rangeLabel = 'Last 90 Days'; break;
    default:   $dateFrom = date('Y-m-d', strtotime('-30 days')); $rangeLabel = 'Last 30 Days'; $dateRange = '30';
}
$dateTo = date('Y-m-d');

// ── Category filter clause for products table ────────────────
$catClause = '';
$catParam  = '';
if ($category !== '') {
    $catClause = "AND p.category = ?";
    $catParam  = $category;
}

// ── All product categories for dropdown ─────────────────────
$categories = [];
$res = $conn->query("SELECT DISTINCT category FROM products WHERE status='available' ORDER BY category");
while ($r = $res->fetch_row()) $categories[] = $r[0];

// ── Stock Movement Trend: daily inflow (receipts) + outflow (adjustments) ──
// Build date spine for the period
$trendDays = (int)$dateRange;
$trendLabels = [];
$trendInflow  = [];
$trendOutflow = [];

for ($i = $trendDays - 1; $i >= 0; $i--) {
    $trendLabels[] = date('M d', strtotime("-$i days"));
}

// Inflow per day (use created_at — arrival_date can be NULL)
$inflowMap = [];
$catJoin = $catParam !== '' ? "JOIN products p ON p.id = sr.product_id AND p.category = '$catParam'" : '';
$sql = "SELECT DATE(sr.created_at) AS d, SUM(sr.quantity) AS total
        FROM stock_receipts sr
        $catJoin
        WHERE DATE(sr.created_at) BETWEEN ? AND ?
        GROUP BY DATE(sr.created_at)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $inflowMap[$r['d']] = (float)$r['total'];
$stmt->close();

// Outflow per day
$outflowMap = [];
$catJoin2 = $catParam !== '' ? "JOIN products p ON p.id = sa.product_id AND p.category = '$catParam'" : '';
$sql = "SELECT DATE(sa.adjusted_at) AS d, SUM(sa.quantity) AS total
        FROM stock_adjustments sa
        $catJoin2
        WHERE DATE(sa.adjusted_at) BETWEEN ? AND ?
        GROUP BY DATE(sa.adjusted_at)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $outflowMap[$r['d']] = (float)$r['total'];
$stmt->close();

for ($i = $trendDays - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendInflow[]  = $inflowMap[$d]  ?? 0;
    $trendOutflow[] = $outflowMap[$d] ?? 0;
}

// ── Item Movement Details table ──────────────────────────────
// stock_in  = receipts (created_at)
// stock_out = inventory_logs action='deduct' by clerk (role_id=3)
// total_adj = stock_adjustments
$movementSQL = "
    SELECT p.id, p.name, p.category, p.unit, p.stock_qty AS closing_stock,
           COALESCE(si.total_in,  0) AS stock_in,
           COALESCE(so.total_out, 0) AS stock_out,
           COALESCE(aj.total_adj, 0) AS total_adj
    FROM products p
    LEFT JOIN (
        SELECT product_id, SUM(quantity) AS total_in
        FROM stock_receipts
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY product_id
    ) si ON si.product_id = p.id
    LEFT JOIN (
        SELECT product_id, SUM(quantity) AS total_out
        FROM inventory_logs
        WHERE action = 'deduct'
          AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY product_id
    ) so ON so.product_id = p.id
    LEFT JOIN (
        SELECT product_id, SUM(quantity) AS total_adj
        FROM stock_adjustments
        WHERE DATE(adjusted_at) BETWEEN ? AND ?
        GROUP BY product_id
    ) aj ON aj.product_id = p.id
    WHERE p.status = 'available'
    $catClause
    ORDER BY (COALESCE(si.total_in,0) + COALESCE(so.total_out,0)) DESC, p.name ASC
    LIMIT 50
";

$stmt = $conn->prepare($movementSQL);
if ($catParam !== '') {
    $stmt->bind_param("sssssss", $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo, $catParam);
} else {
    $stmt->bind_param("ssssss", $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo);
}
$stmt->execute();
$movementRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── CSV export ───────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_report_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Item Name', 'Category', 'Unit', 'Stock In', 'Stock Out (Logs)', 'Current Stock']);
    foreach ($movementRows as $row) {
        fputcsv($out, [
            $row['name'],
            $row['category'],
            $row['unit'],
            (int)$row['stock_in'],
            (int)$row['stock_out'],
            (int)$row['closing_stock'],
        ]);
    }
    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Reports - Top Juan Inc.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --error: #dc2626;
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

        /*Main Content*/
        
        main {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 2.5rem 3rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 2.5rem;
        }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p { color: var(--muted); font-size: 1rem; }

        /* Report Controls */
        .report-controls {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            background: white;
            padding: 1.5rem;
            border-radius: 20px;
            border: 1px solid var(--card-border);
            margin-bottom: 2rem;
            align-items: flex-end;
        }
        .control-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .control-group label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--muted); }
        .control-group select, .control-group input {
            padding: 0.75rem;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            font-family: inherit;
        }
        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .card {
            background: white;
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 2rem;
        }
        .card-title {
            font-family: 'Fraunces', serif;
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Chart Placeholder */
        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Report Table */
        .report-table-card {
            grid-column: span 2;
        }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th { text-align: left; padding: 1rem; font-size: 0.75rem; text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--card-border); }
        td { padding: 1rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border); }

        .trend-up { color: var(--success); font-weight: 600; }
        .trend-down { color: var(--error); font-weight: 600; }

        .export-btn {
            font-size: 0.85rem;
            color: var(--accent);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 600;
        }
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
            <a href="clerk-dashboard.php" class="nav-item" data-testid="link-clerk-dashboard"><i data-lucide="layout-dashboard"></i> Dashboard</a>
            <a href="clerk-orders.php" class="nav-item" data-testid="link-order-monitoring"><i data-lucide="clipboard-list"></i> Order Monitoring</a>
            <a href="clerk-inventory.php" class="nav-item" data-testid="link-clerk-inventory"><i data-lucide="boxes"></i> Inventory</a>
            <a href="clerk-receiving.php" class="nav-item" data-testid="link-stock-receiving"><i data-lucide="download"></i> Stock Receiving</a>
            <a href="clerk-adjustment.php" class="nav-item" data-testid="link-stock-adjustment"><i data-lucide="edit-3"></i> Stock Adjustment</a>
            <a href="clerk-reports.php" class="nav-item active" data-testid="link-reports"><i data-lucide="bar-chart-3"></i> Reports</a>
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
                <h2 data-testid="text-page-title">Inventory Reports</h2>
                <p>Generate data-driven insights on stock movement and replenishment</p>
            </div>

        </div>

        <form method="GET" action="clerk-reports.php" class="report-controls" data-testid="card-report-filters">
            <div class="control-group">
                <label>Date Range</label>
                <select name="date_range" data-testid="select-date-range" onchange="this.form.submit()">
                    <option value="7"  <?= $dateRange==='7'  ? 'selected':'' ?>>Last 7 Days</option>
                    <option value="30" <?= $dateRange==='30' ? 'selected':'' ?>>Last 30 Days</option>
                    <option value="90" <?= $dateRange==='90' ? 'selected':'' ?>>Last 90 Days</option>
                </select>
            </div>
            <div class="control-group">
                <label>Category</label>
                <select name="category" data-testid="select-report-category" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $category===$cat ? 'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="control-group">
                <label>Period</label>
                <input type="text" value="<?= htmlspecialchars($dateFrom) ?> → <?= $dateTo ?>" readonly style="background:#faf9f8;color:var(--muted);">
            </div>
        </form>

        <div class="dashboard-grid">
            <div class="card" data-testid="card-movement-chart">
                <div class="card-title">Stock Movement Trend</div>
                <div class="chart-container">
                    <canvas id="movementChart"></canvas>
                </div>
            </div>

            <div class="card report-table-card" data-testid="card-report-table">
                <div class="card-title">
                    Item Movement Details <span style="font-size:0.8rem;color:var(--muted);font-family:'DM Sans'"><?= $rangeLabel ?></span>
                    <a href="clerk-reports.php?export=csv&date_range=<?= $dateRange ?>&category=<?= urlencode($category) ?>" class="export-btn"><i data-lucide="file-text" size="14"></i> Export CSV</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Stock In</th>
                            <th>Stock Out (Issues)</th>
                            <th>Current Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($movementRows)): ?>
                        <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:2rem;">No movement data found for this period.</td></tr>
                    <?php else: foreach ($movementRows as $row):
                        $stockIn  = (int)$row['stock_in'];
                        $stockOut = (int)$row['stock_out'];
                        $closing  = (int)$row['closing_stock'];
                        $unit     = htmlspecialchars($row['unit']);
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['name']) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars($row['category']) ?></small></td>
                            <td class="trend-up"><?= $stockIn > 0 ? '+' . $stockIn . ' ' . $unit : '—' ?></td>
                            <td class="trend-down"><?= $stockOut > 0 ? '−' . $stockOut . ' ' . $unit : '—' ?></td>
                            <td><strong><?= $closing . ' ' . $unit ?></strong></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();

        const trendLabels  = <?= json_encode($trendLabels) ?>;
        const trendInflow  = <?= json_encode($trendInflow) ?>;
        const trendOutflow = <?= json_encode($trendOutflow) ?>;

        const ctxMovement = document.getElementById('movementChart').getContext('2d');
        new Chart(ctxMovement, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Stock In',
                    data: trendInflow,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: trendLabels.length <= 30 ? 3 : 1,
                    pointHoverRadius: 5,
                }, {
                    label: 'Adjustments Out',
                    data: trendOutflow,
                    borderColor: '#d25424',
                    backgroundColor: 'rgba(210, 84, 36, 0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: trendLabels.length <= 30 ? 3 : 1,
                    pointHoverRadius: 5,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y}`
                        }
                    }
                },
                scales: {
                    x: { ticks: { maxTicksLimit: 10, maxRotation: 0 } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });

    </script>
</body>
</html>