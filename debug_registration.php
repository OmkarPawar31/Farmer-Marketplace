<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

echo "<h2>Database Connection Test</h2>";

try {
    $conn = getDB();
    echo "✅ Database connection successful<br>";
    
    // Check if tables exist
    $tables = ['users', 'buyers'];
    foreach ($tables as $table) {
        $stmt = $conn->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        
        if ($stmt->fetch()) {
            echo "✅ Table '$table' exists<br>";
            
            // Check table structure
            $stmt = $conn->prepare("DESCRIBE $table");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<strong>$table structure:</strong><br>";
            foreach ($columns as $column) {
                echo "- {$column['Field']} ({$column['Type']})<br>";
            }
            echo "<br>";
        } else {
            echo "❌ Table '$table' does not exist<br>";
        }
    }
    
    // Test a simple insert
    echo "<h3>Testing Registration Process</h3>";
    
    // Sample data for testing
    $test_data = [
        'username' => 'test_buyer_' . time(),
        'email' => 'test_' . time() . '@example.com',
        'password' => 'testpassword123',
        'phone' => '9876543210',
        'business_type' => 'company',
        'company_name' => 'Test Company',
        'contact_person' => 'John Doe',
        'business_address' => 'Test Address',
        'state' => 'Maharashtra',
        'district' => 'Mumbai',
        'pincode' => '400001',
        'procurement_capacity' => '10.5',
        'payment_terms' => '15_days'
    ];
    
    // Start transaction
    $conn->beginTransaction();
    
    // Insert into users table
    $hashed_password = password_hash($test_data['password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("
        INSERT INTO users (username, email, password, phone, user_type, status, verification_status, created_at) 
        VALUES (?, ?, ?, ?, 'buyer', 'pending', 'unverified', NOW())
    ");
    
    $result1 = $stmt->execute([
        $test_data['username'],
        $test_data['email'],
        $hashed_password,
        $test_data['phone']
    ]);
    
    if ($result1) {
        echo "✅ User record inserted successfully<br>";
        $user_id = $conn->lastInsertId();
        echo "User ID: $user_id<br>";
        
        // Insert into buyers table
        $stmt = $conn->prepare("
            INSERT INTO buyers (
                user_id, company_name, business_type, business_registration, gst_number,
                business_address, state, district, pincode, contact_person,
                business_documents, procurement_capacity, payment_terms
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result2 = $stmt->execute([
            $user_id,
            $test_data['company_name'],
            $test_data['business_type'],
            null, // business_registration
            null, // gst_number
            $test_data['business_address'],
            $test_data['state'],
            $test_data['district'],
            $test_data['pincode'],
            $test_data['contact_person'],
            json_encode([]), // business_documents
            $test_data['procurement_capacity'],
            $test_data['payment_terms']
        ]);
        
        if ($result2) {
            echo "✅ Buyer record inserted successfully<br>";
            $conn->commit();
            echo "✅ Transaction committed successfully<br>";
            
            // Clean up test data
            $conn->prepare("DELETE FROM buyers WHERE user_id = ?")->execute([$user_id]);
            $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
            echo "✅ Test data cleaned up<br>";
        } else {
            echo "❌ Failed to insert buyer record<br>";
            $conn->rollback();
        }
    } else {
        echo "❌ Failed to insert user record<br>";
        $conn->rollback();
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
    
    if (isset($conn)) {
        $conn->rollback();
    }
}

echo "<h3>PHP Configuration</h3>";
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "PDO available: " . (extension_loaded('pdo') ? 'Yes' : 'No') . "<br>";
echo "PDO MySQL available: " . (extension_loaded('pdo_mysql') ? 'Yes' : 'No') . "<br>";
echo "Session status: " . session_status() . "<br>";

phpinfo(INFO_MODULES);
?>
