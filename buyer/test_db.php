<?php
// Database connection test script
require_once '../config/database.php';

try {
    $conn = getDB();
    echo "<h2>Database Connection Test</h2>";
    
    if ($conn) {
        echo "<p style='color: green;'>✓ Database connection successful!</p>";
        
        // Test if tables exist
        $tables = ['users', 'buyers', 'farmers', 'product_listings', 'crops', 'crop_categories', 'orders', 'bids', 'auctions'];
        
        echo "<h3>Table Existence Check:</h3>";
        foreach ($tables as $table) {
            try {
                $stmt = $conn->prepare("SELECT 1 FROM $table LIMIT 1");
                $stmt->execute();
                echo "<p style='color: green;'>✓ Table '$table' exists</p>";
            } catch (Exception $e) {
                echo "<p style='color: red;'>✗ Table '$table' missing or inaccessible: " . $e->getMessage() . "</p>";
            }
        }
        
        // Test basic queries
        echo "<h3>Query Tests:</h3>";
        
        // Test users table structure
        try {
            $stmt = $conn->prepare("DESCRIBE users");
            $stmt->execute();
            echo "<p style='color: green;'>✓ Users table structure accessible</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>✗ Users table structure error: " . $e->getMessage() . "</p>";
        }
        
        // Test buyers table structure
        try {
            $stmt = $conn->prepare("DESCRIBE buyers");
            $stmt->execute();
            echo "<p style='color: green;'>✓ Buyers table structure accessible</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>✗ Buyers table structure error: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>✗ Database connection failed</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><strong>Database Configuration:</strong></p>";
echo "<p>Host: " . (defined('DB_HOST') ? DB_HOST : 'localhost') . "</p>";
echo "<p>Database: " . (defined('DB_NAME') ? DB_NAME : 'farmer_marketplace') . "</p>";
echo "<p>User: " . (defined('DB_USER') ? DB_USER : 'root') . "</p>";

echo "<hr>";
echo "<p><a href='dashboard.php'>← Back to Dashboard</a></p>";
?>
