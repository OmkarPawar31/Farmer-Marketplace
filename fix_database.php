<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

echo "<h2>Database Structure Fix</h2>";

try {
    $conn = getDB();
    echo "✅ Database connection successful<br><br>";
    
    // Check and fix buyers table structure
    echo "<h3>Checking buyers table structure...</h3>";
    
    // Get current table structure
    $stmt = $conn->prepare("DESCRIBE buyers");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $existing_columns = array_column($columns, 'Field');
    echo "Current columns: " . implode(', ', $existing_columns) . "<br>";
    
    // Required columns for buyers table
    $required_columns = [
        'id', 'user_id', 'company_name', 'business_type', 'business_registration', 
        'gst_number', 'business_address', 'state', 'district', 'pincode', 
        'contact_person', 'business_documents', 'procurement_capacity', 'payment_terms'
    ];
    
    $missing_columns = array_diff($required_columns, $existing_columns);
    
    if (empty($missing_columns)) {
        echo "✅ All required columns exist in buyers table<br>";
    } else {
        echo "❌ Missing columns: " . implode(', ', $missing_columns) . "<br>";
        
        // Add missing columns
        foreach ($missing_columns as $column) {
            try {
                switch ($column) {
                    case 'business_documents':
                        $sql = "ALTER TABLE buyers ADD COLUMN business_documents JSON";
                        break;
                    case 'procurement_capacity':
                        $sql = "ALTER TABLE buyers ADD COLUMN procurement_capacity DECIMAL(15,2)";
                        break;
                    case 'payment_terms':
                        $sql = "ALTER TABLE buyers ADD COLUMN payment_terms VARCHAR(100)";
                        break;
                    default:
                        continue 2; // Skip unknown columns
                }
                
                $conn->exec($sql);
                echo "✅ Added column: $column<br>";
            } catch (Exception $e) {
                echo "❌ Failed to add column $column: " . $e->getMessage() . "<br>";
            }
        }
    }
    
    // Test a registration
    echo "<br><h3>Testing Registration Process...</h3>";
    
    $test_data = [
        'username' => 'test_buyer_' . time(),
        'email' => 'test_' . time() . '@example.com',
        'password' => 'testpassword123',
        'phone' => '9876543210',
        'business_type' => 'company',
        'company_name' => 'Test Company Ltd',
        'contact_person' => 'John Doe',
        'business_address' => '123 Test Street, Test City',
        'state' => 'Maharashtra',
        'district' => 'Mumbai',
        'pincode' => '400001',
        'procurement_capacity' => '10.5',
        'payment_terms' => '15_days'
    ];
    
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
        
        // Insert into buyers table (without created_at)
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
            
            // Clean up test data
            $conn->prepare("DELETE FROM buyers WHERE user_id = ?")->execute([$user_id]);
            $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
            echo "✅ Test data cleaned up<br>";
            
        } else {
            echo "❌ Failed to insert buyer record<br>";
        }
    } else {
        echo "❌ Failed to insert user record<br>";
    }
    
    $conn->rollback();
    
    echo "<br><h3>✅ Database structure check completed!</h3>";
    echo "<p><strong>Your buyer registration should now work properly.</strong></p>";
    echo "<p><a href='buyer/register.php' style='color: #2a9d8f; text-decoration: underline;'>Try registering as a buyer now</a></p>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
    
    if (isset($conn)) {
        $conn->rollback();
    }
}

echo "<br><hr><br>";
echo "<h3>Database Tables Status:</h3>";

// Check all required tables
$required_tables = ['users', 'buyers', 'farmers', 'crops', 'product_listings', 'notifications'];

foreach ($required_tables as $table) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM $table");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        echo "✅ Table '$table' exists with $count records<br>";
    } catch (Exception $e) {
        echo "❌ Table '$table' missing or inaccessible<br>";
    }
}
?>
