<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/database.php';
$conn = getDB();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate and sanitize input data
    $username = trim(filter_var($_POST['username'], FILTER_SANITIZE_STRING));
    $phone = trim(filter_var($_POST['phone'], FILTER_SANITIZE_NUMBER_INT));
    $password = $_POST['password'];
    $farm_name = trim(filter_var($_POST['farm_name'], FILTER_SANITIZE_STRING));
    $farm_size = filter_var($_POST['farm_size'], FILTER_VALIDATE_FLOAT);
    $location = trim(filter_var($_POST['location'], FILTER_SANITIZE_STRING));
    
    // Generate a unique email from phone number since farmers don't provide email
    $email = $phone . '@farmer.farmconnect.local';
    
    // Validation
    $errors = [];
    
    // Username validation
    if (empty($username) || strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters long";
    }
    
    // Phone validation
    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = "Phone number must be exactly 10 digits";
    }
    
    // Check if phone already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->rowCount() > 0) {
        $errors[] = "Phone number already registered";
    }
    
    // Check if username already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->rowCount() > 0) {
        $errors[] = "Username already taken";
    }
    
    // Password validation
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    if (!preg_match('/[a-zA-Z]/', $password)) {
        $errors[] = "Password must contain at least one letter";
    }
    
    // Farm details validation
    if (empty($farm_name)) {
        $errors[] = "Farm name is required";
    }
    if ($farm_size === false || $farm_size <= 0) {
        $errors[] = "Farm size must be a valid positive number";
    }
    
    // If there are validation errors, return them
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit();
    }
    
    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // File upload handling
    $documents = [];
    if (!empty($_FILES['documents']['name'][0])) {
        foreach ($_FILES['documents']['name'] as $key => $name) {
            $tmp_name = $_FILES['documents']['tmp_name'][$key];
            $file_name = time() . "_" . basename($name);
            $file_path = '../uploads/' . $file_name;
            if (move_uploaded_file($tmp_name, $file_path)) {
                $documents[] = $file_name;
            }
        }
    }

    try {
        // Begin transaction
        $conn->beginTransaction();
        
        // Insert user with generated email
        $stmt = $conn->prepare("INSERT INTO users (username, email, phone, password, user_type, status, verification_status, created_at) VALUES (?, ?, ?, ?, 'farmer', 'pending', 'unverified', NOW())");
        $stmt->execute([$username, $email, $phone, $password_hash]);
        $user_id = $conn->lastInsertId();
        
        // Insert farmer details
        $stmt = $conn->prepare("INSERT INTO farmers (user_id, farm_name, farm_size, farm_address, farm_documents) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $farm_name, $farm_size, $location, json_encode($documents)]);
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Registration successful! You can now login with your phone number.'
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo json_encode(['success' => false, 'errors' => ['Registration failed: ' . $e->getMessage()]]);
    }
} else {
    header('Location: register.php');
    exit();
}
?>
