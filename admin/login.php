<?php
// admin/login.php - Redirect to Unified Login System

session_start();

// If already logged in as admin, redirect to dashboard
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true && 
    isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    header('Location: dashboard.php');
    exit;
}

// If already logged in but not admin, redirect to user dashboard
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

// Store the intended destination
$_SESSION['redirect_after_login'] = '/broker_system/admin/dashboard.php';

// Redirect to main login page
header('Location: /broker_system/auth/login.php');
exit;
?>