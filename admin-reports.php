<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: index.php');
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$adminName = $_SESSION['full_name'] ?? 'System Admin';

require_once 'db.php';

// ── CSV Export Handler ────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $category  = $_GET['category']  ?? 'transactions';
    $dateRange = $_GET['date_range'] ?? '30';
    $franchise = intval($_GET['franchise'] ?? 0);

    $dfO = match($dateRange) {
        '7'   => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        '30'  => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        '90'  => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
        '180' => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)",
        'all' => "",
        default => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    };
    $ffO = $franchise > 0 ? "AND o.franchisee_id = " . intval($franchise) : "";
    $ffF = $franchise > 0 ? "AND f.id = "            . intval($franchise) : "";

    $filename = 'TopJuan_' . ucfirst($category) . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    if ($category === 'transactions') {
        fputcsv($out, ['PO Number', 'Franchisee', 'Branch', 'Status', 'Delivery Type', 'Subtotal', 'Delivery Fee', 'Total Amount', 'Date Placed']);
        $res = $conn->query("
            SELECT o.po_number, f.franchisee_name, f.branch_name, o.status,
                   o.delivery_preference, o.subtotal, o.delivery_fee, o.total_amount, o.created_at
            FROM orders o JOIN franchisees f ON f.id = o.franchisee_id
            WHERE 1=1 $dfO $ffO
            ORDER BY o.created_at DESC
        ");
        while ($row = $res->fetch_assoc()) fputcsv($out, $row);

    } elseif ($category === 'inventory') {
        fputcsv($out, ['Product', 'Category', 'Unit', 'Price (₱)', 'Stock Qty', 'Status']);
        $res = $conn->query("SELECT name, category, unit, price, stock_qty, status FROM products ORDER BY category, name");
        while ($row = $res->fetch_assoc()) fputcsv($out, $row);

    } elseif ($category === 'item_usage') {
        $dfU = match($dateRange) {
            '7'   => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30'  => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '90'  => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
            '180' => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 180 DAY)",
            'all' => "",
            default => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        };
        $ffU = $franchise > 0 ? "AND iu.franchisee_id = " . intval($franchise) : "";
        fputcsv($out, ['Branch', 'Franchisee', 'Product', 'Category', 'Qty Used', 'Unit', 'Recording Date', 'Submitted At']);
        $res = $conn->query("
            SELECT f.branch_name, f.franchisee_name,
                   p.name AS product, p.category,
                   iu.quantity_used, iu.unit, iu.recording_date, iu.submitted_at
            FROM item_usage iu
            JOIN franchisees f ON f.id = iu.franchisee_id
            JOIN products    p ON p.id = iu.product_id
            WHERE 1=1 $dfU $ffU
            ORDER BY iu.recording_date DESC
        ");
        while ($row = $res->fetch_assoc()) fputcsv($out, $row);

    } elseif ($category === 'deliveries') {
        fputcsv($out, ['PO Number', 'Franchisee', 'Branch', 'Delivery Type', 'Delivery Fee', 'Total Amount', 'Estimated Pickup', 'Current Status', 'Date Placed']);
        $res = $conn->query("
            SELECT o.po_number, f.franchisee_name, f.branch_name,
                   o.delivery_preference, o.delivery_fee, o.total_amount,
                   o.estimated_pickup, o.status, o.created_at
            FROM orders o
            JOIN franchisees f ON f.id = o.franchisee_id
            WHERE o.delivery_preference != 'Self Pickup' $dfO $ffO
            ORDER BY o.created_at DESC
        ");
        while ($row = $res->fetch_assoc()) fputcsv($out, $row);

    } elseif ($category === 'returns') {
        $dfR = match($dateRange) {
            '7'   => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30'  => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '90'  => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
            '180' => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)",
            'all' => "",
            default => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        };
        $ffR = $franchise > 0 ? "AND r.franchisee_id = " . intval($franchise) : "";
        fputcsv($out, ['PO Number', 'Branch', 'Franchisee', 'Item Returned', 'Reason', 'Notes', 'Status', 'Submitted At', 'Resolved At']);
        $res = $conn->query("
            SELECT o.po_number, f.branch_name, f.franchisee_name,
                   r.item_name, r.reason, r.notes, r.status,
                   r.submitted_at, r.resolved_at
            FROM returns r
            JOIN franchisees f ON f.id = r.franchisee_id
            LEFT JOIN orders  o ON o.id = r.order_id
            WHERE 1=1 $dfR $ffR
            ORDER BY r.submitted_at DESC
        ");
        while ($row = $res->fetch_assoc()) fputcsv($out, $row);
    }

    fclose($out);
    $conn->close();
    exit();
}

// ── Filter Params ─────────────────────────────────────────────
$category  = $_GET['category']  ?? 'transactions';
$dateRange = $_GET['date_range'] ?? '30';
$franchise = intval($_GET['franchise'] ?? 0);

$dfO = match($dateRange) {
    '7'   => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
    '30'  => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    '90'  => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
    '180' => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)",
    'all' => "",
    default => "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
};

$ffO = $franchise > 0 ? "AND o.franchisee_id = " . intval($franchise) : "";
$ffF = $franchise > 0 ? "AND f.id = "            . intval($franchise) : "";

// ── Summary Cards ─────────────────────────────────────────────
$totalOrders = $conn->query("
    SELECT COUNT(*) AS c FROM orders
")->fetch_assoc()['c'];

$totalRevenue = $conn->query("
    SELECT COALESCE(SUM(total_amount),0) AS r
    FROM orders WHERE status IN ('Approved','Processing','Ready','Completed')
")->fetch_assoc()['r'];

$totalDeliveries = $conn->query("
    SELECT COUNT(*) AS c FROM orders
    WHERE delivery_preference != 'Self Pickup'
      AND status IN ('Approved','Processing','Ready','Completed')
")->fetch_assoc()['c'];

$pendingReturns = $conn->query("
    SELECT COUNT(*) AS c FROM returns WHERE status = 'Pending'
")->fetch_assoc()['c'];

// ── All Franchisees for filter dropdown ───────────────────────
$franchises = [];
$fRes = $conn->query("SELECT id, branch_name, franchisee_name FROM franchisees ORDER BY branch_name");
while ($row = $fRes->fetch_assoc()) $franchises[] = $row;

// ── Report Data based on category ────────────────────────────
$reportRows   = [];
$chartLabels  = [];
$chartValues  = [];
$tableHeaders = [];
$reportTitle  = '';
$chartIsMoney = false;

if ($category === 'transactions') {
    $reportTitle  = 'Transaction Records';
    $tableHeaders = ['PO Number', 'Franchisee', 'Branch', 'Status', 'Delivery Type', 'Subtotal', 'Delivery Fee', 'Total', 'Date'];

    $res = $conn->query("
        SELECT o.po_number, f.franchisee_name, f.branch_name, o.status,
               o.delivery_preference, o.subtotal, o.delivery_fee, o.total_amount, o.created_at
        FROM orders o
        JOIN franchisees f ON f.id = o.franchisee_id
        WHERE 1=1 $dfO $ffO
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    while ($row = $res->fetch_assoc()) $reportRows[] = $row;

    // Chart: revenue by order status
    $chartIsMoney = true;
    $cRes = $conn->query("
        SELECT o.status, COALESCE(SUM(o.total_amount), 0) AS total
        FROM orders o
        WHERE 1=1 $dfO $ffO
        GROUP BY o.status
        ORDER BY total DESC
    ");
    while ($row = $cRes->fetch_assoc()) {
        $chartLabels[] = $row['status'];
        $chartValues[] = (float)$row['total'];
    }

} elseif ($category === 'inventory') {
    $reportTitle  = 'Inventory Status';
    $tableHeaders = ['Product', 'Category', 'Unit', 'Price', 'Stock Qty', 'Status'];

    $res = $conn->query("
        SELECT name, category, unit, price, stock_qty, status
        FROM products
        ORDER BY category, name
    ");
    while ($row = $res->fetch_assoc()) $reportRows[] = $row;

    // Chart: product count per category
    $cRes = $conn->query("
        SELECT category, COUNT(*) AS cnt
        FROM products
        GROUP BY category
        ORDER BY cnt DESC
    ");
    while ($row = $cRes->fetch_assoc()) {
        $chartLabels[] = $row['category'];
        $chartValues[] = (int)$row['cnt'];
    }

} elseif ($category === 'item_usage') {
    $reportTitle  = 'Item Usage Report';
    $tableHeaders = ['Branch', 'Franchisee', 'Product', 'Category', 'Qty Used', 'Unit', 'Recording Date'];

    $dfU = match($dateRange) {
        '7'   => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        '30'  => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        '90'  => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
        '180' => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 180 DAY)",
        'all' => "",
        default => "AND iu.recording_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    };
    $ffU = $franchise > 0 ? "AND iu.franchisee_id = " . intval($franchise) : "";

    $res = $conn->query("
        SELECT f.branch_name, f.franchisee_name,
               p.name AS product, p.category,
               iu.quantity_used, iu.unit, iu.recording_date
        FROM item_usage iu
        JOIN franchisees f ON f.id = iu.franchisee_id
        JOIN products    p ON p.id = iu.product_id
        WHERE 1=1 $dfU $ffU
        ORDER BY iu.recording_date DESC
        LIMIT 100
    ");
    while ($row = $res->fetch_assoc()) $reportRows[] = $row;

    // Chart: top 8 most used products by total qty
    $cRes = $conn->query("
        SELECT p.name, COALESCE(SUM(iu.quantity_used), 0) AS total_used
        FROM products p
        JOIN item_usage iu ON iu.product_id = p.id
        WHERE 1=1 $dfU $ffU
        GROUP BY p.id
        ORDER BY total_used DESC
        LIMIT 8
    ");
    while ($row = $cRes->fetch_assoc()) {
        $chartLabels[] = mb_strlen($row['name']) > 16
            ? mb_substr($row['name'], 0, 14) . '…'
            : $row['name'];
        $chartValues[] = (int)$row['total_used'];
    }

} elseif ($category === 'deliveries') {
    $reportTitle  = 'Delivery Report';
    $tableHeaders = ['PO Number', 'Franchisee', 'Branch', 'Delivery Type', 'Delivery Fee', 'Total', 'Est. Pickup', 'Status', 'Date'];

    $res = $conn->query("
        SELECT o.po_number, f.franchisee_name, f.branch_name,
               o.delivery_preference, o.delivery_fee, o.total_amount,
               o.estimated_pickup, o.status, o.created_at
        FROM orders o
        JOIN franchisees f ON f.id = o.franchisee_id
        WHERE o.delivery_preference != 'Self Pickup' $dfO $ffO
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    while ($row = $res->fetch_assoc()) $reportRows[] = $row;

    // Chart: delivery count by type
    $cRes = $conn->query("
        SELECT o.delivery_preference, COUNT(*) AS cnt
        FROM orders o
        WHERE o.delivery_preference != 'Self Pickup' $dfO $ffO
        GROUP BY o.delivery_preference
        ORDER BY cnt DESC
    ");
    while ($row = $cRes->fetch_assoc()) {
        $chartLabels[] = $row['delivery_preference'];
        $chartValues[] = (int)$row['cnt'];
    }

} elseif ($category === 'returns') {
    $reportTitle  = 'Returns & Refunds';
    $tableHeaders = ['PO Number', 'Branch', 'Franchisee', 'Item Returned', 'Reason', 'Status', 'Submitted At'];

    $dfR = match($dateRange) {
        '7'   => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        '30'  => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        '90'  => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
        '180' => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)",
        'all' => "",
        default => "AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    };
    $ffR = $franchise > 0 ? "AND r.franchisee_id = " . intval($franchise) : "";

    $res = $conn->query("
        SELECT o.po_number, f.branch_name, f.franchisee_name,
               r.item_name, r.reason, r.status, r.submitted_at
        FROM returns r
        JOIN franchisees f ON f.id = r.franchisee_id
        LEFT JOIN orders  o ON o.id = r.order_id
        WHERE 1=1 $dfR $ffR
        ORDER BY r.submitted_at DESC
        LIMIT 100
    ");
    while ($row = $res->fetch_assoc()) $reportRows[] = $row;

    // Chart: returns by status
    $cRes = $conn->query("
        SELECT r.status, COUNT(*) AS cnt
        FROM returns r
        WHERE 1=1 $dfR $ffR
        GROUP BY r.status
    ");
    while ($row = $cRes->fetch_assoc()) {
        $chartLabels[] = $row['status'];
        $chartValues[] = (int)$row['cnt'];
    }
}

// ── Top Branches Leaderboard (sidebar) ───────────────────────
$topBranches = [];
$tbRes = $conn->query("
    SELECT f.branch_name,
           COUNT(o.id)                      AS orders,
           COALESCE(SUM(o.total_amount), 0) AS revenue
    FROM franchisees f
    LEFT JOIN orders o ON o.franchisee_id = f.id
        AND o.status IN ('Approved','Processing','Ready','Completed')
    GROUP BY f.id
    HAVING orders > 0
    ORDER BY revenue DESC
    LIMIT 5
");
while ($row = $tbRes->fetch_assoc()) $topBranches[] = $row;

$conn->close();

function exportUrl($cat, $dr, $fr) {
    return 'admin-reports.php?export=csv'
         . '&category='   . urlencode($cat)
         . '&date_range=' . urlencode($dr)
         . '&franchise='  . intval($fr);
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
        :root {
            --background: #f7f3f0;
            --foreground: #2d241e;
            --sidebar-bg: #fdfaf7;
            --card: #ffffff;
            --card-border: #eeeae6;
            --primary: #5c4033;
            --primary-light: #8b5e3c;
            --accent: #d25424;
            --muted: #8c837d;
            --radius: 16px;
            --sidebar-width: 280px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background-color: var(--background); color: var(--foreground); display: flex; min-height: 100vh; }

        /* ── Sidebar ── */
        aside { width: var(--sidebar-width); background: var(--sidebar-bg); border-right: 1px solid var(--card-border); padding: 2rem 1.5rem; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 10; }
        .logo-container { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2.5rem; }
        .logo-icon { width: 40px; height: 40px; background: var(--primary); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; }
        .logo-text h1 { font-family: 'Fraunces', serif; font-size: 1.25rem; line-height: 1; }
        .logo-text span { font-size: 0.75rem; color: var(--muted); font-weight: 500; }
        .menu-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); margin-bottom: 1rem; font-weight: 700; }
        nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
        .nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.875rem 1rem; border-radius: 12px; text-decoration: none; color: var(--muted); font-weight: 500; font-size: 0.95rem; transition: all 0.2s; }
        .nav-item i { width: 20px; height: 20px; stroke-width: 2px; }
        .nav-item:hover { color: var(--primary); background: rgba(92,64,51,0.05); }
        .nav-item.active { background: var(--primary); color: white; }
        .user-profile { margin-top: auto; background: white; border: 1px solid var(--card-border); padding: 1rem; border-radius: 16px; display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; }
        .avatar i { color: var(--muted); }
        .user-meta h4 { font-size: 0.85rem; font-weight: 700; }
        .user-meta p { font-size: 0.75rem; color: var(--muted); }
        .sign-out { display: flex; align-items: center; gap: 0.5rem; text-decoration: none; color: var(--muted); font-size: 0.9rem; padding: 0.5rem; transition: color 0.2s; }
        .sign-out:hover { color: var(--accent); }

        /* ── Main ── */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 2.5rem 3rem; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2.5rem; }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p { color: var(--muted); font-size: 1rem; }

        /* ── Summary Cards ── */
        .summary-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 1.5rem; margin-bottom: 2.5rem; }
        .summary-card { background: white; border: 1px solid var(--card-border); padding: 1.75rem; border-radius: 20px; position: relative; }
        .summary-card .icon-badge { position: absolute; top: 1.75rem; right: 1.75rem; color: var(--muted); }
        .summary-card .label { font-size: 0.9rem; color: var(--muted); margin-bottom: 0.5rem; font-weight: 500; }
        .summary-card .value { font-size: 1.85rem; font-weight: 700; font-family: 'Fraunces', serif; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .summary-card .subtext { font-size: 0.8rem; color: var(--muted); margin-top: 0.5rem; }

        /* ── Layout ── */
        .report-layout { display: grid; grid-template-columns: 1fr 320px; gap: 1.5rem; align-items: start; }
        .card { background: white; border: 1px solid var(--card-border); border-radius: 20px; padding: 2rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }

        /* ── Filter Form ── */
        .filter-bar { display: grid; grid-template-columns: repeat(4,1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .control-group label { display: block; font-size: 0.75rem; font-weight: 700; color: var(--muted); margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .control-group select { width: 100%; padding: 0.65rem 1rem; border: 1px solid var(--card-border); border-radius: 10px; font-family: inherit; font-size: 0.9rem; background: white; color: var(--foreground); outline: none; cursor: pointer; }
        .control-group select:focus { border-color: var(--primary-light); }

        .btn-export { padding: 0.75rem 1.25rem; border-radius: 10px; border: 1px solid var(--card-border); background: white; color: var(--primary); font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; transition: all 0.2s; text-decoration: none; }
        .btn-export:hover { background: var(--background); }

        /* ── Chart ── */
        .chart-wrap { margin-bottom: 1.5rem; background: #faf8f6; border-radius: 14px; padding: 1.25rem; }
        .chart-title { font-size: 0.78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; }
        canvas { display: block; width: 100%; }

        /* ── Table ── */
        .table-wrap { overflow-x: auto; border: 1px solid var(--card-border); border-radius: 14px; overflow: hidden; }
        .table-wrap table { width: 100%; border-collapse: collapse; }
        .table-wrap th { text-align: left; padding: 0.85rem 1.25rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); background: #faf8f6; font-weight: 700; }
        .table-wrap td { padding: 1rem 1.25rem; font-size: 0.88rem; border-bottom: 1px solid var(--card-border); }
        .table-wrap tr:last-child td { border-bottom: none; }
        .table-wrap tr:hover td { background: #fdfaf7; }

        .status-pill { padding: 0.3rem 0.65rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .pill-pending   { background: #fffbeb; color: #b45309; }
        .pill-approved  { background: #f0fdf4; color: #166534; }
        .pill-rejected  { background: #fef2f2; color: #991b1b; }
        .pill-completed { background: #eff6ff; color: #1d4ed8; }
        .pill-resolved  { background: #f0fdf4; color: #166534; }
        .pill-review    { background: #fffbeb; color: #b45309; }

        .empty-state { text-align: center; padding: 3rem; color: var(--muted); }

        /* ── Result Meta ── */
        .result-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; font-size: 0.85rem; color: var(--muted); }
        .result-meta strong { color: var(--foreground); }

        /* ── Sidebar: Top Branches ── */
        .leaderboard-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 0; border-bottom: 1px solid var(--card-border); }
        .leaderboard-item:last-child { border-bottom: none; }
        .rank-badge { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 800; flex-shrink: 0; }
        .rank-1 { background: #fef9c3; color: #854d0e; }
        .rank-2 { background: #f1f5f9; color: #475569; }
        .rank-3 { background: #fff7ed; color: #9a3412; }
        .rank-n { background: var(--background); color: var(--muted); }
        .lb-info { flex: 1; min-width: 0; }
        .lb-name { font-weight: 600; font-size: 0.85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lb-sub  { font-size: 0.75rem; color: var(--muted); }
        .lb-rev  { font-weight: 700; font-size: 0.85rem; color: var(--primary); white-space: nowrap; }

        /* ── Category Tabs ── */
        .category-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .cat-tab { display: flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 99px; border: 1px solid var(--card-border); background: white; font-size: 0.85rem; font-weight: 600; color: var(--muted); text-decoration: none; transition: all 0.2s; cursor: pointer; }
        .cat-tab:hover { border-color: var(--primary-light); color: var(--primary); }
        .cat-tab.active { background: var(--primary); color: white; border-color: var(--primary); }
        .cat-tab i { width: 14px; height: 14px; }

        @media (max-width: 1200px) {
            .summary-grid { grid-template-columns: repeat(2,1fr); }
            .report-layout { grid-template-columns: 1fr; }
            .filter-bar { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<aside>
    <div class="logo-container">
        <div class="logo-icon"><i data-lucide="coffee"></i></div>
        <div class="logo-text"><h1>Top Juan</h1><span>Admin Portal</span></div>
    </div>
    <div class="menu-label">Menu</div>
    <nav>
        <a href="admin-dashboard.php"   class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="admin-orders.php"      class="nav-item"><i data-lucide="clipboard-list"></i> Order Request</a>
        <a href="admin-usage.php"       class="nav-item"><i data-lucide="activity"></i> Item Usage</a>
        <a href="admin-maintenance.php" class="nav-item"><i data-lucide="settings-2"></i> Maintenance</a>
        <a href="admin-inventory.php"   class="nav-item"><i data-lucide="boxes"></i> Inventory</a>
        <a href="admin-returns.php"     class="nav-item"><i data-lucide="rotate-ccw"></i> Return and Refund</a>
        <a href="admin-delivery.php"    class="nav-item"><i data-lucide="truck"></i> Delivery</a>
        <a href="admin-reports.php"     class="nav-item active"><i data-lucide="bar-chart-3"></i> Report</a>
    </nav>
    <div class="user-profile">
        <div class="avatar"><i data-lucide="user-cog"></i></div>
        <div class="user-meta">
            <h4><?php echo htmlspecialchars($adminName); ?></h4>
            <p>System Administrator</p>
        </div>
    </div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
</aside>

<main>
    <div class="header">
        <div>
            <h2>Reports</h2>
            <p>Generate, view, and export reports on transactions, inventory, and deliveries</p>
        </div>
    </div>

    <!-- ── Summary Cards ── -->
    <div class="summary-grid">
        <div class="summary-card">
            <i data-lucide="shopping-bag" class="icon-badge"></i>
            <p class="label">Total Transactions</p>
            <div class="value"><?php echo number_format($totalOrders); ?></div>
            <p class="subtext">All recorded orders</p>
        </div>
        <div class="summary-card">
            <i data-lucide="philippine-peso" class="icon-badge" style="color:#10b981;"></i>
            <p class="label">Total Revenue</p>
            <div class="value" style="color:#166534;">₱<?php echo number_format($totalRevenue, 2); ?></div>
            <p class="subtext">From approved orders</p>
        </div>
        <div class="summary-card">
            <i data-lucide="truck" class="icon-badge" style="color:#3b82f6;"></i>
            <p class="label">Deliveries Completed</p>
            <div class="value" style="color:#1d4ed8;"><?php echo number_format($totalDeliveries); ?></div>
            <p class="subtext">Approved delivery orders</p>
        </div>
        <div class="summary-card">
            <i data-lucide="rotate-ccw" class="icon-badge" style="color:var(--accent);"></i>
            <p class="label">Pending Returns</p>
            <div class="value" style="color:var(--accent);"><?php echo number_format($pendingReturns); ?></div>
            <p class="subtext">Awaiting resolution</p>
        </div>
    </div>

    <!-- ── Main Layout ── -->
    <div class="report-layout">

        <!-- ── Left: Report Generator ── -->
        <div class="card">
            <div class="card-header">
                <h3><?php echo htmlspecialchars($reportTitle); ?></h3>
                <span style="font-size:0.8rem;color:var(--muted);"><?php echo count($reportRows); ?> record<?php echo count($reportRows) != 1 ? 's' : ''; ?></span>
            </div>

            <!-- Category Tabs -->
            <div class="category-tabs">
                <?php
                $tabs = [
                    'transactions' => ['icon' => 'receipt',      'label' => 'Transactions'],
                    'inventory'    => ['icon' => 'boxes',         'label' => 'Inventory'],
                    'item_usage'   => ['icon' => 'activity',      'label' => 'Item Usage'],
                    'deliveries'   => ['icon' => 'truck',         'label' => 'Deliveries'],
                    'returns'      => ['icon' => 'rotate-ccw',    'label' => 'Returns'],
                ];
                foreach ($tabs as $key => $tab):
                    $activeClass = $category === $key ? 'active' : '';
                    $url = 'admin-reports.php?category=' . $key . '&date_range=' . urlencode($dateRange) . '&franchise=' . $franchise;
                ?>
                <a href="<?php echo $url; ?>" class="cat-tab <?php echo $activeClass; ?>">
                    <i data-lucide="<?php echo $tab['icon']; ?>"></i>
                    <?php echo $tab['label']; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <form method="GET" action="admin-reports.php">
                <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
                <div class="filter-bar">
                    <div class="control-group">
                        <label>Date Range</label>
                        <select name="date_range" onchange="this.form.submit()">
                            <option value="7"   <?php echo $dateRange==='7'   ?'selected':''; ?>>Last 7 Days</option>
                            <option value="30"  <?php echo $dateRange==='30'  ?'selected':''; ?>>Last 30 Days</option>
                            <option value="90"  <?php echo $dateRange==='90'  ?'selected':''; ?>>Last 90 Days</option>
                            <option value="180" <?php echo $dateRange==='180' ?'selected':''; ?>>Last 6 Months</option>
                            <option value="all" <?php echo $dateRange==='all' ?'selected':''; ?>>All Time</option>
                        </select>
                    </div>
                    <?php if ($category !== 'inventory'): ?>
                    <div class="control-group">
                        <label>Branch / Franchise</label>
                        <select name="franchise" onchange="this.form.submit()">
                            <option value="0">All Branches</option>
                            <?php foreach ($franchises as $f): ?>
                            <option value="<?php echo $f['id']; ?>" <?php echo $franchise == $f['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($f['branch_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <div class="control-group">
                        <label style="opacity:0;">Spacer</label>
                        <div style="padding: 0.65rem 1rem; font-size:0.85rem; color:var(--muted);">All products shown</div>
                    </div>
                    <?php endif; ?>
                    <div class="control-group" style="display:flex;flex-direction:column;justify-content:flex-end;">
                        <label style="opacity:0;">Export</label>
                        <a href="<?php echo exportUrl($category, $dateRange, $franchise); ?>" class="btn-export">
                            <i data-lucide="download" style="width:16px;height:16px;"></i> Export CSV
                        </a>
                    </div>
                </div>
            </form>

            <!-- Chart -->
            <?php if (!empty($chartLabels)): ?>
            <div class="chart-wrap">
                <div class="chart-title"><?php echo htmlspecialchars($reportTitle); ?> — Visual Overview</div>
                <canvas id="reportChart" height="200"></canvas>
            </div>
            <?php endif; ?>

            <!-- Result meta -->
            <div class="result-meta">
                <span>Showing <strong><?php echo count($reportRows); ?></strong> results</span>
                <span><?php echo date('M d, Y, h:i A'); ?></span>
            </div>

            <!-- Data Table -->
            <?php if (empty($reportRows)): ?>
            <div class="empty-state">
                <i data-lucide="inbox" size="36" style="opacity:.2;display:block;margin:0 auto .75rem;"></i>
                No data found for the selected filters.
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($tableHeaders as $h): ?>
                            <th><?php echo htmlspecialchars($h); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportRows as $row):
                            $values = array_values($row);
                        ?>
                        <tr>
                            <?php foreach ($values as $i => $val):
                                $colHeader = $tableHeaders[$i] ?? '';
                                $isMoney   = in_array($colHeader, ['Total', 'Revenue', 'Price', 'Delivery Fee', 'Subtotal']);
                                $isStatus  = $colHeader === 'Status';
                                $isDate    = in_array($colHeader, ['Date', 'Submitted At', 'Recording Date', 'Est. Pickup']);
                                $statusClass = '';
                                if ($isStatus) {
                                    $statusClass = match(true) {
                                        in_array($val, ['Under Review', 'Pending'])          => 'pill-pending',
                                        in_array($val, ['Approved','Processing','Ready'])     => 'pill-approved',
                                        $val === 'Rejected'                                   => 'pill-rejected',
                                        $val === 'Completed'                                  => 'pill-completed',
                                        $val === 'Resolved'                                   => 'pill-resolved',
                                        default                                               => 'pill-review',
                                    };
                                }
                            ?>
                            <td>
                                <?php if ($isStatus): ?>
                                    <span class="status-pill <?php echo $statusClass; ?>"><?php echo htmlspecialchars($val); ?></span>
                                <?php elseif ($isMoney): ?>
                                    <strong>₱<?php echo number_format((float)$val, 2); ?></strong>
                                <?php elseif ($isDate && $val): ?>
                                    <?php echo date('M d, Y', strtotime($val)); ?>
                                <?php else: ?>
                                    <?php echo htmlspecialchars((string)$val); ?>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Right Sidebar ── -->
        <div>
            <!-- Top Branches -->
            <div class="card">
                <div class="card-header">
                    <h3>Top Branches</h3>
                </div>
                <?php if (empty($topBranches)): ?>
                <div class="empty-state">No data yet.</div>
                <?php else: foreach ($topBranches as $i => $b):
                    $rankClass = match($i) { 0 => 'rank-1', 1 => 'rank-2', 2 => 'rank-3', default => 'rank-n' };
                ?>
                <div class="leaderboard-item">
                    <div class="rank-badge <?php echo $rankClass; ?>"><?php echo $i + 1; ?></div>
                    <div class="lb-info">
                        <div class="lb-name"><?php echo htmlspecialchars($b['branch_name']); ?></div>
                        <div class="lb-sub"><?php echo $b['orders']; ?> order<?php echo $b['orders'] != 1 ? 's' : ''; ?></div>
                    </div>
                    <div class="lb-rev">₱<?php echo number_format($b['revenue'], 0); ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Quick Export -->
            <div class="card" style="margin-top:1.5rem;">
                <div class="card-header" style="margin-bottom:1rem;">
                    <h3>Quick Export</h3>
                </div>
                <div style="display:flex;flex-direction:column;gap:0.6rem;">
                    <a href="<?php echo exportUrl('transactions', 'all', 0); ?>" class="btn-export" style="justify-content:center;">
                        <i data-lucide="receipt" style="width:15px;height:15px;"></i> All Transactions (CSV)
                    </a>
                    <a href="<?php echo exportUrl('inventory', 'all', 0); ?>" class="btn-export" style="justify-content:center;">
                        <i data-lucide="boxes" style="width:15px;height:15px;"></i> Inventory Status (CSV)
                    </a>
                    <a href="<?php echo exportUrl('item_usage', 'all', 0); ?>" class="btn-export" style="justify-content:center;">
                        <i data-lucide="activity" style="width:15px;height:15px;"></i> Item Usage (CSV)
                    </a>
                    <a href="<?php echo exportUrl('deliveries', 'all', 0); ?>" class="btn-export" style="justify-content:center;">
                        <i data-lucide="truck" style="width:15px;height:15px;"></i> Deliveries (CSV)
                    </a>
                    <a href="<?php echo exportUrl('returns', 'all', 0); ?>" class="btn-export" style="justify-content:center;">
                        <i data-lucide="rotate-ccw" style="width:15px;height:15px;"></i> Returns & Refunds (CSV)
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    lucide.createIcons();

    <?php if (!empty($chartLabels) && !empty($chartValues)): ?>
    const labels = <?php echo json_encode($chartLabels); ?>;
    const values = <?php echo json_encode($chartValues); ?>;
    const isMoney = <?php echo $chartIsMoney ? 'true' : 'false'; ?>;

    const palette = [
        '#5c4033','#8b5e3c','#d25424','#b45309','#10b981',
        '#3b82f6','#8b5cf6','#ec4899','#14b8a6','#f59e0b'
    ];
    const colors = labels.map((_,i) => palette[i % palette.length]);

    const ctx = document.getElementById('reportChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: <?php echo json_encode($reportTitle); ?>,
                data: values,
                backgroundColor: colors,
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => isMoney
                            ? '₱' + Number(ctx.parsed.y).toLocaleString('en-PH', {minimumFractionDigits:2})
                            : ctx.parsed.y + ' records'
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                y: {
                    grid: { color: '#eeeae6' },
                    ticks: {
                        font: { size: 11 },
                        callback: v => isMoney ? '₱' + Number(v).toLocaleString('en-PH') : v
                    }
                }
            }
        }
    });
    <?php endif; ?>
</script>
</body>
</html>