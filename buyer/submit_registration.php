<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header('Location: register.php');
    exit();
}

$conn = getDB();
$errors = [];

// Debug: Log the received POST data
error_log('Registration attempt - POST data: ' . print_r($_POST, true));
error_log('Registration attempt - FILES data: ' . print_r($_FILES, true));

try {
    // Validate required fields
    $required_fields = ['username', 'email', 'phone', 'password', 'business_type', 'company_name', 'contact_person', 'business_address', 'state', 'district', 'pincode', 'procurement_capacity', 'payment_terms'];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }

    // Validate email format
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }

    // Check if username already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$_POST['username']]);
    if ($stmt->fetch()) {
        $errors[] = 'Username already exists';
    }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$_POST['email']]);
    if ($stmt->fetch()) {
        $errors[] = 'Email already registered';
    }

    // Validate phone number
    if (!preg_match('/^[0-9]{10}$/', $_POST['phone'])) {
        $errors[] = 'Phone number must be 10 digits';
    }

    // Validate password length
    if (strlen($_POST['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }

    // Validate pincode
    if (!preg_match('/^[0-9]{6}$/', $_POST['pincode'])) {
        $errors[] = 'Pincode must be 6 digits';
    }

    // Validate procurement capacity
    if (!is_numeric($_POST['procurement_capacity']) || $_POST['procurement_capacity'] <= 0) {
        $errors[] = 'Invalid procurement capacity';
    }

    if (!empty($errors)) {
        $_SESSION['registration_errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header('Location: register.php');
        exit();
    }

    // Handle file uploads
    $business_documents = [];
    if (!empty($_FILES['business_documents']['name'][0])) {
        $upload_dir = '../uploads/buyers/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $allowed_types = ['pdf', 'jpg', 'jpeg', 'png'];
        
        for ($i = 0; $i < count($_FILES['business_documents']['name']); $i++) {
            $file_name = $_FILES['business_documents']['name'][$i];
            $file_tmp = $_FILES['business_documents']['tmp_name'][$i];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            if (in_array($file_ext, $allowed_types)) {
                $new_file_name = uniqid() . '_' . $file_name;
                $file_path = $upload_dir . $new_file_name;
                
                if (move_uploaded_file($file_tmp, $file_path)) {
                    $business_documents[] = $new_file_name;
                }
            }
        }
    }

    // Start transaction
    $conn->beginTransaction();

    // Insert into users table
    $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("
        INSERT INTO users (username, email, password, phone, user_type, status, verification_status, created_at) 
        VALUES (?, ?, ?, ?, 'buyer', 'pending', 'unverified', NOW())
    ");
    $stmt->execute([
        $_POST['username'],
        $_POST['email'],
        $hashed_password,
        $_POST['phone']
    ]);

    $user_id = $conn->lastInsertId();

    // Insert into buyers table
    $stmt = $conn->prepare("
        INSERT INTO buyers (
            user_id, company_name, business_type, business_registration, gst_number,
            business_address, state, district, pincode, contact_person,
            business_documents, procurement_capacity, payment_terms
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $user_id,
        $_POST['company_name'],
        $_POST['business_type'],
        $_POST['business_registration'] ?? null,
        $_POST['gst_number'] ?? null,
        $_POST['business_address'],
        $_POST['state'],
        $_POST['district'],
        $_POST['pincode'],
        $_POST['contact_person'],
        json_encode($business_documents),
        $_POST['procurement_capacity'],
        $_POST['payment_terms']
    ]);

    // Insert welcome notification (with error handling for missing table)
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, created_at) 
            VALUES (?, ?, ?, 'system', NOW())
        ");
        $stmt->execute([
            $user_id,
            'Welcome to FarmConnect!',
            'Your buyer account has been created successfully. Your account is pending verification. You will receive a confirmation once your account is approved.'
        ]);
    } catch (Exception $notif_error) {
        // Log notification error but don't fail registration
        error_log('Notification insert failed: ' . $notif_error->getMessage());
    }

    // Commit transaction
    $conn->commit();

    // Set success message and redirect
    session_start();
    $_SESSION['registration_success'] = true;
    $_SESSION['registered_email'] = $_POST['email'];
    header('Location: registration_success.php');
    exit();

} catch (Exception $e) {
    $conn->rollback();
    session_start();
    $_SESSION['registration_errors'] = [
        'Registration failed. Please try again.',
        'Error: ' . $e->getMessage() // Add this line for debugging
    ];
    $_SESSION['form_data'] = $_POST;
    header('Location: register.php');
    exit();
}
?>
