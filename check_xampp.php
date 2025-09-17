<?php
/**
 * XAMPP Service Checker for Farmer Marketplace
 * This script helps verify that all required services are running
 */

echo "<h1>🔧 XAMPP Service Checker</h1>";

// Check PHP version
echo "<h2>📋 System Information</h2>";
echo "✅ PHP Version: " . phpversion() . "<br>";
echo "✅ Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "✅ Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";

// Check required PHP extensions
echo "<h2>🔌 PHP Extensions</h2>";
$required_extensions = ['pdo', 'pdo_mysql', 'json', 'openssl', 'curl', 'gd', 'fileinfo'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "✅ $ext: Loaded<br>";
    } else {
        echo "❌ $ext: Not loaded<br>";
    }
}

// Check MySQL connection
echo "<h2>🗄️ Database Connection</h2>";
try {
    $pdo = new PDO("mysql:host=localhost", "root", "");
    echo "✅ MySQL Connection: Successful<br>";
    
    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE 'farmer_marketplace'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Database 'farmer_marketplace': Exists<br>";
        
        // Check tables
        $pdo->exec("USE farmer_marketplace");
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "✅ Tables found: " . count($tables) . "<br>";
        if (count($tables) > 0) {
            echo "&nbsp;&nbsp;&nbsp;→ " . implode(", ", array_slice($tables, 0, 5));
            if (count($tables) > 5) echo " and " . (count($tables) - 5) . " more...";
            echo "<br>";
        }
    } else {
        echo "⚠️ Database 'farmer_marketplace': Not found (run setup_local.php first)<br>";
    }
} catch (PDOException $e) {
    echo "❌ MySQL Connection: Failed<br>";
    echo "&nbsp;&nbsp;&nbsp;→ Error: " . $e->getMessage() . "<br>";
}

// Check file permissions
echo "<h2>📁 Directory Permissions</h2>";
$directories = ['uploads', 'config', 'css', 'js', 'api'];
foreach ($directories as $dir) {
    if (file_exists($dir)) {
        if (is_writable($dir)) {
            echo "✅ $dir: Writable<br>";
        } else {
            echo "⚠️ $dir: Not writable<br>";
        }
    } else {
        echo "❌ $dir: Does not exist<br>";
    }
}

// Check important files
echo "<h2>📄 Important Files</h2>";
$files = [
    'config/database.php' => 'Database configuration',
    'config/schema.sql' => 'Main database schema',
    'index.php' => 'Main landing page',
    'farmer/register.php' => 'Farmer registration',
    'buyer/register.php' => 'Buyer registration'
];

foreach ($files as $file => $description) {
    if (file_exists($file)) {
        echo "✅ $file: $description<br>";
    } else {
        echo "❌ $file: Missing ($description)<br>";
    }
}

// Check URL access
echo "<h2>🌐 URL Testing</h2>";
$base_url = "http://localhost/farmer-marketplace/";
echo "✅ Base URL: <a href='$base_url' target='_blank'>$base_url</a><br>";

// Quick setup buttons
echo "<h2>⚡ Quick Actions</h2>";
echo "<div style='margin: 20px 0;'>";
echo "<a href='setup_local.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px;'>🚀 Run Setup</a> ";
echo "<a href='http://localhost/phpmyadmin/' target='_blank' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px;'>🗄️ phpMyAdmin</a> ";
echo "<a href='index.php' target='_blank' style='background: #17a2b8; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px;'>🏠 Main Site</a>";
echo "</div>";

// Troubleshooting tips
echo "<h2>🔍 Troubleshooting</h2>";
echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>";
echo "<strong>If you see any red ❌ indicators above:</strong><br><br>";
echo "1. <strong>MySQL Connection Failed:</strong><br>";
echo "&nbsp;&nbsp;• Start XAMPP Control Panel<br>";
echo "&nbsp;&nbsp;• Click 'Start' next to Apache and MySQL<br>";
echo "&nbsp;&nbsp;• Wait for green status indicators<br><br>";

echo "2. <strong>Database Not Found:</strong><br>";
echo "&nbsp;&nbsp;• Click the '🚀 Run Setup' button above<br>";
echo "&nbsp;&nbsp;• Or manually run: <code>http://localhost/farmer-marketplace/setup_local.php</code><br><br>";

echo "3. <strong>Permission Issues:</strong><br>";
echo "&nbsp;&nbsp;• Run XAMPP as Administrator<br>";
echo "&nbsp;&nbsp;• Check folder permissions in Windows<br><br>";

echo "4. <strong>PHP Extensions Missing:</strong><br>";
echo "&nbsp;&nbsp;• Check php.ini file in XAMPP\\php\\<br>";
echo "&nbsp;&nbsp;• Uncomment required extensions<br>";
echo "&nbsp;&nbsp;• Restart Apache<br>";
echo "</div>";

?>

<style>
body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
h1 { color: #2d5a27; }
h2 { color: #5d4e75; border-bottom: 2px solid #eee; padding-bottom: 10px; }
code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; }
a { color: #2a9d8f; }
</style>
