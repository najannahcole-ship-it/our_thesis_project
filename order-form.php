<?php
// ============================================================
// order-form.php — New Purchase Order
// DB Tables used:
//   READ  → products        (populate item dropdown)
//   READ  → franchisees     (get branch info for logged-in user)
//   WRITE → orders          (save the new PO)
//   WRITE → order_items     (save each line item)
//   WRITE → order_status_history (seed the first two timeline entries)
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

// ── Get franchisee record ─────────────────────────────────────
// Falls back gracefully if user_id is not yet linked in franchisees table
$franchisee   = getFranchiseeByUser($conn, $userId);
$franchiseeId = $franchisee['id']          ?? null;
$branchName   = $franchisee['branch_name'] ?? 'Your Branch';

// ── Fetch all available products ─────────────────────────────
$products = [];
$prodResult = $conn->query("SELECT id, name, category, unit, price, stock_qty FROM products WHERE status = 'available' ORDER BY category, name");
while ($row = $prodResult->fetch_assoc()) { $products[] = $row; }

// ── Handle POST submission ────────────────────────────────────
$poNumber  = '';
$submitMsg = '';
$submitErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $franchiseeId) {

    $delivery       = $_POST['delivery']        ?? 'Standard Delivery';
    $deliveryFee    = ($delivery === 'Self Pickup') ? 0.00 : 250.00;
    $paymentMethod  = $_POST['payment_method']   ?? 'Cash';
    $productIds     = $_POST['product_id'] ?? [];
    $quantities     = $_POST['quantity']   ?? [];

    if (empty($productIds)) {
        $submitErr = "Please select at least one item.";
    } else {
        $subtotal  = 0;
        $lineItems = [];

        foreach ($productIds as $i => $pid) {
            $pid = intval($pid);
            $qty = intval($quantities[$i] ?? 1);
            if ($pid <= 0 || $qty <= 0) continue;

            // Always verify price from DB — never trust client-submitted values
            $pStmt = $conn->prepare("SELECT name, unit, price FROM products WHERE id = ? AND status = 'available'");
            $pStmt->bind_param("i", $pid);
            $pStmt->execute();
            $pRow = $pStmt->get_result()->fetch_assoc();
            $pStmt->close();

            if ($pRow) {
                $lineSubtotal = $pRow['price'] * $qty;
                $subtotal    += $lineSubtotal;
                $lineItems[]  = [
                    'product_id' => $pid,
                    'name'       => $pRow['name'],
                    'qty'        => $qty,
                    'unit_price' => $pRow['price'],
                    'subtotal'   => $lineSubtotal
                ];
            }
        }

        if (empty($lineItems)) {
            $submitErr = "No valid items were found. Please select at least one product.";
        } else {
            $total      = $subtotal + $deliveryFee;
            $year       = date('Y');
            $pickupDays = ($delivery === 'Self Pickup') ? 0 : ($delivery === 'Priority Delivery' ? 1 : 2);
            $estPickup  = date('Y-m-d', strtotime("+{$pickupDays} days"));

            // Handle payment ref + screenshot
            $paymentRef  = trim($_POST['payment_ref'] ?? '');
            $paymentProofPath = null;

            if (!empty($_FILES['payment_screenshot']['tmp_name']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/payment_screenshots/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $ext      = strtolower(pathinfo($_FILES['payment_screenshot']['name'], PATHINFO_EXTENSION));
                $allowed  = ['jpg','jpeg','png','gif','webp'];
                if (in_array($ext, $allowed) && $_FILES['payment_screenshot']['size'] <= 5 * 1024 * 1024) {
                    $safeName = 'proof_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $uploadDir . $safeName)) {
                        $paymentProofPath = 'uploads/payment_screenshots/' . $safeName;
                    }
                }
            }

            // Map form values to DB enum values
            $methodMap     = ['Cash' => 'cod', 'GCash' => 'gcash', 'Card' => 'bank'];
            $paymentMethod = $methodMap[$paymentMethod] ?? 'cod';

            // Mark paid when franchisee provides both ref number AND proof
            $paymentStatus = ($paymentRef && $paymentProofPath) ? 'paid' : 'unpaid';

            // INSERT into orders with temp PO, then update with real ID-based PO number
            $tempPO   = 'PO-' . $year . '-TEMP';
            $insOrder = $conn->prepare("
                INSERT INTO orders
                    (po_number, franchisee_id, status, delivery_preference,
                     delivery_fee, subtotal, total_amount, created_at, status_step, estimated_pickup,
                     payment_method, payment_status, payment_ref, payment_proof)
                VALUES (?, ?, 'Under Review', ?, ?, ?, ?, NOW(), 1, ?, ?, ?, ?, ?)
            ");
            $insOrder->bind_param("sisdddsssss", $tempPO, $franchiseeId, $delivery, $deliveryFee, $subtotal, $total, $estPickup, $paymentMethod, $paymentStatus, $paymentRef, $paymentProofPath);
            $insOrder->execute();
            $orderId  = $conn->insert_id;
            $insOrder->close();

            // Build real PO number from year + zero-padded DB ID
            $poNumber = 'PO-' . $year . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
            $conn->query("UPDATE orders SET po_number = '" . $conn->real_escape_string($poNumber) . "' WHERE id = $orderId");

            // INSERT line items into order_items
            $insItem = $conn->prepare("
                INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($lineItems as $li) {
                $insItem->bind_param("iiddd", $orderId, $li['product_id'], $li['qty'], $li['unit_price'], $li['subtotal']);
                $insItem->execute();
            }
            $insItem->close();

            // Decrease stock_qty for each ordered product
            $updStock = $conn->prepare(
                "UPDATE products SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?"
            );
            foreach ($lineItems as $li) {
                $updStock->bind_param("ii", $li['qty'], $li['product_id']);
                $updStock->execute();
            }
            $updStock->close();

            // INSERT status history: step 0 (Submitted) + step 1 (Under Review)
            $insHist = $conn->prepare("
                INSERT INTO order_status_history
                    (order_id, status_step, status_label, detail, changed_at, changed_by)
                VALUES (?, ?, ?, ?, NOW(), ?)
            ");
            $histEntries = [
                [0, 'Order Submitted',  'Purchase order successfully received by the system.'],
                [1, 'Under Review',     'Our team is verifying item availability and branch details.']
            ];
            foreach ($histEntries as $e) {
                $insHist->bind_param("iissi", $orderId, $e[0], $e[1], $e[2], $userId);
                $insHist->execute();
            }
            $insHist->close();

            $submitMsg = "success";
            // Store submitted data for confirmation screen
            $confirmedItems    = $lineItems;
            $confirmedSubtotal = $subtotal;
            $confirmedFee      = $deliveryFee;
            $confirmedTotal    = $total;
            $confirmedDelivery = $delivery;
            $confirmedPickup   = date('M d, Y', strtotime($estPickup));
            $confirmedDate     = date('M d, Y h:i A');
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Purchase Order - Juan Café</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --background: #f7f3f0; --foreground: #2d241e; --sidebar-bg: #fdfaf7;
            --card: #ffffff; --card-border: #eeeae6; --primary: #5c4033;
            --primary-light: #8b5e3c; --accent: #d25424; --muted: #8c837d;
            --success: #10b981; --radius: 16px; --sidebar-width: 280px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background-color: var(--background); color: var(--foreground); display: flex; min-height: 100vh; }

        /* ── Sidebar ── */
        aside { width: var(--sidebar-width); background: var(--sidebar-bg); border-right: 1px solid var(--card-border); padding: 2rem 1.5rem; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 10; }
        .logo-container { display: flex; align-items: center; gap: .75rem; margin-bottom: 2.5rem; }
        .logo-icon { width: 40px; height: 40px; background: var(--primary); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; }
        .logo-text h1 { font-family: 'Fraunces', serif; font-size: 1.25rem; line-height: 1; }
        .logo-text span { font-size: .75rem; color: var(--muted); font-weight: 500; }
        .menu-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin-bottom: 1rem; font-weight: 700; }
        nav { display: flex; flex-direction: column; gap: .25rem; flex: 1; }
        .nav-item { display: flex; align-items: center; gap: .75rem; padding: .875rem 1rem; border-radius: 12px; text-decoration: none; color: var(--muted); font-weight: 500; font-size: .95rem; transition: all .2s; }
        .nav-item i { width: 20px; height: 20px; }
        .nav-item:hover { color: var(--primary); background: rgba(92,64,51,.05); }
        .nav-item.active { background: var(--primary); color: white; }
        .user-profile { margin-top: auto; background: white; border: 1px solid var(--card-border); padding: 1rem; border-radius: 16px; display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; }
        .avatar i { color: var(--muted); }
        .user-meta h4 { font-size: .85rem; font-weight: 700; }
        .user-meta p { font-size: .75rem; color: var(--muted); }
        .sign-out { display: flex; align-items: center; gap: .5rem; text-decoration: none; color: var(--muted); font-size: .9rem; padding: .5rem; }

        /* ── Main ── */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 2.5rem 3rem; }
        .header { margin-bottom: 2rem; }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: .25rem; }
        .header p { color: var(--muted); }

        /* ── Layout ── */
        .order-container { display: grid; grid-template-columns: 1fr 360px; gap: 2rem; align-items: start; }
        .card { background: white; border: 1px solid var(--card-border); border-radius: 20px; padding: 2rem; margin-bottom: 1.5rem; }
        .card:last-child { margin-bottom: 0; }
        .section-title { font-family: 'Fraunces', serif; font-size: 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: .5rem; }

        /* ── Form elements ── */
        .form-group { margin-bottom: 1.25rem; }
        .form-label { display: block; font-size: .8rem; font-weight: 700; color: var(--muted); margin-bottom: .4rem; text-transform: uppercase; letter-spacing: .04em; }
        .input-control { width: 100%; padding: .75rem 1rem; border: 1.5px solid var(--card-border); border-radius: 10px; font-family: inherit; font-size: .95rem; transition: border-color .2s; background: white; }
        .input-control:focus { outline: none; border-color: var(--primary); }
        .input-control[readonly] { background: var(--background); color: var(--muted); cursor: default; }
        select.input-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%238c837d' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .75rem center; background-size: 1.1em; padding-right: 2.5rem; }

        /* ── Item rows ── */
        .item-row { display: grid; grid-template-columns: 1fr 90px 120px 36px; gap: .75rem; margin-bottom: .75rem; align-items: start; }
        .item-row .form-group { margin-bottom: 0; }
        .btn-remove { width: 36px; height: 40px; border: none; background: none; color: var(--muted); cursor: pointer; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: color .2s; align-self: flex-end; margin-bottom: 0; }
        .btn-remove:hover { color: #ef4444; }
        .btn-add-row { width: 100%; padding: .75rem; border: 1.5px dashed var(--card-border); border-radius: 10px; background: transparent; color: var(--primary); font-weight: 600; font-family: inherit; font-size: .9rem; cursor: pointer; margin-top: .5rem; transition: all .2s; }
        .btn-add-row:hover { border-color: var(--primary); background: rgba(92,64,51,.03); }

        /* ── Delivery radio ── */
        .radio-group { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; }
        .radio-option { position: relative; }
        .radio-option input { position: absolute; opacity: 0; width: 0; height: 0; }
        .radio-label { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem; padding: 1rem .5rem; border: 1.5px solid var(--card-border); border-radius: 12px; cursor: pointer; transition: all .2s; text-align: center; min-height: 80px; }
        .radio-label .radio-title { font-weight: 600; font-size: .9rem; }
        .radio-label .radio-sub { font-size: .75rem; color: var(--muted); }
        .radio-option input:checked + .radio-label { border-color: var(--primary); background: rgba(92,64,51,.06); color: var(--primary); }
        .radio-option input:checked + .radio-label .radio-sub { color: var(--primary-light); }

        /* ── Order Summary Panel ── */
        .summary-panel { position: sticky; top: 2rem; }
        .summary-header { font-family: 'Fraunces', serif; font-size: 1.2rem; margin-bottom: 1.5rem; }

        /* Branch info strip */
        .summary-branch { background: var(--background); border-radius: 10px; padding: .875rem 1rem; margin-bottom: 1.25rem; }
        .summary-branch .branch-label { font-size: .75rem; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .summary-branch .branch-value { font-size: .95rem; font-weight: 600; margin-top: .2rem; }
        .summary-branch .branch-franchisee { font-size: .82rem; color: var(--muted); }

        /* Items list */
        .summary-items { margin-bottom: 1.25rem; min-height: 40px; }
        .summary-item-row { display: flex; justify-content: space-between; align-items: flex-start; font-size: .85rem; padding: .4rem 0; border-bottom: 1px dashed var(--card-border); gap: .5rem; }
        .summary-item-row:last-child { border-bottom: none; }
        .summary-item-name { color: var(--foreground); flex: 1; }
        .summary-item-price { font-weight: 600; color: var(--primary); white-space: nowrap; }
        .summary-empty { color: var(--muted); font-size: .85rem; font-style: italic; text-align: center; padding: .5rem 0; }

        /* Delivery summary */
        .summary-delivery { background: var(--background); border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; }
        .summary-delivery .del-label { font-size: .8rem; color: var(--muted); font-weight: 600; text-transform: uppercase; }
        .summary-delivery .del-value { font-size: .9rem; font-weight: 600; }

        /* Totals */
        .summary-divider { border: none; border-top: 1.5px solid var(--card-border); margin: 1rem 0; }
        .summary-line { display: flex; justify-content: space-between; font-size: .9rem; margin-bottom: .6rem; }
        .summary-line.total { font-size: 1.15rem; font-weight: 700; margin-top: .75rem; padding-top: .75rem; border-top: 1.5px solid var(--card-border); }

        /* Submit button */
        .btn-submit { width: 100%; background: var(--primary); color: white; border: none; padding: 1rem; border-radius: 12px; font-size: 1rem; font-weight: 700; cursor: pointer; margin-top: 1.25rem; font-family: inherit; transition: background .2s; display: flex; align-items: center; justify-content: center; gap: .5rem; }
        .btn-submit:hover { background: var(--primary-light); }
        .btn-submit:disabled { opacity: .5; cursor: not-allowed; }
        /* Payment method */
        .pay-options { display: grid; grid-template-columns: repeat(3,1fr); gap: .75rem; }
        .pay-option { position: relative; }
        .pay-option input { position: absolute; opacity: 0; width: 0; height: 0; }
        .pay-label { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .35rem; padding: .875rem .5rem; border: 1.5px solid var(--card-border); border-radius: 12px; cursor: pointer; transition: all .2s; text-align: center; min-height: 72px; }
        .pay-label .pay-icon { font-size: 1.4rem; }
        .pay-label .pay-title { font-weight: 600; font-size: .88rem; }
        .pay-label .pay-sub { font-size: .72rem; color: var(--muted); }
        .pay-option input:checked + .pay-label { border-color: var(--primary); background: rgba(92,64,51,.06); color: var(--primary); }
        .pay-option input:checked + .pay-label .pay-sub { color: var(--primary-light); }

        /* Stock badge after item selection */


        /* Out-of-stock fix modal */
        #stockFixModal { position: fixed; inset: 0; background: rgba(0,0,0,.6); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 200; padding: 1rem; }
        #stockFixModal.open { display: flex; }
        .stockfix-box { background: white; border-radius: 24px; max-width: 500px; width: 100%; box-shadow: 0 25px 60px -12px rgba(0,0,0,.25); overflow: hidden; }
        .stockfix-header { padding: 1.5rem 2rem 1.25rem; border-bottom: 1px solid var(--card-border); display: flex; align-items: center; gap: .75rem; }
        .stockfix-header .sf-icon { width: 44px; height: 44px; background: #fef2f2; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.25rem; }
        .stockfix-header h3 { font-family: 'Fraunces', serif; font-size: 1.2rem; margin-bottom: .2rem; }
        .stockfix-header p { font-size: .82rem; color: var(--muted); }
        .stockfix-body { padding: 1.25rem 2rem; }
        .stockfix-item { display: flex; align-items: center; justify-content: space-between; padding: .75rem 1rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; margin-bottom: .5rem; gap: 1rem; }
        .stockfix-item:last-child { margin-bottom: 0; }
        .stockfix-item-name { font-weight: 600; font-size: .9rem; color: #7f1d1d; }
        .stockfix-item-note { font-size: .78rem; color: #991b1b; margin-top: .15rem; }
        .stockfix-remove { background: #991b1b; color: white; border: none; padding: .4rem .9rem; border-radius: 8px; font-size: .8rem; font-weight: 700; cursor: pointer; white-space: nowrap; font-family: inherit; transition: background .15s; flex-shrink: 0; }
        .stockfix-remove:hover { background: #7f1d1d; }
        .stockfix-footer { padding: 1rem 2rem 1.5rem; display: flex; gap: .75rem; }
        .stockfix-footer button { flex: 1; padding: .875rem; border-radius: 12px; font-weight: 700; font-family: inherit; font-size: .92rem; cursor: pointer; border: none; }
        .sf-btn-cancel { background: var(--background); color: var(--foreground); border: 1px solid var(--card-border) !important; }
        .sf-btn-proceed { background: var(--primary); color: white; }
        #reviewModal { position: fixed; inset: 0; background: rgba(0,0,0,.55); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 150; padding: 1rem; }
        #reviewModal.open { display: flex; }
        #payConfirmModal { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(6px); display: none; align-items: center; justify-content: center; z-index: 200; padding: 1rem; }
        #payConfirmModal.open { display: flex; }
        .payconfirm-box { background: white; border-radius: 24px; max-width: 420px; width: 100%; box-shadow: 0 25px 60px -12px rgba(0,0,0,.3); overflow: hidden; }
        .payconfirm-header { padding: 1.75rem 2rem 1.25rem; text-align: center; border-bottom: 1px solid var(--card-border); }
        .payconfirm-icon { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto .875rem; font-size: 1.5rem; }
        .payconfirm-icon.gcash { background: #eff6ff; }
        .payconfirm-icon.card  { background: #f0fdf4; }
        .payconfirm-header h3 { font-family: 'Fraunces', serif; font-size: 1.3rem; margin-bottom: .4rem; }
        .payconfirm-header p  { font-size: .875rem; color: var(--muted); line-height: 1.5; }
        .payconfirm-summary { padding: 1.25rem 2rem; background: var(--background); }
        .payconfirm-row { display: flex; justify-content: space-between; font-size: .9rem; padding: .35rem 0; }
        .payconfirm-row .pc-label { color: var(--muted); }
        .payconfirm-row .pc-value { font-weight: 700; }
        .payconfirm-warn { margin: 0 2rem; padding: .875rem 1rem; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; font-size: .82rem; color: #92400e; display: flex; align-items: flex-start; gap: .5rem; }
        .payconfirm-footer { padding: 1.25rem 2rem 1.75rem; display: flex; gap: .75rem; }
        .payconfirm-footer button { flex: 1; padding: .875rem; border-radius: 12px; font-weight: 700; font-family: inherit; font-size: .92rem; cursor: pointer; border: none; }
        .pc-back { background: var(--background); color: var(--foreground); border: 1.5px solid var(--card-border) !important; }
        .pc-back:hover { border-color: var(--primary) !important; color: var(--primary); }
        .pc-confirm { background: var(--primary); color: white; }
        .pc-confirm:hover { background: var(--primary-light); }
        .pc-confirm:disabled { opacity: .5; cursor: not-allowed; }
        /* Payment timer */
        .pc-timer-bar { display: flex; align-items: center; justify-content: center; gap: .5rem; padding: .6rem 1.5rem .3rem; font-size: .82rem; color: var(--muted); font-weight: 600; }
        .pc-timer-bar .pc-timer-digits { font-variant-numeric: tabular-nums; color: var(--primary); font-size: 1rem; font-weight: 700; letter-spacing: .04em; min-width: 2.8rem; text-align: center; }
        .pc-timer-bar.urgent .pc-timer-digits { color: #dc2626; animation: timerPulse .8s ease-in-out infinite; }
        @keyframes timerPulse { 0%,100% { opacity:1; } 50% { opacity:.4; } }
        .pc-timer-track { width: calc(100% - 3rem); margin: 0 1.5rem .5rem; height: 4px; background: var(--card-border); border-radius: 99px; overflow: hidden; }
        .pc-timer-fill { height: 100%; background: var(--primary); border-radius: 99px; width: 100%; }
        /* Payment proof fields inside modal */
        .payconfirm-fields { padding: 1.25rem 2rem; border-top: 1px solid var(--card-border); }
        .payconfirm-fields label { display: block; font-size: .78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: .4rem; }
        .payconfirm-fields input[type="text"] { width: 100%; padding: .75rem 1rem; border: 1.5px solid var(--card-border); border-radius: 10px; font-family: inherit; font-size: .92rem; outline: none; margin-bottom: 1rem; transition: border-color .2s; }
        .payconfirm-fields input[type="text"]:focus { border-color: var(--primary); }
        .pc-upload-box { border: 2px dashed var(--card-border); border-radius: 12px; padding: 1.1rem; text-align: center; cursor: pointer; background: var(--background); transition: border-color .2s; }
        .pc-upload-box:hover { border-color: var(--primary); background: white; }
        .pc-upload-box input[type="file"] { display: none; }
        .pc-upload-box label { cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: .35rem; }
        .pc-upload-box .pc-u-icon { font-size: 1.3rem; }
        .pc-upload-box .pc-u-text { font-size: .85rem; font-weight: 600; color: var(--primary); }
        .pc-upload-box .pc-u-sub  { font-size: .72rem; color: var(--muted); }
        .pc-preview { margin-top: .75rem; display: none; position: relative; }
        .pc-preview img { width: 100%; border-radius: 8px; border: 1px solid var(--card-border); max-height: 150px; object-fit: cover; }
        .pc-remove-btn { display: flex; align-items: center; gap: .4rem; margin-top: .5rem; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px; padding: .4rem .875rem; font-size: .82rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: background .15s; width: 100%; justify-content: center; }
        .pc-remove-btn:hover { background: #fee2e2; }
        .pc-required-note { font-size: .75rem; color: #92400e; margin-top: .4rem; font-style: italic; }
        .review-box { background: white; border-radius: 24px; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,.2); }
        .review-header { padding: 1.75rem 2rem 1.25rem; border-bottom: 1px solid var(--card-border); display: flex; justify-content: space-between; align-items: center; }
        .review-header h3 { font-family: 'Fraunces', serif; font-size: 1.4rem; }
        .review-body { padding: 1.5rem 2rem; }
        .review-section { margin-bottom: 1.5rem; }
        .review-section h4 { font-size: .78rem; text-transform: uppercase; color: var(--muted); font-weight: 700; letter-spacing: .04em; margin-bottom: .75rem; }
        .review-row { display: flex; justify-content: space-between; font-size: .9rem; padding: .4rem 0; border-bottom: 1px dashed var(--card-border); }
        .review-row:last-child { border-bottom: none; }
        .review-total { display: flex; justify-content: space-between; font-size: 1.05rem; font-weight: 700; padding: .75rem 0; border-top: 2px solid var(--card-border); margin-top: .5rem; }
        .review-footer { padding: 1.25rem 2rem 1.75rem; display: flex; gap: .75rem; }
        .review-footer button, .review-footer input[type="submit"] { flex: 1; padding: .875rem; border-radius: 12px; font-weight: 700; font-family: inherit; font-size: .95rem; cursor: pointer; border: none; }
        .btn-review-back { background: var(--background); color: var(--foreground); border: 1px solid var(--card-border) !important; }
        .btn-review-confirm { background: var(--primary); color: white; }
        .btn-review-confirm:hover { background: var(--primary-light); }
        .warn-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: .875rem 1rem; font-size: .85rem; color: #92400e; margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: .5rem; }

        /* Alerts */
        .alert-error { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; font-size: .9rem; }
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; font-size: .9rem; }

        /* ── Confirmation Modal ── */
        #confirmModal { position: fixed; inset: 0; background: rgba(0,0,0,.55); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 200; padding: 1rem; }
        #confirmModal.show { display: flex; }
        .modal-box { background: white; border-radius: 24px; padding: 2.5rem; max-width: 520px; width: 100%; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,.2); }
        .modal-icon { width: 72px; height: 72px; background: #f0fdf4; color: var(--success); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; }
        .modal-box h3 { font-family: 'Fraunces', serif; font-size: 1.75rem; margin-bottom: .5rem; }
        .modal-box .modal-sub { color: var(--muted); font-size: .9rem; margin-bottom: 1.5rem; }
        .po-chip { display: inline-block; background: var(--background); color: var(--primary); font-weight: 700; font-size: 1.2rem; padding: .6rem 1.5rem; border-radius: 10px; border: 1px solid var(--card-border); margin-bottom: 1.5rem; letter-spacing: .03em; }

        /* Confirmation details table inside modal */
        .confirm-details { text-align: left; background: var(--background); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; }
        .confirm-row { display: flex; justify-content: space-between; font-size: .875rem; padding: .35rem 0; border-bottom: 1px solid var(--card-border); }
        .confirm-row:last-child { border-bottom: none; }
        .confirm-row .c-label { color: var(--muted); }
        .confirm-row .c-value { font-weight: 600; text-align: right; max-width: 55%; }

        /* Items mini-list in modal */
        .confirm-items { text-align: left; margin-bottom: 1rem; }
        .confirm-items h4 { font-size: .8rem; text-transform: uppercase; color: var(--muted); font-weight: 700; letter-spacing: .04em; margin-bottom: .5rem; }
        .confirm-item { display: flex; justify-content: space-between; font-size: .85rem; padding: .3rem 0; }
        .confirm-total-row { display: flex; justify-content: space-between; font-size: 1rem; font-weight: 700; padding-top: .75rem; border-top: 1.5px solid var(--card-border); margin-top: .5rem; }

        /* Modal buttons */
        .modal-btns { display: flex; gap: .75rem; }
        .modal-btns a { flex: 1; padding: .9rem; border-radius: 12px; font-weight: 700; font-family: inherit; font-size: .95rem; text-decoration: none; text-align: center; transition: opacity .2s; }
        .btn-go-dash { background: var(--primary); color: white; }
        .btn-go-dash:hover { opacity: .88; }
        .btn-go-status { background: var(--background); color: var(--primary); border: 1px solid var(--card-border); }
        .btn-go-status:hover { opacity: .88; }
    </style>
</head>
<body>

<!-- ── Sidebar ── -->
<aside>
    <div class="logo-container">
        <div class="logo-icon"><i data-lucide="coffee"></i></div>
        <div class="logo-text"><h1>Top Juan</h1><span>Franchise Portal</span><span style="font-size:.68rem;color:var(--primary);font-weight:600;margin-top:.1rem;display:block;line-height:1;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? '—'); ?></span></div>
    </div>
    <div class="menu-label">Menu</div>
    <nav>
        <a href="franchisee-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="order-form.php" class="nav-item active"><i data-lucide="clipboard-list"></i> Order Form</a>
        <a href="item-usage.php" class="nav-item"><i data-lucide="box"></i> Item Usage</a>
        <a href="order-status.php" class="nav-item"><i data-lucide="package"></i> Order Status</a>
        <a href="returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i> Returns</a>
        <a href="history.php" class="nav-item"><i data-lucide="history"></i> History</a>
        <a href="profile.php" class="nav-item"><i data-lucide="user"></i> Profile</a>
    </nav>
    <div class="user-profile">
        <div class="avatar"><i data-lucide="user"></i></div>
        <div class="user-meta">
            <h4><?php echo htmlspecialchars($fullName); ?></h4>
            <p style="font-size:.72rem;color:var(--muted);font-weight:500;"><?php echo htmlspecialchars($branchName); ?></p>
        </div>
    </div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
</aside>

<!-- ── Main Content ── -->
<main>
    <div class="header">
        <h2>New Purchase Order</h2>
        <p>Select raw ingredients and supplies for your branch.</p>
    </div>

    <?php if ($submitErr): ?>
        <div class="alert-error"><strong>Error:</strong> <?php echo htmlspecialchars($submitErr); ?></div>
    <?php endif; ?>

    <?php if (!$franchiseeId): ?>
        <div class="alert-warning">
            <strong>Account not linked.</strong> Your account is not yet connected to a branch record. Please ask the administrator to link your account in the franchisees table.
        </div>
    <?php endif; ?>

    <form id="orderForm" method="POST" action="order-form.php" enctype="multipart/form-data">
        <!-- Populated by JS from payment confirm modal -->
        <input type="hidden" name="payment_ref" id="hiddenPayRef">
        <div class="order-container">

            <!-- ── LEFT: Form sections ── -->
            <div>

                <!-- Branch Information -->
                <div class="card">
                    <h3 class="section-title"><i data-lucide="store"></i> Branch Information</h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group">
                            <label class="form-label">Branch Name</label>
                            <input type="text" class="input-control" id="displayBranch" value="<?php echo htmlspecialchars($branchName); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Franchisee</label>
                            <input type="text" class="input-control" id="displayFranchisee" value="<?php echo htmlspecialchars($fullName); ?>" readonly>
                        </div>
                    </div>
                </div>

                <!-- Product Selection -->
                <div class="card">
                    <h3 class="section-title"><i data-lucide="shopping-basket"></i> Product Selection</h3>
                    <div style="display:grid;grid-template-columns:1fr 90px 120px 36px;gap:.75rem;margin-bottom:.25rem;padding:0 0 .25rem;border-bottom:1px solid var(--card-border);">
                        <span style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.04em;">Raw Ingredient</span>
                        <span style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.04em;">Qty</span>
                        <span style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.04em;">Subtotal</span>
                        <span></span>
                    </div>
                    <div id="itemsContainer">
                        <!-- First row (always present) -->
                        <div class="item-row">
                            <div class="form-group">
                                <select class="input-control item-select" name="product_id[]" required onchange="onItemChange(this)">
                                    <option value="" disabled selected>Select item</option>
                                    <?php
                                    $lastCat = '';
                                    foreach ($products as $p):
                                        if ($p['category'] !== $lastCat):
                                            if ($lastCat !== '') echo '</optgroup>';
                                            echo '<optgroup label="' . htmlspecialchars($p['category']) . '">';
                                            $lastCat = $p['category'];
                                        endif;
                                    ?>
                                        <option value="<?php echo $p['id']; ?>"
                                                data-price="<?php echo $p['price']; ?>"
                                                data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                                data-unit="<?php echo htmlspecialchars($p['unit']); ?>"
                                                data-stock="<?php echo intval($p['stock_qty']); ?>"
                                                <?php if (intval($p['stock_qty']) === 0): ?>style="color:#991b1b;" disabled <?php endif; ?>>
                                            <?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['unit']); ?>) — ₱<?php echo number_format($p['price'], 2); ?><?php echo intval($p['stock_qty']) === 0 ? ' — OUT OF STOCK' : ' | Stock: ' . intval($p['stock_qty']); ?>
                                        </option>
                                    <?php endforeach; if ($lastCat !== '') echo '</optgroup>'; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <input type="number" class="input-control item-qty" name="quantity[]" value="1" min="1" required oninput="onQtyChange(this)">
                            </div>
                            <div class="form-group">
                                <input type="text" class="input-control item-subtotal" value="₱0.00" readonly>
                            </div>
                            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove item">
                                <i data-lucide="trash-2" size="18"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="btn-add-row" onclick="addItemRow()">
                        + Add Another Item
                    </button>
                </div>

                <!-- Delivery Preference -->
                <div class="card">
                    <h3 class="section-title"><i data-lucide="truck"></i> Delivery Preference</h3>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="delivery" value="Standard Delivery" checked onchange="onDeliveryChange()">
                            <span class="radio-label">
                                <span class="radio-title">Standard Delivery</span>
                                <span class="radio-sub">2–3 Days • ₱250</span>
                            </span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="delivery" value="Priority Delivery" onchange="onDeliveryChange()">
                            <span class="radio-label">
                                <span class="radio-title">Priority</span>
                                <span class="radio-sub">Next Day • ₱250</span>
                            </span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="delivery" value="Self Pickup" onchange="onDeliveryChange()">
                            <span class="radio-label">
                                <span class="radio-title">Self Pickup</span>
                                <span class="radio-sub">Same Day • Free</span>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Payment Method -->
                <div class="card">
                    <h3 class="section-title"><i data-lucide="credit-card"></i> Payment Method</h3>
                    <div class="pay-options">
                        <label class="pay-option">
                            <input type="radio" name="payment_method" value="Cash" checked onchange="onPayChange(this)">
                            <span class="pay-label">
                                <span class="pay-icon">💵</span>
                                <span class="pay-title">Cash</span>
                                <span class="pay-sub">Pay on pickup</span>
                            </span>
                        </label>
                        <label class="pay-option">
                            <input type="radio" name="payment_method" value="GCash" onchange="onPayChange(this)">
                            <span class="pay-label">
                                <span class="pay-icon">📱</span>
                                <span class="pay-title">GCash</span>
                                <span class="pay-sub">Online transfer</span>
                            </span>
                        </label>
                        <label class="pay-option">
                            <input type="radio" name="payment_method" value="Card" onchange="onPayChange(this)">
                            <span class="pay-label">
                                <span class="pay-icon">💳</span>
                                <span class="pay-title">Card</span>
                                <span class="pay-sub">Debit / Credit</span>
                            </span>
                        </label>
                    </div>

                </div>

            </div><!-- end left -->

            <!-- ── RIGHT: Live Order Summary Panel ── -->
            <div class="summary-panel">
                <div class="card">
                    <h3 class="summary-header">Order Summary</h3>

                    <!-- Branch strip -->
                    <div class="summary-branch">
                        <div class="branch-label">Branch</div>
                        <div class="branch-value" id="sumBranch"><?php echo htmlspecialchars($branchName); ?></div>
                        <div class="branch-franchisee" id="sumFranchisee"><?php echo htmlspecialchars($fullName); ?></div>
                    </div>

                    <!-- Item lines (updated live by JS) -->
                    <div class="summary-items" id="sumItems">
                        <div class="summary-empty" id="sumEmpty">No items selected yet.</div>
                    </div>

                    <!-- Delivery method -->
                    <div class="summary-delivery">
                        <span class="del-label">Delivery</span>
                        <span class="del-value" id="sumDelivery">Standard Delivery</span>
                    </div>
                    <div class="summary-delivery" style="margin-top:.5rem;">
                        <span class="del-label">Payment</span>
                        <span class="del-value" id="sumPayment">Cash</span>
                    </div>

                    <!-- Totals -->
                    <div class="summary-line">
                        <span style="color:var(--muted)">Subtotal</span>
                        <span id="sumSubtotal">₱0.00</span>
                    </div>
                    <div class="summary-line">
                        <span style="color:var(--muted)">Delivery Fee</span>
                        <span id="sumFee">₱250.00</span>
                    </div>
                    <div class="summary-line total">
                        <span>Total Amount</span>
                        <span id="sumTotal">₱250.00</span>
                    </div>

                    <button type="button" class="btn-submit" id="submitBtn" onclick="openReviewModal()" <?php echo !$franchiseeId ? 'disabled' : ''; ?>>
                        <i data-lucide="eye" size="18"></i>
                        Review & Submit Order
                    </button>
                </div>
            </div><!-- end right -->

        </div><!-- end order-container -->
    <!-- Hidden product options template for localStorage draft restore -->
    <select id="productOptionsTemplate" style="display:none;">
        <option value="" disabled selected>Select item</option>
        <?php
        $lastCatT = '';
        foreach ($products as $p):
            if ($p['category'] !== $lastCatT):
                if ($lastCatT !== '') echo '</optgroup>';
                echo '<optgroup label="' . htmlspecialchars($p['category']) . '">';
                $lastCatT = $p['category'];
            endif;
        ?>
            <option value="<?php echo $p['id']; ?>"
                    data-price="<?php echo $p['price']; ?>"
                    data-name="<?php echo htmlspecialchars($p['name']); ?>"
                    data-unit="<?php echo htmlspecialchars($p['unit']); ?>"
                    data-stock="<?php echo intval($p['stock_qty']); ?>"
                    <?php if (intval($p['stock_qty']) === 0): ?>style="color:#991b1b;" disabled <?php endif; ?>>
                <?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['unit']); ?>) — ₱<?php echo number_format($p['price'], 2); ?><?php echo intval($p['stock_qty']) === 0 ? ' — OUT OF STOCK' : ' | Stock: ' . intval($p['stock_qty']); ?>
            </option>
        <?php endforeach; if ($lastCatT !== '') echo '</optgroup>'; ?>
    </select>
    </form>
</main>

<!-- ── Order Review Modal (double-check before submitting) ── -->
<div id="reviewModal">
    <div class="review-box">
        <div class="review-header">
            <h3>Review Your Order</h3>
            <button onclick="closeReview()" style="background:none;border:none;cursor:pointer;color:var(--muted);"><i data-lucide="x" size="22"></i></button>
        </div>
        <div class="review-body">
            <div class="warn-box">
                <i data-lucide="alert-triangle" size="16" style="flex-shrink:0;margin-top:.1rem;"></i>
                <span>Please review your order carefully before confirming. Once submitted it will be sent to the main office for processing.</span>
            </div>

            <!-- Branch + Payment info -->
            <div class="review-section">
                <h4>Order Information</h4>
                <div class="review-row"><span style="color:var(--muted)">Branch</span><span id="rev-branch" style="font-weight:600;"></span></div>
                <div class="review-row"><span style="color:var(--muted)">Franchisee</span><span id="rev-franchisee" style="font-weight:600;"></span></div>
                <div class="review-row"><span style="color:var(--muted)">Delivery Method</span><span id="rev-delivery" style="font-weight:600;"></span></div>
                <div class="review-row"><span style="color:var(--muted)">Payment Method</span><span id="rev-payment" style="font-weight:600;"></span></div>
            </div>

            <!-- Items -->
            <div class="review-section">
                <h4>Items Ordered</h4>
                <div id="rev-items"></div>
                <div class="review-total">
                    <span>Total Amount</span>
                    <span id="rev-total"></span>
                </div>
            </div>
        </div>
        <div class="review-footer">
            <button class="btn-review-back" onclick="closeReview()">← Go Back & Edit</button>
            <button class="btn-review-confirm" id="reviewProceedBtn" onclick="onReviewProceed()">✓ Confirm & Submit</button>
        </div>
    </div>
</div>

<!-- ── Confirmation Modal (shown after successful POST) ── -->
<?php if ($submitMsg === 'success'): ?>
<div id="confirmModal" class="show">
    <div class="modal-box">
        <div class="modal-icon"><i data-lucide="check" size="36"></i></div>
        <h3>Order Submitted!</h3>
        <p class="modal-sub">Your purchase order has been sent to the main office for review.</p>

        <div class="po-chip"><?php echo htmlspecialchars($poNumber); ?></div>

        <!-- Full order summary in modal -->
        <div class="confirm-details">
            <div class="confirm-row">
                <span class="c-label">Branch</span>
                <span class="c-value"><?php echo htmlspecialchars($branchName); ?></span>
            </div>
            <div class="confirm-row">
                <span class="c-label">Franchisee</span>
                <span class="c-value"><?php echo htmlspecialchars($fullName); ?></span>
            </div>
            <div class="confirm-row">
                <span class="c-label">Delivery Method</span>
                <span class="c-value"><?php echo htmlspecialchars($confirmedDelivery); ?></span>
            </div>
            <div class="confirm-row">
                <span class="c-label">Payment Method</span>
                <span class="c-value"><?php echo htmlspecialchars($paymentMethod ?? 'Cash'); ?></span>
            </div>
            <div class="confirm-row">
                <span class="c-label">Est. Pickup / Delivery</span>
                <span class="c-value"><?php echo htmlspecialchars($confirmedPickup); ?></span>
            </div>
            <div class="confirm-row">
                <span class="c-label">Submitted At</span>
                <span class="c-value"><?php echo htmlspecialchars($confirmedDate); ?></span>
            </div>
        </div>

        <!-- Items ordered -->
        <div class="confirm-items">
            <h4>Items Ordered (<?php echo count($confirmedItems); ?>)</h4>
            <?php foreach ($confirmedItems as $ci): ?>
            <div class="confirm-item">
                <span><?php echo htmlspecialchars($ci['name']); ?> ×<?php echo $ci['qty']; ?></span>
                <span>₱<?php echo number_format($ci['subtotal'], 2); ?></span>
            </div>
            <?php endforeach; ?>
            <div class="confirm-total-row">
                <span>Total Amount</span>
                <span>₱<?php echo number_format($confirmedTotal, 2); ?></span>
            </div>
        </div>

        <div class="modal-btns">
            <a href="franchisee-dashboard.php" class="btn-go-dash">
                Go to Dashboard
            </a>
            <a href="order-status.php?po=<?php echo urlencode($poNumber); ?>" class="btn-go-status">
                Track Order
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    lucide.createIcons();

    // ── Live summary update functions ──────────────────────────

    function fmt(num) {
        return '₱' + Number(num).toLocaleString('en-PH', { minimumFractionDigits: 2 });
    }

    function onItemChange(sel) {
        const row   = sel.closest('.item-row');
        const opt   = sel.options[sel.selectedIndex];
        const price = parseFloat(opt?.dataset?.price || 0);
        const qty   = parseInt(row.querySelector('.item-qty').value) || 1;
        const stock = parseInt(opt?.dataset?.stock ?? -1);
        row.querySelector('.item-subtotal').value = fmt(price * qty);

        // Remove any existing stock badge
        sel.classList.remove('out-of-stock');

        // Req 8: Prevent same item in multiple rows
        updateAllDropdowns();
        refreshSummary();
    }

    // Req 8: Grey out already-selected items across all dropdowns
    function updateAllDropdowns() {
        const selects   = document.querySelectorAll('.item-select');
        const selected  = new Set();
        selects.forEach(s => { if (s.value) selected.add(s.value); });
        selects.forEach(s => {
            Array.from(s.options).forEach(opt => {
                const isOutOfStock = parseInt(opt.dataset.stock ?? -1) === 0;
                const isUsedElsewhere = opt.value && selected.has(opt.value) && opt.value !== s.value;
                if (isOutOfStock) {
                    opt.disabled = true;
                    opt.style.color = '#ccc';
                } else if (isUsedElsewhere) {
                    opt.disabled = true;
                    opt.style.color = '#ccc';
                } else {
                    opt.disabled = false;
                    opt.style.color = '';
                }
            });
        });
    }

    function onQtyChange(input) {
        if (parseInt(input.value) < 1) input.value = 1;
        const row   = input.closest('.item-row');
        const sel   = row.querySelector('.item-select');
        const opt   = sel.options[sel.selectedIndex];
        const price = parseFloat(opt?.dataset?.price || 0);
        const qty   = parseInt(input.value) || 1;
        row.querySelector('.item-subtotal').value = fmt(price * qty);
        refreshSummary();
    }

    function onDeliveryChange() {
        const val = document.querySelector('input[name="delivery"]:checked')?.value || 'Standard Delivery';
        document.getElementById('sumDelivery').innerText = val;
        document.getElementById('sumFee').innerText = (val === 'Self Pickup') ? '₱0.00' : '₱250.00';
        refreshSummary();
    }

    function refreshSummary() {
        const rows     = document.querySelectorAll('.item-row');
        const itemsDiv = document.getElementById('sumItems');
        const emptyMsg = document.getElementById('sumEmpty');
        const delivery = document.querySelector('input[name="delivery"]:checked')?.value || 'Standard Delivery';
        const fee      = (delivery === 'Self Pickup') ? 0 : 250;

        let subtotal  = 0;
        let itemsHTML = '';
        let hasItems  = false;

        rows.forEach(row => {
            const sel   = row.querySelector('.item-select');
            const opt   = sel.options[sel.selectedIndex];
            const qty   = parseInt(row.querySelector('.item-qty').value) || 0;
            const price = parseFloat(opt?.dataset?.price || 0);
            if (!price || !qty) return;
            hasItems = true;
            const lineSub = price * qty;
            subtotal += lineSub;
            itemsHTML += `
                <div class="summary-item-row">
                    <span class="summary-item-name">${opt.dataset.name} ×${qty}</span>
                    <span class="summary-item-price">${fmt(lineSub)}</span>
                </div>`;
        });

        // Rebuild items list
        // Remove old item rows but keep the empty message element
        itemsDiv.innerHTML = '';
        if (hasItems) {
            itemsDiv.innerHTML = itemsHTML;
            if (emptyMsg) emptyMsg.style.display = 'none';
        } else {
            itemsDiv.innerHTML = '<div class="summary-empty" id="sumEmpty">No items selected yet.</div>';
        }

        // Update totals
        document.getElementById('sumSubtotal').innerText = fmt(subtotal);
        document.getElementById('sumFee').innerText      = fmt(fee);
        document.getElementById('sumTotal').innerText    = fmt(subtotal + fee);

        // Update delivery label
        document.getElementById('sumDelivery').innerText = delivery;
    }

    function addItemRow() {
        // Clone the options from the first select to reuse
        const firstSelect  = document.querySelector('.item-row .item-select');
        const optionsClone = firstSelect.innerHTML;

        const tpl = `
        <div class="item-row">
            <div class="form-group">
                <select class="input-control item-select" name="product_id[]" required onchange="onItemChange(this)">
                    ${optionsClone}
                </select>
            </div>
            <div class="form-group">
                <input type="number" class="input-control item-qty" name="quantity[]" value="1" min="1" required oninput="onQtyChange(this)">
            </div>
            <div class="form-group">
                <input type="text" class="input-control item-subtotal" value="₱0.00" readonly>
            </div>
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove item">
                <i data-lucide="trash-2" size="18"></i>
            </button>
        </div>`;

        document.getElementById('itemsContainer').insertAdjacentHTML('beforeend', tpl);

        // Reset the new select to placeholder
        const rows    = document.querySelectorAll('.item-row');
        const newRow  = rows[rows.length - 1];
        newRow.querySelector('.item-select').selectedIndex = 0;

        lucide.createIcons();
        updateAllDropdowns();
        saveDraft();
    }

    function removeRow(btn) {
        const rows = document.querySelectorAll('.item-row');
        if (rows.length <= 1) {
            const row = btn.closest('.item-row');
            row.querySelector('.item-select').selectedIndex = 0;
            row.querySelector('.item-qty').value = 1;
            row.querySelector('.item-subtotal').value = '₱0.00';

            updateAllDropdowns();
            refreshSummary();
            return;
        }
        btn.closest('.item-row').remove();
        updateAllDropdowns();
        refreshSummary();
        saveDraft();
    }

    // Run once on page load to set initial state
    refreshSummary();

    // ══════════════════════════════════════════════════════════
    // localStorage: persist form state across module navigation
    // ══════════════════════════════════════════════════════════
    const DRAFT_KEY     = 'jc_order_draft_<?php echo (int)$franchiseeId; ?>';
    const wasSubmitted  = <?php echo ($submitMsg === 'success') ? 'true' : 'false'; ?>;

    // Clear draft immediately after a successful submit
    if (wasSubmitted) {
        try { localStorage.removeItem(DRAFT_KEY); } catch(e) {}
    }

    // Save current form state into localStorage
    function saveDraft() {
        if (wasSubmitted) return;
        try {
            const items = [];
            document.querySelectorAll('.item-row').forEach(row => {
                const sel = row.querySelector('.item-select');
                if (sel && sel.value) {
                    items.push({
                        pid: sel.value,
                        qty: row.querySelector('.item-qty')?.value || '1'
                    });
                }
            });
            const draft = {
                items,
                delivery: document.querySelector('input[name="delivery"]:checked')?.value || 'Standard Delivery',
                payment:  document.querySelector('input[name="payment_method"]:checked')?.value || 'Cash',
                };
            localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
        } catch(e) {}
    }

    // ── Check for item-usage handoff ────────────────────────────
    const USAGE_HANDOFF_KEY = 'jc_usage_to_order_<?php echo (int)$franchiseeId; ?>';
    const fromUsage = new URLSearchParams(window.location.search).get('from_usage') === '1';

    if (fromUsage) {
        // Clear URL param without reload
        history.replaceState({}, '', 'order-form.php');
        // Load handoff items into the draft key so restoreDraft picks them up
        try {
            const handoff = JSON.parse(localStorage.getItem(USAGE_HANDOFF_KEY) || 'null');
            if (handoff?.items?.length) {
                // Check not stale (within 5 minutes)
                if (Date.now() - (handoff.ts || 0) < 5 * 60 * 1000) {
                    // Write into the draft key so restoreDraft handles it
                    localStorage.setItem(DRAFT_KEY, JSON.stringify({
                        items: handoff.items,
                        delivery: 'Standard Delivery',
                        payment: 'Cash',
                    }));
                }
                localStorage.removeItem(USAGE_HANDOFF_KEY);
            }
        } catch(e) {}
    }

    // Restore saved draft on page load
    function restoreDraft() {
        if (wasSubmitted) return;
        try {
            const raw = localStorage.getItem(DRAFT_KEY);
            if (!raw) return;
            const draft = JSON.parse(raw);
            if (!draft?.items?.length) return;

            const container = document.getElementById('itemsContainer');
            // Clear all existing rows first
            container.innerHTML = '';

            // Get options HTML from the page (products are rendered in PHP)
            // We need a fresh set of options — clone from a hidden template select
            const templateSelect = document.getElementById('productOptionsTemplate');
            if (!templateSelect) return;
            const optHTML = templateSelect.innerHTML;

            draft.items.forEach(item => {
                const row = document.createElement('div');
                row.className = 'item-row';
                row.innerHTML = `
                    <div class="form-group">
                        <select class="input-control item-select" name="product_id[]" required onchange="onItemChange(this)">
                            ${optHTML}
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="number" class="input-control item-qty" name="quantity[]" value="${parseInt(item.qty)||1}" min="1" required oninput="onQtyChange(this)">
                    </div>
                    <div class="form-group">
                        <input type="text" class="input-control item-subtotal" value="₱0.00" readonly>
                    </div>
                    <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove item">
                        <i data-lucide="trash-2" size="18"></i>
                    </button>`;
                container.appendChild(row);

                // Set the correct product
                const sel = row.querySelector('.item-select');
                sel.value = item.pid;
                if (sel.value) onItemChange(sel);
            });

            // Restore delivery
            const delRadio = document.querySelector(`input[name="delivery"][value="${draft.delivery}"]`);
            if (delRadio) { delRadio.checked = true; onDeliveryChange(); }

            // Restore payment method
            const payRadio = document.querySelector(`input[name="payment_method"][value="${draft.payment}"]`);
            if (payRadio) { payRadio.checked = true; onPayChange(payRadio); }


            lucide.createIcons();
            updateAllDropdowns();
            refreshSummary();
        } catch(e) {
            console.warn('Draft restore failed:', e);
        }
    }

    // Hook save into all interactions
    document.getElementById('itemsContainer').addEventListener('change', saveDraft);
    document.getElementById('itemsContainer').addEventListener('input',  saveDraft);
    document.querySelectorAll('input[name="delivery"]').forEach(r => r.addEventListener('change', saveDraft));
    document.querySelectorAll('input[name="payment_method"]').forEach(r => r.addEventListener('change', saveDraft));

    // Restore after page fully loads
    setTimeout(restoreDraft, 100);

    // ── Payment method toggle ──────────────────────────────────
    function onPayChange(radio) {
        document.getElementById('sumPayment').innerText = radio.value;
    }

    // ── Open Review Modal: populate then show ─────────────────
    function openReviewModal() {
        const rows = document.querySelectorAll('.item-row');
        let hasItem = false;
        rows.forEach(r => {
            const sel = r.querySelector('.item-select');
            const opt = sel ? sel.options[sel.selectedIndex] : null;
            if (opt && opt.dataset.price) hasItem = true;
        });
        if (!hasItem) {
            alert('Please select at least one item before reviewing your order.');
            return;
        }

        // Check for out-of-stock items — show fix modal if any found
        const outOfStockRows = [];
        rows.forEach(r => {
            const sel   = r.querySelector('.item-select');
            const opt   = sel ? sel.options[sel.selectedIndex] : null;
            const stock = parseInt(opt?.dataset?.stock ?? -1);
            if (opt && opt.value && stock === 0) {
                outOfStockRows.push({ row: r, name: opt.dataset.name, unit: opt.dataset.unit });
            }
        });
        if (outOfStockRows.length > 0) {
            openStockFixModal(outOfStockRows);
            return;
        }



        // Fill in order info
        const branchEl = document.getElementById('displayBranch');
        const fnameEl  = document.getElementById('displayFranchisee');
        document.getElementById('rev-branch').innerText     = branchEl ? branchEl.value : '—';
        document.getElementById('rev-franchisee').innerText = fnameEl  ? fnameEl.value  : '—';
        document.getElementById('rev-delivery').innerText   = document.querySelector('input[name="delivery"]:checked')?.value || '—';
        document.getElementById('rev-payment').innerText    = document.querySelector('input[name="payment_method"]:checked')?.value || 'Cash';

        // Build items list
        let itemsHTML = '';
        let subtotal  = 0;
        rows.forEach(r => {
            const sel   = r.querySelector('.item-select');
            const opt   = sel ? sel.options[sel.selectedIndex] : null;
            const qty   = parseInt(r.querySelector('.item-qty')?.value) || 0;
            const price = parseFloat(opt?.dataset?.price || 0);
            const name  = opt?.dataset?.name || '';
            if (!price || !qty || !name) return;
            const line = price * qty;
            subtotal  += line;
            itemsHTML += `<div class="review-row">
                <span>${name} ×${qty}</span>
                <span style="font-weight:600;">${fmt(line)}</span>
            </div>`;
        });

        const delivery = document.querySelector('input[name="delivery"]:checked')?.value || '';
        const fee      = (delivery === 'Self Pickup') ? 0 : 250;
        const total    = subtotal + fee;

        itemsHTML += `<div class="review-row" style="color:var(--muted);">
            <span>Delivery Fee</span><span>${fmt(fee)}</span>
        </div>`;

        document.getElementById('rev-items').innerHTML = itemsHTML || '<p style="color:var(--muted);font-size:.9rem;">No items selected.</p>';
        document.getElementById('rev-total').innerText = fmt(total);

        // Update the proceed button label based on selected payment method
        const method = document.querySelector('input[name="payment_method"]:checked')?.value || 'Cash';
        const proceedBtn = document.getElementById('reviewProceedBtn');
        if (proceedBtn) proceedBtn.textContent = (method === 'Cash') ? '✓ Confirm & Submit' : '→ Confirm & Payment';

        document.getElementById('reviewModal').classList.add('open');
        lucide.createIcons();
    }

    function closeReview() {
        document.getElementById('reviewModal').classList.remove('open');
    }

    function submitConfirmed() {
        try { localStorage.removeItem(DRAFT_KEY); } catch(e) {}
        document.getElementById('reviewModal').classList.remove('open');
        document.getElementById('orderForm').submit();
    }

    // Close modal on backdrop click
    document.getElementById('reviewModal').addEventListener('click', function(e) {
        if (e.target === this) closeReview();
    });

    // ── Out-of-stock Fix Modal ─────────────────────────────────
    function openStockFixModal(outItems) {
        const body = document.getElementById('stockfix-items');
        body.innerHTML = outItems.map((item, idx) => `
            <div class="stockfix-item" id="sfitem-${idx}">
                <div>
                    <div class="stockfix-item-name">${item.name} <span style="font-weight:400;font-size:.78rem;">(${item.unit})</span></div>
                    <div class="stockfix-item-note">Currently out of stock — cannot be ordered</div>
                </div>
                <button class="stockfix-remove" onclick="removeOutOfStockItem(${idx})">Remove</button>
            </div>
        `).join('');

        // Store refs so we can remove rows
        window._sfOutItems = outItems;
        document.getElementById('stockFixModal').classList.add('open');
    }

    function closeStockFixModal() {
        document.getElementById('stockFixModal').classList.remove('open');
        window._sfOutItems = [];
    }

    function removeOutOfStockItem(idx) {
        const item = window._sfOutItems[idx];
        if (!item) return;

        // Remove the actual item row from the form
        item.row.remove();
        updateAllDropdowns();
        refreshSummary();

        // Remove from the fix modal list
        const el = document.getElementById(`sfitem-${idx}`);
        if (el) el.remove();

        // If no more out-of-stock items remain in the modal, close it and proceed to review
        const remaining = document.querySelectorAll('.stockfix-item');
        if (remaining.length === 0) {
            closeStockFixModal();
            // Small delay so the removal animation settles, then re-trigger review
            setTimeout(openReviewModal, 150);
        }
    }

    function removeAllOutOfStock() {
        if (!window._sfOutItems) return;
        window._sfOutItems.forEach(item => {
            if (item.row && item.row.parentNode) item.row.remove();
        });
        updateAllDropdowns();
        refreshSummary();
        closeStockFixModal();
        setTimeout(openReviewModal, 150);
    }

    document.getElementById('stockFixModal').addEventListener('click', function(e) {
        if (e.target === this) closeStockFixModal();
    });

    // ── Double-authenticator payment flow ──────────────────────
    function onReviewProceed() {
        const method = document.querySelector('input[name="payment_method"]:checked')?.value || 'Cash';
        if (method === 'Cash') {
            submitConfirmed();
        } else {
            openPayConfirm(method);
        }
    }

    window._timerEnd = 0;
    window._timerInt = null;

    function startPayTimer() {
        if (window._timerInt) clearInterval(window._timerInt);
        window._timerEnd = Date.now() + 300000; // 5 min in ms

        window._timerInt = setInterval(function() {
            var now  = Date.now();
            var ms   = window._timerEnd - now;
            if (ms < 0) ms = 0;
            var sec  = Math.ceil(ms / 1000);
            var m    = Math.floor(sec / 60);
            var s    = sec % 60;
            var pct  = (ms / 300000 * 100);

            var el = document.getElementById('pcTimerDigits');
            var bar = document.getElementById('pcTimerFill');
            var wrap = document.getElementById('pcTimerBar');

            if (el)   el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            if (bar)  { bar.style.width = pct + '%'; bar.style.background = sec <= 60 ? '#dc2626' : sec <= 120 ? '#f59e0b' : '#5c4033'; }
            if (wrap) { if (sec <= 60) wrap.classList.add('urgent'); else wrap.classList.remove('urgent'); }

            if (ms <= 0) {
                clearInterval(window._timerInt);
                window._timerInt = null;
                closePayConfirm();
                setTimeout(function() { alert('Time expired. Please try again.'); }, 200);
            }
        }, 1000);
    }

    function stopPayTimer() {
        if (window._timerInt) { clearInterval(window._timerInt); window._timerInt = null; }
        var el  = document.getElementById('pcTimerDigits');
        var bar = document.getElementById('pcTimerFill');
        if (el)  el.textContent = '5:00';
        if (bar) { bar.style.width = '100%'; bar.style.background = '#5c4033'; }
    }

    function openPayConfirm(method) {
        // Set icon
        const icon = document.getElementById('pcIcon');
        if (icon) {
            icon.textContent = method === 'GCash' ? '📱' : '💳';
            icon.className   = 'payconfirm-icon ' + (method === 'GCash' ? 'gcash' : 'card');
        }
        // Set method name in header
        const pcName = document.getElementById('pcMethodName');
        if (pcName) pcName.textContent = method;
        // Set other method labels safely
        const pcMethod = document.getElementById('pc-method');
        if (pcMethod) pcMethod.textContent = method;
        const pcWarn = document.getElementById('pc-method-warn');
        if (pcWarn) pcWarn.textContent = method;
        // Set summary values from review modal
        const pcBranch = document.getElementById('pc-branch');
        if (pcBranch) pcBranch.textContent = document.getElementById('rev-branch')?.textContent || '—';
        const pcTotal = document.getElementById('pc-total');
        if (pcTotal) pcTotal.textContent = document.getElementById('rev-total')?.textContent || '—';
        const pcDel = document.getElementById('pc-delivery');
        if (pcDel) pcDel.textContent = document.getElementById('rev-delivery')?.textContent || '—';
        // Reset fields
        const refInput = document.getElementById('pcRefNumber');
        if (refInput) refInput.value = '';
        const fileInput = document.getElementById('pcScreenshot');
        if (fileInput) fileInput.value = '';
        const preview = document.getElementById('pcPreview');
        if (preview) preview.style.display = 'none';
        const submitBtn = document.getElementById('pcSubmitBtn');
        if (submitBtn) submitBtn.disabled = true;
        const note = document.getElementById('pcRequiredNote');
        if (note) note.style.display = 'none';
        // Show modal
        document.getElementById('payConfirmModal').classList.add('open');
        lucide.createIcons();
        startPayTimer();
    }

    function closePayConfirm() {
        document.getElementById('payConfirmModal').classList.remove('open');
        stopPayTimer();
        // Don't clear the screenshot on close — user may have uploaded correctly
        // Only clear if they explicitly click Remove Photo
    }

    document.getElementById('payConfirmModal').addEventListener('click', function(e) {
        if (e.target === this) closePayConfirm();
    });

    // Update review button label dynamically when payment changes
    document.querySelectorAll('input[name="payment_method"]').forEach(r => {
        r.addEventListener('change', function() {
            const btn = document.getElementById('reviewProceedBtn');
            if (btn) btn.textContent = (this.value === 'Cash') ? '✓ Confirm & Submit' : '→ Confirm & Payment';
        });
    });

    // ── Payment confirm modal: ref + screenshot validation ─────
    function onPcFieldChange() {
        const ref       = document.getElementById('pcRefNumber')?.value.trim();
        const hasFile   = document.getElementById('pcScreenshot')?.files?.length > 0;
        const submitBtn = document.getElementById('pcSubmitBtn');
        const note      = document.getElementById('pcRequiredNote');
        const ready     = ref && hasFile;
        if (submitBtn) submitBtn.disabled = !ready;
        if (note) note.style.display = ready ? 'none' : 'block';
    }

    function onPcScreenshot(input) {
        const preview = document.getElementById('pcPreview');
        const img     = document.getElementById('pcPreviewImg');
        const box     = document.getElementById('pcUploadBox');
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
                box.style.borderColor = 'var(--primary)';
            };
            reader.readAsDataURL(input.files[0]);
        }
        onPcFieldChange();
    }

    function removeScreenshot() {
        // Clear the file input
        const input   = document.getElementById('pcScreenshot');
        const preview = document.getElementById('pcPreview');
        const img     = document.getElementById('pcPreviewImg');
        const box     = document.getElementById('pcUploadBox');

        input.value          = '';
        img.src              = '';
        preview.style.display = 'none';
        box.style.borderColor = '';

        // Also clear the hidden form input if it was already created
        const formInput = document.getElementById('formFileInput');
        if (formInput) formInput.value = '';

        onPcFieldChange();
    }

    function submitWithPayment() {
        const ref     = document.getElementById('pcRefNumber')?.value.trim();
        const hasFile = document.getElementById('pcScreenshot')?.files?.length > 0;

        if (!ref || !hasFile) {
            document.getElementById('pcRequiredNote').style.display = 'block';
            return;
        }

        // Copy ref number into the hidden form field
        const hiddenRef = document.getElementById('hiddenPayRef');
        if (hiddenRef) hiddenRef.value = ref;

        // Transfer file from the modal input into a hidden file input INSIDE the form
        // so it gets submitted with the form POST
        const srcInput = document.getElementById('pcScreenshot');
        let destInput  = document.getElementById('formFileInput');
        if (!destInput) {
            destInput      = document.createElement('input');
            destInput.type = 'file';
            destInput.name = 'payment_screenshot';
            destInput.id   = 'formFileInput';
            destInput.style.display = 'none';
            document.getElementById('orderForm').appendChild(destInput);
        }
        // Use DataTransfer to clone the file into the form's input
        const dt = new DataTransfer();
        dt.items.add(srcInput.files[0]);
        destInput.files = dt.files;

        try { localStorage.removeItem(DRAFT_KEY); } catch(e) {}
        document.getElementById('orderForm').submit();
    }
</script>

<!-- ── Out-of-stock Fix Modal ── -->
<div id="stockFixModal">
    <div class="stockfix-box">
        <div class="stockfix-header">
            <div class="sf-icon">⚠️</div>
            <div>
                <h3>Some Items Are Out of Stock</h3>
                <p>Please remove them before submitting your order.</p>
            </div>
        </div>
        <div class="stockfix-body">
            <div id="stockfix-items"></div>
        </div>
        <div class="stockfix-footer">
            <button class="sf-btn-cancel" onclick="closeStockFixModal()">← Edit Order</button>
            <button class="sf-btn-proceed" onclick="removeAllOutOfStock()">Remove All & Continue</button>
        </div>
    </div>
</div>
<!-- ── Payment Confirmation Modal (2nd check for GCash/Card) ── -->
<div id="payConfirmModal">
    <div class="payconfirm-box" style="max-height:92vh;overflow-y:auto;">
        <div class="payconfirm-header">
            <div class="payconfirm-icon" id="pcIcon">💳</div>
            <h3>Confirm <span id="pcMethodName">GCash</span> Payment</h3>
            <p>Fill in your payment details below before submitting your order.</p>
        </div>

        <!-- 5-minute countdown timer -->
        <div class="pc-timer-bar" id="pcTimerBar">
            <i data-lucide="clock" size="13"></i>
            <span>Time remaining:</span>
            <span class="pc-timer-digits" id="pcTimerDigits">5:00</span>
        </div>
        <div class="pc-timer-track">
            <div class="pc-timer-fill" id="pcTimerFill" style="width:100%;"></div>
        </div>

        <div class="payconfirm-summary">
            <div class="payconfirm-row"><span class="pc-label">Branch</span><span class="pc-value" id="pc-branch">—</span></div>
            <div class="payconfirm-row"><span class="pc-label">Total Amount</span><span class="pc-value" id="pc-total">—</span></div>
            <div class="payconfirm-row"><span class="pc-label">Delivery</span><span class="pc-value" id="pc-delivery">—</span></div>
            <div class="payconfirm-row"><span class="pc-label">Payment Method</span><span class="pc-value" id="pc-method">—</span></div>
        </div>

        <!-- Reference number + screenshot upload -->
        <div class="payconfirm-fields">
            <label>Reference / Transaction Number <span style="color:#ef4444;">*</span></label>
            <input type="text" id="pcRefNumber" placeholder="Enter your GCash / bank reference number" oninput="onPcFieldChange()">

            <label>Payment Screenshot <span style="color:#ef4444;">*</span></label>
            <div class="pc-upload-box" id="pcUploadBox">
                <label for="pcScreenshot">
                    <span class="pc-u-icon">📸</span>
                    <span class="pc-u-text">Tap to upload payment screenshot</span>
                    <span class="pc-u-sub">JPG, PNG — max 5MB</span>
                    <input type="file" id="pcScreenshot" name="payment_screenshot" accept="image/*" onchange="onPcScreenshot(this)">
                </label>
            </div>
            <div class="pc-preview" id="pcPreview">
                <img id="pcPreviewImg" src="" alt="Payment screenshot preview">
                <button type="button" class="pc-remove-btn" onclick="removeScreenshot()">
                    🗑 Remove Photo
                </button>
            </div>
            <p class="pc-required-note" id="pcRequiredNote" style="display:none;">⚠ Please enter a reference number and upload a screenshot to proceed.</p>
        </div>

        <div class="payconfirm-warn">
            <i data-lucide="alert-triangle" size="14" style="flex-shrink:0;margin-top:.1rem;"></i>
            <span>Make sure your reference number and screenshot are correct. The encoder will verify your <span id="pc-method-warn">GCash</span> payment before processing your order.</span>
        </div>

        <div class="payconfirm-footer">
            <button class="pc-back" onclick="closePayConfirm()">← Go Back</button>
            <button class="pc-confirm" id="pcSubmitBtn" onclick="submitWithPayment()" disabled>✓ Submit Order</button>
        </div>
    </div>
</div>

</body>
</html>