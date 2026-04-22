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

require_once 'db.php';

// ── Summary Card Counts ───────────────────────────────────────

// Total orders (all time)
$totalOrders = $conn->query("SELECT COUNT(*) AS c FROM orders")->fetch_assoc()['c'];

// Pending orders (Under Review)
$pendingOrders = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'Under Review'")->fetch_assoc()['c'];

// Active return requests (Pending or Approved, not Resolved/Rejected)
$activeReturns = $conn->query("SELECT COUNT(*) AS c FROM returns WHERE status IN ('Pending','Approved')")->fetch_assoc()['c'];

// Active franchisee branches
$activeBranches = $conn->query("SELECT COUNT(*) AS c FROM franchisees")->fetch_assoc()['c'];

// ── Recent Order Requests (latest 5) ─────────────────────────
$recentOrders = [];
$roRes = $conn->query("
    SELECT o.id, o.po_number, o.status, o.total_amount, o.created_at,
           COALESCE(f.franchisee_name, uf.full_name, '—') AS franchisee_name,
           f.branch_name
    FROM orders o
    JOIN franchisees f ON f.id = o.franchisee_id
    LEFT JOIN users uf ON uf.user_id = f.user_id
    ORDER BY o.created_at DESC
    LIMIT 5
");
while ($row = $roRes->fetch_assoc()) { $recentOrders[] = $row; }

// ── Recent Activity Feed (latest 8 status history entries) ───
$recentActivity = [];
$raRes = $conn->query("
    SELECT h.status_label, h.detail, h.changed_at,
           o.po_number,
           u.full_name AS changed_by_name
    FROM order_status_history h
    JOIN orders o ON o.id = h.order_id
    LEFT JOIN users u ON u.user_id = h.changed_by
    ORDER BY h.changed_at DESC
    LIMIT 8
");
while ($row = $raRes->fetch_assoc()) { $recentActivity[] = $row; }

// ── Encoder Workload Summary ──────────────────────────────────
$encoderSummary = [];
$ewRes = $conn->query("
    SELECT u.full_name,
           COUNT(o.id) AS active_orders
    FROM users u
    LEFT JOIN orders o
        ON o.assigned_encoder_id = u.user_id
        AND o.status NOT IN ('completed','Rejected')
    WHERE u.role_id = 4 AND u.status = 'Active'
    GROUP BY u.user_id, u.full_name
    ORDER BY active_orders DESC, u.full_name ASC
");
while ($row = $ewRes->fetch_assoc()) { $encoderSummary[] = $row; }

$conn->close();

// ── Helpers ───────────────────────────────────────────────────
function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)         return 'Just now';
    if ($diff < 3600)       return floor($diff / 60) . ' min ago';
    if ($diff < 86400)      return floor($diff / 3600) . ' hr ago';
    if ($diff < 172800)     return 'Yesterday';
    return date('M d, Y', strtotime($datetime));
}

function activityIcon($label) {
    $map = [
        'Order Submitted' => ['icon' => 'plus',         'bg' => 'rgba(210,84,36,0.1)',   'color' => '#d25424'],
        'Under Review'    => ['icon' => 'clock',         'bg' => 'rgba(180,83,9,0.1)',    'color' => '#b45309'],
        'Approved'        => ['icon' => 'check-circle',  'bg' => 'rgba(22,101,52,0.1)',   'color' => '#166534'],
        'Rejected'        => ['icon' => 'x-circle',      'bg' => 'rgba(153,27,27,0.1)',   'color' => '#991b1b'],
        'Processing'      => ['icon' => 'package',       'bg' => 'rgba(59,130,246,0.1)',  'color' => '#3b82f6'],
        'Ready'           => ['icon' => 'package-check', 'bg' => 'rgba(16,185,129,0.1)',  'color' => '#10b981'],
        'Completed'       => ['icon' => 'check',         'bg' => 'rgba(16,185,129,0.1)',  'color' => '#10b981'],
    ];
    return $map[$label] ?? ['icon' => 'activity', 'bg' => 'rgba(140,131,125,0.1)', 'color' => '#8c837d'];
}

function statusPillClass($status) {
    $map = [
        'Under Review'     => 'pill-review',
        'Approved'         => 'pill-approved',
        'for_payment'      => 'pill-payment',
        'Processing'       => 'pill-processing',
        'out_for_delivery' => 'pill-delivery',
        'completed'        => 'pill-completed',
        'Rejected'         => 'pill-rejected',
    ];
    return $map[$status] ?? 'pill-review';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Top Juan Inc.</title>
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

        /* ── Sidebar ── */
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
        .logo-container { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2.5rem; }
        .logo-icon { width: 40px; height: 40px; background: var(--primary); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; }
        .logo-text h1 { font-family: 'Fraunces', serif; font-size: 1.25rem; line-height: 1; }
        .logo-text span { font-size: 0.75rem; color: var(--muted); font-weight: 500; }
        .menu-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); margin-bottom: 1rem; font-weight: 700; }
        nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
        .nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.875rem 1rem; border-radius: 12px; text-decoration: none; color: var(--muted); font-weight: 500; font-size: 0.95rem; transition: all 0.2s; }
        .nav-item i { width: 20px; height: 20px; stroke-width: 2px; }
        .nav-item:hover { color: var(--primary); background: rgba(92, 64, 51, 0.05); }
        .nav-item.active { background: var(--primary); color: white; }
        .user-profile { margin-top: auto; background: white; border: 1px solid var(--card-border); padding: 1rem; border-radius: 16px; display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb; overflow: hidden; display: flex; align-items: center; justify-content: center; }
        .avatar i { color: var(--muted); }
        .user-meta h4 { font-size: 0.85rem; font-weight: 700; }
        .user-meta p { font-size: 0.75rem; color: var(--muted); }
        .sign-out { display: flex; align-items: center; gap: 0.5rem; text-decoration: none; color: var(--muted); font-size: 0.9rem; padding: 0.5rem; transition: color 0.2s; }
        .sign-out:hover { color: var(--accent); }

        /* ── Main ── */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 2.5rem 3rem; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2.5rem; }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p { color: var(--muted); font-size: 1rem; }
        .header-date { font-size: 0.85rem; color: var(--muted); background: white; border: 1px solid var(--card-border); padding: 0.5rem 1rem; border-radius: 10px; }

        /* ── Summary Cards ── */
        .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2.5rem; }
        .summary-card { background: white; border: 1px solid var(--card-border); padding: 1.75rem; border-radius: 20px; position: relative; text-decoration: none; color: inherit; display: block; transition: box-shadow 0.2s, transform 0.2s; }
        .summary-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .summary-card .icon-badge { position: absolute; top: 1.75rem; right: 1.75rem; color: var(--muted); }
        .summary-card .label { font-size: 0.9rem; color: var(--muted); margin-bottom: 0.5rem; font-weight: 500; }
        .summary-card .value { font-size: 2rem; font-weight: 700; font-family: 'Fraunces', serif; }
        .summary-card .subtext { font-size: 0.8rem; color: var(--muted); margin-top: 0.5rem; }

        /* ── Content Grid ── */
        .content-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .card { background: white; border: 1px solid var(--card-border); border-radius: 20px; padding: 2rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }
        .card-link { font-size: 0.85rem; color: var(--primary-light); text-decoration: none; font-weight: 600; }
        .card-link:hover { text-decoration: underline; }

        /* ── Recent Orders Table ── */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 0.75rem 1rem; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); font-weight: 700; }
        td { padding: 1.1rem 1rem; font-size: 0.88rem; border-bottom: 1px solid var(--card-border); }
        tr:last-child td { border-bottom: none; }

        .status-pill { padding: 0.3rem 0.65rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .pill-review   { background: #fffbeb; color: #b45309; }
        .pill-approved { background: #f0fdf4; color: #166534; }
        .pill-payment  { background: #eff6ff; color: #1d4ed8; }
        .pill-processing{ background: rgba(210,84,36,.1); color: #d25424; }
        .pill-delivery { background: #fdf4ff; color: #7e22ce; }
        .pill-completed{ background: #f3f4f6; color: #4b5563; }
        .pill-rejected { background: #fef2f2; color: #991b1b; }

        .empty-state { text-align: center; padding: 2.5rem; color: var(--muted); font-size: 0.9rem; }

        /* ── Activity Feed ── */
        .activity-feed { display: flex; flex-direction: column; gap: 1.25rem; }
        .activity-item { display: flex; gap: 1rem; align-items: flex-start; }
        .activity-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .activity-content h4 { font-size: 0.88rem; font-weight: 600; margin-bottom: 0.2rem; }
        .activity-content p { font-size: 0.8rem; color: var(--muted); margin-bottom: 0.15rem; line-height: 1.4; }
        .activity-content span { font-size: 0.72rem; color: var(--muted); opacity: 0.75; }

        /* ── Encoder Workload Card ── */
        .encoder-row { display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 0; border-bottom: 1px solid var(--card-border); }
        .encoder-row:last-child { border-bottom: none; }
        .encoder-row-left { display: flex; align-items: center; gap: 0.65rem; }
        .enc-avatar { width: 30px; height: 30px; border-radius: 50%; background: #eff6ff; display: flex; align-items: center; justify-content: center; color: #1d4ed8; flex-shrink: 0; }
        .enc-name { font-weight: 600; font-size: 0.88rem; }
        .workload-bar-wrap { flex: 1; margin: 0 1rem; height: 6px; background: var(--card-border); border-radius: 99px; overflow: hidden; }
        .workload-bar { height: 100%; border-radius: 99px; transition: width 0.4s ease; }
        .bar-free  { background: #22c55e; }
        .bar-busy  { background: #f59e0b; }
        .bar-heavy { background: #ef4444; }
        .enc-count { font-size: 0.8rem; font-weight: 700; color: var(--muted); min-width: 48px; text-align: right; }

        @media (max-width: 1200px) {
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .content-grid { grid-template-columns: 1fr; }
        }
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
            <a href="admin-dashboard.php"   class="nav-item active"><i data-lucide="layout-dashboard"></i> Dashboard</a>
            <a href="admin-orders.php"      class="nav-item"><i data-lucide="clipboard-list"></i> Order Request</a>
            <a href="admin-usage.php"       class="nav-item"><i data-lucide="activity"></i> Item Usage</a>
            <a href="admin-maintenance.php" class="nav-item"><i data-lucide="settings-2"></i> Maintenance</a>
            <a href="admin-inventory.php"   class="nav-item"><i data-lucide="boxes"></i> Inventory</a>
            <a href="admin-returns.php"     class="nav-item"><i data-lucide="rotate-ccw"></i> Return and Refund</a>
            <a href="admin-delivery.php"    class="nav-item"><i data-lucide="truck"></i> Delivery</a>
            <a href="admin-reports.php"     class="nav-item"><i data-lucide="bar-chart-3"></i> Report</a>
        </nav>

        <div class="user-profile">
            <div class="avatar"><i data-lucide="user-cog"></i></div>
            <div class="user-meta">
                <h4><?php echo htmlspecialchars($adminName); ?></h4>
                <p>System Administrator</p>
            </div>
        </div>
        <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
    </aside>

    <main>
        <div class="header">
            <div>
                <h2>Dashboard Overview</h2>
                <p>Welcome back, <?php echo htmlspecialchars($adminName); ?>. Here's what's happening today.</p>
            </div>
            <div class="header-date">
                <?php echo date('l, F j, Y'); ?>
            </div>
        </div>

        <!-- ── Summary Cards ── -->
        <div class="summary-grid">
            <a href="admin-orders.php" class="summary-card">
                <i data-lucide="shopping-bag" class="icon-badge"></i>
                <p class="label">Total Orders</p>
                <div class="value"><?php echo number_format($totalOrders); ?></div>
                <p class="subtext">All time purchase orders</p>
            </a>
            <a href="admin-orders.php" class="summary-card">
                <i data-lucide="clock" class="icon-badge" style="color: var(--accent)"></i>
                <p class="label">Pending Order Reviews</p>
                <div class="value" style="<?php echo $pendingOrders > 0 ? 'color:var(--accent);' : ''; ?>"><?php echo $pendingOrders; ?></div>
                <p class="subtext"><?php echo $pendingOrders > 0 ? 'Requires your attention' : 'All caught up!'; ?></p>
            </a>
            <a href="admin-returns.php" class="summary-card">
                <i data-lucide="rotate-ccw" class="icon-badge" style="color: #ef4444"></i>
                <p class="label">Active Return Requests</p>
                <div class="value" style="<?php echo $activeReturns > 0 ? 'color:#ef4444;' : ''; ?>"><?php echo $activeReturns; ?></div>
                <p class="subtext"><?php echo $activeReturns > 0 ? 'Awaiting processing' : 'No active returns'; ?></p>
            </a>
            <a href="admin-orders.php" class="summary-card">
                <i data-lucide="store" class="icon-badge" style="color: #3b82f6"></i>
                <p class="label">Franchise Branches</p>
                <div class="value"><?php echo $activeBranches; ?></div>
                <p class="subtext">Registered branches</p>
            </a>
        </div>

        <!-- ── Recent Orders + Activity ── -->
        <div class="content-grid">

            <!-- Recent Order Requests -->
            <div class="card">
                <div class="card-header">
                    <h3>Recent Order Requests</h3>
                    <a href="admin-orders.php" class="card-link">View All →</a>
                </div>
                <?php if (empty($recentOrders)): ?>
                <div class="empty-state">No orders yet.</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Franchisee / Branch</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th style="text-align:right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td style="font-weight:700;"><?php echo htmlspecialchars($o['po_number']); ?></td>
                            <td>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($o['franchisee_name']); ?></div>
                                <div style="font-size:0.78rem;color:var(--muted);"><?php echo htmlspecialchars($o['branch_name']); ?></div>
                            </td>
                            <td style="color:var(--muted);font-size:.85rem;"><?php echo timeAgo($o['created_at']); ?></td>
                            <td><span class="status-pill <?php echo statusPillClass($o['status']); ?>"><?php echo htmlspecialchars($o['status']); ?></span></td>
                            <td style="text-align:right;font-weight:600;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Activity Feed -->
            <div class="card">
                <div class="card-header">
                    <h3>Activity Feed</h3>
                </div>
                <?php if (empty($recentActivity)): ?>
                <div class="empty-state">No recent activity.</div>
                <?php else: ?>
                <div class="activity-feed">
                    <?php foreach ($recentActivity as $a):
                        $ico = activityIcon($a['status_label']);
                    ?>
                    <div class="activity-item">
                        <div class="activity-icon" style="background:<?php echo $ico['bg']; ?>;color:<?php echo $ico['color']; ?>">
                            <i data-lucide="<?php echo $ico['icon']; ?>" size="16"></i>
                        </div>
                        <div class="activity-content">
                            <h4><?php echo htmlspecialchars($a['status_label']); ?> — <?php echo htmlspecialchars($a['po_number']); ?></h4>
                            <p><?php echo htmlspecialchars($a['detail']); ?></p>
                            <span><?php echo timeAgo($a['changed_at']); ?><?php echo $a['changed_by_name'] ? ' · ' . htmlspecialchars($a['changed_by_name']) : ''; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── Encoder Workload ── -->
        <div class="card">
            <div class="card-header">
                <h3>Encoder Workload</h3>
                <a href="admin-orders.php" class="card-link">Manage Assignments →</a>
            </div>
            <?php if (empty($encoderSummary)): ?>
            <div class="empty-state">No active data encoders.</div>
            <?php else:
                $maxOrders = max(array_column($encoderSummary, 'active_orders'));
                $maxOrders = max($maxOrders, 1); // prevent division by zero
            ?>
            <div>
                <?php foreach ($encoderSummary as $enc):
                    $count = (int)$enc['active_orders'];
                    $pct   = round(($count / $maxOrders) * 100);
                    if ($count === 0)     { $barClass = 'bar-free';  $countLabel = 'Available'; }
                    elseif ($count <= 2)  { $barClass = 'bar-busy';  $countLabel = $count . ' order' . ($count > 1 ? 's' : ''); }
                    else                  { $barClass = 'bar-heavy'; $countLabel = $count . ' orders'; }
                ?>
                <div class="encoder-row">
                    <div class="encoder-row-left" style="min-width:160px;">
                        <div class="enc-avatar"><i data-lucide="user" style="width:15px;height:15px;"></i></div>
                        <span class="enc-name"><?php echo htmlspecialchars($enc['full_name']); ?></span>
                    </div>
                    <div class="workload-bar-wrap">
                        <div class="workload-bar <?php echo $barClass; ?>" style="width:<?php echo ($count === 0 ? 4 : $pct); ?>%;"></div>
                    </div>
                    <span class="enc-count"><?php echo $countLabel; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <script>lucide.createIcons();</script>
</body>
</html>