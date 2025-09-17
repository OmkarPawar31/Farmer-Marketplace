<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header('Location: login.php');
    exit();
}

$conn = getDB();

try {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Check if user exists and is a buyer
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.email, u.password, u.status, u.verification_status, 
               b.company_name, b.business_type
        FROM users u 
        JOIN buyers b ON u.id = b.user_id 
        WHERE (u.username = ? OR u.email = ?) AND u.user_type = 'buyer'
    ");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['login_error'] = 'Invalid username/email or password';
        header('Location: login.php');
        exit();
    }

    // Verify password
    if (!password_verify($password, $user['password'])) {
        $_SESSION['login_error'] = 'Invalid username/email or password';
        header('Location: login.php');
        exit();
    }

    // Check if account is active
    if ($user['status'] === 'suspended') {
        $_SESSION['login_error'] = 'Your account has been suspended. Please contact support.';
        header('Location: login.php');
        exit();
    }

    if ($user['status'] === 'inactive') {
        $_SESSION['login_error'] = 'Your account is inactive. Please contact support.';
        header('Location: login.php');
        exit();
    }

    // Set session variables
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['user_type'] = 'buyer';
    $_SESSION['company_name'] = $user['company_name'];
    $_SESSION['business_type'] = $user['business_type'];
    $_SESSION['account_status'] = $user['status'];
    $_SESSION['verification_status'] = $user['verification_status'];

    // Update last login
    $stmt = $conn->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);

    // Redirect to dashboard
    header('Location: dashboard.php');
    exit();

} catch (Exception $e) {
    $_SESSION['login_error'] = 'Login failed. Please try again.';
    header('Location: login.php');
    exit();
}
?>
