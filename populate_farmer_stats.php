<?php
require_once 'config/database.php';

echo "<h1>🌾 Populate Farmer Stats</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .info { color: blue; }
</style>";

try {
    $pdo = getDB();
    
    // Get all farmers from users table
    $farmers_stmt = $pdo->query("SELECT id, username FROM users WHERE user_type = 'farmer'");
    $farmers = $farmers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p class='info'>Found " . count($farmers) . " farmers to process</p>";
    
    foreach ($farmers as $farmer) {
        // Check if farmer already exists in farmer_stats
        $check_stmt = $pdo->prepare("SELECT id FROM farmer_stats WHERE farmer_id = ?");
        $check_stmt->execute([$farmer['id']]);
        
        if ($check_stmt->rowCount() == 0) {
            // Insert new farmer stats record
            $insert_stmt = $pdo->prepare("
                INSERT INTO farmer_stats 
                (farmer_id, total_products, total_orders, rating, reviews_count, specialization, verified_status) 
                VALUES (?, 0, 0, 4.2, 5, 'Organic Vegetables, Grains', 'verified')
            ");
            
            if ($insert_stmt->execute([$farmer['id']])) {
                echo "<p class='success'>✅ Added stats for farmer: {$farmer['username']}</p>";
            } else {
                echo "<p class='error'>❌ Failed to add stats for farmer: {$farmer['username']}</p>";
            }
        } else {
            echo "<p class='info'>ℹ️ Stats already exist for farmer: {$farmer['username']}</p>";
        }
    }
    
    // Update full_name for farmers who don't have it set
    $update_stmt = $pdo->prepare("
        UPDATE users 
        SET full_name = CONCAT(UPPER(SUBSTRING(username, 1, 1)), SUBSTRING(username, 2), ' Singh')
        WHERE user_type = 'farmer' AND (full_name IS NULL OR full_name = '')
    ");
    
    if ($update_stmt->execute()) {
        $affected = $update_stmt->rowCount();
        if ($affected > 0) {
            echo "<p class='success'>✅ Updated full_name for $affected farmers</p>";
        } else {
            echo "<p class='info'>ℹ️ All farmers already have full_name set</p>";
        }
    }
    
    // Show final stats
    echo "<h2>📊 Final Statistics</h2>";
    
    $stats_count = $pdo->query("SELECT COUNT(*) as count FROM farmer_stats")->fetch()['count'];
    $farmers_count = $pdo->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'farmer'")->fetch()['count'];
    
    echo "<p><strong>Farmers in users table:</strong> $farmers_count</p>";
    echo "<p><strong>Records in farmer_stats:</strong> $stats_count</p>";
    
    if ($stats_count == $farmers_count) {
        echo "<p class='success'>✅ All farmers have stats records!</p>";
        echo "<p><a href='buyer/suppliers.php'>🔗 Test Suppliers Page</a></p>";
    } else {
        echo "<p class='error'>❌ Mismatch between farmers and stats records</p>";
    }
    
} catch (PDOException $e) {
    echo "<p class='error'>❌ Database error: " . $e->getMessage() . "</p>";
}

echo "<hr><p><a href='debug_farmer_selection.php'>🔧 Debug Tool</a> | <a href='index.php'>🏠 Home</a></p>";
?>
