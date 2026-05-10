<?php
// ============================================================
// history.php — Transaction History
// DB Tables used:
//   READ → orders       (all orders for this franchisee)
//   READ → order_items  (count items per order)
//   READ → products     (get item names for the summary column)
//   READ → franchisees  (get franchisee_id for logged-in user)
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

// ── Fetch all orders for this franchisee ─────────────────────
// Join with order_items to build a readable item summary string
$orders = [];

if ($franchiseeId) {
    // Fetch orders (newest first)
    $stmt = $conn->prepare("
        SELECT o.id, o.po_number, o.created_at, o.status, o.status_step, o.total_amount, o.delivery_preference
        FROM orders o
        WHERE o.franchisee_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Get item names for this order
        $iStmt = $conn->prepare("
            SELECT p.name, oi.quantity
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
            LIMIT 3
        ");
        $iStmt->bind_param("i", $row['id']);
        $iStmt->execute();
        $iResult = $iStmt->get_result();
        $itemNames = [];
        while ($iRow = $iResult->fetch_assoc()) {
            $itemNames[] = $iRow['name'] . ' ×' . $iRow['quantity'];
        }
        $iStmt->close();

        // Count total items
        $cStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM order_items WHERE order_id = ?");
        $cStmt->bind_param("i", $row['id']);
        $cStmt->execute();
        $total_items = $cStmt->get_result()->fetch_assoc()['cnt'];
        $cStmt->close();

        $row['item_summary'] = implode(', ', $itemNames);
        if ($total_items > 3) $row['item_summary'] .= ', +' . ($total_items - 3) . ' more';
        $orders[] = $row;
    }
    $stmt->close();
}

$conn->close();

function statusLabel($step) {
    $map = [0=>'Submitted', 1=>'Under Review', 2=>'Processing', 3=>'Ready', 4=>'Completed'];
    return $map[$step] ?? 'Unknown';
}
function statusCSS($step) {
    $map = [0=>'status-review', 1=>'status-review', 2=>'status-processing', 3=>'status-ready', 4=>'status-completed'];
    return $map[$step] ?? 'status-review';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History - Juan Café</title>
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
        .nav-item i{width:20px;height:20px;}
        .nav-item:hover{color:var(--primary);background:rgba(92,64,51,.05);}
        .nav-item.active{background:var(--primary);color:white;}
        .user-profile{margin-top:auto;background:white;border:1px solid var(--card-border);padding:1rem;border-radius:16px;display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;}
        .avatar{width:36px;height:36px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;}
        .avatar i{color:var(--muted);}
        .user-meta h4{font-size:.85rem;font-weight:700;}
        .user-meta p{font-size:.75rem;color:var(--muted);}
        .sign-out{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--muted);font-size:.9rem;padding:.5rem;}
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:2rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;}
        .header p{color:var(--muted);}
        .filters{display:flex;gap:1rem;margin-bottom:1.5rem;}
        .search-wrapper{position:relative;flex:1;}
        .search-wrapper i{position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--muted);width:18px;height:18px;}
        .search-wrapper input{width:100%;padding:.75rem 1rem .75rem 2.75rem;border:1px solid var(--card-border);border-radius:12px;font-family:inherit;font-size:.95rem;background:white;}
        .filter-select{padding:.75rem 1rem;border:1px solid var(--card-border);border-radius:12px;font-family:inherit;font-size:.95rem;background:white;color:var(--foreground);cursor:pointer;}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:0;overflow:hidden;}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:1rem 1.5rem;font-size:.8rem;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--card-border);font-weight:700;letter-spacing:.05em;}
        td{padding:1.25rem 1.5rem;font-size:.95rem;border-bottom:1px solid var(--card-border);}
        tr:last-child td{border-bottom:none;}
        tr:hover td{background:#fafafa;}
        .po-link{color:var(--primary);text-decoration:none;font-weight:700;}
        .po-link:hover{text-decoration:underline;}
        .amount{font-weight:600;text-align:right;}
        th.amount-th{text-align:right;}
        .status-pill{padding:.35rem .75rem;border-radius:20px;font-size:.8rem;font-weight:600;display:inline-block;}
        .status-completed{background:#dcfce7;color:#166534;}
        .status-review{background:#fffbeb;color:#b45309;}
        .status-processing{background:#eff6ff;color:#1d4ed8;}
        .status-ready{background:#f0fdf4;color:#166534;}
        .action-btn{background:none;border:none;color:var(--muted);cursor:pointer;padding:.5rem;border-radius:8px;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;}
        .action-btn:hover{background:var(--background);color:var(--primary);}
        .empty-row td{text-align:center;color:var(--muted);padding:3rem;}
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
        <a href="returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i> Returns</a>
        <a href="order-history.php" class="nav-item active"><i data-lucide="history"></i> Order History</a>
        <a href="profile.php" class="nav-item"><i data-lucide="user"></i> Profile</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($fullName); ?></h4><p style="font-size:.72rem;color:var(--muted);font-weight:500;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? '—'); ?></p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
</aside>

<main>
    <div class="header">
        <div><h2>History</h2><p>All your past and active purchase orders</p></div>
    </div>

    <div class="filters">
        <div class="search-wrapper">
            <i data-lucide="search"></i>
            <input type="text" placeholder="Search PO number or items..." id="searchInput">
        </div>
        <select class="filter-select" id="statusFilter">
            <option value="all">All Status</option>
            <option value="Under Review">Under Review</option>
            <option value="Processing">Processing</option>
            <option value="Ready">Ready</option>
            <option value="Completed">Completed</option>
        </select>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>P.O. Number</th>
                    <th>Date</th>
                    <th>Items</th>
                    <th class="amount-th">Total Amount</th>
                    <th>Status</th>
                    <th style="width:50px;"></th>
                </tr>
            </thead>
            <tbody id="historyBody">
                <?php if (empty($orders)): ?>
                <tr class="empty-row">
                    <td colspan="6">No orders found. <a href="order-form.php" style="color:var(--primary)">Place your first order →</a></td>
                </tr>
                <?php else: foreach ($orders as $o):
                    $label = statusLabel($o['status_step']);
                    $css   = statusCSS($o['status_step']);
                ?>
                <tr data-status="<?php echo htmlspecialchars($label); ?>" data-search="<?php echo strtolower(htmlspecialchars($o['po_number'] . ' ' . $o['item_summary'])); ?>">
                    <td><a href="order-status.php?po=<?php echo urlencode($o['po_number']); ?>" class="po-link"><?php echo htmlspecialchars($o['po_number']); ?></a></td>
                    <td style="color:var(--muted);"><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
                    <td style="max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($o['item_summary']); ?>">
                        <?php echo htmlspecialchars($o['item_summary'] ?: '—'); ?>
                    </td>
                    <td class="amount">₱<?php echo number_format($o['total_amount'], 2); ?></td>
                    <td><span class="status-pill <?php echo $css; ?>"><?php echo $label; ?></span></td>
                    <td>
                        <a href="order-status.php?po=<?php echo urlencode($o['po_number']); ?>" class="action-btn" title="Track Order">
                            <i data-lucide="eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</main>

<script>
    lucide.createIcons();

    const searchInput  = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');

    function applyFilters() {
        const term   = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        document.querySelectorAll('#historyBody tr[data-status]').forEach(row => {
            const matchSearch = row.dataset.search.includes(term);
            const matchStatus = status === 'all' || row.dataset.status === status;
            row.style.display = (matchSearch && matchStatus) ? '' : 'none';
        });
    }

    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
</script>
</body>
</html>