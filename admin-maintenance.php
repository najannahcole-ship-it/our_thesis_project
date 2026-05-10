<?php
// 1. DATABASE & AJAX PROCESSING
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $conn = new mysqli(
    getenv("DB_HOST"),
    getenv("DB_USER"),
    getenv("DB_PASSWORD"),
    getenv("DB_NAME"),
    (int)getenv("DB_PORT")
);
    if ($conn->connect_error) { die("Connection failed"); }

    $action = $_POST['ajax_action'];
    $userId = $_POST['user_id'] ?? null;

    // FETCH DATA FOR EDITING
    if ($action === 'get_user_data') {
        $stmt = $conn->prepare("SELECT u.*, f.id as franchisee_id, f.franchisee_name, f.branch_name FROM users u LEFT JOIN franchisees f ON f.user_id = u.user_id WHERE u.user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_assoc());
        $stmt->close();
    } 
    // SAVE DATA (Insert or Update)
    else if ($action === 'save_user') {
        $full_name = $_POST['full_name'];
        $contact   = $_POST['contact_number'];
        $email     = $_POST['email'];
        $role_id   = $_POST['role_id'];

        if (!empty($userId)) {
            // UPDATE EXISTING
            $stmt = $conn->prepare("UPDATE users SET full_name=?, contact_number=?, email=?, role_id=? WHERE user_id=?");
            $stmt->bind_param("sssii", $full_name, $contact, $email, $role_id, $userId);
            if ($stmt->execute()) {
                // If franchisee, sync the franchisees table
                if ($role_id == 2) {
                    $franchisee_name = $_POST['franchisee_name'] ?? $full_name;
                    $branch_name     = $_POST['branch_name'] ?? '';
                    $stmt2 = $conn->prepare("UPDATE franchisees SET franchisee_name=?, branch_name=? WHERE user_id=?");
                    $stmt2->bind_param("ssi", $franchisee_name, $branch_name, $userId);
                    $stmt2->execute();
                    $stmt2->close();
                }
                echo "success";
            } else {
                echo "Error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            // INSERT NEW
            $password = $_POST['password'];
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $username = strtolower(str_replace(' ', '', $full_name));
            $status = 'Active';
            $stmt = $conn->prepare("INSERT INTO users (role_id, username, email, password, full_name, contact_number, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssss", $role_id, $username, $email, $hashed_password, $full_name, $contact, $status);
            if ($stmt->execute()) {
                $new_user_id = $conn->insert_id;
                // If registering a franchisee, create the franchisees record and link it
                if ($role_id == 2) {
                    $franchisee_name = $_POST['franchisee_name'] ?? $full_name;
                    $branch_name     = $_POST['branch_name'] ?? '';
                    $stmt2 = $conn->prepare("INSERT INTO franchisees (franchisee_name, branch_name, user_id) VALUES (?, ?, ?)");
                    $stmt2->bind_param("ssi", $franchisee_name, $branch_name, $new_user_id);
                    if (!$stmt2->execute()) {
                        echo "Error creating franchisee record: " . $stmt2->error;
                        $stmt2->close(); $stmt->close();
                        exit();
                    }
                    $stmt2->close();
                }
                echo "success";
            } else {
                echo "Error: " . $stmt->error;
            }
            $stmt->close();
        }
    }

    // FETCH SINGLE PRODUCT FOR EDITING
    else if ($action === 'get_product') {
        $productId = $_POST['product_id'];
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_assoc());
        $stmt->close();
    }

    // SAVE PRODUCT (Insert or Update)
    else if ($action === 'save_product') {
        $name      = $_POST['product_name'];
        $category  = $_POST['category'];
        $unit      = $_POST['unit'];
        $price     = $_POST['price'];
        $status    = $_POST['status'];
        $productId = $_POST['product_id'] ?? null;

        if (!empty($productId)) {
            $stmt = $conn->prepare("UPDATE products SET name=?, category=?, unit=?, price=?, status=? WHERE id=?");
            $stmt->bind_param("sssssi", $name, $category, $unit, $price, $status, $productId);
        } else {
            $stmt = $conn->prepare("INSERT INTO products (name, category, unit, price, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssds", $name, $category, $unit, $price, $status);
        }

        if ($stmt->execute()) { echo "success"; }
        else { echo "Error: " . $stmt->error; }
        $stmt->close();
    }

    // TOGGLE PRODUCT STATUS
    else if ($action === 'toggle_product_status') {
        $productId = $_POST['product_id'];
        $newStatus = $_POST['new_status'];
        $stmt = $conn->prepare("UPDATE products SET status=? WHERE id=?");
        $stmt->bind_param("si", $newStatus, $productId);
        if ($stmt->execute()) { echo "success"; }
        else { echo "Error: " . $stmt->error; }
        $stmt->close();
    }

    // CHANGE PASSWORD
    else if ($action === 'change_password') {
        $newPassword = $_POST['new_password'];
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE user_id=?");
        $stmt->bind_param("si", $hashed, $userId);
        if ($stmt->execute()) { echo "success"; }
        else { echo "Error: " . $stmt->error; }
        $stmt->close();
    }

    $conn->close();
    exit(); 
}

// 2. SESSION CONTROL
session_start();
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit(); }
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) { header('Location: index.php'); exit(); }
$adminName = $_SESSION['full_name'] ?? 'System Admin';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - Top Juan Inc.</title>
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
            --status-review-bg: #fffbeb;
            --status-review-text: #b45309;
            --status-pickup-bg: #f0fdf4;
            --status-pickup-text: #166534;
            --radius: 16px;
            --sidebar-width: 280px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--background);
            color: var(--foreground);
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
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

        .logo-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 2.5rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .logo-text h1 {
            font-family: 'Fraunces', serif;
            font-size: 1.25rem;
            line-height: 1;
        }
        .logo-text span {
            font-size: 0.75rem;
            color: var(--muted);
            font-weight: 500;
        }

        .menu-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            margin-bottom: 1rem;
            font-weight: 700;
        }

        nav { display: flex; flex-direction: column; gap: 0.25rem; flex: 1; }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            border-radius: 12px;
            text-decoration: none;
            color: var(--muted);
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        .nav-item i { width: 20px; height: 20px; stroke-width: 2px; }
        .nav-item:hover { color: var(--primary); background: rgba(92, 64, 51, 0.05); }
        .nav-item.active {
            background: var(--primary);
            color: white;
        }

        .user-profile {
            margin-top: auto;
            background: white;
            border: 1px solid var(--card-border);
            padding: 1rem;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e5e7eb;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .avatar i { color: var(--muted); }
        
        .user-meta h4 { font-size: 0.85rem; font-weight: 700; }
        .user-meta p { font-size: 0.75rem; color: var(--muted); }

        .sign-out {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--muted);
            font-size: 0.9rem;
            padding: 0.5rem;
            transition: color 0.2s;
        }
        .sign-out:hover { color: var(--accent); }

        /* Main Content */
        main {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 2.5rem 3rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2.5rem;
        }
        .header h2 { font-family: 'Fraunces', serif; font-size: 2rem; margin-bottom: 0.25rem; }
        .header p { color: var(--muted); font-size: 1rem; }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 0.5rem;
        }
        .tab-btn {
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            border: none;
            background: none;
            color: var(--muted);
            font-family: inherit;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .tab-btn i { width: 18px; height: 18px; }
        .tab-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(56, 44, 36, 0.2);
        }

        /* Modal Styles */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(45, 36, 30, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 100;
        }
        .modal-backdrop.active {
            display: flex;
        }
        .modal {
            background: white;
            border-radius: 24px;
            width: 100%;
            max-width: 600px;
            padding: 2.5rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .modal-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }
        .close-modal { background: none; border: none; cursor: pointer; color: var(--muted); }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .form-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .form-group.full { grid-column: span 2; }
        .form-group label { font-size: 0.85rem; font-weight: 600; color: var(--primary); }
        .form-control {
            padding: 0.75rem 1rem;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            font-family: inherit;
            background: var(--background);
            font-size: 0.95rem;
        }
        .form-actions { display: flex; justify-content: flex-end; gap: 1rem; }
        .btn-secondary {
            background: none;
            border: 1px solid var(--card-border);
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-family: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-secondary:hover { background: var(--background); }


        /* Content Card */
        .card {
            background: white;
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }

        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            gap: 1rem;
        }
        .search-container {
            position: relative;
            flex: 1;
        }
        .search-container i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            width: 18px;
            height: 18px;
        }
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: var(--background);
            font-family: inherit;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-family: inherit;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: opacity 0.2s;
        }
        .btn-primary:hover { opacity: 0.9; }

        /* Table */
        .table-container {
            overflow-x: auto;
        }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); }
        td { padding: 1.25rem 1rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border); vertical-align: middle; }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-active { background: #ecfdf5; color: #059669; }
        .status-inactive { background: #fef2f2; color: #dc2626; }

        .actions-cell {
            display: flex;
            gap: 0.5rem;
        }
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--muted);
            transition: all 0.2s;
        }
        .action-btn:hover { border-color: var(--primary); color: var(--primary); }

        /* History Modal Styles */
        .history-item {
            padding: 1rem;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .history-item:last-child { border-bottom: none; }
        .history-info h4 { font-size: 0.9rem; margin-bottom: 0.25rem; }
        .history-info p { font-size: 0.8rem; color: var(--muted); }
        .history-amount { font-weight: 700; font-size: 0.9rem; }

        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .role-badge { font-size: 0.7rem; color: var(--muted); border: 1px solid var(--card-border); padding: 2px 8px; border-radius: 4px; margin-left: 8px; }

        /* Product Management Styles */
        .category-tag {
            font-size: 0.72rem;
            color: var(--muted);
            background: var(--background);
            border: 1px solid var(--card-border);
            padding: 2px 10px;
            border-radius: 6px;
            margin-left: 6px;
            white-space: nowrap;
        }
        .price-cell { font-weight: 700; color: var(--primary); }
        .status-available { background: #ecfdf5; color: #059669; }
        .status-unavailable { background: #fef2f2; color: #dc2626; }
        .toggle-btn {
            width: 32px; height: 32px; border-radius: 8px;
            border: 1px solid var(--card-border); background: white;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s;
        }
        .toggle-btn.on  { color: #059669; border-color: #059669; }
        .toggle-btn.on:hover  { background: #ecfdf5; }
        .toggle-btn.off { color: #dc2626; border-color: #dc2626; }
        .toggle-btn.off:hover { background: #fef2f2; }
        .filter-select {
            padding: 0.75rem 1rem;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: var(--background);
            font-family: inherit;
            font-size: 0.9rem;
            color: var(--foreground);
            cursor: pointer;
        }

        /* Password Modal */
        .pw-user-label {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 1.5rem;
        }
        .pw-user-label strong { color: var(--foreground); }
        .pw-field-row {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .pw-field-row .form-control { flex: 1; font-family: 'DM Sans', monospace; font-size: 1rem; letter-spacing: 0.04em; }
        .btn-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--muted);
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .btn-icon:hover { border-color: var(--primary); color: var(--primary); background: var(--background); }
        .pw-hint {
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 2rem;
            line-height: 1.5;
        }
        .copy-toast {
            display: inline-block;
            font-size: 0.75rem;
            color: #059669;
            margin-left: 0.5rem;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .copy-toast.show { opacity: 1; }
    </style>
</head>
<body>
    <aside>
        <div class="logo-container">
            <div class="logo-icon"><i data-lucide="coffee"></i></div>
            <div class="logo-text">
                <h1>Top Juan</h1>
                <span>Admin Portal</span>
            </div>
        </div>

        <div class="menu-label">Menu</div>
        <nav>
            <a href="admin-dashboard.php" class="nav-item"><i data-lucide="layout-dashboard"></i> Dashboard</a>
            <a href="admin-orders.php" class="nav-item"><i data-lucide="clipboard-list"></i> Order Request</a>
            <a href="admin-usage.php" class="nav-item"><i data-lucide="activity"></i> Item Usage</a>
            <a href="admin-maintenance.php" class="nav-item active"><i data-lucide="settings-2"></i> Maintenance</a>
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
                <h2>System Maintenance</h2>
                <p>Manage your staff and franchise partners</p>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab(event, 'staff')"><i data-lucide="users"></i>Staff</button>
            <button class="tab-btn" onclick="switchTab(event, 'franchisee')"><i data-lucide="building"></i>Franchisees</button>
            <button class="tab-btn" onclick="switchTab(event, 'products')"><i data-lucide="package"></i>Products</button>
        </div>

        <div id="staff" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <h3>Staff Accounts</h3>
                    <button class="btn-primary" onclick="openModal('modal-user', 'staff')"><i data-lucide="plus"></i> Add Staff</button>
                </div>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php
                            $conn = new mysqli("localhost", "root", "", "juancafe");
                            $res = $conn->query("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.role_id IN (3,4,5)");
                            while($row = $res->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                                        <span class="role-badge"><?= $row['role_name'] ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($row['contact_number']) ?></td>
                                    <td><?= htmlspecialchars($row['email']) ?></td>
                                    <td><span class="status-badge status-active"><?= $row['status'] ?></span></td>
                                    <td class="actions-cell">
                                        <button class="action-btn" onclick="editUser(<?= $row['user_id'] ?>, 'staff')" title="Edit"><i data-lucide="edit-2" size="14"></i></button>
                                        <button class="action-btn" onclick="openPasswordModal(<?= $row['user_id'] ?>, '<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>')" title="Change Password"><i data-lucide="key-round" size="14"></i></button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="franchisee" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>Franchise Owners</h3>
                    <button class="btn-primary" onclick="openModal('modal-user', 'franchisee')"><i data-lucide="plus"></i> Register Franchisee</button>
                </div>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Owner Name</th><th>Branch</th><th>Contact</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php
                            $res = $conn->query("SELECT u.*, f.id as franchisee_id, f.franchisee_name, f.branch_name FROM users u LEFT JOIN franchisees f ON f.user_id = u.user_id WHERE u.role_id = 2");
                            while($row = $res->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($row['branch_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($row['contact_number']) ?></td>
                                    <td><?= htmlspecialchars($row['email']) ?></td>
                                    <td><span class="status-badge status-active"><?= $row['status'] ?></span></td>
                                    <td class="actions-cell">
                                        <button class="action-btn" onclick="editUser(<?= $row['user_id'] ?>, 'franchisee')" title="Edit"><i data-lucide="edit-2" size="14"></i></button>
                                        <button class="action-btn" onclick="openPasswordModal(<?= $row['user_id'] ?>, '<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>')" title="Change Password"><i data-lucide="key-round" size="14"></i></button>
                                    </td>
                                </tr>
                            <?php endwhile; $conn->close(); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── PRODUCTS TAB CONTENT ── -->
        <div id="products" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>Product Catalog</h3>
                    <button class="btn-primary" onclick="openProductModal()"><i data-lucide="plus"></i> Add Product</button>
                </div>
                <div class="action-bar">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input type="text" class="search-input" id="product-search" placeholder="Search products..." oninput="filterProducts()">
                    </div>
                    <select class="filter-select" id="category-filter" onchange="filterProducts()">
                        <option value="">All Categories</option>
                        <option value="Powder Flavor">Powder Flavor</option>
                        <option value="Syrup">Syrup</option>
                        <option value="Toppings">Toppings</option>
                        <option value="Tea Base">Tea Base</option>
                        <option value="Coffee Base">Coffee Base</option>
                        <option value="Base Mix">Base Mix</option>
                        <option value="Creamer">Creamer</option>
                        <option value="Sweetener">Sweetener</option>
                        <option value="Fruit Flavor">Fruit Flavor</option>
                        <option value="Milk">Milk</option>
                        <option value="Flavor">Flavor</option>
                    </select>
                    <select class="filter-select" id="status-filter" onchange="filterProducts()">
                        <option value="">All Status</option>
                        <option value="available">Available</option>
                        <option value="unavailable">Unavailable</option>
                    </select>
                </div>
                <div class="table-container">
                    <table id="products-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Unit</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $conn3 = new mysqli("localhost", "root", "", "juancafe");
                            $prod_res = $conn3->query("SELECT * FROM products ORDER BY category, name");
                            if ($prod_res && $prod_res->num_rows > 0):
                                while($p = $prod_res->fetch_assoc()):
                                    $isAvailable = $p['status'] === 'available';
                                    $statusClass  = $isAvailable ? 'status-available' : 'status-unavailable';
                                    $statusLabel  = $isAvailable ? 'Available' : 'Unavailable';
                                    $toggleClass  = $isAvailable ? 'on' : 'off';
                                    $toggleIcon   = $isAvailable ? 'toggle-right' : 'toggle-left';
                                    $nextStatus   = $isAvailable ? 'unavailable' : 'available';
                            ?>
                                <tr data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                                    data-category="<?= htmlspecialchars($p['category']) ?>"
                                    data-status="<?= htmlspecialchars($p['status']) ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($p['name']) ?></strong>
                                        <span class="category-tag"><?= htmlspecialchars($p['category']) ?></span>
                                    </td>
                                    <td class="price-cell">₱<?= number_format($p['price'], 2) ?></td>
                                    <td><?= htmlspecialchars($p['unit']) ?></td>
                                    <td><span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                    <td class="actions-cell">
                                        <button class="action-btn" title="Edit" onclick="editProduct(<?= $p['id'] ?>)">
                                            <i data-lucide="edit-2" size="14"></i>
                                        </button>
                                        <button class="toggle-btn <?= $toggleClass ?>" title="Toggle Status"
                                            onclick="toggleProductStatus(<?= $p['id'] ?>, '<?= $nextStatus ?>', this)">
                                            <i data-lucide="<?= $toggleIcon ?>" size="14"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile;
                            else: ?>
                                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:2rem;">No products found.</td></tr>
                            <?php endif; $conn3->close(); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- PASSWORD MODAL -->
    <div id="modal-password" class="modal-backdrop">
        <div class="modal" style="max-width:460px;">
            <div class="modal-header">
                <h3><i data-lucide="key-round" style="width:18px;height:18px;display:inline;margin-right:6px;vertical-align:middle;"></i> Change Password</h3>
                <button class="close-modal" onclick="closeModal('modal-password')"><i data-lucide="x"></i></button>
            </div>
            <p class="pw-user-label">Setting new password for: <strong id="pw-user-name">—</strong></p>
            <input type="hidden" id="pw-user-id">

            <label class="form-group" style="display:flex;flex-direction:column;gap:0.5rem;margin-bottom:0.5rem;">
                <span style="font-size:0.85rem;font-weight:600;color:var(--primary);">New Password</span>
            </label>
            <div class="pw-field-row">
                <input type="text" id="pw-new-value" class="form-control" placeholder="Generated password" readonly>
                <button class="btn-icon" onclick="copyPassword()" title="Copy password"><i data-lucide="copy" size="16"></i></button>
                <button class="btn-icon" onclick="regeneratePassword()" title="Generate new password"><i data-lucide="refresh-cw" size="16"></i></button>
            </div>
            <span class="copy-toast" id="copy-toast">✓ Copied!</span>
            <p class="pw-hint">A strong password has been auto-generated. You can regenerate or copy it before saving. Make sure to share it with the account holder.</p>

            <div class="form-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('modal-password')">Cancel</button>
                <button type="button" class="btn-primary" onclick="savePassword()"><i data-lucide="save" size="16"></i> Save Password</button>
            </div>
        </div>
    </div>

    <!-- PRODUCT MODAL -->
    <div id="modal-product" class="modal-backdrop">
        <div class="modal" style="max-width:660px;">
            <div class="modal-header">
                <h3 id="product-modal-title">Add Product</h3>
                <button class="close-modal" onclick="closeModal('modal-product')"><i data-lucide="x"></i></button>
            </div>
            <form id="productForm" onsubmit="handleProductSave(event)">
                <input type="hidden" name="product_id" id="pfield-id">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Product Name</label>
                        <input type="text" name="product_name" id="pfield-name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="pfield-category" class="form-control" required>
                            <option value="">Select category...</option>
                            <option value="Powder Flavor">Powder Flavor</option>
                            <option value="Syrup">Syrup</option>
                            <option value="Toppings">Toppings</option>
                            <option value="Tea Base">Tea Base</option>
                            <option value="Coffee Base">Coffee Base</option>
                            <option value="Base Mix">Base Mix</option>
                            <option value="Creamer">Creamer</option>
                            <option value="Sweetener">Sweetener</option>
                            <option value="Fruit Flavor">Fruit Flavor</option>
                            <option value="Milk">Milk</option>
                            <option value="Flavor">Flavor</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Unit of Measurement</label>
                        <select name="unit" id="pfield-unit" class="form-control" required>
                            <option value="">Select unit...</option>
                            <option value="g">Gram (g)</option>
                            <option value="kg">Kilogram (kg)</option>
                            <option value="ml">Milliliter (ml)</option>
                            <option value="L">Liter (L)</option>
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="pack">Pack</option>
                            <option value="box">Box</option>
                            <option value="sack">Sack</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Price (₱)</label>
                        <input type="number" name="price" id="pfield-price" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group full">
                        <label>Availability Status</label>
                        <select name="status" id="pfield-status" class="form-control" required>
                            <option value="available">Available</option>
                            <option value="unavailable">Unavailable</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('modal-product')">Cancel</button>
                    <button type="submit" class="btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-user" class="modal-backdrop">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modal-title">User Account</h3>
                <button class="close-modal" onclick="closeModal('modal-user')"><i data-lucide="x"></i></button>
            </div>
            <form id="userForm" onsubmit="handleSave(event)">
                <input type="hidden" name="user_id" id="field-id">
                <input type="hidden" name="role_id" id="field-role-id">

                <div class="form-grid">
                    <div class="form-group full"><label>Full Name</label><input type="text" name="full_name" id="field-name" class="form-control" required></div>
                    
                    <div class="form-group" id="role-selector-box">
                        <label>Role</label>
                        <select id="staff-role-select" class="form-control" onchange="document.getElementById('field-role-id').value = this.value">
                            <option value="4">Data Encoder</option>
                            <option value="3">Inventory Clerk</option>
                            <option value="5">Delivery Rider</option>
                        </select>
                    </div>

                    <!-- Franchisee-only fields -->
                    <div class="form-group full" id="franchisee-name-box" style="display:none;">
                        <label>Franchisee Name</label>
                        <input type="text" name="franchisee_name" id="field-franchisee-name" class="form-control" placeholder="Name of the franchise owner">
                    </div>
                    <div class="form-group full" id="branch-name-box" style="display:none;">
                        <label>Branch / Location</label>
                        <input type="text" name="branch_name" id="field-branch-name" class="form-control" placeholder="e.g. Talon 2, Las Piñas">
                    </div>

                    <div class="form-group" id="contact-box"><label>Contact Number</label><input type="text" name="contact_number" id="field-contact" class="form-control" required></div>
                    <div class="form-group full"><label>Email Address</label><input type="email" name="email" id="field-email" class="form-control" required></div>
                    
                    <div class="form-group full" id="password-box">
                        <label>Auto-Generated Password</label>
                        <input type="text" name="password" id="field-password" class="form-control">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('modal-user')">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function switchTab(e, tabId) {
            document.querySelectorAll('.tab-content, .tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            e.currentTarget.classList.add('active');
        }

        function openModal(modalId, type) {
            document.getElementById(modalId).classList.add('active');
            document.getElementById('userForm').reset();
            document.getElementById('field-id').value = "";
            document.getElementById('password-box').style.display = "block";
            
            if(type === 'staff') {
                document.getElementById('modal-title').innerText = "Add Staff Member";
                document.getElementById('role-selector-box').style.display = "block";
                document.getElementById('contact-box').classList.remove('full');
                document.getElementById('franchisee-name-box').style.display = "none";
                document.getElementById('branch-name-box').style.display = "none";
                document.getElementById('field-role-id').value = "4"; 
            } else {
                document.getElementById('modal-title').innerText = "Register Franchisee";
                document.getElementById('role-selector-box').style.display = "none";
                document.getElementById('contact-box').classList.add('full');
                document.getElementById('franchisee-name-box').style.display = "block";
                document.getElementById('branch-name-box').style.display = "block";
                document.getElementById('field-role-id').value = "2";
            }
            document.getElementById('field-password').value = Math.random().toString(36).slice(-8);
        }

        function editUser(id, type) {
            const formData = new FormData();
            formData.append('ajax_action', 'get_user_data');
            formData.append('user_id', id);

            fetch('admin-maintenance.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(user => {
                document.getElementById('modal-user').classList.add('active');
                document.getElementById('modal-title').innerText = "Edit Profile";
                document.getElementById('field-id').value = user.user_id;
                document.getElementById('field-name').value = user.full_name;
                document.getElementById('field-contact').value = user.contact_number;
                document.getElementById('field-email').value = user.email;
                document.getElementById('field-role-id').value = user.role_id;
                document.getElementById('password-box').style.display = "none";

                if(type === 'staff') {
                    document.getElementById('role-selector-box').style.display = "block";
                    document.getElementById('contact-box').classList.remove('full');
                    document.getElementById('staff-role-select').value = user.role_id;
                    document.getElementById('franchisee-name-box').style.display = "none";
                    document.getElementById('branch-name-box').style.display = "none";
                } else {
                    document.getElementById('role-selector-box').style.display = "none";
                    document.getElementById('contact-box').classList.add('full');
                    document.getElementById('franchisee-name-box').style.display = "block";
                    document.getElementById('branch-name-box').style.display = "block";
                    document.getElementById('field-franchisee-name').value = user.franchisee_name || user.full_name;
                    document.getElementById('field-branch-name').value = user.branch_name || '';
                }
            });
        }

        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function handleSave(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('ajax_action', 'save_user');

            fetch('admin-maintenance.php', { method: 'POST', body: formData })
            .then(res => res.text())
            .then(data => {
                if(data.trim() === "success") { location.reload(); }
                else { alert(data); }
            });
        }

        // ── PRODUCT MANAGEMENT ──────────────────────────────
        function openProductModal() {
            document.getElementById('productForm').reset();
            document.getElementById('pfield-id').value = "";
            document.getElementById('product-modal-title').innerText = "Add Product";
            document.getElementById('modal-product').classList.add('active');
            lucide.createIcons();
        }

        function editProduct(id) {
            const formData = new FormData();
            formData.append('ajax_action', 'get_product');
            formData.append('product_id', id);

            fetch('admin-maintenance.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(p => {
                document.getElementById('product-modal-title').innerText = "Edit Product";
                document.getElementById('pfield-id').value       = p.id;
                document.getElementById('pfield-name').value     = p.name;
                document.getElementById('pfield-category').value = p.category;
                document.getElementById('pfield-unit').value     = p.unit;
                document.getElementById('pfield-price').value    = p.price;
                document.getElementById('pfield-status').value   = p.status;
                document.getElementById('modal-product').classList.add('active');
                lucide.createIcons();
            });
        }

        function handleProductSave(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('ajax_action', 'save_product');

            fetch('admin-maintenance.php', { method: 'POST', body: formData })
            .then(res => res.text())
            .then(data => {
                if(data.trim() === "success") { location.reload(); }
                else { alert(data); }
            });
        }

        function toggleProductStatus(id, newStatus, btn) {
            if (!confirm(`Set this product to "${newStatus}"?`)) return;
            const formData = new FormData();
            formData.append('ajax_action', 'toggle_product_status');
            formData.append('product_id', id);
            formData.append('new_status', newStatus);

            fetch('admin-maintenance.php', { method: 'POST', body: formData })
            .then(res => res.text())
            .then(data => {
                if(data.trim() === "success") { location.reload(); }
                else { alert(data); }
            });
        }

        function filterProducts() {
            const search   = document.getElementById('product-search').value.toLowerCase();
            const category = document.getElementById('category-filter').value;
            const status   = document.getElementById('status-filter').value;

            document.querySelectorAll('#products-table tbody tr').forEach(row => {
                const matchName     = (row.dataset.name     || "").includes(search);
                const matchCategory = !category || row.dataset.category === category;
                const matchStatus   = !status   || row.dataset.status   === status;
                row.style.display   = (matchName && matchCategory && matchStatus) ? "" : "none";
            });
        }

        // ── PASSWORD MANAGEMENT ────────────────────────────
        function generatePassword(len = 10) {
            const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$';
            return Array.from({ length: len }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
        }

        function openPasswordModal(userId, userName) {
            document.getElementById('pw-user-id').value   = userId;
            document.getElementById('pw-user-name').textContent = userName;
            document.getElementById('pw-new-value').value = generatePassword();
            document.getElementById('copy-toast').classList.remove('show');
            document.getElementById('modal-password').classList.add('active');
            lucide.createIcons();
        }

        function regeneratePassword() {
            document.getElementById('pw-new-value').value = generatePassword();
            document.getElementById('copy-toast').classList.remove('show');
        }

        function copyPassword() {
            const val = document.getElementById('pw-new-value').value;
            navigator.clipboard.writeText(val).then(() => {
                const toast = document.getElementById('copy-toast');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2000);
            });
        }

        function savePassword() {
            const userId = document.getElementById('pw-user-id').value;
            const newPw  = document.getElementById('pw-new-value').value.trim();
            if (!newPw) { alert('Password cannot be empty.'); return; }

            const formData = new FormData();
            formData.append('ajax_action', 'change_password');
            formData.append('user_id', userId);
            formData.append('new_password', newPw);

            fetch('admin-maintenance.php', { method: 'POST', body: formData })
            .then(res => res.text())
            .then(data => {
                if (data.trim() === 'success') {
                    closeModal('modal-password');
                    alert('Password updated successfully!');
                } else {
                    alert('Error: ' + data);
                }
            });
        }
    </script>
</body>
</html>