<?php
ob_start();
// ============================================================
// encoder-orders.php — Order Processing & Verification
// DB Tables used:
//   READ  → orders + order_items + products + franchisees
//   WRITE → orders              (update status/status_step)
//   WRITE → order_status_history (log every status change)
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$encoderName = $_SESSION['full_name'] ?? 'Data Encoder';
$encoderId   = $_SESSION['user_id'];

// ── AJAX: Fetch available clerks ─────────────────────────────
if (isset($_GET['fetch_clerks'])) {
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $clerks = [];
    $stmt = $conn->query("SELECT user_id AS id, full_name AS name FROM users WHERE role_id = 3 AND status = 'Active' ORDER BY full_name ASC");
    if ($stmt) { while ($row = $stmt->fetch_assoc()) { $clerks[] = $row; } }
    else {
        $stmt2 = $conn->query("SELECT user_id AS id, full_name AS name FROM users WHERE role_id = 3 ORDER BY full_name ASC");
        if ($stmt2) { while ($row = $stmt2->fetch_assoc()) { $clerks[] = $row; } }
    }
    echo json_encode(['success' => true, 'clerks' => $clerks]);
    $conn->close(); exit();
}

// ── Handle POST: update order status ─────────────────────────
$actionMsg = '';
$actionErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = intval($_POST['order_id'] ?? 0);
    $action  = $_POST['action'] ?? '';

    if ($orderId > 0 && in_array($action, ['confirm','processing','ready','flag','mark_paid'])) {

        // Map action to new step and labels
        // Handle payment confirmation separately
        if ($action === 'mark_paid') {
            $payMethodRaw = $_POST['payment_method'] ?? 'Cash';
            $payRef       = trim($_POST['payment_ref'] ?? '');

            // Map form values to DB enum
            $methodMap = ['Cash' => 'cod', 'GCash' => 'gcash', 'Card' => 'bank'];
            $payMethod = $methodMap[$payMethodRaw] ?? 'cod';

            // Handle screenshot upload
            $screenshotPath = null;
            if (!empty($_FILES['payment_screenshot']['tmp_name']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/payment_screenshots/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $ext     = strtolower(pathinfo($_FILES['payment_screenshot']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp'];
                if (in_array($ext, $allowed) && $_FILES['payment_screenshot']['size'] <= 5 * 1024 * 1024) {
                    $safeName = 'pay_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $uploadDir . $safeName)) {
                        $screenshotPath = 'uploads/payment_screenshots/' . $safeName;
                    }
                }
            }

            if ($screenshotPath) {
                $upd = $conn->prepare("UPDATE orders SET payment_status='paid', payment_method=?, payment_ref=?, payment_proof=? WHERE id=?");
                $upd->bind_param("sssi", $payMethod, $payRef, $screenshotPath, $orderId);
            } else {
                $upd = $conn->prepare("UPDATE orders SET payment_status='paid', payment_method=?, payment_ref=? WHERE id=?");
                $upd->bind_param("ssi", $payMethod, $payRef, $orderId);
            }
            $upd->execute();
            $upd->close();

            $actionMsg = "Payment confirmed as <strong>$payMethodRaw</strong>.";
            header("Location: encoder-orders.php?po=" . urlencode($_POST['po_num'] ?? '') . "&filter=$filterStep");
            exit();
        }

        $stepMap = [
            'confirm'    => [2, 'for_payment', 'Order confirmed by encoder. Awaiting payment verification before forwarding to warehouse.'],
            'processing' => [3, 'processing',  'Payment verified. Order forwarded to Inventory Clerk for fulfillment.'],
            'ready'      => [3, 'processing',  'Order marked as Ready for Fulfillment by encoder. Forwarded to Inventory Clerk.'],
            'flag'       => [1, 'Under Review', ''],  // detail built from flag_note below
        ];

        // Flag requires a note
        $blocked = false;
        if ($action === 'flag') {
            $flagNote = trim($_POST['flag_note'] ?? '');
            if (empty($flagNote)) {
                $actionErr = "Please provide a note explaining the issue before flagging.";
                $blocked   = true;
            } else {
                $stepMap['flag'][2] = 'Flagged by Encoder: ' . $flagNote;
            }
        }

        // Req 11: Block confirm if no payment method on file
        if ($action === 'confirm' && !$blocked) {
            $chk = $conn->prepare("SELECT payment_method FROM orders WHERE id = ?");
            $chk->bind_param("i", $orderId);
            $chk->execute();
            $chkRow = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (empty($chkRow['payment_method'])) {
                $actionErr = "Cannot confirm — the franchisee's payment method has not been recorded.";
                $blocked = true;
            }
        }

        if (!$blocked) {
            [$newStep, $newStatus, $histDetail] = $stepMap[$action];

            if ($action === 'flag') {
                // Keep assigned_encoder_id so order stays in encoder's queue
                $upd = $conn->prepare("UPDATE orders SET status_step = ?, status = ?, approved_by = NULL, approved_at = NULL WHERE id = ?");
                $upd->bind_param("isi", $newStep, $newStatus, $orderId);
            } elseif ($action === 'ready' || $action === 'processing') {
                // Save assigned clerk if provided
                $clerkId_assigned = intval($_POST['clerk_id'] ?? 0);
                if ($clerkId_assigned) {
                    $cChk = $conn->prepare("SELECT full_name FROM users WHERE user_id = ? AND role_id = 3");
                    $cChk->bind_param("i", $clerkId_assigned);
                    $cChk->execute();
                    $cRow = $cChk->get_result()->fetch_assoc();
                    $cChk->close();
                    if ($cRow) { $histDetail .= ' Assigned Clerk: ' . $cRow['full_name'] . '.'; }
                    $conn->query("UPDATE orders SET assigned_clerk_id = $clerkId_assigned WHERE id = $orderId");
                }
                $upd = $conn->prepare("UPDATE orders SET status_step = ?, status = ? WHERE id = ?");
                $upd->bind_param("isi", $newStep, $newStatus, $orderId);
            } else {
                $upd = $conn->prepare("UPDATE orders SET status_step = ?, status = ? WHERE id = ?");
                $upd->bind_param("isi", $newStep, $newStatus, $orderId);
            }
            $upd->execute();
            $upd->close();

            $statusLabels = [
                'for_payment' => 'For Payment',
                'processing'  => 'Processing',
                'Under Review'=> 'Under Review',
                'completed'   => 'Completed',
            ];
            $histLabel = $statusLabels[$newStatus] ?? $newStatus;

            // Log to order_status_history
            $ins = $conn->prepare("INSERT INTO order_status_history (order_id, status_step, status_label, detail, changed_at, changed_by) VALUES (?,?,?,?,NOW(),?)");
            $ins->bind_param("iissi", $orderId, $newStep, $histLabel, $histDetail, $encoderId);
            $ins->execute();
            $ins->close();

            $actionMsg = "Order updated to <strong>$newStatus</strong> successfully.";
        }
    }
}

// ── Fetch all orders for the queue ───────────────────────────
// Filter by step 1 (Under Review) and 2 (Processing) — encoder's responsibility
$filterStep = $_GET['filter'] ?? 'active';
$searchTerm = $_GET['search'] ?? '';
$selectedPO = $_GET['po']     ?? '';

$whereClause = "WHERE o.assigned_encoder_id = ?";
$params = [$encoderId];
$types  = 'i';

if ($filterStep === 'active') {
    $whereClause .= " AND o.status IN ('Approved','for_payment','processing','Under Review')";
} elseif ($filterStep === 'pending') {
    $whereClause .= " AND o.status_step = 1";
} elseif ($filterStep === 'today') {
    $whereClause .= " AND o.status_step >= 2 AND DATE(o.created_at) = CURDATE()";
} elseif ($filterStep === 'ready') {
    $whereClause .= " AND o.status = 'completed'";
} elseif ($filterStep === 'completed') {
    $whereClause .= " AND o.status = 'completed'";
}
// 'all' — no extra status filter

if ($searchTerm) {
    $whereClause .= " AND (o.po_number LIKE ? OR f.branch_name LIKE ?)";
    $like     = '%' . $searchTerm . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$sql = "
    SELECT o.id, o.po_number, o.status, o.status_step, o.created_at,
           o.total_amount, o.delivery_preference, o.estimated_pickup,
           COALESCE(f.franchisee_name, uf.full_name, '—') AS franchisee_name,
           f.branch_name
    FROM orders o
    LEFT JOIN franchisees f  ON f.id       = o.franchisee_id
    LEFT JOIN users       uf ON uf.user_id = f.user_id
    $whereClause
    ORDER BY o.created_at DESC
    LIMIT 50
";

$orders = [];
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $orders[] = $row; }
$stmt->close();

// ── Fetch selected order details ─────────────────────────────
$selectedOrder = null;
$selectedItems = [];
$orderHistory  = [];

// Auto-select first order or from URL ?po=
if (!$selectedPO && !empty($orders)) {
    $selectedPO = $orders[0]['po_number'];
}

if ($selectedPO) {
    $stmt = $conn->prepare("
        SELECT o.*,
               COALESCE(f.franchisee_name, uf.full_name, '—') AS franchisee_name,
               f.branch_name,
               adm.full_name AS approver_name
        FROM orders o
        LEFT JOIN franchisees f  ON f.id       = o.franchisee_id
        LEFT JOIN users       uf ON uf.user_id = f.user_id
        LEFT JOIN users       adm ON adm.user_id = o.approved_by
        WHERE o.po_number = ? AND o.assigned_encoder_id = ?
    ");
    $stmt->bind_param("si", $selectedPO, $encoderId);
    $stmt->execute();
    $selectedOrder = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($selectedOrder) {
        // Fetch ordered items
        $stmt = $conn->prepare("
            SELECT oi.quantity, oi.unit_price, oi.subtotal,
                   p.name, p.unit, p.stock_qty
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
        ");
        $stmt->bind_param("i", $selectedOrder['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $selectedItems[] = $row; }
        $stmt->close();

        // Fetch order status history with who approved/changed each step
        $stmt = $conn->prepare("
            SELECT h.status_step, h.status_label, h.detail, h.changed_at,
                   u.full_name as changed_by_name, u.role_id,
                   r.role_name
            FROM order_status_history h
            LEFT JOIN users u ON u.user_id = h.changed_by
            LEFT JOIN roles r ON r.role_id = u.role_id
            WHERE h.order_id = ?
            ORDER BY h.changed_at ASC
        ");
        $stmt->bind_param("i", $selectedOrder['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $orderHistory[] = $row; }
        $stmt->close();
    }
}

$conn->close();

function stepLabel($s) {
    $m = [0=>'Submitted',1=>'Under Review',2=>'Processing',3=>'Ready',4=>'Completed'];
    return $m[$s] ?? 'Unknown';
}
function stepClass($s) {
    $m = [0=>'s-submitted',1=>'s-review',2=>'s-processing',3=>'s-ready',4=>'s-completed'];
    return $m[$s] ?? 's-review';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Processing - Top Juan Inc.</title>
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
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;max-width:calc(100vw - var(--sidebar-width));}
        .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:2.5rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);font-size:1rem;}
        .order-container{display:grid;grid-template-columns:1fr 420px;gap:2rem;align-items:start;}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:2rem;margin-bottom:1.5rem;}
        .card:last-child{margin-bottom:0;}
        .card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;}
        .card-header h3{font-family:'Fraunces',serif;font-size:1.25rem;}
        .controls-row{display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;}
        .search-box{flex:1;position:relative;min-width:200px;}
        .search-box i{position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--muted);width:18px;height:18px;}
        .search-box input{width:100%;padding:.75rem 1rem .75rem 2.75rem;border-radius:12px;border:1px solid var(--card-border);font-family:inherit;font-size:.9rem;outline:none;}
        .search-box input:focus{border-color:var(--primary);}
        .filter-select{padding:.75rem 1rem;border-radius:12px;border:1px solid var(--card-border);font-family:inherit;font-size:.9rem;background:white;outline:none;cursor:pointer;}
        .table-container{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:1rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid var(--card-border);white-space:nowrap;}
        td{padding:1.25rem 1rem;font-size:.9rem;border-bottom:1px solid var(--card-border);}
        tr:last-child td{border-bottom:none;}
        tr.order-row{cursor:pointer;transition:background .15s;}
        tr.order-row:hover td{background:rgba(92,64,51,.03);}
        tr.order-row.selected td{background:rgba(92,64,51,.05);}
        .status-pill{padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-block;}
        .s-submitted{background:rgba(59,130,246,.1);color:#3b82f6;}
        .s-review{background:#fffbeb;color:#b45309;}
        .s-processing{background:rgba(210,84,36,.1);color:var(--accent);}
        .s-ready{background:#f0fdf4;color:#166534;}
        .s-completed{background:#f3f4f6;color:#4b5563;}
        .verification-panel{position:sticky;top:2rem;}
        .detail-row{display:flex;justify-content:space-between;margin-bottom:.875rem;font-size:.9rem;}
        .detail-label{color:var(--muted);}
        .detail-value{font-weight:600;text-align:right;}
        .items-list{margin:1.5rem 0;max-height:280px;overflow-y:auto;}
        .item-row-v{padding:.75rem 0;border-bottom:1px solid var(--card-border);display:flex;justify-content:space-between;}
        .item-row-v:last-child{border-bottom:none;}
        .item-info h5{font-weight:600;font-size:.875rem;margin-bottom:.15rem;}
        .item-info span{font-size:.75rem;color:var(--muted);}
        .item-stock{font-weight:700;color:#10b981;font-size:.85rem;white-space:nowrap;}
        .item-stock.low{color:#ef4444;}
        .action-group{display:grid;grid-template-columns:1fr;gap:.75rem;margin-top:1.5rem;}
        .btn{padding:.875rem 1.5rem;border-radius:12px;font-weight:600;font-size:.9rem;cursor:pointer;transition:all .2s;border:none;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;font-family:inherit;}
        .btn-primary{background:var(--primary);color:white;}
        .btn-primary:hover{background:var(--primary-light);}
        .btn-accent{background:var(--accent);color:white;}
        .btn-accent:hover{opacity:.9;}
        .btn-success{background:#10b981;color:white;}
        .btn-success:hover{opacity:.9;}
        .btn-outline{background:transparent;border:1px solid var(--card-border);color:var(--muted);}
        .btn-outline:hover{background:rgba(0,0,0,.02);color:var(--primary);}
        .btn-danger{background:rgba(239,68,68,.1);color:#ef4444;}
        .btn-danger:hover{background:rgba(239,68,68,.2);}
        .btn-2col{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;}
        /* Approval info banner */
        .approval-banner{background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;padding:1rem 1.25rem;margin-bottom:1.25rem;}
        .approval-banner .ab-label{font-size:.7rem;text-transform:uppercase;color:#1d4ed8;font-weight:700;letter-spacing:.04em;margin-bottom:.2rem;}
        .approval-banner .ab-value{font-size:.88rem;font-weight:600;color:var(--foreground);}
        .approval-banner .ab-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1.5rem;}
        /* Payment proof box */
        .proof-box{background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:.875rem 1rem;margin-top:1rem;}
        .proof-box .pb-label{font-size:.7rem;text-transform:uppercase;color:#166534;font-weight:700;letter-spacing:.04em;margin-bottom:.5rem;}
        .proof-box .pb-ref{font-size:.88rem;font-weight:600;margin-bottom:.5rem;font-family:monospace;color:#166534;}
        .proof-img{width:100%;border-radius:8px;border:1px solid #86efac;max-height:180px;object-fit:cover;cursor:pointer;transition:opacity .15s;}
        .proof-img:hover{opacity:.88;}
        /* Screenshot lightbox */
        .lb-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);display:none;align-items:center;justify-content:center;z-index:500;padding:1.5rem;backdrop-filter:blur(6px);}
        .lb-overlay.open{display:flex;}
        .lb-inner{background:white;border-radius:20px;overflow:hidden;max-width:680px;width:100%;box-shadow:0 32px 80px rgba(0,0,0,.4);}
        .lb-header{display:flex;justify-content:space-between;align-items:center;padding:.875rem 1.25rem;background:#fafafa;border-bottom:1px solid var(--card-border);}
        .lb-header span{font-weight:700;font-size:.9rem;}
        .lb-close{background:none;border:none;cursor:pointer;font-size:1.4rem;color:var(--muted);padding:.25rem .5rem;border-radius:6px;}
        .lb-close:hover{background:#f3f4f6;}
        .lb-img-wrap{background:#1a1a1a;display:flex;align-items:center;justify-content:center;max-height:70vh;overflow:hidden;}
        .lb-img-wrap img{max-width:100%;max-height:70vh;object-fit:contain;display:block;}
        .lb-footer{padding:.75rem 1.25rem;background:#fafafa;border-top:1px solid var(--card-border);font-size:.8rem;color:var(--muted);text-align:center;}
        /* Encoder confirm modal */
        #encReviewModal{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(5px);display:none;align-items:center;justify-content:center;z-index:200;padding:1rem;}
        #encReviewModal.open{display:flex;}
        .er-box{background:white;border-radius:24px;max-width:500px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 25px 50px -12px rgba(0,0,0,.2);}
        .er-head{padding:1.5rem 2rem 1rem;border-bottom:1px solid var(--card-border);display:flex;justify-content:space-between;align-items:center;}
        .er-head h3{font-family:'Fraunces',serif;font-size:1.35rem;}
        .er-body{padding:1.5rem 2rem;}
        .er-warn{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.875rem 1rem;font-size:.85rem;color:#92400e;margin-bottom:1.25rem;display:flex;gap:.5rem;}
        .er-row{display:flex;justify-content:space-between;padding:.45rem 0;border-bottom:1px dashed var(--card-border);font-size:.9rem;}
        .er-row:last-child{border-bottom:none;}
        .er-items-table{width:100%;border-collapse:collapse;font-size:.82rem;margin-top:.25rem;}
        .er-items-table thead tr{background:var(--background);}
        .er-items-table th{padding:.4rem .6rem;text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:700;border-bottom:1px solid var(--card-border);}
        .er-items-table th:last-child{text-align:right;}
        .er-items-table td{padding:.45rem .6rem;border-bottom:1px dashed var(--card-border);vertical-align:top;}
        .er-items-table td:last-child{text-align:right;font-weight:600;}
        .er-items-table tr:last-child td{border-bottom:none;}
        .er-stock-ok{font-size:.72rem;color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;padding:1px 6px;white-space:nowrap;}
        .er-stock-low{font-size:.72rem;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:4px;padding:1px 6px;white-space:nowrap;}
        .er-stock-out{font-size:.72rem;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:1px 6px;white-space:nowrap;}
        .er-foot{padding:1rem 2rem 1.5rem;display:flex;gap:.75rem;}
        .er-foot button{flex:1;padding:.875rem;border-radius:12px;font-weight:700;font-family:inherit;font-size:.92rem;cursor:pointer;border:none;}
        .er-back{background:#f3f4f6;color:var(--foreground);}
        .er-confirm{background:var(--primary);color:white;}
        .er-confirm:hover{background:var(--primary-light);}
        .er-confirm:disabled{opacity:.5;cursor:not-allowed;}
        /* Clerk assignment panel */
        .assign-clerk-panel{margin-bottom:1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:1rem 1.25rem;}
        .assign-clerk-panel .acp-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#166534;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
        .clerk-list{display:flex;flex-direction:column;gap:.4rem;max-height:150px;overflow-y:auto;}
        .clerk-option{display:flex;align-items:center;justify-content:space-between;padding:.5rem .875rem;border-radius:8px;border:1.5px solid #d1fae5;background:white;cursor:pointer;transition:all .15s;}
        .clerk-option:hover{border-color:#16a34a;background:#f0fdf4;}
        .clerk-option.selected{border-color:#16a34a;background:#dcfce7;}
        .clerk-option input[type="radio"]{display:none;}
        .clerk-name{font-size:.875rem;font-weight:600;color:var(--foreground);}
        .clerk-list-loading{font-size:.85rem;color:var(--muted);text-align:center;padding:.5rem;}
        /* 2nd authenticator dialog */
        #enc2ndConfirm{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:300;padding:1rem;}
        #enc2ndConfirm.open{display:flex;}
        .enc2-box{background:white;border-radius:20px;padding:2.25rem 2.5rem;max-width:400px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:popIn .18s ease;}
        @keyframes popIn{from{transform:scale(.92);opacity:0;}to{transform:scale(1);opacity:1;}}
        /* Flag modal */
        #flagModal{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:300;padding:1rem;}
        #flagModal.open{display:flex;}
        .flag-box{background:white;border-radius:20px;padding:2rem 2.5rem;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:popIn .18s ease;}
        .flag-box h4{font-family:'Fraunces',serif;font-size:1.15rem;margin-bottom:.3rem;color:#991b1b;}
        .flag-box .flag-sub{font-size:.85rem;color:var(--muted);margin-bottom:1.25rem;}
        .flag-po{font-weight:700;color:var(--primary);}
        .flag-box label{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);display:block;margin-bottom:.4rem;}
        .flag-box textarea{width:100%;border:1.5px solid var(--card-border);border-radius:10px;padding:.75rem 1rem;font-family:inherit;font-size:.9rem;resize:vertical;min-height:100px;outline:none;transition:border-color .15s;}
        .flag-box textarea:focus{border-color:#ef4444;}
        .flag-char{font-size:.72rem;color:var(--muted);text-align:right;margin-top:.25rem;margin-bottom:1rem;}
        .flag-btns{display:flex;gap:.75rem;}
        .flag-btns button{flex:1;padding:.8rem;border-radius:12px;font-weight:700;font-size:.9rem;cursor:pointer;border:none;font-family:inherit;}
        .flag-cancel{background:#f3f4f6;color:#4b5563;}
        .flag-submit{background:#ef4444;color:white;}
        .flag-submit:disabled{opacity:.45;cursor:not-allowed;}
        .enc2-icon{width:56px;height:56px;background:#fef9c3;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;font-size:1.5rem;}
        .enc2-box h4{font-family:'Fraunces',serif;font-size:1.15rem;margin-bottom:.5rem;}
        .enc2-box p{font-size:.875rem;color:var(--muted);line-height:1.55;margin-bottom:.35rem;}
        .enc2-po{font-weight:700;color:var(--primary);font-size:.95rem;display:block;margin-bottom:1.5rem;}
        .enc2-warn{font-size:.82rem;color:#991b1b;font-weight:600;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.55rem .875rem;margin-bottom:1.5rem;}
        .enc2-btns{display:flex;gap:.75rem;}
        .enc2-btns button{flex:1;padding:.8rem;border-radius:12px;font-weight:700;font-size:.9rem;cursor:pointer;border:none;transition:filter .2s;font-family:inherit;}
        .enc2-btns button:hover{filter:brightness(.93);}
        .enc2-cancel{background:#f3f4f6;color:#4b5563;}
        .enc2-go{background:var(--primary);color:white;}
        .pay-form{margin-top:1rem;padding:1rem;background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;}
        .pay-form label{display:block;font-size:.78rem;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.4rem;}
        .pay-form select,.pay-form input[type="text"],.pay-form input[type="number"]{width:100%;padding:.65rem .9rem;border:1.5px solid #86efac;border-radius:8px;font-family:inherit;font-size:.9rem;margin-bottom:.75rem;outline:none;background:white;}
        .enc-screenshot-wrap{display:none;margin-bottom:.75rem;}
        .enc-upload-box{border:2px dashed #86efac;border-radius:10px;padding:1rem;text-align:center;cursor:pointer;background:white;transition:border-color .2s;}
        .enc-upload-box:hover{border-color:#166534;}
        .enc-upload-box input[type="file"]{display:none;}
        .enc-upload-box label{cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:.35rem;}
        .enc-upload-box .eu-icon{font-size:1.4rem;}
        .enc-upload-box .eu-text{font-size:.82rem;font-weight:600;color:#166534;}
        .enc-upload-box .eu-sub{font-size:.72rem;color:var(--muted);}
        .enc-preview{margin-top:.5rem;display:none;}
        .enc-preview img{width:100%;border-radius:8px;border:1px solid #86efac;max-height:160px;object-fit:cover;}
        .enc-existing-proof{margin-bottom:.75rem;padding:.75rem;background:white;border:1px solid #86efac;border-radius:8px;}
        .btn-pay{background:#166534;color:white;border:none;width:100%;padding:.875rem;border-radius:10px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.9rem;display:flex;align-items:center;justify-content:center;gap:.5rem;}
        .pay-done{background:#dcfce7;border:1.5px solid #86efac;border-radius:12px;padding:1rem;text-align:center;color:#166534;margin-top:1rem;}
        .alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:.875rem 1rem;border-radius:10px;margin-bottom:1.5rem;font-size:.9rem;}
        .alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.875rem 1rem;border-radius:10px;margin-bottom:1.5rem;font-size:.9rem;}
        .empty-state{text-align:center;padding:3rem;color:var(--muted);}
        .empty-state h4{color:var(--foreground);margin:.75rem 0 .5rem;}
        .no-select{text-align:center;padding:3rem 2rem;color:var(--muted);}
        .no-select i{opacity:.2;display:block;margin:0 auto .75rem;}
        /* Order History Timeline */
        .hist-card{margin-top:1.5rem;}
        /* Verification panel tabs */
        .vp-tabs{display:flex;align-items:center;justify-content:center;gap:.375rem;padding:.875rem 1rem .65rem;border-bottom:1.5px solid var(--card-border);margin:-2rem -2rem 0;background:var(--background);border-radius:20px 20px 0 0;}
        .vp-header{margin:-2rem -2rem 1.5rem;background:var(--background);border-radius:20px 20px 0 0;border-bottom:1.5px solid var(--card-border);}
        .vp-header-top{display:flex;justify-content:space-between;align-items:center;padding:.75rem 1.25rem .5rem;}
        .vp-po-pill{font-size:.75rem;font-weight:700;color:var(--primary);background:rgba(92,64,51,.07);border:1px solid rgba(92,64,51,.15);border-radius:99px;padding:.2rem .75rem;letter-spacing:.02em;}
        .vp-tabs{display:flex;align-items:center;justify-content:center;gap:.375rem;padding:.25rem 1rem .65rem;border-bottom:none;margin:0;background:transparent;border-radius:0;}
        .vp-tab{display:flex;align-items:center;gap:.4rem;padding:.45rem 1rem;font-size:.82rem;font-weight:600;color:var(--muted);cursor:pointer;border-radius:99px;border:1.5px solid transparent;transition:all .18s;user-select:none;white-space:nowrap;}
        .vp-tab i{width:13px;height:13px;stroke-width:2px;}
        .vp-tab .tab-badge{background:var(--muted);color:white;border-radius:99px;padding:.05rem .45rem;font-size:.68rem;font-weight:700;line-height:1.4;transition:background .18s;}
        .vp-tab:hover{color:var(--primary);background:rgba(92,64,51,.06);}
        .vp-tab.active{color:var(--primary);background:white;border-color:var(--card-border);box-shadow:0 1px 5px rgba(0,0,0,.08);}
        .vp-tab.active .tab-badge{background:var(--accent);}
        .vp-pane{display:none;}
        .vp-pane.active{display:block;}
        .hist-title{font-family:'Fraunces',serif;font-size:1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;}
        .hist-item{display:flex;gap:.875rem;padding-bottom:1.25rem;position:relative;}
        .hist-item:not(:last-child)::before{content:'';position:absolute;left:11px;top:24px;bottom:0;width:2px;background:#eeeae6;z-index:0;}
        .hist-dot{width:24px;height:24px;border-radius:50%;flex-shrink:0;z-index:1;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:white;}
        .hist-dot.dot-submitted{background:#94a3b8;}
        .hist-dot.dot-review{background:#f59e0b;}
        .hist-dot.dot-processing{background:var(--accent);}
        .hist-dot.dot-ready{background:#3b82f6;}
        .hist-dot.dot-completed{background:#10b981;}
        .hist-dot.dot-system{background:#cbd5e1;}
        .hist-content{flex:1;}
        .hist-content h5{font-size:.9rem;font-weight:700;margin-bottom:.2rem;}
        .hist-content .hist-detail{font-size:.82rem;color:var(--muted);margin-bottom:.25rem;line-height:1.5;}
        .hist-content .hist-who{font-size:.78rem;display:flex;align-items:center;gap:.35rem;}
        .hist-content .hist-who .who-badge{background:#f1f5f9;color:#475569;padding:.15rem .5rem;border-radius:6px;font-weight:600;}
        .hist-content .hist-time{font-size:.75rem;color:var(--muted);opacity:.75;display:block;margin-top:.2rem;}
        .hist-empty{font-size:.85rem;color:var(--muted);font-style:italic;padding:.5rem 0;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="coffee"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Data Encoder</span></div></div>
    <p class="menu-label">Main Menu</p>
    <nav>
        <a href="encoder-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i>Dashboard</a>
        <a href="encoder-orders.php" class="nav-item active"><i data-lucide="shopping-bag"></i>Order Process</a>
        <a href="encoder-returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i>Return and Refund</a>
        <a href="encoder-reports.php" class="nav-item"><i data-lucide="file-text"></i>Reports</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($encoderName); ?></h4><p>Data Encoder</p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i><span>Sign Out</span></a>
</aside>

<main>
    <div class="header">
        <div><h2>Order Processing & Verification</h2><p>Verify franchisee submissions and cross-reference inventory availability.</p></div>
    </div>

    <?php if ($actionMsg): ?>
    <div class="alert-success"><?php echo $actionMsg; ?></div>
    <?php endif; ?>
    <?php if ($actionErr): ?>
    <div class="alert-error"><strong>⚠ </strong><?php echo htmlspecialchars($actionErr); ?></div>
    <?php endif; ?>

    <div class="order-container">
        <!-- Left: Order Queue -->
        <div>
            <div class="card">
                <div class="card-header"><h3>Order Queue</h3>
                    <span style="font-size:.85rem;color:var(--muted);"><?php echo count($orders); ?> order<?php echo count($orders) != 1 ? 's' : ''; ?></span>
                </div>

                <!-- Search & Filter -->
                <form method="GET" action="encoder-orders.php">
                    <div class="controls-row">
                        <div class="search-box">
                            <i data-lucide="search"></i>
                            <input type="text" name="search" placeholder="Search PO or franchisee..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                        </div>
                        <select class="filter-select" name="filter" onchange="this.form.submit()">
                            <option value="active"    <?php echo $filterStep==='active'    ?'selected':''; ?>>Active (Review + Processing)</option>
                            <option value="ready"     <?php echo $filterStep==='ready'     ?'selected':''; ?>>Ready for Pickup</option>
                            <option value="completed" <?php echo $filterStep==='completed' ?'selected':''; ?>>Completed</option>
                            <option value="all"       <?php echo $filterStep==='all'       ?'selected':''; ?>>All Orders</option>
                        </select>
                        <?php if ($searchTerm): ?>
                            <a href="encoder-orders.php?filter=<?php echo $filterStep; ?>" style="padding:.75rem;color:var(--muted);text-decoration:none;display:flex;align-items:center;" title="Clear search"><i data-lucide="x" size="18"></i></a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="table-container">
                    <?php if (empty($orders)): ?>
                    <div class="empty-state">
                        <i data-lucide="inbox" size="40" style="opacity:.2;display:block;margin:0 auto .75rem;"></i>
                        <h4>No orders found</h4>
                        <p>Try changing the filter or search term.</p>
                    </div>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>P.O. Number</th><th>Franchisee / Branch</th><th>Date</th><th>Delivery</th><th>Total</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o): ?>
                            <tr class="order-row <?php echo $o['po_number'] === $selectedPO ? 'selected' : ''; ?>"
                                onclick="window.location.href='encoder-orders.php?po=<?php echo urlencode($o['po_number']); ?>&filter=<?php echo $filterStep; ?>&search=<?php echo urlencode($searchTerm); ?>'">
                                <td style="font-weight:700;"><?php echo htmlspecialchars($o['po_number']); ?></td>
                                <td>
                                    <div style="font-weight:600;font-size:.875rem;"><?php echo htmlspecialchars($o['franchisee_name'] ?? '—'); ?></div>
                                    <div style="font-size:.75rem;color:var(--muted)"><?php echo htmlspecialchars($o['branch_name'] ?? '—'); ?></div>
                                </td>
                                <td style="font-size:.85rem;color:var(--muted);"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                                <td style="font-size:.85rem;"><?php echo htmlspecialchars($o['delivery_preference']); ?></td>
                                <td style="font-weight:700;">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                                <td><span class="status-pill <?php echo stepClass($o['status_step']); ?>"><?php echo stepLabel($o['status_step']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Tabbed Verification Panel -->
        <div class="verification-panel">
            <?php if ($selectedOrder): ?>
            <div class="card">
                <!-- Tab headers -->
                <div class="vp-header">
                    <div class="vp-header-top">
                        <span style="font-size:.8rem;font-weight:600;color:var(--muted);">Verification Details</span>
                        <span class="vp-po-pill"><?php echo htmlspecialchars($selectedOrder['po_number']); ?></span>
                    </div>
                    <div class="vp-tabs">
                        <div class="vp-tab active" onclick="switchVpTab('details', this)">
                            <i data-lucide="clipboard-check"></i>
                            Verification
                        </div>
                        <div class="vp-tab" onclick="switchVpTab('history', this)">
                            <i data-lucide="clock"></i>
                            History
                            <?php if (!empty($orderHistory)): ?>
                            <span class="tab-badge"><?php echo count($orderHistory); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ── Verification Details Pane ── -->
                <div class="vp-pane active" id="vp-details">

                <!-- ── Approval Info Banner ── -->
                <div class="approval-banner">
                    <div class="ab-grid">
                        <div>
                            <div class="ab-label">Approved By</div>
                            <div class="ab-value"><?php echo htmlspecialchars($selectedOrder['approver_name'] ?? '—'); ?></div>
                        </div>
                        <div>
                            <div class="ab-label">Payment Method</div>
                            <div class="ab-value"><?php
                                $pmLabels = ['cod' => 'Cash', 'gcash' => 'GCash', 'bank' => 'Card/Bank'];
                                echo htmlspecialchars($pmLabels[$selectedOrder['payment_method'] ?? ''] ?? ($selectedOrder['payment_method'] ?? '—'));
                            ?></div>
                        </div>
                        <div>
                            <div class="ab-label">Payment Status</div>
                            <div class="ab-value" style="color:<?php echo strtolower($selectedOrder['payment_status'] ?? '') === 'paid' ? '#166534' : '#ff0000'; ?>">
                                <?php echo strtolower($selectedOrder['payment_status'] ?? '') === 'paid' ? '✓ Paid' : '! Unpaid'; ?>
                            </div>
                        </div>
                        <div>
                            <div class="ab-label">Order Status</div>
                            <div class="ab-value"><?php echo htmlspecialchars($selectedOrder['status']); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($selectedOrder['payment_ref']) || !empty($selectedOrder['payment_proof'])): ?>
                    <div class="proof-box" style="margin-top:.875rem;">
                        <div class="pb-label">📎 Payment Proof from Franchisee</div>
                        <?php if (!empty($selectedOrder['payment_ref'])): ?>
                        <div class="pb-ref">Ref #: <?php echo htmlspecialchars($selectedOrder['payment_ref']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($selectedOrder['payment_proof'])): ?>
                        <img src="<?php echo htmlspecialchars($selectedOrder['payment_proof']); ?>"
                             class="proof-img"
                             alt="Payment screenshot"
                             onclick="openLightbox('<?php echo htmlspecialchars($selectedOrder['payment_proof']); ?>')">
                        <p style="font-size:.72rem;color:#166534;margin-top:.35rem;">Click image to enlarge</p>
                        <?php else: ?>
                        <p style="font-size:.8rem;color:var(--muted);font-style:italic;">No screenshot uploaded.</p>
                        <?php endif; ?>
                    </div>
                    <?php elseif (in_array($selectedOrder['payment_method'], ['cod', 'Cash'])): ?>
                    <div style="margin-top:.75rem;font-size:.82rem;color:#b45309;background:#fffbeb;border-radius:8px;padding:.5rem .75rem;">
                        Cash payment — to be collected on delivery/pickup.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Order details -->
                <div class="detail-row"><span class="detail-label">Franchisee:</span><span class="detail-value"><?php echo htmlspecialchars($selectedOrder['franchisee_name'] ?? '—'); ?></span></div>
                <div class="detail-row"><span class="detail-label">Branch:</span><span class="detail-value"><?php echo htmlspecialchars($selectedOrder['branch_name'] ?? '—'); ?></span></div>
                <div class="detail-row"><span class="detail-label">Order Date:</span><span class="detail-value"><?php echo date('M d, Y', strtotime($selectedOrder['created_at'])); ?></span></div>
                <div class="detail-row"><span class="detail-label">Delivery Method:</span><span class="detail-value"><?php echo htmlspecialchars($selectedOrder['delivery_preference']); ?></span></div>
                <div class="detail-row"><span class="detail-label">Est. Pickup/Delivery:</span><span class="detail-value"><?php echo $selectedOrder['estimated_pickup'] ? date('M d, Y', strtotime($selectedOrder['estimated_pickup'])) : '—'; ?></span></div>
                <div class="detail-row"><span class="detail-label">Total Amount:</span><span class="detail-value" style="color:var(--primary);">₱<?php echo number_format($selectedOrder['total_amount'], 2); ?></span></div>

                <!-- Ordered items with stock check -->
                <div style="border-top:1px solid var(--card-border);margin:1.5rem 0;padding-top:1.5rem;">
                    <h4 style="font-size:.9rem;margin-bottom:1rem;">Ordered Items (<?php echo count($selectedItems); ?>)</h4>
                    <div class="items-list">
                        <?php foreach ($selectedItems as $item):
                            $isLow = $item['stock_qty'] < ($item['quantity'] * 2);
                        ?>
                        <div class="item-row-v">
                            <div class="item-info">
                                <h5><?php echo htmlspecialchars($item['name']); ?> (<?php echo htmlspecialchars($item['unit']); ?>)</h5>
                                <span>Qty: <?php echo $item['quantity']; ?> × ₱<?php echo number_format($item['unit_price'], 2); ?> = ₱<?php echo number_format($item['subtotal'], 2); ?></span>
                            </div>
                            <div class="item-stock <?php echo $isLow ? 'low' : ''; ?>">
                                <?php echo $item['stock_qty'] <= 0 ? 'Out of Stock' : ($isLow ? 'Low ('.$item['stock_qty'].')' : 'In Stock ('.$item['stock_qty'].')'); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Action buttons -->
                <?php if ($selectedOrder['status_step'] < 4): ?>
                <form method="POST" action="encoder-orders.php?po=<?php echo urlencode($selectedOrder['po_number']); ?>&filter=<?php echo $filterStep; ?>">
                    <input type="hidden" name="order_id" value="<?php echo $selectedOrder['id']; ?>">
                    <input type="hidden" name="clerk_id" id="selectedClerkInput" value="">
                    <div class="action-group"
                         data-po="<?php echo htmlspecialchars($selectedOrder['po_number']); ?>"
                         data-franchisee="<?php echo htmlspecialchars($selectedOrder['franchisee_name'] ?? '—'); ?>"
                         data-branch="<?php echo htmlspecialchars($selectedOrder['branch_name'] ?? '—'); ?>"
                         data-delivery="<?php echo htmlspecialchars($selectedOrder['delivery_preference']); ?>"
                         data-total="₱<?php echo number_format($selectedOrder['total_amount'], 2); ?>"
                         data-approver="<?php echo htmlspecialchars($selectedOrder['approver_name'] ?? '—'); ?>"
                         data-items="<?php echo count($selectedItems); ?>"
                         data-items-json="<?php echo htmlspecialchars(json_encode(array_map(function($i) {
                             return [
                                 'name'      => $i['name'],
                                 'unit'      => $i['unit'],
                                 'quantity'  => $i['quantity'],
                                 'unit_price'=> $i['unit_price'],
                                 'subtotal'  => $i['subtotal'],
                                 'stock_qty' => $i['stock_qty'],
                             ];
                         }, $selectedItems)), ENT_QUOTES); ?>">
                        <?php
                        $orderStatus = $selectedOrder['status'];
                        ?>
                        <?php if ($orderStatus === 'Under Review'): ?>
                        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.75rem 1rem;font-size:.85rem;color:#92400e;margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem;">
                            <i data-lucide="refresh-cw" size="15"></i>
                            <span>Franchisee has resubmitted updated payment details. Please review and confirm.</span>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="openEncReview('confirm')"><i data-lucide="eye"></i> Review Updated Payment</button>
                        <?php endif; ?>
                        <?php if ($orderStatus === 'Approved'): ?>
                        <button type="button" class="btn btn-primary" onclick="openEncReview('confirm')"><i data-lucide="eye"></i> Review & Confirm Order</button>
                        <?php endif; ?>
                        <?php if ($orderStatus === 'for_payment'): ?>
                        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:.75rem 1rem;font-size:.85rem;color:#1d4ed8;margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem;">
                            <i data-lucide="credit-card" size="15"></i>
                            <span>Payment is being verified. Once confirmed, forward to warehouse.</span>
                        </div>
                        <button type="button" class="btn btn-success" onclick="openEncReview('ready')"><i data-lucide="package-check"></i> Mark as Ready for Fulfillment</button>
                        <?php endif; ?>
                        <?php if ($orderStatus === 'processing'): ?>
                        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.75rem 1rem;font-size:.85rem;color:#166534;display:flex;align-items:center;gap:.5rem;">
                            <i data-lucide="loader" size="15"></i>
                            <span>Order has been forwarded to the Inventory Clerk for fulfillment.</span>
                        </div>
                        <?php endif; ?>
                        <div style="display:flex;">
                            <button type="button" class="btn btn-danger" style="flex:1;" onclick="openFlagModal(<?php echo $selectedOrder['id']; ?>, '<?php echo htmlspecialchars(addslashes($selectedOrder['po_number'])); ?>')"><i data-lucide="flag"></i> Flag Issue</button>
                        </div>
                    </div>
                </form>
                <?php else: ?>
                <div style="background:#f0fdf4;border-radius:12px;padding:1rem;text-align:center;color:#166534;font-weight:600;">
                    <i data-lucide="check-circle" size="20" style="display:block;margin:0 auto .5rem;"></i> Successfully Review
                </div>
                <?php endif; ?>

                </div><!-- end vp-details pane -->

                <!-- ── History Pane ── -->
                <div class="vp-pane" id="vp-history">
                    <?php if (empty($orderHistory)): ?>
                    <p class="hist-empty">No status history recorded for this order yet.</p>
                    <?php else: ?>
                    <?php
                    $dotMap = [
                        0 => 'dot-submitted',
                        1 => 'dot-review',
                        2 => 'dot-processing',
                        3 => 'dot-ready',
                        4 => 'dot-completed',
                    ];
                    foreach ($orderHistory as $h):
                        $dotCls = $dotMap[$h['status_step']] ?? 'dot-system';
                        $byName = $h['changed_by_name'] ?? null;
                        $byRole = $h['role_name']        ?? null;
                    ?>
                    <div class="hist-item">
                        <div class="hist-dot <?php echo $dotCls; ?>">
                            <?php echo $h['status_step']; ?>
                        </div>
                        <div class="hist-content">
                            <h5><?php echo htmlspecialchars($h['status_label']); ?></h5>
                            <div class="hist-detail"><?php echo htmlspecialchars($h['detail']); ?></div>
                            <div class="hist-who">
                                <?php if ($byName): ?>
                                    <i data-lucide="user" size="11"></i>
                                    <span class="who-badge"><?php echo htmlspecialchars($byName); ?></span>
                                    <?php if ($byRole): ?>
                                    <span style="color:var(--muted);">· <?php echo htmlspecialchars($byRole); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <i data-lucide="cpu" size="11"></i>
                                    <span style="color:var(--muted);">System (auto)</span>
                                <?php endif; ?>
                            </div>
                            <span class="hist-time"><?php echo date('M d, Y h:i A', strtotime($h['changed_at'])); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div><!-- end vp-history pane -->

            </div><!-- end card -->

            <?php else: ?>
            <div class="card">
                <div class="no-select">
                    <i data-lucide="mouse-pointer-click" size="40"></i>
                    <p>Select an order from the queue to view details and take action.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Encoder Order Confirmation Modal (1st authenticator) -->
<div id="encReviewModal">
    <div class="er-box">
        <div class="er-head">
            <h3 id="er-modal-title">Confirm Order Action</h3>
            <button onclick="closeEncReview()" style="background:none;border:none;cursor:pointer;color:var(--muted);"><i data-lucide="x" size="22"></i></button>
        </div>
        <div class="er-body">
            <div class="er-warn">
                <i data-lucide="alert-triangle" size="15" style="flex-shrink:0;margin-top:.1rem;"></i>
                <span id="er-warn-text">Please verify the order details below before proceeding.</span>
            </div>
            <div style="margin-bottom:1.25rem;">
                <div style="font-size:.75rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.04em;margin-bottom:.75rem;">Order Details</div>
                <div class="er-row"><span style="color:var(--muted);">P.O. Number</span><span id="er-po" style="font-weight:700;color:var(--primary);"></span></div>
                <div class="er-row"><span style="color:var(--muted);">Approved By (Admin)</span><span id="er-approver" style="font-weight:600;color:#1d4ed8;"></span></div>
                <div class="er-row"><span style="color:var(--muted);">Franchisee</span><span id="er-franchisee" style="font-weight:600;"></span></div>
                <div class="er-row"><span style="color:var(--muted);">Branch</span><span id="er-branch" style="font-weight:600;"></span></div>
                <div class="er-row"><span style="color:var(--muted);">Delivery Method</span><span id="er-delivery" style="font-weight:600;"></span></div>
                <div class="er-row"><span style="color:var(--muted);">Total Amount</span><span id="er-total" style="font-weight:700;"></span></div>
                <div class="er-row"><span style="color:var(--muted);">Items Count</span><span id="er-items" style="font-weight:600;"></span></div>
            </div>

            <!-- Ordered items breakdown -->
            <div style="margin-top:1.25rem;">
                <div style="font-size:.75rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.04em;margin-bottom:.5rem;">Ordered Items</div>
                <table class="er-items-table" id="er-items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Stock</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="er-items-body">
                        <tr><td colspan="4" style="color:var(--muted);font-style:italic;padding:.75rem .6rem;">—</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Clerk assignment — only shown for 'ready' action -->
            <div class="assign-clerk-panel" id="er-clerk-panel" style="display:none;">
                <div class="acp-label">
                    <i data-lucide="user-check" size="13"></i>
                    Assign Inventory Clerk <span style="color:#dc2626;">*</span>
                </div>
                <div class="clerk-list" id="er-clerk-list">
                    <div class="clerk-list-loading">Loading clerks…</div>
                </div>
            </div>
            <div id="er-action-note" style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.875rem 1rem;font-size:.85rem;color:#166534;"></div>
        </div>
        <div class="er-foot">
            <button class="er-back" onclick="closeEncReview()">← Cancel</button>
            <button class="er-confirm" id="er-submit-btn">✓ Confirm</button>
        </div>
    </div>
</div>

<!-- 2nd Authenticator Dialog -->
<div id="enc2ndConfirm">
    <div class="enc2-box">
        <div class="enc2-icon">⚠️</div>
        <h4 id="enc2-title">Are you absolutely sure?</h4>
        <p id="enc2-body">This action will update the order status and cannot be easily undone.</p>
        <span class="enc2-po" id="enc2-po"></span>
        <div class="enc2-warn" id="enc2-warn"></div>
        <div class="enc2-btns">
            <button class="enc2-cancel" onclick="close2ndConfirm()">Cancel</button>
            <button class="enc2-go" id="enc2-proceed-btn">Yes, Proceed</button>
        </div>
    </div>
</div>

<script>lucide.createIcons();
    function esc(str) {
        if (str == null) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    let _pendingAction     = null;
    let _selectedClerkId   = null;
    let _selectedClerkName = null;

    const actionConfig = {
        confirm:    { title: 'Review & Confirm Order',        warn: 'You are about to confirm this order and move it to <strong>Processing</strong>. Please verify all details below.', note: 'This will move the order to <strong>Processing</strong> and log the update in order history.', btn: '✓ Confirm & Process', color: '#5c4033' },
        ready:      { title: 'Mark as Ready for Fulfillment', warn: 'You are about to mark this order as <strong>Ready for Fulfillment</strong> and forward it to the Inventory Clerk.', note: 'This will move the order to <strong>Ready</strong> status. The Inventory Clerk will deduct warehouse stock.', btn: '✓ Mark Ready', color: '#16a34a' },
        processing: { title: 'Set Order to Processing',       warn: 'You are about to set this order back to <strong>Processing</strong>.', note: 'This will update the order status to <strong>Processing</strong>.', btn: '✓ Set Processing', color: '#b45309' },
    };

    const second2Config = {
        confirm:    { title: 'Confirm this order?',             body: 'This will move the order to Processing status.', warn: 'This action will be logged under your account.' },
        ready:      { title: 'Mark as Ready for Fulfillment?',  body: 'This forwards the order to the Inventory Clerk for stock deduction.', warn: '⚠ This cannot be undone. The order will proceed to the Inventory Clerk immediately.' },
        processing: { title: 'Set to Processing?',              body: 'This updates the order status to Processing.', warn: 'This action will be logged under your account.' },
    };

    function openEncReview(action) {
        _pendingAction     = action;
        _selectedClerkId   = null;
        _selectedClerkName = null;
        const cfg = actionConfig[action];
        const ag  = document.querySelector('.action-group[data-po]');
        if (!ag) return;

        document.getElementById('er-modal-title').textContent = cfg.title;
        document.getElementById('er-warn-text').innerHTML     = cfg.warn;
        document.getElementById('er-action-note').innerHTML   = '<strong>Action:</strong> ' + cfg.note;
        document.getElementById('er-submit-btn').textContent  = cfg.btn;
        document.getElementById('er-submit-btn').style.background = cfg.color;

        document.getElementById('er-po').textContent         = ag.dataset.po        || '—';
        document.getElementById('er-approver').textContent   = ag.dataset.approver  || '—';
        document.getElementById('er-franchisee').textContent = ag.dataset.franchisee || '—';
        document.getElementById('er-branch').textContent     = ag.dataset.branch    || '—';
        document.getElementById('er-delivery').textContent   = ag.dataset.delivery  || '—';
        document.getElementById('er-total').textContent      = ag.dataset.total     || '—';
        document.getElementById('er-items').textContent      = (ag.dataset.items || '0') + ' item(s)';

        // Populate items table
        const itemsBody = document.getElementById('er-items-body');
        try {
            const items = JSON.parse(ag.dataset.itemsJson || '[]');
            if (items.length) {
                itemsBody.innerHTML = items.map(it => {
                    const stockQty = parseInt(it.stock_qty) || 0;
                    const qty      = parseFloat(it.quantity) || 0;
                    let stockBadge;
                    if (stockQty <= 0)        stockBadge = `<span class="er-stock-out">Out of Stock</span>`;
                    else if (stockQty < qty*2) stockBadge = `<span class="er-stock-low">Low (${stockQty})</span>`;
                    else                       stockBadge = `<span class="er-stock-ok">In Stock (${stockQty})</span>`;
                    return `<tr>
                        <td><strong>${esc(it.name)}</strong> <span style="color:var(--muted);font-size:.78rem;">(${esc(it.unit)})</span></td>
                        <td>${qty} × ₱${parseFloat(it.unit_price).toFixed(2)}</td>
                        <td>${stockBadge}</td>
                        <td>₱${parseFloat(it.subtotal).toFixed(2)}</td>
                    </tr>`;
                }).join('');
            } else {
                itemsBody.innerHTML = '<tr><td colspan="4" style="color:var(--muted);font-style:italic;padding:.75rem .6rem;">No items found.</td></tr>';
            }
        } catch(e) {
            itemsBody.innerHTML = '<tr><td colspan="4" style="color:var(--muted);font-style:italic;">—</td></tr>';
        }

        // Show clerk panel only for ready action
        const clerkPanel = document.getElementById('er-clerk-panel');
        if (action === 'ready') {
            clerkPanel.style.display = 'block';
            fetchClerks();
        } else {
            clerkPanel.style.display = 'none';
        }

        document.getElementById('encReviewModal').classList.add('open');
        lucide.createIcons();

        document.getElementById('er-submit-btn').onclick = function() {
            if (action === 'ready' && !_selectedClerkId) {
                clerkPanel.style.border = '1.5px solid #dc2626';
                setTimeout(() => clerkPanel.style.border = '1px solid #bbf7d0', 2000);
                return;
            }
            closeEncReview();
            open2ndConfirm(action, ag.dataset.po);
        };
    }

    function fetchClerks() {
        fetch('fetch-clerks.php')
            .then(r => r.text())
            .then(text => {
                const list = document.getElementById('er-clerk-list');
                try {
                    const data = JSON.parse(text);
                    if (!data.clerks || !data.clerks.length) {
                        list.innerHTML = '<div class="clerk-list-loading">No active inventory clerks found.</div>';
                        return;
                    }
                    list.innerHTML = data.clerks.map(c => `
                        <label class="clerk-option" onclick="selectClerk(${c.id}, '${c.name.replace(/'/g,"\\'")}', this)">
                            <input type="radio" name="clerk_pick" value="${c.id}">
                            <span class="clerk-name">${c.name}</span>
                            <span style="font-size:.75rem;color:#166534;font-weight:600;">Available</span>
                        </label>`).join('');
                    lucide.createIcons();
                } catch(e) {
                    console.error('fetchClerks raw response:', text);
                    list.innerHTML = '<div class="clerk-list-loading" style="color:#dc2626;">Could not load clerks.</div>';
                }
            })
            .catch(() => {
                document.getElementById('er-clerk-list').innerHTML = '<div class="clerk-list-loading" style="color:#dc2626;">Network error loading clerks.</div>';
            });
    }

    function selectClerk(id, name, el) {
        document.querySelectorAll('#er-clerk-list .clerk-option').forEach(o => o.classList.remove('selected'));
        el.classList.add('selected');
        _selectedClerkId   = id;
        _selectedClerkName = name;
    }

    function closeEncReview() {
        document.getElementById('encReviewModal').classList.remove('open');
    }

    // ── 2nd Authenticator ─────────────────────────────────────
    function open2ndConfirm(action, poNumber) {
        const cfg2 = second2Config[action];
        document.getElementById('enc2-title').textContent  = cfg2.title;
        document.getElementById('enc2-body').textContent   = cfg2.body;
        document.getElementById('enc2-warn').textContent   = cfg2.warn;
        document.getElementById('enc2-po').textContent     = poNumber;
        document.getElementById('enc2ndConfirm').classList.add('open');
        lucide.createIcons();

        document.getElementById('enc2-proceed-btn').onclick = function() {
            close2ndConfirm();
            submitEncoderAction(_pendingAction);
        };
    }

    function close2ndConfirm() {
        document.getElementById('enc2ndConfirm').classList.remove('open');
    }

    function submitEncoderAction(action) {
        const form = document.querySelector('form[method="POST"] .action-group')?.closest('form');
        if (!form) return;
        // Write selected clerk to hidden input
        const clerkInput = document.getElementById('selectedClerkInput');
        if (clerkInput && _selectedClerkId) clerkInput.value = _selectedClerkId;
        const btn = document.createElement('button');
        btn.name  = 'action';
        btn.value = action;
        btn.type  = 'submit';
        btn.style.display = 'none';
        form.appendChild(btn);
        btn.click();
    }

    document.getElementById('encReviewModal').addEventListener('click', function(e) { if (e.target === this) closeEncReview(); });
    document.getElementById('enc2ndConfirm').addEventListener('click', function(e) { if (e.target === this) close2ndConfirm(); });

    // Show/hide screenshot upload based on payment method
    const encPaySel = document.querySelector('.pay-form select[name="payment_method"]');
    const encScrWrap = document.getElementById('encScreenshotWrap');

    function updateEncScreenshot() {
        if (!encPaySel || !encScrWrap) return;
        const val = encPaySel.value;
        encScrWrap.style.display = (val === 'GCash' || val === 'Card') ? 'block' : 'none';
    }

    if (encPaySel) {
        encPaySel.addEventListener('change', updateEncScreenshot);
        updateEncScreenshot(); // Run on load in case method is already pre-selected
    }

    function encPreviewScreenshot(input) {
        const preview = document.getElementById('encPreviewBox');
        const img     = document.getElementById('encPreviewImg');
        if (input.files && input.files[0]) {
            if (input.files[0].size > 5 * 1024 * 1024) {
                alert('File too large. Maximum 5MB.');
                input.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = e => {
                img.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // ── Payment proof lightbox ────────────────────────────────
    // ── Verification Panel Tabs ───────────────────────────────
    function switchVpTab(pane, el) {
        document.querySelectorAll('.vp-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.vp-pane').forEach(p => p.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('vp-' + pane).classList.add('active');
    }

    // ── Flag Issue Modal ──────────────────────────────────────
    function openFlagModal(orderId, poNumber) {
        document.getElementById('flag-order-id').value      = orderId;
        document.getElementById('flag-po-label').textContent = poNumber;
        document.getElementById('flagNoteArea').value        = '';
        document.getElementById('flagCharCount').textContent = '0';
        document.getElementById('flagSubmitBtn').disabled    = true;
        document.getElementById('flagModal').classList.add('open');
    }
    function closeFlagModal() {
        document.getElementById('flagModal').classList.remove('open');
    }
    function onFlagInput() {
        const len = document.getElementById('flagNoteArea').value.trim().length;
        document.getElementById('flagCharCount').textContent = len;
        document.getElementById('flagSubmitBtn').disabled    = len < 10;
    }
    document.getElementById('flagModal').addEventListener('click', function(e) {
        if (e.target === this) closeFlagModal();
    });

    // ── Payment proof lightbox ────────────────────────────────
    function openLightbox(src) {
        document.getElementById('lb-img').src = src;
        document.getElementById('lbOverlay').classList.add('open');
    }
    function closeLightbox() {
        document.getElementById('lbOverlay').classList.remove('open');
        document.getElementById('lb-img').src = '';
    }
    document.getElementById('lbOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeLightbox();
    });
</script>

<!-- Screenshot Lightbox -->
<!-- Flag Issue Modal -->
<div id="flagModal">
    <div class="flag-box">
        <h4>🚩 Flag This Order</h4>
        <p class="flag-sub">Order <span class="flag-po" id="flag-po-label"></span> will be returned to the franchisee as <strong>Under Review</strong> with your note so they can correct and resubmit.</p>
        <form method="POST" action="encoder-orders.php" id="flagForm">
            <input type="hidden" name="order_id" id="flag-order-id">
            <input type="hidden" name="action" value="flag">
            <input type="hidden" name="filter" value="active">
            <label for="flagNoteArea">Note / Reason <span style="color:#ef4444;">*</span></label>
            <textarea id="flagNoteArea" name="flag_note" maxlength="500"
                      placeholder="Describe the issue clearly (e.g. payment reference doesn't match, wrong items ordered, screenshot unclear...)"
                      oninput="onFlagInput()"></textarea>
            <div class="flag-char"><span id="flagCharCount">0</span> / 500</div>
            <div class="flag-btns">
                <button type="button" class="flag-cancel" onclick="closeFlagModal()">Cancel</button>
                <button type="submit" class="flag-submit" id="flagSubmitBtn" disabled>🚩 Flag & Return to Franchisee</button>
            </div>
        </form>
    </div>
</div>

<div class="lb-overlay" id="lbOverlay">
    <div class="lb-inner">
        <div class="lb-header">
            <span>🖼 Payment Proof Screenshot</span>
            <button class="lb-close" onclick="closeLightbox()">✕</button>
        </div>
        <div class="lb-img-wrap">
            <img id="lb-img" src="" alt="Payment screenshot">
        </div>
        <div class="lb-footer">Click outside to close</div>
    </div>
</div>
</body>
</html>