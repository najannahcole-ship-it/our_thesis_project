<?php
// ============================================================
// clerk-orders.php — Inventory Clerk Order Monitoring
// ============================================================
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: index.php'); exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

require_once 'db.php';

$clerkName = $_SESSION['full_name'] ?? 'Inventory Clerk';
$clerkId   = $_SESSION['user_id'];

// ── AJAX: fetch order details ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_order'])) {
    header('Content-Type: application/json');
    $orderId = intval($_GET['fetch_order']);

    $stmt = $conn->prepare("
        SELECT o.id, o.po_number, o.status, o.status_step,
               o.delivery_preference, o.delivery_fee,
               o.subtotal, o.total_amount, o.created_at, o.estimated_pickup,
               o.payment_method, o.payment_status, o.payment_ref,
               o.assigned_encoder_id, o.assigned_rider_id, o.approved_by,
               f.branch_name,
               COALESCE(f.franchisee_name, uf.full_name, '—') AS franchisee_name,
               enc.full_name  AS encoder_name,
               adm.full_name  AS approver_name,
               rdr.full_name  AS rider_name
        FROM orders o
        JOIN franchisees f   ON f.id          = o.franchisee_id
        LEFT JOIN users  uf  ON uf.user_id    = f.user_id
        LEFT JOIN users  enc ON enc.user_id   = o.assigned_encoder_id
        LEFT JOIN users  adm ON adm.user_id   = o.approved_by
        LEFT JOIN users  rdr ON rdr.user_id   = o.assigned_rider_id
        WHERE o.id = ?
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) { echo json_encode(['success' => false, 'message' => 'Order not found.']); $conn->close(); exit(); }

    $iStmt = $conn->prepare("
        SELECT oi.quantity, oi.unit_price, oi.subtotal,
               p.id AS product_id, p.name AS product_name,
               p.category, p.unit, p.stock_qty
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY p.category, p.name
    ");
    $iStmt->bind_param('i', $orderId);
    $iStmt->execute();
    $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $iStmt->close();

    $hStmt = $conn->prepare("
        SELECT osh.status_label, osh.detail, osh.changed_at,
               u.full_name AS changed_by_name
        FROM order_status_history osh
        LEFT JOIN users u ON u.user_id = osh.changed_by
        WHERE osh.order_id = ?
        ORDER BY osh.changed_at ASC
    ");
    $hStmt->bind_param('i', $orderId);
    $hStmt->execute();
    $history = $hStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $hStmt->close();

    $conn->close();
    echo json_encode(['success' => true, 'order' => $order, 'items' => $items, 'history' => $history]);
    exit();
}

// ── AJAX: fetch riders ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_riders'])) {
    header('Content-Type: application/json');
    $stmt = $conn->query("SELECT user_id AS id, full_name AS name FROM users WHERE role_id = 5 AND status = 'Active' ORDER BY full_name ASC");
    $riders = $stmt->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'riders' => $riders]);
    $conn->close(); exit();
}

// ── AJAX: Fulfill & Deduct ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'fulfill') {
    header('Content-Type: application/json');
    $orderId = intval($_POST['order_id'] ?? 0);
    $riderId = intval($_POST['rider_id'] ?? 0);

    if (!$orderId) { echo json_encode(['success' => false, 'message' => 'Invalid order.']); exit(); }

    $chk = $conn->prepare("SELECT id, po_number, status, delivery_preference, assigned_rider_id FROM orders WHERE id = ?");
    $chk->bind_param('i', $orderId);
    $chk->execute();
    $order = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$order) { echo json_encode(['success' => false, 'message' => 'Order not found.']); exit(); }
    if ($order['status'] !== 'Approved') { echo json_encode(['success' => false, 'message' => 'Order is no longer in Approved status.']); exit(); }

    $isDelivery   = stripos($order['delivery_preference'], 'pickup') === false;
    $finalRiderId = $riderId ?: intval($order['assigned_rider_id']);

    if ($isDelivery && !$finalRiderId) {
        echo json_encode(['success' => false, 'message' => 'A delivery rider must be assigned for delivery orders.']); exit();
    }

    $riderName = null;
    if ($finalRiderId) {
        $rChk = $conn->prepare("SELECT user_id, full_name FROM users WHERE user_id = ? AND role_id = 5 AND status = 'Active'");
        $rChk->bind_param('i', $finalRiderId);
        $rChk->execute();
        $riderRow = $rChk->get_result()->fetch_assoc();
        $rChk->close();
        if (!$riderRow) { echo json_encode(['success' => false, 'message' => 'Invalid rider selected.']); exit(); }
        $riderName = $riderRow['full_name'];
    }

    $iStmt = $conn->prepare("
        SELECT oi.product_id, oi.quantity, p.name, p.stock_qty
        FROM order_items oi JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $iStmt->bind_param('i', $orderId);
    $iStmt->execute();
    $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $iStmt->close();

    foreach ($items as $item) {
        if ($item['stock_qty'] < $item['quantity']) {
            echo json_encode(['success' => false, 'message' => 'Insufficient stock for ' . htmlspecialchars($item['name']) . '. Available: ' . $item['stock_qty'] . ', Required: ' . $item['quantity'] . '.']);
            exit();
        }
    }

    $conn->begin_transaction();
    try {
        $deduct = $conn->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");
        foreach ($items as $item) {
            $deduct->bind_param('ii', $item['quantity'], $item['product_id']);
            $deduct->execute();
        }
        $deduct->close();

        // Always mark as completed after fulfillment — clerk's job is done.
        // 'Ready' is not in the orders status enum so we use 'completed' for all cases.
        $newStatus   = 'completed';
        $newStep     = 4;
        $statusLabel = 'Completed';

        $updOrder = $conn->prepare("UPDATE orders SET status = ?, status_step = ?, assigned_rider_id = COALESCE(NULLIF(?,0), assigned_rider_id) WHERE id = ?");
        $updOrder->bind_param('siii', $newStatus, $newStep, $finalRiderId, $orderId);
        $updOrder->execute();
        $updOrder->close();

        $detail = 'Order fulfilled by Inventory Clerk ' . $clerkName . '. Stock deducted from warehouse.';
        if ($riderName) $detail .= ' Rider assigned: ' . $riderName . '.';

        $hist = $conn->prepare("INSERT INTO order_status_history (order_id, status_step, status_label, detail, changed_by) VALUES (?, ?, ?, ?, ?)");
        $hist->bind_param('iissi', $orderId, $newStep, $statusLabel, $detail, $clerkId);
        $hist->execute();
        $hist->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Order ' . $order['po_number'] . ' fulfilled and marked as Completed. Stock deducted successfully.']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed. Please try again.']);
    }
    $conn->close(); exit();
}

// ── Load orders by status ─────────────────────────────────────
function fetchOrders($conn, $status) {
    $stmt = $conn->prepare("
        SELECT o.id, o.po_number, o.status, o.delivery_preference,
               o.total_amount, o.created_at, o.payment_status,
               COALESCE(f.franchisee_name, uf.full_name, '—') AS franchisee_name,
               f.branch_name,
               enc.full_name AS encoder_name,
               adm.full_name AS approver_name
        FROM orders o
        JOIN franchisees f   ON f.id        = o.franchisee_id
        LEFT JOIN users  uf  ON uf.user_id  = f.user_id
        LEFT JOIN users  enc ON enc.user_id = o.assigned_encoder_id
        LEFT JOIN users  adm ON adm.user_id = o.approved_by
        WHERE o.status = ?
        ORDER BY o.created_at ASC
    ");
    $stmt->bind_param('s', $status);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$approvedOrders  = fetchOrders($conn, 'Approved');
$completedOrders = fetchOrders($conn, 'completed');
$pendingCount    = count($approvedOrders);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Monitoring — Top Juan Inventory</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --background:#f7f3f0;--foreground:#2d241e;--sidebar-bg:#fdfaf7;
            --card:#fff;--card-border:#eeeae6;--primary:#5c4033;--primary-light:#8b5e3c;
            --accent:#d25424;--muted:#8c837d;
            --success:#16a34a;--success-bg:#f0fdf4;
            --info:#2563eb;--info-bg:#eff6ff;
            --warning:#b45309;--warning-bg:#fffbeb;
            --danger:#991b1b;--danger-bg:#fef2f2;
            --sidebar-width:280px;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'DM Sans',sans-serif;background:var(--background);color:var(--foreground);display:flex;min-height:100vh;}
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
        .avatar{width:36px;height:36px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;}
        .avatar i{color:var(--muted);}
        .user-meta h4{font-size:.85rem;font-weight:700;}
        .user-meta p{font-size:.75rem;color:var(--muted);}
        .sign-out{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--muted);font-size:.9rem;padding:.5rem;transition:color .2s;}
        .sign-out:hover{color:var(--accent);}
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:2rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);}
        /* Tabs */
        .tabs{display:flex;gap:2rem;margin-bottom:2rem;border-bottom:1px solid var(--card-border);}
        .tab{padding-bottom:1rem;font-weight:600;color:var(--muted);cursor:pointer;position:relative;transition:all .2s;font-size:.95rem;}
        .tab:hover{color:var(--primary-light);}
        .tab.active{color:var(--primary);}
        .tab.active::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:2px;background:var(--primary);}
        .tab-count{display:inline-flex;align-items:center;justify-content:center;background:var(--warning-bg);color:var(--warning);border-radius:99px;font-size:.72rem;font-weight:700;padding:.15rem .5rem;margin-left:.4rem;}
        .tab.active .tab-count{background:var(--primary);color:white;}
        .tab-panel{display:none;}
        .tab-panel.active{display:block;}
        /* Card + Table */
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;overflow:hidden;margin-bottom:1.5rem;}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:1rem 1.5rem;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid var(--card-border);background:#faf8f6;white-space:nowrap;font-weight:700;}
        td{padding:1.1rem 1.5rem;font-size:.9rem;border-bottom:1px solid var(--card-border);vertical-align:middle;}
        tr:last-child td{border-bottom:none;}
        tr:hover td{background:#fdfaf7;}
        /* Pills */
        .pill{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .7rem;border-radius:99px;font-size:.75rem;font-weight:600;}
        .pill-approved{background:var(--info-bg);color:var(--info);}
        .pill-completed{background:#f3f4f6;color:#4b5563;}
        .pill-paid{background:var(--success-bg);color:var(--success);}
        .pill-unpaid{background:var(--warning-bg);color:var(--warning);}
        /* Buttons */
        .btn-view{padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;border:1px solid var(--card-border);background:#f3f4f6;color:#4b5563;transition:all .15s;}
        .btn-view:hover{background:#e5e7eb;}
        .btn-fulfill-sm{padding:.45rem 1rem;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;border:1px solid #bbf7d0;background:var(--success-bg);color:var(--success);margin-left:.5rem;transition:all .15s;display:inline-flex;align-items:center;gap:.35rem;}
        .btn-fulfill-sm:hover{background:#dcfce7;}
        .btn-fulfill-sm:disabled{opacity:.45;cursor:not-allowed;}
        .empty-row td{text-align:center;color:var(--muted);padding:3rem!important;font-style:italic;}
        .encoder-badge{display:inline-flex;align-items:center;gap:.3rem;background:var(--info-bg);color:var(--info);padding:.25rem .65rem;border-radius:99px;font-size:.78rem;font-weight:600;}
        /* Modal */
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:100;backdrop-filter:blur(4px);padding:1rem;}
        .modal-overlay.open{display:flex;}
        .modal{background:white;width:100%;max-width:820px;border-radius:24px;padding:2.5rem;max-height:90vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.15);}
        .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;}
        .modal-header h3{font-family:'Fraunces',serif;font-size:1.5rem;}
        .modal-close{cursor:pointer;color:var(--muted);background:none;border:none;padding:.25rem;}
        /* Approval banner */
        .approval-info{background:var(--info-bg);border:1px solid #bfdbfe;border-radius:14px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:grid;grid-template-columns:1fr 1fr;gap:.75rem 2rem;}
        .ai-item label{font-size:.7rem;text-transform:uppercase;color:#1d4ed8;font-weight:700;letter-spacing:.04em;}
        .ai-item p{font-size:.88rem;font-weight:600;color:var(--foreground);margin-top:.2rem;}
        /* Detail grid */
        .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem 2.5rem;margin-bottom:2rem;}
        .detail-group label{display:block;font-size:.73rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.04em;margin-bottom:.4rem;}
        .detail-group p{font-weight:500;font-size:.95rem;}
        .detail-group .sub{font-size:.82rem;color:var(--muted);margin-top:.1rem;}
        /* Items list */
        .items-label{font-size:.73rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.04em;margin-bottom:.5rem;display:block;}
        .item-list{border:1px solid var(--card-border);border-radius:14px;overflow:hidden;margin-bottom:1.5rem;}
        .item-list table th{background:#faf8f6;padding:.75rem 1.25rem;}
        .item-list table td{padding:.875rem 1.25rem;}
        .stock-ok{color:var(--success);font-weight:600;}
        .stock-low{color:var(--warning);font-weight:600;}
        .stock-out{color:var(--danger);font-weight:600;}
        /* Totals */
        .modal-totals{display:flex;flex-direction:column;align-items:flex-end;gap:.4rem;margin-bottom:1.5rem;}
        .total-row{display:flex;gap:3rem;font-size:.9rem;}
        .total-row .t-label{color:var(--muted);}
        .total-row.grand{font-size:1.1rem;font-weight:700;color:var(--primary);padding-top:.75rem;border-top:1.5px solid var(--card-border);margin-top:.25rem;}
        /* Timeline */
        .timeline-label{font-size:.73rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.04em;margin-bottom:.75rem;}
        .timeline-item{display:flex;gap:1rem;margin-bottom:.6rem;font-size:.85rem;}
        .tl-dot{width:10px;height:10px;border-radius:50%;background:var(--primary);flex-shrink:0;margin-top:.3rem;}
        .tl-label{font-weight:600;}
        .tl-detail{color:var(--muted);font-size:.8rem;margin-top:.1rem;}
        .tl-time{color:var(--muted);font-size:.78rem;}
        /* Assign rider panel — same style as admin encoder panel */
        .assign-rider-panel {
            margin-top: 1.75rem;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
        }
        .assign-rider-panel label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #166534;
            margin-bottom: 0.75rem;
            letter-spacing: 0.05em;
        }
        .encoder-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .encoder-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.65rem 1rem;
            border-radius: 10px;
            border: 2px solid #d1fae5;
            background: white;
            cursor: pointer;
            transition: all 0.15s;
        }
        .encoder-option:hover { border-color: #16a34a; background: #f0fdf4; }
        .encoder-option.selected { border-color: #16a34a; background: #dcfce7; }
        .encoder-option input[type="radio"] { display: none; }
        .encoder-name { font-weight: 600; font-size: 0.9rem; color: var(--foreground); }
        .encoder-load { font-size: 0.78rem; font-weight: 600; padding: 0.2rem 0.6rem; border-radius: 99px; }
        .load-free { background: #f0fdf4; color: #166534; }
        .load-busy { background: #fffbeb; color: #b45309; }
        .encoder-list-loading { color: var(--muted); font-size: 0.9rem; text-align: center; padding: 1rem; }
        /* Modal actions */
        .modal-actions{display:flex;justify-content:flex-end;gap:1rem;margin-top:1.5rem;}
        .btn-lg{padding:.875rem 2rem;border-radius:12px;font-weight:700;font-size:.95rem;cursor:pointer;border:none;transition:all .2s;}
        .btn-secondary{background:#f3f4f6;color:#4b5563;}
        .btn-secondary:hover{background:#e5e7eb;}
        .btn-fulfill-lg{background:var(--success);color:white;display:flex;align-items:center;gap:.5rem;}
        .btn-fulfill-lg:hover{background:#15803d;}
        .btn-fulfill-lg:disabled{opacity:.45;cursor:not-allowed;}
        /* 2nd Confirm */
        .confirm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:200;backdrop-filter:blur(6px);}
        .confirm-overlay.open{display:flex;}
        .confirm-dialog{background:white;border-radius:20px;padding:2.25rem 2.5rem;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.18);text-align:center;animation:popIn .18s ease;}
        @keyframes popIn{from{transform:scale(.92);opacity:0;}to{transform:scale(1);opacity:1;}}
        .confirm-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;background:var(--success-bg);color:var(--success);font-size:1.5rem;}
        .confirm-dialog h4{font-family:'Fraunces',serif;font-size:1.2rem;margin-bottom:.5rem;}
        .confirm-dialog p{font-size:.88rem;color:var(--muted);line-height:1.55;margin-bottom:.35rem;}
        .confirm-po{font-weight:700;color:var(--primary);font-size:.95rem;margin-bottom:1.5rem;display:block;}
        .confirm-warn{font-size:.82rem;color:#991b1b;font-weight:600;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.6rem .875rem;margin-top:.75rem;}
        .confirm-btns{display:flex;gap:.75rem;justify-content:center;margin-top:1.5rem;}
        .confirm-btns button{flex:1;padding:.8rem 1.25rem;border-radius:12px;font-weight:700;font-size:.9rem;cursor:pointer;border:none;transition:filter .2s;}
        .confirm-btns button:hover{filter:brightness(.93);}
        .cb-cancel{background:#f3f4f6;color:#4b5563;}
        .cb-confirm{background:var(--success);color:white;}
        /* Toast */
        .toast{position:fixed;bottom:2rem;right:2rem;background:var(--foreground);color:white;padding:1rem 1.5rem;border-radius:14px;font-weight:600;font-size:.9rem;z-index:9999;display:none;align-items:center;gap:.75rem;box-shadow:0 8px 24px rgba(0,0,0,.15);max-width:380px;}
        .toast.show{display:flex;}
        .toast.success{background:#166534;}
        .toast.error{background:#991b1b;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container">
        <div class="logo-icon"><i data-lucide="coffee"></i></div>
        <div class="logo-text"><h1>Top Juan</h1><span>Inventory Portal</span></div>
    </div>
    <div class="menu-label">Menu</div>
    <nav>
        <a href="clerk-dashboard.php"  class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="clerk-orders.php"     class="nav-item active"><i data-lucide="clipboard-list"></i> Order Monitoring</a>
        <a href="clerk-inventory.php"  class="nav-item"><i data-lucide="boxes"></i> Inventory</a>
        <a href="clerk-receiving.php"  class="nav-item"><i data-lucide="download"></i> Stock Receiving</a>
        <a href="clerk-adjustment.php" class="nav-item"><i data-lucide="edit-3"></i> Stock Adjustment</a>
        <a href="clerk-reports.php"    class="nav-item"><i data-lucide="bar-chart-3"></i> Report</a>
    </nav>
    <div class="user-profile">
        <div class="avatar"><i data-lucide="user"></i></div>
        <div class="user-meta">
            <h4><?php echo htmlspecialchars($clerkName); ?></h4>
            <p>Inventory Clerk</p>
        </div>
    </div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
</aside>

<main>
    <div class="header">
        <div>
            <h2>Order Monitoring</h2>
            <p>Review approved orders, fulfill stock, and assign delivery riders</p>
        </div>
        <?php if ($pendingCount > 0): ?>
        <div style="display:inline-flex;align-items:center;gap:.4rem;background:var(--warning-bg);color:var(--warning);border:1px solid #fde68a;padding:.45rem 1rem;border-radius:99px;font-size:.82rem;font-weight:700;">
            <i data-lucide="clock" style="width:14px;height:14px;"></i>
            <?php echo $pendingCount; ?> Awaiting Fulfillment
        </div>
        <?php endif; ?>
    </div>

    <div class="tabs">
        <div class="tab active" data-target="approved" onclick="switchTab('approved')">
            For Fulfillment <span class="tab-count"><?php echo count($approvedOrders); ?></span>
        </div>
        <div class="tab" data-target="completed" onclick="switchTab('completed')">
            Completed <span class="tab-count"><?php echo count($completedOrders); ?></span>
        </div>
    </div>

    <!-- For Fulfillment Tab -->
    <div class="tab-panel active" id="panel-approved">
        <div class="card">
            <table>
                <thead><tr>
                    <th>PO Number</th><th>Branch</th><th>Approved By</th>
                    <th>Encoder</th><th>Delivery</th><th>Amount</th>
                    <th>Payment</th><th style="text-align:right">Actions</th>
                </tr></thead>
                <tbody>
                <?php if (empty($approvedOrders)): ?>
                <tr class="empty-row"><td colspan="8">No orders awaiting fulfillment.</td></tr>
                <?php else: foreach ($approvedOrders as $o): ?>
                <tr>
                    <td style="font-weight:700;"><?php echo htmlspecialchars($o['po_number']); ?></td>
                    <td>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($o['branch_name']); ?></div>
                        <div style="font-size:.78rem;color:var(--muted);"><?php echo htmlspecialchars($o['franchisee_name']); ?></div>
                    </td>
                    <td style="font-size:.85rem;"><?php echo htmlspecialchars($o['approver_name'] ?? '—'); ?></td>
                    <td>
                        <?php if (!empty($o['encoder_name'])): ?>
                        <span class="encoder-badge"><i data-lucide="user-check" style="width:12px;height:12px;"></i> <?php echo htmlspecialchars($o['encoder_name']); ?></span>
                        <?php else: ?><span style="color:var(--muted);">—</span><?php endif; ?>
                    </td>
                    <td style="font-size:.85rem;"><?php echo htmlspecialchars($o['delivery_preference']); ?></td>
                    <td style="font-weight:600;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                    <td>
                        <span class="pill <?php echo strtolower($o['payment_status'] ?? '') === 'paid' ? 'pill-paid' : 'pill-unpaid'; ?>">
                            <?php echo ucfirst($o['payment_status'] ?? 'unpaid'); ?>
                        </span>
                    </td>
                    <td style="text-align:right;">
                        <button class="btn-view" onclick="openModal(<?php echo $o['id']; ?>)">View Details</button>
                        <button class="btn-fulfill-sm" onclick="openModal(<?php echo $o['id']; ?>, true)">
                            <i data-lucide="package-check" style="width:13px;height:13px;"></i> Fulfill &amp; Deduct
                        </button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Completed Tab -->
    <div class="tab-panel" id="panel-completed">
        <div class="card">
            <table>
                <thead><tr>
                    <th>PO Number</th><th>Branch</th><th>Delivery</th>
                    <th>Amount</th><th>Status</th><th style="text-align:right">Actions</th>
                </tr></thead>
                <tbody>
                <?php if (empty($completedOrders)): ?>
                <tr class="empty-row"><td colspan="6">No completed orders.</td></tr>
                <?php else: foreach ($completedOrders as $o): ?>
                <tr>
                    <td style="font-weight:700;"><?php echo htmlspecialchars($o['po_number']); ?></td>
                    <td>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($o['branch_name']); ?></div>
                        <div style="font-size:.78rem;color:var(--muted);"><?php echo htmlspecialchars($o['franchisee_name']); ?></div>
                    </td>
                    <td style="font-size:.85rem;"><?php echo htmlspecialchars($o['delivery_preference']); ?></td>
                    <td style="font-weight:600;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                    <td><span class="pill pill-completed">Completed</span></td>
                    <td style="text-align:right;"><button class="btn-view" onclick="openModal(<?php echo $o['id']; ?>)">View</button></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Order Detail Modal -->
<div class="modal-overlay" id="orderModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modal-title">Order Details</h3>
            <button class="modal-close" onclick="closeModal()"><i data-lucide="x" style="width:22px;height:22px;"></i></button>
        </div>
        <div id="modal-loading" style="text-align:center;padding:3rem;color:var(--muted);">Loading order details…</div>
        <div id="modal-content" style="display:none;">

            <!-- Approval info -->
            <div class="approval-info">
                <div class="ai-item"><label>Approved By (Admin)</label><p id="md-approver">—</p></div>
                <div class="ai-item"><label>Assigned Data Encoder</label><p id="md-encoder">—</p></div>
                <div class="ai-item"><label>Assigned Rider</label><p id="md-rider">—</p></div>
                <div class="ai-item"><label>Payment Status</label><p id="md-pay-status">—</p></div>
            </div>

            <!-- Order info -->
            <div class="detail-grid">
                <div class="detail-group"><label>Branch</label><p id="md-branch"></p><p class="sub" id="md-franchisee"></p></div>
                <div class="detail-group"><label>Delivery Method</label><p id="md-delivery"></p><p class="sub" id="md-pickup"></p></div>
                <div class="detail-group"><label>Date Submitted</label><p id="md-date"></p></div>
                <div class="detail-group"><label>Payment Method</label><p id="md-payment"></p><p class="sub" id="md-ref"></p></div>
            </div>

            <!-- Items -->
            <span class="items-label">Items Ordered</span>
            <div class="item-list">
                <table>
                    <thead><tr>
                        <th>Item</th><th>Category</th><th>Ordered</th>
                        <th>Warehouse Stock</th><th style="text-align:right">Unit Price</th><th style="text-align:right">Subtotal</th>
                    </tr></thead>
                    <tbody id="modal-items"></tbody>
                </table>
            </div>

            <!-- Totals -->
            <div class="modal-totals">
                <div class="total-row"><span class="t-label">Subtotal</span><span id="md-subtotal"></span></div>
                <div class="total-row"><span class="t-label">Delivery Fee</span><span id="md-fee"></span></div>
                <div class="total-row grand"><span class="t-label">Grand Total</span><span id="md-grand"></span></div>
            </div>

            <!-- Timeline -->
            <div style="margin-bottom:1.5rem;">
                <div class="timeline-label">Status Timeline</div>
                <div id="timeline-items"></div>
            </div>

            <!-- Rider assignment (delivery orders awaiting fulfillment only) -->
            <div class="assign-rider-panel" id="riderPanel" style="display:none;">
                <label>
                    <i data-lucide="bike" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>
                    Assign Delivery Rider <span style="color:#dc2626;">*</span>
                    <span style="font-weight:400;font-size:.7rem;text-transform:none;letter-spacing:0;"> (required for delivery orders)</span>
                </label>
                <div class="encoder-list" id="riderList">
                    <div class="encoder-list-loading">Loading riders…</div>
                </div>
            </div>

            <div class="modal-actions" id="modal-actions"></div>
        </div>
    </div>
</div>

<!-- 2nd Confirmation Dialog -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-dialog">
        <div class="confirm-icon">✓</div>
        <h4>Confirm Fulfillment</h4>
        <p id="confirm-body-text">You are about to fulfill this order and permanently deduct stock from the warehouse.</p>
        <span class="confirm-po" id="confirm-po"></span>
        <p class="confirm-warn">⚠ This action cannot be undone. Stock will be deducted immediately.</p>
        <div class="confirm-btns">
            <button class="cb-cancel" onclick="closeConfirm()">Cancel</button>
            <button class="cb-confirm" onclick="proceedFulfill()">Yes, Fulfill Order</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"><span id="toast-msg"></span></div>

<script>
    lucide.createIcons();

    let currentOrderId   = null;
    let currentOrderData = null;
    let selectedRiderId  = null;
    let fulfillMode      = false;

    function switchTab(tabId) {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`.tab[data-target="${tabId}"]`).classList.add('active');
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        document.getElementById(`panel-${tabId}`).classList.add('active');
    }

    function openModal(orderId, withFulfill = false) {
        currentOrderId  = orderId;
        fulfillMode     = withFulfill;
        selectedRiderId = null;
        document.getElementById('orderModal').classList.add('open');
        document.getElementById('modal-loading').style.display = 'block';
        document.getElementById('modal-content').style.display = 'none';

        fetch(`clerk-orders.php?fetch_order=${orderId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { showToast(data.message, 'error'); closeModal(); return; }
                currentOrderData = data.order;
                renderModal(data.order, data.items, data.history);
                const isDelivery = !data.order.delivery_preference.toLowerCase().includes('pickup');
                if (data.order.status === 'Approved' && isDelivery) fetchRiders();
            })
            .catch(() => { showToast('Failed to load order details.', 'error'); closeModal(); });
    }

    function renderModal(order, items, history) {
        document.getElementById('modal-title').textContent = `Order Details — ${order.po_number}`;

        document.getElementById('md-approver').textContent = order.approver_name || '—';
        document.getElementById('md-encoder').textContent  = order.encoder_name  || '—';
        document.getElementById('md-rider').textContent    = order.rider_name    || 'Not yet assigned';
        const ps = (order.payment_status || 'unpaid').toLowerCase();
        document.getElementById('md-pay-status').innerHTML = ps === 'paid'
            ? '<span style="color:#166534;font-weight:700;">✓ Paid</span>'
            : '<span style="color:#b45309;font-weight:700;">! Unpaid</span>';

        document.getElementById('md-branch').textContent     = order.branch_name;
        document.getElementById('md-franchisee').textContent = order.franchisee_name;
        document.getElementById('md-delivery').textContent   = order.delivery_preference;
        document.getElementById('md-pickup').textContent     = order.estimated_pickup ? 'Est. ' + fmtDateOnly(order.estimated_pickup) : '';
        document.getElementById('md-date').textContent       = fmtDate(order.created_at);
        document.getElementById('md-payment').textContent    = order.payment_method || '—';
        document.getElementById('md-ref').textContent        = order.payment_ref ? `Ref: ${order.payment_ref}` : '';

        let hasStockIssue = false;
        document.getElementById('modal-items').innerHTML = items.map(i => {
            const stock = parseInt(i.stock_qty), ordered = parseInt(i.quantity);
            let cls, lbl;
            if (stock === 0)          { cls='stock-out'; lbl=`✕ Out of Stock`; hasStockIssue=true; }
            else if (stock < ordered) { cls='stock-out'; lbl=`⚠ Only ${stock} left`; hasStockIssue=true; }
            else if (stock <= 10)     { cls='stock-low'; lbl=`⚠ Low (${stock})`; }
            else                      { cls='stock-ok';  lbl=`✓ ${stock}`; }
            return `<tr>
                <td style="font-weight:500;">${esc(i.product_name)}</td>
                <td><span style="font-size:.75rem;background:var(--background);border:1px solid var(--card-border);padding:2px 8px;border-radius:6px;">${esc(i.category)}</span></td>
                <td style="font-weight:700;">${i.quantity} ${esc(i.unit)}</td>
                <td class="${cls}">${lbl}</td>
                <td style="text-align:right;">₱${fmt(i.unit_price)}</td>
                <td style="text-align:right;font-weight:600;">₱${fmt(i.subtotal)}</td>
            </tr>`;
        }).join('');

        document.getElementById('md-subtotal').textContent = '₱' + fmt(order.subtotal);
        document.getElementById('md-fee').textContent      = '₱' + fmt(order.delivery_fee);
        document.getElementById('md-grand').textContent    = '₱' + fmt(order.total_amount);

        document.getElementById('timeline-items').innerHTML = history.length
            ? history.map(h => `<div class="timeline-item">
                <div class="tl-dot"></div>
                <div>
                    <div class="tl-label">${esc(h.status_label)}</div>
                    <div class="tl-detail">${esc(h.detail || '')}</div>
                    <div class="tl-time">${fmtDate(h.changed_at)}${h.changed_by_name ? ' · ' + esc(h.changed_by_name) : ''}</div>
                </div></div>`).join('')
            : '<p style="font-size:.85rem;color:var(--muted);">No history recorded yet.</p>';

        const isDelivery = !order.delivery_preference.toLowerCase().includes('pickup');
        const isApproved = order.status === 'Approved';
        document.getElementById('riderPanel').style.display = (isApproved && isDelivery) ? 'block' : 'none';

        const actionsDiv = document.getElementById('modal-actions');
        if (isApproved) {
            const dis = hasStockIssue ? 'disabled title="Insufficient stock"' : '';
            actionsDiv.innerHTML = `
                <button class="btn-lg btn-secondary" onclick="closeModal()">Close</button>
                <button class="btn-lg btn-fulfill-lg" id="btn-fulfill" onclick="triggerFulfill('${esc(order.po_number)}')" ${dis}>
                    <i data-lucide="package-check" style="width:17px;height:17px;"></i> Fulfill &amp; Deduct Stock
                </button>`;
        } else {
            actionsDiv.innerHTML = `<button class="btn-lg btn-secondary" onclick="closeModal()">Close</button>`;
        }

        document.getElementById('modal-loading').style.display = 'none';
        document.getElementById('modal-content').style.display = 'block';
        lucide.createIcons();

        if (fulfillMode && isApproved && isDelivery) {
            setTimeout(() => document.getElementById('riderPanel').scrollIntoView({ behavior:'smooth', block:'nearest' }), 300);
        }
    }

    function closeModal() {
        document.getElementById('orderModal').classList.remove('open');
        document.getElementById('riderList').innerHTML = '<div class="encoder-list-loading">Loading riders…</div>';
        currentOrderId = currentOrderData = selectedRiderId = null;
        fulfillMode = false;
    }

    function fetchRiders() {
        fetch('clerk-orders.php?fetch_riders=1')
            .then(r => r.json())
            .then(data => {
                const list = document.getElementById('riderList');
                if (!data.success || !data.riders.length) {
                    list.innerHTML = '<div class="encoder-list-loading">No active riders found.</div>';
                    return;
                }
                const pre = currentOrderData?.assigned_rider_id;
                list.innerHTML = data.riders.map(r => {
                    const isSelected = String(r.id) === String(pre);
                    return `
                    <label class="encoder-option ${isSelected ? 'selected' : ''}"
                           id="rider-opt-${r.id}"
                           onclick="selectRider(${r.id}, '${esc(r.name)}', this)">
                        <input type="radio" name="rider_pick" value="${r.id}" ${isSelected ? 'checked' : ''}>
                        <span class="encoder-name">${esc(r.name)}</span>
                        <span class="encoder-load load-free">Available</span>
                    </label>`;
                }).join('');
                if (pre) selectedRiderId = parseInt(pre);
            })
            .catch(() => { document.getElementById('riderList').innerHTML = '<div class="encoder-list-loading">Failed to load riders.</div>'; });
    }

    function selectRider(id, name, el) {
        document.querySelectorAll('#riderList .encoder-option').forEach(o => o.classList.remove('selected'));
        el.classList.add('selected');
        selectedRiderId = id;
    }

    function triggerFulfill(poNumber) {
        if (!currentOrderId) return;
        const isDelivery = !currentOrderData?.delivery_preference?.toLowerCase().includes('pickup');
        if (isDelivery && !selectedRiderId) {
            const panel = document.getElementById('riderPanel');
            panel.style.border = '2px solid #dc2626';
            setTimeout(() => panel.style.border = '1px solid #bbf7d0', 2000);
            showToast('Please assign a delivery rider before fulfilling.', 'error');
            return;
        }

        // Update confirm dialog body
        document.getElementById('confirm-body-text').innerHTML =
            'Stock will be permanently deducted from the warehouse and the order will be marked as <strong>Completed</strong>.';
        document.getElementById('confirm-po').textContent = poNumber;
        document.getElementById('confirmOverlay').classList.add('open');
        lucide.createIcons();
    }

    function closeConfirm() {
        document.getElementById('confirmOverlay').classList.remove('open');
    }

    function proceedFulfill() {
        if (!currentOrderId) return;
        closeConfirm();
        const btn = document.getElementById('btn-fulfill');
        if (btn) btn.disabled = true;

        const body = new URLSearchParams({ ajax_action: 'fulfill', order_id: currentOrderId });
        if (selectedRiderId) body.append('rider_id', selectedRiderId);

        fetch('clerk-orders.php', { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message, 'error');
                    if (btn) btn.disabled = false;
                }
            })
            .catch(() => { showToast('Network error. Please try again.', 'error'); if (btn) btn.disabled = false; });
    }

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ','T'));
        return d.toLocaleString('en-PH',{month:'short',day:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:true});
    }
    function fmtDateOnly(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ','T'));
        return d.toLocaleDateString('en-PH',{month:'short',day:'2-digit',year:'numeric'});
    }
    function fmt(n) { return Number(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
    function showToast(msg, type='success') {
        const t = document.getElementById('toast');
        document.getElementById('toast-msg').textContent = msg;
        t.className = `toast show ${type}`;
        setTimeout(() => t.classList.remove('show'), 4500);
    }

    document.getElementById('orderModal').addEventListener('click', function(e) { if (e.target===this) closeModal(); });
    document.getElementById('confirmOverlay').addEventListener('click', function(e) { if (e.target===this) closeConfirm(); });
</script>
</body>
</html>