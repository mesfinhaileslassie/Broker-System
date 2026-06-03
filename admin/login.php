<?php
// admin/login.php - Admin Login Page

session_start();

// If already logged in as admin, redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Check against database
    require_once '../config/database.php';
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT id, full_name, password_hash FROM users WHERE email = ? AND role = 'admin'");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $admin = $result->fetch_assoc();
        if (password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['full_name'];
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid password';
        }
    } else {
        $error = 'Admin account not found';
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea, #764ba2);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            width: 400px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo-icon { font-size: 48px; }
        h1 { font-size: 24px; margin-bottom: 8px; }
        p { color: #64748b; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
        }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">
            <div class="logo-icon">🏪</div>
            <h1>Admin Login</h1>
            <p>Enter your credentials to access dashboard</p>
        </div>
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="username" placeholder="admin@example.com" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>