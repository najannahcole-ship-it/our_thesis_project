<?php
// ============================================================
// rider-assignment.php — Delivery Assignments
// DB Tables used:
//   READ  → orders WHERE status_step = 3 (Ready for dispatch)
//   READ  → franchisees (branch name / destination)
//   READ  → order_items (item count per order)
//   WRITE → orders (status_step=3 stays, rider goes to tracking)
// The rider sees all orders marked Ready by the encoder.
// Clicking "Start Delivery" takes them to rider-tracking.php
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$riderId   = $_SESSION['user_id'];
$riderName = $_SESSION['full_name'] ?? 'Delivery Rider';

// ── Fetch orders that are Ready for dispatch (status_step = 3) ─
$readyOrders = [];
$result = $conn->query("
    SELECT o.id, o.po_number, o.delivery_preference, o.total_amount,
           o.created_at, o.estimated_pickup,
           f.branch_name, f.franchisee_name,
           COUNT(oi.id) as item_count
    FROM orders o
    LEFT JOIN franchisees f ON f.id = o.franchisee_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.status_step = 3
    AND o.delivery_preference != 'Self Pickup'
    GROUP BY o.id
    ORDER BY o.estimated_pickup ASC, o.created_at ASC
");
while ($row = $result->fetch_assoc()) { $readyOrders[] = $row; }

// ── Fetch self-pickup orders (status_step = 3, Self Pickup) ───
$pickupOrders = [];
$result = $conn->query("
    SELECT o.id, o.po_number, o.delivery_preference, o.total_amount,
           o.created_at, o.estimated_pickup,
           f.branch_name, f.franchisee_name,
           COUNT(oi.id) as item_count
    FROM orders o
    LEFT JOIN franchisees f ON f.id = o.franchisee_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.status_step = 3
    AND o.delivery_preference = 'Self Pickup'
    GROUP BY o.id
    ORDER BY o.created_at ASC
");
while ($row = $result->fetch_assoc()) { $pickupOrders[] = $row; }

// ── Stats ──────────────────────────────────────────────────────
$totalReady     = count($readyOrders);
$totalPickup    = count($pickupOrders);
$totalCompleted = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status_step = 4")->fetch_assoc()['cnt'] ?? 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Assignments - Top Juan Inc.</title>
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
        .header{margin-bottom:2rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);}
        /* Stats */
        .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:2rem;}
        .stat-card{background:white;border:1px solid var(--card-border);border-radius:16px;padding:1.5rem;position:relative;}
        .stat-card .ic{position:absolute;top:1.25rem;right:1.25rem;color:var(--muted);}
        .stat-card .lbl{font-size:.88rem;color:var(--muted);margin-bottom:.4rem;font-weight:500;}
        .stat-card .val{font-size:2rem;font-weight:700;font-family:'Fraunces',serif;}
        /* Section */
        .section-title{font-family:'Fraunces',serif;font-size:1.2rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;}
        /* Order cards */
        .orders-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.25rem;margin-bottom:2.5rem;}
        .order-card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:1.75rem;transition:box-shadow .2s;}
        .order-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
        .oc-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;}
        .oc-po{font-weight:700;color:var(--primary);font-size:1rem;}
        .pill{padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-block;}
        .pill-delivery{background:#eff6ff;color:#1d4ed8;}
        .pill-pickup{background:#f0fdf4;color:#166534;}
        .oc-branch{font-weight:600;font-size:.95rem;margin-bottom:.25rem;}
        .oc-sub{font-size:.82rem;color:var(--muted);}
        .oc-meta{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin:1rem 0;padding:1rem;background:var(--background);border-radius:10px;}
        .oc-meta-item .m-lbl{font-size:.72rem;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:.2rem;}
        .oc-meta-item .m-val{font-size:.9rem;font-weight:600;}
        .btn-start{width:100%;background:var(--primary);color:white;border:none;padding:.875rem;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.92rem;display:flex;align-items:center;justify-content:center;gap:.5rem;text-decoration:none;transition:background .2s;}
        .btn-start:hover{background:var(--primary-light);}
        .btn-confirm{width:100%;background:#10b981;color:white;border:none;padding:.875rem;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.92rem;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:background .2s;}
        .btn-confirm:hover{opacity:.9;}
        .empty-state{text-align:center;padding:3rem 2rem;background:white;border:1px solid var(--card-border);border-radius:20px;color:var(--muted);}
        .empty-state h4{color:var(--foreground);margin:.75rem 0 .5rem;font-family:'Fraunces',serif;}
        .alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:1rem 1.25rem;border-radius:12px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.92rem;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="truck"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Delivery Rider</span></div></div>
    <p class="menu-label">Main Menu</p>
    <nav>
        <a href="rider-assignment.php" class="nav-item active"><i data-lucide="clipboard-list"></i>Assignment</a>
        <a href="rider-tracking.php" class="nav-item"><i data-lucide="map-pin"></i>Delivery Tracking</a>
        <a href="rider-profile.php" class="nav-item"><i data-lucide="user"></i>Profile</a>
        <a href="rider-history.php" class="nav-item"><i data-lucide="history"></i>Delivery History</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($riderName); ?></h4><p>Delivery Rider</p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i><span>Sign Out</span></a>
</aside>

<main>
    <div class="header">
        <h2>Current Assignments</h2>
        <p>View and manage your active delivery tasks for today.</p>
    </div>

    <?php if (isset($_GET['done'])): ?>
    <div class="alert-success"><i data-lucide="check-circle" size="18"></i><span>Order marked as completed successfully.</span></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <i data-lucide="truck" class="ic" style="color:var(--primary)"></i>
            <div class="lbl">For Delivery</div>
            <div class="val"><?php echo $totalReady; ?></div>
        </div>
        <div class="stat-card">
            <i data-lucide="store" class="ic" style="color:#10b981"></i>
            <div class="lbl">Self Pickup</div>
            <div class="val"><?php echo $totalPickup; ?></div>
        </div>
        <div class="stat-card">
            <i data-lucide="check-circle" class="ic" style="color:#3b82f6"></i>
            <div class="lbl">Completed (All Time)</div>
            <div class="val"><?php echo $totalCompleted; ?></div>
        </div>
    </div>

    <!-- Delivery Orders -->
    <h3 class="section-title"><i data-lucide="truck" size="20"></i> Orders for Delivery</h3>
    <?php if (empty($readyOrders)): ?>
    <div class="empty-state" style="margin-bottom:2rem;">
        <i data-lucide="package" size="40" style="opacity:.2;display:block;margin:0 auto;"></i>
        <h4>No delivery orders right now</h4>
        <p>Check back when the encoder marks orders as Ready.</p>
    </div>
    <?php else: ?>
    <div class="orders-grid">
        <?php foreach ($readyOrders as $o): ?>
        <div class="order-card">
            <div class="oc-head">
                <span class="oc-po"><?php echo htmlspecialchars($o['po_number']); ?></span>
                <span class="pill pill-delivery">Delivery</span>
            </div>
            <div class="oc-branch"><?php echo htmlspecialchars($o['franchisee_name'] ?? '—'); ?></div>
            <div class="oc-sub"><?php echo htmlspecialchars($o['branch_name'] ?? '—'); ?></div>
            <div class="oc-meta">
                <div class="oc-meta-item">
                    <div class="m-lbl">Items</div>
                    <div class="m-val"><?php echo $o['item_count']; ?> product<?php echo $o['item_count'] != 1 ? 's' : ''; ?></div>
                </div>
                <div class="oc-meta-item">
                    <div class="m-lbl">Est. Date</div>
                    <div class="m-val"><?php echo $o['estimated_pickup'] ? date('M d, Y', strtotime($o['estimated_pickup'])) : '—'; ?></div>
                </div>
                <div class="oc-meta-item">
                    <div class="m-lbl">Amount</div>
                    <div class="m-val">₱<?php echo number_format($o['total_amount'], 2); ?></div>
                </div>
                <div class="oc-meta-item">
                    <div class="m-lbl">Type</div>
                    <div class="m-val"><?php echo htmlspecialchars($o['delivery_preference']); ?></div>
                </div>
            </div>
            <a href="rider-tracking.php?po=<?php echo urlencode($o['po_number']); ?>" class="btn-start">
                <i data-lucide="truck" size="16"></i> Start Delivery
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Self Pickup Orders -->
    <h3 class="section-title"><i data-lucide="store" size="20"></i> Self Pickup Orders</h3>
    <?php if (empty($pickupOrders)): ?>
    <div class="empty-state">
        <i data-lucide="store" size="40" style="opacity:.2;display:block;margin:0 auto;"></i>
        <h4>No pickup orders right now</h4>
        <p>Self-pickup orders will appear here when ready.</p>
    </div>
    <?php else: ?>
    <div class="orders-grid">
        <?php foreach ($pickupOrders as $o): ?>
        <div class="order-card">
            <div class="oc-head">
                <span class="oc-po"><?php echo htmlspecialchars($o['po_number']); ?></span>
                <span class="pill pill-pickup">Self Pickup</span>
            </div>
            <div class="oc-branch"><?php echo htmlspecialchars($o['franchisee_name'] ?? '—'); ?></div>
            <div class="oc-sub"><?php echo htmlspecialchars($o['branch_name'] ?? '—'); ?></div>
            <div class="oc-meta">
                <div class="oc-meta-item">
                    <div class="m-lbl">Items</div>
                    <div class="m-val"><?php echo $o['item_count']; ?> product<?php echo $o['item_count'] != 1 ? 's' : ''; ?></div>
                </div>
                <div class="oc-meta-item">
                    <div class="m-lbl">Amount</div>
                    <div class="m-val">₱<?php echo number_format($o['total_amount'], 2); ?></div>
                </div>
            </div>
            <!-- Self pickup: mark complete directly -->
            <form method="POST" action="rider-tracking.php">
                <input type="hidden" name="po" value="<?php echo htmlspecialchars($o['po_number']); ?>">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="notes" value="Franchisee collected order at warehouse.">
                <button type="submit" class="btn-confirm">
                    <i data-lucide="check-circle" size="16"></i> Confirm Pickup
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<script>lucide.createIcons();</script>
</body>
</html>
