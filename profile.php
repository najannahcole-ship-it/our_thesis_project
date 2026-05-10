<?php
// ============================================================
// profile.php — Franchisee Profile Settings
// DB Tables used:
//   READ  → users        (full_name, email, contact_number, status, username)
//   READ  → franchisees  (branch_name, franchisee_name, id)
//   READ  → orders       (total orders count, total spent)
// Per thesis: editing is restricted to the administrator.
// Franchisees can only VIEW their registered information.
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

require_once 'db.php';

$userId = $_SESSION['user_id'];

// ── Fetch user data from DB ───────────────────────────────────
$stmt = $conn->prepare(
    "SELECT user_id, full_name, email, contact_number, status, username FROM users WHERE user_id = ?"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Fetch franchisee / branch data ───────────────────────────
$franchisee = getFranchiseeByUser($conn, $userId);
$franchiseeId   = $franchisee['id']              ?? null;
$branchName     = $franchisee['branch_name']     ?? '—';
$franchiseeName = $franchisee['franchisee_name'] ?? '—';

// ── Fetch order stats ─────────────────────────────────────────
$totalOrders = 0;
$totalSpent  = 0;

if ($franchiseeId) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as spent FROM orders WHERE franchisee_id = ?"
    );
    $stmt->bind_param("i", $franchiseeId);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $totalOrders = $stats['cnt']   ?? 0;
    $totalSpent  = $stats['spent'] ?? 0;
    $stmt->close();
}

$conn->close();

// ── Format display values ─────────────────────────────────────
$fullName      = $user['full_name']      ?? '—';
$email         = $user['email']          ?? '—';
$contactRaw    = $user['contact_number'] ?? 0;
$contactNumber = $contactRaw && $contactRaw != 0
    ? '+63 ' . substr((string)$contactRaw, 0, 3) . ' ' . substr((string)$contactRaw, 3, 3) . ' ' . substr((string)$contactRaw, 6)
    : '—';
$accountStatus = $user['status']   ?? '—';
$username      = $user['username'] ?? '—';

// Branch ID: use franchisee id zero-padded (e.g. id=7 → FR-0007)
$branchId = $franchiseeId ? 'FR-' . str_pad($franchiseeId, 4, '0', STR_PAD_LEFT) : '—';

// Format total spent
$spentDisplay = $totalSpent >= 1000000
    ? '₱' . number_format($totalSpent / 1000000, 1) . 'M'
    : '₱' . number_format($totalSpent, 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Juan Café</title>
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
        .header{margin-bottom:2.5rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.5rem;}
        .header p{color:var(--muted);}

        /* Layout */
        .profile-container{display:grid;grid-template-columns:300px 1fr;gap:2.5rem;align-items:start;}
        .profile-sidebar{display:flex;flex-direction:column;gap:1.5rem;}
        .card{background:white;border:1px solid var(--card-border);border-radius:24px;padding:2rem;}

        /* Avatar card */
        .avatar-card{text-align:center;}
        .profile-avatar{width:110px;height:110px;border-radius:50%;background:#f3f4f6;margin:0 auto 1.25rem;display:flex;align-items:center;justify-content:center;border:4px solid white;box-shadow:0 4px 12px rgba(0,0,0,.08);}
        .avatar-card h3{font-family:'Fraunces',serif;font-size:1.25rem;margin-bottom:.25rem;}
        .avatar-card p{font-size:.85rem;color:var(--muted);font-weight:500;}

        /* Stats card */
        .stats-card{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
        .stat-item{text-align:center;padding:.75rem;background:var(--background);border-radius:12px;}
        .stat-value{display:block;font-size:1.5rem;font-weight:700;color:var(--primary);font-family:'Fraunces',serif;}
        .stat-label{font-size:.72rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.04em;}

        /* View-only notice */
        .notice-bar{background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:.875rem 1rem;margin-bottom:2rem;display:flex;align-items:center;gap:.75rem;font-size:.875rem;color:#92400e;}

        /* Form sections */
        .profile-form-card{border-radius:24px;}
        .form-section{margin-bottom:2.5rem;}
        .form-section:last-child{margin-bottom:0;}
        .section-title{font-family:'Fraunces',serif;font-size:1.2rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.5rem;color:var(--primary);}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;}
        .form-group{margin-bottom:0;}
        .form-group.full{grid-column:span 2;}
        .form-group label{display:block;font-size:.82rem;font-weight:700;color:var(--muted);margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.03em;}
        .form-group input{width:100%;padding:.875rem 1rem;border:1.5px solid var(--card-border);border-radius:12px;font-family:inherit;font-size:.95rem;background:#fafafa;color:var(--foreground);cursor:default;}
        /* All inputs are read-only — style them as display fields */
        .form-group input[readonly]{background:var(--background);color:var(--foreground);border-color:var(--card-border);}
        .form-group input.status-active{color:#166534;font-weight:700;background:#f0fdf4;border-color:#86efac;}
        .form-group input.status-inactive{color:#991b1b;font-weight:700;background:#fee2e2;border-color:#fca5a5;}

        /* Contact info note */
        .contact-note{font-size:.78rem;color:var(--muted);margin-top:.4rem;font-style:italic;}
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
        <a href="order-history.php" class="nav-item"><i data-lucide="history"></i> Order History</a>
        <a href="profile.php" class="nav-item active"><i data-lucide="user"></i> Profile</a>
    </nav>
    <div class="user-profile">
        <div class="avatar"><i data-lucide="user"></i></div>
        <div class="user-meta">
            <h4><?php echo htmlspecialchars($fullName); ?></h4>
            <p style="font-size:.72rem;color:var(--muted);font-weight:500;"><?php echo htmlspecialchars($branchName ?? $franchisee['branch_name'] ?? 'Franchisee'); ?></p>
        </div>
    </div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i> Sign Out</a>
</aside>

<main>
    <div class="header">
        <h2>Profile Settings</h2>
        <p>View your registered account and branch information.</p>
    </div>

    <!-- View-only notice per thesis spec -->
    <div class="notice-bar">
        <i data-lucide="info" size="16"></i>
        <span>Your profile information is managed by the administrator. Contact admin to request any changes.</span>
    </div>

    <div class="profile-container">

        <!-- LEFT: Avatar + Stats -->
        <div class="profile-sidebar">

            <!-- Avatar Card -->
            <div class="card avatar-card">
                <div class="profile-avatar">
                    <i data-lucide="user" size="48" style="color:var(--muted);"></i>
                </div>
                <h3><?php echo htmlspecialchars($fullName); ?></h3>
                <p>Franchisee Account <?php echo $branchId; ?></p>
            </div>

            <!-- Stats Card — live from DB -->
            <div class="card">
                <div class="stats-card">
                    <div class="stat-item">
                        <span class="stat-value"><?php echo number_format($totalOrders); ?></span>
                        <span class="stat-label">Orders</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $spentDisplay; ?></span>
                        <span class="stat-label">Total Spent</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- RIGHT: Profile Info — all from DB, all read-only -->
        <div class="card profile-form-card">

            <!-- Personal Information -->
            <div class="form-section">
                <h3 class="section-title"><i data-lucide="user" size="20"></i> Personal Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" value="<?php echo htmlspecialchars($fullName); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" value="<?php echo htmlspecialchars($username); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" value="<?php echo htmlspecialchars($email); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" value="<?php echo htmlspecialchars($contactNumber); ?>" readonly>
                        <?php if ($contactRaw == 0): ?>
                        <p class="contact-note">No contact number on record.</p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Account Status</label>
                        <input type="text"
                               value="<?php echo htmlspecialchars($accountStatus); ?>"
                               class="<?php echo $accountStatus === 'Active' ? 'status-active' : 'status-inactive'; ?>"
                               readonly>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <input type="text" value="Franchisee" readonly>
                    </div>
                </div>
            </div>

            <!-- Branch Information -->
            <div class="form-section">
                <h3 class="section-title"><i data-lucide="map-pin" size="20"></i> Branch Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Branch Name</label>
                        <input type="text" value="<?php echo htmlspecialchars($branchName); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Branch ID</label>
                        <input type="text" value="<?php echo htmlspecialchars($branchId); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Owner / Operator Name</label>
                        <input type="text" value="<?php echo htmlspecialchars($franchiseeName); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Total Orders Placed</label>
                        <input type="text" value="<?php echo number_format($totalOrders); ?> order<?php echo $totalOrders != 1 ? 's' : ''; ?>" readonly>
                    </div>
                </div>

                <?php if (!$franchiseeId): ?>
                <div style="margin-top:1.25rem;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;font-size:.875rem;color:#92400e;">
                    <strong>Branch not linked.</strong> Your account is not yet connected to a branch record. Please contact the administrator.
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<script>lucide.createIcons();</script>
</body>
</html>