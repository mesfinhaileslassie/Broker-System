<?php
// company/browse.php - Redirect to job posts

require_once '../includes/auth.php';
requireLogin();

if ($_SESSION['user_role'] != 'company') {
    header('Location: /broker_system/user/dashboard.php');
    exit;
}

// Companies browse job applicants, not listings
header('Location: job_posts.php?tab=applications');
exit;
?>