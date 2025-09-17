<?php
require_once '../config/database.php';
require_once '../config/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Check authentication
$auth = new Auth($pdo);
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path_parts = explode('/', trim($path, '/'));

class SupplyChainAPI {
    private $pdo;
    private $user;
    
    public function __construct($pdo, $user) {
        $this->pdo = $pdo;
        $this->user = $user;
    }
    
    // Get supply chain tracking for an order
    public function getOrderTracking($orderId) {
        try {
            // Verify user has access to this order
            $stmt = $this->pdo->prepare("
                SELECT id FROM orders 
                WHERE id = ? AND (buyer_id = ? OR farmer_id = ? OR ? IN (SELECT id FROM users WHERE role = 'admin'))
            ");
            $stmt->execute([$orderId, $this->user['id'], $this->user['id'], $this->user['id']]);
            
            if (!$stmt->fetch()) {
                return ['error' => 'Order not found or access denied'];
            }
            
            // Get tracking details
            $stmt = $this->pdo->prepare("
                SELECT 
                    sct.*,
                    scs.status_name,
                    scs.description,
                    scs.stage_order,
                    u.name as updated_by_name
                FROM supply_chain_tracking sct
                JOIN supply_chain_statuses scs ON sct.status_id = scs.id
                LEFT JOIN users u ON sct.updated_by = u.id
                WHERE sct.order_id = ?
                ORDER BY sct.created_at ASC
            ");
            $stmt->execute([$orderId]);
            $tracking = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get current status
            $stmt = $this->pdo->prepare("
                SELECT * FROM order_supply_chain_status WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
            $currentStatus = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get delivery tracking if exists
            $stmt = $this->pdo->prepare("
                SELECT * FROM delivery_tracking WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'tracking' => $tracking,
                'current_status' => $currentStatus,
                'delivery' => $delivery
            ];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch tracking: ' . $e->getMessage()];
        }
    }
    
    // Update supply chain status
    public function updateStatus($data) {
        try {
            $orderId = $data['order_id'];
            $statusCode = $data['status_code'];
            $location = $data['location'] ?? null;
            $notes = $data['notes'] ?? null;
            $images = $data['images'] ?? null;
            $temperature = $data['temperature'] ?? null;
            $humidity = $data['humidity'] ?? null;
            $vehicleNumber = $data['vehicle_number'] ?? null;
            $driverName = $data['driver_name'] ?? null;
            $driverPhone = $data['driver_phone'] ?? null;
            $latitude = $data['latitude'] ?? null;
            $longitude = $data['longitude'] ?? null;
            
            // Verify user has permission to update
            $stmt = $this->pdo->prepare("
                SELECT id FROM orders 
                WHERE id = ? AND (farmer_id = ? OR ? IN (SELECT id FROM users WHERE role = 'admin'))
            ");
            $stmt->execute([$orderId, $this->user['id'], $this->user['id']]);
            
            if (!$stmt->fetch()) {
                return ['error' => 'Order not found or permission denied'];
            }
            
            // Get status ID
            $stmt = $this->pdo->prepare("SELECT id FROM supply_chain_statuses WHERE status_code = ?");
            $stmt->execute([$statusCode]);
            $status = $stmt->fetch();
            
            if (!$status) {
                return ['error' => 'Invalid status code'];
            }
            
            // Insert tracking record
            $stmt = $this->pdo->prepare("
                INSERT INTO supply_chain_tracking (
                    order_id, status_id, location, latitude, longitude, notes, 
                    actual_date, updated_by, images, temperature, humidity,
                    vehicle_number, driver_name, driver_phone
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $imagesJson = $images ? json_encode($images) : null;
            
            $stmt->execute([
                $orderId, $status['id'], $location, $latitude, $longitude, 
                $notes, $this->user['id'], $imagesJson, $temperature, 
                $humidity, $vehicleNumber, $driverName, $driverPhone
            ]);
            
            // Send notifications based on status
            $this->sendStatusNotification($orderId, $statusCode);
            
            return ['success' => true, 'message' => 'Status updated successfully'];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to update status: ' . $e->getMessage()];
        }
    }
    
    // Get all statuses
    public function getStatuses() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM supply_chain_statuses 
                WHERE is_active = 1 
                ORDER BY stage_order
            ");
            $stmt->execute();
            return ['statuses' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch statuses: ' . $e->getMessage()];
        }
    }
    
    // Get orders with current status for farmer/buyer dashboard
    public function getOrdersList($userId = null, $role = null) {
        try {
            $userId = $userId ?: $this->user['id'];
            $role = $role ?: $this->user['role'];
            
            $whereClause = '';
            $params = [];
            
            if ($role === 'farmer') {
                $whereClause = 'WHERE o.farmer_id = ?';
                $params[] = $userId;
            } elseif ($role === 'buyer') {
                $whereClause = 'WHERE o.buyer_id = ?';
                $params[] = $userId;
            }
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    o.id,
                    o.order_number,
                    o.total_amount,
                    o.created_at,
                    oscs.current_status,
                    oscs.stage_order,
                    oscs.status_date,
                    oscs.location,
                    CASE WHEN o.buyer_id = ? THEN f.name ELSE b.name END as other_party_name,
                    GROUP_CONCAT(CONCAT(oi.quantity, ' ', oi.unit, ' ', c.name) SEPARATOR ', ') as items
                FROM orders o
                LEFT JOIN order_supply_chain_status oscs ON o.id = oscs.order_id
                LEFT JOIN users f ON o.farmer_id = f.id
                LEFT JOIN users b ON o.buyer_id = b.id
                LEFT JOIN order_items oi ON o.id = oi.order_id
                LEFT JOIN crops c ON oi.crop_id = c.id
                $whereClause
                GROUP BY o.id
                ORDER BY o.created_at DESC
            ");
            
            array_unshift($params, $userId);
            $stmt->execute($params);
            
            return ['orders' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch orders: ' . $e->getMessage()];
        }
    }
    
    // Create delivery tracking
    public function createDeliveryTracking($data) {
        try {
            $orderId = $data['order_id'];
            
            // Verify access
            $stmt = $this->pdo->prepare("
                SELECT id FROM orders 
                WHERE id = ? AND (farmer_id = ? OR ? IN (SELECT id FROM users WHERE role = 'admin'))
            ");
            $stmt->execute([$orderId, $this->user['id'], $this->user['id']]);
            
            if (!$stmt->fetch()) {
                return ['error' => 'Order not found or permission denied'];
            }
            
            // Generate tracking number
            $trackingNumber = 'TRK' . date('Ymd') . rand(1000, 9999);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO delivery_tracking (
                    order_id, tracking_number, courier_service, 
                    expected_delivery_date, delivery_address, 
                    delivery_instructions, recipient_name, recipient_phone
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $orderId,
                $trackingNumber,
                $data['courier_service'] ?? null,
                $data['expected_delivery_date'] ?? null,
                $data['delivery_address'] ?? null,
                $data['delivery_instructions'] ?? null,
                $data['recipient_name'] ?? null,
                $data['recipient_phone'] ?? null
            ]);
            
            return [
                'success' => true, 
                'tracking_number' => $trackingNumber,
                'message' => 'Delivery tracking created successfully'
            ];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to create delivery tracking: ' . $e->getMessage()];
        }
    }
    
    // Update delivery status
    public function updateDeliveryStatus($data) {
        try {
            $trackingNumber = $data['tracking_number'];
            $status = $data['delivery_status'];
            $notes = $data['delivery_notes'] ?? null;
            $proof = $data['delivery_proof'] ?? null;
            
            $stmt = $this->pdo->prepare("
                UPDATE delivery_tracking SET 
                    delivery_status = ?,
                    delivery_notes = ?,
                    delivery_proof = ?,
                    actual_delivery_date = CASE WHEN ? = 'delivered' THEN NOW() ELSE actual_delivery_date END,
                    updated_at = NOW()
                WHERE tracking_number = ?
            ");
            
            $proofJson = $proof ? json_encode($proof) : null;
            $stmt->execute([$status, $notes, $proofJson, $status, $trackingNumber]);
            
            if ($stmt->rowCount() === 0) {
                return ['error' => 'Tracking number not found'];
            }
            
            // If delivered, update supply chain status
            if ($status === 'delivered') {
                $stmt = $this->pdo->prepare("
                    SELECT order_id FROM delivery_tracking WHERE tracking_number = ?
                ");
                $stmt->execute([$trackingNumber]);
                $delivery = $stmt->fetch();
                
                if ($delivery) {
                    $this->updateStatus([
                        'order_id' => $delivery['order_id'],
                        'status_code' => 'DELIVERED',
                        'notes' => 'Package delivered by courier'
                    ]);
                }
            }
            
            return ['success' => true, 'message' => 'Delivery status updated successfully'];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to update delivery status: ' . $e->getMessage()];
        }
    }
    
    // Cold chain monitoring
    public function logColdChain($data) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO cold_chain_logs (
                    order_id, sensor_id, temperature, humidity, location,
                    latitude, longitude, recorded_at, alert_triggered, alert_type
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['order_id'],
                $data['sensor_id'] ?? null,
                $data['temperature'],
                $data['humidity'] ?? null,
                $data['location'] ?? null,
                $data['latitude'] ?? null,
                $data['longitude'] ?? null,
                $data['recorded_at'] ?? date('Y-m-d H:i:s'),
                $data['alert_triggered'] ?? false,
                $data['alert_type'] ?? null
            ]);
            
            return ['success' => true, 'message' => 'Cold chain data logged'];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to log cold chain data: ' . $e->getMessage()];
        }
    }
    
    // Get cold chain data for order
    public function getColdChainData($orderId, $hours = 24) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM cold_chain_logs 
                WHERE order_id = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY recorded_at DESC
            ");
            $stmt->execute([$orderId, $hours]);
            
            return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch cold chain data: ' . $e->getMessage()];
        }
    }
    
    private function sendStatusNotification($orderId, $statusCode) {
        // Implementation for sending notifications (SMS, email, push)
        // This would integrate with notification services
        
        try {
            // Get order details
            $stmt = $this->pdo->prepare("
                SELECT o.*, b.email as buyer_email, f.email as farmer_email,
                       b.phone as buyer_phone, f.phone as farmer_phone
                FROM orders o
                JOIN users b ON o.buyer_id = b.id
                JOIN users f ON o.farmer_id = f.id
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) return;
            
            // Get status details
            $stmt = $this->pdo->prepare("
                SELECT status_name FROM supply_chain_statuses WHERE status_code = ?
            ");
            $stmt->execute([$statusCode]);
            $status = $stmt->fetch();
            
            // Send notification to both buyer and farmer
            $message = "Order #{$order['order_number']} status updated to: {$status['status_name']}";
            
            // Here you would integrate with actual notification services
            // For now, we'll just log the notification
            error_log("Notification: $message - Buyer: {$order['buyer_email']}, Farmer: {$order['farmer_email']}");
            
        } catch (Exception $e) {
            error_log("Failed to send notification: " . $e->getMessage());
        }
    }
}

// Handle API requests
$api = new SupplyChainAPI($pdo, $user);

switch ($method) {
    case 'GET':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'tracking':
                    $orderId = $_GET['order_id'] ?? null;
                    if (!$orderId) {
                        echo json_encode(['error' => 'Order ID required']);
                        break;
                    }
                    echo json_encode($api->getOrderTracking($orderId));
                    break;
                    
                case 'statuses':
                    echo json_encode($api->getStatuses());
                    break;
                    
                case 'orders':
                    echo json_encode($api->getOrdersList());
                    break;
                    
                case 'cold_chain':
                    $orderId = $_GET['order_id'] ?? null;
                    $hours = $_GET['hours'] ?? 24;
                    if (!$orderId) {
                        echo json_encode(['error' => 'Order ID required']);
                        break;
                    }
                    echo json_encode($api->getColdChainData($orderId, $hours));
                    break;
                    
                default:
                    echo json_encode(['error' => 'Invalid action']);
            }
        } else {
            echo json_encode(['error' => 'Action required']);
        }
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (isset($input['action'])) {
            switch ($input['action']) {
                case 'update_status':
                    echo json_encode($api->updateStatus($input));
                    break;
                    
                case 'create_delivery':
                    echo json_encode($api->createDeliveryTracking($input));
                    break;
                    
                case 'update_delivery':
                    echo json_encode($api->updateDeliveryStatus($input));
                    break;
                    
                case 'log_cold_chain':
                    echo json_encode($api->logColdChain($input));
                    break;
                    
                default:
                    echo json_encode(['error' => 'Invalid action']);
            }
        } else {
            echo json_encode(['error' => 'Action required']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
?>
