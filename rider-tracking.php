<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: index.php');
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php'; // must create $pdo

// Auto-add missing columns
try {
    $pdo->exec("ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `delivery_status` VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `pod_photo` VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `rider_id` BIGINT(20) NULL");
} catch (PDOException $e) {
    // ignore if already exists / unsupported
}

$riderId = (int)$_SESSION['user_id'];
$riderName = $_SESSION['full_name'] ?? 'Delivery Rider';

$actionMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $poNum = trim($_POST['po'] ?? '');
    $action = trim($_POST['action'] ?? '');

    if ($poNum && in_array($action, ['pickedup', 'intransit', 'complete'], true)) {
        $stmt = $pdo->prepare("SELECT id, status_step FROM orders WHERE po_number = ? AND rider_id = ?");
        $stmt->execute([$poNum, $riderId]);
        $ord = $stmt->fetch();

        if ($ord) {
            $orderId = (int)$ord['id'];

            if ($action === 'pickedup') {
                $label = 'Picked Up';
                $deliveryLabel = 'picked_up';
                $detail = 'Order collected from warehouse by delivery rider.';

                $upd = $pdo->prepare("UPDATE orders SET delivery_status = ? WHERE id = ?");
                $upd->execute([$deliveryLabel, $orderId]);

            } elseif ($action === 'intransit') {
                $label = 'In Transit';
                $deliveryLabel = 'in_transit';
                $detail = 'Order is en route to the franchisee branch.';

                $upd = $pdo->prepare("UPDATE orders SET delivery_status = ? WHERE id = ?");
                $upd->execute([$deliveryLabel, $orderId]);

            } elseif ($action === 'complete') {
                if (empty($_FILES['pod_photo']['tmp_name'])) {
                    $errorMsg = 'Please attach a proof-of-delivery photo before confirming.';
                } else {
                    $file = $_FILES['pod_photo'];
                    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    $maxBytes = 5 * 1024 * 1024;

                    if (!in_array($file['type'], $allowed, true)) {
                        $errorMsg = 'Invalid file type. Please upload a JPEG, PNG, or WebP image.';
                    } elseif ($file['size'] > $maxBytes) {
                        $errorMsg = 'Photo is too large. Maximum size is 5 MB.';
                    } else {
                        $uploadDir = __DIR__ . '/uploads/pod/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $filename = 'pod_' . $orderId . '_' . time() . '.' . $ext;
                        $dest = $uploadDir . $filename;

                        if (!move_uploaded_file($file['tmp_name'], $dest)) {
                            $errorMsg = 'Failed to save photo. Please try again.';
                        } else {
                            $podPath = 'uploads/pod/' . $filename;
                            $label = 'Delivered';
                            $deliveryLabel = 'delivered';
                            $newStep = 4;
                            $newStatus = 'completed';
                            $detail = 'Order successfully delivered to franchisee.';

                            $upd = $pdo->prepare("
                                UPDATE orders
                                SET `status` = ?, status_step = ?, delivery_status = ?, pod_photo = ?
                                WHERE id = ?
                            ");
                            $upd->execute([$newStatus, $newStep, $deliveryLabel, $podPath, $orderId]);

                            $ins = $pdo->prepare("
                                INSERT INTO order_status_history
                                    (order_id, status_step, status_label, detail, changed_at, changed_by)
                                VALUES (?, ?, ?, ?, NOW(), ?)
                            ");
                            $ins->execute([$orderId, 4, $label, $detail, $riderId]);

                            header("Location: rider-tracking.php?done=1");
                            exit();
                        }
                    }
                }
            }

            if (!$errorMsg && $action !== 'complete') {
                $ins = $pdo->prepare("
                    INSERT INTO order_status_history
                        (order_id, status_step, status_label, detail, changed_at, changed_by)
                    VALUES (?, ?, ?, ?, NOW(), ?)
                ");
                $ins->execute([$orderId, 3, $label, $detail, $riderId]);

                $actionMsg = $label;
            }
        }
    }
}

$selectedPO = trim($_GET['po'] ?? $_POST['po'] ?? '');
$order = null;
$orderItems = [];
$history = [];

$myOrders = [];

$stmt = $pdo->prepare("
    SELECT o.id, o.po_number, o.delivery_preference, o.total_amount,
           o.estimated_pickup, o.delivery_status,
           COALESCE(f.franchisee_name, uf.full_name, '—') AS franchisee_name,
           f.branch_name
    FROM orders o
    LEFT JOIN franchisees f ON f.id = o.franchisee_id
    LEFT JOIN users uf ON uf.user_id = f.user_id
    WHERE o.rider_id = ?
      AND o.status_step = 3
      AND o.delivery_status != 'delivered'
    ORDER BY o.estimated_pickup ASC, o.created_at ASC
");
$stmt->execute([$riderId]);
$myOrders = $stmt->fetchAll();

if ($selectedPO) {
    $stmt = $pdo->prepare("
        SELECT o.*, f.branch_name,
               COALESCE(f.franchisee_name, uf.full_name, '—') AS franchisee_name
        FROM orders o
        LEFT JOIN franchisees f ON f.id = o.franchisee_id
        LEFT JOIN users uf ON uf.user_id = f.user_id
        WHERE o.po_number = ? AND o.rider_id = ?
    ");
    $stmt->execute([$selectedPO, $riderId]);
    $order = $stmt->fetch();

    if ($order) {
        $stmt = $pdo->prepare("
            SELECT oi.quantity, oi.unit_price, oi.subtotal, p.name, p.unit
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
        ");
        $stmt->execute([$order['id']]);
        $orderItems = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT status_label, detail, changed_at
            FROM order_status_history
            WHERE order_id = ?
            ORDER BY changed_at DESC
        ");
        $stmt->execute([$order['id']]);
        $history = $stmt->fetchAll();
    }
}

$deliveryStatus = $order['delivery_status'] ?? '';
$isPickedUp = in_array($deliveryStatus, ['picked_up', 'in_transit', 'delivered'], true);
$isInTransit = in_array($deliveryStatus, ['in_transit', 'delivered'], true);
$isCompleted = $deliveryStatus === 'delivered' || ($order['status'] ?? '') === 'completed';

function deliveryBadge(string $ds): array {
    return match($ds) {
        'accepted' => ['label' => 'Accepted', 'color' => '#3b82f6', 'bg' => '#dbeafe'],
        'picked_up' => ['label' => 'Picked Up', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
        'in_transit' => ['label' => 'In Transit', 'color' => '#8b5cf6', 'bg' => '#ede9fe'],
        'delivered' => ['label' => 'Delivered', 'color' => '#10b981', 'bg' => '#dcfce7'],
        default => ['label' => 'Pending', 'color' => '#6b7280', 'bg' => '#f3f4f6'],
    };
}
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
        .header{margin-bottom:2rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);}

        /* ── My Deliveries list ── */
        .deliveries-list{display:flex;flex-direction:column;gap:1rem;margin-bottom:2rem;}
        .delivery-row{background:white;border:1px solid var(--card-border);border-radius:16px;padding:1.25rem 1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;text-decoration:none;color:var(--foreground);transition:box-shadow .2s,border-color .2s;}
        .delivery-row:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);border-color:var(--primary);}
        .dr-left{display:flex;flex-direction:column;gap:.2rem;}
        .dr-po{font-weight:700;color:var(--primary);font-size:.95rem;}
        .dr-branch{font-size:.88rem;font-weight:600;}
        .dr-sub{font-size:.78rem;color:var(--muted);}
        .dr-right{display:flex;align-items:center;gap:.75rem;flex-shrink:0;}
        .status-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .75rem;border-radius:999px;font-size:.75rem;font-weight:700;line-height:1;white-space:nowrap;}
        .status-badge i{width:12px;height:12px;flex-shrink:0;}
        .dr-arrow{color:var(--muted);}
        .empty-state{text-align:center;padding:3rem 2rem;background:white;border:1px solid var(--card-border);border-radius:20px;color:var(--muted);}
        .empty-state h4{color:var(--foreground);margin:.75rem 0 .5rem;font-family:'Fraunces',serif;}
        .section-title{font-family:'Fraunces',serif;font-size:1.2rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;}

        /* ── Detail view ── */
        .btn-back{display:inline-flex;align-items:center;gap:.5rem;color:var(--primary);text-decoration:none;font-weight:600;font-size:.9rem;margin-bottom:1.5rem;}
        .po-badge{background:var(--background);border:1px solid var(--card-border);padding:.5rem 1rem;border-radius:10px;font-weight:700;color:var(--primary);}
        .header-detail{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:2rem;}
        .tracking-grid{display:grid;grid-template-columns:1fr 380px;gap:2rem;align-items:start;}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:2rem;margin-bottom:1.5rem;}
        .card:last-child{margin-bottom:0;}
        .card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;}
        .card-header h3{font-family:'Fraunces',serif;font-size:1.25rem;}
        .status-stepper{display:flex;flex-direction:column;gap:1rem;}
        .status-btn{display:flex;align-items:center;gap:1rem;padding:1.25rem;border-radius:16px;border:2px solid var(--card-border);background:white;text-align:left;width:100%;font-family:inherit;}
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
        .pod-upload-area{border:2px dashed var(--card-border);border-radius:16px;padding:1.5rem;text-align:center;cursor:pointer;transition:all .2s;background:#fdfaf7;margin-bottom:1.25rem;}
        .pod-upload-area:hover,.pod-upload-area.dragover{border-color:var(--primary);background:rgba(92,64,51,.04);}
        .pod-upload-area .upload-icon{display:block;margin:0 auto .75rem;color:var(--muted);}
        .pod-upload-area p{font-size:.875rem;color:var(--muted);margin-bottom:.25rem;}
        .pod-upload-area span{font-size:.75rem;color:var(--muted);opacity:.7;}
        #pod_photo{display:none;}
        .pod-preview{margin-bottom:1.25rem;border-radius:16px;overflow:hidden;border:1.5px solid var(--card-border);position:relative;background:#f7f3f0;}
        .pod-preview img{width:100%;display:block;max-height:480px;object-fit:contain;}
        .pod-preview-remove{position:absolute;top:.5rem;right:.5rem;background:rgba(0,0,0,.6);color:white;border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;}
        .pod-label{font-size:.85rem;font-weight:600;color:var(--muted);margin-bottom:.5rem;display:flex;align-items:center;gap:.35rem;}
        .pod-required{background:#fee2e2;color:#b91c1c;font-size:.7rem;padding:.15rem .45rem;border-radius:6px;font-weight:700;}
        .btn-action{width:100%;background:var(--primary);color:white;border:none;padding:1rem;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.95rem;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:background .2s;}
        .btn-action:hover{background:var(--primary-light);}
        .btn-delivered{background:var(--primary);}
        .btn-delivered:hover{background:var(--primary-light);opacity:1;}
        .detail-row{display:flex;justify-content:space-between;margin-bottom:.875rem;font-size:.9rem;}
        .detail-row .dl{color:var(--muted);}
        .detail-row .dv{font-weight:600;}
        .items-list{margin-top:1rem;border-top:1px solid var(--card-border);padding-top:1rem;}
        .item-line{display:flex;justify-content:space-between;font-size:.875rem;padding:.4rem 0;border-bottom:1px dashed var(--card-border);}
        .item-line:last-child{border-bottom:none;}

        .alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:.875rem 1rem;border-radius:10px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.9rem;}
        .alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;padding:.875rem 1rem;border-radius:10px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.9rem;}
        .pod-completed{margin-top:1rem;border-radius:12px;overflow:hidden;border:1.5px solid #86efac;background:#f0fdf4;}
        .pod-completed img{width:100%;display:block;max-height:480px;object-fit:contain;}
        .pod-completed-label{font-size:.78rem;color:#166534;font-weight:600;padding:.4rem .75rem;background:#dcfce7;display:flex;align-items:center;gap:.35rem;}
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

<?php if ($order && $selectedPO): ?>
    <?php /* ══════════════ DETAIL VIEW ══════════════ */ ?>

    <a href="rider-tracking.php" class="btn-back"><i data-lucide="arrow-left" size="16"></i> My Deliveries</a>

    <div class="header-detail">
        <div>
            <h2 style="font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;">Delivery Status Update</h2>
            <p style="color:var(--muted);"><?php echo htmlspecialchars($order['franchisee_name'] ?? '—'); ?> — <?php echo htmlspecialchars($order['branch_name'] ?? '—'); ?></p>
        </div>
        <span class="po-badge"><?php echo htmlspecialchars($order['po_number']); ?></span>
    </div>

    <?php if ($actionMsg): ?>
    <div class="alert-success"><i data-lucide="check-circle" size="18"></i><span>Status updated to <strong><?php echo htmlspecialchars($actionMsg); ?></strong>.</span></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
    <div class="alert-error"><i data-lucide="alert-circle" size="18"></i><span><?php echo htmlspecialchars($errorMsg); ?></span></div>
    <?php endif; ?>
    <?php if (isset($_GET['done'])): ?>
    <div class="alert-success"><i data-lucide="check-circle" size="18"></i><span>Order marked as delivered successfully.</span></div>
    <?php endif; ?>

    <div class="tracking-grid">
        <div>
            <div class="card">
                <div class="card-header"><h3>Update Progress</h3></div>

                <?php if ($isCompleted): ?>
                <div style="background:#f0fdf4;border-radius:12px;padding:1.5rem;text-align:center;color:#166534;">
                    <i data-lucide="check-circle" size="32" style="display:block;margin:0 auto .75rem;"></i>
                    <strong style="font-size:1.1rem;">Delivery Completed</strong><br>
                    <span style="font-size:.875rem;">This order has been successfully delivered.</span>
                </div>
                <?php if (!empty($order['pod_photo'])): ?>
                <div class="pod-completed" style="margin-top:1.25rem;">
                    <div class="pod-completed-label"><i data-lucide="camera" size="13"></i> Proof of Delivery</div>
                    <?php
                        $podSrc = $order['pod_photo'];
                        if (!str_starts_with($podSrc, '/') && !str_starts_with($podSrc, 'http')) {
                            $podSrc = '/thesis/' . $podSrc;
                        }
                    ?>
                    <img src="<?php echo htmlspecialchars($podSrc); ?>" alt="Proof of Delivery" style="max-width:100%;border-radius:8px;">
                </div>
                <?php endif; ?>

                <?php else: ?>

                <div class="status-stepper">
                    <div class="status-btn <?php echo $isPickedUp ? 'done' : 'current'; ?>">
                        <div class="sb-icon"><i data-lucide="package-check" size="22"></i></div>
                        <div>
                            <h4>Picked Up</h4>
                            <p><?php echo $isPickedUp ? 'Collected from warehouse ✓' : 'Mark when collected from warehouse'; ?></p>
                        </div>
                    </div>
                    <div class="status-btn <?php echo $isInTransit ? 'done' : ($isPickedUp ? 'current' : ''); ?>">
                        <div class="sb-icon"><i data-lucide="truck" size="22"></i></div>
                        <div>
                            <h4>In Transit</h4>
                            <p><?php echo $isInTransit ? 'En route to destination ✓' : 'Mark when heading to branch'; ?></p>
                        </div>
                    </div>
                    <div class="status-btn">
                        <div class="sb-icon"><i data-lucide="flag" size="22"></i></div>
                        <div>
                            <h4>Delivered</h4>
                            <p>Mark when handed over to franchisee</p>
                        </div>
                    </div>
                </div>

                <form method="POST" action="rider-tracking.php?po=<?php echo urlencode($order['po_number']); ?>"
                      enctype="multipart/form-data" style="margin-top:1.5rem;" id="deliveryForm">
                    <input type="hidden" name="po" value="<?php echo htmlspecialchars($order['po_number']); ?>">

                    <?php if (!$isPickedUp): ?>
                    <button type="submit" name="action" value="pickedup" class="btn-action">
                        <i data-lucide="package-check" size="18"></i> Mark as Picked Up
                    </button>

                    <?php elseif (!$isInTransit): ?>
                    <button type="submit" name="action" value="intransit" class="btn-action">
                        <i data-lucide="truck" size="18"></i> Mark as In Transit
                    </button>

                    <?php else: ?>
                    <div class="pod-label">
                        <i data-lucide="camera" size="15"></i> Proof of Delivery Photo
                        <span class="pod-required">REQUIRED</span>
                    </div>
                    <div class="pod-preview" id="podPreview" style="display:none;">
                        <img id="podPreviewImg" src="" alt="Preview">
                        <button type="button" class="pod-preview-remove" id="podRemoveBtn" title="Remove photo">
                            <i data-lucide="x" size="14"></i>
                        </button>
                    </div>
                    <div class="pod-upload-area" id="podUploadArea">
                        <i data-lucide="upload-cloud" size="36" class="upload-icon"></i>
                        <p>Tap to take a photo or choose from gallery</p>
                        <span>JPEG / PNG / WebP — max 5 MB</span>
                    </div>
                    <input type="file" name="pod_photo" id="pod_photo" accept="image/*" capture="environment">
                    <button type="submit" name="action" value="complete"
                            class="btn-action btn-delivered" id="confirmBtn">
                        <i data-lucide="check-circle" size="18"></i> Confirm Delivery Complete
                    </button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>


        </div>

        <div class="card" style="position:sticky;top:2rem;">
            <div class="card-header"><h3>Order Details</h3></div>
            <div class="detail-row"><span class="dl">Franchisee</span><span class="dv"><?php echo htmlspecialchars($order['franchisee_name'] ?? '—'); ?></span></div>
            <div class="detail-row"><span class="dl">Branch</span><span class="dv"><?php echo htmlspecialchars($order['branch_name'] ?? '—'); ?></span></div>
            <div class="detail-row"><span class="dl">Delivery Type</span><span class="dv"><?php echo htmlspecialchars($order['delivery_preference']); ?></span></div>
            <div class="detail-row"><span class="dl">Payment Method</span>
                <span class="dv">
                <?php
                $pm = strtolower($order['payment_method'] ?? '');
                $isCod = $pm === 'cod';
                echo '<span style="background:' . ($isCod ? '#fef3c7' : '#dcfce7') . ';color:' . ($isCod ? '#92400e' : '#166534') . ';font-size:.75rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;">'
                    . ($isCod ? '💵 ' : '✓ ') . htmlspecialchars($order['payment_method'] ?? '—') . '</span>';
                ?>
                </span>
            </div>
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

<?php else: ?>
    <?php /* ══════════════ LIST VIEW ══════════════ */ ?>

    <div class="header">
        <h2>My Deliveries</h2>
        <p>Tap an order below to update its delivery status.</p>
    </div>

    <?php if (isset($_GET['done'])): ?>
    <div class="alert-success"><i data-lucide="check-circle" size="18"></i><span>Order marked as delivered successfully.</span></div>
    <?php endif; ?>

    <h3 class="section-title"><i data-lucide="map-pin" size="20"></i> Active Deliveries</h3>

    <?php if (empty($myOrders)): ?>
    <div class="empty-state">
        <i data-lucide="package" size="40" style="opacity:.2;display:block;margin:0 auto;"></i>
        <h4>No active deliveries</h4>
        <p>Accept orders from <a href="rider-assignment.php" style="color:var(--primary);font-weight:600;">Assignments</a> to see them here.</p>
    </div>
    <?php else: ?>
    <div class="deliveries-list">
        <?php foreach ($myOrders as $mo):
            $badge = deliveryBadge($mo['delivery_status'] ?? '');
        ?>
        <a href="rider-tracking.php?po=<?php echo urlencode($mo['po_number']); ?>" class="delivery-row">
            <div class="dr-left">
                <span class="dr-po"><?php echo htmlspecialchars($mo['po_number']); ?></span>
                <span class="dr-branch"><?php echo htmlspecialchars($mo['franchisee_name']); ?></span>
                <span class="dr-sub"><?php echo htmlspecialchars($mo['branch_name'] ?? '—'); ?>
                    <?php if ($mo['estimated_pickup']): ?> · Est. <?php echo date('M d', strtotime($mo['estimated_pickup'])); ?><?php endif; ?>
                </span>
            </div>
            <div class="dr-right">
                <span class="status-badge"
                      style="background:<?php echo $badge['bg']; ?>;color:<?php echo $badge['color']; ?>;">
                    <i data-lucide="circle" style="fill:<?php echo $badge['color']; ?>;stroke:none;"></i>
                    <?php echo $badge['label']; ?>
                </span>
                <i data-lucide="chevron-right" class="dr-arrow" size="18"></i>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>
</main>

<script>
lucide.createIcons();

(function () {
    const area       = document.getElementById('podUploadArea');
    const input      = document.getElementById('pod_photo');
    const preview    = document.getElementById('podPreview');
    const previewImg = document.getElementById('podPreviewImg');
    const removeBtn  = document.getElementById('podRemoveBtn');

    if (!area) return;

    area.addEventListener('click', () => input.click());
    area.addEventListener('dragover', e => { e.preventDefault(); area.classList.add('dragover'); });
    area.addEventListener('dragleave', () => area.classList.remove('dragover'));
    area.addEventListener('drop', e => {
        e.preventDefault(); area.classList.remove('dragover');
        if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
    });
    input.addEventListener('change', () => { if (input.files[0]) setFile(input.files[0]); });
    removeBtn.addEventListener('click', () => {
        input.value = '';
        previewImg.src = '';
        preview.style.display = 'none';
        area.style.display = '';
        lucide.createIcons();
    });

    document.getElementById('deliveryForm')?.addEventListener('submit', function (e) {
        if (e.submitter?.value === 'complete' && !input.files[0]) {
            e.preventDefault();

            // Remove any existing inline error first
            const existing = document.getElementById('podInlineError');
            if (existing) existing.remove();

            // Build styled error banner
            const err = document.createElement('div');
            err.id = 'podInlineError';
            err.style.cssText = [
                'display:flex','align-items:center','gap:.65rem',
                'background:#fff1f2','border:1.5px solid #fca5a5',
                'color:#b91c1c','border-radius:12px','padding:.9rem 1.1rem',
                'font-size:.875rem','font-weight:600','margin-bottom:1rem'
            ].join(';');
            err.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                + '<span>Proof of delivery photo is required. Please upload an image before confirming.</span>';

            // Insert the error banner just above the upload area
            area.parentNode.insertBefore(err, area);

            // Highlight the upload area border in red
            area.style.border = '2px dashed #ef4444';
            area.style.background = '#fff1f2';

            // Auto-clear the highlight when a file is chosen
            input.addEventListener('change', function clearErr() {
                const el = document.getElementById('podInlineError');
                if (el) el.remove();
                area.style.border = '';
                area.style.background = '';
                input.removeEventListener('change', clearErr);
            }, { once: true });

            err.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    function setFile(file) {
        const reader = new FileReader();
        reader.onload = ev => {
            previewImg.src = ev.target.result;
            preview.style.display = 'block';
            area.style.display = 'none';
            lucide.createIcons();
        };
        reader.readAsDataURL(file);
        if (file !== input.files[0]) {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        }
    }
})();
</script>
</body>
</html>