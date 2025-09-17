<?php
session_start();
require_once 'config/database.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Check if user is logged in and is a farmer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'farmer') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit();
}

// Handle POST request only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit();
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    // Validate required fields
    if (!isset($input['product_id']) || !isset($input['quantity'])) {
        throw new Exception('Product ID and quantity are required');
    }
    
    $product_id = (int)$input['product_id'];
    $quantity = (float)$input['quantity'];
    $user_id = $_SESSION['user_id'];
    
    // Validate quantity
    if ($quantity < 0) {
        throw new Exception('Quantity cannot be negative');
    }
    
    $conn = getDB();
    
    // Verify the product belongs to the logged-in farmer
    $stmt = $conn->prepare("
        SELECT pl.id, pl.title, pl.quantity_available, f.user_id 
        FROM product_listings pl 
        JOIN farmers f ON pl.farmer_id = f.id 
        WHERE pl.id = ? AND f.user_id = ?
    ");
    $stmt->execute([$product_id, $user_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        throw new Exception('Product not found or access denied');
    }
    
    // Update the inventory
    $stmt = $conn->prepare("
        UPDATE product_listings 
        SET quantity_available = ?, updated_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $stmt->execute([$quantity, $product_id]);
    
    // Log the inventory change for audit purposes
    $stmt = $conn->prepare("
        INSERT INTO inventory_logs (product_id, previous_quantity, new_quantity, changed_by, change_reason, created_at) 
        VALUES (?, ?, ?, ?, 'Manual update via dashboard', CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$product_id, $product['quantity_available'], $quantity, $user_id]);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Inventory updated successfully',
        'data' => [
            'product_id' => $product_id,
            'previous_quantity' => $product['quantity_available'],
            'new_quantity' => $quantity,
            'updated_at' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}
?>
