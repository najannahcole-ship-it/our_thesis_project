<?php
// ============================================================
// encoder-dashboard.php — Data Encoder Dashboard
// DB Tables used:
//   READ → orders       (pending count, processed today, recent activity)
//   READ → returns      (pending returns count)
//   READ → franchisees  (branch names for activity feed)
//   READ → users        (encoder's full name)
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$encoderName = $_SESSION['full_name'] ?? 'Data Encoder';

// ── Stats ─────────────────────────────────────────────────────
// Pending orders = status_step = 1 (Under Review) — waiting for encoder action
$r = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status_step = 1");
$pendingOrders = $r->fetch_assoc()['cnt'] ?? 0;

// Processed today = orders moved to Processing (step 2) or beyond today
$r = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status_step >= 2 AND DATE(created_at) = CURDATE()");
$processedToday = $r->fetch_assoc()['cnt'] ?? 0;

// Returns pending = returns with status = 'Pending'
$r = $conn->query("SELECT COUNT(*) as cnt FROM returns WHERE status = 'Pending'");
$returnsPending = $r->fetch_assoc()['cnt'] ?? 0;

// Total orders ever processed (step 2+) for accuracy display
$r = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status_step >= 2");
$totalProcessed = $r->fetch_assoc()['cnt'] ?? 0;

// ── Recent Activity: last 5 orders with franchisee name ───────
$recentActivity = [];
$stmt = $conn->query("
    SELECT o.po_number, o.status, o.status_step, o.created_at, o.total_amount,
           f.branch_name, f.franchisee_name
    FROM orders o
    LEFT JOIN franchisees f ON f.id = o.franchisee_id
    ORDER BY o.created_at DESC
    LIMIT 5
");
while ($row = $stmt->fetch_assoc()) { $recentActivity[] = $row; }

// ── Recent returns for notifications ──────────────────────────
$recentReturns = [];
$stmt = $conn->query("
    SELECT r.id, r.item_name, r.reason, r.status, r.submitted_at,
           f.franchisee_name
    FROM returns r
    LEFT JOIN franchisees f ON f.id = r.franchisee_id
    ORDER BY r.submitted_at DESC
    LIMIT 3
");
while ($row = $stmt->fetch_assoc()) { $recentReturns[] = $row; }

$conn->close();

function stepLabel($s) {
    $m = [0=>'Submitted',1=>'Under Review',2=>'Processing',3=>'Ready',4=>'Completed'];
    return $m[$s] ?? 'Unknown';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encoder Dashboard - Top Juan Inc.</title>
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
        .sign-out{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--muted);font-size:.9rem;padding:.5rem;transition:color .2s;}
        .sign-out:hover{color:var(--accent);}
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:2.5rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);font-size:1rem;}
        .summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.5rem;margin-bottom:2.5rem;}
        .summary-card{background:white;border:1px solid var(--card-border);padding:1.75rem;border-radius:20px;position:relative;}
        .summary-card .icon-badge{position:absolute;top:1.75rem;right:1.75rem;color:var(--muted);}
        .summary-card .label{font-size:.9rem;color:var(--muted);margin-bottom:.5rem;font-weight:500;}
        .summary-card .value{font-size:2rem;font-weight:700;font-family:'Fraunces',serif;}
        .summary-card .subtext{font-size:.8rem;color:var(--muted);margin-top:.5rem;}
        .content-grid{display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:2rem;}
        .card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;}
        .card-header h3{font-family:'Fraunces',serif;font-size:1.25rem;}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:1rem;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid var(--card-border);}
        td{padding:1.25rem 1rem;font-size:.9rem;border-bottom:1px solid var(--card-border);}
        tr:last-child td{border-bottom:none;}
        tr:hover td{background:#fafafa;}
        .status-pill{padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-block;}
        .s-submitted{background:rgba(59,130,246,.1);color:#3b82f6;}
        .s-review{background:#fffbeb;color:#b45309;}
        .s-processing{background:rgba(210,84,36,.1);color:var(--accent);}
        .s-ready{background:#f0fdf4;color:#166534;}
        .s-completed{background:#f3f4f6;color:#4b5563;}
        .activity-item{display:flex;gap:1rem;margin-bottom:1.5rem;}
        .activity-item:last-child{margin-bottom:0;}
        .activity-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .activity-content h4{font-size:.9rem;margin-bottom:.15rem;}
        .activity-content p{font-size:.8rem;color:var(--muted);margin-bottom:.15rem;}
        .activity-content span{font-size:.7rem;color:var(--muted);opacity:.7;}
        .po-link{color:var(--primary);text-decoration:none;font-weight:700;}
        .po-link:hover{text-decoration:underline;}
        .btn-action{background:var(--primary);color:white;border:none;padding:.5rem 1rem;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="coffee"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Data Encoder</span></div></div>
    <p class="menu-label">Main Menu</p>
    <nav>
        <a href="encoder-dashboard.php" class="nav-item active"><i data-lucide="layout-dashboard"></i>Dashboard</a>
        <a href="encoder-orders.php" class="nav-item"><i data-lucide="shopping-bag"></i>Order Process</a>
        <a href="encoder-returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i>Return and Refund</a>
        <a href="encoder-reports.php" class="nav-item"><i data-lucide="file-text"></i>Reports</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($encoderName); ?></h4><p>Data Encoder</p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i><span>Sign Out</span></a>
</aside>

<main>
    <div class="header">
        <div><h2>Encoder Dashboard</h2><p>Welcome back, <?php echo htmlspecialchars($encoderName); ?>! Here's what needs your attention today.</p></div>
        <a href="encoder-orders.php" class="btn-action"><i data-lucide="inbox" size="16"></i> View Order Queue</a>
    </div>

    <!-- Live Stats from DB -->
    <div class="summary-grid">
        <div class="summary-card">
            <i data-lucide="clock" class="icon-badge" style="color:var(--accent)"></i>
            <p class="label">Pending Orders</p>
            <div class="value"><?php echo $pendingOrders; ?></div>
            <p class="subtext">Awaiting encoding</p>
        </div>
        <div class="summary-card">
            <i data-lucide="check-circle" class="icon-badge" style="color:#10b981"></i>
            <p class="label">Processed Today</p>
            <div class="value"><?php echo $processedToday; ?></div>
            <p class="subtext">Successfully encoded</p>
        </div>
        <div class="summary-card">
            <i data-lucide="rotate-ccw" class="icon-badge" style="color:#ef4444"></i>
            <p class="label">Returns Pending</p>
            <div class="value"><?php echo $returnsPending; ?></div>
            <p class="subtext">Requires attention</p>
        </div>
        <div class="summary-card">
            <i data-lucide="package" class="icon-badge" style="color:#3b82f6"></i>
            <p class="label">Total Processed</p>
            <div class="value"><?php echo $totalProcessed; ?></div>
            <p class="subtext">All time orders handled</p>
        </div>
    </div>

    <div class="content-grid">
        <!-- Recent Encoding Activity from DB -->
        <div class="card">
            <div class="card-header">
                <h3>Recent Encoding Activity</h3>
                <a href="encoder-orders.php" style="font-size:.85rem;color:var(--primary);text-decoration:none;font-weight:600;">View All →</a>
            </div>
            <table>
                <thead>
                    <tr><th>P.O. Number</th><th>Branch</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($recentActivity)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:2rem;">No orders yet.</td></tr>
                    <?php else: foreach ($recentActivity as $a):
                        $sClass = ['s-submitted','s-review','s-processing','s-ready','s-completed'][$a['status_step']] ?? 's-review';
                    ?>
                    <tr>
                        <td><a href="encoder-orders.php?po=<?php echo urlencode($a['po_number']); ?>" class="po-link"><?php echo htmlspecialchars($a['po_number']); ?></a></td>
                        <td>
                            <div style="font-weight:600;font-size:.88rem;"><?php echo htmlspecialchars($a['franchisee_name'] ?? '—'); ?></div>
                            <div style="font-size:.75rem;color:var(--muted)"><?php echo htmlspecialchars($a['branch_name'] ?? '—'); ?></div>
                        </td>
                        <td style="color:var(--muted);font-size:.85rem;"><?php echo date('M d, Y h:i A', strtotime($a['created_at'])); ?></td>
                        <td style="font-weight:700;">₱<?php echo number_format($a['total_amount'], 2); ?></td>
                        <td><span class="status-pill <?php echo $sClass; ?>"><?php echo stepLabel($a['status_step']); ?></span></td>
                        <td><a href="encoder-orders.php?po=<?php echo urlencode($a['po_number']); ?>" class="btn-action"><i data-lucide="eye" size="14"></i></a></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Notifications: recent returns from DB -->
        <div class="card">
            <div class="card-header">
                <h3>Notifications</h3>
                <a href="encoder-returns.php" style="font-size:.85rem;color:var(--primary);text-decoration:none;font-weight:600;">View All →</a>
            </div>
            <?php if ($pendingOrders > 0): ?>
            <div class="activity-item">
                <div class="activity-icon" style="background:rgba(210,84,36,.1);color:var(--accent)"><i data-lucide="inbox" size="16"></i></div>
                <div class="activity-content">
                    <h4><?php echo $pendingOrders; ?> Order<?php echo $pendingOrders > 1 ? 's' : ''; ?> Awaiting Review</h4>
                    <p>New franchisee orders need encoding</p>
                    <span>Requires action now</span>
                </div>
            </div>
            <?php endif; ?>
            <?php foreach ($recentReturns as $ret): ?>
            <div class="activity-item">
                <div class="activity-icon" style="background:rgba(239,68,68,.1);color:#ef4444"><i data-lucide="rotate-ccw" size="16"></i></div>
                <div class="activity-content">
                    <h4>Return Request — <?php echo htmlspecialchars($ret['reason']); ?></h4>
                    <p><?php echo htmlspecialchars($ret['franchisee_name'] ?? 'Franchisee'); ?>: <?php echo htmlspecialchars($ret['item_name']); ?></p>
                    <span><?php echo date('M d, Y h:i A', strtotime($ret['submitted_at'])); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($recentReturns) && $pendingOrders == 0): ?>
            <p style="color:var(--muted);font-size:.9rem;text-align:center;padding:1rem 0;">All clear — nothing urgent right now.</p>
            <?php endif; ?>
        </div>
    </div>
</main>
<script>lucide.createIcons();</script>
</body>
</html>