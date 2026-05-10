<?php
// ================================================================
// clerk-adjustment.php  —  Stock Adjustment Module
// Requires: db.php in the same directory
// ================================================================

session_start();

// 1. Auth guard — Inventory Clerk only (role_id = 3)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: index.php');
    exit();
}

// 2. No-cache headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once __DIR__ . '/db.php';  // $conn available after this

$clerkName   = $_SESSION['full_name'] ?? 'Inventory Clerk';
$clerkUserId = $_SESSION['user_id'];

// ----------------------------------------------------------------
// AJAX handler — POST requests return JSON
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    header('Content-Type: application/json');

    $action = $_POST['action'];

    // ---- Log a new adjustment ----
    if ($action === 'log_adjustment') {

        $productId   = (int)   ($_POST['product_id']   ?? 0);
        $adjType     = trim($_POST['adj_type']          ?? '');  // 'add' or 'subtract'
        $quantity    = (int)   ($_POST['quantity']      ?? 0);
        $reasonCode  = trim($_POST['reason_code']       ?? '');
        $notes       = trim($_POST['notes']             ?? '') ?: null;

        // Validation
        $errors = [];
        if (!$productId)                      $errors[] = 'Please select an item.';
        if ($adjType !== 'subtract') $errors[] = 'Invalid adjustment type.';
        if ($quantity <= 0)                   $errors[] = 'Quantity must be greater than 0.';
        if (!$reasonCode)                     $errors[] = 'Please select a reason code.';
        if (!$notes)                          $errors[] = 'Detailed explanation is required.';

        if ($errors) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit();
        }

        // Check we won't go below zero on a subtract
        if ($adjType === 'subtract') {
            $chk = $conn->prepare("SELECT stock_qty FROM products WHERE id = ?");
            $chk->bind_param("i", $productId);
            $chk->execute();
            $chk->bind_result($currentQty);
            $chk->fetch();
            $chk->close();
            if ($quantity > $currentQty) {
                echo json_encode(['success' => false, 'errors' => ["Cannot deduct $quantity — current stock is only $currentQty."]]);
                exit();
            }
        }

        $conn->begin_transaction();
        try {
            // Insert into stock_adjustments
            $adjType = 'subtract'; // always subtract
            $ins = $conn->prepare("
                INSERT INTO stock_adjustments
                    (product_id, adj_type, quantity, reason_code, notes, adjusted_by)
                VALUES (?, 'subtract', ?, ?, ?, ?)
            ");
            $ins->bind_param("idssi", $productId, $quantity, $reasonCode, $notes, $clerkUserId);
            $ins->execute();
            $adjId = $conn->insert_id;
            $ins->close();

            // Get current stock before update
            $sq = $conn->prepare("SELECT stock_qty FROM products WHERE id = ? LIMIT 1");
            $sq->bind_param("i", $productId); $sq->execute();
            $prevStock = (int)$sq->get_result()->fetch_assoc()['stock_qty']; $sq->close();
            $newStock  = $prevStock - (int)$quantity;

            // Update products.stock_qty
            $upd = $conn->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");
            $upd->bind_param("di", $quantity, $productId);
            $upd->execute(); $upd->close();

            // Write to inventory_logs
            $qtyInt  = (int)$quantity;
            $remarks = "Stock adjustment — " . $reasonCode;
            $log = $conn->prepare("
                INSERT INTO inventory_logs
                    (product_id, action, quantity, unit, previous_stock, new_stock, remarks, created_by)
                VALUES (?, 'deduct', ?, ?, ?, ?, ?, ?)
            ");
            // fetch unit first
            $us = $conn->prepare("SELECT unit FROM products WHERE id = ? LIMIT 1");
            $us->bind_param("i", $productId); $us->execute();
            $punit = $us->get_result()->fetch_assoc()['unit'] ?? ''; $us->close();
            $log->bind_param("iisiisi", $productId, $qtyInt, $punit, $prevStock, $newStock, $remarks, $clerkUserId);
            $log->execute(); $log->close();

            $conn->commit();

            echo json_encode([
                'success' => true,
                'adj_id'  => $adjId,
                'message' => 'Adjustment logged and inventory updated.'
            ]);
        } catch (\Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'errors' => ['Database error: ' . $e->getMessage()]]);
        }
        exit();
    }

    // ---- Fetch single adjustment detail ----
    if ($action === 'get_adjustment') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT a.*, p.name AS product_name, u.full_name AS adjusted_by_name
            FROM stock_adjustments a
            LEFT JOIN products p ON p.id = a.product_id
            LEFT JOIN users    u ON u.user_id = a.adjusted_by
            WHERE a.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode($row ?: ['error' => 'Not found']);
        exit();
    }

    echo json_encode(['error' => 'Unknown action']);
    exit();
}

// ----------------------------------------------------------------
// Load data for the page
// ----------------------------------------------------------------

// Products dropdown
$products = [];
$res = $conn->query("SELECT id, name, unit, category, stock_qty FROM products WHERE status = 'available' ORDER BY category, name");
while ($row = $res->fetch_assoc()) $products[] = $row;

// Recent adjustments (latest 20)
$recentAdj = [];
$res = $conn->query("
    SELECT a.id, a.adj_type, a.quantity, a.reason_code, a.notes, a.adjusted_at,
           p.name AS product_name, p.unit,
           u.full_name AS adjusted_by_name
    FROM stock_adjustments a
    LEFT JOIN products p ON p.id = a.product_id
    LEFT JOIN users    u ON u.user_id = a.adjusted_by
    ORDER BY a.adjusted_at DESC
    LIMIT 20
");
if ($res) while ($row = $res->fetch_assoc()) $recentAdj[] = $row;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Adjustment - Top Juan Inc.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --background:         #f7f3f0;
            --foreground:         #2d241e;
            --sidebar-bg:         #fdfaf7;
            --card:               #ffffff;
            --card-border:        #eeeae6;
            --primary:            #5c4033;
            --primary-light:      #8b5e3c;
            --accent:             #d25424;
            --muted:              #8c837d;
            --error:              #dc2626;
            --success:            #059669;
            --warning:            #d97706;
            --radius:             16px;
            --sidebar-width:      280px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--background);
            color: var(--foreground);
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ──────────────────────────────────────────── */
        aside {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            border-right: 1px solid var(--card-border);
            padding: 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 10;
        }
        .logo-container { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2.5rem; }
        .logo-icon {
            width: 40px; height: 40px; background: var(--primary); border-radius: 10px;
            display: flex; align-items: center; justify-content: center; color: white;
        }
        .logo-text h1 { font-family: 'Fraunces', serif; font-size: 1.25rem; line-height: 1; }
        .logo-text span { font-size: 0.75rem; color: var(--muted); font-weight: 500; }
        .menu-label {
            font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em;
            color: var(--muted); margin-bottom: 1rem; font-weight: 700;
        }
        nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
        .nav-item {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.875rem 1rem; border-radius: 12px;
            text-decoration: none; color: var(--muted); font-weight: 500; font-size: 0.95rem;
            transition: all 0.2s;
        }
        .nav-item i { width: 20px; height: 20px; stroke-width: 2px; }
        .nav-item:hover { color: var(--primary); background: rgba(92,64,51,0.05); }
        .nav-item.active { background: var(--primary); color: white; }
        .user-profile {
            margin-top: auto; background: white; border: 1px solid var(--card-border);
            padding: 1rem; border-radius: 16px; display: flex; align-items: center;
            gap: 0.75rem; margin-bottom: 1rem;
        }
        .avatar {
            width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb;
            overflow: hidden; display: flex; align-items: center; justify-content: center;
        }
        .avatar i { color: var(--muted); }
        .user-meta h4 { font-size: 0.85rem; font-weight: 700; }
        .user-meta p  { font-size: 0.75rem; color: var(--muted); }
        .sign-out {
            display: flex; align-items: center; gap: 0.5rem;
            text-decoration: none; color: var(--muted); font-size: 0.9rem;
            padding: 0.5rem; transition: color 0.2s;
        }
        .sign-out:hover { color: var(--accent); }

        /* ── Main ─────────────────────────────────────────────── */
        main { margin-left: var(--sidebar-width); flex: 1; padding: 2.5rem 3rem; }
        .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2.5rem; }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p  { color: var(--muted); font-size: 1rem; }

        /* ── Card / Form ──────────────────────────────────────── */
        .card {
            background: white; border: 1px solid var(--card-border);
            border-radius: 24px; padding: 2rem; margin-bottom: 2rem;
        }
        .card-title {
            font-family: 'Fraunces', serif; font-size: 1.5rem;
            margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;
        }
        .form-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 1.5rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .form-group.full-width { grid-column: span 2; }
        .form-group label {
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--muted);
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.875rem 1rem; border-radius: 12px;
            border: 1px solid var(--card-border);
            font-family: inherit; font-size: 0.95rem; background: #faf9f8;
            transition: all 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none; border-color: var(--accent); background: white;
            box-shadow: 0 0 0 4px rgba(210,84,36,0.05);
        }

        /* Stock preview badge */
        #stockPreview {
            font-size: 0.8rem; padding: 0.4rem 0.75rem; border-radius: 8px;
            background: #f0fdf4; color: #059669; border: 1px solid #a7f3d0;
            display: none; align-items: center; gap: 0.4rem; margin-top: 0.4rem;
        }

        .btn-group { display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem; }
        .btn {
            padding: 0.875rem 2rem; border-radius: 12px; font-weight: 600;
            cursor: pointer; transition: all 0.2s;
            display: flex; align-items: center; gap: 0.5rem; font-family: inherit;
        }
        .btn-outline { background: white; border: 1px solid var(--card-border); color: var(--foreground); }
        .btn-outline:hover { background: var(--background); }
        .btn-primary { background: var(--primary); color: white; border: none; }
        .btn-primary:hover { background: var(--primary-light); transform: translateY(-1px); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── Alerts ───────────────────────────────────────────── */
        .alert-warning {
            background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
            padding: 1rem; border-radius: 12px;
            display: flex; gap: 0.75rem; margin-bottom: 1.5rem; font-size: 0.9rem;
        }
        #errorList {
            display: none; background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626;
            padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; font-size: 0.9rem;
        }
        #errorList ul { padding-left: 1.25rem; }

        /* ── Toast ────────────────────────────────────────────── */
        #toast {
            position: fixed; bottom: 2rem; right: 2rem; z-index: 999;
            padding: 1rem 1.5rem; border-radius: 14px;
            font-weight: 600; font-size: 0.9rem;
            display: flex; align-items: center; gap: 0.75rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            transform: translateY(100px); opacity: 0;
            transition: all 0.35s cubic-bezier(0.34,1.56,0.64,1);
            pointer-events: none;
        }
        #toast.show { transform: translateY(0); opacity: 1; }
        #toast.success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        #toast.error   { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }

        /* ── Table ────────────────────────────────────────────── */
        .history-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .history-table th {
            text-align: left; padding: 1rem;
            font-size: 0.75rem; text-transform: uppercase; color: var(--muted);
            border-bottom: 1px solid var(--card-border);
        }
        .history-table td {
            padding: 1rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
        }
        .history-table tr:last-child td { border-bottom: none; }
        .history-table tr:hover td { background: var(--background); }

        .reason-badge {
            padding: 0.25rem 0.6rem; border-radius: 6px;
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
        }
        .reason-Spoilage        { background: #fee2e2; color: #991b1b; }
        .reason-Damage          { background: #ffedd5; color: #9a3412; }
        .reason-Discrepancy     { background: #f3f4f6; color: #374151; }
        .reason-QC              { background: #e0f2fe; color: #075985; }
        .reason-Waste           { background: #fef9c3; color: #854d0e; }
        .reason-Correction      { background: #ede9fe; color: #5b21b6; }

        .qty-badge { font-weight: 700; }
        .qty-negative { color: var(--error); }
        .qty-positive { color: var(--success); }

        .empty-state {
            text-align: center; padding: 3rem;
            color: var(--muted); font-size: 0.95rem;
        }
        .action-btn {
            background: none; border: none; color: var(--muted); cursor: pointer;
            padding: 0.35rem; border-radius: 8px; transition: all 0.15s;
        }
        .action-btn:hover { color: var(--primary); background: rgba(92,64,51,0.07); }

        /* ── Modal ────────────────────────────────────────────── */
        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            z-index: 100; align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: white; border-radius: 24px; padding: 2.5rem;
            width: 100%; max-width: 520px; position: relative;
            box-shadow: 0 24px 60px rgba(0,0,0,0.15);
        }
        .modal-close {
            position: absolute; top: 1.25rem; right: 1.25rem;
            background: none; border: none; cursor: pointer; color: var(--muted);
        }
        .modal h3 { font-family: 'Fraunces', serif; font-size: 1.5rem; margin-bottom: 1.5rem; }
        .detail-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 1rem; }
        .detail-item .label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); margin-bottom: 0.25rem; }
        .detail-item .value { font-size: 0.95rem; }
        .detail-item.full { grid-column: span 2; }
    </style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────────────────────── -->
<aside>
    <div class="logo-container">
        <div class="logo-icon"><i data-lucide="coffee"></i></div>
        <div class="logo-text">
            <h1>Top Juan</h1>
            <span>Clerk Portal</span>
        </div>
    </div>

    <div class="menu-label">Menu</div>
    <nav>
        <a href="clerk-dashboard.php"  class="nav-item" data-testid="link-clerk-dashboard">
            <i data-lucide="layout-dashboard"></i> Dashboard
        </a>
        <a href="clerk-orders.php"     class="nav-item" data-testid="link-order-monitoring">
            <i data-lucide="clipboard-list"></i> Order Monitoring
        </a>
        <a href="clerk-inventory.php"  class="nav-item" data-testid="link-clerk-inventory">
            <i data-lucide="boxes"></i> Inventory
        </a>
        <a href="clerk-receiving.php"  class="nav-item" data-testid="link-stock-receiving">
            <i data-lucide="download"></i> Stock Receiving
        </a>
        <a href="clerk-adjustment.php" class="nav-item active" data-testid="link-stock-adjustment">
            <i data-lucide="edit-3"></i> Stock Adjustment
        </a>
        <a href="clerk-reports.php"    class="nav-item" data-testid="link-reports">
            <i data-lucide="bar-chart-3"></i> Reports
        </a>
    </nav>

    <div class="user-profile">
        <div class="avatar"><i data-lucide="user"></i></div>
        <div class="user-meta">
            <h4 data-testid="text-username"><?= htmlspecialchars($clerkName) ?></h4>
            <p>Inventory Clerk</p>
        </div>
    </div>
    <a href="logout.php" class="sign-out" data-testid="button-logout">
        <i data-lucide="log-out"></i> Sign Out
    </a>
</aside>

<!-- ── Main ─────────────────────────────────────────────────── -->
<main>
    <div class="header">
        <div>
            <h2 data-testid="text-page-title">Stock Adjustment</h2>
            <p>Correct inventory levels due to spoilage, damage, or discrepancies</p>
        </div>
    </div>

    <!-- Adjustment Form -->
    <div class="card" data-testid="card-adjustment-form">
        <div class="card-title">
            <i data-lucide="clipboard-edit"></i>
            New Inventory Adjustment
        </div>

        <div class="alert-warning">
            <i data-lucide="alert-triangle" size="20"></i>
            <p>Adjusting stock levels will affect inventory records. Please select the correct reason code and provide supporting notes.</p>
        </div>

        <div id="errorList" role="alert">
            <strong>Please fix the following errors:</strong>
            <ul id="errorItems"></ul>
        </div>

        <form id="adjustmentForm" novalidate>
            <div class="form-grid">

                <div class="form-group">
                    <label for="product_id">Select Item to Adjust *</label>
                    <select id="product_id" name="product_id" data-testid="select-item" required>
                        <option value="">Search for an item…</option>
                        <?php
                        $lastCat = '';
                        foreach ($products as $p):
                            if ($p['category'] !== $lastCat):
                                if ($lastCat !== '') echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($p['category']) . '">';
                                $lastCat = $p['category'];
                            endif;
                        ?>
                        <option value="<?= $p['id'] ?>"
                                data-unit="<?= htmlspecialchars($p['unit']) ?>"
                                data-qty="<?= $p['stock_qty'] ?>">
                            <?= htmlspecialchars($p['name']) ?> (<?= $p['stock_qty'] ?> <?= htmlspecialchars($p['unit']) ?> in stock)
                        </option>
                        <?php endforeach; if ($lastCat) echo '</optgroup>'; ?>
                    </select>
                    <div id="stockPreview">
                        <i data-lucide="package" size="14"></i>
                        <span id="stockPreviewText"></span>
                    </div>
                </div>

                <div class="form-group">
                    <!-- Deduct only — type is fixed -->
                    <input type="hidden" id="adj_type" name="adj_type" value="subtract">
                </div>

                <div class="form-group">
                    <label for="quantity">Quantity *</label>
                    <input id="quantity" name="quantity" type="number"
                           placeholder="0" min="1" step="1"
                           data-testid="input-adj-qty" required>
                </div>

                <div class="form-group">
                    <label for="reason_code">Reason Code *</label>
                    <select id="reason_code" name="reason_code" data-testid="select-reason-code" required>
                        <option value="">Select a reason…</option>
                        <option value="Spoilage">Spoilage / Expired</option>
                        <option value="Damage">Physical Damage / Spills</option>
                        <option value="Discrepancy">Physical Count Discrepancy</option>
                    </select>
                </div>

                <div class="form-group">
                    <!-- spacer -->
                </div>

                <div class="form-group full-width">
                    <label for="notes">Detailed Explanation / Observations *</label>
                    <textarea id="notes" name="notes" rows="3"
                              placeholder="Describe the reason in detail (e.g., 'Broken seal found during morning audit')"
                              data-testid="textarea-adj-notes" required></textarea>
                </div>

            </div><!-- /form-grid -->

            <div class="btn-group">
                <button type="reset" class="btn btn-outline"
                        data-testid="button-reset-form"
                        onclick="clearErrors(); resetUnitDisplay();">
                    Reset Form
                </button>
                <button type="submit" class="btn btn-primary" id="submitBtn"
                        data-testid="button-submit-adjustment">
                    <i data-lucide="save"></i> Log Adjustment
                </button>
            </div>
        </form>
    </div>

    <!-- Recent Adjustments Table -->
    <div class="card" data-testid="card-adjustment-history">
        <div class="card-title" style="font-size:1.25rem;">
            Recent Adjustment Logs
        </div>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Item Name</th>
                    <th>Change</th>
                    <th>Reason</th>
                    <th>Adjusted By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="adjBody">
            <?php if (empty($recentAdj)): ?>
                <tr id="emptyRow">
                    <td colspan="6">
                        <div class="empty-state">
                            <i data-lucide="inbox" size="48" style="display:block;margin:0 auto 0.75rem;"></i>
                            No adjustments logged yet.
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($recentAdj as $a): ?>
                <tr data-id="<?= $a['id'] ?>">
                    <td><?= date('M d, Y g:i A', strtotime($a['adjusted_at'])) ?></td>
                    <td><strong><?= htmlspecialchars($a['product_name']) ?></strong></td>
                    <td>
                        <span class="qty-badge <?= $a['adj_type'] === 'subtract' ? 'qty-negative' : 'qty-positive' ?>">
                            <?= $a['adj_type'] === 'subtract' ? '−' : '+' ?><?= (int)$a['quantity'] ?> <?= htmlspecialchars($a['unit']) ?>
                        </span>
                    </td>
                    <td><span class="reason-badge reason-<?= htmlspecialchars($a['reason_code']) ?>"><?= htmlspecialchars($a['reason_code']) ?></span></td>
                    <td><?= htmlspecialchars($a['adjusted_by_name'] ?? '—') ?></td>
                    <td>
                        <button class="action-btn" title="View Notes" onclick="viewAdjustment(<?= $a['id'] ?>)">
                            <i data-lucide="info" size="18"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- ── Detail Modal ──────────────────────────────────────────── -->
<div class="modal-overlay" id="detailModal" onclick="closeModal(event)">
    <div class="modal" role="dialog" aria-modal="true">
        <button class="modal-close" onclick="document.getElementById('detailModal').classList.remove('open')">
            <i data-lucide="x" size="22"></i>
        </button>
        <h3>Adjustment Details</h3>
        <div class="detail-grid" id="modalBody">
            <p style="color:var(--muted)">Loading…</p>
        </div>
    </div>
</div>

<!-- ── Toast ─────────────────────────────────────────────────── -->
<div id="toast" role="status" aria-live="polite"></div>

<script>
lucide.createIcons();

// ── Auto-fill unit display when item selected ─────────────────
document.getElementById('product_id').addEventListener('change', function () {
    const opt  = this.options[this.selectedIndex];
    const unit = opt.dataset.unit;
    const qty  = opt.dataset.qty;
    const preview = document.getElementById('stockPreview');
    const previewText = document.getElementById('stockPreviewText');

    if (unit) {
        preview.style.display = 'flex';
        previewText.textContent = `Current stock: ${qty} ${unit}`;
        lucide.createIcons();
    } else {
        unitOpt.textContent = '—';
        preview.style.display = 'none';
    }
});

function resetUnitDisplay() {
    document.getElementById('stockPreview').style.display = 'none';
}

// ── Toast ─────────────────────────────────────────────────────
let toastTimer;
function showToast(msg, type = 'success') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className   = 'show ' + type;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = ''; }, 4000);
}

// ── Error display ─────────────────────────────────────────────
function showErrors(errors) {
    const box   = document.getElementById('errorList');
    const items = document.getElementById('errorItems');
    items.innerHTML = errors.map(e => `<li>${e}</li>`).join('');
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function clearErrors() {
    document.getElementById('errorList').style.display = 'none';
    document.getElementById('errorItems').innerHTML = '';
}

// ── Form submit ───────────────────────────────────────────────
document.getElementById('adjustmentForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    clearErrors();

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2"></i> Saving…';
    lucide.createIcons();

    // Capture display values before reset
    const selEl    = document.getElementById('product_id');
    const prodName = selEl.options[selEl.selectedIndex]?.text?.split(' (')[0] ?? '—';
    const selOpt   = selEl.options[selEl.selectedIndex];
    const unit     = selOpt?.dataset.unit ?? '';

    const fd = new FormData(this);
    fd.append('action', 'log_adjustment');

    try {
        const res  = await fetch('clerk-adjustment.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            showToast('✓ Adjustment logged and inventory updated!', 'success');
            this.reset();
            resetUnitDisplay();
            prependAdjRow(data.adj_id, fd, prodName, unit);
        } else {
            showErrors(data.errors || ['An unknown error occurred.']);
            showToast('Please fix the errors above.', 'error');
        }
    } catch (err) {
        showErrors(['Network error: ' + err.message]);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="save"></i> Log Adjustment';
        lucide.createIcons();
    }
});

// ── Prepend new row ───────────────────────────────────────────
function prependAdjRow(id, fd, prodName, unit) {
    const tbody    = document.getElementById('adjBody');
    const emptyRow = document.getElementById('emptyRow');
    if (emptyRow) emptyRow.remove();

    const adjType    = fd.get('adj_type');
    const qty        = fd.get('quantity');
    const reasonCode = fd.get('reason_code');
    const sign       = adjType === 'subtract' ? '−' : '+';
    const cls        = adjType === 'subtract' ? 'qty-negative' : 'qty-positive';
    const now        = new Date().toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric', hour:'numeric', minute:'2-digit' });

    const tr = document.createElement('tr');
    tr.dataset.id = id;
    tr.innerHTML = `
        <td>${now}</td>
        <td><strong>${prodName}</strong></td>
        <td><span class="qty-badge ${cls}">${sign}${qty} ${unit}</span></td>
        <td><span class="reason-badge reason-${reasonCode}">${reasonCode}</span></td>
        <td><?= htmlspecialchars($clerkName) ?></td>
        <td>
            <button class="action-btn" title="View Notes" onclick="viewAdjustment(${id})">
                <i data-lucide="info" size="18"></i>
            </button>
        </td>`;

    tbody.insertBefore(tr, tbody.firstChild);
    lucide.createIcons();
}

// ── Modal: view adjustment detail ────────────────────────────
async function viewAdjustment(id) {
    const modal = document.getElementById('detailModal');
    const body  = document.getElementById('modalBody');
    body.innerHTML = '<p style="color:var(--muted)">Loading…</p>';
    modal.classList.add('open');

    const fd = new FormData();
    fd.append('action', 'get_adjustment');
    fd.append('id', id);

    try {
        const res = await fetch('clerk-adjustment.php', { method: 'POST', body: fd });
        const d   = await res.json();

        if (d.error) { body.innerHTML = `<p style="color:#dc2626">${d.error}</p>`; return; }

        const fmt   = (v) => v ?? '—';
        const fmtDT = (v) => v ? new Date(v).toLocaleString('en-US', { month:'long', day:'2-digit', year:'numeric', hour:'numeric', minute:'2-digit' }) : '—';
        const sign  = d.adj_type === 'subtract' ? '−' : '+';
        const cls   = d.adj_type === 'subtract' ? 'qty-negative' : 'qty-positive';

        body.innerHTML = `
            <div class="detail-item"><div class="label">Item</div><div class="value">${fmt(d.product_name)}</div></div>
            <div class="detail-item"><div class="label">Adjustment</div><div class="value"><span class="qty-badge ${cls}">${sign}${d.quantity} ${fmt(d.unit)}</span></div></div>
            <div class="detail-item"><div class="label">Reason</div><div class="value"><span class="reason-badge reason-${d.reason_code}">${fmt(d.reason_code)}</span></div></div>
            <div class="detail-item"><div class="label">Adjusted By</div><div class="value">${fmt(d.adjusted_by_name)}</div></div>
            <div class="detail-item"><div class="label">Date &amp; Time</div><div class="value">${fmtDT(d.adjusted_at)}</div></div>
            <div class="detail-item full"><div class="label">Notes</div><div class="value">${fmt(d.notes)}</div></div>
        `;
    } catch (err) {
        body.innerHTML = `<p style="color:#dc2626">Failed to load: ${err.message}</p>`;
    }
}

function closeModal(e) {
    if (e.target === document.getElementById('detailModal')) {
        document.getElementById('detailModal').classList.remove('open');
    }
}
</script>
</body>
</html>