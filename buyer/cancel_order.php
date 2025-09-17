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
    
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    $order_id = intval($input['order_id'] ?? 0);
    
    if ($order_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit();
    }
    
    // Get order details and verify ownership
    $stmt = $conn->prepare("
        SELECT o.*, p.title as product_title, p.quantity_available
        FROM orders o
        JOIN product_listings p ON o.product_id = p.id
        WHERE o.id = ? AND o.buyer_id = ?
    ");
    $stmt->execute([$order_id, $buyer_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }
    
    // Check if order can be cancelled
    if ($order['order_status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Only pending orders can be cancelled']);
        exit();
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Update order status to cancelled
        $stmt = $conn->prepare("
            UPDATE orders 
            SET order_status = 'cancelled', updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$order_id]);
        
        // Restore product quantity
        $stmt = $conn->prepare("
            UPDATE product_listings 
            SET quantity_available = quantity_available + ?, 
                status = CASE WHEN status = 'sold' THEN 'active' ELSE status END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$order['quantity'], $order['product_id']]);
        
        // Update farmer's total orders count (decrement)
        $stmt = $conn->prepare("
            UPDATE farmers 
            SET total_orders = GREATEST(0, total_orders - 1) 
            WHERE id = ?
        ");
        $stmt->execute([$order['farmer_id']]);
        
        // Update buyer's total orders count (decrement)
        $stmt = $conn->prepare("
            UPDATE buyers 
            SET total_orders = GREATEST(0, total_orders - 1) 
            WHERE id = ?
        ");
        $stmt->execute([$buyer_id]);
        
        // Log the cancellation (optional)
        $stmt = $conn->prepare("
            INSERT INTO order_activity_log (order_id, activity_type, activity_data, created_at)
            VALUES (?, 'order_cancelled', ?, NOW())
        ");
        $activity_data = json_encode([
            'cancelled_by' => 'buyer',
            'buyer_company' => $_SESSION['company_name'],
            'product_title' => $order['product_title'],
            'quantity_restored' => $order['quantity'],
            'cancellation_time' => date('Y-m-d H:i:s')
        ]);
        
        // Try to insert activity log, but don't fail if table doesn't exist
        try {
            $stmt->execute([$order_id, $activity_data]);
        } catch (Exception $e) {
            // Activity log table might not exist, continue without it
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'order_number' => $order['order_number']
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Order cancellation error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to cancel order. Please try again.']);
    }
    
} catch (Exception $e) {
    error_log("Order cancellation general error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while cancelling the order.']);
}
?>
