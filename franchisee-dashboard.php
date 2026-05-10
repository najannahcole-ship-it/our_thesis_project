<?php
// ============================================================
// franchisee-dashboard.php — Franchisee Main Dashboard
// DB Tables used:
//   READ → users        (get full_name)
//   READ → franchisees  (get branch_name via user_id link)
//   READ → orders       (count pending, ready; list recent 5)
// ============================================================

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$userId   = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Franchisee';

$franchisee   = getFranchiseeByUser($conn, $userId);
$franchiseeId = $franchisee['id']          ?? null;
$branchName   = $franchisee['branch_name'] ?? 'Your Branch';

// ── Dashboard stats ──────────────────────────────────────────
$statPending = 0;  // orders with status_step 1 or 2
$statReady   = 0;  // orders with status_step 3
$statTotal   = 0;  // total orders ever
$totalSpent  = 0;  // sum of all order totals

// Recent 5 orders for the table
$recentOrders = [];

// Latest 3 activity events (order submissions)
$activityOrders = [];

if ($franchiseeId) {
    // Pending count (Under Review + Processing = steps 1 and 2)
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM orders WHERE franchisee_id = ? AND status_step IN (1,2)");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $statPending = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    // Ready for pickup count (step 3)
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM orders WHERE franchisee_id = ? AND status_step = 3");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $statReady = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    // Total orders + total spent
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as spent FROM orders WHERE franchisee_id = ?");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $statTotal  = $row['cnt'];
    $totalSpent = $row['spent'];
    $stmt->close();

    // Recent 5 orders
    $stmt = $conn->prepare("SELECT po_number, created_at, status, status_step, total_amount FROM orders WHERE franchisee_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $recentOrders[] = $row; }
    $stmt->close();

    // Latest 3 submitted orders for activity feed
    $stmt = $conn->prepare("SELECT po_number, created_at FROM orders WHERE franchisee_id = ? ORDER BY created_at DESC LIMIT 3");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $activityOrders[] = $row; }
    $stmt->close();
}

$conn->close();

// Map status_step to display label and pill class
function statusLabel($step) {
    $map = [0=>'Submitted', 1=>'Under Review', 2=>'Processing', 3=>'Ready', 4=>'Completed'];
    return $map[$step] ?? 'Unknown';
}
function statusClass($step) {
    $map = [0=>'pill-review', 1=>'pill-review', 2=>'pill-processing', 3=>'pill-pickup', 4=>'pill-completed'];
    return $map[$step] ?? 'pill-review';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Franchisee Dashboard - Juan Café</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root{--background:#f7f3f0;--foreground:#2d241e;--sidebar-bg:#fdfaf7;--card:#ffffff;--card-border:#eeeae6;--primary:#5c4033;--primary-light:#8b5e3c;--accent:#d25424;--muted:#8c837d;--status-review-bg:#fffbeb;--status-review-text:#b45309;--status-pickup-bg:#f0fdf4;--status-pickup-text:#166534;--radius:16px;--sidebar-width:280px;}
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
        .nav-item i{width:20px;height:20px;}
        .nav-item:hover{color:var(--primary);background:rgba(92,64,51,.05);}
        .nav-item.active{background:var(--primary);color:white;}
        .user-profile{margin-top:auto;background:white;border:1px solid var(--card-border);padding:1rem;border-radius:16px;display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;}
        .avatar{width:36px;height:36px;border-radius:50%;background:#e5e7eb;overflow:hidden;display:flex;align-items:center;justify-content:center;}
        .avatar i{color:var(--muted);}
        .user-meta h4{font-size:.85rem;font-weight:700;}
        .user-meta p{font-size:.75rem;color:var(--muted);}
        .sign-out{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--muted);font-size:.9rem;padding:.5rem;}
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:2.5rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);font-size:1rem;}

        .summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;margin-bottom:2.5rem;}
        .summary-card{background:white;border:1px solid var(--card-border);padding:1.75rem;border-radius:20px;position:relative;}
        .summary-card .icon-badge{position:absolute;top:1.75rem;right:1.75rem;color:var(--muted);}
        .summary-card .label{font-size:.9rem;color:var(--muted);margin-bottom:.5rem;font-weight:500;}
        .summary-card .value{font-size:2.25rem;font-weight:700;font-family:'Fraunces',serif;}
        .summary-card .subtext{font-size:.8rem;color:var(--muted);margin-top:.5rem;}
        .summary-card-link{display:block;text-decoration:none;color:inherit;border-radius:20px;transition:transform .18s,box-shadow .18s;}
        .summary-card-link:hover{transform:translateY(-4px);box-shadow:0 10px 28px rgba(92,64,51,.13);}
        .summary-card-link:hover .summary-card{border-color:var(--primary);}
        .card-arrow{position:absolute;bottom:1.25rem;right:1.75rem;opacity:0;transition:opacity .18s;color:var(--primary);}
        .summary-card-link:hover .card-arrow{opacity:1;}
        .content-grid{display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:2rem;}
        .card-header{margin-bottom:1.5rem;}
        .card-header h3{font-family:'Fraunces',serif;font-size:1.25rem;margin-bottom:.25rem;}
        .card-header p{font-size:.85rem;color:var(--muted);}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:1rem;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid var(--card-border);}
        td{padding:1.25rem 1rem;font-size:.9rem;border-bottom:1px solid var(--card-border);}
        tr:last-child td{border-bottom:none;}
        .status-pill{padding:.35rem .75rem;border-radius:99px;font-size:.75rem;font-weight:600;}
        .pill-review{background:var(--status-review-bg);color:var(--status-review-text);}
        .pill-pickup{background:var(--status-pickup-bg);color:var(--status-pickup-text);}
        .pill-processing{background:#eff6ff;color:#1d4ed8;}
        .pill-completed{background:#f3f4f6;color:#4b5563;}
        .activity-item{display:flex;gap:1rem;margin-bottom:1.5rem;}
        .activity-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .activity-content h4{font-size:.9rem;margin-bottom:.15rem;}
        .activity-content p{font-size:.8rem;color:var(--muted);margin-bottom:.15rem;}
        .activity-content span{font-size:.7rem;color:var(--muted);opacity:.7;}
        .banner{grid-column:span 2;background:var(--primary);background-image:linear-gradient(to right,#5c4033,#8b5e3c);color:white;padding:2.5rem;border-radius:24px;display:flex;justify-content:space-between;align-items:center;margin-top:1rem;}
        .banner-text h2{font-family:'Fraunces',serif;font-size:1.75rem;margin-bottom:.5rem;}
        .banner-text p{opacity:.8;font-size:1rem;max-width:500px;}
        .btn-view{background:white;color:var(--primary);border:none;padding:.75rem 1.5rem;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;}
        .po-link{color:var(--primary);text-decoration:none;font-weight:600;}
        .po-link:hover{text-decoration:underline;}
        .empty-row td{text-align:center;color:var(--muted);padding:2rem;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="coffee"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Franchisee Portal</span><span style="font-size:.85rem;color:var(--primary);font-weight:600;margin-top:.1rem;display:block;line-height:1;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? '—'); ?></span></div></div>
    <div class="menu-label">Menu</div>
    <nav>
        <a href="franchisee-dashboard.php" class="nav-item active"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="order-form.php" class="nav-item"><i data-lucide="clipboard-list"></i> Order Form</a>
        <a href="item-usage.php" class="nav-item"><i data-lucide="box"></i> Item Usage</a>
        <a href="order-status.php" class="nav-item"><i data-lucide="package"></i> Order Status</a>
        <a href="returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i> Returns</a>
        <a href="order-history.php" class="nav-item"><i data-lucide="history"></i> Order History</a>
        <a href="profile.php" class="nav-item"><i data-lucide="user"></i> Profile</a>
    </nav>
    <div class="user-profile">
        <div class="avatar"><i data-lucide="user"></i></div>
        <div class="user-meta">
            <h4><?php echo htmlspecialchars($fullName); ?></h4>
            <p style="font-size:.72rem;color:var(--muted);font-weight:500;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? 'Franchisee'); ?></p>
        </div>
    </div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
</aside>

<main>
    <div class="header">
        <div>
            <h2>Dashboard</h2>
            <p>Welcome back, <?php echo htmlspecialchars($branchName); ?></p>
        </div>

    </div>

    <!-- Summary Stats (from DB) -->
    <div class="summary-grid">
        <a href="order-status.php" class="summary-card-link" title="View Order Status">
            <div class="summary-card">
                <i data-lucide="clock" class="icon-badge"></i>
                <i data-lucide="arrow-right" size="16" class="card-arrow"></i>
                <p class="label">Pending Orders</p>
                <div class="value"><?php echo $statPending; ?></div>
                <p class="subtext"><?php echo $statPending > 0 ? 'Under review or processing' : 'No pending orders'; ?></p>
            </div>
        </a>
        <a href="order-status.php" class="summary-card-link" title="View Order Status">
            <div class="summary-card">
                <i data-lucide="check-circle" class="icon-badge" style="color:var(--status-pickup-text)"></i>
                <i data-lucide="arrow-right" size="16" class="card-arrow"></i>
                <p class="label">Ready for Pickup</p>
                <div class="value"><?php echo $statReady; ?></div>
                <p class="subtext"><?php echo $statReady > 0 ? 'Orders awaiting pickup' : 'None ready yet'; ?></p>
            </div>
        </a>
        <a href="order-history.php" class="summary-card-link" title="View Order History">
            <div class="summary-card">
                <i data-lucide="trending-up" class="icon-badge" style="color:#3b82f6"></i>
                <i data-lucide="arrow-right" size="16" class="card-arrow"></i>
                <p class="label">Total Orders</p>
                <div class="value"><?php echo $statTotal; ?></div>
                <p class="subtext">₱<?php echo number_format($totalSpent, 2); ?> total value</p>
            </div>
        </a>
    </div>

    <div class="content-grid">
        <!-- Recent Orders Table (from DB) -->
        <div class="card">
            <div class="card-header"><h3>Recent Orders</h3><p>Your latest purchase orders</p></div>
            <table>
                <thead><tr><th>P.O. Number</th><th>Date</th><th>Status</th><th style="text-align:right">Amount</th></tr></thead>
                <tbody>
                    <?php if (empty($recentOrders)): ?>
                    <tr class="empty-row"><td colspan="4">No orders yet. <a href="order-form.php" style="color:var(--primary)">Place your first order →</a></td></tr>
                    <?php else: foreach ($recentOrders as $o): ?>
                    <tr>
                        <td><a href="order-status.php?po=<?php echo urlencode($o['po_number']); ?>" class="po-link"><?php echo htmlspecialchars($o['po_number']); ?></a></td>
                        <td><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                        <td><span class="status-pill <?php echo statusClass($o['status_step']); ?>"><?php echo statusLabel($o['status_step']); ?></span></td>
                        <td style="text-align:right;font-weight:600;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Activity Feed (recent order submissions from DB) -->
        <div class="card">
            <div class="card-header"><h3>Activity Feed</h3><p>Latest updates on your account</p></div>
            <?php foreach ($activityOrders as $ao): ?>
            <div class="activity-item">
                <div class="activity-icon" style="background:#fff7ed;color:#f97316"><i data-lucide="file-text" size="16"></i></div>
                <div class="activity-content">
                    <h4>Order Submitted</h4>
                    <p><?php echo htmlspecialchars($ao['po_number']); ?> sent for review</p>
                    <span><?php echo date('M d, Y h:i A', strtotime($ao['created_at'])); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <!-- Static stock alert — until notification system is built -->
            <div class="activity-item">
                <div class="activity-icon" style="background:#fef2f2;color:#ef4444"><i data-lucide="alert-triangle" size="16"></i></div>
                <div class="activity-content">
                    <h4>Stock Alert</h4>
                    <p>Check your item usage records</p>
                    <span>Monitor your branch stock</span>
                </div>
            </div>
        </div>

        <div class="banner">
            <div class="banner-text">
                <h2>Need to restock for the weekend?</h2>
                <p>Record your daily item usage to keep inventory levels accurate and avoid running out of best-sellers.</p>
            </div>
            <a href="item-usage.php" class="btn-view">Record Usage</a>
        </div>
    </div>
</main>

<script>lucide.createIcons();</script>
</body>
</html>