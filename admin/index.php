<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Redirect to dashboard
header('Location: dashboard.php');
exit();
?>
