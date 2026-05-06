<?php
// includes/auth.php - Updated with company support

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function isLoggedIn() {
    return isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: /broker_system/auth/login.php');
        exit;
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role' => $_SESSION['user_role'],
        'balance' => $_SESSION['user_balance'] ?? 0
    ];
}

function userLogin($userId, $fullName, $email, $role, $balance) {
    $_SESSION['user_logged_in'] = true;
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = $role;
    $_SESSION['user_balance'] = $balance;
    
    // Update last login
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $conn->close();
}

function userLogout() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: /broker_system/auth/login.php');
    exit;
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        header('Location: /broker_system/auth/login.php');
        exit;
    }
}

function adminLogout() {
    session_destroy();
    header('Location: /broker_system/auth/login.php');
    exit;
}
?>