<?php
/**
 * Local XAMPP Setup Script for Farmer Marketplace
 * Run this script once to set up the complete database and initial data
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🌾 Farmer Marketplace - Local XAMPP Setup</h1>";

// Database connection parameters
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'farmer_marketplace';

try {
    // Step 1: Connect to MySQL (without database)
    echo "<h2>Step 1: Connecting to MySQL...</h2>";
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Connected to MySQL successfully<br>";

    // Step 2: Create database
    echo "<h2>Step 2: Creating Database...</h2>";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✅ Database '$database' created successfully<br>";

    // Step 3: Use the database
    $pdo->exec("USE $database");
    echo "✅ Using database '$database'<br>";

    // Step 4: Execute main schema
    echo "<h2>Step 3: Creating Main Tables...</h2>";
    $mainSchema = file_get_contents('config/schema.sql');
    if ($mainSchema) {
        // Split by semicolon and execute each statement
        $statements = explode(';', $mainSchema);
        $tableCount = 0;
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                    if (stripos($statement, 'CREATE TABLE') !== false) {
                        $tableCount++;
                    }
                } catch (PDOException $e) {
                    // Skip errors for existing tables
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        echo "⚠️ Warning: " . $e->getMessage() . "<br>";
                    }
                }
            }
        }
        echo "✅ Main schema loaded ($tableCount tables created)<br>";
    } else {
        echo "❌ Could not read main schema file<br>";
    }

    // Step 5: Execute security schema
    echo "<h2>Step 4: Creating Security Tables...</h2>";
    if (file_exists('config/security_schema.sql')) {
        $securitySchema = file_get_contents('config/security_schema.sql');
        if ($securitySchema) {
            $statements = explode(';', $securitySchema);
            $securityTableCount = 0;
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement) && stripos($statement, 'CREATE DATABASE') === false && stripos($statement, 'USE ') === false) {
                    try {
                        $pdo->exec($statement);
                        if (stripos($statement, 'CREATE TABLE') !== false) {
                            $securityTableCount++;
                        }
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate entry') === false) {
                            echo "⚠️ Security Warning: " . $e->getMessage() . "<br>";
                        }
                    }
                }
            }
            echo "✅ Security schema loaded ($securityTableCount additional tables)<br>";
        }
    }

    // Step 6: Execute supply chain schema
    echo "<h2>Step 5: Creating Supply Chain Tables...</h2>";
    if (file_exists('config/supply_chain_schema.sql')) {
        $supplyChainSchema = file_get_contents('config/supply_chain_schema.sql');
        if ($supplyChainSchema) {
            $statements = explode(';', $supplyChainSchema);
            $supplyChainTableCount = 0;
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement) && stripos($statement, 'USE ') === false) {
                    try {
                        $pdo->exec($statement);
                        if (stripos($statement, 'CREATE TABLE') !== false) {
                            $supplyChainTableCount++;
                        }
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate entry') === false) {
                            echo "⚠️ Supply Chain Warning: " . $e->getMessage() . "<br>";
                        }
                    }
                }
            }
            echo "✅ Supply chain schema loaded ($supplyChainTableCount additional tables)<br>";
        }
    }

    // Step 7: Create upload directories
    echo "<h2>Step 6: Creating Upload Directories...</h2>";
    $uploadDirs = [
        'uploads',
        'uploads/farmers',
        'uploads/buyers', 
        'uploads/products',
        'uploads/documents',
        'uploads/temp'
    ];

    foreach ($uploadDirs as $dir) {
        if (!file_exists($dir)) {
            if (mkdir($dir, 0777, true)) {
                echo "✅ Created directory: $dir<br>";
            } else {
                echo "❌ Failed to create directory: $dir<br>";
            }
        } else {
            echo "✅ Directory exists: $dir<br>";
        }
        
        // Create .htaccess for security
        $htaccessPath = $dir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            $htaccessContent = "Options -Indexes\n";
            $htaccessContent .= "deny from all\n";
            $htaccessContent .= "<Files ~ \"\\.(jpg|jpeg|png|gif|pdf)$\">\n";
            $htaccessContent .= "allow from all\n";
            $htaccessContent .= "</Files>";
            file_put_contents($htaccessPath, $htaccessContent);
        }
    }

    // Step 8: Create sample data
    echo "<h2>Step 7: Creating Sample Data...</h2>";
    
    // Check if sample data already exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $userCount = $stmt->fetchColumn();
    
    if ($userCount == 0) {
        // Create admin user
        $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, email, password, phone, user_type, status, verification_status, created_at) 
                   VALUES ('admin', 'admin@farmermarketplace.com', '$adminPassword', '9999999999', 'admin', 'active', 'verified', NOW())");
        echo "✅ Admin user created (username: admin, password: admin123)<br>";
        
        // Create sample farmer
        $farmerPassword = password_hash('farmer123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, email, password, phone, user_type, status, verification_status, created_at) 
                   VALUES ('farmer1', 'farmer@example.com', '$farmerPassword', '9876543210', 'farmer', 'active', 'verified', NOW())");
        
        $farmerId = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO farmers (user_id, farm_name, farm_size, farm_address, state, district, village, pincode, experience_years, created_at) 
                   VALUES ($farmerId, 'Green Valley Farm', 5.5, 'Village Greenfield', 'Maharashtra', 'Pune', 'Greenfield', '411001', 10, NOW())");
        echo "✅ Sample farmer created (username: farmer1, password: farmer123)<br>";
        
        // Create sample buyer
        $buyerPassword = password_hash('buyer123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, email, password, phone, user_type, status, verification_status, created_at) 
                   VALUES ('buyer1', 'buyer@example.com', '$buyerPassword', '9876543211', 'buyer', 'active', 'verified', NOW())");
        
        $buyerId = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO buyers (user_id, company_name, business_type, business_address, state, district, pincode, contact_person, procurement_capacity, payment_terms, created_at) 
                   VALUES ($buyerId, 'Fresh Foods Ltd', 'company', 'Business District, Mumbai', 'Maharashtra', 'Mumbai', '400001', 'John Manager', 100.0, 'immediate', NOW())");
        echo "✅ Sample buyer created (username: buyer1, password: buyer123)<br>";
        
    } else {
        echo "✅ Sample data already exists ($userCount users found)<br>";
    }

    // Step 9: Load market data
    echo "<h2>Step 8: Loading Market Price Data...</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) FROM market_prices");
    $priceCount = $stmt->fetchColumn();
    
    if ($priceCount == 0 && file_exists('populate_market_data.php')) {
        include 'populate_market_data.php';
        echo "✅ Market price data loaded<br>";
    } else {
        echo "✅ Market price data already exists ($priceCount records)<br>";
    }

    // Step 10: Verify installation
    echo "<h2>Step 9: Installation Verification...</h2>";
    
    $tables = ['users', 'farmers', 'buyers', 'crops', 'product_listings', 'orders'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "✅ Table '$table': $count records<br>";
    }

    echo "<h2>🎉 Setup Complete!</h2>";
    echo "<div style='background: #e8f5e8; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
    echo "<h3>🔗 Access Your Application:</h3>";
    echo "<ul>";
    echo "<li><strong>Main Site:</strong> <a href='http://localhost/farmer-marketplace/' target='_blank'>http://localhost/farmer-marketplace/</a></li>";
    echo "<li><strong>Admin Panel:</strong> <a href='http://localhost/farmer-marketplace/admin/' target='_blank'>http://localhost/farmer-marketplace/admin/</a></li>";
    echo "<li><strong>Farmer Dashboard:</strong> <a href='http://localhost/farmer-marketplace/farmer/dashboard.php' target='_blank'>Farmer Login</a></li>";
    echo "<li><strong>Buyer Dashboard:</strong> <a href='http://localhost/farmer-marketplace/buyer/dashboard.php' target='_blank'>Buyer Login</a></li>";
    echo "</ul>";
    
    echo "<h3>👤 Test Accounts:</h3>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> username: admin, password: admin123</li>";
    echo "<li><strong>Farmer:</strong> username: farmer1, password: farmer123</li>";
    echo "<li><strong>Buyer:</strong> username: buyer1, password: buyer123</li>";
    echo "</ul>";
    
    echo "<h3>🗄️ Database Info:</h3>";
    echo "<ul>";
    echo "<li><strong>Database:</strong> farmer_marketplace</li>";
    echo "<li><strong>phpMyAdmin:</strong> <a href='http://localhost/phpmyadmin/' target='_blank'>http://localhost/phpmyadmin/</a></li>";
    echo "</ul>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<h2>❌ Setup Failed</h2>";
    echo "<div style='background: #ffe8e8; padding: 20px; border-radius: 10px;'>";
    echo "<strong>Error:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>Please check:</strong><br>";
    echo "• XAMPP is running (Apache + MySQL)<br>";
    echo "• MySQL service is started<br>";
    echo "• No firewall blocking connections<br>";
    echo "</div>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
h1 { color: #2d5a27; }
h2 { color: #5d4e75; border-bottom: 2px solid #eee; padding-bottom: 10px; }
ul { margin: 10px 0; }
a { color: #2a9d8f; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>
