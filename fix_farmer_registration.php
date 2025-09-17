<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

echo "<h2>Farmer Registration Database Fix</h2>";

try {
    $conn = getDB();
    echo "✅ Database connection successful<br><br>";
    
    // Check if there are any users with empty emails causing the duplicate issue
    echo "<h3>Checking for duplicate/empty email issues...</h3>";
    
    $stmt = $conn->prepare("SELECT id, username, email, phone, user_type FROM users WHERE email = '' OR email IS NULL");
    $stmt->execute();
    $empty_emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($empty_emails)) {
        echo "❌ Found " . count($empty_emails) . " users with empty emails:<br>";
        foreach ($empty_emails as $user) {
            echo "- ID: {$user['id']}, Username: {$user['username']}, Phone: {$user['phone']}, Type: {$user['user_type']}<br>";
        }
        
        echo "<br><strong>Fixing empty emails...</strong><br>";
        
        // Fix empty emails by generating unique emails from phone numbers
        foreach ($empty_emails as $user) {
            if (!empty($user['phone'])) {
                $new_email = $user['phone'] . '@' . $user['user_type'] . '.farmconnect.local';
                $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
                $stmt->execute([$new_email, $user['id']]);
                echo "✅ Fixed user ID {$user['id']}: {$new_email}<br>";
            }
        }
    } else {
        echo "✅ No users with empty emails found<br>";
    }
    
    // Check for actual duplicate emails
    echo "<br><h3>Checking for duplicate emails...</h3>";
    $stmt = $conn->prepare("
        SELECT email, COUNT(*) as count 
        FROM users 
        GROUP BY email 
        HAVING COUNT(*) > 1
    ");
    $stmt->execute();
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($duplicates)) {
        echo "❌ Found duplicate emails:<br>";
        foreach ($duplicates as $dup) {
            echo "- Email: '{$dup['email']}' appears {$dup['count']} times<br>";
            
            // Get the duplicate users
            $stmt = $conn->prepare("SELECT id, username, phone, user_type FROM users WHERE email = ?");
            $stmt->execute([$dup['email']]);
            $dup_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Keep the first user, update others
            $first = true;
            foreach ($dup_users as $user) {
                if ($first) {
                    echo "  → Keeping user ID {$user['id']} ({$user['username']})<br>";
                    $first = false;
                } else {
                    $new_email = $user['phone'] . '@' . $user['user_type'] . '.farmconnect.local.' . $user['id'];
                    $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $stmt->execute([$new_email, $user['id']]);
                    echo "  → Updated user ID {$user['id']} to: {$new_email}<br>";
                }
            }
        }
    } else {
        echo "✅ No duplicate emails found<br>";
    }
    
    // Test farmer registration
    echo "<br><h3>Testing Farmer Registration...</h3>";
    
    $test_data = [
        'username' => 'test_farmer_' . time(),
        'phone' => '98765' . str_pad(mt_rand(0, 99999), 5, '0', STR_PAD_LEFT),
        'password' => 'TestPass123',
        'farm_name' => 'Test Farm',
        'farm_size' => '5.5',
        'location' => 'Test Village, Test District, Test State'
    ];
    
    $email = $test_data['phone'] . '@farmer.farmconnect.local';
    
    echo "Testing with data:<br>";
    echo "- Username: {$test_data['username']}<br>";
    echo "- Phone: {$test_data['phone']}<br>";
    echo "- Generated Email: {$email}<br>";
    echo "- Farm: {$test_data['farm_name']}<br><br>";
    
    $conn->beginTransaction();
    
    try {
        // Insert user with generated email
        $hashed_password = password_hash($test_data['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, phone, password, user_type, status, verification_status, created_at) VALUES (?, ?, ?, ?, 'farmer', 'pending', 'unverified', NOW())");
        $stmt->execute([$test_data['username'], $email, $test_data['phone'], $hashed_password]);
        $user_id = $conn->lastInsertId();
        
        echo "✅ User record inserted successfully (ID: $user_id)<br>";
        
        // Insert farmer details
        $stmt = $conn->prepare("INSERT INTO farmers (user_id, farm_name, farm_size, farm_address, farm_documents) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $test_data['farm_name'], $test_data['farm_size'], $test_data['location'], json_encode([])]);
        
        echo "✅ Farmer record inserted successfully<br>";
        
        // Clean up test data
        $conn->prepare("DELETE FROM farmers WHERE user_id = ?")->execute([$user_id]);
        $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
        echo "✅ Test data cleaned up<br>";
        
        $conn->rollback();
        
        echo "<br><h3>🎉 Farmer registration should now work properly!</h3>";
        echo "<p><a href='farmer/register.php' style='color: #2a9d8f; text-decoration: underline;'>Try registering as a farmer now</a></p>";
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "❌ Registration test failed: " . $e->getMessage() . "<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
}

echo "<br><hr><br>";
echo "<h3>Current User Statistics:</h3>";

try {
    // Show user statistics
    $stmt = $conn->prepare("SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type");
    $stmt->execute();
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stats as $stat) {
        echo "- {$stat['user_type']}: {$stat['count']} users<br>";
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users");
    $stmt->execute();
    $total = $stmt->fetchColumn();
    echo "<strong>Total users: $total</strong><br>";
    
} catch (Exception $e) {
    echo "Could not fetch user statistics<br>";
}
?>
