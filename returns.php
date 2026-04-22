<?php
// ============================================================
// returns.php — Franchisee Return Requests
// DB Tables used:
//   READ  → franchisees (get franchisee_id for logged-in user)
//   READ  → orders      (populate order reference dropdown)
//   READ  → products    (populate item dropdown)
//   READ  → returns     (show this franchisee's return history)
//   WRITE → returns     (save new return request on submit)
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
    $orderId   = intval($_POST['order_id']   ?? 0) ?: null;
    $itemName  = trim($_POST['item_name']    ?? '');
    $reason    = trim($_POST['reason']       ?? '');
    $notes     = trim($_POST['notes']        ?? '');

    if (empty($itemName) || empty($reason)) {
        $submitErr = "Please select an item and a reason before submitting.";
    } else {
        $ins = $conn->prepare("
            INSERT INTO returns
                (order_id, franchisee_id, item_name, reason, notes, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        $ins->bind_param("iisss", $orderId, $franchiseeId, $itemName, $reason, $notes);
        $ins->execute();
        $ins->close();
        $submitMsg = "Return request submitted successfully. Our team will review it shortly.";
    }
}

// Fetch this franchisee's return history from DB
$returnHistory = [];
if ($franchiseeId) {
    $stmt = $conn->prepare("
        SELECT r.id, r.item_name, r.reason, r.notes, r.status, r.submitted_at, r.resolved_at,
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
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="coffee"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Franchise Portal</span><span style="font-size:.68rem;color:var(--primary);font-weight:600;margin-top:.1rem;display:block;line-height:1;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? '—'); ?></span></div></div>
    <div class="menu-label">Menu</div>
    <nav>
        <a href="franchisee-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="order-form.php" class="nav-item"><i data-lucide="clipboard-list"></i> Order Form</a>
        <a href="item-usage.php" class="nav-item"><i data-lucide="box"></i> Item Usage</a>
        <a href="order-status.php" class="nav-item"><i data-lucide="package"></i> Order Status</a>
        <a href="returns.php" class="nav-item active"><i data-lucide="rotate-ccw"></i> Returns</a>
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
                    <td style="color:var(--muted);font-size:.88rem;"><?php echo date('M d, Y h:i A', strtotime($ret['submitted_at'])); ?></td>
                    <td><span class="pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars($ret['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<!-- New Return Request Modal — form POSTs to this same page -->
<div class="modal-overlay" id="returnModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>New Return Request</h3>
            <button class="close-btn" onclick="document.getElementById('returnModal').classList.remove('open')">
                <i data-lucide="x" size="22"></i>
            </button>
        </div>

        <form method="POST" action="returns.php">
            <!-- Order Reference (from this franchisee's real orders) -->
            <div class="form-group">
                <label>Order Reference <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
                <select name="order_id">
                    <option value="">— No specific order —</option>
                    <?php foreach ($franchiseeOrders as $o): ?>
                    <option value="<?php echo $o['id']; ?>">
                        <?php echo htmlspecialchars($o['po_number']); ?> — <?php echo date('M d, Y', strtotime($o['created_at'])); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Item to Return (from real products table) -->
            <div class="form-group">
                <label>Item to Return <span style="color:#ef4444;">*</span></label>
                <select name="item_name" required>
                    <option value="" disabled selected>Select an item</option>
                    <?php
                    $lastCat2 = '';
                    foreach ($products as $p):
                        if ($p['category'] !== $lastCat2):
                            if ($lastCat2 !== '') echo '</optgroup>';
                            echo '<optgroup label="' . htmlspecialchars($p['category']) . '">';
                            $lastCat2 = $p['category'];
                        endif;
                    ?>
                    <option value="<?php echo htmlspecialchars($p['name']); ?>">
                        <?php echo htmlspecialchars($p['name']); ?>
                    </option>
                    <?php endforeach; if ($lastCat2 !== '') echo '</optgroup>'; ?>
                </select>
            </div>

            <!-- Reason -->
            <div class="form-group">
                <label>Reason for Return <span style="color:#ef4444;">*</span></label>
                <select name="reason" required>
                    <option value="" disabled selected>Select a reason</option>
                    <option value="Damaged">Damaged Packaging</option>
                    <option value="Expired">Near Expiry / Expired</option>
                    <option value="Wrong Item">Incorrect Item Delivered</option>
                    <option value="Quality">Quality Issues</option>
                </select>
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
    document.getElementById('returnModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });

    // Auto-open modal if there was a form error (so user doesn't have to click again)
    <?php if ($submitErr): ?>
    document.getElementById('returnModal').classList.add('open');
    <?php endif; ?>
</script>
</body>
</html>