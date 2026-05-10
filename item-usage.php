<?php
// ============================================================
// item-usage.php — Item Usage / Default Order Items
// DB Tables used:
//   READ  → products              (item selector)
//   READ  → franchisees           (get franchisee_id)
//   READ  → item_usage            (usage history)
//   READ  → item_usage_defaults   (load saved locked defaults)
//   WRITE → item_usage            (save usage submission)
//   WRITE → item_usage_defaults   (save/delete locked defaults via AJAX)
//
// SQL to create defaults table (run once):
//   CREATE TABLE IF NOT EXISTS item_usage_defaults (
//     id            INT AUTO_INCREMENT PRIMARY KEY,
//     franchisee_id INT NOT NULL,
//     product_id    INT NOT NULL,
//     quantity      INT NOT NULL DEFAULT 1,
//     unit          VARCHAR(50) NOT NULL,
//     updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
//       ON UPDATE CURRENT_TIMESTAMP,
//     UNIQUE KEY uq_fran_prod (franchisee_id, product_id)
//   );
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

// ── Auto-create item_usage_defaults if it doesn't exist ──────
$conn->query("
    CREATE TABLE IF NOT EXISTS item_usage_defaults (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        franchisee_id INT NOT NULL,
        product_id    INT NOT NULL,
        quantity      INT NOT NULL DEFAULT 1,
        unit          VARCHAR(50) NOT NULL DEFAULT '',
        updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fran_prod (franchisee_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Auto-add usage_ref column to item_usage if missing ───────
$conn->query("ALTER TABLE item_usage ADD COLUMN IF NOT EXISTS `usage_ref` VARCHAR(30) NULL AFTER `id`");
$conn->query("ALTER TABLE item_usage ADD COLUMN IF NOT EXISTS `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `usage_ref`");

// ── AJAX: save or remove a locked default ────────────────────
if (isset($_POST['ajax_action']) && $franchiseeId) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $pid    = intval($_POST['product_id'] ?? 0);
    $qty    = max(1, intval($_POST['quantity'] ?? 1));
    $unit   = trim($_POST['unit'] ?? '');

    if ($action === 'lock' && $pid > 0) {
        $stmt = $conn->prepare("
            INSERT INTO item_usage_defaults (franchisee_id, product_id, quantity, unit, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), unit = VALUES(unit), updated_at = NOW()
        ");
        $stmt->bind_param("iiis", $franchiseeId, $pid, $qty, $unit);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok, 'action' => 'locked']);

    } elseif ($action === 'unlock' && $pid > 0) {
        $stmt = $conn->prepare("DELETE FROM item_usage_defaults WHERE franchisee_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $franchiseeId, $pid);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok, 'action' => 'unlocked']);

    } elseif ($action === 'send_to_order') {
        // Session-based handoff to order-form.php — no localStorage needed
        $items = json_decode($_POST['items'] ?? '[]', true) ?: [];
        $_SESSION['usage_handoff'] = ['items' => $items, 'ts' => time()];
        echo json_encode(['success' => true, 'redirect' => 'order-form.php?from_usage=1']);

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    $conn->close(); exit();
}

// Fetch all available products for the dropdown
$products = [];
$prodResult = $conn->query(
    "SELECT id, name, category, unit FROM products WHERE status = 'available' ORDER BY category, name"
);
while ($row = $prodResult->fetch_assoc()) { $products[] = $row; }

// ── Load this franchisee's saved locked defaults ──────────────
$savedDefaults = [];
if ($franchiseeId) {
    $result = $conn->query("
        SELECT d.product_id, d.quantity, d.unit, p.name
        FROM item_usage_defaults d
        JOIN products p ON p.id = d.product_id
        WHERE d.franchisee_id = {$franchiseeId}
        ORDER BY p.name ASC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) { $savedDefaults[] = $row; }
    }
}

// ── Handle POST: save usage entries to DB ─────────────────────
$submitMsg = '';
$submitErr = '';
$savedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $franchiseeId) {
    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['qty_used']   ?? [];
    $units      = $_POST['unit']       ?? [];
    $lockedIds  = array_map('intval', $_POST['locked_id'] ?? []);
    $recordDate = date('Y-m-d');
    $year       = date('Y');

    if (empty($productIds)) {
        $submitErr = "Please add at least one item before submitting.";
    } else {
        // ── Generate unique usage_ref: IU-YYYY-NNNN ────────────
        $lastRef = $conn->query("
            SELECT usage_ref FROM item_usage
            WHERE usage_ref LIKE 'IU-{$year}-%'
            ORDER BY id DESC LIMIT 1
        ")->fetch_assoc()['usage_ref'] ?? null;

        $nextSeq = 1;
        if ($lastRef) {
            $parts   = explode('-', $lastRef);
            $nextSeq = intval(end($parts)) + 1;
        }
        $usageRef = 'IU-' . $year . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);

        // ── Save usage history ─────────────────────────────────
        $insUsage = $conn->prepare("
            INSERT INTO item_usage
                (usage_ref, franchisee_id, product_id, quantity_used, unit, recording_date, submitted_at, is_default)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ");

        foreach ($productIds as $i => $pid) {
            $pid       = intval($pid);
            $qty       = intval($quantities[$i] ?? 0);
            $unit      = $units[$i] ?? '';
            $isDefault = in_array($pid, $lockedIds) ? 1 : 0;
            if ($pid <= 0 || $qty <= 0) continue;

            $insUsage->bind_param("siiissi", $usageRef, $franchiseeId, $pid, $qty, $unit, $recordDate, $isDefault);
            $insUsage->execute();
            $savedCount++;
        }
        $insUsage->close();

        // ── Upsert locked rows into item_usage_defaults ────────
        // This ensures locked items survive the page reload after submit
        if (!empty($lockedIds)) {
            $upsert = $conn->prepare("
                INSERT INTO item_usage_defaults (franchisee_id, product_id, quantity, unit, updated_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), unit = VALUES(unit), updated_at = NOW()
            ");
            foreach ($lockedIds as $lockedPid) {
                $idx  = array_search((string)$lockedPid, array_map('strval', $productIds));
                if ($idx === false) continue;
                $qty  = intval($quantities[$idx] ?? 1);
                $unit = $units[$idx] ?? '';
                $upsert->bind_param("iiis", $franchiseeId, $lockedPid, $qty, $unit);
                $upsert->execute();
            }
            $upsert->close();
        }

        if ($savedCount > 0) {
            $submitMsg = $savedCount . ' item' . ($savedCount > 1 ? 's' : '') . ' recorded successfully for ' . date('M d, Y') . '. Reference: <strong>' . htmlspecialchars($usageRef) . '</strong>';
        } else {
            $submitErr = "No valid entries were submitted. Please select items with valid quantities.";
        }
    }
}

// Fetch usage history grouped by date
$usageHistory = [];
if ($franchiseeId) {
    $stmt = $conn->prepare("
        SELECT iu.id, iu.usage_ref, iu.quantity_used, iu.unit, iu.recording_date, iu.is_default,
               p.id as product_id, p.name as product_name
        FROM item_usage iu
        JOIN products p ON p.id = iu.product_id
        WHERE iu.franchisee_id = ?
        ORDER BY iu.recording_date DESC, p.name ASC
        LIMIT 100
    ");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $usageHistory[] = $row; }
    $stmt->close();
}

// Group history by recording_date, also group by usage_ref within date
$historyByDate = [];
foreach ($usageHistory as $h) {
    $date = $h['recording_date'];
    if (!isset($historyByDate[$date])) { $historyByDate[$date] = []; }
    $historyByDate[$date][] = $h;
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
        .empty-msg{text-align:center;color:var(--muted);padding:2rem;font-size:.9rem;font-style:italic;}

        /* Lock / unlock button */
        .btn-unlock{background:#fef3c7;border:1.5px solid #fde68a;color:#92400e;cursor:pointer;padding:.3rem .6rem;border-radius:8px;font-size:.75rem;font-weight:700;display:inline-flex;align-items:center;gap:.25rem;transition:all .15s;}
        .btn-unlock:hover{background:#fde68a;}
        .lock-label{font-size:.72rem;font-weight:700;letter-spacing:.01em;}
        tr.row-locked td{background:#fffdf5 !important;}
        tr.row-locked .qty-input{color:var(--muted);}
        .default-badge{font-size:.7rem;color:#d97706;font-weight:700;background:#fef3c7;border:1px solid #fde68a;padding:.1rem .45rem;border-radius:20px;margin-left:.4rem;vertical-align:middle;}

        /* History accordion */
        .history-card{margin-top:0;}
        .status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;margin-right:.4rem;}
        .hist-date-group{border:1px solid var(--card-border);border-radius:12px;margin-bottom:.6rem;overflow:hidden;}
        .hist-date-header{display:flex;align-items:center;justify-content:space-between;padding:.875rem 1.25rem;cursor:pointer;background:#fdfaf7;transition:background .15s;user-select:none;}
        .hist-date-header:hover{background:#f5ede6;}
        .hist-date-header .date-label{font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:.6rem;}
        .hist-date-header .item-count-badge{font-size:.75rem;color:var(--primary);background:#f5ede6;padding:.15rem .55rem;border-radius:20px;font-weight:600;}
        .hist-chevron{transition:transform .2s;color:var(--muted);}
        .hist-date-header.open .hist-chevron{transform:rotate(180deg);}
        .hist-date-body{display:none;border-top:1px solid var(--card-border);}
        .hist-date-body.open{display:block;}
        .hist-date-body table{width:100%;border-collapse:collapse;}
        .hist-date-body td{padding:.7rem 1.25rem;font-size:.88rem;border-bottom:1px solid var(--card-border);}
        .hist-date-body tr:last-child td{border-bottom:none;}
        /* Load-into-form button on date header */
        .btn-load-date{background:var(--primary);color:white;border:none;padding:.35rem .8rem;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:.35rem;font-family:inherit;transition:background .15s;white-space:nowrap;flex-shrink:0;}
        .btn-load-date:hover{background:var(--primary-light);}
        .btn-load-date.loaded{background:#10b981;}
        /* Grayed-out already-added options in selector */
        .item-selector option:disabled{color:#ccc;font-style:italic;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="coffee"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Franchise Portal</span><span style="font-size:.85rem;color:var(--primary);font-weight:600;margin-top:.1rem;display:block;line-height:1;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? '—'); ?></span></div></div>
    <div class="menu-label">Menu</div>
    <nav>
        <a href="franchisee-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="order-form.php" class="nav-item"><i data-lucide="clipboard-list"></i> Order Form</a>
        <a href="item-usage.php" class="nav-item active"><i data-lucide="box"></i> Item Usage</a>
        <a href="order-status.php" class="nav-item"><i data-lucide="package"></i> Order Status</a>
        <a href="returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i> Returns</a>
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
        <h2>Item Usage Recording</h2>
        <p>Set your default products and record daily consumption — default items auto-load into the Order Form.</p>
    </div>

    <!-- Success / Error messages from DB submission -->
    <?php if ($submitMsg): ?>
    <div class="alert-success">
        <i data-lucide="check-circle" size="18"></i>
        <span><?php echo $submitMsg; ?></span>
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

        <div class="page-grid">
            <!-- LEFT: Recording section -->
            <div>
                <div class="card">
                    <!-- Info banner -->
                    <div class="usage-info-banner">
                        <i data-lucide="info" size="16" style="flex-shrink:0;margin-top:.1rem;"></i>
                        <span>Add the items you regularly use, then <strong>🔒 lock</strong> the ones you always order — they'll be remembered and loaded back here every time. Unlock any item to edit its quantity or remove it.</span>
                    </div>

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
                            <tr>
                                <th>Item Name</th>
                                <th>Unit</th>
                                <th>Quantity Used</th>
                                <th style="width:120px;text-align:center;">
                                    <button type="button" id="lockAllBtn" onclick="lockAllItems()"
                                        style="background:#f59e0b;border:none;color:white;padding:.35rem .8rem;border-radius:8px;font-size:.75rem;font-weight:700;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:.3rem;">
                                        🔒 Lock All
                                    </button>
                                </th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody id="usageBody"></tbody>
                    </table>
                    <div id="emptyMsg" class="empty-msg">No items added yet. Use the selector above to add items.</div>
                </div>

                <!-- Recent history — accordion with date picker beside title -->
                <?php if (!empty($historyByDate)): ?>
                <?php
                $allDateData = [];
                foreach ($historyByDate as $date => $entries) {
                    $dlabel = date('D, M d, Y', strtotime($date));
                    $dateItems = [];
                    foreach ($entries as $e) {
                        $pid = $e['product_id'];
                        if (!isset($dateItems[$pid])) {
                            $dateItems[$pid] = ['pid' => $pid, 'name' => $e['product_name'], 'unit' => $e['unit'], 'qty' => 0];
                        }
                        $dateItems[$pid]['qty'] += intval($e['quantity_used']);
                    }
                    $allDateData[$date] = [
                        'label' => $dlabel,
                        'count' => count($entries),
                        'items' => array_values($dateItems),
                        'raw'   => $entries
                    ];
                }
                ?>
                <div class="card history-card">
                    <!-- Title row with inline date picker -->
                    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;">
                        <h3 class="section-title" style="margin-bottom:0;flex-shrink:0;">
                            <i data-lucide="history"></i> Recent Usage History
                        </h3>
                        <select id="histDatePicker" onchange="jumpToDate(this.value)"
                            style="flex:1;min-width:180px;padding:.45rem .8rem;border-radius:9px;border:1.5px solid var(--card-border);font-size:.84rem;font-family:inherit;background:white;cursor:pointer;color:var(--foreground);">
                            <option value="">— Jump to date —</option>
                            <?php foreach ($historyByDate as $date => $entries):
                                $dlabel = date('D, M d, Y', strtotime($date));
                                $dcount = count($entries);
                            ?>
                            <option value="<?php echo $date; ?>"><?php echo $dlabel; ?> (<?php echo $dcount; ?> item<?php echo $dcount > 1 ? 's' : ''; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Accordion groups -->
                    <div id="histAccordion">
                    <?php $isFirst = true; foreach ($historyByDate as $date => $entries):
                        $label = date('D, M d, Y', strtotime($date));
                        $count = count($entries);
                        $dateItems = $allDateData[$date]['items'];
                        $dateItemsJson = htmlspecialchars(json_encode($dateItems), ENT_QUOTES);
                        // Group entries by usage_ref within this date
                        $refGroups = [];
                        foreach ($entries as $e) {
                            $ref = $e['usage_ref'] ?? '—';
                            if (!isset($refGroups[$ref])) $refGroups[$ref] = [];
                            $refGroups[$ref][] = $e;
                        }
                    ?>
                    <div class="hist-date-group" data-date="<?php echo $date; ?>">
                        <div class="hist-date-header <?php echo $isFirst ? 'open' : ''; ?>"
                             onclick="toggleHistDate(this)">
                            <span class="date-label">
                                <i data-lucide="calendar" size="15" style="color:var(--primary);"></i>
                                <?php echo $label; ?>
                                <span class="item-count-badge"><?php echo $count; ?> item<?php echo $count > 1 ? 's' : ''; ?></span>
                            </span>
                            <div style="display:flex;align-items:center;gap:.6rem;" onclick="event.stopPropagation()">
                                <button type="button" class="btn-load-date"
                                        data-items="<?php echo $dateItemsJson; ?>"
                                        onclick="loadDateItems(this, event)"
                                        title="Load these items into the form above">
                                    <i data-lucide="corner-left-up" size="13"></i>
                                    Load into form
                                </button>
                                <i data-lucide="chevron-down" size="18" class="hist-chevron" style="pointer-events:none;"></i>
                            </div>
                        </div>
                        <div class="hist-date-body <?php echo $isFirst ? 'open' : ''; ?>">
                            <?php foreach ($refGroups as $ref => $refEntries):
                                $hasDefault = array_sum(array_column($refEntries, 'is_default')) > 0;
                            ?>
                            <!-- Ref group header -->
                            <div style="display:flex;align-items:center;gap:.6rem;padding:.55rem 1.25rem;background:#fafaf9;border-bottom:1px solid var(--card-border);">
                                <span style="font-size:.72rem;font-weight:700;color:var(--primary);background:#f5ede6;padding:.15rem .55rem;border-radius:6px;letter-spacing:.03em;"><?php echo htmlspecialchars($ref); ?></span>
                                <?php if ($hasDefault): ?>
                                <span style="font-size:.68rem;font-weight:700;color:#d97706;background:#fef3c7;border:1px solid #fde68a;padding:.1rem .4rem;border-radius:6px;">⭐ Includes Defaults</span>
                                <?php endif; ?>
                            </div>
                            <table>
                                <thead>
                                    <tr>
                                        <th style="padding:.5rem 1.25rem;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Product</th>
                                        <th style="padding:.5rem 1.25rem;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Qty</th>
                                        <th style="padding:.5rem 1.25rem;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Unit</th>
                                        <th style="padding:.5rem 1.25rem;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($refEntries as $h): ?>
                                    <tr>
                                        <td><span class="status-dot"></span><?php echo htmlspecialchars($h['product_name']); ?></td>
                                        <td style="font-weight:700;color:var(--primary);"><?php echo $h['quantity_used']; ?></td>
                                        <td style="color:var(--muted);"><?php echo htmlspecialchars($h['unit']); ?></td>
                                        <td>
                                            <?php if ($h['is_default']): ?>
                                            <span style="font-size:.7rem;font-weight:700;color:#d97706;background:#fef3c7;border:1px solid #fde68a;padding:.1rem .4rem;border-radius:6px;">Default</span>
                                            <?php else: ?>
                                            <span style="font-size:.7rem;color:var(--muted);">Manual</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php $isFirst = false; endforeach; ?>
                    </div><!-- end histAccordion -->
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
                    <div class="summary-row">
                        <span class="s-label">Locked (Default)</span>
                        <span class="s-value" id="lockedCount">0</span>
                    </div>

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

                    <p style="font-size:.78rem;color:var(--muted);margin-top:.875rem;text-align:center;line-height:1.5;">
                        You can also load a past date's items using <strong>Load into form</strong> in the history below.
                    </p>
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

    // Saved defaults from DB (pre-loaded on page open)
    const savedDefaults = <?php echo json_encode($savedDefaults); ?>;

    function buildRow(pid, name, unit, qty, isLocked) {
        addedIds.add(String(pid));
        const tbody = document.getElementById('usageBody');
        const row   = document.createElement('tr');
        row.dataset.pid = pid;
        if (isLocked) row.classList.add('row-locked');
        row.innerHTML = `
            <td>
                <strong>${name}</strong>${isLocked ? '<span class="default-badge">Default</span>' : ''}
                <input type="hidden" name="product_id[]" value="${pid}">
                <input type="hidden" name="unit[]" value="${unit}" class="unit-field">
                ${isLocked ? `<input type="hidden" name="locked_id[]" value="${pid}" class="locked-field">` : ''}
            </td>
            <td style="color:var(--muted);">${unit}</td>
            <td>
                <div class="qty-wrap">
                    <button type="button" class="qty-btn" onclick="adj(this,-1)" ${isLocked ? 'disabled' : ''}>
                        <i data-lucide="minus" size="13"></i>
                    </button>
                    <input type="text" class="qty-input" name="qty_used[]" value="${qty}" min="1" ${isLocked ? 'readonly' : ''}>
                    <button type="button" class="qty-btn" onclick="adj(this,1)" ${isLocked ? 'disabled' : ''}>
                        <i data-lucide="plus" size="13"></i>
                    </button>
                </div>
            </td>
            <td style="text-align:center;">
                ${isLocked
                    ? `<button type="button" class="btn-unlock" onclick="unlockRow(this)"
                            title="Unlock to edit or remove">
                            <i data-lucide="lock-open" size="13"></i> Unlock
                        </button>`
                    : `<span style="color:#d1c5bb;font-size:.75rem;">—</span>`
                }
            </td>
            <td>
                <button type="button" class="btn-del" onclick="removeItem(this,'${pid}')" ${isLocked ? 'disabled style="opacity:.2;cursor:default;"' : ''}>
                    <i data-lucide="trash-2" size="17"></i>
                </button>
            </td>`;
        tbody.appendChild(row);
    }

    // ── localStorage state persistence key ───────────────────────
    const USAGE_STATE_KEY = 'jc_item_usage_state_<?php echo (int)$franchiseeId; ?>';
    const ORDER_DRAFT_KEY = 'jc_order_draft_<?php echo (int)$franchiseeId; ?>';

    // Save current unlocked rows to localStorage (locked ones reload from DB)
    function saveState() {
        try {
            const rows = document.querySelectorAll('#usageBody tr');
            const items = [];
            rows.forEach(row => {
                if (row.classList.contains('row-locked')) return; // locked = DB handles it
                const pid  = row.dataset.pid;
                const name = row.querySelector('strong')?.innerText?.replace(' Default','').trim() || '';
                const unit = row.querySelector('.unit-field')?.value || '';
                const qty  = row.querySelector('.qty-input')?.value || '1';
                if (pid) items.push({ pid, name, unit, qty });
            });
            localStorage.setItem(USAGE_STATE_KEY, JSON.stringify({ items, ts: Date.now() }));
        } catch(e) {}
    }

    // Restore unlocked rows from localStorage on page load
    function restoreState() {
        try {
            const raw = localStorage.getItem(USAGE_STATE_KEY);
            if (!raw) return;
            const data = JSON.parse(raw);
            // Expire after 4 hours
            if (!data?.items?.length || (Date.now() - data.ts) > 14400000) return;
            data.items.forEach(item => {
                if (!addedIds.has(String(item.pid))) {
                    buildRow(item.pid, item.name, item.unit, item.qty, false);
                }
            });
            lucide.createIcons();
            updateCount();
            refreshSelectorDisabled();
        } catch(e) {}
    }

    // On page load: first restore locked defaults from DB, then unlocked from localStorage
    window.addEventListener('DOMContentLoaded', () => {
        // 1. Load locked defaults from DB (server-rendered)
        if (savedDefaults && savedDefaults.length > 0) {
            savedDefaults.forEach(item => {
                buildRow(item.product_id, item.name, item.unit, item.quantity, true);
            });
        }
        // 2. Restore unlocked rows from localStorage
        restoreState();

        lucide.createIcons();
        updateCount();
        refreshSelectorDisabled();
    });

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
        buildRow(pid, name, unit, 1, false);
        lucide.createIcons();
        updateCount();
        refreshSelectorDisabled();
        saveState();
        sel.selectedIndex = 0;
    }

    // Gray out already-added options in the selector
    function refreshSelectorDisabled() {
        const sel = document.getElementById('itemSelector');
        Array.from(sel.options).forEach(opt => {
            if (!opt.value) return;
            if (addedIds.has(String(opt.value))) {
                opt.disabled = true;
                opt.text = opt.dataset.name + ' (' + opt.dataset.unit + ') — already added';
            } else {
                opt.disabled = false;
                opt.text = opt.dataset.name + ' (' + opt.dataset.unit + ')';
            }
        });
    }

    // Load all items from a history date into the form table
    function loadDateItems(btn, event) {
        event.stopPropagation(); // Don't toggle accordion
        const items = JSON.parse(btn.dataset.items || '[]');
        if (!items.length) return;

        let added = 0;
        items.forEach(item => {
            const pid  = String(item.pid);
            const name = item.name;
            const unit = item.unit;
            const qty  = item.qty || 1;

            if (addedIds.has(pid)) {
                // Already in table — just bump the quantity
                const existing = document.querySelector(`tr[data-pid="${pid}"]`);
                if (existing) {
                    const qtyInput = existing.querySelector('.qty-input');
                    if (qtyInput && !existing.classList.contains('row-locked')) {
                        qtyInput.value = qty;
                    }
                }
            } else {
                buildRow(pid, name, unit, qty, false);
                added++;
            }
        });

        lucide.createIcons();
        updateCount();
        refreshSelectorDisabled();
        saveState();
        btn.classList.add('loaded');
        btn.innerHTML = '<i data-lucide="check" size="13"></i> Loaded!';
        lucide.createIcons();
        setTimeout(() => {
            btn.classList.remove('loaded');
            btn.innerHTML = '<i data-lucide="corner-left-up" size="13"></i> Load into form';
            lucide.createIcons();
        }, 2000);

        // Scroll up to the form table smoothly
        document.getElementById('usageBody').closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function adj(btn, delta) {
        const input = btn.parentElement.querySelector('.qty-input');
        input.value = Math.max(1, (parseInt(input.value) || 1) + delta);
        saveState();
    }

    function removeItem(btn, pid) {
        const row = btn.closest('tr');
        if (row.classList.contains('row-locked')) {
            row.style.outline = '2px solid #f59e0b';
            setTimeout(() => row.style.outline = '', 1200);
            return;
        }
        row.remove();
        addedIds.delete(pid);
        updateCount();
        refreshSelectorDisabled();
        saveState();
    }

    function updateCount() {
        const n      = document.querySelectorAll('#usageBody tr').length;
        const locked = document.querySelectorAll('#usageBody tr.row-locked').length;
        document.getElementById('itemCount').innerText    = n;
        document.getElementById('lockedCount').innerText  = locked;
        document.getElementById('emptyMsg').style.display = n === 0 ? 'block' : 'none';
        document.getElementById('submitBtn').disabled     = n === 0;
        document.getElementById('proceedBtn').disabled    = n === 0;
    }

    // All history date data from PHP
    const allDateData = <?php echo json_encode($allDateData ?? []); ?>;

    // ── Lock All — one click locks every row, batch AJAX save ──
    function lockAllItems() {
        const rows = document.querySelectorAll('#usageBody tr:not(.row-locked)');
        if (rows.length === 0) return;

        const toSave = [];
        rows.forEach(row => {
            const pid   = row.dataset.pid;
            const qty   = row.querySelector('.qty-input')?.value || '1';
            const unit  = row.querySelector('.unit-field')?.value || '';
            const name  = row.querySelector('strong')?.innerText?.replace(' Default','').trim() || '';
            const badge = row.querySelector('.default-badge');
            const firstTd = row.querySelector('td:first-child');

            // Visual: lock the row immediately
            row.classList.add('row-locked');
            row.style.background = '';

            // Disable qty controls
            row.querySelectorAll('.qty-btn').forEach(b => b.disabled = true);
            const qi = row.querySelector('.qty-input');
            if (qi) qi.readOnly = true;

            // Add Default badge
            if (!badge) {
                const b = document.createElement('span');
                b.className = 'default-badge'; b.textContent = 'Default';
                row.querySelector('strong').after(b);
            }

            // Add locked_id hidden input
            if (!firstTd.querySelector('.locked-field')) {
                const h = document.createElement('input');
                h.type = 'hidden'; h.name = 'locked_id[]';
                h.value = pid; h.className = 'locked-field';
                firstTd.appendChild(h);
            }

            // Swap action cell: replace "—" with Unlock button, disable delete
            const actionTd = row.querySelectorAll('td')[3];
            if (actionTd) actionTd.innerHTML = `<button type="button" class="btn-unlock" onclick="unlockRow(this)" title="Unlock to edit or remove"><i data-lucide="lock-open" size="13"></i> Unlock</button>`;
            const delBtn = row.querySelector('.btn-del');
            if (delBtn) { delBtn.disabled = true; delBtn.style.opacity = '.2'; delBtn.style.cursor = 'default'; }

            toSave.push({ pid, qty, unit });
        });

        lucide.createIcons();
        updateCount();

        // Batch save to DB
        toSave.forEach(item => {
            const fd = new FormData();
            fd.append('ajax_action', 'lock');
            fd.append('product_id', item.pid);
            fd.append('quantity', item.qty);
            fd.append('unit', item.unit);
            fetch('item-usage.php', { method: 'POST', body: fd }).catch(() => {});
        });

        // Visual feedback on Lock All button
        const btn = document.getElementById('lockAllBtn');
        if (btn) {
            btn.textContent = '✓ All Locked!';
            btn.style.background = '#10b981';
            setTimeout(() => {
                btn.innerHTML = '🔒 Lock All';
                btn.style.background = '#f59e0b';
            }, 1500);
        }
    }

    // ── Unlock a single row to allow editing ──────────────────
    function unlockRow(btn) {
        const row     = btn.closest('tr');
        const pid     = row.dataset.pid;
        const unit    = row.querySelector('.unit-field')?.value || '';
        const qty     = row.querySelector('.qty-input')?.value || '1';

        // Visual: unlock
        row.classList.remove('row-locked');
        row.querySelector('.default-badge')?.remove();

        // Re-enable qty controls
        row.querySelectorAll('.qty-btn').forEach(b => b.disabled = false);
        const qi = row.querySelector('.qty-input');
        if (qi) qi.readOnly = false;

        // Remove locked_id hidden input
        row.querySelector('.locked-field')?.remove();

        // Restore action cell to "—" and re-enable delete
        const actionTd = row.querySelectorAll('td')[3];
        if (actionTd) actionTd.innerHTML = `<span style="color:#d1c5bb;font-size:.75rem;">—</span>`;
        const delBtn = row.querySelector('.btn-del');
        if (delBtn) { delBtn.disabled = false; delBtn.style.opacity = '1'; delBtn.style.cursor = 'pointer'; }

        lucide.createIcons();
        updateCount();
        saveState();

        // Remove from DB defaults
        const fd = new FormData();
        fd.append('ajax_action', 'unlock');
        fd.append('product_id', pid);
        fetch('item-usage.php', { method: 'POST', body: fd }).catch(() => {});
    }

    // ── Jump to date: moves selected group to top and opens it ──
    function jumpToDate(date) {
        if (!date) return;
        const accordion = document.getElementById('histAccordion');
        if (!accordion) return;

        // Find by looping — avoids any CSS selector escaping issues with dates
        let target = null;
        accordion.querySelectorAll('.hist-date-group').forEach(g => {
            if (g.getAttribute('data-date') === date) target = g;
        });
        if (!target) return;

        // Close all groups
        accordion.querySelectorAll('.hist-date-group').forEach(g => {
            const h = g.querySelector('.hist-date-header');
            const b = g.querySelector('.hist-date-body');
            if (h) h.classList.remove('open');
            if (b) b.classList.remove('open');
        });

        // Move target to top and open it
        accordion.prepend(target);
        const th = target.querySelector('.hist-date-header');
        const tb = target.querySelector('.hist-date-body');
        if (th) th.classList.add('open');
        if (tb) tb.classList.add('open');

        // Scroll to it
        setTimeout(() => {
            target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 50);

        // Reset picker
        setTimeout(() => {
            const picker = document.getElementById('histDatePicker');
            if (picker) picker.value = '';
        }, 1000);
    }

    // ── History date accordion toggle ─────────────────────────
    function toggleHistDate(header) {
        const body = header.nextElementSibling;
        header.classList.toggle('open');
        body.classList.toggle('open');
    }

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
        // Clear the persisted state — items were recorded, start fresh next visit
        try { localStorage.removeItem(USAGE_STATE_KEY); } catch(e) {}
        document.getElementById('usageReviewModal').classList.remove('open');
        document.getElementById('usageForm').submit();
    }

    document.getElementById('usageReviewModal').addEventListener('click', function(e) {
        if (e.target === this) closeUsageReview();
    });

    // ── Proceed to Order Form — session-based handoff ──────────
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
        const rows = document.querySelectorAll('#usageBody tr');
        const items = [];
        rows.forEach(row => {
            const pid  = row.dataset.pid;
            const name = row.querySelector('strong')?.innerText?.replace(' Default','').trim() || '';
            const qty  = row.querySelector('.qty-input')?.value || '1';
            const unit = row.querySelector('.unit-field')?.value || '';
            if (pid) items.push({ pid: String(pid), name, qty: String(qty), unit });
        });

        if (items.length === 0) { closeProceedReview(); return; }

        // Write DIRECTLY into order-form's draft localStorage key — no session needed
        try {
            localStorage.setItem(ORDER_DRAFT_KEY, JSON.stringify({
                items,
                delivery: 'Standard Delivery',
                payment:  'Cash',
                ts: Date.now()
            }));
        } catch(e) {}

        // Also save via PHP session as belt-and-suspenders
        const fd = new FormData();
        fd.append('ajax_action', 'send_to_order');
        fd.append('items', JSON.stringify(items));

        fetch('item-usage.php', { method: 'POST', body: fd })
            .finally(() => {
                window.location.href = 'order-form.php?from_usage=1';
            });
    }

    document.getElementById('proceedReviewModal').addEventListener('click', function(e) {
        if (e.target === this) closeProceedReview();
    });

    updateCount();
    lucide.createIcons();
</script>
</body>
</html>