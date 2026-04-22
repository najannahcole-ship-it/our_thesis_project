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

// ── DATABASE ─────────────────────────────────────────────────────────────────
require_once 'db.php';

// ── FILTERS ──────────────────────────────────────────────────────────────────
$filter_franchisee = isset($_GET['franchisee']) ? intval($_GET['franchisee']) : 0;
$filter_range      = isset($_GET['range'])      ? $_GET['range']              : 'all';
$filter_search     = isset($_GET['search'])     ? trim($_GET['search'])       : '';

// Date range
$date_from = match($filter_range) {
    '7'   => date('Y-m-d', strtotime('-7 days')),
    '30'  => date('Y-m-d', strtotime('-30 days')),
    '90'  => date('Y-m-d', strtotime('-90 days')),
    'all' => '2000-01-01',
    default => date('Y-m-d', strtotime('-30 days')),
};

// ── STATS ─────────────────────────────────────────────────────────────────────
$total_submissions = $conn->query("SELECT COUNT(*) c FROM item_usage WHERE recording_date >= '$date_from'")->fetch_assoc()['c'];
$total_qty_used    = $conn->query("SELECT SUM(quantity_used) s FROM item_usage WHERE recording_date >= '$date_from'")->fetch_assoc()['s'] ?? 0;
$active_branches   = $conn->query("SELECT COUNT(DISTINCT franchisee_id) c FROM item_usage WHERE recording_date >= '$date_from'")->fetch_assoc()['c'];
$unique_items      = $conn->query("SELECT COUNT(DISTINCT product_id) c FROM item_usage WHERE recording_date >= '$date_from'")->fetch_assoc()['c'];

// ── FRANCHISEE LIST for filter dropdown ──────────────────────────────────────
$franchisee_res = $conn->query("
    SELECT f.id, f.branch_name,
           COALESCE(f.franchisee_name, u.full_name, 'Unknown') AS franchisee_name
    FROM franchisees f
    LEFT JOIN users u ON u.user_id = f.user_id
    ORDER BY f.branch_name
");
$franchisees = [];
while ($f = $franchisee_res->fetch_assoc()) { $franchisees[] = $f; }

// ── MAIN USAGE QUERY ─────────────────────────────────────────────────────────
$where_parts = ["iu.recording_date >= '$date_from'"];
$params      = [];
$types       = '';

if ($filter_franchisee > 0) {
    $where_parts[] = "iu.franchisee_id = ?";
    $params[]      = $filter_franchisee;
    $types        .= 'i';
}
if ($filter_search !== '') {
    $like           = '%' . $conn->real_escape_string($filter_search) . '%';
    $where_parts[]  = "(p.name LIKE '$like' OR p.category LIKE '$like' OR f.branch_name LIKE '$like' OR f.franchisee_name LIKE '$like')";
}

$where_sql = implode(' AND ', $where_parts);

$sql = "
    SELECT iu.id, iu.quantity_used, iu.unit, iu.recording_date, iu.submitted_at,
           p.name AS product_name, p.category,
           COALESCE(f.franchisee_name, u.full_name, 'Unknown') AS franchisee_name,
           f.branch_name,
           o.po_number
    FROM item_usage iu
    LEFT JOIN products    p ON iu.product_id    = p.id
    LEFT JOIN franchisees f ON iu.franchisee_id = f.id
    LEFT JOIN users       u ON u.user_id        = f.user_id
    LEFT JOIN orders      o ON o.id             = iu.order_id
    WHERE $where_sql
    ORDER BY iu.recording_date DESC, iu.submitted_at DESC, iu.id DESC
";

if ($types) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $usage_res = $stmt->get_result();
} else {
    $usage_res = $conn->query($sql);
}

// ── TOP ITEMS by total quantity ───────────────────────────────────────────────
$top_items_res = $conn->query("
    SELECT p.name AS product_name, p.category, SUM(iu.quantity_used) AS total_qty, iu.unit,
           COUNT(iu.id) AS submission_count
    FROM item_usage iu
    LEFT JOIN products p ON iu.product_id = p.id
    WHERE iu.recording_date >= '$date_from'
    GROUP BY iu.product_id, p.name, p.category, iu.unit
    ORDER BY total_qty DESC
    LIMIT 5
");

// ── TOP BRANCHES by submission count ─────────────────────────────────────────
$top_branches_res = $conn->query("
    SELECT COALESCE(f.franchisee_name, u.full_name, 'Unknown') AS franchisee_name,
           f.branch_name, COUNT(iu.id) AS submissions,
           SUM(iu.quantity_used) AS total_qty
    FROM item_usage iu
    LEFT JOIN franchisees f ON iu.franchisee_id = f.id
    LEFT JOIN users       u ON u.user_id        = f.user_id
    WHERE iu.recording_date >= '$date_from'
    GROUP BY iu.franchisee_id, f.branch_name, f.franchisee_name, u.full_name
    ORDER BY submissions DESC
    LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Usage Monitoring - Top Juan Inc.</title>
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
            --sidebar-width: 280px;
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
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; }
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
        .view-only-badge { background: #f3f4f6; color: #6b7280; padding: 0.35rem 0.75rem; border-radius: 8px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; border: 1px solid var(--card-border); padding: 1.5rem; border-radius: 20px; }
        .stat-card .stat-label { font-size: 0.75rem; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.5rem; display: block; }
        .stat-card .value { font-size: 1.75rem; font-weight: 700; font-family: 'Fraunces', serif; }
        .stat-card .sub { font-size: 0.78rem; color: var(--muted); margin-top: 0.3rem; }

        /* Filter bar */
        .filter-bar { display: flex; gap: 1rem; margin-bottom: 1.5rem; align-items: center; flex-wrap: wrap; }
        .search-box { flex: 1; min-width: 220px; position: relative; }
        .search-box i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--muted); width: 18px; }
        .search-box input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.75rem; border-radius: 12px; border: 1px solid var(--card-border); font-family: inherit; font-size: 0.9rem; background: white; }
        .filter-select { padding: 0.75rem 1rem; border-radius: 12px; border: 1px solid var(--card-border); background: white; font-family: inherit; font-size: 0.9rem; color: var(--foreground); cursor: pointer; }
        .btn-filter { padding: 0.75rem 1.5rem; border-radius: 12px; border: none; background: var(--primary); color: white; font-family: inherit; font-weight: 600; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: opacity 0.2s; }
        .btn-filter:hover { opacity: 0.9; }

        /* Two-column layout */
        .content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }

        /* Card */
        .card { background: white; border: 1px solid var(--card-border); border-radius: 20px; overflow: hidden; margin-bottom: 2rem; }
        .card-inner { padding: 2rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 2rem; border-bottom: 1px solid var(--card-border); }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }
        .card-header span { font-size: 0.85rem; color: var(--muted); }

        /* Table */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1rem 1.5rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); background: #faf8f6; white-space: nowrap; }
        td { padding: 1.1rem 1.5rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fdfaf7; }

        .category-tag { font-size: 0.72rem; color: var(--muted); background: var(--background); border: 1px solid var(--card-border); padding: 2px 8px; border-radius: 6px; margin-left: 6px; }

        /* Top items/branches list */
        .rank-list { display: flex; flex-direction: column; }
        .rank-item { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; border-bottom: 1px solid var(--card-border); }
        .rank-item:last-child { border-bottom: none; }
        .rank-num { width: 28px; height: 28px; border-radius: 8px; background: var(--background); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; color: var(--muted); flex-shrink: 0; }
        .rank-num.top { background: var(--primary); color: white; }
        .rank-info { flex: 1; }
        .rank-info h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 0.1rem; }
        .rank-info p { font-size: 0.78rem; color: var(--muted); }
        .rank-qty { font-weight: 700; font-size: 0.9rem; color: var(--primary); text-align: right; }
        .rank-qty span { display: block; font-size: 0.75rem; color: var(--muted); font-weight: 400; }

        .empty-state { text-align: center; color: var(--muted); padding: 3rem 1rem; }

        @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2,1fr); } .content-grid { grid-template-columns: 1fr; } }
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
            <a href="admin-usage.php" class="nav-item active"><i data-lucide="activity"></i> Item Usage</a>
            <a href="admin-maintenance.php" class="nav-item"><i data-lucide="settings-2"></i> Maintenance</a>
            <a href="admin-inventory.php" class="nav-item"><i data-lucide="boxes"></i> Inventory</a>
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
                <h2>Item Usage Monitoring</h2>
                <p>Real-time visibility of branch-level consumption &mdash; View Only</p>
            </div>
            <div class="view-only-badge">Read Only Access</div>
        </div>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Total Submissions</span>
                <div class="value"><?= number_format($total_submissions) ?></div>
                <p class="sub">In selected period</p>
            </div>
            <div class="stat-card">
                <span class="stat-label">Total Qty Used</span>
                <div class="value"><?= number_format($total_qty_used) ?></div>
                <p class="sub">Units across all items</p>
            </div>
            <div class="stat-card">
                <span class="stat-label">Active Branches</span>
                <div class="value"><?= number_format($active_branches) ?></div>
                <p class="sub">With submissions</p>
            </div>
            <div class="stat-card">
                <span class="stat-label">Unique Items Used</span>
                <div class="value"><?= number_format($unique_items) ?></div>
                <p class="sub">Distinct products</p>
            </div>
        </div>

        <!-- FILTERS -->
        <form method="GET" action="admin-usage.php">
            <div class="filter-bar">
                <div class="search-box">
                    <i data-lucide="search"></i>
                    <input type="text" name="search" placeholder="Search by item, category, or branch..."
                        value="<?= htmlspecialchars($filter_search) ?>">
                </div>
                <select class="filter-select" name="franchisee">
                    <option value="0">All Branches</option>
                    <?php foreach ($franchisees as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= $filter_franchisee == $f['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['branch_name']) ?><?= $f['franchisee_name'] ? ' — ' . htmlspecialchars($f['franchisee_name']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" name="range">
                    <option value="7"  <?= $filter_range === '7'   ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30" <?= $filter_range === '30'  ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="90" <?= $filter_range === '90'  ? 'selected' : '' ?>>Last 90 Days</option>
                    <option value="all"<?= $filter_range === 'all' ? 'selected' : '' ?>>All Time</option>
                </select>
                <button type="submit" class="btn-filter"><i data-lucide="filter"></i> Apply</button>
            </div>
        </form>

        <!-- TOP ITEMS & TOP BRANCHES -->
        <div class="content-grid">
            <div class="card">
                <div class="card-header">
                    <h3>Top Items by Usage</h3>
                    <span>By total quantity</span>
                </div>
                <div class="rank-list">
                <?php if ($top_items_res && $top_items_res->num_rows > 0):
                    $rank = 1;
                    while ($item = $top_items_res->fetch_assoc()): ?>
                    <div class="rank-item">
                        <div class="rank-num <?= $rank === 1 ? 'top' : '' ?>"><?= $rank ?></div>
                        <div class="rank-info">
                            <h4><?= htmlspecialchars($item['product_name'] ?? 'Unknown') ?></h4>
                            <p><?= htmlspecialchars($item['category'] ?? '') ?> &middot; <?= $item['submission_count'] ?> submissions</p>
                        </div>
                        <div class="rank-qty">
                            <?= number_format($item['total_qty']) ?>
                            <span><?= htmlspecialchars($item['unit']) ?></span>
                        </div>
                    </div>
                    <?php $rank++; endwhile;
                else: ?>
                    <p class="empty-state">No data for this period.</p>
                <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Most Active Branches</h3>
                    <span>By submissions</span>
                </div>
                <div class="rank-list">
                <?php if ($top_branches_res && $top_branches_res->num_rows > 0):
                    $rank = 1;
                    while ($branch = $top_branches_res->fetch_assoc()): ?>
                    <div class="rank-item">
                        <div class="rank-num <?= $rank === 1 ? 'top' : '' ?>"><?= $rank ?></div>
                        <div class="rank-info">
                            <h4><?= htmlspecialchars($branch['franchisee_name'] ?? 'Unknown') ?></h4>
                            <p><?= htmlspecialchars($branch['branch_name'] ?? '') ?></p>
                        </div>
                        <div class="rank-qty">
                            <?= number_format($branch['submissions']) ?>
                            <span>submissions</span>
                        </div>
                    </div>
                    <?php $rank++; endwhile;
                else: ?>
                    <p class="empty-state">No data for this period.</p>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- MAIN USAGE TABLE -->
        <div class="card">
            <div class="card-header">
                <h3>All Usage Records</h3>
                <span>
                    <?php
                    $row_count = $usage_res ? $usage_res->num_rows : 0;
                    echo $row_count . ' ' . ($row_count === 1 ? 'record' : 'records');
                    ?>
                </span>
            </div>
            <?php if ($row_count > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Qty Used</th>
                        <th>Unit</th>
                        <th>Date Recorded</th>
                        <th>Submitted At</th>
                        <th>Linked Order</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $usage_res->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($row['branch_name'] ?? 'Unknown') ?></div>
                            <div style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($row['franchisee_name'] ?? '') ?></div>
                        </td>
                        <td style="font-weight:500;"><?= htmlspecialchars($row['product_name'] ?? 'Unknown') ?></td>
                        <td>
                            <span class="category-tag"><?= htmlspecialchars($row['category'] ?? '—') ?></span>
                        </td>
                        <td style="font-weight:700;"><?= number_format($row['quantity_used']) ?></td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($row['unit']) ?></td>
                        <td><?= date('M j, Y', strtotime($row['recording_date'])) ?></td>
                        <td style="color:var(--muted);font-size:.85rem;">
                            <?= $row['submitted_at'] ? date('M j, Y g:i A', strtotime($row['submitted_at'])) : '—' ?>
                        </td>
                        <td style="font-size:.85rem;">
                            <?php if (!empty($row['po_number'])): ?>
                                <span style="background:#eff6ff;color:#1d4ed8;font-weight:600;padding:.2rem .6rem;border-radius:6px;font-size:.78rem;">
                                    <?= htmlspecialchars($row['po_number']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="empty-state">No usage records found for the selected filters.</p>
            <?php endif; ?>
        </div>
    </main>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
<?php $conn->close(); ?>