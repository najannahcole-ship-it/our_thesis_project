<?php
// ============================================================
// order-status.php — Order Pipeline Tracker
// DB Tables used:
//   READ → orders               (get PO details, status, step)
//   READ → order_items          (get line items for this order)
//   READ → products             (get product names for display)
//   READ → order_status_history (get timeline entries)
//   READ → franchisees          (verify this order belongs to the logged-in user)
// ============================================================

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

require_once 'db.php';

$userId   = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Franchisee';

$franchisee   = getFranchiseeByUser($conn, $userId);
$franchiseeId = $franchisee['id'] ?? null;

// ── Determine which order to show ─────────────────────────────
// URL param ?po=PO-2026-0001 → find that specific order
// No param → show the most recent active order for this franchisee
$requestedPO = isset($_GET['po']) ? trim($_GET['po']) : '';
$order       = null;
$orderItems  = [];
$history     = [];

if ($franchiseeId) {

    if ($requestedPO) {
        // Fetch the specific PO, but only if it belongs to this franchisee
        $stmt = $conn->prepare("SELECT * FROM orders WHERE po_number = ? AND franchisee_id = ?");
        $stmt->bind_param("si", $requestedPO, $franchiseeId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$order) {
        // Fall back to most recent order (including completed)
        $stmt = $conn->prepare("SELECT * FROM orders WHERE franchisee_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param("i", $franchiseeId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($order) {
        // Fetch line items with product names
        $stmt = $conn->prepare("
            SELECT oi.quantity, oi.unit_price, oi.subtotal, p.name, p.unit
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
        ");
        $stmt->bind_param("i", $order['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $orderItems[] = $row; }
        $stmt->close();

        // Fetch status history (newest first for the feed)
        $stmt = $conn->prepare("SELECT * FROM order_status_history WHERE order_id = ? ORDER BY changed_at DESC");
        $stmt->bind_param("i", $order['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $history[] = $row; }
        $stmt->close();
    }

    // Fetch active orders for the PO selector pills only — exclude completed and rejected
    $activePOs = [];
    $stmt = $conn->prepare("
        SELECT po_number, status, status_step
        FROM orders
        WHERE franchisee_id = ?
          AND status_step < 4
          AND status NOT IN ('completed','rejected','cancelled')
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $activePOs[] = $row; }
    $stmt->close();
}

$conn->close();

// Pipeline step definitions — aligned to actual status_step values in DB
// step 0=Submitted, 1=Under Review, 2=For Payment, 3=Processing, 3.5=Out for Delivery, 4=Completed
$STEPS = [
    ['label' => 'Submitted',        'icon' => 'file-text'],
    ['label' => 'Under Review',     'icon' => 'search'],
    ['label' => 'For Payment',      'icon' => 'credit-card'],
    ['label' => 'Processing',       'icon' => 'loader'],
    ['label' => 'Out for Delivery', 'icon' => 'truck'],
    ['label' => 'Completed',        'icon' => 'flag'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Tracking - Juan Café</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root{--background:#f7f3f0;--foreground:#2d241e;--sidebar-bg:#fdfaf7;--card:#ffffff;--card-border:#eeeae6;--primary:#5c4033;--primary-light:#8b5e3c;--accent:#d25424;--muted:#8c837d;--success:#10b981;--radius:16px;--sidebar-width:280px;}
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
        .avatar{width:36px;height:36px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;}
        .avatar i{color:var(--muted);}
        .user-meta h4{font-size:.85rem;font-weight:700;}
        .user-meta p{font-size:.75rem;color:var(--muted);}
        .sign-out{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--muted);font-size:.9rem;padding:.5rem;}
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{margin-bottom:2.5rem;display:flex;justify-content:space-between;align-items:flex-end;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);}
        .po-badge{background:var(--background);padding:.5rem 1rem;border-radius:10px;font-weight:700;color:var(--primary);font-size:1.1rem;border:1px solid var(--card-border);}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:2rem;}
        .section-title{font-family:'Fraunces',serif;font-size:1.25rem;margin-bottom:2rem;}
        /* Pipeline */
        .pipeline-container{display:flex;justify-content:space-between;position:relative;margin-bottom:2.5rem;padding:0 1rem;}
        .pipeline-container::before{content:'';position:absolute;top:20px;left:10%;right:10%;height:2px;background:#eeeae6;z-index:1;}
        .pipeline-step{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;width:120px;text-align:center;}
        .step-dot{width:42px;height:42px;border-radius:50%;background:white;border:2px solid #eeeae6;display:flex;align-items:center;justify-content:center;color:var(--muted);margin-bottom:.75rem;}
        .step-label{font-size:.85rem;font-weight:600;color:var(--muted);}
        .step-completed .step-dot{background:var(--success);border-color:var(--success);color:white;}
        .step-completed .step-label{color:var(--foreground);}
        .step-active .step-dot{background:var(--primary);border-color:var(--primary);color:white;box-shadow:0 0 0 4px rgba(92,64,51,.1);}
        .step-active .step-label{color:var(--primary);font-weight:700;}
        /* History feed */
        .history-item{display:flex;gap:1.5rem;position:relative;padding-bottom:2rem;}
        .history-item:not(:last-child)::before{content:'';position:absolute;left:11px;top:24px;bottom:0;width:2px;background:#eeeae6;}
        .history-dot{width:24px;height:24px;border-radius:50%;background:#eeeae6;flex-shrink:0;position:relative;z-index:2;}
        .history-item.is-latest .history-dot{background:var(--primary);}
        .history-content h4{font-size:1rem;margin-bottom:.25rem;}
        .history-content p{font-size:.85rem;color:var(--muted);}
        .history-time{font-size:.8rem;color:var(--muted);opacity:.7;display:block;margin-top:.25rem;}
        .items-preview{margin-top:1.5rem;border-top:1px solid var(--card-border);padding-top:1.5rem;}
        .item-row-d{display:flex;justify-content:space-between;margin-bottom:.5rem;font-size:.9rem;}
        .detail-total{margin-top:1rem;padding-top:1rem;border-top:1px solid var(--card-border);font-weight:700;}
        .payment-block{margin-top:1.5rem;padding:1rem;border-radius:12px;border:1.5px solid var(--card-border);}
        .payment-block.paid{background:#f0fdf4;border-color:#86efac;}
        .payment-block.unpaid{background:#fffbeb;border-color:#fde68a;}
        .pay-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;}
        .pay-badge{padding:.3rem .75rem;border-radius:20px;font-size:.78rem;font-weight:700;}
        .pay-badge.paid{background:#dcfce7;color:#166534;}
        .pay-badge.unpaid{background:#fef3c7;color:#92400e;}
        .po-selector{display:flex;gap:.75rem;margin-bottom:1.5rem;align-items:center;flex-wrap:wrap;}
        .po-pill{padding:.4rem .85rem;border-radius:20px;border:1px solid var(--card-border);background:white;font-size:.85rem;font-weight:600;text-decoration:none;color:var(--muted);transition:all .2s;}
        .po-pill:hover{border-color:var(--primary);color:var(--primary);}
        .po-pill.current{background:var(--primary);color:white;border-color:var(--primary);}
        .empty-state{text-align:center;padding:4rem 2rem;color:var(--muted);}
        .empty-state h3{color:var(--foreground);margin-bottom:.5rem;margin-top:1rem;}
        .btn-order{display:inline-block;margin-top:1.5rem;background:var(--primary);color:white;padding:.75rem 1.5rem;border-radius:12px;text-decoration:none;font-weight:600;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="coffee"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Franchise Portal</span><span style="font-size:.85rem;color:var(--primary);font-weight:600;margin-top:.1rem;display:block;line-height:1;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? '—'); ?></span></div></div>
    <div class="menu-label">Menu</div>
    <nav>
        <a href="franchisee-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="order-form.php" class="nav-item"><i data-lucide="clipboard-list"></i> Order Form</a>
        <a href="item-usage.php" class="nav-item"><i data-lucide="box"></i> Item Usage</a>
        <a href="order-status.php" class="nav-item active"><i data-lucide="package"></i> Order Status</a>
        <a href="returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i> Returns</a>
        <a href="order-history.php" class="nav-item"><i data-lucide="history"></i> Order History</a>
        <a href="profile.php" class="nav-item"><i data-lucide="user"></i> Profile</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($fullName); ?></h4><p style="font-size:.72rem;color:var(--muted);font-weight:500;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? '—'); ?></p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
</aside>

<main>
    <div class="header">
        <div><h2>Order Tracking</h2><p>Real-time updates on your purchase orders.</p></div>
        <?php if ($order): ?>
            <span class="po-badge"><?php echo htmlspecialchars($order['po_number']); ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$order): ?>
    <!-- No orders yet -->
    <div class="card">
        <div class="empty-state">
            <i data-lucide="package" size="48" style="opacity:.25;display:block;margin:0 auto;"></i>
            <h3>No orders yet</h3>
            <p>You haven't submitted any purchase orders.</p>
            <a href="order-form.php" class="btn-order">Place Your First Order</a>
        </div>
    </div>

    <?php else: ?>

    <!-- PO selector pills for multiple active orders -->
    <?php if (count($activePOs) > 1): ?>
    <div class="po-selector">
        <span style="font-size:.85rem;color:var(--muted);font-weight:600;">Switch order:</span>
        <?php foreach ($activePOs as $ap): ?>
            <a href="order-status.php?po=<?php echo urlencode($ap['po_number']); ?>"
               class="po-pill <?php echo $ap['po_number'] === $order['po_number'] ? 'current' : ''; ?>">
                <?php echo htmlspecialchars($ap['po_number']); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Pipeline -->
    <div class="card" style="margin-bottom:2rem;">
        <div class="pipeline-container">
            <?php
            $isPaid           = strtolower($order['payment_status'] ?? 'unpaid') === 'paid';
            $isFullyCompleted = intval($order['status_step']) >= 4;
            $deliveryStatus   = $order['delivery_status'] ?? 'pending';
            $isOutForDelivery = in_array($deliveryStatus, ['picked_up','in_transit','delivered']) || $isFullyCompleted;

            // STEPS indices: 0=Submitted,1=Review,2=Payment,3=Processing,4=OutForDelivery,5=Completed
            foreach ($STEPS as $i => $step):
                $cls  = '';
                $icon = $step['icon'];

                if ($i === 4) {
                    // Out for Delivery — driven by delivery_status
                    if ($isFullyCompleted)     { $cls = 'step-completed'; $icon = 'check'; }
                    elseif ($isOutForDelivery) { $cls = 'step-active'; }
                } elseif ($i === 5) {
                    // Completed
                    if ($isFullyCompleted)     { $cls = 'step-completed'; $icon = 'check'; }
                } else {
                    // Steps 0–3: use status_step
                    // Also mark as completed if Out for Delivery is active (meaning Processing is done)
                    if ($i < $order['status_step'] || ($isOutForDelivery && $i <= $order['status_step'])) {
                        $cls = 'step-completed'; $icon = 'check';
                    } elseif ($i == $order['status_step'] && !$isOutForDelivery && !$isFullyCompleted) {
                        $cls = 'step-active';
                    }
                }
            ?>
            <div class="pipeline-step <?php echo $cls; ?>">
                <div class="step-dot"><i data-lucide="<?php echo $icon; ?>" size="20"></i></div>
                <span class="step-label"><?php echo $step['label']; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Details + History -->
    <div style="display:grid;grid-template-columns:1fr 380px;gap:2rem;align-items:start;">

        <!-- Status history feed -->
        <div class="card">
            <h3 class="section-title">Status Updates</h3>
            <?php if (empty($history)): ?>
                <p style="color:var(--muted);">No status updates yet.</p>
            <?php else: ?>
                <?php foreach ($history as $idx => $h): ?>
                <div class="history-item <?php echo $idx === 0 ? 'is-latest' : ''; ?>">
                    <div class="history-dot"></div>
                    <div class="history-content">
                        <?php
                        $labelMap = [
                            'for_payment'      => 'For Payment',
                            'out_for_delivery' => 'Out for Delivery',
                            'Under Review'     => 'Under Review',
                            'Approved'         => 'Approved',
                            'Processing'       => 'Processing',
                            'completed'        => 'Completed',
                            'Rejected'         => 'Rejected',
                        ];
                        $rawLabel     = $h['status_label'];
                        $friendlyLabel = $labelMap[$rawLabel] ?? $rawLabel;
                        ?>
                        <h4><?php echo htmlspecialchars($friendlyLabel); ?></h4>
                        <p><?php echo htmlspecialchars($h['detail']); ?></p>
                        <span class="history-time"><?php echo date('M d, Y • h:i A', strtotime($h['changed_at'])); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Order details -->
        <div class="card">
            <h3 class="section-title">Order Details</h3>
            <div style="display:flex;justify-content:space-between;margin-bottom:.75rem;">
                <span style="color:var(--muted);font-size:.9rem;">Delivery Method</span>
                <strong style="font-size:.9rem;"><?php echo htmlspecialchars($order['delivery_preference']); ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:.75rem;">
                <span style="color:var(--muted);font-size:.9rem;">Est. Pickup / Delivery</span>
                <strong style="font-size:.9rem;"><?php echo $order['estimated_pickup'] ? date('M d, Y', strtotime($order['estimated_pickup'])) : '—'; ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:1.5rem;">
                <span style="color:var(--muted);font-size:.9rem;">Order Date</span>
                <strong style="font-size:.9rem;"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></strong>
            </div>

            <div class="items-preview">
                <h4 style="font-size:.9rem;margin-bottom:1rem;">Items (<?php echo count($orderItems); ?>)</h4>
                <?php foreach ($orderItems as $oi): ?>
                <div class="item-row-d">
                    <span><?php echo htmlspecialchars($oi['name']); ?> (<?php echo htmlspecialchars($oi['unit']); ?>)</span>
                    <span>×<?php echo $oi['quantity']; ?></span>
                </div>
                <?php endforeach; ?>
                <div class="item-row-d detail-total">
                    <span>Total Amount</span>
                    <span>₱<?php echo number_format($order['total_amount'], 2); ?></span>
                </div>
            </div>

            <!-- Payment Status Block -->
            <?php
            $payStatus  = $order['payment_status']  ?? 'unpaid';
            $payMethod  = $order['payment_method']   ?? null;
            $payRef     = $order['payment_ref']      ?? null;
            $isPaid     = strtolower($payStatus) === 'paid';
            ?>
            <div class="payment-block <?php echo $isPaid ? 'paid' : 'unpaid'; ?>">
                <div class="pay-head">
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <i data-lucide="<?php echo $isPaid ? 'check-circle' : 'clock'; ?>" size="16" style="color:<?php echo $isPaid ? '#166534' : '#92400e'; ?>;"></i>
                        <strong style="font-size:.92rem;">Payment Status</strong>
                    </div>
                    <span class="pay-badge <?php echo $isPaid ? 'paid' : 'unpaid'; ?>"><?php echo $isPaid ? '✓ Paid' : 'Unpaid'; ?></span>
                </div>
                <?php if ($isPaid && $payMethod): ?>
                <div style="font-size:.85rem;color:var(--muted);margin-top:.35rem;">
                    Method: <strong style="color:var(--foreground);"><?php echo htmlspecialchars($payMethod); ?></strong>
                    <?php if ($payRef): ?>· Ref: <strong style="color:var(--foreground);"><?php echo htmlspecialchars($payRef); ?></strong><?php endif; ?>
                </div>
                <?php if (!empty($order['payment_screenshot'])): ?>
                <div style="margin-top:.75rem;">
                    <p style="font-size:.72rem;color:#166534;font-weight:700;margin-bottom:.35rem;">Payment Screenshot:</p>
                    <img src="<?php echo htmlspecialchars($order['payment_screenshot']); ?>"
                         style="width:100%;border-radius:8px;max-height:160px;object-fit:cover;border:1px solid #86efac;cursor:pointer;"
                         onclick="this.style.maxHeight=this.style.maxHeight==='none'?'160px':'none'"
                         title="Click to expand">
                </div>
                <?php endif; ?>
                <?php elseif (!$isPaid): ?>
                <div style="font-size:.82rem;color:#92400e;margin-top:.35rem;">
                    <?php if ($payMethod && $payMethod !== 'Cash'): ?>
                    Your payment screenshot has been submitted. The encoder will confirm once verified.
                    <?php else: ?>
                    Payment has not been confirmed yet. The encoder will update this after receiving your payment.
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>lucide.createIcons();</script>
</body>
</html>