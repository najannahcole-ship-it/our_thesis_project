<?php
// ============================================================
// encoder-reports.php — Transaction & Sales Reports
// Per thesis: Data Encoder can generate and export
// transaction-based reports summarizing sales, payments,
// and pending orders.
// DB Tables used:
//   READ → orders + order_items + products + franchisees + returns
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$encoderName = $_SESSION['full_name'] ?? 'Data Encoder';

// ── Date filter ───────────────────────────────────────────────
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');  // Today
$report   = $_GET['report']    ?? 'sales';         // sales | pending | returns | items

// ── Report: Sales Summary ─────────────────────────────────────
$salesData   = [];
$salesTotal  = 0;
$salesCount  = 0;

if ($report === 'sales' || $report === 'all') {
    $stmt = $conn->prepare("
        SELECT o.po_number, o.created_at, o.status, o.status_step,
               o.delivery_preference, o.subtotal, o.delivery_fee, o.total_amount,
               f.franchisee_name, f.branch_name
        FROM orders o
        LEFT JOIN franchisees f ON f.id = o.franchisee_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $salesData[]  = $row;
        $salesTotal  += $row['total_amount'];
        $salesCount++;
    }
    $stmt->close();
}

// ── Report: Pending Orders ─────────────────────────────────────
$pendingData = [];
if ($report === 'pending' || $report === 'all') {
    $stmt = $conn->prepare("
        SELECT o.po_number, o.created_at, o.status, o.status_step,
               o.delivery_preference, o.total_amount,
               f.franchisee_name, f.branch_name
        FROM orders o
        LEFT JOIN franchisees f ON f.id = o.franchisee_id
        WHERE o.status_step IN (1,2)
        AND DATE(o.created_at) BETWEEN ? AND ?
        ORDER BY o.created_at ASC
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $pendingData[] = $row; }
    $stmt->close();
}

// ── Report: Return Requests ────────────────────────────────────
$returnsData = [];
$returnsTotal = 0;
if ($report === 'returns' || $report === 'all') {
    $stmt = $conn->prepare("
        SELECT r.id, r.item_name, r.reason, r.status, r.submitted_at, r.resolved_at,
               o.po_number, f.franchisee_name, f.branch_name
        FROM returns r
        LEFT JOIN franchisees f ON f.id = r.franchisee_id
        LEFT JOIN orders o ON o.id = r.order_id
        WHERE DATE(r.submitted_at) BETWEEN ? AND ?
        ORDER BY r.submitted_at DESC
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $returnsData[] = $row; }
    $stmt->close();
}

// ── Report: Top Ordered Items ──────────────────────────────────
$itemsData = [];
if ($report === 'items' || $report === 'all') {
    $stmt = $conn->prepare("
        SELECT p.name, p.category, p.unit,
               SUM(oi.quantity) as total_qty,
               SUM(oi.subtotal) as total_revenue,
               COUNT(DISTINCT oi.order_id) as order_count
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        JOIN orders o ON o.id = oi.order_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY oi.product_id, p.name, p.category, p.unit
        ORDER BY total_qty DESC
        LIMIT 20
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $itemsData[] = $row; }
    $stmt->close();
}

// ── Summary stats for header cards ────────────────────────────
$totalRevenue  = 0; $totalOrders = 0; $pendingCount = 0; $completedCount = 0;
$r = $conn->query("SELECT COUNT(*) as c, COALESCE(SUM(total_amount),0) as t FROM orders WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'");
if ($row = $r->fetch_assoc()) { $totalOrders = $row['c']; $totalRevenue = $row['t']; }
$r = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status_step IN (1,2) AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'");
if ($row = $r->fetch_assoc()) { $pendingCount = $row['c']; }
$r = $conn->query("SELECT COUNT(*) as c FROM orders WHERE status_step = 4 AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'");
if ($row = $r->fetch_assoc()) { $completedCount = $row['c']; }
$r = $conn->query("SELECT COUNT(*) as c FROM returns WHERE DATE(submitted_at) BETWEEN '$dateFrom' AND '$dateTo'");
$returnCount = $r->fetch_assoc()['c'] ?? 0;

$conn->close();

function stepLabel($s) {
    $m=[0=>'Submitted',1=>'Under Review',2=>'Processing',3=>'Ready',4=>'Completed'];
    return $m[$s] ?? '—';
}
function stepClass($s) {
    $m=[0=>'s-submitted',1=>'s-review',2=>'s-processing',3=>'s-ready',4=>'s-completed'];
    return $m[$s] ?? 's-review';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Top Juan Inc.</title>
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
        .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:2rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);}
        /* Filter bar */
        .filter-bar{background:white;border:1px solid var(--card-border);border-radius:16px;padding:1.5rem;margin-bottom:2rem;display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;}
        .filter-group{display:flex;flex-direction:column;gap:.4rem;}
        .filter-group label{font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
        .filter-group input,.filter-group select{padding:.65rem .9rem;border:1.5px solid var(--card-border);border-radius:10px;font-family:inherit;font-size:.9rem;outline:none;background:white;}
        .filter-group input:focus,.filter-group select:focus{border-color:var(--primary);}
        .btn-generate{background:var(--primary);color:white;border:none;padding:.7rem 1.5rem;border-radius:10px;font-weight:600;font-family:inherit;font-size:.9rem;cursor:pointer;display:flex;align-items:center;gap:.5rem;align-self:flex-end;}
        .btn-generate:hover{background:var(--primary-light);}
        .btn-export{background:white;color:var(--primary);border:1.5px solid var(--card-border);padding:.7rem 1.25rem;border-radius:10px;font-weight:600;font-family:inherit;font-size:.9rem;cursor:pointer;display:flex;align-items:center;gap:.5rem;align-self:flex-end;}
        .btn-export:hover{border-color:var(--primary);}
        /* Tab buttons */
        .report-tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap;}
        .tab-btn{padding:.6rem 1.25rem;border-radius:10px;border:1.5px solid var(--card-border);background:white;font-family:inherit;font-size:.875rem;font-weight:600;cursor:pointer;color:var(--muted);text-decoration:none;transition:all .2s;}
        .tab-btn:hover{border-color:var(--primary);color:var(--primary);}
        .tab-btn.active{background:var(--primary);color:white;border-color:var(--primary);}
        /* Stats */
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:2rem;}
        .stat-card{background:white;border:1px solid var(--card-border);border-radius:16px;padding:1.5rem;position:relative;}
        .stat-card .icon-b{position:absolute;top:1.25rem;right:1.25rem;color:var(--muted);}
        .stat-card .s-label{font-size:.85rem;color:var(--muted);margin-bottom:.4rem;font-weight:500;}
        .stat-card .s-value{font-size:1.75rem;font-weight:700;font-family:'Fraunces',serif;}
        .stat-card .s-sub{font-size:.78rem;color:var(--muted);margin-top:.3rem;}
        /* Table */
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;overflow:hidden;}
        .card-header-row{display:flex;justify-content:space-between;align-items:center;padding:1.5rem 2rem;border-bottom:1px solid var(--card-border);}
        .card-header-row h3{font-family:'Fraunces',serif;font-size:1.2rem;}
        .card-header-row .meta{font-size:.82rem;color:var(--muted);}
        table{width:100%;border-collapse:collapse;}
        th{text-align:left;padding:1rem 1.5rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid var(--card-border);font-weight:700;}
        td{padding:1.1rem 1.5rem;font-size:.9rem;border-bottom:1px solid var(--card-border);}
        tr:last-child td{border-bottom:none;}
        tr:hover td{background:#fafafa;}
        .status-pill{padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-block;}
        .s-submitted{background:rgba(59,130,246,.1);color:#3b82f6;}
        .s-review{background:#fffbeb;color:#b45309;}
        .s-processing{background:rgba(210,84,36,.1);color:var(--accent);}
        .s-ready{background:#f0fdf4;color:#166534;}
        .s-completed{background:#f3f4f6;color:#4b5563;}
        .s-pending{background:rgba(210,84,36,.1);color:var(--accent);}
        .s-approved{background:rgba(16,185,129,.1);color:#10b981;}
        .s-resolved{background:#f1f5f9;color:#64748b;}
        .empty-row td{text-align:center;color:var(--muted);padding:3rem;}
        /* Print styles */
        @media print {
            aside,.filter-bar,.report-tabs,.btn-export,.btn-generate{display:none!important;}
            main{margin-left:0!important;padding:1rem!important;}
            .stats-grid{grid-template-columns:repeat(4,1fr);}
        }
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="coffee"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Data Encoder</span></div></div>
    <p class="menu-label">Main Menu</p>
    <nav>
        <a href="encoder-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i>Dashboard</a>
        <a href="encoder-orders.php" class="nav-item"><i data-lucide="shopping-bag"></i>Order Process</a>
        <a href="encoder-returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i>Return and Refund</a>
        <a href="encoder-reports.php" class="nav-item active"><i data-lucide="file-text"></i>Reports</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($encoderName); ?></h4><p>Data Encoder</p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i><span>Sign Out</span></a>
</aside>

<main>
    <div class="header">
        <div><h2>Reports</h2><p>Generate and export transaction-based reports for the selected period.</p></div>
        <div style="display:flex;gap:.75rem;">
            <button class="btn-export" onclick="window.print()"><i data-lucide="printer" size="16"></i> Print</button>
            <button class="btn-export" onclick="exportCSV()"><i data-lucide="download" size="16"></i> Export CSV</button>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="encoder-reports.php" class="filter-bar">
        <input type="hidden" name="report" value="<?php echo htmlspecialchars($report); ?>">
        <div class="filter-group">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="filter-group">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="filter-group">
            <label>Report Type</label>
            <select name="report">
                <option value="sales"   <?php echo $report==='sales'  ?'selected':''; ?>>Sales Transactions</option>
                <option value="pending" <?php echo $report==='pending'?'selected':''; ?>>Pending Orders</option>
                <option value="returns" <?php echo $report==='returns'?'selected':''; ?>>Return Requests</option>
                <option value="items"   <?php echo $report==='items'  ?'selected':''; ?>>Top Ordered Items</option>
            </select>
        </div>
        <button type="submit" class="btn-generate"><i data-lucide="bar-chart-2" size="16"></i> Generate Report</button>
    </form>

    <!-- Summary Stats (always shown, based on date range) -->
    <div class="stats-grid">
        <div class="stat-card">
            <i data-lucide="shopping-bag" class="icon-b" style="color:var(--primary)"></i>
            <div class="s-label">Total Orders</div>
            <div class="s-value"><?php echo $totalOrders; ?></div>
            <div class="s-sub"><?php echo date('M d', strtotime($dateFrom)); ?> – <?php echo date('M d, Y', strtotime($dateTo)); ?></div>
        </div>
        <div class="stat-card">
            <i data-lucide="trending-up" class="icon-b" style="color:#10b981"></i>
            <div class="s-label">Total Revenue</div>
            <div class="s-value">₱<?php echo number_format($totalRevenue, 0); ?></div>
            <div class="s-sub">Gross order value</div>
        </div>
        <div class="stat-card">
            <i data-lucide="clock" class="icon-b" style="color:var(--accent)"></i>
            <div class="s-label">Pending Orders</div>
            <div class="s-value"><?php echo $pendingCount; ?></div>
            <div class="s-sub">Needs processing</div>
        </div>
        <div class="stat-card">
            <i data-lucide="rotate-ccw" class="icon-b" style="color:#ef4444"></i>
            <div class="s-label">Return Requests</div>
            <div class="s-value"><?php echo $returnCount; ?></div>
            <div class="s-sub">In this period</div>
        </div>
    </div>

    <!-- ── Sales Transactions Report ── -->
    <?php if ($report === 'sales'): ?>
    <div class="card">
        <div class="card-header-row">
            <h3>Sales Transactions Report</h3>
            <span class="meta"><?php echo count($salesData); ?> orders · Total: ₱<?php echo number_format($salesTotal, 2); ?></span>
        </div>
        <table id="reportTable">
            <thead><tr><th>P.O. Number</th><th>Franchisee</th><th>Branch</th><th>Date</th><th>Delivery</th><th>Subtotal</th><th>Fee</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($salesData)): ?>
                <tr class="empty-row"><td colspan="9">No transactions found for this period.</td></tr>
                <?php else: foreach ($salesData as $s): ?>
                <tr>
                    <td style="font-weight:700;"><?php echo htmlspecialchars($s['po_number']); ?></td>
                    <td><?php echo htmlspecialchars($s['franchisee_name'] ?? '—'); ?></td>
                    <td style="font-size:.82rem;color:var(--muted)"><?php echo htmlspecialchars($s['branch_name'] ?? '—'); ?></td>
                    <td style="font-size:.82rem;"><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                    <td style="font-size:.82rem;"><?php echo htmlspecialchars($s['delivery_preference']); ?></td>
                    <td>₱<?php echo number_format($s['subtotal'], 2); ?></td>
                    <td>₱<?php echo number_format($s['delivery_fee'], 2); ?></td>
                    <td style="font-weight:700;">₱<?php echo number_format($s['total_amount'], 2); ?></td>
                    <td><span class="status-pill <?php echo stepClass($s['status_step']); ?>"><?php echo stepLabel($s['status_step']); ?></span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Pending Orders Report ── -->
    <?php elseif ($report === 'pending'): ?>
    <div class="card">
        <div class="card-header-row">
            <h3>Pending Orders Report</h3>
            <span class="meta"><?php echo count($pendingData); ?> orders pending action</span>
        </div>
        <table id="reportTable">
            <thead><tr><th>P.O. Number</th><th>Franchisee</th><th>Branch</th><th>Submitted</th><th>Delivery</th><th>Total</th><th>Current Status</th></tr></thead>
            <tbody>
                <?php if (empty($pendingData)): ?>
                <tr class="empty-row"><td colspan="7">No pending orders for this period.</td></tr>
                <?php else: foreach ($pendingData as $p): ?>
                <tr>
                    <td style="font-weight:700;"><a href="encoder-orders.php?po=<?php echo urlencode($p['po_number']); ?>" style="color:var(--primary);text-decoration:none;"><?php echo htmlspecialchars($p['po_number']); ?></a></td>
                    <td><?php echo htmlspecialchars($p['franchisee_name'] ?? '—'); ?></td>
                    <td style="font-size:.82rem;color:var(--muted)"><?php echo htmlspecialchars($p['branch_name'] ?? '—'); ?></td>
                    <td style="font-size:.82rem;"><?php echo date('M d, Y h:i A', strtotime($p['created_at'])); ?></td>
                    <td style="font-size:.82rem;"><?php echo htmlspecialchars($p['delivery_preference']); ?></td>
                    <td style="font-weight:700;">₱<?php echo number_format($p['total_amount'], 2); ?></td>
                    <td><span class="status-pill <?php echo stepClass($p['status_step']); ?>"><?php echo stepLabel($p['status_step']); ?></span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Returns Report ── -->
    <?php elseif ($report === 'returns'): ?>
    <div class="card">
        <div class="card-header-row">
            <h3>Return Requests Report</h3>
            <span class="meta"><?php echo count($returnsData); ?> return cases</span>
        </div>
        <table id="reportTable">
            <thead><tr><th>Return ID</th><th>Franchisee</th><th>Item</th><th>Reason</th><th>Linked Order</th><th>Submitted</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($returnsData)): ?>
                <tr class="empty-row"><td colspan="7">No return requests for this period.</td></tr>
                <?php else: foreach ($returnsData as $ret): ?>
                <tr>
                    <td style="font-weight:700;">#RET-<?php echo str_pad($ret['id'], 4, '0', STR_PAD_LEFT); ?></td>
                    <td><?php echo htmlspecialchars($ret['franchisee_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($ret['item_name']); ?></td>
                    <td style="font-size:.82rem;"><?php echo htmlspecialchars($ret['reason']); ?></td>
                    <td style="font-size:.82rem;"><?php echo $ret['po_number'] ? htmlspecialchars($ret['po_number']) : '—'; ?></td>
                    <td style="font-size:.82rem;"><?php echo date('M d, Y', strtotime($ret['submitted_at'])); ?></td>
                    <td><span class="status-pill s-<?php echo strtolower($ret['status']); ?>"><?php echo htmlspecialchars($ret['status']); ?></span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Top Ordered Items Report ── -->
    <?php elseif ($report === 'items'): ?>
    <div class="card">
        <div class="card-header-row">
            <h3>Top Ordered Items Report</h3>
            <span class="meta"><?php echo count($itemsData); ?> products ordered in this period</span>
        </div>
        <table id="reportTable">
            <thead><tr><th>Rank</th><th>Product Name</th><th>Category</th><th>Unit</th><th>Total Qty Ordered</th><th>Appeared In Orders</th><th>Total Revenue</th></tr></thead>
            <tbody>
                <?php if (empty($itemsData)): ?>
                <tr class="empty-row"><td colspan="7">No order data for this period.</td></tr>
                <?php else: foreach ($itemsData as $idx => $item): ?>
                <tr>
                    <td style="font-weight:700;color:var(--muted);">#<?php echo $idx + 1; ?></td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($item['name']); ?></td>
                    <td style="font-size:.82rem;color:var(--muted);"><?php echo htmlspecialchars($item['category']); ?></td>
                    <td style="font-size:.82rem;"><?php echo htmlspecialchars($item['unit']); ?></td>
                    <td style="font-weight:700;"><?php echo number_format($item['total_qty']); ?></td>
                    <td><?php echo number_format($item['order_count']); ?> orders</td>
                    <td style="font-weight:700;">₱<?php echo number_format($item['total_revenue'], 2); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>

<script>
lucide.createIcons();

function exportCSV() {
    const table = document.getElementById('reportTable');
    if (!table) return;

    let csv = [];
    // Header row
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => '"' + th.innerText.trim() + '"');
    csv.push(headers.join(','));

    // Data rows
    table.querySelectorAll('tbody tr').forEach(row => {
        const cells = Array.from(row.querySelectorAll('td')).map(td => {
            let text = td.innerText.trim().replace(/"/g, '""');
            return '"' + text + '"';
        });
        if (cells.length > 1) csv.push(cells.join(','));
    });

    const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', 'juancafe_report_<?php echo $report; ?>_<?php echo $dateFrom; ?>_to_<?php echo $dateTo; ?>.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>
