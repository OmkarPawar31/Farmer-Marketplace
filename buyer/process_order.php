<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in and is a buyer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'buyer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];

try {
    // Get buyer ID
    $stmt = $conn->prepare("SELECT id FROM buyers WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $buyer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$buyer) {
        echo json_encode(['success' => false, 'message' => 'Buyer profile not found']);
        exit();
    }
    
    $buyer_id = $buyer['id'];
    
    // Get form data
    $product_id = intval($_POST['product_id']);
    $quantity = floatval($_POST['quantity']);
    $delivery_address = trim($_POST['delivery_address']);
    
    // Validate input
    if ($product_id <= 0 || $quantity <= 0 || empty($delivery_address)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
        exit();
    }
    
    // Get product details and verify availability
    $stmt = $conn->prepare("
        SELECT p.*, f.id as farmer_id, f.farm_name, u.username as farmer_username
        FROM product_listings p 
        JOIN farmers f ON p.farmer_id = f.id 
        JOIN users u ON f.user_id = u.id
        WHERE p.id = ? AND p.status = 'active'
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found or not available']);
        exit();
    }
    
    // Check if enough quantity is available
    if ($quantity > $product['quantity_available']) {
        echo json_encode(['success' => false, 'message' => 'Requested quantity exceeds available stock (' . $product['quantity_available'] . ' ' . $product['unit'] . ')']);
        exit();
    }
    
    // Check minimum order quantity
    if ($product['minimum_order'] && $quantity < $product['minimum_order']) {
        echo json_encode(['success' => false, 'message' => 'Quantity must be at least ' . $product['minimum_order'] . ' ' . $product['unit']]);
        exit();
    }
    
    // Calculate totals
    $unit_price = floatval($product['price_per_unit']);
    $total_amount = $quantity * $unit_price;
    $commission_rate = 2.5; // 2.5% platform commission
    $commission_amount = ($total_amount * $commission_rate) / 100;
    $farmer_earnings = $total_amount - $commission_amount;
    
    // Generate unique order number
    $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Insert order
        $stmt = $conn->prepare("
            INSERT INTO orders (
                order_number, buyer_id, farmer_id, product_id, quantity, 
                unit_price, total_amount, delivery_address, payment_method, 
                payment_status, order_status, commission_rate, commission_amount, 
                farmer_earnings, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'bank_transfer', 'pending', 'pending', ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $order_number, $buyer_id, $product['farmer_id'], $product_id, $quantity,
            $unit_price, $total_amount, $delivery_address, $commission_rate, 
            $commission_amount, $farmer_earnings
        ]);
        
        $order_id = $conn->lastInsertId();
        
        // Update product quantity
        $new_quantity = $product['quantity_available'] - $quantity;
        $stmt = $conn->prepare("
            UPDATE product_listings 
            SET quantity_available = ?, 
                status = CASE WHEN ? <= 0 THEN 'sold' ELSE status END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_quantity, $new_quantity, $product_id]);
        
        // Update farmer's total orders count
        $stmt = $conn->prepare("
            UPDATE farmers 
            SET total_orders = total_orders + 1 
            WHERE id = ?
        ");
        $stmt->execute([$product['farmer_id']]);
        
        // Update buyer's total orders count  
        $stmt = $conn->prepare("
            UPDATE buyers 
            SET total_orders = total_orders + 1 
            WHERE id = ?
        ");
        $stmt->execute([$buyer_id]);
        
        // Log the order activity (optional)
        $stmt = $conn->prepare("
            INSERT INTO order_activity_log (order_id, activity_type, activity_data, created_at)
            VALUES (?, 'order_placed', ?, NOW())
        ");
        $activity_data = json_encode([
            'buyer_company' => $_SESSION['company_name'],
            'farmer_name' => $product['farm_name'],
            'product_title' => $product['title'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'total_amount' => $total_amount
        ]);
        
        // Try to insert activity log, but don't fail if table doesn't exist
        try {
            $stmt->execute([$order_id, $activity_data]);
        } catch (Exception $e) {
            // Activity log table might not exist, continue without it
        }
        
        $conn->commit();
        
        // Send response
        echo json_encode([
            'success' => true,
            'order_id' => $order_number,
            'message' => 'Order placed successfully',
            'details' => [
                'product' => $product['title'],
                'farmer' => $product['farm_name'],
                'quantity' => $quantity . ' ' . $product['unit'],
                'total_amount' => number_format($total_amount, 2),
                'order_number' => $order_number
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Order processing error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to process order. Please try again.']);
    }
    
} catch (Exception $e) {
    error_log("Order processing general error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing your order.']);
}
?>
