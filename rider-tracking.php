<?php
// ============================================================
// rider-tracking.php — Delivery Status Update
// DB Tables used:
//   READ  → orders + franchisees + order_items + products
//   WRITE → orders              (UPDATE status + status_step)
//   WRITE → order_status_history (INSERT log entry per step)
// Status steps the rider controls:
//   3 → 3  = "Picked Up"  (still step 3, adds history log)
//   3 → 3  = "In Transit" (still step 3, adds history log)
//   3 → 4  = "Delivered"  (moves to Completed)
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$riderId   = $_SESSION['user_id'];
$riderName = $_SESSION['full_name'] ?? 'Delivery Rider';

// ── Handle POST: update delivery status ───────────────────────
$actionMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $poNum  = trim($_POST['po']     ?? '');
    $action = trim($_POST['action'] ?? '');
    $notes  = trim($_POST['notes']  ?? '');

    if ($poNum && in_array($action, ['pickedup','intransit','complete'])) {

        // Get the order ID
        $stmt = $conn->prepare("SELECT id, status_step FROM orders WHERE po_number = ?");
        $stmt->bind_param("s", $poNum);
        $stmt->execute();
        $ord = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($ord) {
            $orderId = $ord['id'];

            if ($action === 'pickedup') {
                // Log "Picked Up" but keep step at 3
                $label  = 'Picked Up';
                $detail = 'Order collected from warehouse by delivery rider. ' . ($notes ?: '');
                $conn->prepare("UPDATE orders SET status = 'Picked Up' WHERE id = ?")->execute() || null;
                $upd = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
                $upd->bind_param("si", $label, $orderId); $upd->execute(); $upd->close();

            } elseif ($action === 'intransit') {
                $label  = 'In Transit';
                $detail = 'Order is en route to the franchisee branch. ' . ($notes ?: '');
                $upd = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
                $upd->bind_param("si", $label, $orderId); $upd->execute(); $upd->close();

            } elseif ($action === 'complete') {
                // Mark as Completed (step 4)
                $label   = 'Completed';
                $detail  = 'Order successfully delivered to franchisee. ' . ($notes ?: '');
                $newStep = 4;
                $upd = $conn->prepare("UPDATE orders SET status = ?, status_step = ? WHERE id = ?");
                $upd->bind_param("sii", $label, $newStep, $orderId); $upd->execute(); $upd->close();
            }

            // Log to order_status_history
            $stepNum = ($action === 'complete') ? 4 : 3;
            $ins = $conn->prepare("
                INSERT INTO order_status_history
                    (order_id, status_step, status_label, detail, changed_at, changed_by)
                VALUES (?, ?, ?, ?, NOW(), ?)
            ");
            $ins->bind_param("iissi", $orderId, $stepNum, $label, $detail, $riderId);
            $ins->execute();
            $ins->close();

            $actionMsg = $label;

            // Redirect after completing
            if ($action === 'complete') {
                $conn->close();
                header("Location: rider-assignment.php?done=1");
                exit();
            }
        }
    }
}

// ── Determine which order to track ────────────────────────────
// From URL param ?po=  OR most recent Ready order
$selectedPO = $_GET['po'] ?? $_POST['po'] ?? '';
$order      = null;
$orderItems = [];
$history    = [];

if ($selectedPO) {
    $stmt = $conn->prepare("
        SELECT o.*, f.branch_name, f.franchisee_name
        FROM orders o
        LEFT JOIN franchisees f ON f.id = o.franchisee_id
        WHERE o.po_number = ?
    ");
    $stmt->bind_param("s", $selectedPO);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$order) {
    // Show most recent order at step 3 or 4
    $order = $conn->query("
        SELECT o.*, f.branch_name, f.franchisee_name
        FROM orders o
        LEFT JOIN franchisees f ON f.id = o.franchisee_id
        WHERE o.status_step IN (3,4)
        ORDER BY o.created_at DESC LIMIT 1
    ")->fetch_assoc();
}

if ($order) {
    // Fetch items
    $stmt = $conn->prepare("
        SELECT oi.quantity, oi.unit_price, oi.subtotal, p.name, p.unit
        FROM order_items oi JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $stmt->bind_param("i", $order['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $orderItems[] = $row; }
    $stmt->close();

    // Fetch status history (newest first)
    $stmt = $conn->prepare("
        SELECT status_label, detail, changed_at
        FROM order_status_history WHERE order_id = ?
        ORDER BY changed_at DESC
    ");
    $stmt->bind_param("i", $order['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $history[] = $row; }
    $stmt->close();
}

$conn->close();

// Determine current delivery sub-status
$currentStatus = $order['status'] ?? '';
$isPickedUp  = in_array($currentStatus, ['Picked Up', 'In Transit', 'Completed']);
$isInTransit = in_array($currentStatus, ['In Transit', 'Completed']);
$isCompleted = $currentStatus === 'Completed' || ($order['status_step'] ?? 0) == 4;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Tracking - Top Juan Inc.</title>
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
        .header{margin-bottom:2rem;display:flex;justify-content:space-between;align-items:flex-start;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);}
        .po-badge{background:var(--background);border:1px solid var(--card-border);padding:.5rem 1rem;border-radius:10px;font-weight:700;color:var(--primary);}
        .tracking-grid{display:grid;grid-template-columns:1fr 380px;gap:2rem;align-items:start;}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:2rem;margin-bottom:1.5rem;}
        .card:last-child{margin-bottom:0;}
        .card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;}
        .card-header h3{font-family:'Fraunces',serif;font-size:1.25rem;}
        /* Status steps */
        .status-stepper{display:flex;flex-direction:column;gap:1rem;}
        .status-btn{display:flex;align-items:center;gap:1rem;padding:1.25rem;border-radius:16px;border:2px solid var(--card-border);background:white;cursor:pointer;text-align:left;width:100%;font-family:inherit;transition:all .2s;}
        .status-btn .sb-icon{width:48px;height:48px;background:#fdfaf7;border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0;}
        .status-btn h4{font-size:1rem;margin-bottom:.2rem;}
        .status-btn p{font-size:.8rem;color:var(--muted);}
        .status-btn.done{border-color:#10b981;background:rgba(16,185,129,.04);}
        .status-btn.done .sb-icon{background:#10b981;color:white;}
        .status-btn.done h4,.status-btn.done p{color:#166534;}
        .status-btn.current{border-color:var(--primary);background:var(--primary);color:white;}
        .status-btn.current .sb-icon{background:rgba(255,255,255,.15);color:white;}
        .status-btn.current h4{color:white;}
        .status-btn.current p{color:rgba(255,255,255,.8);}
        .status-btn:disabled{opacity:.5;cursor:not-allowed;}
        /* Form */
        .form-group{margin-bottom:1.25rem;}
        .form-group label{display:block;font-size:.85rem;font-weight:600;color:var(--muted);margin-bottom:.5rem;}
        .form-group textarea{width:100%;padding:.875rem 1rem;border-radius:12px;border:1.5px solid var(--card-border);font-family:inherit;font-size:.9rem;outline:none;resize:none;}
        .form-group textarea:focus{border-color:var(--primary);}
        .btn-action{width:100%;background:var(--primary);color:white;border:none;padding:1rem;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.95rem;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:background .2s;}
        .btn-action:hover{background:var(--primary-light);}
        .btn-delivered{background:#10b981;}
        .btn-delivered:hover{opacity:.9;background:#10b981;}
        /* Order details */
        .detail-row{display:flex;justify-content:space-between;margin-bottom:.875rem;font-size:.9rem;}
        .detail-row .dl{color:var(--muted);}
        .detail-row .dv{font-weight:600;}
        .items-list{margin-top:1rem;border-top:1px solid var(--card-border);padding-top:1rem;}
        .item-line{display:flex;justify-content:space-between;font-size:.875rem;padding:.4rem 0;border-bottom:1px dashed var(--card-border);}
        .item-line:last-child{border-bottom:none;}
        /* History feed */
        .hist-item{display:flex;gap:1rem;padding-bottom:1.5rem;position:relative;}
        .hist-item:not(:last-child)::before{content:'';position:absolute;left:11px;top:24px;bottom:0;width:2px;background:#eeeae6;}
        .hist-dot{width:24px;height:24px;border-radius:50%;background:#eeeae6;flex-shrink:0;z-index:1;}
        .hist-item.latest .hist-dot{background:var(--primary);}
        .hist-content h5{font-size:.92rem;margin-bottom:.2rem;}
        .hist-content p{font-size:.82rem;color:var(--muted);}
        .hist-time{font-size:.75rem;color:var(--muted);opacity:.7;display:block;margin-top:.2rem;}
        .alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:.875rem 1rem;border-radius:10px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.9rem;}
        .empty-state{text-align:center;padding:4rem 2rem;color:var(--muted);}
        .empty-state h4{color:var(--foreground);margin:.75rem 0 .5rem;font-family:'Fraunces',serif;}
        .btn-back{display:inline-flex;align-items:center;gap:.5rem;color:var(--primary);text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:1.5rem;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="truck"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Delivery Rider</span></div></div>
    <p class="menu-label">Main Menu</p>
    <nav>
        <a href="rider-assignment.php" class="nav-item"><i data-lucide="clipboard-list"></i>Assignment</a>
        <a href="rider-tracking.php" class="nav-item active"><i data-lucide="map-pin"></i>Delivery Tracking</a>
        <a href="rider-profile.php" class="nav-item"><i data-lucide="user"></i>Profile</a>
        <a href="rider-history.php" class="nav-item"><i data-lucide="history"></i>Delivery History</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($riderName); ?></h4><p>Delivery Rider</p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i><span>Sign Out</span></a>
</aside>

<main>
    <?php if (!$order): ?>
    <div class="header"><div><h2>Delivery Tracking</h2><p>No active delivery found.</p></div></div>
    <div class="empty-state">
        <i data-lucide="package" size="48" style="opacity:.2;display:block;margin:0 auto;"></i>
        <h4>Nothing to track right now</h4>
        <p>Go to your assignments and start a delivery first.</p>
        <a href="rider-assignment.php" style="display:inline-block;margin-top:1.5rem;background:var(--primary);color:white;padding:.75rem 1.5rem;border-radius:12px;text-decoration:none;font-weight:600;">← Back to Assignments</a>
    </div>
    <?php else: ?>

    <div class="header">
        <div>
            <a href="rider-assignment.php" class="btn-back"><i data-lucide="arrow-left" size="16"></i> Assignments</a>
            <h2>Delivery Status Update</h2>
            <p><?php echo htmlspecialchars($order['franchisee_name'] ?? '—'); ?> — <?php echo htmlspecialchars($order['branch_name'] ?? '—'); ?></p>
        </div>
        <span class="po-badge"><?php echo htmlspecialchars($order['po_number']); ?></span>
    </div>

    <?php if ($actionMsg): ?>
    <div class="alert-success"><i data-lucide="check-circle" size="18"></i><span>Status updated to <strong><?php echo htmlspecialchars($actionMsg); ?></strong>.</span></div>
    <?php endif; ?>

    <div class="tracking-grid">
        <!-- LEFT: Status Controls -->
        <div>
            <div class="card">
                <div class="card-header"><h3>Update Progress</h3></div>

                <?php if ($isCompleted): ?>
                <div style="background:#f0fdf4;border-radius:12px;padding:1.5rem;text-align:center;color:#166534;">
                    <i data-lucide="check-circle" size="32" style="display:block;margin:0 auto .75rem;"></i>
                    <strong style="font-size:1.1rem;">Delivery Completed</strong><br>
                    <span style="font-size:.875rem;">This order has been successfully delivered.</span>
                </div>
                <?php else: ?>

                <div class="status-stepper">
                    <!-- Step 1: Picked Up -->
                    <div class="status-btn <?php echo $isPickedUp ? 'done' : 'current'; ?>">
                        <div class="sb-icon"><i data-lucide="package-check" size="22"></i></div>
                        <div>
                            <h4>Picked Up</h4>
                            <p><?php echo $isPickedUp ? 'Collected from warehouse ✓' : 'Mark when collected from warehouse'; ?></p>
                        </div>
                    </div>

                    <!-- Step 2: In Transit -->
                    <div class="status-btn <?php echo $isInTransit ? 'done' : ($isPickedUp ? 'current' : ''); ?>">
                        <div class="sb-icon"><i data-lucide="truck" size="22"></i></div>
                        <div>
                            <h4>In Transit</h4>
                            <p><?php echo $isInTransit ? 'En route to destination ✓' : 'Mark when heading to branch'; ?></p>
                        </div>
                    </div>

                    <!-- Step 3: Delivered -->
                    <div class="status-btn <?php echo $isCompleted ? 'done' : ''; ?>">
                        <div class="sb-icon"><i data-lucide="flag" size="22"></i></div>
                        <div>
                            <h4>Delivered</h4>
                            <p>Mark when handed over to franchisee</p>
                        </div>
                    </div>
                </div>

                <!-- Action form -->
                <form method="POST" action="rider-tracking.php" style="margin-top:1.5rem;">
                    <input type="hidden" name="po" value="<?php echo htmlspecialchars($order['po_number']); ?>">
                    <div class="form-group">
                        <label>Delivery Notes (optional)</label>
                        <textarea name="notes" rows="3" placeholder="Add delivery notes, special instructions, or remarks..."></textarea>
                    </div>

                    <?php if (!$isPickedUp): ?>
                    <button type="submit" name="action" value="pickedup" class="btn-action">
                        <i data-lucide="package-check" size="18"></i> Mark as Picked Up
                    </button>

                    <?php elseif (!$isInTransit): ?>
                    <button type="submit" name="action" value="intransit" class="btn-action">
                        <i data-lucide="truck" size="18"></i> Mark as In Transit
                    </button>

                    <?php else: ?>
                    <button type="submit" name="action" value="complete" class="btn-action btn-delivered">
                        <i data-lucide="check-circle" size="18"></i> Confirm Delivery Complete
                    </button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>

            <!-- Status History -->
            <?php if (!empty($history)): ?>
            <div class="card">
                <div class="card-header"><h3>Status Timeline</h3></div>
                <?php foreach ($history as $idx => $h): ?>
                <div class="hist-item <?php echo $idx === 0 ? 'latest' : ''; ?>">
                    <div class="hist-dot"></div>
                    <div class="hist-content">
                        <h5><?php echo htmlspecialchars($h['status_label']); ?></h5>
                        <p><?php echo htmlspecialchars($h['detail']); ?></p>
                        <span class="hist-time"><?php echo date('M d, Y h:i A', strtotime($h['changed_at'])); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Order Details -->
        <div class="card" style="position:sticky;top:2rem;">
            <div class="card-header"><h3>Order Details</h3></div>
            <div class="detail-row"><span class="dl">Franchisee</span><span class="dv"><?php echo htmlspecialchars($order['franchisee_name'] ?? '—'); ?></span></div>
            <div class="detail-row"><span class="dl">Branch</span><span class="dv"><?php echo htmlspecialchars($order['branch_name'] ?? '—'); ?></span></div>
            <div class="detail-row"><span class="dl">Delivery Type</span><span class="dv"><?php echo htmlspecialchars($order['delivery_preference']); ?></span></div>
            <div class="detail-row"><span class="dl">Est. Date</span><span class="dv"><?php echo $order['estimated_pickup'] ? date('M d, Y', strtotime($order['estimated_pickup'])) : '—'; ?></span></div>
            <div class="detail-row"><span class="dl">Order Date</span><span class="dv"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></span></div>

            <div class="items-list">
                <p style="font-size:.85rem;font-weight:700;margin-bottom:.75rem;">Items (<?php echo count($orderItems); ?>)</p>
                <?php foreach ($orderItems as $item): ?>
                <div class="item-line">
                    <span><?php echo htmlspecialchars($item['name']); ?> (<?php echo htmlspecialchars($item['unit']); ?>) ×<?php echo $item['quantity']; ?></span>
                    <span style="font-weight:600;">₱<?php echo number_format($item['subtotal'] ?? $item['unit_price'] * $item['quantity'], 2); ?></span>
                </div>
                <?php endforeach; ?>
                <div class="item-line" style="margin-top:.5rem;font-weight:700;">
                    <span>Total Amount</span>
                    <span>₱<?php echo number_format($order['total_amount'], 2); ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>
<script>lucide.createIcons();</script>
</body>
</html>
