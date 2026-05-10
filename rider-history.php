<?php
// ============================================================
// rider-history.php — Delivery History
// DB Tables used:
//   READ → orders WHERE status_step = 4 (Completed)
//   READ → franchisees (branch names)
//   READ → order_status_history (delivery timestamps)
//   READ → order_items + products (item summary)
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$riderId   = $_SESSION['user_id'];
$riderName = $_SESSION['full_name'] ?? 'Delivery Rider';

// Search / filter
// When no date is supplied (first page load) we show ALL completed deliveries.
$searchTerm  = $_GET['search']    ?? '';
$dateFrom    = $_GET['date_from'] ?? '';
$dateTo      = $_GET['date_to']   ?? '';
$hasDateFilter = $dateFrom !== '' || $dateTo !== '';

// ── Fetch completed deliveries ────────────────────────────────
$completedOrders = [];
$whereExtra = '';
$params = [$riderId];
$types  = 'i';

// Only apply date range when the user has actually set filters
// Use the actual delivery completion timestamp from order_status_history
if ($hasDateFilter) {
    $df = $dateFrom ?: '2000-01-01';
    $dt = $dateTo   ?: date('Y-m-d');
    $whereExtra .= " AND DATE(osh.changed_at) BETWEEN ? AND ?";
    $params[] = $df;
    $params[] = $dt;
    $types   .= 'ss';
}

if ($searchTerm) {
    $whereExtra .= " AND (o.po_number LIKE ? OR f.franchisee_name LIKE ? OR f.branch_name LIKE ?)";
    $like = '%' . $searchTerm . '%';
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}

$sql = "
    SELECT o.id, o.po_number, o.created_at, o.total_amount,
           o.delivery_preference, o.estimated_pickup, o.payment_method,
           f.branch_name, f.franchisee_name,
           COUNT(DISTINCT oi.id) as item_count,
           osh.changed_at AS delivered_at
    FROM orders o
    LEFT JOIN franchisees f ON f.id = o.franchisee_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN order_status_history osh
        ON osh.order_id = o.id AND osh.status_step = 4
    WHERE o.rider_id = ?
    AND o.status_step = 4
    AND o.delivery_preference != 'Self Pickup'
    $whereExtra
    GROUP BY o.id, osh.changed_at
    ORDER BY COALESCE(osh.changed_at, o.created_at) DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $completedOrders[] = $row; }
$stmt->close();

// ── Stats ──────────────────────────────────────────────────────
$totalDeliveries = count($completedOrders);
$totalValue      = array_sum(array_column($completedOrders, 'total_amount'));

// All-time count — scoped to this rider
$stmtAt = $conn->prepare("SELECT COUNT(*) as cnt FROM orders WHERE rider_id = ? AND status_step = 4");
$stmtAt->bind_param("i", $riderId);
$stmtAt->execute();
$allTime = $stmtAt->get_result()->fetch_assoc()['cnt'] ?? 0;
$stmtAt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery History - Top Juan Inc.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root{--background:#f7f3f0;--foreground:#2d241e;--sidebar-bg:#fdfaf7;--card:#ffffff;--card-border:#eeeae6;--primary:#5c4033;--primary-light:#8b5e3c;--accent:#d25424;--muted:#8c837d;--radius:16px;--sidebar-width:280px;}
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'DM Sans',sans-serif;background-color:var(--background);color:var(--foreground);display:flex;min-height:100vh;}
        aside{width:var(--sidebar-width);background:var(--sidebar-bg);border-right:1px solid var(--card-border);padding:2rem 1.5rem;display:flex;flex-direction:column;position:fixed;height:100vh;z-index:10;}
        .logo-container{display:flex;align-items:center;gap:.75rem;margin-bottom:2.5rem;}
        .logo-icon{width:40px;height:40px;background:var(--primary);border-radius:10px;display:flex;align-items:center;justify-content:center;color:white;}
        .logo-text h1{font-family:'Fraunces',serif;font-size:1.25rem;line-height:1;}
        .logo-text span{font-size:.75rem;color:var(--muted);font-weight:500;}
        .menu-label{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:1rem;font-weight:700;}
        nav{display:flex;flex-direction:column;gap:.25rem;flex:1;}
        .nav-item{display:flex;align-items:center;gap:.75rem;padding:.875rem 1rem;border-radius:12px;text-decoration:none;color:var(--muted);font-weight:500;font-size:.95rem;transition:all .2s;}
        .nav-item i{width:20px;height:20px;stroke-width:2px;}
        .nav-item:hover{color:var(--primary);background:rgba(92,64,51,.05);}
        .nav-item.active{background:var(--primary);color:white;}
        .user-profile{margin-top:auto;background:white;border:1px solid var(--card-border);padding:1rem;border-radius:16px;display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;}
        .avatar{width:36px;height:36px;border-radius:50%;background:#e5e7eb;overflow:hidden;display:flex;align-items:center;justify-content:center;}
        .avatar i{color:var(--muted);}
        .user-meta h4{font-size:.85rem;font-weight:700;}
        .user-meta p{font-size:.75rem;color:var(--muted);}
        .sign-out{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--muted);font-size:.9rem;padding:.5rem;}
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{margin-bottom:2rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);}
        /* Stats */
        .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:2rem;}
        .stat-card{background:white;border:1px solid var(--card-border);border-radius:16px;padding:1.5rem;position:relative;}
        .stat-card .ic{position:absolute;top:1.25rem;right:1.25rem;}
        .stat-card .lbl{font-size:.88rem;color:var(--muted);margin-bottom:.4rem;font-weight:500;}
        .stat-card .val{font-size:2rem;font-weight:700;font-family:'Fraunces',serif;}
        .stat-card .sub{font-size:.78rem;color:var(--muted);margin-top:.3rem;}
        /* Filter bar */
        .filter-bar{background:white;border:1px solid var(--card-border);border-radius:16px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;}
        .filter-group{display:flex;flex-direction:column;gap:.35rem;}
        .filter-group label{font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
        .filter-group input,.filter-group select{padding:.6rem .9rem;border:1.5px solid var(--card-border);border-radius:10px;font-family:inherit;font-size:.9rem;outline:none;}
        .search-wrap{flex:1;position:relative;}
        .search-wrap i{position:absolute;left:.9rem;top:50%;transform:translateY(-50%);color:var(--muted);width:16px;height:16px;}
        .search-wrap input{width:100%;padding:.65rem .9rem .65rem 2.5rem;border:1.5px solid var(--card-border);border-radius:10px;font-family:inherit;font-size:.9rem;outline:none;}
        .btn-filter{background:var(--primary);color:white;border:none;padding:.65rem 1.25rem;border-radius:10px;font-weight:600;font-family:inherit;font-size:.88rem;cursor:pointer;align-self:flex-end;}
        .btn-clear{background:transparent;color:var(--muted);border:1.5px solid var(--card-border);padding:.65rem 1rem;border-radius:10px;font-weight:600;font-family:inherit;font-size:.88rem;cursor:pointer;text-decoration:none;align-self:flex-end;display:inline-block;}
        /* Table */
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;overflow:hidden;}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:1rem 1.5rem;font-size:.78rem;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--card-border);font-weight:700;letter-spacing:.04em;}
        td{padding:1.1rem 1.5rem;font-size:.9rem;border-bottom:1px solid var(--card-border);}
        tr:last-child td{border-bottom:none;}
        tr:hover td{background:#fafafa;}
        .pill-done{background:#dcfce7;color:#166534;padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-block;}
        .pill-pickup{background:#f0fdf4;color:#166634;padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-block;}
        .view-link{color:var(--primary);text-decoration:none;font-weight:600;font-size:.85rem;}
        .view-link:hover{text-decoration:underline;}
        .empty-row td{text-align:center;color:var(--muted);padding:3rem;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="truck"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Delivery Rider</span></div></div>
    <p class="menu-label">Main Menu</p>
    <nav>
        <a href="rider-assignment.php" class="nav-item"><i data-lucide="clipboard-list"></i>Assignment</a>
        <a href="rider-tracking.php" class="nav-item"><i data-lucide="map-pin"></i>Delivery Tracking</a>
        <a href="rider-profile.php" class="nav-item"><i data-lucide="user"></i>Profile</a>
        <a href="rider-history.php" class="nav-item active"><i data-lucide="history"></i>Delivery History</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($riderName); ?></h4><p>Delivery Rider</p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i><span>Sign Out</span></a>
</aside>

<main>
    <div class="header"><h2>Delivery History</h2><p>Record of all completed deliveries.</p></div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <i data-lucide="check-circle" class="ic" style="color:#10b981"></i>
            <div class="lbl"><?php echo $hasDateFilter ? 'This Period' : 'All Deliveries'; ?></div>
            <div class="val"><?php echo $totalDeliveries; ?></div>
            <div class="sub"><?php echo $hasDateFilter ? (date('M d', strtotime($dateFrom ?: '2000-01-01')) . ' – ' . date('M d, Y', strtotime($dateTo ?: date('Y-m-d')))) : 'All time, all branches'; ?></div>
        </div>
        <div class="stat-card">
            <i data-lucide="trending-up" class="ic" style="color:#3b82f6"></i>
            <div class="lbl">Value Delivered</div>
            <div class="val">₱<?php echo number_format($totalValue, 0); ?></div>
            <div class="sub">This period total</div>
        </div>
        <div class="stat-card">
            <i data-lucide="package" class="ic" style="color:var(--primary)"></i>
            <div class="lbl">All-Time Deliveries</div>
            <div class="val"><?php echo $allTime; ?></div>
            <div class="sub">Total completed orders</div>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="rider-history.php" class="filter-bar">
        <div class="filter-group" style="flex:1;">
            <label>Search</label>
            <div class="search-wrap">
                <i data-lucide="search"></i>
                <input type="text" name="search" placeholder="PO number or branch..." value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
        </div>
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <button type="submit" class="btn-filter">Filter</button>
        <?php if ($hasDateFilter || $searchTerm): ?>
        <a href="rider-history.php" class="btn-clear">Clear</a>
        <?php endif; ?>
    </form>

    <!-- History Table -->
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>P.O. Number</th>
                    <th>Franchisee / Branch</th>
                    <th>Items</th>
                    <th>Type</th>
                    <th>Payment</th>
                    <th>Date Completed</th>
                    <th style="text-align:right">Amount</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($completedOrders)): ?>
                <tr class="empty-row"><td colspan="8">No deliveries found for this period.</td></tr>
                <?php else: foreach ($completedOrders as $o): ?>
                <tr>
                    <td style="font-weight:700;"><?php echo htmlspecialchars($o['po_number']); ?></td>
                    <td>
                        <div style="font-weight:600;font-size:.875rem;"><?php echo htmlspecialchars($o['franchisee_name'] ?? '—'); ?></div>
                        <div style="font-size:.78rem;color:var(--muted);"><?php echo htmlspecialchars($o['branch_name'] ?? '—'); ?></div>
                    </td>
                    <td style="font-size:.88rem;"><?php echo $o['item_count']; ?> item<?php echo $o['item_count'] != 1 ? 's' : ''; ?></td>
                    <td>
                        <span class="pill-done"><?php echo htmlspecialchars($o['delivery_preference']); ?></span>
                    </td>
                    <td>
                        <?php
                        $pm = strtolower($o['payment_method'] ?? '');
                        $isCod = str_contains($pm, 'cod') || str_contains($pm, 'cash');
                        echo '<span style="background:' . ($isCod ? '#fef3c7' : '#dcfce7') . ';color:' . ($isCod ? '#92400e' : '#166534') . ';padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-block;">'
                            . ($isCod ? '💵 ' : '✓ ') . htmlspecialchars($o['payment_method'] ?? '—') . '</span>';
                        ?>
                    </td>
                    <td style="font-size:.875rem;color:var(--muted);"><?php echo date('M d, Y', strtotime($o['delivered_at'] ?? $o['created_at'])); ?></td>
                    <td style="text-align:right;font-weight:700;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                    <td><a href="rider-tracking.php?po=<?php echo urlencode($o['po_number']); ?>" class="view-link">View →</a></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</main>
<script>lucide.createIcons();</script>
</body>
</html>