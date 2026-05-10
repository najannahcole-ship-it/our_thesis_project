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

// ── DATABASE ─────────────────────────────────────────────────────────────────
require_once 'db.php';

// ── FILTERS ──────────────────────────────────────────────────────────────────
$filter_franchisee = isset($_GET['franchisee']) ? intval($_GET['franchisee']) : 0;
$filter_range      = isset($_GET['range'])      ? $_GET['range']              : 'all';
$filter_search     = isset($_GET['search'])     ? trim($_GET['search'])       : '';

$date_from = '2000-01-01';

// ── FRANCHISEE LIST — only accounts registered in the system ─
$franchisee_res = $conn->query("
    SELECT f.id, f.branch_name,
           COALESCE(f.franchisee_name, u.full_name, 'Unknown') AS franchisee_name
    FROM franchisees f
    INNER JOIN users u ON u.user_id = f.user_id
    WHERE f.user_id IS NOT NULL
    ORDER BY f.branch_name
");
$franchisees = [];
while ($f = $franchisee_res->fetch_assoc()) { $franchisees[] = $f; }

// ── DEFAULTS — respects franchisee filter ────────────────────
// When a franchisee is filtered: show only that branch (even if no defaults → empty state)
// When no filter: show all registered branches that HAVE defaults
$defaults_where = $filter_franchisee > 0
    ? "AND f.id = {$filter_franchisee}"
    : "";

$defaults_res = $conn->query("
    SELECT d.franchisee_id, d.quantity, d.unit,
           p.name AS product_name, p.category,
           f.branch_name,
           COALESCE(f.franchisee_name, u.full_name, 'Unknown') AS franchisee_name
    FROM item_usage_defaults d
    JOIN products    p ON p.id      = d.product_id
    JOIN franchisees f ON f.id      = d.franchisee_id
    JOIN users       u ON u.user_id = f.user_id
    WHERE f.user_id IS NOT NULL {$defaults_where}
    ORDER BY f.branch_name, p.name
");
$defaults_by_branch = [];
while ($dr = $defaults_res->fetch_assoc()) {
    $key = $dr['franchisee_id'];
    if (!isset($defaults_by_branch[$key])) {
        $defaults_by_branch[$key] = [
            'branch_name'     => $dr['branch_name'],
            'franchisee_name' => $dr['franchisee_name'],
            'items'           => [],
        ];
    }
    $defaults_by_branch[$key]['items'][] = $dr;
}

// When filtered to a specific franchisee that has no defaults, still show the empty state
$filtered_branch_info = null;
if ($filter_franchisee > 0 && empty($defaults_by_branch)) {
    $fb = $conn->query("
        SELECT f.branch_name,
               COALESCE(f.franchisee_name, u.full_name, 'Unknown') AS franchisee_name
        FROM franchisees f
        INNER JOIN users u ON u.user_id = f.user_id
        WHERE f.id = {$filter_franchisee} LIMIT 1
    ");
    $filtered_branch_info = $fb ? $fb->fetch_assoc() : null;
}

// ── MAIN USAGE QUERY ─────────────────────────────────────────────────────────
$where_parts = ["iu.recording_date >= '$date_from'"];
$params      = [];
$types       = '';

if ($filter_franchisee > 0) {
    $where_parts[] = "iu.franchisee_id = ?";
    $params[]      = $filter_franchisee;
    $types        .= 'i';
}
if ($filter_search !== '') {
    $like           = '%' . $conn->real_escape_string($filter_search) . '%';
    $where_parts[]  = "(p.name LIKE '$like' OR p.category LIKE '$like' OR f.branch_name LIKE '$like' OR f.franchisee_name LIKE '$like')";
}

$where_sql = implode(' AND ', $where_parts);

$sql = "
    SELECT iu.id, iu.usage_ref, iu.quantity_used, iu.unit, iu.recording_date, iu.submitted_at,
           iu.is_default,
           p.name AS product_name, p.category, p.product_code,
           COALESCE(f.franchisee_name, u.full_name, f.branch_name, 'Unknown') AS franchisee_name,
           f.branch_name
    FROM item_usage iu
    LEFT JOIN products    p ON iu.product_id    = p.id
    LEFT JOIN franchisees f ON iu.franchisee_id = f.id
    LEFT JOIN users       u ON u.user_id        = f.user_id
    WHERE $where_sql
" . ($filter_franchisee === 0 ? " AND f.user_id IS NOT NULL" : "") . "
    ORDER BY iu.recording_date DESC, iu.submitted_at DESC, iu.id DESC
";

if ($types) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $usage_res = $stmt->get_result();
} else {
    $usage_res = $conn->query($sql);
}

?>
<?php if (isset($_GET['ajax'])): ?>
<?php
// ── AJAX: output only the two swappable panels as JSON ───────
ob_start();
?>
<!-- panel-defaults -->
<div class="card">
    <div class="card-header">
        <h3><i data-lucide="bookmark" size="18" style="vertical-align:middle;margin-right:.4rem;color:var(--primary);"></i>
            <?php echo $filter_franchisee > 0 ? 'Default Items' : 'Franchisee Default Items'; ?>
        </h3>
        <span><?php echo $filter_franchisee > 0 ? 'Locked defaults' : 'Select a branch'; ?></span>
    </div>
    <?php if ($filter_franchisee === 0): ?>
        <p class="empty-state">
            <i data-lucide="git-branch" size="32" style="display:block;margin:0 auto .75rem;opacity:.3;"></i>
            Select a Franchisee<br>
            <span style="font-size:.82rem;font-weight:400;">Choose a specific branch above to view its default items.</span>
        </p>
    <?php elseif (empty($defaults_by_branch)): ?>
        <p class="empty-state">
            <i data-lucide="inbox" size="32" style="display:block;margin:0 auto .75rem;opacity:.3;"></i>
            No Default Items Yet<br>
            <span style="font-size:.82rem;font-weight:400;">
            <?php echo $filtered_branch_info
                ? htmlspecialchars($filtered_branch_info['branch_name']) . " hasn't locked any default items yet."
                : "This branch hasn't set any default items yet."; ?>
            </span>
        </p>
    <?php else: ?>
        <?php foreach ($defaults_by_branch as $fid => $ddata):
            if (count($defaults_by_branch) > 1): ?>
            <div style="padding:.5rem 1.5rem;background:var(--background);border-bottom:1px solid var(--card-border);font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">
                <?php echo htmlspecialchars($ddata['branch_name']); ?>
                <span style="font-weight:400;text-transform:none;"> — <?php echo htmlspecialchars($ddata['franchisee_name']); ?></span>
            </div>
            <?php endif; ?>
            <div class="rank-list">
            <?php $rank = 1; foreach ($ddata['items'] as $di): ?>
            <div class="rank-item">
                <div class="rank-num <?php echo $rank === 1 ? 'top' : ''; ?>"><?php echo $rank; ?></div>
                <div class="rank-info">
                    <h4><?php echo htmlspecialchars($di['product_name']); ?></h4>
                    <p><?php echo htmlspecialchars($di['category']); ?></p>
                </div>
                <div class="rank-qty">
                    <?php echo $di['quantity']; ?>
                    <span><?php echo htmlspecialchars($di['unit']); ?></span>
                </div>
            </div>
            <?php $rank++; endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php
$defaults_html = ob_get_clean();

ob_start();
?>
<!-- panel-usage -->
<div class="card">
    <div class="card-header">
        <h3>
            <?php if ($filter_franchisee > 0):
                $fn_res = $conn->query("SELECT COALESCE(f.franchisee_name, u.full_name, 'Unknown') AS name FROM franchisees f LEFT JOIN users u ON u.user_id = f.user_id WHERE f.id = $filter_franchisee LIMIT 1");
                $fn = $fn_res ? $fn_res->fetch_assoc() : null;
            ?>
                Usage Records — <span style="color:var(--primary);"><?= htmlspecialchars($fn['name'] ?? 'Franchisee') ?></span>
            <?php else: ?>
                All Usage Records
            <?php endif; ?>
        </h3>
        <span style="display:flex;align-items:center;gap:.75rem;">
            <?php if ($filter_franchisee > 0): ?>
            <a href="#" onclick="clearFilter(event)" style="font-size:.8rem;color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:.3rem;border:1px solid var(--card-border);padding:.25rem .75rem;border-radius:8px;">
                <i data-lucide="x" size="13"></i> Clear filter
            </a>
            <?php endif; ?>
            <?php
            $row_count = $usage_res ? $usage_res->num_rows : 0;
            echo $row_count . ' ' . ($row_count === 1 ? 'record' : 'records');
            ?>
        </span>
    </div>
    <?php if ($row_count > 0):
        $grouped = [];
        while ($row = $usage_res->fetch_assoc()) {
            $d   = $row['recording_date'];
            $ref = $row['usage_ref'] ?? '—';
            if (!isset($grouped[$d])) $grouped[$d] = [];
            if (!isset($grouped[$d][$ref])) $grouped[$d][$ref] = [];
            $grouped[$d][$ref][] = $row;
        }
    ?>
    <?php foreach ($grouped as $date => $refs): ?>
    <div style="background:var(--background);padding:.6rem 1.25rem;font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--card-border);display:flex;align-items:center;gap:.5rem;">
        <i data-lucide="calendar" size="13"></i>
        <?php echo date('l, F j, Y', strtotime($date)); ?>
        <span style="font-weight:500;color:var(--muted);opacity:.7;">(<?php echo array_sum(array_map('count', $refs)); ?> entries)</span>
    </div>
    <?php foreach ($refs as $ref => $rows):
        $toggleId = 'ref-' . md5($date . $ref);
        $grouped_items = [];
        foreach ($rows as $row) {
            $key = ($row['product_name'] ?? 'Unknown') . '||' . ($row['unit'] ?? '');
            if (!isset($grouped_items[$key])) {
                $grouped_items[$key] = $row;
                $grouped_items[$key]['quantity_used'] = 0;
            }
            $grouped_items[$key]['quantity_used'] += $row['quantity_used'];
        }
    ?>
    <div onclick="toggleRef('<?= $toggleId ?>')" style="padding:.5rem 1.25rem;background:#fdfaf7;border-bottom:1px solid var(--card-border);display:flex;align-items:center;gap:.5rem;cursor:pointer;user-select:none;">
        <i data-lucide="chevron-down" size="14" id="ico-<?= $toggleId ?>" style="color:var(--muted);transition:transform .2s;"></i>
        <span style="font-size:.72rem;font-weight:700;color:var(--primary);background:#f5ede6;padding:.15rem .55rem;border-radius:6px;"><?php echo htmlspecialchars($ref); ?></span>
        <span style="font-size:.72rem;color:var(--muted);">submitted <?php echo $rows[0]['submitted_at'] ? date('g:i A', strtotime($rows[0]['submitted_at'])) : '—'; ?></span>
        <span style="font-size:.72rem;color:var(--muted);margin-left:auto;"><?= count($grouped_items) ?> item<?= count($grouped_items) !== 1 ? 's' : '' ?></span>
    </div>
    <div id="<?= $toggleId ?>">
    <table>
        <thead><tr><th>Branch</th><th>Item</th><th>Category</th><th>Qty Used</th><th>Unit</th></tr></thead>
        <tbody>
        <?php foreach ($grouped_items as $item): ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?= htmlspecialchars($item['branch_name'] ?? 'Unknown') ?></div>
                    <div style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($item['franchisee_name'] ?? '') ?></div>
                </td>
                <td>
                    <div style="font-weight:500;"><?= htmlspecialchars($item['product_name'] ?? 'Unknown') ?></div>
                    <?php if (!empty($item['product_code'])): ?>
                    <div style="font-size:0.72rem;color:var(--muted);font-family:monospace;"><?= htmlspecialchars($item['product_code']) ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="category-tag"><?= htmlspecialchars($item['category'] ?? '—') ?></span></td>
                <td style="font-weight:700;"><?= number_format($item['quantity_used']) ?></td>
                <td style="color:var(--muted);"><?= htmlspecialchars($item['unit']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endforeach; endforeach; ?>
    <?php else: ?>
        <p class="empty-state">No usage records found for the selected filters.</p>
    <?php endif; ?>
</div>
<?php
$usage_html = ob_get_clean();
header('Content-Type: application/json');
echo json_encode(['defaults' => $defaults_html, 'usage' => $usage_html]);
exit;
endif; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Usage Monitoring - Top Juan Inc.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --background: #f7f3f0; --foreground: #2d241e; --sidebar-bg: #fdfaf7;
            --card: #ffffff; --card-border: #eeeae6; --primary: #5c4033;
            --primary-light: #8b5e3c; --accent: #d25424; --muted: #8c837d;
            --success: #059669; --warning: #d97706; --error: #dc2626;
            --sidebar-width: 280px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background-color: var(--background); color: var(--foreground); display: flex; min-height: 100vh; }

        /* Sidebar */
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

        /* Main */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 2.5rem 3rem; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2.5rem; }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p { color: var(--muted); font-size: 1rem; }
        .view-only-badge { background: #f3f4f6; color: #6b7280; padding: 0.35rem 0.75rem; border-radius: 8px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; }

        /* Filter bar */
        .filter-bar { display: flex; gap: 1rem; margin-bottom: 1.5rem; align-items: center; flex-wrap: wrap; }
        .search-box { flex: 1; min-width: 220px; position: relative; }
        .search-box i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--muted); width: 18px; }
        .search-box input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.75rem; border-radius: 12px; border: 1px solid var(--card-border); font-family: inherit; font-size: 0.9rem; background: white; }
        .filter-select { padding: 0.75rem 1rem; border-radius: 12px; border: 1px solid var(--card-border); background: white; font-family: inherit; font-size: 0.9rem; color: var(--foreground); cursor: pointer; }
        .btn-filter { padding: 0.75rem 1.5rem; border-radius: 12px; border: none; background: var(--primary); color: white; font-family: inherit; font-weight: 600; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: opacity 0.2s; }
        .btn-filter:hover { opacity: 0.9; }

        /* Two-column layout */
        .content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }

        /* Card */
        .card { background: white; border: 1px solid var(--card-border); border-radius: 20px; overflow: hidden; margin-bottom: 2rem; }
        .card-inner { padding: 2rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 2rem; border-bottom: 1px solid var(--card-border); }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }
        .card-header span { font-size: 0.85rem; color: var(--muted); }

        /* Table */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1rem 1.5rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); background: #faf8f6; white-space: nowrap; }
        td { padding: 1.1rem 1.5rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fdfaf7; }

        .category-tag { font-size: 0.72rem; color: var(--muted); background: var(--background); border: 1px solid var(--card-border); padding: 2px 8px; border-radius: 6px; margin-left: 6px; }

        /* Top items/branches list */
        .rank-list { display: flex; flex-direction: column; }
        .rank-item { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; border-bottom: 1px solid var(--card-border); }
        .rank-item:last-child { border-bottom: none; }
        .rank-num { width: 28px; height: 28px; border-radius: 8px; background: var(--background); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; color: var(--muted); flex-shrink: 0; }
        .rank-num.top { background: var(--primary); color: white; }
        .rank-info { flex: 1; }
        .rank-info h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 0.1rem; }
        .rank-info p { font-size: 0.78rem; color: var(--muted); }
        .rank-qty { font-weight: 700; font-size: 0.9rem; color: var(--primary); text-align: right; }
        .rank-qty span { display: block; font-size: 0.75rem; color: var(--muted); font-weight: 400; }

        .empty-state { text-align: center; color: var(--muted); padding: 3rem 1rem; }

        @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2,1fr); } .content-grid { grid-template-columns: 1fr; } }
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
            <a href="admin-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
            <a href="admin-orders.php" class="nav-item"><i data-lucide="clipboard-list"></i> Order Request</a>
            <a href="admin-usage.php" class="nav-item active"><i data-lucide="activity"></i> Item Usage</a>
            <a href="admin-maintenance.php" class="nav-item"><i data-lucide="settings-2"></i> Maintenance</a>
            <a href="admin-inventory.php" class="nav-item"><i data-lucide="boxes"></i> Inventory</a>
            <a href="admin-returns.php" class="nav-item"><i data-lucide="rotate-ccw"></i> Return and Refund</a>
            <a href="admin-delivery.php" class="nav-item"><i data-lucide="truck"></i> Delivery</a>
            <a href="admin-reports.php" class="nav-item"><i data-lucide="bar-chart-3"></i> Report</a>
        </nav>
        <div class="user-profile">
            <div class="avatar"><i data-lucide="user-cog"></i></div>
            <div class="user-meta">
                <h4><?= htmlspecialchars($adminName) ?></h4>
                <p>System Administrator</p>
            </div>
        </div>
        <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
    </aside>

    <main>
        <div class="header">
            <div>
                <h2>Item Usage Monitoring</h2>
                <p>Monitor and review franchisee pre-recorded item usage across all branches</p>
            </div>
            <div class="view-only-badge">Read Only Access</div>
        </div>

        <!-- FILTERS -->
        <div class="filter-bar">
            <div class="search-box">
                <i data-lucide="search"></i>
                <input type="text" id="filter-search" placeholder="Search by item, category, or branch..."
                    value="<?= htmlspecialchars($filter_search) ?>">
            </div>
            <select class="filter-select" id="filter-franchisee">
                <option value="0">All Branches</option>
                <?php foreach ($franchisees as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $filter_franchisee == $f['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($f['branch_name']) ?><?= $f['franchisee_name'] ? ' — ' . htmlspecialchars($f['franchisee_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn-filter" onclick="applyFilters()"><i data-lucide="filter"></i> Apply</button>
        </div>

        <!-- DEFAULT ITEMS — swappable -->
        <div id="panel-defaults" style="transition:opacity .2s;margin-bottom:1.5rem;">
        <div class="card">
            <div class="card-header">
                <h3><i data-lucide="bookmark" size="18" style="vertical-align:middle;margin-right:.4rem;color:var(--primary);"></i>
                    <?php echo $filter_franchisee > 0 ? 'Default Items' : 'Franchisee Default Items'; ?>
                </h3>
                <span><?php echo $filter_franchisee > 0 ? 'Locked defaults' : 'Select a branch'; ?></span>
            </div>

            <?php if ($filter_franchisee === 0): ?>
                <p class="empty-state">
                    <i data-lucide="git-branch" size="32" style="display:block;margin:0 auto .75rem;opacity:.3;"></i>
                    Select a Franchisee<br>
                    <span style="font-size:.82rem;font-weight:400;">Choose a specific branch above to view its default items.</span>
                </p>
            <?php elseif (empty($defaults_by_branch)): ?>
                <p class="empty-state">
                    <i data-lucide="inbox" size="32" style="display:block;margin:0 auto .75rem;opacity:.3;"></i>
                    No Default Items Yet<br>
                    <span style="font-size:.82rem;font-weight:400;">
                    <?php echo $filtered_branch_info
                        ? htmlspecialchars($filtered_branch_info['branch_name']) . " hasn't locked any default items yet."
                        : "This branch hasn't set any default items yet."; ?>
                    </span>
                </p>
            <?php else: ?>
                <?php foreach ($defaults_by_branch as $fid => $ddata):
                    if (count($defaults_by_branch) > 1): ?>
                    <div style="padding:.5rem 1.5rem;background:var(--background);border-bottom:1px solid var(--card-border);font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">
                        <?php echo htmlspecialchars($ddata['branch_name']); ?>
                        <span style="font-weight:400;text-transform:none;"> — <?php echo htmlspecialchars($ddata['franchisee_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="rank-list">
                    <?php $rank = 1; foreach ($ddata['items'] as $di): ?>
                    <div class="rank-item">
                        <div class="rank-num <?php echo $rank === 1 ? 'top' : ''; ?>"><?php echo $rank; ?></div>
                        <div class="rank-info">
                            <h4><?php echo htmlspecialchars($di['product_name']); ?></h4>
                            <p><?php echo htmlspecialchars($di['category']); ?></p>
                        </div>
                        <div class="rank-qty">
                            <?php echo $di['quantity']; ?>
                            <span><?php echo htmlspecialchars($di['unit']); ?></span>
                        </div>
                    </div>
                    <?php $rank++; endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        </div><!-- end panel-defaults -->

        <!-- USAGE HISTORY (full width, swappable) -->
        <div id="panel-usage" style="transition:opacity .2s;">
        <div class="card">
            <div class="card-header">
                <h3>
                    <?php if ($filter_franchisee > 0):
                        $fn_res = $conn->query("SELECT COALESCE(f.franchisee_name, u.full_name, 'Unknown') AS name, f.branch_name FROM franchisees f LEFT JOIN users u ON u.user_id = f.user_id WHERE f.id = $filter_franchisee LIMIT 1");
                        $fn = $fn_res ? $fn_res->fetch_assoc() : null;
                    ?>
                        Usage Records — <span style="color:var(--primary);"><?= htmlspecialchars($fn['name'] ?? 'Franchisee') ?></span>
                    <?php else: ?>
                        All Usage Records
                    <?php endif; ?>
                </h3>
                <span style="display:flex;align-items:center;gap:.75rem;">
                    <?php if ($filter_franchisee > 0): ?>
                    <a href="#" onclick="clearFilter(event)" style="font-size:.8rem;color:var(--muted);text-decoration:none;display:flex;align-items:center;gap:.3rem;border:1px solid var(--card-border);padding:.25rem .75rem;border-radius:8px;">
                        <i data-lucide="x" size="13"></i> Clear filter
                    </a>
                    <?php endif; ?>
                    <?php
                    $row_count = $usage_res ? $usage_res->num_rows : 0;
                    echo $row_count . ' ' . ($row_count === 1 ? 'record' : 'records');
                    ?>
                </span>
            </div>

            <?php if ($row_count > 0):
                // Group rows by date → by usage_ref
                $grouped = [];
                while ($row = $usage_res->fetch_assoc()) {
                    $d   = $row['recording_date'];
                    $ref = $row['usage_ref'] ?? '—';
                    if (!isset($grouped[$d])) $grouped[$d] = [];
                    if (!isset($grouped[$d][$ref])) $grouped[$d][$ref] = [];
                    $grouped[$d][$ref][] = $row;
                }
            ?>
            <?php foreach ($grouped as $date => $refs): ?>
            <!-- Date header -->
            <div style="background:var(--background);padding:.6rem 1.25rem;font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--card-border);display:flex;align-items:center;gap:.5rem;">
                <i data-lucide="calendar" size="13"></i>
                <?php echo date('l, F j, Y', strtotime($date)); ?>
                <span style="font-weight:500;color:var(--muted);opacity:.7;">(<?php echo array_sum(array_map('count', $refs)); ?> entries)</span>
            </div>

            <?php foreach ($refs as $ref => $rows):
                $toggleId = 'ref-' . md5($date . $ref);

                // Group same product+unit combos together
                $grouped_items = [];
                foreach ($rows as $row) {
                    $key = ($row['product_name'] ?? 'Unknown') . '||' . ($row['unit'] ?? '');
                    if (!isset($grouped_items[$key])) {
                        $grouped_items[$key] = $row;
                        $grouped_items[$key]['quantity_used'] = 0;
                    }
                    $grouped_items[$key]['quantity_used'] += $row['quantity_used'];
                }
            ?>
            <!-- Usage ref sub-header — clickable toggle -->
            <div onclick="toggleRef('<?= $toggleId ?>')"
                 style="padding:.5rem 1.25rem;background:#fdfaf7;border-bottom:1px solid var(--card-border);display:flex;align-items:center;gap:.5rem;cursor:pointer;user-select:none;">
                <i data-lucide="chevron-down" size="14" id="ico-<?= $toggleId ?>" style="color:var(--muted);transition:transform .2s;"></i>
                <span style="font-size:.72rem;font-weight:700;color:var(--primary);background:#f5ede6;padding:.15rem .55rem;border-radius:6px;"><?php echo htmlspecialchars($ref); ?></span>
                <span style="font-size:.72rem;color:var(--muted);">submitted <?php echo $rows[0]['submitted_at'] ? date('g:i A', strtotime($rows[0]['submitted_at'])) : '—'; ?></span>
                <span style="font-size:.72rem;color:var(--muted);margin-left:auto;"><?= count($grouped_items) ?> item<?= count($grouped_items) !== 1 ? 's' : '' ?></span>
            </div>
            <div id="<?= $toggleId ?>">
            <table>
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Qty Used</th>
                        <th>Unit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($grouped_items as $item): ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($item['branch_name'] ?? 'Unknown') ?></div>
                            <div style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($item['franchisee_name'] ?? '') ?></div>
                        </td>
                        <td>
                            <div style="font-weight:500;"><?= htmlspecialchars($item['product_name'] ?? 'Unknown') ?></div>
                            <?php if (!empty($item['product_code'])): ?>
                            <div style="font-size:0.72rem;color:var(--muted);font-family:monospace;"><?= htmlspecialchars($item['product_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="category-tag"><?= htmlspecialchars($item['category'] ?? '—') ?></span></td>
                        <td style="font-weight:700;"><?= number_format($item['quantity_used']) ?></td>
                        <td style="color:var(--muted);"><?= htmlspecialchars($item['unit']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>

            <?php else: ?>
                <p class="empty-state">No usage records found for the selected filters.</p>
            <?php endif; ?>
        </div>

        </div><!-- end panel-usage -->
    </main>

    <script>
        lucide.createIcons();

        function toggleRef(id) {
            const el = document.getElementById(id);
            const ico = document.getElementById('ico-' + id);
            const hidden = el.style.display === 'none';
            el.style.display = hidden ? '' : 'none';
            ico.style.transform = hidden ? '' : 'rotate(-90deg)';
        }

        function applyFilters() {
            const franchisee = document.getElementById('filter-franchisee').value;
            const search = document.getElementById('filter-search').value;
            loadPanels(franchisee, search);
        }

        function clearFilter(e) {
            e.preventDefault();
            document.getElementById('filter-franchisee').value = '0';
            document.getElementById('filter-search').value = '';
            loadPanels('0', '');
        }

        function loadPanels(franchisee, search) {
            const params = new URLSearchParams({ franchisee, search, ajax: '1' });
            document.getElementById('panel-defaults').style.opacity = '0.4';
            document.getElementById('panel-defaults').style.pointerEvents = 'none';
            document.getElementById('panel-usage').style.opacity = '0.4';
            document.getElementById('panel-usage').style.pointerEvents = 'none';

            fetch('admin-usage.php?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    document.getElementById('panel-defaults').innerHTML = data.defaults;
                    document.getElementById('panel-usage').innerHTML    = data.usage;
                    document.getElementById('panel-defaults').style.opacity = '';
                    document.getElementById('panel-defaults').style.pointerEvents = '';
                    document.getElementById('panel-usage').style.opacity = '';
                    document.getElementById('panel-usage').style.pointerEvents = '';
                    lucide.createIcons();

                    const url = new URL(window.location);
                    url.searchParams.set('franchisee', franchisee);
                    url.searchParams.set('search', search);
                    window.history.replaceState({}, '', url);
                })
                .catch(() => {
                    document.getElementById('panel-defaults').style.opacity = '';
                    document.getElementById('panel-defaults').style.pointerEvents = '';
                    document.getElementById('panel-usage').style.opacity = '';
                    document.getElementById('panel-usage').style.pointerEvents = '';
                });
        }


    </script>
</body>
</html>
<?php $conn->close(); ?>