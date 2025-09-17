<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $phone = trim(filter_var($_POST['phone'], FILTER_SANITIZE_NUMBER_INT));
    $password = $_POST['password'];
    
    // Validation
    $errors = [];
    
    // Phone validation
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = "Invalid phone number format";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit();
    }
    
    try {
        $conn = getDB();
        
        // Get user by phone number
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.phone, u.password, u.user_type, u.status, 
                   f.farm_name, f.farm_size, f.farm_address 
            FROM users u 
            LEFT JOIN farmers f ON u.id = f.user_id 
            WHERE u.phone = ? AND u.user_type = 'farmer'
        ");
        $stmt->execute([$phone]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'errors' => ['Phone number not registered or not a farmer account']]);
            exit();
        }
        
        // Check account status
        if ($user['status'] === 'suspended') {
            echo json_encode(['success' => false, 'errors' => ['Account suspended. Please contact support.']]);
            exit();
        }
        
        if ($user['status'] === 'inactive') {
            echo json_encode(['success' => false, 'errors' => ['Account inactive. Please contact support.']]);
            exit();
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            echo json_encode(['success' => false, 'errors' => ['Invalid password']]);
            exit();
        }
        
        // Create session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['phone'] = $user['phone'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['farm_name'] = $user['farm_name'];
        $_SESSION['farm_size'] = $user['farm_size'];
        $_SESSION['farm_address'] = $user['farm_address'];
        $_SESSION['logged_in'] = true;
        
        // Update last login
        $stmt = $conn->prepare("UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful! Redirecting to dashboard...',
            'user' => [
                'username' => $user['username'],
                'phone' => $user['phone'],
                'farm_name' => $user['farm_name']
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'errors' => ['Login failed: ' . $e->getMessage()]]);
    }
} else {
    header('Location: login.php');
    exit();
}
?>
