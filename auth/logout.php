<?php
// auth/logout.php - User logout

session_start();
session_destroy();
header('Location: /broker_system/index.php');
exit;