<?php
session_start();
require_once 'config/database.php';

echo "<h1>🔧 Farmer Selection Debug Tool</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
</style>";

try {
    $pdo = getDB();
    echo "<p class='success'>✅ Database connection successful</p>";
    
    // Check if tables exist
    echo "<h2>📋 Database Tables Check</h2>";
    $tables_to_check = ['users', 'farmers', 'farmer_stats'];
    
    foreach ($tables_to_check as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "<p class='success'>✅ Table '$table' exists</p>";
                
                // Get table structure
                $struct_stmt = $pdo->query("DESCRIBE $table");
                $columns = $struct_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "<h3>Table Structure: $table</h3>";
                echo "<table>";
                echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                foreach ($columns as $col) {
                    echo "<tr>";
                    echo "<td>{$col['Field']}</td>";
                    echo "<td>{$col['Type']}</td>";
                    echo "<td>{$col['Null']}</td>";
                    echo "<td>{$col['Key']}</td>";
                    echo "<td>{$col['Default']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
                
                // Count records
                $count_stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo "<p><strong>Records in $table:</strong> $count</p>";
                
            } else {
                echo "<p class='error'>❌ Table '$table' does not exist</p>";
            }
        } catch (PDOException $e) {
            echo "<p class='error'>❌ Error checking table '$table': " . $e->getMessage() . "</p>";
        }
    }
    
    // Check farmers in users table
    echo "<h2>👨‍🌾 Farmers in Users Table</h2>";
    try {
        $farmers_stmt = $pdo->query("SELECT id, username, full_name, business_name, email, phone, user_type, status, location FROM users WHERE user_type = 'farmer' LIMIT 10");
        $farmers = $farmers_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($farmers) > 0) {
            echo "<p class='success'>✅ Found " . count($farmers) . " farmers (showing first 10)</p>";
            echo "<table>";
            echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Business Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Location</th></tr>";
            foreach ($farmers as $farmer) {
                echo "<tr>";
                echo "<td>{$farmer['id']}</td>";
                echo "<td>{$farmer['username']}</td>";
                echo "<td>" . ($farmer['full_name'] ?: 'NULL') . "</td>";
                echo "<td>" . ($farmer['business_name'] ?: 'NULL') . "</td>";
                echo "<td>{$farmer['email']}</td>";
                echo "<td>{$farmer['phone']}</td>";
                echo "<td>{$farmer['status']}</td>";
                echo "<td>" . ($farmer['location'] ?: 'NULL') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>⚠️ No farmers found in users table</p>";
        }
    } catch (PDOException $e) {
        echo "<p class='error'>❌ Error querying farmers: " . $e->getMessage() . "</p>";
    }
    
    // Test the actual query from suppliers.php
    echo "<h2>🔍 Testing Suppliers Query</h2>";
    try {
        $test_sql = "SELECT DISTINCT u.id, u.full_name, u.business_name, u.email, u.phone, 
                           u.location, u.profile_image, u.created_at,
                           fs.total_products, fs.total_orders, fs.rating, fs.reviews_count,
                           fs.specialization, fs.verified_status
                    FROM users u 
                    LEFT JOIN farmer_stats fs ON u.id = fs.farmer_id 
                    WHERE u.user_type = 'farmer' AND u.status = 'active'
                    ORDER BY fs.rating DESC 
                    LIMIT 5";
        
        echo "<h3>Query being executed:</h3>";
        echo "<pre>$test_sql</pre>";
        
        $test_stmt = $pdo->query($test_sql);
        $test_results = $test_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<p><strong>Query returned:</strong> " . count($test_results) . " results</p>";
        
        if (count($test_results) > 0) {
            echo "<table>";
            echo "<tr><th>ID</th><th>Full Name</th><th>Business Name</th><th>Email</th><th>Location</th><th>Rating</th><th>Products</th></tr>";
            foreach ($test_results as $result) {
                echo "<tr>";
                echo "<td>{$result['id']}</td>";
                echo "<td>" . ($result['full_name'] ?: 'NULL') . "</td>";
                echo "<td>" . ($result['business_name'] ?: 'NULL') . "</td>";
                echo "<td>{$result['email']}</td>";
                echo "<td>" . ($result['location'] ?: 'NULL') . "</td>";
                echo "<td>" . ($result['rating'] ?: 'NULL') . "</td>";
                echo "<td>" . ($result['total_products'] ?: 'NULL') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
    } catch (PDOException $e) {
        echo "<p class='error'>❌ Query failed: " . $e->getMessage() . "</p>";
        
        // Try simpler query
        echo "<h3>Trying simpler query without farmer_stats:</h3>";
        try {
            $simple_sql = "SELECT id, username, full_name, business_name, email, phone, location 
                          FROM users 
                          WHERE user_type = 'farmer' AND status = 'active' 
                          LIMIT 5";
            
            echo "<pre>$simple_sql</pre>";
            
            $simple_stmt = $pdo->query($simple_sql);
            $simple_results = $simple_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<p><strong>Simple query returned:</strong> " . count($simple_results) . " results</p>";
            
            if (count($simple_results) > 0) {
                echo "<table>";
                echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Business Name</th><th>Email</th><th>Location</th></tr>";
                foreach ($simple_results as $result) {
                    echo "<tr>";
                    echo "<td>{$result['id']}</td>";
                    echo "<td>{$result['username']}</td>";
                    echo "<td>" . ($result['full_name'] ?: 'NULL') . "</td>";
                    echo "<td>" . ($result['business_name'] ?: 'NULL') . "</td>";
                    echo "<td>{$result['email']}</td>";
                    echo "<td>" . ($result['location'] ?: 'NULL') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            
        } catch (PDOException $e2) {
            echo "<p class='error'>❌ Simple query also failed: " . $e2->getMessage() . "</p>";
        }
    }
    
    // Check if farmer_stats table needs to be created
    echo "<h2>🔧 Recommendations</h2>";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'farmer_stats'");
    if ($stmt->rowCount() == 0) {
        echo "<p class='warning'>⚠️ farmer_stats table is missing. This table is needed for the suppliers page to work properly.</p>";
        echo "<p><strong>Solution:</strong> Create the farmer_stats table or modify the query to work without it.</p>";
        
        echo "<h3>Option 1: Create farmer_stats table</h3>";
        echo "<pre>
CREATE TABLE farmer_stats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    farmer_id INT NOT NULL,
    total_products INT DEFAULT 0,
    total_orders INT DEFAULT 0,
    rating DECIMAL(3,2) DEFAULT 0.00,
    reviews_count INT DEFAULT 0,
    specialization TEXT,
    verified_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE
);
        </pre>";
        
        echo "<h3>Option 2: Fix suppliers.php to work without farmer_stats</h3>";
        echo "<p>Modify the query to use only the users table and calculate stats on the fly.</p>";
    }
    
    // Check for empty full_name fields
    $empty_names_stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'farmer' AND (full_name IS NULL OR full_name = '')");
    $empty_names = $empty_names_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($empty_names > 0) {
        echo "<p class='warning'>⚠️ Found $empty_names farmers with empty full_name fields. This might cause display issues.</p>";
        echo "<p><strong>Solution:</strong> Update farmers to have proper names, or modify the display logic to use username as fallback.</p>";
    }
    
} catch (PDOException $e) {
    echo "<p class='error'>❌ Database connection failed: " . $e->getMessage() . "</p>";
    echo "<p>Please check your XAMPP MySQL service and database configuration.</p>";
}

echo "<hr>";
echo "<p><a href='buyer/suppliers.php'>← Back to Suppliers Page</a> | <a href='setup_local.php'>Run Setup</a></p>";
?>
