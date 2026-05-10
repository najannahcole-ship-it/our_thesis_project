<?php
session_start();
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        $pdo = new PDO(
            "mysql:host=" . getenv("DB_HOST") .
            ";port=" . getenv("DB_PORT") .
            ";dbname=" . getenv("DB_NAME") .
            ";charset=utf8mb4",
            getenv("DB_USER"),
            getenv("DB_PASSWORD"),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $email = trim($_POST['email'] ?? '');
        $pass_input = $_POST['password'] ?? '';

        // Fetch user data by email
        $stmt = $pdo->prepare("
            SELECT user_id, full_name, password, status, role_id
            FROM users
            WHERE email = ?
        ");

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        if ($user) {

            // Verify password
            if (!password_verify($pass_input, $user['password'])) {
                $error = "Incorrect password. Please try again.";
            }

            // Check account status
            elseif ($user['status'] !== 'Active') {
                $error = "Your account is Inactive. Please contact the Admin.";
            }

            // Login success
            else {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['full_name'] = $user['full_name'];

                switch ((int)$user['role_id']) {

                    case 1:
                        header("Location: admin-dashboard.php");
                        exit();

                    case 2:
                        header("Location: franchisee-dashboard.php");
                        exit();

                    case 3:
                        header("Location: clerk-dashboard.php");
                        exit();

                    case 4:
                        header("Location: encoder-dashboard.php");
                        exit();

                    case 5:
                        header("Location: rider-assignment.php");
                        exit();

                    default:
                        $error = "Role not recognized. Contact support.";
                        break;
                }
            }

        } else {
            $error = "The email address entered was not recognized.";
        }

    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Juan Café Franchise Management</title>

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
        }

        .footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.75rem;
            color: var(--muted-foreground);
        }
    </style>
</head>

<body>

<div class="bg-effect"></div>

<div class="container">

    <div class="hero">
        <h1>Juan Café<span>Franchise Portal</span></h1>

        <p>
            Sign in to manage orders, inventory, and reports — built for Top Juan Inc. Metro Manila branches.
        </p>
    </div>

    <div class="login-card">

        <h2>Sign in</h2>

        <p class="subtitle">
            Use your company email and password.
        </p>

        <?php if ($error): ?>
            <div style="color:#d25424;background:#fff1ed;padding:10px;border-radius:8px;margin-bottom:1rem;font-size:0.875rem;border:1px solid #d25424;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php">

            <div class="form-group">
                <label>Email</label>

                <div class="input-wrapper">
                    <input
                        type="email"
                        name="email"
                        placeholder="name@juancafe.ph"
                        required
                    >
                </div>
            </div>

            <div class="form-group">
                <label>Password</label>

                <div class="input-wrapper">
                    <input
                        type="password"
                        name="password"
                        placeholder="••••••••"
                        required
                    >
                </div>
            </div>

            <button type="submit" class="submit-btn">
                Sign in
            </button>

        </form>

        <div class="footer">
            © 2026 Top Juan Franchising Inc. • Juan Café
        </div>

    </div>

</div>

</body>
</html>