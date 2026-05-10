<?php
// ============================================================
// rider-assignment.php — Delivery Assignments
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$riderId   = $_SESSION['user_id'];
$riderName = $_SESSION['full_name'] ?? 'Delivery Rider';

// ── Handle POST: Accept an order ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['accept_order_id'])) {
    $acceptId = (int)$_POST['accept_order_id'];
    $upd = $conn->prepare("
        UPDATE orders
        SET rider_id = ?, delivery_status = 'accepted'
        WHERE id = ? AND status_step = 3 AND (rider_id IS NULL OR rider_id = 0)
    ");
    $upd->bind_param("ii", $riderId, $acceptId);
    $upd->execute();
    $upd->close();
    header("Location: rider-assignment.php?accepted=1");
    exit();
}

// ── Handle POST: Reject an order ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['reject_order_id'])) {
    $rejectId = (int)$_POST['reject_order_id'];
    // Simply remove this rider's claim if any; order stays unassigned for others
    $upd = $conn->prepare("
        UPDATE orders
        SET rider_id = NULL, delivery_status = 'pending'
        WHERE id = ? AND status_step = 3
    ");
    $upd->bind_param("i", $rejectId);
    $upd->execute();
    $upd->close();
    header("Location: rider-assignment.php?rejected=1");
    exit();
}

// ── Fetch unassigned orders (status_step=3, no rider yet) ──────
$readyOrders = [];
$result = $conn->query("
    SELECT o.id, o.po_number, o.delivery_preference, o.total_amount,
           o.created_at, o.estimated_pickup, o.payment_method,
           f.branch_name, f.franchisee_name,
           COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN franchisees f ON f.id = o.franchisee_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.status_step = 3
      AND o.delivery_preference != 'Self Pickup'
      AND (o.rider_id IS NULL OR o.rider_id = 0)
    GROUP BY o.id
    ORDER BY o.estimated_pickup ASC, o.created_at ASC
");
while ($row = $result->fetch_assoc()) { $readyOrders[] = $row; }

// ── Fetch line items for each order ───────────────────────────
$orderItems = [];
if (!empty($readyOrders)) {
    $ids = implode(',', array_column($readyOrders, 'id'));
    $itemRes = $conn->query("
        SELECT oi.order_id, p.name, p.unit, oi.quantity, oi.unit_price, oi.subtotal
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id IN ($ids)
        ORDER BY oi.id ASC
    ");
    while ($row = $itemRes->fetch_assoc()) {
        $orderItems[$row['order_id']][] = $row;
    }
}

// ── Stats ──────────────────────────────────────────────────────
$totalReady     = count($readyOrders);
$totalCompleted = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status_step = 4 AND rider_id = $riderId")->fetch_assoc()['cnt'] ?? 0;

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
        .stats-row{display:grid;grid-template-columns:repeat(2,1fr);gap:1.25rem;margin-bottom:2rem;}
        .stat-card{background:white;border:1px solid var(--card-border);border-radius:16px;padding:1.5rem;position:relative;}
        .stat-card .ic{position:absolute;top:1.25rem;right:1.25rem;}
        .stat-card .lbl{font-size:.88rem;color:var(--muted);margin-bottom:.4rem;font-weight:500;}
        .stat-card .val{font-size:2rem;font-weight:700;font-family:'Fraunces',serif;}
        .stat-card-link{display:block;text-decoration:none;color:inherit;border-radius:16px;transition:transform .18s,box-shadow .18s;}
        .stat-card-link:hover{transform:translateY(-4px);box-shadow:0 10px 28px rgba(92,64,51,.13);}
        .card-arrow{display:none;}
        .section-title{font-family:'Fraunces',serif;font-size:1.2rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;}
        .orders-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.25rem;margin-bottom:2.5rem;}
        .order-card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:1.75rem;transition:box-shadow .2s;}
        .order-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
        .oc-head{display:flex;justify-content:flex-start;align-items:flex-start;margin-bottom:1rem;}
        .oc-po{font-weight:700;color:var(--primary);font-size:1rem;}
        .oc-branch{font-weight:600;font-size:.95rem;margin-bottom:.25rem;}
        .oc-sub{font-size:.82rem;color:var(--muted);}
        .oc-meta{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin:1rem 0;padding:1rem;background:var(--background);border-radius:10px;}
        .oc-meta-item .m-lbl{font-size:.72rem;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:.2rem;}
        .oc-meta-item .m-val{font-size:.9rem;font-weight:600;}
        .btn-accept{width:100%;background:var(--primary);color:white;border:none;padding:.875rem;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.92rem;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:background .2s;}
        .btn-accept:hover{background:var(--primary-light);}
        .btn-reject{width:100%;background:white;color:#b91c1c;border:1.5px solid #fca5a5;padding:.875rem;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.92rem;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:all .2s;margin-top:.6rem;}
        .btn-reject:hover{background:#fff1f2;border-color:#ef4444;}
        .btn-group{display:flex;flex-direction:column;gap:.5rem;}
        .empty-state{text-align:center;padding:3rem 2rem;background:white;border:1px solid var(--card-border);border-radius:20px;color:var(--muted);}
        .empty-state h4{color:var(--foreground);margin:.75rem 0 .5rem;font-family:'Fraunces',serif;}
        .alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:1rem 1.25rem;border-radius:12px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.92rem;}
        .alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;padding:1rem 1.25rem;border-radius:12px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.92rem;}
        /* Confirm modal */
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
        .modal-overlay.open{opacity:1;pointer-events:all;}
        .modal{background:white;border-radius:20px;padding:2rem;width:360px;max-width:90vw;box-shadow:0 8px 40px rgba(0,0,0,.15);}
        .modal h4{font-family:'Fraunces',serif;font-size:1.15rem;margin-bottom:.5rem;}
        .modal p{font-size:.88rem;color:var(--muted);margin-bottom:1.5rem;line-height:1.5;}
        .modal-actions{display:flex;gap:.75rem;}
        .modal-actions .btn-cancel{flex:1;background:white;border:1.5px solid var(--card-border);color:var(--foreground);padding:.8rem;border-radius:10px;font-weight:600;font-family:inherit;font-size:.9rem;cursor:pointer;}
        .modal-actions .btn-confirm-reject{flex:1;background:#ef4444;color:white;border:none;padding:.8rem;border-radius:10px;font-weight:700;font-family:inherit;font-size:.9rem;cursor:pointer;}
        .modal-actions .btn-confirm-reject:hover{background:#dc2626;}
        /* Drawer */
        .drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:100;opacity:0;pointer-events:none;transition:opacity .25s;}
        .drawer-overlay.open{opacity:1;pointer-events:all;}
        .drawer{position:fixed;top:0;right:0;height:100vh;width:480px;max-width:95vw;background:white;z-index:101;transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:-8px 0 32px rgba(0,0,0,.12);}
        .drawer.open{transform:translateX(0);}
        .drawer-header{display:flex;justify-content:space-between;align-items:flex-start;padding:1.75rem 2rem 1.25rem;border-bottom:1px solid var(--card-border);}
        .drawer-header h3{font-family:'Fraunces',serif;font-size:1.35rem;}
        .drawer-close{background:none;border:none;cursor:pointer;color:var(--muted);padding:.25rem;border-radius:8px;display:flex;align-items:center;}
        .drawer-close:hover{color:var(--foreground);background:var(--background);}
        .drawer-body{flex:1;overflow-y:auto;padding:1.5rem 2rem;}
        .drawer-footer{padding:1.25rem 2rem;border-top:1px solid var(--card-border);}
        .drawer-section{margin-bottom:1.5rem;}
        .drawer-section-title{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin-bottom:.75rem;}
        .drawer-detail-row{display:flex;justify-content:space-between;font-size:.9rem;padding:.5rem 0;border-bottom:1px dashed var(--card-border);}
        .drawer-detail-row:last-child{border-bottom:none;}
        .drawer-detail-row .dl{color:var(--muted);}
        .drawer-detail-row .dv{font-weight:600;text-align:right;max-width:60%;}
        .drawer-item-row{display:flex;justify-content:space-between;align-items:center;font-size:.875rem;padding:.6rem 0;border-bottom:1px dashed var(--card-border);}
        .drawer-item-row:last-child{border-bottom:none;}
        .drawer-item-name{font-weight:500;}
        .drawer-item-sub{font-size:.78rem;color:var(--muted);}
        .drawer-item-price{font-weight:700;white-space:nowrap;}
        .drawer-total{display:flex;justify-content:space-between;font-weight:700;font-size:1rem;padding:1rem 0 0;margin-top:.5rem;border-top:2px solid var(--card-border);}
        .order-card{cursor:pointer;}
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
        <p>Accept orders and manage your active deliveries for today.</p>
    </div>

    <?php if (isset($_GET['accepted'])): ?>
    <div class="alert-success"><i data-lucide="check-circle" size="18"></i><span>Order accepted! Head to <a href="rider-tracking.php" style="color:#166534;font-weight:700;">Delivery Tracking</a> to start updating its status.</span></div>
    <?php endif; ?>

    <?php if (isset($_GET['rejected'])): ?>
    <div class="alert-error"><i data-lucide="x-circle" size="18"></i><span>Order rejected and returned to the pool.</span></div>
    <?php endif; ?>

    <?php if (isset($_GET['done'])): ?>
    <div class="alert-success"><i data-lucide="check-circle" size="18"></i><span>Order marked as delivered successfully.</span></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <a href="rider-tracking.php" class="stat-card-link">
            <div class="stat-card">
                <i data-lucide="package" class="ic" style="color:var(--primary)"></i>
                <div class="lbl">Available for Pickup</div>
                <div class="val"><?php echo $totalReady; ?></div>
            </div>
        </a>
        <a href="rider-history.php" class="stat-card-link">
            <div class="stat-card">
                <i data-lucide="check-circle" class="ic" style="color:#3b82f6"></i>
                <div class="lbl">My Completed Deliveries</div>
                <div class="val"><?php echo $totalCompleted; ?></div>
            </div>
        </a>
    </div>

    <!-- Unassigned Orders -->
    <h3 class="section-title"><i data-lucide="inbox" size="20"></i> Pending Orders</h3>
    <?php if (empty($readyOrders)): ?>
    <div class="empty-state">
        <i data-lucide="package" size="40" style="opacity:.2;display:block;margin:0 auto;"></i>
        <h4>No orders available right now</h4>

    </div>
    <?php else: ?>
    <div class="orders-grid">
        <?php foreach ($readyOrders as $o):
            $items = $orderItems[$o['id']] ?? [];
            $itemsJson = htmlspecialchars(json_encode($items), ENT_QUOTES);
        ?>
        <div class="order-card" onclick="openDrawer(<?php echo $o['id']; ?>)">
            <div class="oc-head">
                <span class="oc-po"><?php echo htmlspecialchars($o['po_number']); ?></span>
                <?php
                $pm = strtolower($o['payment_method'] ?? '');
                $isCod = $pm === 'cod';
                ?>
                <span style="margin-left:auto;background:<?php echo $isCod ? '#fef3c7' : '#dcfce7'; ?>;color:<?php echo $isCod ? '#92400e' : '#166534'; ?>;font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;white-space:nowrap;">
                    <?php echo $isCod ? '💵 COD' : '✓ Paid'; ?>
                </span>
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
                    <div class="m-lbl">Payment</div>
                    <div class="m-val"><?php echo htmlspecialchars($o['payment_method'] ?? '—'); ?></div>
                </div>
            </div>
            <!-- Accept / Reject forms — stop click propagation so it doesn't reopen drawer -->
            <div class="btn-group" onclick="event.stopPropagation()">
                <form method="POST" action="rider-assignment.php">
                    <input type="hidden" name="accept_order_id" value="<?php echo $o['id']; ?>">
                    <button type="submit" class="btn-accept">
                        <i data-lucide="check" size="16"></i> Accept Delivery
                    </button>
                </form>
                <button type="button" class="btn-reject" onclick="confirmReject(<?php echo $o['id']; ?>, '<?php echo htmlspecialchars($o['po_number'], ENT_QUOTES); ?>')">
                    <i data-lucide="x" size="16"></i> Reject
                </button>
            </div>
        </div>

        <!-- Hidden data block for drawer -->
        <script>
        window._orderData = window._orderData || {};
        window._orderData[<?php echo $o['id']; ?>] = {
            id: <?php echo $o['id']; ?>,
            po_number: <?php echo json_encode($o['po_number']); ?>,
            franchisee_name: <?php echo json_encode($o['franchisee_name'] ?? '—'); ?>,
            branch_name: <?php echo json_encode($o['branch_name'] ?? '—'); ?>,
            delivery_preference: <?php echo json_encode($o['delivery_preference']); ?>,
            payment_method: <?php echo json_encode($o['payment_method'] ?? '—'); ?>,
            estimated_pickup: <?php echo json_encode($o['estimated_pickup'] ? date('M d, Y', strtotime($o['estimated_pickup'])) : '—'); ?>,
            order_date: <?php echo json_encode(date('M d, Y', strtotime($o['created_at']))); ?>,
            total_amount: <?php echo json_encode(number_format($o['total_amount'], 2)); ?>,
            delivery_address: '—',
            items: <?php echo json_encode($items); ?>
        };
        </script>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<!-- Drawer overlay + panel -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="orderDrawer">
    <div class="drawer-header">
        <div>
            <div style="font-size:.78rem;color:var(--muted);font-weight:600;margin-bottom:.2rem;">ORDER DETAILS</div>
            <h3 id="drawerPO">—</h3>
        </div>
        <button class="drawer-close" onclick="closeDrawer()"><i data-lucide="x" size="20"></i></button>
    </div>
    <div class="drawer-body">
        <div class="drawer-section">
            <div class="drawer-section-title">Franchisee Info</div>
            <div class="drawer-detail-row"><span class="dl">Franchisee</span><span class="dv" id="dFranchisee">—</span></div>
            <div class="drawer-detail-row"><span class="dl">Branch</span><span class="dv" id="dBranch">—</span></div>
            <div class="drawer-detail-row"><span class="dl">Delivery Address</span><span class="dv" id="dAddress">—</span></div>
        </div>
        <div class="drawer-section">
            <div class="drawer-section-title">Order Info</div>
            <div class="drawer-detail-row"><span class="dl">Delivery Type</span><span class="dv" id="dType">—</span></div>
            <div class="drawer-detail-row"><span class="dl">Payment Method</span><span class="dv" id="dPayment">—</span></div>
            <div class="drawer-detail-row"><span class="dl">Est. Pickup Date</span><span class="dv" id="dDate">—</span></div>
            <div class="drawer-detail-row"><span class="dl">Order Date</span><span class="dv" id="dOrderDate">—</span></div>
        </div>
        <div class="drawer-section">
            <div class="drawer-section-title">Items</div>
            <div id="dItemsList"></div>
            <div class="drawer-total"><span>Total Amount</span><span id="dTotal">—</span></div>
        </div>
    </div>
    <div class="drawer-footer">
        <div class="btn-group">
            <form method="POST" action="rider-assignment.php">
                <input type="hidden" name="accept_order_id" id="drawerAcceptId" value="">
                <button type="submit" class="btn-accept">
                    <i data-lucide="check" size="16"></i> Accept Delivery
                </button>
            </form>
            <button type="button" class="btn-reject" id="drawerRejectBtn" onclick="confirmRejectFromDrawer()">
                <i data-lucide="x" size="16"></i> Reject
            </button>
        </div>
    </div>
</div>

<!-- Reject confirmation modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <h4>Reject this order?</h4>
        <p id="rejectModalText">The order will be returned to the pool for other riders to accept.</p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeRejectModal()">Cancel</button>
            <form method="POST" action="rider-assignment.php" style="flex:1;">
                <input type="hidden" name="reject_order_id" id="rejectOrderId" value="">
                <button type="submit" class="btn-confirm-reject" style="width:100%;">Yes, Reject</button>
            </form>
        </div>
    </div>
</div>

</main>

<script>
lucide.createIcons();

let _currentDrawerOrder = null;

function openDrawer(id) {
    const o = window._orderData[id];
    if (!o) return;
    _currentDrawerOrder = o;

    document.getElementById('drawerPO').textContent       = o.po_number;
    document.getElementById('dFranchisee').textContent    = o.franchisee_name;
    document.getElementById('dBranch').textContent        = o.branch_name;
    document.getElementById('dAddress').textContent       = o.delivery_address;
    document.getElementById('dType').textContent          = o.delivery_preference;
    document.getElementById('dPayment').textContent       = o.payment_method;
    document.getElementById('dDate').textContent          = o.estimated_pickup;
    document.getElementById('dOrderDate').textContent     = o.order_date;
    document.getElementById('dTotal').textContent         = '₱' + o.total_amount;
    document.getElementById('drawerAcceptId').value       = o.id;

    const list = document.getElementById('dItemsList');
    list.innerHTML = o.items.length ? o.items.map(item => `
        <div class="drawer-item-row">
            <div>
                <div class="drawer-item-name">${item.name}</div>
                <div class="drawer-item-sub">${item.unit} × ${item.quantity}</div>
            </div>
            <div class="drawer-item-price">₱${parseFloat(item.subtotal || item.unit_price * item.quantity).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
        </div>`).join('') : '<p style="color:var(--muted);font-size:.875rem;">No items found.</p>';

    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('orderDrawer').classList.add('open');
    lucide.createIcons();
}

function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('orderDrawer').classList.remove('open');
}

function confirmReject(id, po) {
    document.getElementById('rejectOrderId').value = id;
    document.getElementById('rejectModalText').textContent = `Order ${po} will be returned to the pool for other riders to accept.`;
    document.getElementById('rejectModal').classList.add('open');
}

function confirmRejectFromDrawer() {
    if (_currentDrawerOrder) confirmReject(_currentDrawerOrder.id, _currentDrawerOrder.po_number);
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('open');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeDrawer(); closeRejectModal(); } });
</script>
</body>
</html>