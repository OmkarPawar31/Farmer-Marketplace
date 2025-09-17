<?php
require_once 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $email = $_POST['email'];
    $role = $_POST['role'];

    $result = $auth->register($username, $password, $email, $role);

    if (isset($result['success'])) {
        echo '<div class="success">' . $result['success'] . ' <a href="login_process.php">Login here</a></div>';
    } else {
        echo '<div class="error">' . $result['error'] . '</div>';
    }
    include 'includes/register.php';
} else {
    include 'includes/register.php';
}
?>
