<?php
// company/legal_process.php - Legal Process for Companies

require_once '../includes/auth.php';
requireLogin();

if ($_SESSION['user_role'] != 'company') {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

// Companies handle legal process through job applications
header('Location: job_posts.php?tab=applications');
exit;
?>