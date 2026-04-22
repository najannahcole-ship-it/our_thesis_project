<?php
session_start();
$error = ""; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = new mysqli("localhost", "root", "", "juancafe");
    
    if ($conn->connect_error) {
        die("Connection failed.");
    }

    $email = trim($_POST['email'] ?? '');
    $pass_input = $_POST['password'] ?? ''; 

    // 1. Fetch user data by email
    $stmt = $conn->prepare("SELECT user_id, full_name, password, status, role_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // 2. Verify Password
        if (!password_verify($pass_input, $user['password'])) {
            $error = "Incorrect password. Please try again.";
        }
        // 3. Check if account is Active
        elseif ($user['status'] !== 'Active') {
            $error = "Your account is Inactive. Please contact the Admin.";
        }
        // 4. Success! Set sessions and Route by role_id
        else {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['full_name'] = $user['full_name'];

            // Mapping IDs from your database image
            switch ($user['role_id']) {
                case 1: // Administrator
                    header("Location: admin-dashboard.php");
                    break;
                case 2: // Franchisee
                    header("Location: franchisee-dashboard.php");
                    break;
                case 3: // Inventory Clerk
                    header("Location: clerk-dashboard.php");
                    break;
                case 4: // Data Encoder
                    header("Location: encoder-dashboard.php");
                    break;
                case 5: // Delivery Rider
                    header("Location: rider-assignment.php");
                    break;
                default:
                    $error = "Role not recognized. Contact support.";
                    break;
            }
            if (empty($error)) exit();
        }
    } else {
        $error = "The email address entered was not recognized.";
    }

    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Juan Café Franchise Management</title>
    <!-- Google Fonts: Fraunces (Display) and DM Sans (Body) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --background: #f8f6f4;
            --foreground: #221f1d;
            --card: #fdfcfb;
            --card-border: #dcd7d2;
            --primary: #382c24;
            --primary-foreground: #fcfaf8;
            --accent: #d25424;
            --accent-foreground: #fcfaf8;
            --muted: #ebe7e3;
            --muted-foreground: #665c54;
            --radius: 16px;
            --font-display: 'Fraunces', serif;
            --font-body: 'DM Sans', sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-body);
            background-color: var(--background);
            color: var(--foreground);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
        }

        /* Coffee Grid Background Effect */
        .bg-effect {
            position: fixed;
            inset: 0;
            z-index: -1;
            background-image: 
                radial-gradient(1000px 600px at 20% 10%, rgba(210, 84, 36, 0.1), transparent 55%),
                radial-gradient(900px 520px at 80% 0%, rgba(56, 44, 36, 0.08), transparent 55%);
        }

        .container {
            width: 100%;
            max-width: 1100px;
            padding: 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
        }

        @media (max-width: 900px) {
            .container {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }

        .hero h1 {
            font-family: var(--font-display);
            font-size: 3.5rem;
            line-height: 1.1;
            margin-bottom: 1.5rem;
        }

        .hero h1 span {
            display: block;
            color: var(--accent);
        }

        .hero p {
            color: var(--muted-foreground);
            font-size: 1.125rem;
            max-width: 480px;
            margin-bottom: 2rem;
        }

        .features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .feature-card {
            background: rgba(253, 252, 251, 0.6);
            border: 1px solid var(--card-border);
            padding: 1.25rem;
            border-radius: var(--radius);
            backdrop-filter: blur(8px);
        }

        .feature-card h3 {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .feature-card p {
            font-size: 0.875rem;
            margin-bottom: 0;
        }

        .login-card {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 20px 50px -20px rgba(0,0,0,0.1);
        }

        .login-card h2 {
            font-family: var(--font-display);
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }

        .login-card p.subtitle {
            color: var(--muted-foreground);
            font-size: 0.875rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--muted-foreground);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input {
            width: 100%;
            height: 48px;
            padding: 0 1rem;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: #fff;
            font-family: inherit;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .input-wrapper input:focus {
            border-color: var(--accent);
        }

        .role-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .role-btn {
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: rgba(255,255,255,0.4);
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--muted-foreground);
        }

        .role-btn.active {
            background: var(--primary);
            color: var(--primary-foreground);
            border-color: var(--primary);
        }

        .submit-btn {
            width: 100%;
            height: 48px;
            background: var(--primary);
            color: var(--primary-foreground);
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            margin-top: 1rem;
            transition: opacity 0.2s;
        }

        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.75rem;
            color: var(--muted-foreground);
        }

        .grid-5 {
            grid-template-columns: repeat(2, 1fr);
        }
        @media (min-width: 640px) {
            .grid-5 {
                grid-template-columns: repeat(5, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="bg-effect"></div>

    <div class="container">
        <div class="hero">
            <h1>Juan Café<span>Franchise Portal</span></h1>
            <p>Sign in to manage orders, inventory, and reports — built for Top Juan Inc. Metro Manila branches.</p>
            
            <div class="features">
                <div class="feature-card">
                    <h3>Ordering</h3>
                    <p>Streamlined purchase orders.</p>
                </div>
                <div class="feature-card">
                    <h3>Inventory</h3>
                    <p>Real-time stock tracking.</p>
                </div>
            </div>
        </div>

        <div class="login-wrapper">
    <div class="login-card">
        <h2>Sign in</h2>
        <p class="subtitle">Use your company email and password.</p>

        <?php if ($error): ?>
            <div style="color: #d25424; background: #fff1ed; padding: 10px; border-radius: 8px; margin-bottom: 1rem; font-size: 0.875rem; border: 1px solid #d25424;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <div class="form-group">
                <label>Email</label>
                <div class="input-wrapper">
                    <input type="email" name="email" id="email" placeholder="name@juancafe.ph" oninput="validate()" required>
                </div>
            </div>

            <div class="form-group">
                <label>Password</label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="password" placeholder="••••••••" oninput="validate()" required>
                </div>
            </div>

            <button type="submit" id="submitBtn" class="submit-btn" disabled>Sign in</button>
        </form>
        
        <div class="footer">
            © 2026 Top Juan Franchising Inc. • Juan Café
        </div>
    </div>
</div>
    </div>

    <script>
    function validate() {
        const email = document.getElementById('email').value;
        const pass = document.getElementById('password').value;
        const btn = document.getElementById('submitBtn');
        // Simple check to enable button
        btn.disabled = !(email.trim().length > 0 && pass.trim().length > 0);
    }

        function handleLogin() {
            const email = document.getElementById('email').value.toLowerCase();
            if (email.includes('admin')) {
                window.location.href = 'admin-dashboard.html';
            } else if (email.includes('clerk')) {
                window.location.href = 'clerk-dashboard.html';
            } else {
                window.location.href = 'franchisee-dashboard.html';
            }
        }
    </script>
</body>
</html>
