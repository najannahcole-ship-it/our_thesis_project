<?php
// ============================================================
// encoder-returns.php — Return Resolutions
// DB Tables used:
//   READ  → returns + franchisees + orders
//   WRITE → returns  (update status to Resolved, set resolved_at)
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$encoderName = $_SESSION['full_name'] ?? 'Data Encoder';
$encoderId   = $_SESSION['user_id'];

// ── Handle POST: finalize a return ───────────────────────────
$actionMsg = '';
$actionErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnId  = intval($_POST['return_id'] ?? 0);
    $action    = $_POST['action'] ?? '';
    $payRef    = trim($_POST['payment_ref'] ?? '');
    $resoNotes = trim($_POST['resolution_notes'] ?? '');

    if ($returnId > 0 && $action === 'resolve') {
        if (empty($payRef)) {
            $actionErr = "Please enter a payment reference number before finalizing.";
        } else {
            // Append resolution note to existing notes
            $fullNotes = $resoNotes ? "Resolution: $resoNotes | Ref: $payRef" : "Payment Ref: $payRef";

            $upd = $conn->prepare("UPDATE returns SET status = 'Resolved', resolved_at = NOW(), notes = CONCAT(IFNULL(notes,''), ' | ', ?) WHERE id = ? AND status = 'Approved'");
            $upd->bind_param("si", $fullNotes, $returnId);
            $upd->execute();
            $upd->close();
            $actionMsg = "Return case resolved successfully. Payment reference recorded.";
        }
    }
}

// ── Fetch returns ─────────────────────────────────────────────
$filterStatus = $_GET['filter'] ?? 'approved';
$searchTerm   = $_GET['search'] ?? '';
$selectedId   = intval($_GET['id'] ?? 0);

$whereMap = [
    'approved'  => "r.status = 'Approved'",
    'pending'   => "r.status = 'Pending'",
    'resolved'  => "r.status = 'Resolved'",
    'all'       => "1=1"
];
$whereClause = $whereMap[$filterStatus] ?? "r.status = 'Approved'";

$returns = [];
$searchWhere = '';
$params = [];
$types  = '';

if ($searchTerm) {
    $searchWhere = " AND (f.franchisee_name LIKE ? OR r.item_name LIKE ? OR r.reason LIKE ?)";
    $like = '%' . $searchTerm . '%';
    $params = [$like, $like, $like];
    $types  = 'sss';
}

$sql = "
    SELECT r.id, r.item_name, r.reason, r.notes, r.status, r.submitted_at, r.resolved_at,
           r.order_id, o.po_number, o.total_amount,
           f.franchisee_name, f.branch_name
    FROM returns r
    LEFT JOIN franchisees f ON f.id = r.franchisee_id
    LEFT JOIN orders o ON o.id = r.order_id
    WHERE $whereClause $searchWhere
    ORDER BY r.submitted_at DESC
    LIMIT 50
";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $returns[] = $row; }
    $stmt->close();
} else {
    $result = $conn->query($sql);
    if ($result) while ($row = $result->fetch_assoc()) { $returns[] = $row; }
}

// Auto-select first or from URL
if (!$selectedId && !empty($returns)) {
    $selectedId = $returns[0]['id'];
}

$selectedReturn = null;
if ($selectedId) {
    foreach ($returns as $r) {
        if ($r['id'] == $selectedId) { $selectedReturn = $r; break; }
    }
    // If not in filtered list, fetch directly
    if (!$selectedReturn) {
        $stmt = $conn->prepare("
            SELECT r.*, o.po_number, o.total_amount, f.franchisee_name, f.branch_name
            FROM returns r
            LEFT JOIN franchisees f ON f.id = r.franchisee_id
            LEFT JOIN orders o ON o.id = r.order_id
            WHERE r.id = ?
        ");
        $stmt->bind_param("i", $selectedId);
        $stmt->execute();
        $selectedReturn = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Resolutions - Top Juan Inc.</title>
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
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:2.5rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);font-size:1rem;}
        .returns-container{display:grid;grid-template-columns:1fr 420px;gap:2rem;align-items:start;}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:2rem;margin-bottom:1.5rem;}
        .card:last-child{margin-bottom:0;}
        .card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;}
        .card-header h3{font-family:'Fraunces',serif;font-size:1.25rem;}
        .controls-row{display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;}
        .search-box{flex:1;position:relative;min-width:200px;}
        .search-box i{position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--muted);width:18px;height:18px;}
        .search-box input{width:100%;padding:.75rem 1rem .75rem 2.75rem;border-radius:12px;border:1px solid var(--card-border);font-family:inherit;font-size:.9rem;outline:none;}
        .filter-select{padding:.75rem 1rem;border-radius:12px;border:1px solid var(--card-border);font-family:inherit;font-size:.9rem;background:white;outline:none;cursor:pointer;}
        .table-container{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:1rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid var(--card-border);}
        td{padding:1.25rem 1rem;font-size:.9rem;border-bottom:1px solid var(--card-border);}
        tr:last-child td{border-bottom:none;}
        tr.ret-row{cursor:pointer;transition:background .15s;}
        tr.ret-row:hover td{background:rgba(92,64,51,.03);}
        tr.ret-row.selected td{background:rgba(92,64,51,.05);}
        .status-pill{padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-block;}
        .s-approved{background:rgba(16,185,129,.1);color:#10b981;}
        .s-pending{background:rgba(210,84,36,.1);color:var(--accent);}
        .s-resolved{background:#f1f5f9;color:#64748b;}
        .s-rejected{background:rgba(239,68,68,.1);color:#ef4444;}
        .resolution-panel{position:sticky;top:2rem;}
        .form-group{margin-bottom:1.25rem;}
        .form-group label{display:block;font-size:.85rem;font-weight:600;margin-bottom:.5rem;}
        .form-group input,.form-group textarea{width:100%;padding:.75rem;border-radius:10px;border:1px solid var(--card-border);font-family:inherit;font-size:.9rem;outline:none;background:#fdfaf7;}
        .form-group input:focus,.form-group textarea:focus{border-color:var(--primary);background:white;}
        .info-box{background:#f8fafc;border-radius:12px;padding:1.25rem;margin-bottom:1.5rem;border:1px solid #e2e8f0;}
        .info-title{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.75rem;font-weight:700;}
        .info-row{display:flex;justify-content:space-between;margin-bottom:.5rem;font-size:.85rem;}
        .btn{padding:.875rem 1.5rem;border-radius:12px;font-weight:600;font-size:.9rem;cursor:pointer;transition:all .2s;border:none;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;font-family:inherit;width:100%;}
        .btn-primary{background:var(--primary);color:white;}
        .btn-primary:hover{background:var(--primary-light);}
        .btn-outline{background:transparent;border:1px solid var(--card-border);color:var(--muted);}
        .btn-outline:hover{background:rgba(0,0,0,.02);color:var(--primary);}
        .alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:.875rem 1rem;border-radius:10px;margin-bottom:1.5rem;font-size:.9rem;}
        .alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.875rem 1rem;border-radius:10px;margin-bottom:1.5rem;font-size:.9rem;}
        .empty-state{text-align:center;padding:3rem;color:var(--muted);}
        .no-select{text-align:center;padding:3rem 2rem;color:var(--muted);}
        .no-select i{opacity:.2;display:block;margin:0 auto .75rem;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="coffee"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Data Encoder</span></div></div>
    <p class="menu-label">Main Menu</p>
    <nav>
        <a href="encoder-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i>Dashboard</a>
        <a href="encoder-orders.php" class="nav-item"><i data-lucide="shopping-bag"></i>Order Process</a>
        <a href="encoder-returns.php" class="nav-item active"><i data-lucide="rotate-ccw"></i>Return and Refund</a>
        <a href="encoder-reports.php" class="nav-item"><i data-lucide="file-text"></i>Reports</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($encoderName); ?></h4><p>Data Encoder</p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i><span>Sign Out</span></a>
</aside>

<main>
    <div class="header">
        <div><h2>Return Resolutions</h2><p>Document refund transactions and finalize approved return cases.</p></div>
    </div>

    <?php if ($actionMsg): ?><div class="alert-success"><?php echo $actionMsg; ?></div><?php endif; ?>
    <?php if ($actionErr): ?><div class="alert-error"><?php echo htmlspecialchars($actionErr); ?></div><?php endif; ?>

    <div class="returns-container">
        <!-- Left: Returns Queue -->
        <div>
            <div class="card">
                <div class="card-header"><h3>Return Queue</h3>
                    <span style="font-size:.85rem;color:var(--muted);"><?php echo count($returns); ?> case<?php echo count($returns) != 1 ? 's' : ''; ?></span>
                </div>

                <form method="GET" action="encoder-returns.php">
                    <div class="controls-row">
                        <div class="search-box">
                            <i data-lucide="search"></i>
                            <input type="text" name="search" placeholder="Search franchisee or item..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                        </div>
                        <select class="filter-select" name="filter" onchange="this.form.submit()">
                            <option value="approved" <?php echo $filterStatus==='approved'?'selected':''; ?>>Admin Approved</option>
                            <option value="pending"  <?php echo $filterStatus==='pending' ?'selected':''; ?>>Pending</option>
                            <option value="resolved" <?php echo $filterStatus==='resolved'?'selected':''; ?>>Resolved</option>
                            <option value="all"      <?php echo $filterStatus==='all'     ?'selected':''; ?>>All Returns</option>
                        </select>
                    </div>
                </form>

                <div class="table-container">
                    <?php if (empty($returns)): ?>
                    <div class="empty-state">
                        <i data-lucide="check-circle" size="40" style="opacity:.2;display:block;margin:0 auto .75rem;color:#10b981;"></i>
                        <p>No returns found for this filter.</p>
                    </div>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>Return ID</th><th>Franchisee</th><th>Item / Reason</th><th>Order</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returns as $ret):
                                $sCls = 's-' . strtolower($ret['status']);
                            ?>
                            <tr class="ret-row <?php echo $ret['id'] == $selectedId ? 'selected' : ''; ?>"
                                onclick="window.location.href='encoder-returns.php?id=<?php echo $ret['id']; ?>&filter=<?php echo $filterStatus; ?>'">
                                <td style="font-weight:700;">#RET-<?php echo str_pad($ret['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                <td>
                                    <div style="font-weight:600;font-size:.875rem;"><?php echo htmlspecialchars($ret['franchisee_name'] ?? '—'); ?></div>
                                    <div style="font-size:.75rem;color:var(--muted)"><?php echo htmlspecialchars($ret['branch_name'] ?? '—'); ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500;font-size:.875rem;"><?php echo htmlspecialchars($ret['item_name']); ?></div>
                                    <div style="font-size:.75rem;color:var(--muted)">Reason: <?php echo htmlspecialchars($ret['reason']); ?></div>
                                </td>
                                <td style="font-size:.85rem;"><?php echo $ret['po_number'] ? htmlspecialchars($ret['po_number']) : '—'; ?></td>
                                <td><span class="status-pill <?php echo $sCls; ?>"><?php echo htmlspecialchars($ret['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Resolution Form -->
        <div class="resolution-panel">
            <?php if ($selectedReturn): ?>
            <div class="card">
                <div class="card-header">
                    <h3>Execute Resolution</h3>
                    <span class="status-pill s-<?php echo strtolower($selectedReturn['status']); ?>">#RET-<?php echo str_pad($selectedReturn['id'], 4, '0', STR_PAD_LEFT); ?></span>
                </div>

                <div class="info-box">
                    <div class="info-title">Case Details</div>
                    <div class="info-row"><span>Franchisee:</span><span style="font-weight:600;"><?php echo htmlspecialchars($selectedReturn['franchisee_name'] ?? '—'); ?></span></div>
                    <div class="info-row"><span>Branch:</span><span style="font-weight:600;"><?php echo htmlspecialchars($selectedReturn['branch_name'] ?? '—'); ?></span></div>
                    <div class="info-row"><span>Item Returned:</span><span style="font-weight:600;"><?php echo htmlspecialchars($selectedReturn['item_name']); ?></span></div>
                    <div class="info-row"><span>Reason:</span><span style="font-weight:600;"><?php echo htmlspecialchars($selectedReturn['reason']); ?></span></div>
                    <?php if ($selectedReturn['po_number']): ?>
                    <div class="info-row"><span>Original Order:</span><span style="font-weight:600;"><?php echo htmlspecialchars($selectedReturn['po_number']); ?></span></div>
                    <?php endif; ?>
                    <div class="info-row"><span>Submitted:</span><span><?php echo date('M d, Y h:i A', strtotime($selectedReturn['submitted_at'])); ?></span></div>
                    <?php if ($selectedReturn['notes']): ?>
                    <div class="info-row" style="flex-direction:column;gap:.25rem;"><span style="color:var(--muted);">Notes:</span><span style="font-size:.8rem;"><?php echo htmlspecialchars($selectedReturn['notes']); ?></span></div>
                    <?php endif; ?>
                </div>

                <?php if ($selectedReturn['status'] === 'Approved'): ?>
                <form method="POST" action="encoder-returns.php?id=<?php echo $selectedReturn['id']; ?>&filter=<?php echo $filterStatus; ?>">
                    <input type="hidden" name="return_id" value="<?php echo $selectedReturn['id']; ?>">
                    <div class="form-group">
                        <label>Payment Reference Number <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="payment_ref" placeholder="Enter GCash / bank reference number" required>
                    </div>
                    <div class="form-group">
                        <label>Resolution Details & Communication</label>
                        <textarea name="resolution_notes" rows="4" placeholder="Enter details to communicate to the franchisee...">The return request for <?php echo htmlspecialchars($selectedReturn['item_name']); ?> (<?php echo htmlspecialchars($selectedReturn['reason']); ?>) has been verified and approved. A refund has been processed to your registered account.</textarea>
                    </div>
                    <div style="display:grid;gap:.75rem;">
                        <button type="submit" name="action" value="resolve" class="btn btn-primary"><i data-lucide="check-square"></i> Finalize & Close Case</button>
                        <button type="button" class="btn btn-outline" onclick="window.print()"><i data-lucide="printer"></i> Print Resolution</button>
                    </div>
                </form>

                <?php elseif ($selectedReturn['status'] === 'Resolved'): ?>
                <div style="background:#f0fdf4;border-radius:12px;padding:1.25rem;text-align:center;color:#166534;">
                    <i data-lucide="check-circle" size="24" style="display:block;margin:0 auto .5rem;"></i>
                    <strong>Case Resolved</strong><br>
                    <span style="font-size:.85rem;">Resolved on <?php echo $selectedReturn['resolved_at'] ? date('M d, Y h:i A', strtotime($selectedReturn['resolved_at'])) : '—'; ?></span>
                </div>

                <?php else: ?>
                <div style="background:#fffbeb;border-radius:12px;padding:1.25rem;text-align:center;color:#b45309;">
                    <i data-lucide="clock" size="24" style="display:block;margin:0 auto .5rem;"></i>
                    <strong>Awaiting Admin Approval</strong><br>
                    <span style="font-size:.85rem;">This return needs admin approval before you can process it.</span>
                </div>
                <?php endif; ?>

                <!-- Audit trail -->
                <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--card-border);">
                    <div class="info-title">Audit Trail</div>
                    <div style="font-size:.8rem;color:var(--muted);line-height:1.8;">
                        <div>• Submitted: <?php echo date('M d, Y h:i A', strtotime($selectedReturn['submitted_at'])); ?></div>
                        <?php if ($selectedReturn['resolved_at']): ?>
                        <div>• Resolved: <?php echo date('M d, Y h:i A', strtotime($selectedReturn['resolved_at'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="card">
                <div class="no-select">
                    <i data-lucide="mouse-pointer-click" size="40"></i>
                    <p>Select a return case from the queue to view details and execute resolution.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<script>lucide.createIcons();</script>
</body>
</html>