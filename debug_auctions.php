<?php
session_start();
require_once 'config/database.php';

$conn = getDB();

echo "<h1>Auction System Debug</h1>";

// Check auctions table structure
echo "<h2>1. Auctions Table Structure</h2>";
try {
    $stmt = $conn->prepare("DESCRIBE auctions");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
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
} catch (Exception $e) {
    echo "<p style='color: red;'>Error checking table structure: " . $e->getMessage() . "</p>";
}

// Check all auctions
echo "<h2>2. All Auctions in Database</h2>";
try {
    $stmt = $conn->prepare("
        SELECT a.*, p.title, c.name as crop_name, f.farm_name, u.username as farmer_username,
               CASE WHEN a.end_time > NOW() THEN 'Active' ELSE 'Expired' END as time_status
        FROM auctions a
        LEFT JOIN product_listings p ON a.product_id = p.id
        LEFT JOIN crops c ON p.crop_id = c.id
        LEFT JOIN farmers f ON p.farmer_id = f.id
        LEFT JOIN users u ON f.user_id = u.id
        ORDER BY a.created_at DESC
    ");
    $stmt->execute();
    $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($auctions)) {
        echo "<p>No auctions found in the database.</p>";
    } else {
        echo "<table border='1' style='width: 100%; border-collapse: collapse;'>";
        echo "<tr>";
        echo "<th>ID</th><th>Product Title</th><th>Farmer</th><th>Start Price</th>";
        echo "<th>Current Bid</th><th>Status</th><th>End Time</th><th>Time Status</th><th>Created</th>";
        echo "</tr>";
        
        foreach ($auctions as $auction) {
            echo "<tr>";
            echo "<td>{$auction['id']}</td>";
            echo "<td>" . ($auction['title'] ?? 'Product Not Found') . "</td>";
            echo "<td>" . ($auction['farmer_username'] ?? 'Farmer Not Found') . "</td>";
            echo "<td>₹" . number_format($auction['start_price'], 2) . "</td>";
            echo "<td>₹" . number_format($auction['current_bid'], 2) . "</td>";
            echo "<td>{$auction['status']}</td>";
            echo "<td>{$auction['end_time']}</td>";
            echo "<td>{$auction['time_status']}</td>";
            echo "<td>{$auction['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error fetching auctions: " . $e->getMessage() . "</p>";
}

// Check what buyer sees
echo "<h2>3. Active Auctions (Buyer View)</h2>";
try {
    $stmt = $conn->prepare("
        SELECT a.*, p.title, c.name as crop_name, f.farm_name, u.username as farmer_username
        FROM auctions a
        JOIN product_listings p ON a.product_id = p.id
        JOIN crops c ON p.crop_id = c.id
        JOIN farmers f ON p.farmer_id = f.id
        JOIN users u ON f.user_id = u.id
        WHERE a.end_time > NOW() AND a.status = 'active'
        ORDER BY a.end_time DESC
    ");
    $stmt->execute();
    $active_auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($active_auctions)) {
        echo "<p style='color: orange;'>No active auctions visible to buyers.</p>";
    } else {
        echo "<p style='color: green;'>" . count($active_auctions) . " active auctions found.</p>";
        echo "<table border='1' style='width: 100%; border-collapse: collapse;'>";
        echo "<tr>";
        echo "<th>ID</th><th>Product Title</th><th>Crop</th><th>Farmer</th>";
        echo "<th>Current Bid</th><th>End Time</th>";
        echo "</tr>";
        
        foreach ($active_auctions as $auction) {
            echo "<tr>";
            echo "<td>{$auction['id']}</td>";
            echo "<td>{$auction['title']}</td>";
            echo "<td>{$auction['crop_name']}</td>";
            echo "<td>{$auction['farmer_username']}</td>";
            echo "<td>₹" . number_format($auction['current_bid'], 2) . "</td>";
            echo "<td>{$auction['end_time']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error fetching active auctions: " . $e->getMessage() . "</p>";
}

// Check product listings
echo "<h2>4. Product Listings Status</h2>";
try {
    $stmt = $conn->prepare("
        SELECT p.id, p.title, p.status, f.farm_name, u.username,
               (SELECT COUNT(*) FROM auctions WHERE product_id = p.id) as auction_count
        FROM product_listings p
        JOIN farmers f ON p.farmer_id = f.id
        JOIN users u ON f.user_id = u.id
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='width: 100%; border-collapse: collapse;'>";
    echo "<tr>";
    echo "<th>Product ID</th><th>Title</th><th>Status</th><th>Farm</th><th>Farmer</th><th>Auctions</th>";
    echo "</tr>";
    
    foreach ($products as $product) {
        echo "<tr>";
        echo "<td>{$product['id']}</td>";
        echo "<td>{$product['title']}</td>";
        echo "<td>{$product['status']}</td>";
        echo "<td>{$product['farm_name']}</td>";
        echo "<td>{$product['username']}</td>";
        echo "<td>{$product['auction_count']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error fetching products: " . $e->getMessage() . "</p>";
}

// Check current time
echo "<h2>5. Current Server Time</h2>";
echo "<p>PHP Time: " . date('Y-m-d H:i:s') . "</p>";
try {
    $stmt = $conn->prepare("SELECT NOW() as db_time");
    $stmt->execute();
    $time = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Database Time: " . $time['db_time'] . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error getting database time: " . $e->getMessage() . "</p>";
}

// Quick test auction creation
echo "<h2>6. Test Auction Creation</h2>";
if (isset($_GET['test_create'])) {
    try {
        // Get a test product
        $stmt = $conn->prepare("SELECT id FROM product_listings WHERE status = 'active' LIMIT 1");
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            $end_time = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $stmt = $conn->prepare("INSERT INTO auctions (product_id, start_price, current_bid, end_time, status, created_at) VALUES (?, 100, 100, ?, 'active', NOW())");
            $stmt->execute([$product['id'], $end_time]);
            echo "<p style='color: green;'>Test auction created successfully!</p>";
        } else {
            echo "<p style='color: red;'>No active products found for test auction.</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error creating test auction: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p><a href='?test_create=1'>Create Test Auction</a></p>";
}

?>
