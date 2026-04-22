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

// ── DATABASE ─────────────────────────────────────────────
$conn = new mysqli("localhost", "root", "", "juancafe");
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// ── AJAX: Usage history for a single product ─────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
    $product_id = intval($_GET['product_id']);
    $stmt = $conn->prepare("
        SELECT iu.quantity_used, iu.unit, iu.recording_date,
               f.franchisee_name, f.branch_name
        FROM item_usage iu
        LEFT JOIN franchisees f ON iu.franchisee_id = f.id
        WHERE iu.product_id = ?
        ORDER BY iu.recording_date DESC
        LIMIT 30
    ");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    $conn->close();
    exit();
}

// ── STATS ────────────────────────────────────────────────
define('LOW_THRESHOLD', 20);

$total_skus   = $conn->query("SELECT COUNT(*) c FROM products")->fetch_assoc()['c'];
$low_stock    = $conn->query("SELECT COUNT(*) c FROM products WHERE stock_qty > 0 AND stock_qty <= " . LOW_THRESHOLD)->fetch_assoc()['c'];
$out_of_stock = $conn->query("SELECT COUNT(*) c FROM products WHERE stock_qty = 0")->fetch_assoc()['c'];
$total_value  = $conn->query("SELECT SUM(price * stock_qty) v FROM products")->fetch_assoc()['v'] ?? 0;

// ── PRODUCTS ─────────────────────────────────────────────
$products_res = $conn->query("SELECT * FROM products ORDER BY category, name");

// ── RECENT USAGE HISTORY ─────────────────────────────────
$history_res = $conn->query("
    SELECT iu.quantity_used, iu.unit, iu.recording_date,
           p.name AS product_name, p.category,
           f.franchisee_name, f.branch_name
    FROM item_usage iu
    LEFT JOIN products p    ON iu.product_id    = p.id
    LEFT JOIN franchisees f ON iu.franchisee_id = f.id
    ORDER BY iu.recording_date DESC
    LIMIT 15
");

// ── DISTINCT CATEGORIES for filter ───────────────────────
$cat_res    = $conn->query("SELECT DISTINCT category FROM products ORDER BY category");
$categories = [];
while ($r = $cat_res->fetch_assoc()) { $categories[] = $r['category']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Top Juan Inc.</title>
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
            --radius: 16px; --sidebar-width: 280px;
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
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb; overflow: hidden; display: flex; align-items: center; justify-content: center; }
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

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 20px; border: 1px solid var(--card-border); }
        .stat-card .label { font-size: 0.85rem; color: var(--muted); margin-bottom: 0.5rem; }
        .stat-card .value { font-family: 'Fraunces', serif; font-size: 1.75rem; font-weight: 700; }
        .stat-card .trend { font-size: 0.75rem; margin-top: 0.5rem; display: flex; align-items: center; gap: 0.25rem; color: var(--muted); }
        .trend.up { color: var(--success); } .trend.warn { color: var(--warning); } .trend.down { color: var(--error); }

        /* Controls */
        .controls { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
        .search-box { position: relative; flex: 1; }
        .search-box i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--muted); width: 18px; }
        .search-box input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.75rem; border-radius: 12px; border: 1px solid var(--card-border); background: white; font-family: inherit; font-size: 0.9rem; }
        .filter-select { padding: 0.75rem 1rem; border-radius: 12px; border: 1px solid var(--card-border); background: white; font-family: inherit; font-size: 0.9rem; min-width: 180px; cursor: pointer; color: var(--foreground); }

        /* Card */
        .card { background: white; border: 1px solid var(--card-border); border-radius: 20px; padding: 2rem; margin-bottom: 2rem; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }

        /* Table */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); }
        td { padding: 1.25rem 1rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }

        .stock-indicator { display: flex; align-items: center; gap: 0.75rem; }
        .stock-bar-bg { flex: 1; height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden; max-width: 100px; }
        .stock-bar-fill { height: 100%; border-radius: 3px; }

        .status-pill { padding: 0.25rem 0.75rem; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .status-good     { background: #ecfdf5; color: #059669; }
        .status-low      { background: #fffbeb; color: #d97706; }
        .status-critical { background: #fef2f2; color: #dc2626; }

        .btn-action { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--card-border); background: white; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--muted); transition: all 0.2s; font-family: inherit; }
        .btn-action:hover { border-color: var(--primary); color: var(--primary); }

        /* History list */
        .history-list { display: flex; flex-direction: column; gap: 1rem; }
        .history-item { display: flex; align-items: center; gap: 1rem; padding: 1rem; background: var(--background); border-radius: 12px; }
        .history-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .history-info { flex: 1; }
        .history-info h4 { font-size: 0.9rem; margin-bottom: 0.15rem; }
        .history-info p { font-size: 0.8rem; color: var(--muted); }
        .history-meta { text-align: right; }
        .history-change { font-weight: 700; font-size: 0.9rem; color: var(--warning); }

        /* Modal */
        .modal-backdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(45,36,30,0.6); backdrop-filter: blur(4px); display: none; justify-content: center; align-items: center; z-index: 100; }
        .modal-backdrop.active { display: flex; }
        .modal { background: white; border-radius: 24px; width: 100%; max-width: 580px; padding: 2.5rem; box-shadow: 0 20px 40px rgba(0,0,0,0.1); max-height: 80vh; display: flex; flex-direction: column; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-shrink: 0; }
        .modal-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }
        .close-modal { background: none; border: none; cursor: pointer; color: var(--muted); }
        .modal-body { overflow-y: auto; flex: 1; }
        .modal-row { display: flex; justify-content: space-between; align-items: center; padding: 0.875rem 0; border-bottom: 1px solid var(--card-border); }
        .modal-row:last-child { border-bottom: none; }

        .empty-state { text-align: center; color: var(--muted); padding: 2rem; }

        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }
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
            <a href="admin-usage.php" class="nav-item"><i data-lucide="activity"></i> Item Usage</a>
            <a href="admin-maintenance.php" class="nav-item"><i data-lucide="settings-2"></i> Maintenance</a>
            <a href="admin-inventory.php" class="nav-item active"><i data-lucide="boxes"></i> Inventory</a>
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
                <h2>Inventory Management</h2>
                <p>Real-time monitoring and stock level analysis</p>
            </div>
        </div>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <p class="label">Total SKUs</p>
                <div class="value"><?= number_format($total_skus) ?></div>
                <p class="trend up"><i data-lucide="package" size="14"></i> Active products</p>
            </div>
            <div class="stat-card">
                <p class="label">Low Stock Alerts</p>
                <div class="value" style="color:var(--warning);"><?= number_format($low_stock) ?></div>
                <p class="trend warn"><i data-lucide="alert-triangle" size="14"></i> Needs attention</p>
            </div>
            <div class="stat-card">
                <p class="label">Out of Stock</p>
                <div class="value" style="color:var(--error);"><?= number_format($out_of_stock) ?></div>
                <p class="trend down"><i data-lucide="x-circle" size="14"></i> Critical priority</p>
            </div>
            <div class="stat-card">
                <p class="label">Total Asset Value</p>
                <div class="value">₱<?= number_format($total_value, 0) ?></div>
                <p class="trend"><i data-lucide="trending-up" size="14"></i> Stock × price</p>
            </div>
        </div>

        <!-- CONTROLS -->
        <div class="controls">
            <div class="search-box">
                <i data-lucide="search"></i>
                <input type="text" id="search-input" placeholder="Search by product name or category..." oninput="filterTable()">
            </div>
            <select class="filter-select" id="category-filter" onchange="filterTable()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="filter-select" id="status-filter" onchange="filterTable()">
                <option value="">All Statuses</option>
                <option value="good">Good Stock</option>
                <option value="low">Low Stock</option>
                <option value="critical">Out of Stock</option>
            </select>
        </div>

        <!-- STOCK TABLE -->
        <div class="card">
            <div class="card-header">
                <h3>Stock Overview</h3>
                <span id="row-count" style="font-size:0.85rem;color:var(--muted);"></span>
            </div>
            <table id="inventory-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Unit</th>
                        <th>Price</th>
                        <th>Stock Qty</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if ($products_res && $products_res->num_rows > 0):
                    while ($p = $products_res->fetch_assoc()):
                        $qty = intval($p['stock_qty']);
                        if ($qty === 0) {
                            $sc = 'status-critical'; $sl = 'Out of Stock'; $ss = 'critical';
                            $bc = 'var(--error)'; $bp = 2;
                        } elseif ($qty <= LOW_THRESHOLD) {
                            $sc = 'status-low'; $sl = 'Low Stock'; $ss = 'low';
                            $bc = 'var(--warning)'; $bp = max(5, min(40, ($qty / LOW_THRESHOLD) * 40));
                        } else {
                            $sc = 'status-good'; $sl = 'Good'; $ss = 'good';
                            $bc = 'var(--success)'; $bp = min(100, ($qty / 200) * 100);
                        }
                ?>
                    <tr data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                        data-category="<?= htmlspecialchars($p['category']) ?>"
                        data-status="<?= $ss ?>">
                        <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                        <td><?= htmlspecialchars($p['category']) ?></td>
                        <td><?= htmlspecialchars($p['unit']) ?></td>
                        <td>₱<?= number_format($p['price'], 2) ?></td>
                        <td>
                            <div class="stock-indicator">
                                <div class="stock-bar-bg">
                                    <div class="stock-bar-fill" style="width:<?= $bp ?>%;background:<?= $bc ?>;"></div>
                                </div>
                                <span><?= $qty ?></span>
                            </div>
                        </td>
                        <td><span class="status-pill <?= $sc ?>"><?= $sl ?></span></td>
                        <td>
                            <button class="btn-action" title="View Usage History"
                                onclick="viewHistory(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">
                                <i data-lucide="history" size="16"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile;
                else: ?>
                    <tr><td colspan="7" class="empty-state">No products found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- RECENT USAGE HISTORY -->
        <div class="card">
            <div class="card-header">
                <h3>Recent Item Usage</h3>
                <span style="font-size:0.85rem;color:var(--muted);">Latest 15 entries</span>
            </div>
            <div class="history-list">
            <?php if ($history_res && $history_res->num_rows > 0):
                while ($h = $history_res->fetch_assoc()): ?>
                <div class="history-item">
                    <div class="history-icon" style="background:rgba(217,119,6,0.1);color:var(--warning);">
                        <i data-lucide="activity" size="20"></i>
                    </div>
                    <div class="history-info">
                        <h4><?= htmlspecialchars($h['product_name'] ?? 'Unknown Product') ?></h4>
                        <p>
                            <?= htmlspecialchars($h['franchisee_name'] ?? 'Unknown franchisee') ?>
                            <?php if (!empty($h['branch_name'])): ?> — <?= htmlspecialchars($h['branch_name']) ?><?php endif; ?>
                            &nbsp;<span style="color:var(--muted);font-size:0.75rem;"><?= htmlspecialchars($h['category'] ?? '') ?></span>
                        </p>
                    </div>
                    <div class="history-meta">
                        <div class="history-change">−<?= $h['quantity_used'] ?> <?= htmlspecialchars($h['unit']) ?></div>
                        <p style="font-size:0.75rem;color:var(--muted);"><?= date('M j, Y', strtotime($h['recording_date'])) ?></p>
                    </div>
                </div>
            <?php endwhile;
            else: ?>
                <p class="empty-state">No usage records found.</p>
            <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- HISTORY MODAL -->
    <div id="history-modal" class="modal-backdrop">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modal-title">Usage History</h3>
                <button class="close-modal" onclick="closeModal()"><i data-lucide="x"></i></button>
            </div>
            <div class="modal-body" id="modal-body">
                <p class="empty-state">Loading...</p>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function updateRowCount() {
            const total   = document.querySelectorAll('#inventory-table tbody tr[data-name]').length;
            const visible = document.querySelectorAll('#inventory-table tbody tr[data-name]:not([style*="none"])').length;
            document.getElementById('row-count').textContent = visible === total ? `${total} products` : `${visible} of ${total} products`;
        }
        updateRowCount();

        function filterTable() {
            const search   = document.getElementById('search-input').value.toLowerCase();
            const category = document.getElementById('category-filter').value;
            const status   = document.getElementById('status-filter').value;
            document.querySelectorAll('#inventory-table tbody tr[data-name]').forEach(row => {
                const matchName     = row.dataset.name.includes(search) || row.dataset.category.toLowerCase().includes(search);
                const matchCategory = !category || row.dataset.category === category;
                const matchStatus   = !status   || row.dataset.status   === status;
                row.style.display   = (matchName && matchCategory && matchStatus) ? '' : 'none';
            });
            updateRowCount();
        }

        function viewHistory(id, name) {
            document.getElementById('modal-title').textContent = name + ' — Usage History';
            document.getElementById('modal-body').innerHTML    = '<p class="empty-state">Loading...</p>';
            document.getElementById('history-modal').classList.add('active');

            fetch(`admin-inventory.php?ajax=history&product_id=${id}`)
                .then(r => r.json())
                .then(rows => {
                    if (!rows.length) {
                        document.getElementById('modal-body').innerHTML = '<p class="empty-state">No usage records for this product.</p>';
                        return;
                    }
                    let html = rows.map(r => `
                        <div class="modal-row">
                            <div>
                                <div style="font-weight:600;font-size:0.9rem;">${r.franchisee_name ?? 'Unknown'}</div>
                                <div style="font-size:0.8rem;color:var(--muted);">${r.branch_name ?? ''}</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:700;color:var(--warning);">−${r.quantity_used} ${r.unit}</div>
                                <div style="font-size:0.75rem;color:var(--muted);">${new Date(r.recording_date).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})}</div>
                            </div>
                        </div>`).join('');
                    document.getElementById('modal-body').innerHTML = html;
                });
        }

        function closeModal() { document.getElementById('history-modal').classList.remove('active'); }
        document.getElementById('history-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
    </script>
</body>
</html>
<?php $conn->close(); ?>