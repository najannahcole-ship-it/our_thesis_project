<?php
// ============================================================
// item-usage.php — Daily Item Usage Recording
// DB Tables used:
//   READ  → products    (populate item selector from real catalog)
//   READ  → franchisees (get franchisee_id for logged-in user)
//   READ  → orders      (find latest active order to link usage to)
//   WRITE → item_usage  (save each usage entry on submit)
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

// Get franchisee record linked to this user
$franchisee   = getFranchiseeByUser($conn, $userId);
$franchiseeId = $franchisee['id'] ?? null;

// Fetch all available products for the dropdown (from real products table)
$products = [];
$prodResult = $conn->query(
    "SELECT id, name, category, unit FROM products WHERE status = 'available' ORDER BY category, name"
);
while ($row = $prodResult->fetch_assoc()) { $products[] = $row; }

// Find the most recent active order to link usage to + get its items
$linkedOrder     = null;
$lastOrderItems  = []; // Pre-populate item-usage from last order
if ($franchiseeId) {
    $stmt = $conn->prepare(
        "SELECT id, po_number FROM orders WHERE franchisee_id = ? ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $linkedOrder = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fetch that order's items to pre-populate the usage form
    if ($linkedOrder) {
        $stmt = $conn->prepare("
            SELECT p.id as product_id, p.name, p.unit, p.stock_qty, oi.quantity
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
        ");
        $stmt->bind_param("i", $linkedOrder['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $lastOrderItems[] = $row; }
        $stmt->close();
    }
}

// ── Handle POST: save usage entries to DB ─────────────────────
$submitMsg = '';
$submitErr = '';
$savedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $franchiseeId) {
    $productIds  = $_POST['product_id'] ?? [];
    $quantities  = $_POST['qty_used']   ?? [];
    $units       = $_POST['unit']       ?? [];
    $orderIdLink = intval($_POST['order_id'] ?? 0) ?: null;
    $recordDate  = date('Y-m-d');

    if (empty($productIds)) {
        $submitErr = "Please add at least one item before submitting.";
    } else {
        $insUsage = $conn->prepare("
            INSERT INTO item_usage
                (franchisee_id, product_id, quantity_used, unit, recording_date, submitted_at, order_id)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");

        foreach ($productIds as $i => $pid) {
            $pid  = intval($pid);
            $qty  = intval($quantities[$i] ?? 0);
            $unit = $units[$i] ?? '';
            if ($pid <= 0 || $qty <= 0) continue;

            $insUsage->bind_param("iiissi", $franchiseeId, $pid, $qty, $unit, $recordDate, $orderIdLink);
            $insUsage->execute();
            $savedCount++;
        }
        $insUsage->close();

        if ($savedCount > 0) {
            $submitMsg = $savedCount . ' item' . ($savedCount > 1 ? 's' : '') . ' recorded successfully for ' . date('M d, Y') . '.';
        } else {
            $submitErr = "No valid entries were submitted. Please select items with valid quantities.";
        }
    }
}

// Fetch recent usage history for this franchisee (last 10 entries)
$usageHistory = [];
if ($franchiseeId) {
    $stmt = $conn->prepare("
        SELECT iu.quantity_used, iu.unit, iu.recording_date, iu.submitted_at,
               p.name as product_name,
               o.po_number
        FROM item_usage iu
        JOIN products p ON p.id = iu.product_id
        LEFT JOIN orders o ON o.id = iu.order_id
        WHERE iu.franchisee_id = ?
        ORDER BY iu.submitted_at DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $usageHistory[] = $row; }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Usage - Juan Café</title>
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
        .avatar{width:36px;height:36px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;}
        .avatar i{color:var(--muted);}
        .user-meta h4{font-size:.85rem;font-weight:700;}
        .user-meta p{font-size:.75rem;color:var(--muted);}
        .sign-out{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--muted);font-size:.9rem;padding:.5rem;transition:color .2s;}
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{margin-bottom:2rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);}

        /* Layout */
        .page-grid{display:grid;grid-template-columns:1fr 340px;gap:2rem;align-items:start;}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:2rem;margin-bottom:1.5rem;}
        .card:last-child{margin-bottom:0;}
        .section-title{font-family:'Fraunces',serif;font-size:1.2rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.5rem;}

        /* Alerts */
        .alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:1rem 1.25rem;border-radius:12px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.92rem;}
        .alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:1rem 1.25rem;border-radius:12px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.92rem;}
        .alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;padding:.875rem 1rem;border-radius:12px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.875rem;}

        /* Add item bar */
        .add-bar{display:flex;gap:1rem;margin-bottom:1.5rem;}
        .item-selector{flex:1;padding:.75rem 1rem;border:1.5px solid var(--card-border);border-radius:10px;font-family:inherit;font-size:.95rem;outline:none;background:white;}
        .item-selector:focus{border-color:var(--primary);}
        .btn-add{background:var(--primary);color:white;border:none;padding:.75rem 1.25rem;border-radius:10px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:.4rem;font-family:inherit;font-size:.9rem;white-space:nowrap;}
        .btn-add:hover{background:var(--primary-light);}

        /* Table */
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:.875rem 1rem;font-size:.78rem;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--card-border);font-weight:700;letter-spacing:.04em;}
        td{padding:1rem;font-size:.92rem;border-bottom:1px solid var(--card-border);}
        tr:last-child td{border-bottom:none;}

        /* Qty controls */
        .qty-wrap{display:flex;align-items:center;gap:.5rem;background:var(--background);border-radius:8px;padding:.25rem;width:fit-content;}
        .qty-btn{width:28px;height:28px;border-radius:6px;border:none;background:white;color:var(--primary);cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 2px rgba(0,0,0,.06);}
        .qty-input{width:44px;border:none;background:transparent;text-align:center;font-weight:700;font-family:inherit;font-size:.9rem;color:var(--primary);outline:none;}
        .btn-del{color:var(--muted);background:none;border:none;cursor:pointer;transition:color .2s;padding:.25rem;}
        .btn-del:hover{color:#ef4444;}

        /* Summary panel */
        .summary-panel{position:sticky;top:2rem;}
        .summary-row{display:flex;justify-content:space-between;margin-bottom:.875rem;font-size:.9rem;}
        .summary-row .s-label{color:var(--muted);}
        .summary-row .s-value{font-weight:600;}
        .btn-submit{width:100%;background:var(--primary);color:white;border:none;padding:1rem;border-radius:12px;font-weight:700;cursor:pointer;margin-top:1.25rem;font-family:inherit;font-size:1rem;transition:background .2s;}
        .btn-submit:hover{background:var(--primary-light);}
        .btn-submit:disabled{opacity:.5;cursor:not-allowed;}
        .btn-proceed{width:100%;background:#10b981;color:white;border:none;padding:1rem;border-radius:12px;font-weight:700;cursor:pointer;margin-top:.75rem;font-family:inherit;font-size:1rem;transition:background .2s;display:flex;align-items:center;justify-content:center;gap:.5rem;}
        .btn-proceed:hover{background:#059669;}
        .btn-proceed:disabled{opacity:.5;cursor:not-allowed;}
        .flow-divider{text-align:center;color:var(--muted);font-size:.8rem;margin:.75rem 0;position:relative;}
        .flow-divider::before,.flow-divider::after{content:'';position:absolute;top:50%;width:42%;height:1px;background:var(--card-border);}
        .flow-divider::before{left:0;} .flow-divider::after{right:0;}
        .usage-info-banner{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:.875rem 1rem;margin-bottom:1.25rem;font-size:.85rem;color:#1d4ed8;display:flex;align-items:flex-start;gap:.6rem;}
        /* Usage review modal */
        #usageReviewModal{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(5px);display:none;align-items:center;justify-content:center;z-index:150;padding:1rem;}
        #usageReviewModal.open{display:flex;}
        .ur-box{background:white;border-radius:24px;max-width:480px;width:100%;max-height:88vh;overflow-y:auto;box-shadow:0 25px 50px -12px rgba(0,0,0,.2);}
        .ur-head{padding:1.5rem 2rem 1rem;border-bottom:1px solid var(--card-border);display:flex;justify-content:space-between;align-items:center;}
        .ur-head h3{font-family:'Fraunces',serif;font-size:1.35rem;}
        .ur-body{padding:1.5rem 2rem;}
        .ur-warn{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.875rem 1rem;font-size:.85rem;color:#92400e;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.5rem;}
        .ur-section h4{font-size:.75rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.04em;margin-bottom:.75rem;}
        .ur-row{display:flex;justify-content:space-between;padding:.45rem 0;border-bottom:1px dashed var(--card-border);font-size:.9rem;}
        .ur-row:last-child{border-bottom:none;}
        .ur-foot{padding:1rem 2rem 1.5rem;display:flex;gap:.75rem;}
        .ur-foot button{flex:1;padding:.875rem;border-radius:12px;font-weight:700;font-family:inherit;font-size:.92rem;cursor:pointer;border:none;}
        .ur-back{background:var(--background);color:var(--foreground);border:1px solid var(--card-border)!important;}
        .ur-confirm{background:var(--primary);color:white;}
        .ur-confirm:hover{background:var(--primary-light);}
        .linked-po{font-size:.82rem;color:#1d4ed8;font-weight:600;text-decoration:none;}
        .linked-po:hover{text-decoration:underline;}
        .empty-msg{text-align:center;color:var(--muted);padding:2rem;font-size:.9rem;font-style:italic;}

        /* History table */
        .history-card{margin-top:0;}
        .status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;margin-right:.4rem;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="coffee"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Franchise Portal</span><span style="font-size:.68rem;color:var(--primary);font-weight:600;margin-top:.1rem;display:block;line-height:1;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? '—'); ?></span></div></div>
    <div class="menu-label">Menu</div>
    <nav>
        <a href="franchisee-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="order-form.php" class="nav-item"><i data-lucide="clipboard-list"></i> Order Form</a>
        <a href="item-usage.php" class="nav-item active"><i data-lucide="box"></i> Item Usage</a>
        <a href="order-status.php" class="nav-item"><i data-lucide="package"></i> Order Status</a>
        <a href="returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i> Returns</a>
        <a href="history.php" class="nav-item"><i data-lucide="history"></i> History</a>
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
        <h2>Item Usage Recording</h2>
        <p>Record daily consumption to maintain accurate inventory levels.</p>
    </div>

    <!-- Success / Error messages from DB submission -->
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
        <span>Your account is not linked to a branch yet. Please contact the administrator.</span>
    </div>
    <?php endif; ?>

    <form id="usageForm" method="POST" action="item-usage.php">
        <!-- Pass linked order ID -->
        <input type="hidden" name="order_id" value="<?php echo $linkedOrder ? $linkedOrder['id'] : 0; ?>">

        <div class="page-grid">
            <!-- LEFT: Recording section -->
            <div>
                <div class="card">
                    <!-- Linked order info banner -->
                    <!-- Flow explanation banner -->
                    <div class="usage-info-banner">
                        <i data-lucide="info" size="16" style="flex-shrink:0;margin-top:.1rem;"></i>
                        <span>This is your <strong>daily consumption record</strong> — track what your branch uses each day. Pre-filled from your last order. You can also <strong>send these items directly to the Order Form</strong> to place a new purchase order.</span>
                    </div>

                    <?php if ($linkedOrder): ?>
                    <div class="alert-info">
                        <i data-lucide="link" size="16"></i>
                        <span>This report will be linked to order <strong><?php echo htmlspecialchars($linkedOrder['po_number']); ?></strong>.</span>
                        <a href="order-status.php?po=<?php echo urlencode($linkedOrder['po_number']); ?>" class="linked-po" style="margin-left:auto;">View Order →</a>
                    </div>
                    <?php endif; ?>

                    <!-- Item selector -->
                    <div class="add-bar">
                        <select class="item-selector" id="itemSelector">
                            <option value="" disabled selected>Search or select item to add...</option>
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
                                    data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                    data-unit="<?php echo htmlspecialchars($p['unit']); ?>">
                                <?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['unit']); ?>)
                            </option>
                            <?php endforeach; if ($lastCat !== '') echo '</optgroup>'; ?>
                        </select>
                        <button type="button" class="btn-add" onclick="addItem()">
                            <i data-lucide="plus" size="16"></i> Add Item
                        </button>
                    </div>

                    <!-- Usage table -->
                    <table>
                        <thead>
                            <tr><th>Item Name</th><th>Unit</th><th>Quantity Used</th><th style="width:40px;"></th></tr>
                        </thead>
                        <tbody id="usageBody"></tbody>
                    </table>
                    <div id="emptyMsg" class="empty-msg">No items added yet. Use the selector above to add items.</div>
                </div>

                <!-- Recent history from DB -->
                <?php if (!empty($usageHistory)): ?>
                <div class="card history-card">
                    <h3 class="section-title"><i data-lucide="history"></i> Recent Usage History</h3>
                    <table>
                        <thead>
                            <tr><th>Item</th><th>Qty</th><th>Unit</th><th>Date</th><th>Linked Order</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usageHistory as $h): ?>
                            <tr>
                                <td><span class="status-dot"></span><?php echo htmlspecialchars($h['product_name']); ?></td>
                                <td style="font-weight:700;"><?php echo $h['quantity_used']; ?></td>
                                <td style="color:var(--muted);"><?php echo htmlspecialchars($h['unit']); ?></td>
                                <td style="color:var(--muted);font-size:.85rem;"><?php echo date('M d, Y', strtotime($h['recording_date'])); ?></td>
                                <td style="font-size:.85rem;">
                                    <?php if ($h['po_number']): ?>
                                        <a href="order-status.php?po=<?php echo urlencode($h['po_number']); ?>" class="linked-po"><?php echo htmlspecialchars($h['po_number']); ?></a>
                                    <?php else: ?>
                                        <span style="color:var(--muted);">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT: Summary + Submit -->
            <div class="summary-panel">
                <div class="card">
                    <h3 class="section-title"><i data-lucide="clipboard-check"></i> Submission Details</h3>

                    <div class="summary-row">
                        <span class="s-label">Items Recorded</span>
                        <span class="s-value" id="itemCount">0</span>
                    </div>
                    <div class="summary-row">
                        <span class="s-label">Recording Date</span>
                        <span class="s-value"><?php echo date('M d, Y'); ?></span>
                    </div>
                    <?php if ($linkedOrder): ?>
                    <div class="summary-row">
                        <span class="s-label">Linked Order</span>
                        <a href="order-status.php?po=<?php echo urlencode($linkedOrder['po_number']); ?>" class="linked-po s-value">
                            <?php echo htmlspecialchars($linkedOrder['po_number']); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--card-border);">
                        <p style="font-size:.85rem;color:var(--muted);line-height:1.6;">
                            Recording daily consumption keeps your branch stock levels accurate and helps the warehouse plan restocking schedules.
                        </p>
                    </div>

                    <button type="button" class="btn-submit" id="submitBtn" disabled
                        onclick="openUsageReview()" <?php echo !$franchiseeId ? 'disabled' : ''; ?>>
                        <i data-lucide="eye" size="16" style="display:inline;vertical-align:middle;margin-right:.4rem;"></i>
                        Review & Submit Usage Report
                    </button>

                    <div class="flow-divider">or</div>

                    <button type="button" class="btn-proceed" id="proceedBtn" disabled
                        onclick="openProceedReview()" <?php echo !$franchiseeId ? 'disabled' : ''; ?>>
                        <i data-lucide="shopping-cart" size="16" style="display:inline;vertical-align:middle;margin-right:.4rem;"></i>
                        Send to Order Form →
                    </button>
                </div>
            </div>
        </div>
    </form>
</main>

<!-- Usage Review Modal -->
<div id="usageReviewModal">
    <div class="ur-box">
        <div class="ur-head">
            <h3>Review Usage Report</h3>
            <button onclick="closeUsageReview()" style="background:none;border:none;cursor:pointer;color:var(--muted);"><i data-lucide="x" size="22"></i></button>
        </div>
        <div class="ur-body">
            <div class="ur-warn">
                <i data-lucide="alert-triangle" size="15" style="flex-shrink:0;margin-top:.1rem;"></i>
                <span>Please confirm the items and quantities below before submitting. This record will be saved to the database.</span>
            </div>
            <div class="ur-section">
                <h4>Items to Record</h4>
                <div id="ur-items-list"></div>
            </div>
            <div style="margin-top:1rem;padding:.875rem;background:var(--background);border-radius:10px;font-size:.85rem;">
                <div style="display:flex;justify-content:space-between;"><span style="color:var(--muted);">Recording Date</span><strong><?php echo date('M d, Y'); ?></strong></div>
                <?php if ($linkedOrder): ?>
                <div style="display:flex;justify-content:space-between;margin-top:.4rem;"><span style="color:var(--muted);">Linked Order</span><strong><?php echo htmlspecialchars($linkedOrder['po_number']); ?></strong></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="ur-foot">
            <button class="ur-back" onclick="closeUsageReview()">← Go Back & Edit</button>
            <button class="ur-confirm" onclick="submitUsageConfirmed()">✓ Confirm & Submit</button>
        </div>
    </div>
</div>

<!-- Proceed to Order Form Review Modal -->
<div id="proceedReviewModal" style="position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(5px);display:none;align-items:center;justify-content:center;z-index:150;padding:1rem;">
    <div class="ur-box">
        <div class="ur-head">
            <h3>Send to Order Form</h3>
            <button onclick="closeProceedReview()" style="background:none;border:none;cursor:pointer;color:var(--muted);"><i data-lucide="x" size="22"></i></button>
        </div>
        <div class="ur-body">
            <div class="ur-warn" style="background:#f0fdf4;border-color:#86efac;">
                <i data-lucide="shopping-cart" size="15" style="flex-shrink:0;margin-top:.1rem;"></i>
                <span style="color:#065f46;">These items will be <strong>pre-loaded into the Order Form</strong>. You can still edit quantities, add or remove items there before placing the actual order.</span>
            </div>
            <div class="ur-section">
                <h4>Items to carry over</h4>
                <div id="proceed-items-list"></div>
            </div>
            <div style="margin-top:1rem;padding:.875rem;background:var(--background);border-radius:10px;font-size:.82rem;color:var(--muted);">
                Note: This will NOT submit a usage report — it only loads items into the Order Form.
            </div>
        </div>
        <div class="ur-foot">
            <button class="ur-back" onclick="closeProceedReview()">← Cancel</button>
            <button style="flex:1;padding:.875rem;border-radius:12px;font-weight:700;font-family:inherit;font-size:.92rem;cursor:pointer;border:none;background:#10b981;color:white;" onclick="proceedToOrderForm()">
                → Go to Order Form
            </button>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();

    const addedIds = new Set();

    // Pre-populate items from the franchisee's last order (Req 3 & 5)
    const lastOrderItems = <?php echo json_encode($lastOrderItems); ?>;

    function buildRow(pid, name, unit, qty) {
        addedIds.add(String(pid));
        const tbody = document.getElementById('usageBody');
        const row   = document.createElement('tr');
        row.dataset.pid = pid;
        row.innerHTML = `
            <td>
                <strong>${name}</strong>
                <input type="hidden" name="product_id[]" value="${pid}">
                <input type="hidden" name="unit[]" value="${unit}" class="unit-field">
            </td>
            <td style="color:var(--muted);">${unit}</td>
            <td>
                <div class="qty-wrap">
                    <button type="button" class="qty-btn" onclick="adj(this,-1)">
                        <i data-lucide="minus" size="13"></i>
                    </button>
                    <input type="text" class="qty-input" name="qty_used[]" value="${qty}" min="1">
                    <button type="button" class="qty-btn" onclick="adj(this,1)">
                        <i data-lucide="plus" size="13"></i>
                    </button>
                </div>
            </td>
            <td>
                <button type="button" class="btn-del" onclick="removeItem(this,'${pid}')">
                    <i data-lucide="trash-2" size="17"></i>
                </button>
            </td>`;
        tbody.appendChild(row);
    }

    // On page load: pre-populate from last order if items exist
    window.addEventListener('DOMContentLoaded', () => {
        if (lastOrderItems && lastOrderItems.length > 0) {
            lastOrderItems.forEach(item => {
                buildRow(item.product_id, item.name, item.unit, item.quantity);
            });
            lucide.createIcons();
            updateCount();
        }
    }); // Prevent duplicate items

    function addItem() {
        const sel = document.getElementById('itemSelector');
        const opt = sel.options[sel.selectedIndex];
        if (!sel.value) return;

        const pid  = sel.value;
        const name = opt.dataset.name;
        const unit = opt.dataset.unit;

        if (addedIds.has(pid)) {
            const existing = document.querySelector(`tr[data-pid="${pid}"]`);
            if (existing) {
                existing.style.background = '#fffbeb';
                setTimeout(() => existing.style.background = '', 1500);
            }
            sel.selectedIndex = 0;
            return;
        }
        buildRow(pid, name, unit, 1);
        lucide.createIcons();
        updateCount();
        sel.selectedIndex = 0;
    }

    function adj(btn, delta) {
        const input = btn.parentElement.querySelector('.qty-input');
        input.value = Math.max(1, (parseInt(input.value) || 1) + delta);
    }

    function removeItem(btn, pid) {
        btn.closest('tr').remove();
        addedIds.delete(pid);
        updateCount();
    }

    function updateCount() {
        const n = document.querySelectorAll('#usageBody tr').length;
        document.getElementById('itemCount').innerText  = n;
        document.getElementById('emptyMsg').style.display = n === 0 ? 'block' : 'none';
        document.getElementById('submitBtn').disabled  = n === 0;
        document.getElementById('proceedBtn').disabled = n === 0;
    }

    updateCount();

    // ── Usage Review Modal functions ───────────────────────────
    function openUsageReview() {
        const rows = document.querySelectorAll('#usageBody tr');
        if (rows.length === 0) return;

        let html = '';
        rows.forEach(row => {
            const name = row.querySelector('strong')?.innerText || '—';
            const qty  = row.querySelector('.qty-input')?.value || '0';
            const unit = row.querySelectorAll('td')[1]?.innerText || '';
            html += `<div class="ur-row">
                <span>${name}</span>
                <span style="font-weight:600;">${qty} ${unit}</span>
            </div>`;
        });

        document.getElementById('ur-items-list').innerHTML = html;
        document.getElementById('usageReviewModal').classList.add('open');
        lucide.createIcons();
    }

    function closeUsageReview() {
        document.getElementById('usageReviewModal').classList.remove('open');
    }

    function submitUsageConfirmed() {
        document.getElementById('usageReviewModal').classList.remove('open');
        document.getElementById('usageForm').submit();
    }

    document.getElementById('usageReviewModal').addEventListener('click', function(e) {
        if (e.target === this) closeUsageReview();
    });

    // ── Proceed to Order Form functions ────────────────────────
    const USAGE_HANDOFF_KEY = 'jc_usage_to_order_<?php echo (int)$franchiseeId; ?>';

    function openProceedReview() {
        const rows = document.querySelectorAll('#usageBody tr');
        if (rows.length === 0) return;

        let html = '';
        rows.forEach(row => {
            const name = row.querySelector('strong')?.innerText || '—';
            const qty  = row.querySelector('.qty-input')?.value  || '0';
            const unit = row.querySelectorAll('td')[1]?.innerText || '';
            const pid  = row.dataset.pid || '';
            if (pid) {
                html += `<div class="ur-row">
                    <span>${name} <span style="color:var(--muted);font-size:.82rem;">(${unit})</span></span>
                    <span style="font-weight:700;">×${qty}</span>
                </div>`;
            }
        });

        document.getElementById('proceed-items-list').innerHTML = html || '<p style="color:var(--muted);font-size:.9rem;">No items added yet.</p>';
        document.getElementById('proceedReviewModal').style.display = 'flex';
        lucide.createIcons();
    }

    function closeProceedReview() {
        document.getElementById('proceedReviewModal').style.display = 'none';
    }

    function proceedToOrderForm() {
        // Collect all current rows into a handoff payload
        const rows = document.querySelectorAll('#usageBody tr');
        const items = [];
        rows.forEach(row => {
            const pid  = row.dataset.pid;
            const qty  = row.querySelector('.qty-input')?.value || '1';
            if (pid) items.push({ pid, qty });
        });

        if (items.length === 0) { closeProceedReview(); return; }

        // Save to localStorage — order-form.php will read this on load
        try {
            localStorage.setItem(USAGE_HANDOFF_KEY, JSON.stringify({ items, ts: Date.now() }));
        } catch(e) {}

        // Navigate to order form
        window.location.href = 'order-form.php?from_usage=1';
    }

    document.getElementById('proceedReviewModal').addEventListener('click', function(e) {
        if (e.target === this) closeProceedReview();
    });
</script>
</body>
</html>