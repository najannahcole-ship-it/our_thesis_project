<?php
// ============================================================
// rider-profile.php — Rider Profile & Performance
// DB Tables used:
//   READ  → users   (full_name, email, contact_number, status)
//   READ  → orders  (total completed deliveries, total value)
//   WRITE → users   (UPDATE contact_number on save)
// ============================================================
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) { header('Location: index.php'); exit(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php';

$riderId   = $_SESSION['user_id'];
$riderName = $_SESSION['full_name'] ?? 'Delivery Rider';

// Handle POST: save updated contact number
$saveMsg = '';
$saveErr = '';

// Fetch rider user data
$stmt = $conn->prepare("SELECT full_name, email, contact_number, status, username FROM users WHERE user_id = ?");
$stmt->bind_param("i", $riderId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch delivery stats — all completed orders
$stmt = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as val FROM orders WHERE status_step = 4");
$stats = $stmt->fetch_assoc();
$totalDeliveries = $stats['cnt'] ?? 0;
$totalValue      = $stats['val'] ?? 0;

// This month
$stmt = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status_step = 4 AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
$thisMonth = $stmt->fetch_assoc()['cnt'] ?? 0;

$conn->close();

$fullName      = $user['full_name']      ?? $riderName;
$email         = $user['email']          ?? '—';
$contactRaw    = $user['contact_number'] ?? 0;
$accountStatus = $user['status']         ?? 'Active';
$username      = $user['username']       ?? '—';
$riderId_pad   = 'RID-' . str_pad($riderId, 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Profile - Top Juan Inc.</title>
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
        .sign-out{display:flex;align-items:center;gap:.5rem;text-decoration:none;color:var(--muted);font-size:.9rem;padding:.5rem;}
        main{margin-left:var(--sidebar-width);flex:1;padding:2.5rem 3rem;}
        .header{margin-bottom:2rem;}
        .header h2{font-family:'Fraunces',serif;font-size:2rem;margin-bottom:.25rem;}
        .header p{color:var(--muted);}
        /* Layout */
        .profile-grid{display:grid;grid-template-columns:1fr 360px;gap:2rem;align-items:start;}
        .card{background:white;border:1px solid var(--card-border);border-radius:20px;padding:2rem;margin-bottom:1.5rem;}
        .card:last-child{margin-bottom:0;}
        /* Hero */
        .hero{display:flex;align-items:center;gap:1.5rem;margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:1px solid var(--card-border);}
        .hero-avatar{width:80px;height:80px;background:var(--background);border-radius:20px;display:flex;align-items:center;justify-content:center;border:1px solid var(--card-border);}
        .hero-info h4{font-family:'Fraunces',serif;font-size:1.4rem;margin-bottom:.25rem;}
        .hero-info p{font-size:.88rem;color:var(--muted);}
        .badge-active{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .75rem;border-radius:20px;font-size:.75rem;font-weight:700;background:rgba(16,185,129,.1);color:#10b981;}
        /* Form */
        .section-title{font-family:'Fraunces',serif;font-size:1.1rem;margin-bottom:1.25rem;color:var(--primary);}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem;}
        .form-group label{display:block;font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.4rem;}
        .form-group input{width:100%;padding:.75rem 1rem;border-radius:12px;border:1.5px solid var(--card-border);font-family:inherit;font-size:.92rem;background:#fafafa;outline:none;transition:border-color .2s;}
        .form-group input:focus{border-color:var(--primary);background:white;}
        .form-group input[readonly]{background:var(--background);color:var(--muted);cursor:default;}
        .btn-save{background:var(--primary);color:white;border:none;padding:.875rem 1.5rem;border-radius:12px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.92rem;display:flex;align-items:center;gap:.5rem;transition:background .2s;}
        .btn-save:hover{background:var(--primary-light);}
        /* Stats */
        .metrics-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;}
        .metric{background:var(--background);border-radius:14px;padding:1.25rem;text-align:center;}
        .metric-val{font-family:'Fraunces',serif;font-size:1.75rem;font-weight:700;color:var(--primary);display:block;margin-bottom:.25rem;}
        .metric-lbl{font-size:.72rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.04em;}
        /* Alerts */
        .alert-success{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:.875rem 1rem;border-radius:10px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.9rem;}
        .alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.875rem 1rem;border-radius:10px;margin-bottom:1.5rem;display:flex;align-items:center;gap:.75rem;font-size:.9rem;}
    </style>
</head>
<body>
<aside>
    <div class="logo-container"><div class="logo-icon"><i data-lucide="truck"></i></div><div class="logo-text"><h1>Top Juan</h1><span>Delivery Rider</span></div></div>
    <p class="menu-label">Main Menu</p>
    <nav>
        <a href="rider-assignment.php" class="nav-item"><i data-lucide="clipboard-list"></i>Assignment</a>
        <a href="rider-tracking.php" class="nav-item"><i data-lucide="map-pin"></i>Delivery Tracking</a>
        <a href="rider-profile.php" class="nav-item active"><i data-lucide="user"></i>Profile</a>
        <a href="rider-history.php" class="nav-item"><i data-lucide="history"></i>Delivery History</a>
    </nav>
    <div class="user-profile"><div class="avatar"><i data-lucide="user"></i></div><div class="user-meta"><h4><?php echo htmlspecialchars($fullName); ?></h4><p>Delivery Rider</p></div></div>
    <a href="logout.php" class="sign-out"><i data-lucide="log-out"></i><span>Sign Out</span></a>
</aside>

<main>
    <div class="header"><h2>Rider Profile</h2><p>Your account information and delivery performance.</p></div>

    <?php if ($saveMsg): ?><div class="alert-success"><i data-lucide="check-circle" size="18"></i><?php echo htmlspecialchars($saveMsg); ?></div><?php endif; ?>
    <?php if ($saveErr): ?><div class="alert-error"><i data-lucide="alert-circle" size="18"></i><?php echo htmlspecialchars($saveErr); ?></div><?php endif; ?>

    <div class="profile-grid">
        <!-- LEFT: Info + editable contact -->
        <div class="card">
            <div class="hero">
                <div class="hero-avatar"><i data-lucide="user" size="36" style="color:var(--muted);"></i></div>
                <div class="hero-info">
                    <h4><?php echo htmlspecialchars($fullName); ?></h4>
                    <p>ID: <?php echo $riderId_pad; ?> · <span class="badge-active"><i data-lucide="circle" size="8"></i> <?php echo htmlspecialchars($accountStatus); ?></span></p>
                </div>
            </div>

            <h3 class="section-title">Account Information</h3>
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
                    <label>Account Status</label>
                    <input type="text" value="<?php echo htmlspecialchars($accountStatus); ?>" readonly>
                </div>
                <div class="form-group" style="grid-column:span 2;">
                    <label>Contact Number</label>
                    <input type="text" value="<?php echo htmlspecialchars($contactRaw ?: '—'); ?>" readonly>
                    <p style="font-size:.75rem;color:var(--muted);margin-top:.35rem;">Contact changes must be requested through the administrator.</p>
                </div>
            </div>
        </div>

        <!-- RIGHT: Performance stats -->
        <div>
            <div class="card">
                <h3 class="section-title">Performance Metrics</h3>
                <div class="metrics-grid">
                    <div class="metric">
                        <span class="metric-val"><?php echo number_format($totalDeliveries); ?></span>
                        <span class="metric-lbl">Total Deliveries</span>
                    </div>
                    <div class="metric">
                        <span class="metric-val"><?php echo $thisMonth; ?></span>
                        <span class="metric-lbl">This Month</span>
                    </div>
                </div>
                <div style="background:var(--background);border-radius:12px;padding:1rem;text-align:center;">
                    <div style="font-size:.82rem;color:var(--muted);margin-bottom:.25rem;">Total Value Delivered</div>
                    <div style="font-size:1.5rem;font-weight:700;font-family:'Fraunces',serif;color:var(--primary);">₱<?php echo number_format($totalValue, 2); ?></div>
                </div>
            </div>

            <div class="card">
                <h3 class="section-title">Quick Links</h3>
                <div style="display:flex;flex-direction:column;gap:.75rem;">
                    <a href="rider-assignment.php" style="display:flex;align-items:center;gap:.75rem;padding:.875rem 1rem;border:1px solid var(--card-border);border-radius:12px;text-decoration:none;color:var(--foreground);font-weight:500;transition:all .2s;">
                        <i data-lucide="clipboard-list" size="18" style="color:var(--primary);"></i>
                        View Current Assignments
                    </a>
                    <a href="rider-history.php" style="display:flex;align-items:center;gap:.75rem;padding:.875rem 1rem;border:1px solid var(--card-border);border-radius:12px;text-decoration:none;color:var(--foreground);font-weight:500;transition:all .2s;">
                        <i data-lucide="history" size="18" style="color:var(--primary);"></i>
                        View Delivery History
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>
<script>lucide.createIcons();</script>
</body>
</html>