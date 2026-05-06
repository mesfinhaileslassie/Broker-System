<?php
// company/listings.php - Show company listings or helpful message

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check if logged in
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /broker_system/auth/login.php');
    exit;
}

// Check if user has company role
if ($_SESSION['user_role'] != 'company') {
    // Show helpful message instead of just redirecting
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Access Denied</title>
        <style>
            body { font-family: Arial; text-align: center; padding: 50px; }
            .error-box { background: #fee2e2; border: 1px solid #fecaca; border-radius: 10px; padding: 20px; max-width: 500px; margin: 0 auto; }
            h1 { color: #dc2626; }
            .btn { display: inline-block; padding: 10px 20px; margin-top: 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>⚠️ Access Denied</h1>
            <p>You don't have company access. Your current role is: <strong><?php echo htmlspecialchars($_SESSION['user_role']); ?></strong></p>
            <p>To access company features, you need to have a 'company' role.</p>
            <a href="/broker_system/user/dashboard.php" class="btn">Go to User Dashboard</a>
            <a href="/broker_system/auth/logout.php" class="btn" style="background: #64748b;">Logout</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Companies manage their job posts
header('Location: job_posts.php');
exit;
?>