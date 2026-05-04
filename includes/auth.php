<?php
// includes/auth.php

session_start();

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        header('Location: /broker_system/admin/login.php');
        exit;
    }
}

function adminLogin($password) {
    // Simple admin auth (use hashed password in production)
    $admin_password = 'admin123'; // Change this!
    
    if ($password === $admin_password) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_name'] = 'Administrator';
        return true;
    }
    return false;
}

function adminLogout() {
    session_destroy();
    header('Location: login.php');
    exit;
}