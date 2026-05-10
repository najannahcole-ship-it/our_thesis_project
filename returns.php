<?php
// ============================================================
// returns.php — Franchisee Return Requests
// DB Tables used:
//   READ  → franchisees (get franchisee_id for logged-in user)
//   READ  → orders      (populate order reference dropdown)
//   READ  → products    (populate item dropdown)
//   READ  → returns     (show this franchisee's return history)
//   WRITE → returns     (save new return request on submit)
//
// New columns expected in `returns` table:
//   receipt_number  VARCHAR(100) NULL
//   receipt_photo   VARCHAR(255) NULL   (file path on server)
// NOTE: Returns are NEVER written to item_usage — separate records.
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

// Get franchisee record
$franchisee   = getFranchiseeByUser($conn, $userId);
$franchiseeId = $franchisee['id'] ?? null;

// ── AJAX: fetch items for a specific order ────────────────────
if (isset($_GET['fetch_order_items']) && $franchiseeId) {
    header('Content-Type: application/json');
    $orderId = intval($_GET['order_id'] ?? 0);
    $items   = [];
    if ($orderId) {
        $stmt = $conn->prepare("
            SELECT p.name, p.unit
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
              AND oi.order_id IN (SELECT id FROM orders WHERE franchisee_id = ?)
            ORDER BY p.name ASC
        ");
        $stmt->bind_param("ii", $orderId, $franchiseeId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $items[] = $row; }
        $stmt->close();
    }
    echo json_encode(['success' => true, 'items' => $items]);
    $conn->close(); exit();
}

// Fetch this franchisee's orders for the "Order Reference" dropdown
$franchiseeOrders = [];
if ($franchiseeId) {
    $stmt = $conn->prepare(
        "SELECT id, po_number, created_at FROM orders WHERE franchisee_id = ? ORDER BY created_at DESC LIMIT 20"
    );
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $franchiseeOrders[] = $row; }
    $stmt->close();
}

// Fetch available products for the "Item to Return" dropdown
$products = [];
$result = $conn->query(
    "SELECT id, name, category FROM products WHERE status = 'available' ORDER BY category, name"
);
while ($row = $result->fetch_assoc()) { $products[] = $row; }

// ── Handle POST: save new return request ─────────────────────
$submitMsg = '';
$submitErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $franchiseeId) {
    $orderId       = intval($_POST['order_id']       ?? 0) ?: null;
    $receiptNumber = trim($_POST['receipt_number']   ?? '');

    $itemNames = $_POST['item_name'] ?? [];
    $reasons   = $_POST['reason']    ?? [];
    $notes     = trim($_POST['notes'] ?? '');

    $itemNames = array_filter(array_map('trim', $itemNames));

    // ── Validate: receipt photo is required ───────────────────
    $receiptPhotoPath = null;
    if (empty($_FILES['receipt_photo']['name'])) {
        $submitErr = "An order receipt photo is required. Please attach a photo before submitting.";
    } else {
        // Handle file upload
        $uploadDir  = 'uploads/return_receipts/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
        $ext        = strtolower(pathinfo($_FILES['receipt_photo']['name'], PATHINFO_EXTENSION));
        $allowed    = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        if (!in_array($ext, $allowed)) {
            $submitErr = "Invalid file type. Please upload a JPG, PNG, GIF, WEBP, or PDF.";
        } elseif ($_FILES['receipt_photo']['size'] > 10 * 1024 * 1024) {
            $submitErr = "File is too large. Maximum size is 10 MB.";
        } else {
            $safeFile = uniqid('receipt_', true) . '.' . $ext;
            if (!move_uploaded_file($_FILES['receipt_photo']['tmp_name'], $uploadDir . $safeFile)) {
                $submitErr = "Failed to save the receipt photo. Please try again.";
            } else {
                $receiptPhotoPath = $uploadDir . $safeFile;
            }
        }
    }

    if (!$submitErr && empty($itemNames)) {
        $submitErr = "Please add at least one item to return.";
    }

    // ── Save to `returns` table ONLY — never to item_usage ───
    if (!$submitErr) {
        $ins = $conn->prepare("
            INSERT INTO returns
                (order_id, franchisee_id, item_name, reason, notes,
                 receipt_number, receipt_photo, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        $count = 0;
        foreach ($itemNames as $idx => $itemName) {
            $reason = trim($reasons[$idx] ?? '');
            if (empty($itemName) || empty($reason)) continue;
            $ins->bind_param("iisssss",
                $orderId, $franchiseeId, $itemName, $reason, $notes,
                $receiptNumber, $receiptPhotoPath
            );
            $ins->execute();
            $count++;
        }
        $ins->close();
        if ($count > 0) {
            $submitMsg = $count === 1
                ? "Return request submitted successfully. Our team will review it shortly."
                : "$count return items submitted successfully. Our team will review them shortly.";
        } else {
            $submitErr = "Please fill in all item and reason fields.";
        }
    }
}

// Fetch this franchisee's return history from DB
$returnHistory = [];
if ($franchiseeId) {
    $stmt = $conn->prepare("
        SELECT r.id, r.item_name, r.reason, r.notes, r.status, r.submitted_at, r.resolved_at,
               r.receipt_number, r.receipt_photo,
               o.po_number
        FROM returns r
        LEFT JOIN orders o ON o.id = r.order_id
        WHERE r.franchisee_id = ?
        ORDER BY r.submitted_at DESC
    ");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $returnHistory[] = $row; }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returns - Juan Café</title>
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
        .nav-item i{width:20px;height:20px;stroke-width:2px;}
        .nav-item:hover{color:var(--primary);background:rgba(92,64,51,.05);}
        .nav-item.active{background:var(--primary);color:white;}
        .user-profile{margin-top:auto;background:white;border:1px solid var(--card-border);padding:1rem;border-radius:16px;display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;}
        .avatar{width:36px;height:36px;border-radius:50%;background:#e5e7eb;overflow:hidden;display:flex;align-items:center;justify-content:center;}
        .avatar i{color:var(--muted);}
        .user-meta h4{font-size:.85rem;font-weight:700;}
        .user-meta p{font-size:.75rem;color:var(--muted);}
        .sign-out{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--muted);font-size:.9rem;padding:.5rem;transition:color .2s;}
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:2rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;}
        .header p{color:var(--muted);}

        /* Alerts */
        .alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:1rem 1.25rem;border-radius:12px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.92rem;}
        .alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:1rem 1.25rem;border-radius:12px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.92rem;}

        /* New Return button */
        .btn-primary{background:var(--primary);color:white;border:none;padding:.75rem 1.5rem;border-radius:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:.5rem;font-family:inherit;font-size:.92rem;transition:background .2s;}
        .btn-primary:hover{background:var(--primary-light);}

        /* Table card */
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;overflow:hidden;}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:1rem 1.5rem;font-size:.78rem;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--card-border);font-weight:700;letter-spacing:.04em;}
        td{padding:1.25rem 1.5rem;font-size:.92rem;border-bottom:1px solid var(--card-border);}
        tr:last-child td{border-bottom:none;}
        tr:hover td{background:#fafafa;}

        /* Status pills */
        .pill{padding:.3rem .8rem;border-radius:20px;font-size:.78rem;font-weight:600;display:inline-block;}
        .pill-pending  {background:#fffbeb;color:#b45309;}
        .pill-approved {background:#dcfce7;color:#166534;}
        .pill-resolved {background:#f1f5f9;color:#64748b;}
        .pill-rejected {background:#fee2e2;color:#991b1b;}

        /* Empty state */
        .empty-state{text-align:center;padding:4rem 2rem;color:var(--muted);}
        .empty-state h3{color:var(--foreground);margin:.75rem 0 .5rem;font-family:'Fraunces',serif;}

        /* Modal */
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:200;padding:1rem;}
        .modal-overlay.open{display:flex;}
        .modal-box{background:white;border-radius:24px;width:100%;max-width:500px;padding:2rem;box-shadow:0 20px 40px -12px rgba(0,0,0,.2);}
        .modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;}
        .modal-head h3{font-family:'Fraunces',serif;font-size:1.4rem;}
        .close-btn{background:none;border:none;cursor:pointer;color:var(--muted);padding:.25rem;}
        .form-group{margin-bottom:1.25rem;}
        .form-group label{display:block;font-size:.875rem;font-weight:600;margin-bottom:.5rem;}
        .form-group select,.form-group input,.form-group textarea{width:100%;padding:.75rem 1rem;border:1.5px solid var(--card-border);border-radius:10px;font-family:inherit;font-size:.92rem;outline:none;background:white;transition:border-color .2s;}
        .form-group select:focus,.form-group input:focus,.form-group textarea:focus{border-color:var(--primary);}
        .btn-submit-modal{width:100%;background:var(--primary);color:white;border:none;padding:1rem;border-radius:12px;font-weight:700;cursor:pointer;margin-top:.5rem;font-family:inherit;font-size:.95rem;transition:background .2s;}
        .btn-submit-modal:hover{background:var(--primary-light);}
        .ret-id{font-weight:700;color:var(--primary);}
        /* Receipt photo upload */
        .file-upload-area{border:2px dashed var(--card-border);border-radius:12px;padding:1.25rem;text-align:center;cursor:pointer;transition:border-color .2s;background:#fdfaf7;position:relative;min-height:90px;}
        .file-upload-area:hover,.file-upload-area.has-file{border-color:var(--primary);}
        .file-upload-area input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;z-index:2;}
        .file-upload-area .upload-label{font-size:.88rem;color:var(--muted);pointer-events:none;}
        .file-upload-area .upload-label strong{color:var(--primary);}
        .receipt-required-note{font-size:.78rem;color:#ef4444;margin-top:.35rem;text-align:center;}
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
        <a href="order-status.php" class="nav-item"><i data-lucide="package"></i> Order Status</a>
        <a href="returns.php" class="nav-item active"><i data-lucide="rotate-ccw"></i> Returns</a>
        <a href="order-history.php" class="nav-item"><i data-lucide="history"></i> Order History</a>
        <a href="profile.php" class="nav-item"><i data-lucide="user"></i> Profile</a>
    </nav>
    <div class="user-profile">
        <div class="avatar"><i data-lucide="user"></i></div>
        <div class="user-meta"><h4><?php echo htmlspecialchars($fullName); ?></h4><p style="font-size:.72rem;color:var(--muted);font-weight:500;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? '—'); ?></p></div>
    </div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
</aside>

<main>
    <div class="header">
        <div>
            <h2>Returns</h2>
            <p>Manage and track your product return requests</p>
        </div>
        <?php if ($franchiseeId): ?>
        <button class="btn-primary" onclick="document.getElementById('returnModal').classList.add('open')">
            <i data-lucide="plus" size="16"></i> New Return Request
        </button>
        <?php endif; ?>
    </div>

    <!-- Success / Error messages -->
    <?php if ($submitMsg): ?>
    <div class="alert-success">
        <i data-lucide="check-circle" size="18"></i>
        <span><?php echo htmlspecialchars($submitMsg); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($submitErr): ?>
    <div class="alert-error">
        <i data-lucide="alert-circle" size="18"></i>
        <span><?php echo htmlspecialchars($submitErr); ?></span>
    </div>
    <?php endif; ?>
    <?php if (!$franchiseeId): ?>
    <div class="alert-error">
        <i data-lucide="alert-triangle" size="18"></i>
        <span>Your account is not linked to a branch. Please contact the administrator.</span>
    </div>
    <?php endif; ?>

    <!-- Returns Table from DB -->
    <?php if (empty($returnHistory)): ?>
    <div class="card">
        <div class="empty-state">
            <i data-lucide="rotate-ccw" size="48" style="opacity:.2;display:block;margin:0 auto;"></i>
            <h3>No return requests yet</h3>
            <p>You haven't submitted any return requests. Click "New Return Request" to get started.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Return ID</th>
                    <th>Item</th>
                    <th>Reason</th>
                    <th>Linked Order</th>
                    <th>Receipt</th>
                    <th>Date Submitted</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($returnHistory as $ret):
                    $statusClass = 'pill-' . strtolower($ret['status']);
                ?>
                <tr>
                    <td><span class="ret-id">#RET-<?php echo str_pad($ret['id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                    <td><?php echo htmlspecialchars($ret['item_name']); ?></td>
                    <td style="color:var(--muted);font-size:.88rem;"><?php echo htmlspecialchars($ret['reason']); ?></td>
                    <td style="font-size:.88rem;">
                        <?php if ($ret['po_number']): ?>
                            <a href="order-status.php?po=<?php echo urlencode($ret['po_number']); ?>" style="color:var(--primary);text-decoration:none;font-weight:600;">
                                <?php echo htmlspecialchars($ret['po_number']); ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($ret['receipt_photo'])): ?>
                            <button type="button" onclick="viewPhoto('<?php echo htmlspecialchars($ret['receipt_photo']); ?>', '<?php echo htmlspecialchars($ret['receipt_number'] ?? ''); ?>')"
                                style="background:none;border:none;cursor:pointer;padding:0;display:flex;align-items:center;gap:.3rem;color:var(--primary);font-size:.82rem;font-weight:600;">
                                <img src="<?php echo htmlspecialchars($ret['receipt_photo']); ?>" alt="receipt"
                                    style="width:38px;height:38px;object-fit:cover;border-radius:6px;border:1px solid var(--card-border);">
                                <span><?php echo htmlspecialchars($ret['receipt_number'] ?: 'View'); ?></span>
                            </button>
                        <?php elseif (!empty($ret['receipt_number'])): ?>
                            <span style="font-size:.82rem;color:var(--muted);"><?php echo htmlspecialchars($ret['receipt_number']); ?></span>
                        <?php else: ?>
                            <span style="color:var(--muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--muted);font-size:.88rem;"><?php echo date('M d, Y h:i A', strtotime($ret['submitted_at'])); ?></td>
                    <td><span class="pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars($ret['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<!-- Photo Lightbox -->
<div id="photoLightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:500;align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)closeLightbox()">
    <div style="background:white;border-radius:16px;max-width:560px;width:100%;overflow:hidden;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid #eee;">
            <div>
                <div style="font-weight:700;font-size:.95rem;">Receipt Photo</div>
                <div id="lightboxRef" style="font-size:.8rem;color:#6b7280;margin-top:.1rem;"></div>
            </div>
            <button onclick="closeLightbox()" style="background:none;border:none;cursor:pointer;color:#6b7280;font-size:1.25rem;">✕</button>
        </div>
        <img id="lightboxImg" src="" alt="Receipt" style="width:100%;max-height:70vh;object-fit:contain;">
    </div>
</div>

<!-- New Return Request Modal — form POSTs to this same page -->
<div class="modal-overlay" id="returnModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>New Return Request</h3>
            <button class="close-btn" onclick="document.getElementById('returnModal').classList.remove('open')">
                <i data-lucide="x" size="22"></i>
            </button>
        </div>

        <form method="POST" action="returns.php" enctype="multipart/form-data">
            <!-- Order Reference (from this franchisee's real orders) -->
            <div class="form-group">
                <label>Order Reference <span style="font-weight:400;color:var(--muted);"></span></label>
                <select name="order_id" id="orderRefSelect" onchange="loadOrderItems(this.value)">
                    <option value="">— No specific order —</option>
                    <?php foreach ($franchiseeOrders as $o): ?>
                    <option value="<?php echo $o['id']; ?>">
                        <?php echo htmlspecialchars($o['po_number']); ?> — <?php echo date('M d, Y', strtotime($o['created_at'])); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Receipt Number -->
            <div class="form-group">
                <label for="receiptNumberInput">Receipt Number <span style="font-weight:400;color:var(--muted);">(from your order receipt)</span></label>
                <input type="text" id="receiptNumberInput" name="receipt_number"
                       placeholder="e.g. OR-2024-00123"
                       value="<?php echo htmlspecialchars($_POST['receipt_number'] ?? ''); ?>">
            </div>

            <!-- Order Receipt Photo (required) -->
            <div class="form-group">
                <label>Order Receipt Photo <span style="color:#ef4444;">*</span></label>
                <div class="file-upload-area" id="uploadArea">
                    <input type="file" name="receipt_photo" id="receiptPhotoInput"
                           accept="image/*,.pdf" required onchange="handleFileSelect(this)">
                    <!-- Default prompt (hidden once photo is chosen) -->
                    <div class="upload-label" id="uploadLabel">
                        <i data-lucide="upload-cloud" size="28" style="display:block;margin:0 auto .5rem;opacity:.5;"></i>
                        <strong>Click to upload</strong> or drag &amp; drop<br>
                        <span style="font-size:.78rem;">JPG, PNG, GIF, WEBP, PDF — max 10 MB</span>
                    </div>
                    <!-- Image preview (shown once photo is chosen) -->
                    <div id="imgPreviewWrap" style="display:none;pointer-events:none;">
                        <img id="imgPreview" src="" alt="Receipt preview"
                            style="max-width:100%;max-height:220px;object-fit:contain;border-radius:8px;display:block;margin:0 auto;">
                        <div id="imgPreviewName" style="font-size:.8rem;color:var(--primary);font-weight:600;margin-top:.5rem;text-align:center;"></div>
                    </div>
                </div>
                <!-- Remove photo button (shown once photo is chosen) -->
                <button type="button" id="removePhotoBtn" onclick="removePhoto()"
                    style="display:none;margin-top:.5rem;width:100%;padding:.45rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;font-family:inherit;">
                    🗑 Remove Photo
                </button>
                <p class="receipt-required-note">A photo or scan of the order receipt is required to submit a return.</p>
            </div>

            <!-- Items to Return — dynamic rows -->
            <div class="form-group">
                <label>Items to Return <span style="color:#ef4444;">*</span></label>
                <div id="itemRowsContainer">
                    <!-- First row (always shown) -->
                    <div class="item-row" style="display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;align-items:center;margin-bottom:.5rem;">
                        <select name="item_name[]" class="item-select" required style="margin:0;">
                            <option value="" disabled selected>Select an item</option>
                        </select>
                        <select name="reason[]" required style="margin:0;">
                            <option value="" disabled selected>Reason</option>
                            <option value="Damaged">Damaged Packaging</option>
                            <option value="Expired">Near Expiry / Expired</option>
                            <option value="Wrong Item">Incorrect Item Delivered</option>
                            <option value="Quality">Quality Issues</option>
                        </select>
                        <button type="button" onclick="removeItemRow(this)" style="width:34px;height:34px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:8px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;" title="Remove">✕</button>
                    </div>
                </div>
                <button type="button" id="addItemBtn" onclick="addItemRow()" style="margin-top:.4rem;width:100%;padding:.6rem;border:1.5px dashed var(--card-border);border-radius:10px;background:transparent;color:var(--primary);font-weight:600;font-family:inherit;font-size:.88rem;cursor:pointer;">
                    + Add Another Item
                </button>
            </div>

            <!-- Additional Notes -->
            <div class="form-group">
                <label>Additional Notes <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
                <textarea name="notes" rows="3" placeholder="Please provide more details about the issue..."></textarea>
            </div>

            <button type="submit" class="btn-submit-modal">
                Submit Return Request
            </button>
        </form>
    </div>
</div>

<!-- Close modal when clicking backdrop -->
<script>
    lucide.createIcons();

    // ── Receipt photo: image preview ──────────────────────────
    function handleFileSelect(input) {
        const area       = document.getElementById('uploadArea');
        const label      = document.getElementById('uploadLabel');
        const previewWrap= document.getElementById('imgPreviewWrap');
        const previewImg = document.getElementById('imgPreview');
        const previewName= document.getElementById('imgPreviewName');
        const removeBtn  = document.getElementById('removePhotoBtn');

        if (!input.files || !input.files[0]) {
            resetUpload();
            return;
        }

        const f   = input.files[0];
        const ext = f.name.split('.').pop().toLowerCase();

        previewName.textContent = f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
        area.classList.add('has-file');
        removeBtn.style.display = 'block';
        label.style.display = 'none';
        previewWrap.style.display = 'block';

        if (ext === 'pdf') {
            // PDF: show icon instead of image
            previewImg.src = '';
            previewImg.style.display = 'none';
            previewWrap.querySelector('div') || previewWrap.insertAdjacentHTML('afterbegin',
                '<div id="pdfIcon" style="font-size:2.5rem;text-align:center;padding:.5rem;">📄</div>');
        } else {
            previewImg.style.display = 'block';
            const reader = new FileReader();
            reader.onload = e => { previewImg.src = e.target.result; };
            reader.readAsDataURL(f);
        }
    }

    function removePhoto() {
        document.getElementById('receiptPhotoInput').value = '';
        resetUpload();
    }

    function resetUpload() {
        document.getElementById('uploadArea').classList.remove('has-file');
        document.getElementById('uploadLabel').style.display = 'block';
        document.getElementById('imgPreviewWrap').style.display = 'none';
        document.getElementById('imgPreview').src = '';
        document.getElementById('imgPreviewName').textContent = '';
        document.getElementById('removePhotoBtn').style.display = 'none';
    }

    // ── Client-side: block submission if no photo attached ────
    document.querySelector('form').addEventListener('submit', function(e) {
        const photoInput = document.getElementById('receiptPhotoInput');
        if (!photoInput.files || !photoInput.files[0]) {
            e.preventDefault();
            alert('Please attach an order receipt photo before submitting.');
            photoInput.closest('.form-group').scrollIntoView({behavior:'smooth', block:'center'});
        }
    });

    let _orderItems = []; // cached items for current order

    function updateAddBtn() {
        const btn  = document.getElementById('addItemBtn');
        if (!btn) return;
        const rows = document.querySelectorAll('#itemRowsContainer .item-row').length;
        // Hide if only 1 item available OR all items already added
        if (_orderItems.length <= 1 || rows >= _orderItems.length) {
            btn.style.display = 'none';
        } else {
            btn.style.display = 'block';
        }
    }

    function loadOrderItems(orderId) {
        const container = document.getElementById('itemRowsContainer');
        if (!orderId) {
            _orderItems = [];
            container.querySelectorAll('.item-select').forEach(sel => {
                sel.innerHTML = '<option value="" disabled selected>Select an item</option>';
            });
            updateAddBtn();
            return;
        }
        container.querySelectorAll('.item-select').forEach(sel => {
            sel.innerHTML = '<option value="" disabled selected>Loading…</option>';
        });
        fetch('returns.php?fetch_order_items=1&order_id=' + orderId)
            .then(r => r.json())
            .then(data => {
                _orderItems = data.items || [];
                container.querySelectorAll('.item-select').forEach(sel => {
                    populateItemSelect(sel);
                });
                updateAddBtn();
            })
            .catch(() => {
                container.querySelectorAll('.item-select').forEach(sel => {
                    sel.innerHTML = '<option value="" disabled selected>Failed to load items</option>';
                });
                updateAddBtn();
            });
    }

    function updateAllItemSelects() {
        const rows    = document.querySelectorAll('#itemRowsContainer .item-row');
        const picked  = new Set();
        rows.forEach(row => {
            const sel = row.querySelector('.item-select');
            if (sel && sel.value) picked.add(sel.value);
        });
        rows.forEach(row => {
            const sel = row.querySelector('.item-select');
            if (!sel) return;
            const current = sel.value;
            Array.from(sel.options).forEach(opt => {
                if (!opt.value) return;
                opt.disabled = picked.has(opt.value) && opt.value !== current;
            });
        });
        updateAddBtn();
    }

    function populateItemSelect(sel, selectedVal) {
        if (!_orderItems.length) {
            sel.innerHTML = '<option value="" disabled selected>Select an order first</option>';
            updateAddBtn();
            return;
        }
        sel.innerHTML = '<option value="" disabled selected>Select an item</option>' +
            _orderItems.map(it =>
                `<option value="${it.name}" ${selectedVal === it.name ? 'selected' : ''}>${it.name} (${it.unit})</option>`
            ).join('');
        sel.addEventListener('change', updateAllItemSelects);
        updateAllItemSelects();
    }

    function addItemRow() {
        const rows = document.querySelectorAll('#itemRowsContainer .item-row').length;
        if (_orderItems.length > 0 && rows >= _orderItems.length) return; // all items already added
        const container = document.getElementById('itemRowsContainer');
        const row = document.createElement('div');
        row.className = 'item-row';
        row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;align-items:center;margin-bottom:.5rem;';
        row.innerHTML = `
            <select name="item_name[]" class="item-select" required style="margin:0;">
                <option value="" disabled selected>Select an item</option>
            </select>
            <select name="reason[]" required style="margin:0;">
                <option value="" disabled selected>Reason</option>
                <option value="Damaged">Damaged Packaging</option>
                <option value="Expired">Near Expiry / Expired</option>
                <option value="Wrong Item">Incorrect Item Delivered</option>
                <option value="Quality">Quality Issues</option>
            </select>
            <button type="button" onclick="removeItemRow(this)" style="width:34px;height:34px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:8px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;" title="Remove">✕</button>
        `;
        container.appendChild(row);
        populateItemSelect(row.querySelector('.item-select'));
        updateAddBtn();
    }

    function removeItemRow(btn) {
        const rows = document.querySelectorAll('#itemRowsContainer .item-row');
        if (rows.length <= 1) return;
        btn.closest('.item-row').remove();
        updateAllItemSelects();
        updateAddBtn();
    }

    // Populate first row on load and set initial button state
    populateItemSelect(document.querySelector('.item-select'));
    updateAddBtn();

    document.getElementById('returnModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });

    function viewPhoto(src, ref) {
        document.getElementById('lightboxImg').src = src;
        document.getElementById('lightboxRef').textContent = ref ? 'Receipt #: ' + ref : '';
        document.getElementById('photoLightbox').style.display = 'flex';
    }
    function closeLightbox() {
        document.getElementById('photoLightbox').style.display = 'none';
    }

    <?php if ($submitErr): ?>
    document.getElementById('returnModal').classList.add('open');
    <?php endif; ?>
</script>
</body>
</html>