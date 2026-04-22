<?php
session_start();

// 1. Kick out anyone who isn't logged in as an Admin (Role ID 1)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: index.php');
    exit();
}

// 2. Prevent the "Back" button from showing cached data after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 3. Get the Admin's name from the session
$adminName = $_SESSION['full_name'] ?? 'System Admin';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Tracking - Top Juan Inc.</title>
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

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        .summary-card {
            background: white;
            border: 1px solid var(--card-border);
            padding: 1.75rem;
            border-radius: 20px;
            position: relative;
        }
        .summary-card .icon-badge {
            position: absolute;
            top: 1.75rem;
            right: 1.75rem;
            color: var(--muted);
        }
        .summary-card .label { font-size: 0.9rem; color: var(--muted); margin-bottom: 0.5rem; font-weight: 500; }
        .summary-card .value { font-size: 2rem; font-weight: 700; font-family: 'Fraunces', serif; }
        .summary-card .subtext { font-size: 0.8rem; color: var(--muted); margin-top: 0.5rem; }

        .card {
            background: white;
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .card-header h3 { font-family: 'Fraunces', serif; font-size: 1.25rem; }

        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .search-input {
            padding: 0.75rem 1rem;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            flex: 1;
            font-family: inherit;
        }
        .filter-select {
            padding: 0.75rem 1rem;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            background: white;
            font-family: inherit;
        }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); border-bottom: 1px solid var(--card-border); }
        td { padding: 1.25rem 1rem; font-size: 0.9rem; border-bottom: 1px solid var(--card-border); }
        
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .status-picked-up { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .status-in-transit { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .status-delivered { background: rgba(16, 185, 129, 0.1); color: #10b981; }

        .action-btn {
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .action-btn:hover { background: rgba(56, 44, 36, 0.05); color: var(--primary); }

        .timeline {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .timeline-item {
            display: flex;
            gap: 1rem;
            position: relative;
        }
        .timeline-item:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 15px;
            top: 32px;
            bottom: -15px;
            width: 2px;
            background: var(--card-border);
        }
        .timeline-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--sidebar-bg);
            border: 2px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
            flex-shrink: 0;
        }
        .timeline-dot.active {
            border-color: var(--accent);
            color: var(--accent);
        }
        .timeline-content h4 { font-size: 0.9rem; margin-bottom: 0.15rem; }
        .timeline-content p { font-size: 0.8rem; color: var(--muted); }
        .timeline-content span { font-size: 0.7rem; color: var(--muted); opacity: 0.7; }

        .performance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }
        .perf-item {
            background: var(--sidebar-bg);
            padding: 1rem;
            border-radius: 12px;
            text-align: center;
        }
        .perf-value { font-family: 'Fraunces', serif; font-size: 1.25rem; font-weight: 700; color: var(--primary); }
        .perf-label { font-size: 0.75rem; color: var(--muted); margin-top: 0.25rem; }

        .grid-layout {
            display: grid;
            grid-template-columns: 2.5fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1200px) {
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .grid-layout { grid-template-columns: 1fr; }
        }
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
            <a href="admin-dashboard.php" class="nav-item" data-testid="link-dashboard"><i data-lucide="layout-dashboard"></i> Dashboard</a>
            <a href="admin-orders.php" class="nav-item" data-testid="link-orders"><i data-lucide="clipboard-list"></i> Order Request</a>
            <a href="admin-usage.php" class="nav-item" data-testid="link-usage"><i data-lucide="activity"></i> Item Usage</a>
            <a href="admin-maintenance.php" class="nav-item" data-testid="link-maintenance"><i data-lucide="settings-2"></i> Maintenance</a>
            <a href="admin-inventory.php" class="nav-item" data-testid="link-inventory"><i data-lucide="boxes"></i> Inventory</a>
            <a href="admin-returns.php" class="nav-item" data-testid="link-returns"><i data-lucide="rotate-ccw"></i> Return and Refund</a>
            <a href="admin-delivery.php" class="nav-item active" data-testid="link-delivery"><i data-lucide="truck"></i> Delivery</a>
            <a href="admin-reports.php" class="nav-item" data-testid="link-reports"><i data-lucide="bar-chart-3"></i> Report</a>
        </nav>

        <div class="user-profile">
            <div class="avatar"><i data-lucide="user-cog"></i></div>
            <div class="user-meta">
                <h4 data-testid="text-username">Admin Account</h4>
                <p>System Administrator</p>
            </div>
        </div>
        <a href="logout.php" class="sign-out" data-testid="button-logout"><i data-lucide="log-out"></i> Sign Out</a>
    </aside>

    <main>
        <div class="header">
            <div>
                <h2 data-testid="text-page-title">Delivery Monitoring</h2>
                <p>Track order statuses, rider assignments, and delivery performance metrics</p>
            </div>
            <button class="nav-item active" style="padding: 0.75rem 1.5rem; border: none; cursor: pointer;" data-testid="button-dispatch-new">
                <i data-lucide="plus"></i> Dispatch New
            </button>
        </div>

        <div class="summary-grid">
            <div class="summary-card" data-testid="card-active-deliveries">
                <i data-lucide="truck" class="icon-badge"></i>
                <p class="label">Active Deliveries</p>
                <div class="value" data-testid="text-active-deliveries">24</div>
                <p class="subtext">Currently on the road</p>
            </div>
            <div class="summary-card" data-testid="card-pending-pickup">
                <i data-lucide="package" class="icon-badge" style="color: var(--info)"></i>
                <p class="label">Pending Pickup</p>
                <div class="value" data-testid="text-pending-pickup">8</div>
                <p class="subtext">Awaiting rider assignment</p>
            </div>
            <div class="summary-card" data-testid="card-avg-delivery-time">
                <i data-lucide="timer" class="icon-badge" style="color: var(--success)"></i>
                <p class="label">Avg. Delivery Time</p>
                <div class="value" data-testid="text-avg-time">42m</div>
                <p class="subtext">Last 24 hours</p>
            </div>
            <div class="summary-card" data-testid="card-delivery-efficiency">
                <i data-lucide="trending-up" class="icon-badge" style="color: var(--accent)"></i>
                <p class="label">On-Time Rate</p>
                <div class="value" data-testid="text-on-time-rate">94%</div>
                <p class="subtext">Performance metric</p>
            </div>
        </div>

        <div class="grid-layout">
            <div class="card" data-testid="card-delivery-monitoring">
                <div class="card-header">
                    <h3>Active Delivery Operations</h3>
                    <div class="filters">
                        <input type="text" class="search-input" placeholder="Search Order ID or Branch..." data-testid="input-search-delivery">
                        <select class="filter-select" data-testid="select-status-filter">
                            <option>All Statuses</option>
                            <option>Picked Up</option>
                            <option>In Transit</option>
                            <option>Delivered</option>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Franchisee Location</th>
                            <th>Rider</th>
                            <th>Status</th>
                            <th>Est. Arrival</th>
                            <th style="text-align: right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr data-testid="row-delivery-1">
                            <td style="font-weight: 700;">#ORD-552</td>
                            <td>
                                <strong>Juan Coffee - Quezon City</strong><br>
                                <span style="font-size: 0.75rem; color: var(--muted)">SM North EDSA</span>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div class="avatar" style="width: 24px; height: 24px;"><i data-lucide="user" size="12"></i></div>
                                    <span>Ricardo M.</span>
                                </div>
                            </td>
                            <td><span class="status-badge status-in-transit"><i data-lucide="clock" size="12"></i> In Transit</span></td>
                            <td>Today, 2:00 PM</td>
                            <td style="text-align: right">
                                <button class="action-btn" title="Update Status" data-testid="button-update-552"><i data-lucide="refresh-cw" size="16"></i></button>
                                <button class="action-btn" title="Reassign Rider" data-testid="button-reassign-552"><i data-lucide="user-plus" size="16"></i></button>
                            </td>
                        </tr>
                        <tr data-testid="row-delivery-2">
                            <td style="font-weight: 700;">#ORD-548</td>
                            <td>
                                <strong>Juan Coffee - Makati</strong><br>
                                <span style="font-size: 0.75rem; color: var(--muted)">Glorietta 4</span>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div class="avatar" style="width: 24px; height: 24px;"><i data-lucide="user" size="12"></i></div>
                                    <span>Antonio G.</span>
                                </div>
                            </td>
                            <td><span class="status-badge status-picked-up"><i data-lucide="package" size="12"></i> Picked Up</span></td>
                            <td>Today, 1:15 PM</td>
                            <td style="text-align: right">
                                <button class="action-btn" title="Update Status" data-testid="button-update-548"><i data-lucide="refresh-cw" size="16"></i></button>
                                <button class="action-btn" title="Reassign Rider" data-testid="button-reassign-548"><i data-lucide="user-plus" size="16"></i></button>
                            </td>
                        </tr>
                        <tr data-testid="row-delivery-3">
                            <td style="font-weight: 700;">#ORD-545</td>
                            <td>
                                <strong>Juan Coffee - Manila</strong><br>
                                <span style="font-size: 0.75rem; color: var(--muted)">Malate Central</span>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div class="avatar" style="width: 24px; height: 24px;"><i data-lucide="user" size="12"></i></div>
                                    <span>Maria S.</span>
                                </div>
                            </td>
                            <td><span class="status-badge status-delivered"><i data-lucide="check-circle" size="12"></i> Delivered</span></td>
                            <td>Today, 10:30 AM</td>
                            <td style="text-align: right">
                                <button class="action-btn" title="View Logs" data-testid="button-logs-545"><i data-lucide="eye" size="16"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="side-content">
                <div class="card" data-testid="card-delivery-timeline">
                    <div class="card-header">
                        <h3>Delivery Timeline</h3>
                    </div>
                    <div class="timeline">
                        <div class="timeline-item" data-testid="timeline-item-1">
                            <div class="timeline-dot active"><i data-lucide="truck" size="14"></i></div>
                            <div class="timeline-content">
                                <h4>Out for Delivery</h4>
                                <p>Order #ORD-552 left the warehouse</p>
                                <span>12 minutes ago</span>
                            </div>
                        </div>
                        <div class="timeline-item" data-testid="timeline-item-2">
                            <div class="timeline-dot"><i data-lucide="package" size="14"></i></div>
                            <div class="timeline-content">
                                <h4>Order Picked Up</h4>
                                <p>Rider Antonio G. picked up #ORD-548</p>
                                <span>45 minutes ago</span>
                            </div>
                        </div>
                        <div class="timeline-item" data-testid="timeline-item-3">
                            <div class="timeline-dot" style="border-color: var(--success); color: var(--success);"><i data-lucide="check" size="14"></i></div>
                            <div class="timeline-content">
                                <h4>Successfully Delivered</h4>
                                <p>Order #ORD-545 reached Manila Branch</p>
                                <span>2 hours ago</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" data-testid="card-rider-performance" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h3>Rider Performance</h3>
                    </div>
                    <div class="performance-grid">
                        <div class="perf-item">
                            <div class="perf-value">12</div>
                            <div class="perf-label">Active Riders</div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-value">38m</div>
                            <div class="perf-label">Avg. Lead Time</div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-value">98%</div>
                            <div class="perf-label">Update Compliance</div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-value">4.9</div>
                            <div class="perf-label">Rider Rating</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>


    <script>
        lucide.createIcons();
    </script>
</body>
</html>
