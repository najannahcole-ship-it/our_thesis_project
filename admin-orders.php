<?php
// ============================================================
// admin-orders.php — Admin Order Management
// DB Tables used:
//   READ  → orders               (fetch all POs by status)
//   READ  → order_items          (fetch line items per order)
//   READ  → franchisees          (get branch info)
//   READ  → products             (get product names/categories)
//   WRITE → orders               (update status on approve/reject)
//   WRITE → order_status_history (log every status change)
// ============================================================

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: index.php');
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

require_once 'db.php';

$adminId   = $_SESSION['user_id'];
$adminName = $_SESSION['full_name'] ?? 'System Admin';

// ── Handle AJAX actions (Approve / Reject) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    $action  = $_POST['ajax_action'];
    $orderId = intval($_POST['order_id'] ?? 0);

    if (!$orderId) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
        exit();
    }

    // Verify the order exists and is still Under Review
    $chk = $conn->prepare("SELECT id, po_number, status FROM orders WHERE id = ?");
    $chk->bind_param("i", $orderId);
    $chk->execute();
    $order = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit();
    }
    if ($order['status'] !== 'Under Review') {
        echo json_encode(['success' => false, 'message' => 'This order has already been processed.']);
        exit();
    }

    if ($action === 'approve') {
        $encoderId = intval($_POST['encoder_id'] ?? 0);

        if (!$encoderId) {
            echo json_encode(['success' => false, 'message' => 'Please select a Data Encoder to assign this order.']);
            exit();
        }

        // Verify the encoder exists and has role_id = 4
        $encChk = $conn->prepare("SELECT user_id, full_name FROM users WHERE user_id = ? AND role_id = 4 AND status = 'Active'");
        $encChk->bind_param("i", $encoderId);
        $encChk->execute();
        $encoder = $encChk->get_result()->fetch_assoc();
        $encChk->close();
        if (!$encoder) {
            echo json_encode(['success' => false, 'message' => 'Invalid encoder selected.']);
            exit();
        }

        // ── SERVER-SIDE STOCK CHECK: block approval if any item has insufficient stock ──
        $stockChk = $conn->prepare("
            SELECT p.name, oi.quantity, p.stock_qty
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
              AND p.stock_qty < oi.quantity
        ");
        $stockChk->bind_param("i", $orderId);
        $stockChk->execute();
        $stockResult = $stockChk->get_result();
        $outOfStockItems = [];
        while ($row = $stockResult->fetch_assoc()) {
            $outOfStockItems[] = $row['name'] . ' (ordered: ' . $row['quantity'] . ', in stock: ' . $row['stock_qty'] . ')';
        }
        $stockChk->close();

        if (!empty($outOfStockItems)) {
            $itemList = implode('; ', $outOfStockItems);
            echo json_encode([
                'success' => false,
                'message' => 'Cannot approve: insufficient stock for — ' . $itemList . '. Please update inventory before approving.'
            ]);
            exit();
        }
        // ── END STOCK CHECK ───────────────────────────────────────────────────────────

        // ── Approve: set status → Approved, status_step → 2, assign encoder
        $upd = $conn->prepare("UPDATE orders SET status = 'Approved', status_step = 2, assigned_encoder_id = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
        $upd->bind_param("iii", $encoderId, $adminId, $orderId);
        $upd->execute();
        $upd->close();

        // Log to history
        $detail = 'Order approved by administrator and assigned to Data Encoder: ' . $encoder['full_name'] . '.';
        $hist = $conn->prepare("
            INSERT INTO order_status_history (order_id, status_step, status_label, detail, changed_at, changed_by)
            VALUES (?, 2, 'Approved', ?, NOW(), ?)
        ");
        $hist->bind_param("isi", $orderId, $detail, $adminId);
        $hist->execute();
        $hist->close();

        echo json_encode(['success' => true, 'message' => 'Order approved and assigned to ' . htmlspecialchars($encoder['full_name']) . '.']);

    } elseif ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if (empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Please provide a rejection reason.']);
            exit();
        }

        // ── Reject: set status → Rejected, status_step → -1
        $upd = $conn->prepare("UPDATE orders SET status = 'Rejected', status_step = -1 WHERE id = ?");
        $upd->bind_param("i", $orderId);
        $upd->execute();
        $upd->close();

        // Log to history with reason
        $detail = 'Order rejected by administrator. Reason: ' . $conn->real_escape_string($reason);
        $hist = $conn->prepare("
            INSERT INTO order_status_history (order_id, status_step, status_label, detail, changed_at, changed_by)
            VALUES (?, -1, 'Rejected', ?, NOW(), ?)
        ");
        $hist->bind_param("isi", $orderId, $detail, $adminId);
        $hist->execute();
        $hist->close();

        echo json_encode(['success' => true, 'message' => 'Order has been rejected and franchisee will be notified.']);

    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }

    $conn->close();
    exit();
}

// ── Handle AJAX: fetch order details for modal ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_order'])) {
    header('Content-Type: application/json');

    $orderId = intval($_GET['fetch_order']);

    $stmt = $conn->prepare("
        SELECT o.id, o.po_number, o.status, o.delivery_preference, o.delivery_fee,
               o.subtotal, o.total_amount, o.created_at, o.estimated_pickup,
               o.payment_method, o.payment_status, o.payment_ref, o.payment_proof,
               f.franchisee_name, f.branch_name,
               u.full_name AS assigned_encoder_name
        FROM orders o
        JOIN franchisees f ON f.id = o.franchisee_id
        LEFT JOIN users u ON u.user_id = o.assigned_encoder_id
        WHERE o.id = ?
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        $conn->close();
        exit();
    }

    // Fetch line items
    $items = [];
    $iStmt = $conn->prepare("
        SELECT oi.quantity, oi.unit_price, oi.subtotal,
               p.name AS product_name, p.category, p.unit,
               p.stock_qty, p.low_stock_threshold
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $iStmt->bind_param("i", $orderId);
    $iStmt->execute();
    $iRes = $iStmt->get_result();
    while ($row = $iRes->fetch_assoc()) { $items[] = $row; }
    $iStmt->close();

    $conn->close();
    echo json_encode(['success' => true, 'order' => $order, 'items' => $items]);
    exit();
}

// ── Handle AJAX: fetch encoders with workload ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_encoders'])) {
    header('Content-Type: application/json');

    $stmt = $conn->query("
        SELECT u.user_id, u.full_name,
               COUNT(o.id) AS active_orders
        FROM users u
        LEFT JOIN orders o
            ON o.assigned_encoder_id = u.user_id
            AND o.status NOT IN ('Completed','Rejected')
        WHERE u.role_id = 4 AND u.status = 'Active'
        GROUP BY u.user_id, u.full_name
        ORDER BY active_orders ASC, u.full_name ASC
    ");
    $encoders = [];
    while ($row = $stmt->fetch_assoc()) { $encoders[] = $row; }

    echo json_encode(['success' => true, 'encoders' => $encoders]);
    $conn->close();
    exit();
}

// ── Fetch orders grouped by status for page render ───────────
function fetchOrdersByStatus($conn, $status) {
    $stmt = $conn->prepare("
        SELECT o.id, o.po_number, o.status, o.delivery_preference,
               o.total_amount, o.created_at,
               f.franchisee_name, f.branch_name,
               u.full_name AS assigned_encoder_name
        FROM orders o
        JOIN franchisees f ON f.id = o.franchisee_id
        LEFT JOIN users u ON u.user_id = o.assigned_encoder_id
        WHERE o.status = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param("s", $status);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) { $rows[] = $row; }
    $stmt->close();
    return $rows;
}

$pendingOrders  = fetchOrdersByStatus($conn, 'Under Review');
$approvedOrders = fetchOrdersByStatus($conn, 'Approved');
$rejectedOrders = fetchOrdersByStatus($conn, 'Rejected');

// ── Fetch encoder workload: all encoders + their assigned orders ──
$encoderWorkload = [];
$ewRes = $conn->query("
    SELECT u.user_id, u.full_name,
           o.id AS order_id, o.po_number, o.status, o.status_step,
           o.total_amount, o.created_at, o.delivery_preference,
           f.franchisee_name, f.branch_name
    FROM users u
    LEFT JOIN orders o
        ON o.assigned_encoder_id = u.user_id
        AND o.status NOT IN ('Rejected')
    LEFT JOIN franchisees f ON f.id = o.franchisee_id
    WHERE u.role_id = 4 AND u.status = 'Active'
    ORDER BY u.full_name ASC, o.created_at DESC
");
while ($row = $ewRes->fetch_assoc()) {
    $uid = $row['user_id'];
    if (!isset($encoderWorkload[$uid])) {
        $encoderWorkload[$uid] = [
            'user_id'    => $uid,
            'full_name'  => $row['full_name'],
            'orders'     => []
        ];
    }
    if ($row['order_id']) {
        $encoderWorkload[$uid]['orders'][] = $row;
    }
}

$conn->close();

// ── Helper: format date ───────────────────────────────────────
function fmtDate($dt) {
    return date('M d, Y, h:i A', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Requests - Top Juan Inc.</title>
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
            --status-review-bg: #fffbeb;
            --status-review-text: #b45309;
            --status-pickup-bg: #f0fdf4;
            --status-pickup-text: #166534;
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

        /* Sidebar */
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

        .logo-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 2.5rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .logo-text h1 { font-family: 'Fraunces', serif; font-size: 1.25rem; line-height: 1; }
        .logo-text span { font-size: 0.75rem; color: var(--muted); font-weight: 500; }

        .menu-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            margin-bottom: 1rem;
            font-weight: 700;
        }

        nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            border-radius: 12px;
            text-decoration: none;
            color: var(--muted);
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .nav-item i { width: 20px; height: 20px; stroke-width: 2px; }
        .nav-item:hover { color: var(--primary); background: rgba(92, 64, 51, 0.05); }
        .nav-item.active { background: var(--primary); color: white; }

        .user-profile {
            margin-top: auto;
            background: white;
            border: 1px solid var(--card-border);
            padding: 1rem;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e5e7eb;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .avatar i { color: var(--muted); }
        .user-meta h4 { font-size: 0.85rem; font-weight: 700; }
        .user-meta p { font-size: 0.75rem; color: var(--muted); }

        .sign-out {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--muted);
            font-size: 0.9rem;
            padding: 0.5rem;
            transition: color 0.2s;
        }
        .sign-out:hover { color: var(--accent); }

        /* Main Content */
        main {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 2.5rem 3rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2.5rem;
        }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p { color: var(--muted); font-size: 1rem; }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--card-border);
        }
        .tab {
            padding-bottom: 1rem;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            position: relative;
            transition: all 0.2s;
        }
        .tab:hover { color: var(--primary-light); }
        .tab.active { color: var(--primary); }
        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        /* Order Table */
        .card {
            background: white;
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 0;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .order-table { display: none; width: 100%; }
        .order-table.active { display: table; }

        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left;
            padding: 1rem 1.5rem;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            border-bottom: 1px solid var(--card-border);
            background: #faf8f6;
        }
        td {
            padding: 1.25rem 1.5rem;
            font-size: 0.9rem;
            border-bottom: 1px solid var(--card-border);
        }
        tr:last-child td { border-bottom: none; }

        .status-pill {
            padding: 0.35rem 0.75rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .pill-pending   { background: #fffbeb; color: #b45309; }
        .pill-approved  { background: #f0fdf4; color: #166534; }
        .pill-rejected  { background: #fef2f2; color: #991b1b; }

        .encoder-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: #eff6ff;
            color: #1d4ed8;
            padding: 0.3rem 0.65rem;
            border-radius: 99px;
            font-size: 0.78rem;
            font-weight: 600;
        }

        /* Assign encoder panel inside modal */
        .assign-encoder-panel {
            margin-top: 1.75rem;
            background: #f8faff;
            border: 1px solid #dbeafe;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
        }
        .assign-encoder-panel label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #1d4ed8;
            margin-bottom: 0.75rem;
            letter-spacing: 0.05em;
        }

        .encoder-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .encoder-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.65rem 1rem;
            border-radius: 10px;
            border: 2px solid #e0e7ff;
            background: white;
            cursor: pointer;
            transition: all 0.15s;
        }
        .encoder-option:hover { border-color: #6366f1; background: #eef2ff; }
        .encoder-option.selected { border-color: #4f46e5; background: #eef2ff; }
        .encoder-option input[type="radio"] { display: none; }
        .encoder-name { font-weight: 600; font-size: 0.9rem; color: var(--foreground); }
        .encoder-load {
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.2rem 0.6rem;
            border-radius: 99px;
        }
        .load-free { background: #f0fdf4; color: #166534; }
        .load-busy { background: #fffbeb; color: #b45309; }
        .load-heavy { background: #fef2f2; color: #991b1b; }
        .encoder-list-loading { color: var(--muted); font-size: 0.9rem; text-align: center; padding: 1rem; }

        /* ── Encoder Workload Tab ── */
        .encoder-workload-list {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            padding: 1.5rem;
        }
        .encoder-card {
            border: 1px solid var(--card-border);
            border-radius: 16px;
            overflow: hidden;
            background: white;
        }
        .encoder-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
            background: #faf8f6;
            border-bottom: 1px solid var(--card-border);
            cursor: pointer;
            user-select: none;
            transition: background 0.15s;
        }
        .encoder-card-header:hover { background: #f3ede8; }
        .encoder-card-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .encoder-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #eff6ff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1d4ed8;
            flex-shrink: 0;
        }
        .encoder-card-name { font-weight: 700; font-size: 0.95rem; }
        .encoder-card-meta { font-size: 0.78rem; color: var(--muted); margin-top: 0.1rem; }
        .encoder-card-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .workload-badge {
            font-size: 0.78rem;
            font-weight: 700;
            padding: 0.3rem 0.75rem;
            border-radius: 99px;
        }
        .wb-free   { background: #f0fdf4; color: #166534; }
        .wb-busy   { background: #fffbeb; color: #b45309; }
        .wb-heavy  { background: #fef2f2; color: #991b1b; }
        .encoder-chevron { color: var(--muted); transition: transform 0.2s; }
        .encoder-chevron.open { transform: rotate(180deg); }

        .encoder-card-body { display: none; }
        .encoder-card-body.open { display: block; }

        .encoder-orders-table { width: 100%; border-collapse: collapse; }
        .encoder-orders-table th {
            text-align: left;
            padding: 0.75rem 1.5rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            border-bottom: 1px solid var(--card-border);
            background: #fdfcfb;
            font-weight: 700;
        }
        .encoder-orders-table td {
            padding: 1rem 1.5rem;
            font-size: 0.88rem;
            border-bottom: 1px solid var(--card-border);
        }
        .encoder-orders-table tr:last-child td { border-bottom: none; }
        .encoder-empty {
            text-align: center;
            padding: 2rem;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .btn-view    { background: #f3f4f6; color: #4b5563; }
        .btn-approve { background: #f0fdf4; color: #166534; margin-left: 0.5rem; }
        .btn-reject  { background: #fef2f2; color: #991b1b; margin-left: 0.5rem; }
        .btn-action:hover { filter: brightness(0.95); }

        .empty-row td { text-align: center; color: var(--muted); padding: 3rem !important; }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 100;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.open { display: flex; }

        .modal {
            background: white;
            width: 100%;
            max-width: 820px;
            border-radius: 24px;
            padding: 2.5rem;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .modal-header h3 { font-family: 'Fraunces', serif; font-size: 1.5rem; }
        .modal-close { cursor: pointer; color: var(--muted); transition: color 0.2s; }
        .modal-close:hover { color: var(--foreground); }

        .order-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        .detail-group label {
            display: block;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .detail-group p { font-weight: 500; }
        .detail-group .sub { color: var(--muted); font-size: 0.85rem; margin-top: 0.15rem; }

        .items-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: block;
        }
        .item-list {
            border: 1px solid var(--card-border);
            border-radius: 16px;
            overflow: hidden;
        }
        .item-list table th { background: #faf8f6; }
        .item-list table td { padding: 0.9rem 1.25rem; }

        .modal-totals {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.4rem;
            margin-top: 1.25rem;
            padding-right: 0.5rem;
        }
        .total-row { display: flex; gap: 2rem; font-size: 0.9rem; }
        .total-row .t-label { color: var(--muted); }
        .total-row.grand { font-size: 1.1rem; font-weight: 700; color: var(--primary); border-top: 1px solid var(--card-border); padding-top: 0.75rem; margin-top: 0.35rem; }

        .modal-actions {
            margin-top: 2.5rem;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        .btn-large {
            padding: 0.875rem 2rem;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .btn-large:hover { filter: brightness(0.93); }
        .btn-large:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            filter: none;
        }
        .btn-primary   { background: var(--primary); color: white; }
        .btn-secondary { background: #f3f4f6; color: #4b5563; }
        .btn-danger    { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        /* Reject reason form inside modal */
        .reject-form { display: none; margin-top: 1.5rem; }
        .reject-form.visible { display: block; }
        .reject-form label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 0.5rem;
        }
        .reject-form textarea {
            width: 100%;
            min-height: 90px;
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            resize: vertical;
            outline: none;
        }
        .reject-form textarea:focus { border-color: var(--accent); }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--foreground);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 14px;
            font-weight: 600;
            font-size: 0.9rem;
            z-index: 9999;
            display: none;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            max-width: 380px;
        }
        .toast.show { display: flex; }
        .toast.success { background: #166534; }
        .toast.error   { background: #991b1b; }

        /* Stock badges */
        .stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.6rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .stock-ok         { background: #f0fdf4; color: #166534; }
        .stock-low        { background: #fffbeb; color: #b45309; }
        .stock-out        { background: #fef2f2; color: #991b1b; }

        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 16px; height: 16px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Confirmation Dialog ── */
        .confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 200;
            backdrop-filter: blur(6px);
        }
        .confirm-overlay.open { display: flex; }

        .confirm-dialog {
            background: white;
            border-radius: 20px;
            padding: 2.25rem 2.5rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.18);
            text-align: center;
            animation: popIn 0.18s ease;
        }
        @keyframes popIn {
            from { transform: scale(0.92); opacity: 0; }
            to   { transform: scale(1);    opacity: 1; }
        }

        .confirm-icon {
            width: 56px; height: 56px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
            font-size: 1.5rem;
        }
        .confirm-icon.approve { background: #f0fdf4; color: #166534; }
        .confirm-icon.reject  { background: #fef2f2; color: #991b1b; }

        .confirm-dialog h4 {
            font-family: 'Fraunces', serif;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            color: var(--foreground);
        }
        .confirm-dialog p {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.55;
            margin-bottom: 0.35rem;
        }
        .confirm-dialog .confirm-po {
            font-weight: 700;
            color: var(--primary);
            font-size: 0.95rem;
            margin-bottom: 1.75rem;
            display: block;
        }

        .confirm-btns {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
        }
        .confirm-btns button {
            flex: 1;
            padding: 0.8rem 1.25rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            border: none;
            transition: filter 0.2s;
        }
        .confirm-btns button:hover { filter: brightness(0.93); }
        .confirm-btns .cb-cancel  { background: #f3f4f6; color: #4b5563; }
        .confirm-btns .cb-approve { background: var(--primary); color: white; }
        .confirm-btns .cb-reject  { background: #991b1b; color: white; }

        /* ── Screenshot Lightbox ── */
        .lightbox-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.85);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 500;
            padding: 1.5rem;
            backdrop-filter: blur(6px);
        }
        .lightbox-overlay.open { display: flex; }
        .lightbox-inner {
            position: relative;
            max-width: 720px;
            width: 100%;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(0,0,0,.4);
        }
        .lightbox-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .875rem 1.25rem;
            background: #fafafa;
            border-bottom: 1px solid var(--card-border);
        }
        .lightbox-header span {
            font-weight: 700;
            font-size: .9rem;
            display: flex;
            align-items: center;
            gap: .4rem;
        }
        .lightbox-close {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted);
            font-size: 1.4rem;
            line-height: 1;
            padding: .25rem .5rem;
            border-radius: 6px;
            transition: background .15s;
        }
        .lightbox-close:hover { background: #f3f4f6; color: var(--foreground); }
        .lightbox-img-wrap {
            background: #1a1a1a;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 200px;
            max-height: 70vh;
            overflow: hidden;
        }
        .lightbox-img-wrap img {
            max-width: 100%;
            max-height: 70vh;
            object-fit: contain;
            display: block;
        }
        .lightbox-footer {
            padding: .75rem 1.25rem;
            background: #fafafa;
            border-top: 1px solid var(--card-border);
            font-size: .8rem;
            color: var(--muted);
            text-align: center;
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
            <a href="admin-dashboard.php"   class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
            <a href="admin-orders.php"      class="nav-item active"><i data-lucide="clipboard-list"></i> Order Request</a>
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
                <h2>Order Requests</h2>
                <p>Manage and verify branch purchase orders</p>
            </div>
        </div>

        <div class="tabs" id="orderTabs">
            <div class="tab active" data-target="pending" onclick="switchTab('pending')">
                Pending Review (<?php echo count($pendingOrders); ?>)
            </div>
            <div class="tab" data-target="approved" onclick="switchTab('approved')">
                Approved (<?php echo count($approvedOrders); ?>)
            </div>
            <div class="tab" data-target="rejected" onclick="switchTab('rejected')">
                Rejected (<?php echo count($rejectedOrders); ?>)
            </div>
            <div class="tab" data-target="workload" onclick="switchTab('workload')">
                Encoder Workload (<?php echo count($encoderWorkload); ?>)
            </div>
        </div>

        <div class="card">

            <!-- ── Pending Orders Table ── -->
            <table class="order-table active" id="table-pending">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Franchisee / Branch</th>
                        <th>Date Submitted</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th style="text-align: right">Actions</th>
                    </tr>
                </thead>
                <tbody id="pending-list">
                    <?php if (empty($pendingOrders)): ?>
                    <tr class="empty-row"><td colspan="6">No pending orders at this time.</td></tr>
                    <?php else: foreach ($pendingOrders as $o): ?>
                    <tr id="row-<?php echo $o['id']; ?>">
                        <td style="font-weight: 700;"><?php echo htmlspecialchars($o['po_number']); ?></td>
                        <td>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($o['franchisee_name']); ?></div>
                            <div style="font-size: 0.8rem; color: var(--muted);"><?php echo htmlspecialchars($o['branch_name']); ?></div>
                        </td>
                        <td><?php echo fmtDate($o['created_at']); ?></td>
                        <td style="font-weight: 600;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                        <td><span class="status-pill pill-pending">Under Review</span></td>
                        <td style="text-align: right">
                            <button class="btn-action btn-view"    onclick="openModal(<?php echo $o['id']; ?>)">View Details</button>
                            <button class="btn-action btn-approve" onclick="quickApprove(<?php echo $o['id']; ?>, '<?php echo htmlspecialchars($o['po_number']); ?>')">Approve</button>
                            <button class="btn-action btn-reject"  onclick="openModal(<?php echo $o['id']; ?>, true)">Reject</button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <!-- ── Approved Orders Table ── -->
            <table class="order-table" id="table-approved">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Franchisee / Branch</th>
                        <th>Date Approved</th>
                        <th>Amount</th>
                        <th>Assigned To</th>
                        <th>Status</th>
                        <th style="text-align: right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($approvedOrders)): ?>
                    <tr class="empty-row"><td colspan="7">No approved orders yet.</td></tr>
                    <?php else: foreach ($approvedOrders as $o): ?>
                    <tr id="row-<?php echo $o['id']; ?>">
                        <td style="font-weight: 700;"><?php echo htmlspecialchars($o['po_number']); ?></td>
                        <td>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($o['franchisee_name']); ?></div>
                            <div style="font-size: 0.8rem; color: var(--muted);"><?php echo htmlspecialchars($o['branch_name']); ?></div>
                        </td>
                        <td><?php echo fmtDate($o['created_at']); ?></td>
                        <td style="font-weight: 600;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                        <td>
                            <?php if (!empty($o['assigned_encoder_name'])): ?>
                                <span class="encoder-badge"><i data-lucide="user-check" style="width:13px;height:13px;"></i> <?php echo htmlspecialchars($o['assigned_encoder_name']); ?></span>
                            <?php else: ?>
                                <span style="color:var(--muted);font-size:.85rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="status-pill pill-approved">Approved</span></td>
                        <td style="text-align: right">
                            <button class="btn-action btn-view" onclick="openModal(<?php echo $o['id']; ?>)">View</button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <!-- ── Rejected Orders Table ── -->
            <table class="order-table" id="table-rejected">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Franchisee / Branch</th>
                        <th>Date Rejected</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th style="text-align: right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rejectedOrders)): ?>
                    <tr class="empty-row"><td colspan="6">No rejected orders.</td></tr>
                    <?php else: foreach ($rejectedOrders as $o): ?>
                    <tr id="row-<?php echo $o['id']; ?>">
                        <td style="font-weight: 700;"><?php echo htmlspecialchars($o['po_number']); ?></td>
                        <td>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($o['franchisee_name']); ?></div>
                            <div style="font-size: 0.8rem; color: var(--muted);"><?php echo htmlspecialchars($o['branch_name']); ?></div>
                        </td>
                        <td><?php echo fmtDate($o['created_at']); ?></td>
                        <td style="font-weight: 600;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                        <td><span class="status-pill pill-rejected">Rejected</span></td>
                        <td style="text-align: right">
                            <button class="btn-action btn-view" onclick="openModal(<?php echo $o['id']; ?>)">View</button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <!-- ── Encoder Workload Panel ── -->
            <div class="order-table" id="table-workload">
                <div class="encoder-workload-list">
                    <?php if (empty($encoderWorkload)): ?>
                    <div class="encoder-empty">No active data encoders found.</div>
                    <?php else: foreach ($encoderWorkload as $enc):
                        $orderCount = count($enc['orders']);
                        if ($orderCount === 0)       { $wbClass = 'wb-free';  $wbLabel = 'No Orders'; }
                        elseif ($orderCount <= 2)    { $wbClass = 'wb-busy';  $wbLabel = $orderCount . ' Active Order' . ($orderCount > 1 ? 's' : ''); }
                        else                         { $wbClass = 'wb-heavy'; $wbLabel = $orderCount . ' Active Orders'; }
                    ?>
                    <div class="encoder-card">
                        <div class="encoder-card-header" onclick="toggleEncoderCard(<?php echo $enc['user_id']; ?>)">
                            <div class="encoder-card-header-left">
                                <div class="encoder-avatar"><i data-lucide="user" style="width:18px;height:18px;"></i></div>
                                <div>
                                    <div class="encoder-card-name"><?php echo htmlspecialchars($enc['full_name']); ?></div>
                                    <div class="encoder-card-meta">Data Encoder</div>
                                </div>
                            </div>
                            <div class="encoder-card-right">
                                <span class="workload-badge <?php echo $wbClass; ?>"><?php echo $wbLabel; ?></span>
                                <i data-lucide="chevron-down" class="encoder-chevron" id="chevron-<?php echo $enc['user_id']; ?>" style="width:18px;height:18px;"></i>
                            </div>
                        </div>
                        <div class="encoder-card-body" id="enc-body-<?php echo $enc['user_id']; ?>">
                            <?php if (empty($enc['orders'])): ?>
                            <div class="encoder-empty">This encoder has no assigned orders.</div>
                            <?php else: ?>
                            <table class="encoder-orders-table">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Franchisee / Branch</th>
                                        <th>Date</th>
                                        <th>Delivery</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th style="text-align:right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enc['orders'] as $o):
                                        $stepLabels = [0=>'Submitted',1=>'Under Review',2=>'Processing',3=>'Ready',4=>'Completed'];
                                        $stepClasses = [0=>'pill-pending',1=>'pill-pending',2=>'pill-approved',3=>'pill-approved',4=>'pill-approved'];
                                        $sLabel = $stepLabels[$o['status_step']] ?? $o['status'];
                                        $sClass = $stepClasses[$o['status_step']] ?? 'pill-pending';
                                    ?>
                                    <tr>
                                        <td style="font-weight:700;"><?php echo htmlspecialchars($o['po_number']); ?></td>
                                        <td>
                                            <div style="font-weight:600;"><?php echo htmlspecialchars($o['franchisee_name'] ?? '—'); ?></div>
                                            <div style="font-size:.78rem;color:var(--muted);"><?php echo htmlspecialchars($o['branch_name'] ?? '—'); ?></div>
                                        </td>
                                        <td style="color:var(--muted);font-size:.85rem;"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                                        <td style="font-size:.85rem;"><?php echo htmlspecialchars($o['delivery_preference']); ?></td>
                                        <td style="font-weight:700;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                                        <td><span class="status-pill <?php echo $sClass; ?>"><?php echo $sLabel; ?></span></td>
                                        <td style="text-align:right;">
                                            <button class="btn-action btn-view" onclick="openModal(<?php echo $o['order_id']; ?>)">View</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

        </div>
    </main>

    <!-- ── Order Detail Modal ── -->
    <div class="modal-overlay" id="orderModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modal-title">Order Details</h3>
                <i data-lucide="x" class="modal-close" onclick="closeModal()"></i>
            </div>

            <!-- Loading state -->
            <div id="modal-loading" style="text-align:center; padding: 3rem; color: var(--muted);">
                Loading order details…
            </div>

            <!-- Content (hidden while loading) -->
            <div id="modal-content" style="display:none;">
                <div class="order-details-grid">
                    <div class="detail-group">
                        <label>Franchisee Information</label>
                        <p id="md-franchisee"></p>
                        <p class="sub" id="md-branch"></p>
                    </div>
                    <div class="detail-group">
                        <label>Delivery Preference</label>
                        <p id="md-delivery"></p>
                        <p class="sub" id="md-pickup"></p>
                    </div>
                    <div class="detail-group">
                        <label>Date Submitted</label>
                        <p id="md-date"></p>
                    </div>
                    <div class="detail-group">
                        <label>Payment Method</label>
                        <p id="md-payment"></p>
                        <p class="sub" id="md-payment-status"></p>
                    </div>
                    <div class="detail-group">
                        <label>Estimated Total</label>
                        <p id="md-total" style="font-size: 1.25rem; font-weight: 700; color: var(--primary);"></p>
                    </div>
                </div>

                <!-- Assigned encoder display (shown for non-Under Review orders) -->
                <div id="md-assigned-wrap" style="display:none; margin-bottom:1.5rem;">
                    <div class="detail-group">
                        <label>Assigned Data Encoder</label>
                        <p id="md-assigned-encoder"></p>
                    </div>
                </div>

                <span class="items-label">Ordered Items</span>
                <div class="item-list">
                    <table>
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Qty Ordered</th>
                                <th>Current Stock</th>
                                <th style="text-align:right">Unit Price</th>
                                <th style="text-align:right">Total</th>
                            </tr>
                        </thead>
                        <tbody id="modal-items"></tbody>
                    </table>
                </div>

                <div class="modal-totals">
                    <div class="total-row"><span class="t-label">Subtotal</span><span id="md-subtotal"></span></div>
                    <div class="total-row"><span class="t-label">Delivery Fee</span><span id="md-fee"></span></div>
                    <div class="total-row grand"><span class="t-label">Grand Total</span><span id="md-grand"></span></div>
                    <div class="total-row" id="md-ref-wrap" style="display:none;margin-top:.6rem;"><span class="t-label">Payment Ref #</span><span id="md-ref" style="font-size:.85rem;color:var(--muted);font-family:monospace;"></span></div>
                    <div class="total-row" id="md-screenshot-wrap" style="display:none;margin-top:.5rem;align-items:center;">
                        <span class="t-label">Payment Proof</span>
                        <button id="md-screenshot-btn" type="button" onclick="" style="display:inline-flex;align-items:center;gap:.4rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:8px;padding:.35rem .85rem;font-size:.82rem;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s;">
                            🖼 View Photo
                        </button>
                    </div>
                </div>

                <!-- Payment Proof Banner: shown when order has ref + screenshot (Paid) -->
                <div id="md-paid-banner" style="display:none;margin-top:1.25rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:.85rem 1.25rem;align-items:center;gap:.75rem;font-size:.88rem;color:#166534;font-weight:600;">
                    <span style="font-size:1.2rem;">✓</span>
                    <span>Franchisee has submitted a payment reference and screenshot. This order is marked as <strong>Paid</strong>.</span>
                </div>

                <!-- Unpaid notice: shown for GCash/Card without proof -->
                <div id="md-unpaid-banner" style="display:none;margin-top:1.25rem;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:.85rem 1.25rem;align-items:center;gap:.75rem;font-size:.88rem;color:#92400e;font-weight:600;">
                    <span style="font-size:1.2rem;">!</span>
                    <span>No payment proof submitted. Payment is <strong>Unpaid</strong> — collect payment upon delivery or pickup.</span>
                </div>

                <!-- Assign encoder panel (shown only when Under Review) -->
                <div class="assign-encoder-panel" id="assignEncoderPanel" style="display:none;">
                    <label><i data-lucide="user-check" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i> Assign to Data Encoder</label>
                    <div class="encoder-list" id="encoderList">
                        <div class="encoder-list-loading">Loading encoders…</div>
                    </div>
                </div>

                <!-- Reject reason (shown only when rejecting) -->
                <div class="reject-form" id="rejectForm">
                    <label>Rejection Reason <span style="color:var(--accent)">*</span></label>
                    <textarea id="rejectReason" placeholder="e.g. Item out of stock, incorrect quantities, etc."></textarea>
                </div>

                <!-- Stock warning banner (shown if any item has insufficient stock) -->
                <div id="stock-warning-banner" style="display:none; margin-top:1.25rem; background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:0.85rem 1.25rem; align-items:center; gap:0.65rem; font-size:0.88rem; color:#991b1b; font-weight:600;">
                    <span style="font-size:1.1rem;">⚠</span>
                    <span id="stock-warning-text"></span>
                </div>

                <div class="modal-actions" id="modal-actions">
                    <!-- Buttons injected by JS based on order status -->
                </div>
            </div>
        </div>
    </div>

    <!-- ── Confirmation Dialog ── -->
    <div class="confirm-overlay" id="confirmOverlay">
        <div class="confirm-dialog">
            <div class="confirm-icon" id="confirm-icon">
                <i data-lucide="help-circle"></i>
            </div>
            <h4 id="confirm-title">Are you sure?</h4>
            <p id="confirm-body">This action cannot be undone.</p>
            <span class="confirm-po" id="confirm-po"></span>
            <div class="confirm-btns">
                <button class="cb-cancel" onclick="closeConfirm()">Cancel</button>
                <button id="confirm-proceed-btn" onclick="proceedConfirmed()">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Toast notification -->
    <div class="toast" id="toast">
        <span id="toast-icon"></span>
        <span id="toast-msg"></span>
    </div>

    <script>
        lucide.createIcons();

        let currentOrderId      = null;
        let currentOrderStatus  = null;
        let rejectMode          = false;
        let selectedEncoderId   = null;
        let selectedEncoderName = null;

        // Tracks whether the currently open order has any out-of-stock items
        let currentOrderHasStockIssue = false;

        // Confirmation dialog state
        let confirmAction      = null; // 'approve' | 'reject'
        let confirmOrderId     = null;
        let confirmReason      = null;
        let confirmPoNumber    = null;
        let confirmEncoderId   = null;
        let confirmEncoderName = null;

        // ── Tab switching ─────────────────────────────────────────
        function switchTab(tabId) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`.tab[data-target="${tabId}"]`).classList.add('active');
            document.querySelectorAll('.order-table').forEach(t => t.classList.remove('active'));
            document.getElementById(`table-${tabId}`).classList.add('active');
        }

        // ── Toggle encoder card expand/collapse ───────────────────
        function toggleEncoderCard(userId) {
            const body    = document.getElementById(`enc-body-${userId}`);
            const chevron = document.getElementById(`chevron-${userId}`);
            const isOpen  = body.classList.contains('open');
            body.classList.toggle('open', !isOpen);
            chevron.classList.toggle('open', !isOpen);
            lucide.createIcons();
        }

        // ── Open modal & fetch order data ─────────────────────────
        function openModal(orderId, openInRejectMode = false) {
            currentOrderId            = parseInt(orderId, 10);
            rejectMode                = openInRejectMode;
            selectedEncoderId         = null;
            selectedEncoderName       = null;
            currentOrderHasStockIssue = false;

            document.getElementById('orderModal').classList.add('open');
            document.getElementById('modal-loading').style.display = 'block';
            document.getElementById('modal-content').style.display = 'none';

            fetch(`admin-orders.php?fetch_order=${orderId}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { showToast(data.message, 'error'); closeModal(); return; }
                    renderModal(data.order, data.items);
                    // Pre-load encoders if order is Under Review
                    if (data.order.status === 'Under Review') {
                        fetchEncoders();
                    }
                })
                .catch(() => { showToast('Failed to load order details.', 'error'); closeModal(); });
        }

        function renderModal(order, items) {
            currentOrderStatus = order.status;

            document.getElementById('modal-title').textContent   = `Order Details — ${order.po_number}`;
            document.getElementById('md-franchisee').textContent = order.franchisee_name;
            document.getElementById('md-branch').textContent     = order.branch_name;
            document.getElementById('md-delivery').textContent   = order.delivery_preference;
            document.getElementById('md-pickup').textContent     = order.estimated_pickup
                ? 'Est. ' + (order.delivery_preference === 'Self Pickup' ? 'Pickup' : 'Delivery') + ': ' + fmtDateOnly(order.estimated_pickup)
                : '';
            document.getElementById('md-date').textContent       = fmtDate(order.created_at);
            document.getElementById('md-total').textContent      = '₱' + fmt(order.total_amount);
            document.getElementById('md-subtotal').textContent   = '₱' + fmt(order.subtotal);
            document.getElementById('md-fee').textContent        = '₱' + fmt(order.delivery_fee);
            document.getElementById('md-grand').textContent      = '₱' + fmt(order.total_amount);

            // Payment info
            const payMethod    = order.payment_method || 'Cash';
            const payStatusRaw = (order.payment_status || 'unpaid').toLowerCase();
            document.getElementById('md-payment').textContent = payMethod;

            // Payment status badge
            const payStatusEl = document.getElementById('md-payment-status');
            if (payStatusRaw === 'paid') {
                payStatusEl.innerHTML = '<span style="display:inline-flex;align-items:center;gap:.35rem;background:#f0fdf4;color:#166534;font-weight:700;font-size:.8rem;padding:.25rem .65rem;border-radius:99px;"><span>✓</span> Paid</span>';
            } else {
                payStatusEl.innerHTML = '<span style="display:inline-flex;align-items:center;gap:.35rem;background:#fffbeb;color:#b45309;font-weight:700;font-size:.8rem;padding:.25rem .65rem;border-radius:99px;"><span>!</span> Unpaid</span>';
            }

            // Payment reference
            const refWrap = document.getElementById('md-ref-wrap');
            const refEl   = document.getElementById('md-ref');
            if (order.payment_ref) {
                refEl.textContent     = order.payment_ref;
                refWrap.style.display = 'flex';
            } else {
                refWrap.style.display = 'none';
            }

            // Payment screenshot — View Photo button
            const screenshotWrap = document.getElementById('md-screenshot-wrap');
            const screenshotBtn  = document.getElementById('md-screenshot-btn');
            if (order.payment_proof) {
                screenshotBtn.onclick = () => openScreenshotLightbox(order.payment_proof);
                screenshotWrap.style.display = 'flex';
            } else {
                screenshotWrap.style.display = 'none';
            }

            // Paid / Unpaid banners
            const paidBanner   = document.getElementById('md-paid-banner');
            const unpaidBanner = document.getElementById('md-unpaid-banner');
            const isCash       = (payMethod.toLowerCase() === 'cash');
            if (payStatusRaw === 'paid') {
                paidBanner.style.display   = 'flex';
                unpaidBanner.style.display = 'none';
            } else if (!isCash) {
                paidBanner.style.display   = 'none';
                unpaidBanner.style.display = 'flex';
            } else {
                paidBanner.style.display   = 'none';
                unpaidBanner.style.display = 'none';
            }

            // Show assigned encoder if already approved
            const assignedWrap = document.getElementById('md-assigned-wrap');
            const assignedEl   = document.getElementById('md-assigned-encoder');
            if (order.status !== 'Under Review' && order.assigned_encoder_name) {
                assignedEl.textContent     = order.assigned_encoder_name;
                assignedWrap.style.display = 'block';
            } else {
                assignedWrap.style.display = 'none';
            }

            // Show/hide assign encoder panel
            const assignPanel = document.getElementById('assignEncoderPanel');
            assignPanel.style.display = (order.status === 'Under Review') ? 'block' : 'none';

            // Render items table
            const tbody = document.getElementById('modal-items');
            tbody.innerHTML = items.map(i => {
                const stock     = parseInt(i.stock_qty, 10);
                const threshold = parseInt(i.low_stock_threshold, 10);
                const ordered   = parseInt(i.quantity, 10);
                let stockClass, stockIcon, stockLabel;
                if (stock === 0) {
                    stockClass = 'stock-out'; stockIcon = '✕'; stockLabel = 'Out of Stock';
                } else if (stock < ordered) {
                    stockClass = 'stock-out'; stockIcon = '⚠'; stockLabel = `Only ${stock} left`;
                } else if (stock <= threshold) {
                    stockClass = 'stock-low'; stockIcon = '⚠'; stockLabel = `Low (${stock})`;
                } else {
                    stockClass = 'stock-ok';  stockIcon = '✓'; stockLabel = stock;
                }
                return `
                <tr>
                    <td>${esc(i.product_name)}</td>
                    <td>${esc(i.category)}</td>
                    <td>${i.quantity} ${esc(i.unit)}</td>
                    <td><span class="stock-badge ${stockClass}">${stockIcon} ${stockLabel}</span></td>
                    <td style="text-align:right">₱${fmt(i.unit_price)}</td>
                    <td style="text-align:right; font-weight:600;">₱${fmt(i.subtotal)}</td>
                </tr>`;
            }).join('');

            // ── STOCK CHECK: detect items with insufficient stock ──────────────────
            const banner     = document.getElementById('stock-warning-banner');
            const shortItems = items.filter(i => parseInt(i.stock_qty, 10) < parseInt(i.quantity, 10));
            currentOrderHasStockIssue = (order.status === 'Under Review' && shortItems.length > 0);

            if (currentOrderHasStockIssue) {
                const names = shortItems.map(i => i.product_name).join(', ');
                document.getElementById('stock-warning-text').textContent =
                    shortItems.length === 1
                        ? `Cannot approve — insufficient stock for: ${names}. Adjust inventory or reject this order.`
                        : `Cannot approve — insufficient stock for ${shortItems.length} items: ${names}. Adjust inventory or reject this order.`;
                banner.style.display = 'flex';
            } else {
                banner.style.display = 'none';
            }
            // ─────────────────────────────────────────────────────────────────────

            // Build action buttons based on status
            const actionsDiv = document.getElementById('modal-actions');
            const rejectForm = document.getElementById('rejectForm');

            if (order.status === 'Under Review') {
                // Approve button is disabled when there are stock issues
                const approveDisabled = currentOrderHasStockIssue ? 'disabled title="Cannot approve: one or more items are out of stock."' : '';
                actionsDiv.innerHTML = `
                    <button class="btn-large btn-secondary" onclick="closeModal()">Close</button>
                    <button class="btn-large btn-danger"  id="btn-reject-modal" onclick="toggleRejectForm()">Reject Order</button>
                    <button class="btn-large btn-primary" id="btn-approve-modal" onclick="approveFromModal('${esc(order.po_number)}')" ${approveDisabled}>Approve Order</button>
                `;
                if (rejectMode) {
                    rejectForm.classList.add('visible');
                    document.getElementById('btn-reject-modal').textContent = 'Confirm Rejection';
                    document.getElementById('btn-reject-modal').onclick = () => rejectFromModal(order.po_number);
                }
            } else {
                actionsDiv.innerHTML = `<button class="btn-large btn-secondary" onclick="closeModal()">Close</button>`;
                rejectForm.classList.remove('visible');
            }

            document.getElementById('modal-loading').style.display = 'none';
            document.getElementById('modal-content').style.display = 'block';
            lucide.createIcons();
        }

        function closeModal() {
            document.getElementById('orderModal').classList.remove('open');
            document.getElementById('rejectForm').classList.remove('visible');
            document.getElementById('rejectReason').value = '';
            document.getElementById('encoderList').innerHTML = '<div class="encoder-list-loading">Loading encoders…</div>';
            document.getElementById('md-paid-banner').style.display     = 'none';
            document.getElementById('md-unpaid-banner').style.display   = 'none';
            document.getElementById('md-screenshot-wrap').style.display = 'none';
            currentOrderId            = null;
            rejectMode                = false;
            selectedEncoderId         = null;
            selectedEncoderName       = null;
            currentOrderHasStockIssue = false;
        }

        // ── Fetch encoders with active order count ────────────────
        function fetchEncoders() {
            fetch('admin-orders.php?fetch_encoders=1')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    const list = document.getElementById('encoderList');
                    if (!data.encoders.length) {
                        list.innerHTML = '<div class="encoder-list-loading">No active encoders found.</div>';
                        return;
                    }
                    list.innerHTML = data.encoders.map(e => {
                        const count = parseInt(e.active_orders);
                        let loadClass = 'load-free', loadLabel = 'Available';
                        if (count >= 5)      { loadClass = 'load-heavy'; loadLabel = `Busy (${count} orders)`; }
                        else if (count >= 2) { loadClass = 'load-busy';  loadLabel = `${count} active`; }
                        else if (count > 0)  { loadClass = 'load-busy';  loadLabel = `${count} active`; }
                        return `
                        <label class="encoder-option" id="enc-opt-${e.user_id}" onclick="selectEncoder(${e.user_id}, '${esc(e.full_name)}', this)">
                            <input type="radio" name="encoder_pick" value="${e.user_id}">
                            <span class="encoder-name">${esc(e.full_name)}</span>
                            <span class="encoder-load ${loadClass}">${loadLabel}</span>
                        </label>`;
                    }).join('');
                })
                .catch(() => {
                    document.getElementById('encoderList').innerHTML = '<div class="encoder-list-loading">Failed to load encoders.</div>';
                });
        }

        function selectEncoder(id, name, el) {
            document.querySelectorAll('.encoder-option').forEach(o => o.classList.remove('selected'));
            el.classList.add('selected');
            selectedEncoderId   = id;
            selectedEncoderName = name;
        }

        // ── Toggle reject reason textarea ─────────────────────────
        function toggleRejectForm() {
            const form = document.getElementById('rejectForm');
            const btn  = document.getElementById('btn-reject-modal');
            const isVisible = form.classList.contains('visible');
            form.classList.toggle('visible');
            btn.textContent = isVisible ? 'Reject Order' : 'Confirm Rejection';
            btn.onclick = isVisible
                ? toggleRejectForm
                : () => rejectFromModal(document.getElementById('modal-title').textContent.split('—')[1]?.trim() || '');
        }

        // ── Approve from modal → stock check → encoder check → confirmation ──
        function approveFromModal(poNumber) {
            if (!currentOrderId) return;

            // Block approval if any item has insufficient stock
            if (currentOrderHasStockIssue) {
                showToast('Cannot approve: one or more items are out of stock. Adjust inventory first.', 'error');
                const banner = document.getElementById('stock-warning-banner');
                banner.style.border = '2px solid #991b1b';
                setTimeout(() => banner.style.border = '1px solid #fecaca', 2500);
                return;
            }

            if (!selectedEncoderId) {
                const panel = document.getElementById('assignEncoderPanel');
                panel.style.border = '2px solid var(--accent)';
                setTimeout(() => panel.style.border = '1px solid #dbeafe', 2000);
                showToast('Please select a Data Encoder to assign this order.', 'error');
                return;
            }

            const franchisee = document.getElementById('md-franchisee').textContent;
            const branch     = document.getElementById('md-branch').textContent;
            openConfirm('approve', currentOrderId, poNumber, franchisee, branch, null, selectedEncoderId, selectedEncoderName);
        }

        // ── Reject from modal → validate reason → show confirmation
        function rejectFromModal(poNumber) {
            const reason = document.getElementById('rejectReason').value.trim();
            if (!reason) {
                document.getElementById('rejectReason').style.borderColor = 'var(--accent)';
                document.getElementById('rejectReason').focus();
                return;
            }
            if (!currentOrderId) return;
            const franchisee = document.getElementById('md-franchisee').textContent;
            const branch     = document.getElementById('md-branch').textContent;
            openConfirm('reject', currentOrderId, poNumber, franchisee, branch, reason, null, null);
        }

        // ── Quick approve from table row → open modal (full validation inside) ──
        function quickApprove(orderId, poNumber) {
            openModal(parseInt(orderId, 10));
        }

        // ── Open confirmation dialog ──────────────────────────────
        function openConfirm(action, orderId, poNumber, franchisee, branch, reason, encoderId = null, encoderName = null) {
            confirmAction      = action;
            confirmOrderId     = orderId;
            confirmReason      = reason;
            confirmPoNumber    = poNumber;
            confirmEncoderId   = encoderId;
            confirmEncoderName = encoderName;

            const icon  = document.getElementById('confirm-icon');
            const title = document.getElementById('confirm-title');
            const body  = document.getElementById('confirm-body');
            const poEl  = document.getElementById('confirm-po');
            const btn   = document.getElementById('confirm-proceed-btn');

            if (action === 'approve') {
                icon.className    = 'confirm-icon approve';
                icon.innerHTML    = '<i data-lucide="check-circle"></i>';
                title.textContent = 'Approve this order?';
                body.textContent  = franchisee
                    ? `You are about to approve the order from ${franchisee} (${branch}) and assign it to ${encoderName || 'the selected encoder'}.`
                    : `This order will be approved and assigned to ${encoderName || 'the selected encoder'}.`;
                btn.className   = 'cb-approve';
                btn.textContent = 'Yes, Approve';
            } else {
                icon.className    = 'confirm-icon reject';
                icon.innerHTML    = '<i data-lucide="x-circle"></i>';
                title.textContent = 'Reject this order?';
                body.textContent  = franchisee
                    ? `You are about to reject the order from ${franchisee} (${branch}). The franchisee will be notified with your reason.`
                    : 'This order will be rejected and the franchisee will be notified.';
                btn.className   = 'cb-reject';
                btn.textContent = 'Yes, Reject';
            }

            poEl.textContent = poNumber || '';
            document.getElementById('confirmOverlay').classList.add('open');
            lucide.createIcons();
        }

        function closeConfirm() {
            document.getElementById('confirmOverlay').classList.remove('open');
            confirmAction = confirmOrderId = confirmReason = confirmPoNumber = confirmEncoderId = confirmEncoderName = null;
        }

        // ── Called when admin clicks "Yes, Approve / Yes, Reject" ─
        function proceedConfirmed() {
            if (!confirmAction || !confirmOrderId) return;
            const _id        = parseInt(confirmOrderId, 10);
            const _action    = confirmAction;
            const _reason    = confirmReason;
            const _encoderId = confirmEncoderId;
            closeConfirm();
            sendAction(_id, _action, _reason, _encoderId);
        }

        // ── Core AJAX action sender ───────────────────────────────
        function sendAction(orderId, action, reason, encoderId) {
            const body = new URLSearchParams({ ajax_action: action, order_id: orderId });
            if (reason)    body.append('reason', reason);
            if (encoderId) body.append('encoder_id', encoderId);

            fetch('admin-orders.php', { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeModal();
                        const row = document.getElementById(`row-${orderId}`);
                        if (row) row.remove();
                        updatePendingCount(-1);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(() => showToast('A network error occurred. Please try again.', 'error'));
        }

        // ── Update pending tab counter ────────────────────────────
        function updatePendingCount(delta) {
            const tab   = document.querySelector('.tab[data-target="pending"]');
            const match = tab.textContent.match(/\((\d+)\)/);
            if (match) {
                const newCount = Math.max(0, parseInt(match[1]) + delta);
                tab.textContent = `Pending Review (${newCount})`;
            }
        }

        // ── Utility: format date string ───────────────────────────
        function fmtDate(dtStr) {
            const d = new Date(dtStr.replace(' ', 'T'));
            return d.toLocaleString('en-PH', {
                month: 'short', day: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: true
            });
        }

        // ── Utility: format date only (no time) ──────────────────
        function fmtDateOnly(dtStr) {
            const d = new Date(dtStr.replace(' ', 'T'));
            return d.toLocaleDateString('en-PH', { month: 'short', day: '2-digit', year: 'numeric' });
        }

        // ── Utility: format currency ──────────────────────────────
        function fmt(n) {
            return Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // ── Utility: escape HTML ──────────────────────────────────
        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // ── Toast notification ────────────────────────────────────
        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            document.getElementById('toast-msg').textContent = msg;
            toast.className = `toast show ${type}`;
            setTimeout(() => { toast.classList.remove('show'); }, 4000);
        }

        // ── Screenshot Lightbox ───────────────────────────────────
        function openScreenshotLightbox(path) {
            const overlay = document.getElementById('screenshotLightbox');
            const img     = document.getElementById('lightbox-img');
            img.src       = path;
            overlay.classList.add('open');
        }

        function closeLightbox() {
            const overlay = document.getElementById('screenshotLightbox');
            overlay.classList.remove('open');
            document.getElementById('lightbox-img').src = '';
        }

        document.getElementById('screenshotLightbox').addEventListener('click', function(e) {
            if (e.target === this) closeLightbox();
        });
    </script>

    <!-- ── Screenshot Lightbox Overlay ── -->
    <div class="lightbox-overlay" id="screenshotLightbox">
        <div class="lightbox-inner">
            <div class="lightbox-header">
                <span>🖼 Payment Proof Screenshot</span>
                <button class="lightbox-close" onclick="closeLightbox()" title="Close">✕</button>
            </div>
            <div class="lightbox-img-wrap">
                <img id="lightbox-img" src="" alt="Payment screenshot">
            </div>
            <div class="lightbox-footer">Click outside the image to close</div>
        </div>
    </div>
</body>
</html>