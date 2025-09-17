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

class EscrowAPI {
    private $pdo;
    private $user;
    
    public function __construct($pdo, $user) {
        $this->pdo = $pdo;
        $this->user = $user;
    }
    
    // Get escrow account details for an order
    public function getEscrowAccount($orderId) {
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
            
            // Get escrow account
            $stmt = $this->pdo->prepare("
                SELECT ea.*, o.total_amount as order_amount
                FROM escrow_accounts ea
                JOIN orders o ON ea.order_id = o.id
                WHERE ea.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $escrow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$escrow) {
                return ['error' => 'Escrow account not found'];
            }
            
            // Get escrow transactions
            $stmt = $this->pdo->prepare("
                SELECT et.*, pt.gateway_transaction_id, pt.gateway_reference
                FROM escrow_transactions et
                LEFT JOIN payment_transactions pt ON et.payment_transaction_id = pt.id
                WHERE et.escrow_account_id = ?
                ORDER BY et.created_at DESC
            ");
            $stmt->execute([$escrow['id']]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'escrow' => $escrow,
                'transactions' => $transactions
            ];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch escrow account: ' . $e->getMessage()];
        }
    }
    
    // Deposit funds to escrow (buyer action)
    public function depositFunds($data) {
        try {
            $orderId = $data['order_id'];
            $amount = $data['amount'];
            $paymentMethod = $data['payment_method'];
            $gatewayTransactionId = $data['gateway_transaction_id'] ?? null;
            $gatewayReference = $data['gateway_reference'] ?? null;
            
            // Verify buyer owns this order
            $stmt = $this->pdo->prepare("
                SELECT id, total_amount FROM orders WHERE id = ? AND buyer_id = ?
            ");
            $stmt->execute([$orderId, $this->user['id']]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                return ['error' => 'Order not found or access denied'];
            }
            
            // Check if amount matches order total
            if ($amount != $order['total_amount']) {
                return ['error' => 'Deposit amount must match order total'];
            }
            
            // Get escrow account
            $stmt = $this->pdo->prepare("SELECT id FROM escrow_accounts WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $escrow = $stmt->fetch();
            
            if (!$escrow) {
                return ['error' => 'Escrow account not found'];
            }
            
            $this->pdo->beginTransaction();
            
            try {
                // Create payment transaction record
                $stmt = $this->pdo->prepare("
                    INSERT INTO payment_transactions (
                        order_id, user_id, amount, payment_method, transaction_type,
                        status, gateway_transaction_id, gateway_reference, created_at
                    ) VALUES (?, ?, ?, ?, 'deposit', 'completed', ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $orderId, $this->user['id'], $amount, $paymentMethod,
                    $gatewayTransactionId, $gatewayReference
                ]);
                
                $paymentTransactionId = $this->pdo->lastInsertId();
                
                // Create escrow transaction
                $stmt = $this->pdo->prepare("
                    INSERT INTO escrow_transactions (
                        escrow_account_id, transaction_type, amount, description,
                        payment_transaction_id, created_by
                    ) VALUES (?, 'deposit', ?, 'Initial deposit by buyer', ?, ?)
                ");
                
                $stmt->execute([
                    $escrow['id'], $amount, $paymentTransactionId, $this->user['id']
                ]);
                
                // Update escrow balance (this will be handled by trigger, but we can do it manually too)
                $stmt = $this->pdo->prepare("
                    UPDATE escrow_accounts 
                    SET balance = balance + ?, status = 'funded', updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$amount, $escrow['id']]);
                
                $this->pdo->commit();
                
                // Send notification to farmer
                $this->sendEscrowNotification($orderId, 'funds_deposited', $amount);
                
                return [
                    'success' => true,
                    'message' => 'Funds deposited to escrow successfully',
                    'payment_transaction_id' => $paymentTransactionId
                ];
                
            } catch (Exception $e) {
                $this->pdo->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            return ['error' => 'Failed to deposit funds: ' . $e->getMessage()];
        }
    }
    
    // Release funds from escrow (triggered by delivery confirmation or admin)
    public function releaseFunds($data) {
        try {
            $orderId = $data['order_id'];
            $amount = $data['amount'] ?? null;
            $reason = $data['reason'] ?? 'Order completed';
            
            // Check permission - buyer can release, admin can release, or automatic trigger
            $hasPermission = false;
            
            if ($this->user['role'] === 'admin') {
                $hasPermission = true;
            } else {
                // Check if user is buyer of this order
                $stmt = $this->pdo->prepare("SELECT id FROM orders WHERE id = ? AND buyer_id = ?");
                $stmt->execute([$orderId, $this->user['id']]);
                if ($stmt->fetch()) {
                    $hasPermission = true;
                }
            }
            
            if (!$hasPermission) {
                return ['error' => 'Permission denied'];
            }
            
            // Get escrow account and order details
            $stmt = $this->pdo->prepare("
                SELECT ea.*, o.farmer_id, o.total_amount
                FROM escrow_accounts ea
                JOIN orders o ON ea.order_id = o.id
                WHERE ea.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $escrow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$escrow) {
                return ['error' => 'Escrow account not found'];
            }
            
            if ($escrow['status'] !== 'funded') {
                return ['error' => 'Escrow account is not funded'];
            }
            
            // If amount not specified, release full balance
            $releaseAmount = $amount ?? $escrow['balance'];
            
            if ($releaseAmount > $escrow['balance']) {
                return ['error' => 'Insufficient escrow balance'];
            }
            
            $this->pdo->beginTransaction();
            
            try {
                // Create payment transaction for farmer
                $stmt = $this->pdo->prepare("
                    INSERT INTO payment_transactions (
                        order_id, user_id, amount, payment_method, transaction_type,
                        status, gateway_reference, created_at
                    ) VALUES (?, ?, ?, 'escrow_release', 'payout', 'completed', ?, NOW())
                ");
                
                $gatewayRef = 'ESC_REL_' . $orderId . '_' . time();
                $stmt->execute([$orderId, $escrow['farmer_id'], $releaseAmount, $gatewayRef]);
                $paymentTransactionId = $this->pdo->lastInsertId();
                
                // Create escrow transaction
                $stmt = $this->pdo->prepare("
                    INSERT INTO escrow_transactions (
                        escrow_account_id, transaction_type, amount, description,
                        payment_transaction_id, created_by
                    ) VALUES (?, 'release', ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $escrow['id'], $releaseAmount, $reason, $paymentTransactionId, $this->user['id']
                ]);
                
                // Update escrow balance
                $stmt = $this->pdo->prepare("
                    UPDATE escrow_accounts 
                    SET balance = balance - ?, 
                        status = CASE WHEN balance - ? <= 0 THEN 'released' ELSE 'partially_released' END,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$releaseAmount, $releaseAmount, $escrow['id']]);
                
                $this->pdo->commit();
                
                // Send notifications
                $this->sendEscrowNotification($orderId, 'funds_released', $releaseAmount);
                
                return [
                    'success' => true,
                    'message' => 'Funds released successfully',
                    'released_amount' => $releaseAmount
                ];
                
            } catch (Exception $e) {
                $this->pdo->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            return ['error' => 'Failed to release funds: ' . $e->getMessage()];
        }
    }
    
    // Refund funds from escrow (in case of cancellation or dispute)
    public function refundFunds($data) {
        try {
            $orderId = $data['order_id'];
            $amount = $data['amount'] ?? null;
            $reason = $data['reason'] ?? 'Order cancelled';
            
            // Only admin or system can initiate refunds
            if ($this->user['role'] !== 'admin') {
                return ['error' => 'Only administrators can process refunds'];
            }
            
            // Get escrow account and order details
            $stmt = $this->pdo->prepare("
                SELECT ea.*, o.buyer_id
                FROM escrow_accounts ea
                JOIN orders o ON ea.order_id = o.id
                WHERE ea.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $escrow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$escrow) {
                return ['error' => 'Escrow account not found'];
            }
            
            if ($escrow['balance'] <= 0) {
                return ['error' => 'No funds available for refund'];
            }
            
            // If amount not specified, refund full balance
            $refundAmount = $amount ?? $escrow['balance'];
            
            if ($refundAmount > $escrow['balance']) {
                return ['error' => 'Insufficient escrow balance'];
            }
            
            $this->pdo->beginTransaction();
            
            try {
                // Create payment transaction for refund
                $stmt = $this->pdo->prepare("
                    INSERT INTO payment_transactions (
                        order_id, user_id, amount, payment_method, transaction_type,
                        status, gateway_reference, created_at
                    ) VALUES (?, ?, ?, 'escrow_refund', 'refund', 'completed', ?, NOW())
                ");
                
                $gatewayRef = 'ESC_REF_' . $orderId . '_' . time();
                $stmt->execute([$orderId, $escrow['buyer_id'], $refundAmount, $gatewayRef]);
                $paymentTransactionId = $this->pdo->lastInsertId();
                
                // Create escrow transaction
                $stmt = $this->pdo->prepare("
                    INSERT INTO escrow_transactions (
                        escrow_account_id, transaction_type, amount, description,
                        payment_transaction_id, created_by
                    ) VALUES (?, 'refund', ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $escrow['id'], $refundAmount, $reason, $paymentTransactionId, $this->user['id']
                ]);
                
                // Update escrow balance
                $stmt = $this->pdo->prepare("
                    UPDATE escrow_accounts 
                    SET balance = balance - ?, 
                        status = CASE WHEN balance - ? <= 0 THEN 'refunded' ELSE 'partially_refunded' END,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$refundAmount, $refundAmount, $escrow['id']]);
                
                $this->pdo->commit();
                
                // Send notifications
                $this->sendEscrowNotification($orderId, 'funds_refunded', $refundAmount);
                
                return [
                    'success' => true,
                    'message' => 'Funds refunded successfully',
                    'refunded_amount' => $refundAmount
                ];
                
            } catch (Exception $e) {
                $this->pdo->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            return ['error' => 'Failed to refund funds: ' . $e->getMessage()];
        }
    }
    
    // Get escrow summary for user dashboard
    public function getEscrowSummary($userId = null) {
        try {
            $userId = $userId ?: $this->user['id'];
            
            // Get orders with escrow accounts for this user
            $stmt = $this->pdo->prepare("
                SELECT 
                    o.id as order_id,
                    o.order_number,
                    o.total_amount,
                    ea.balance,
                    ea.status as escrow_status,
                    ea.created_at as escrow_created,
                    CASE WHEN o.buyer_id = ? THEN 'buyer' ELSE 'seller' END as user_role,
                    CASE WHEN o.buyer_id = ? THEN u2.name ELSE u1.name END as other_party
                FROM orders o
                JOIN escrow_accounts ea ON o.id = ea.order_id
                LEFT JOIN users u1 ON o.buyer_id = u1.id
                LEFT JOIN users u2 ON o.farmer_id = u2.id
                WHERE o.buyer_id = ? OR o.farmer_id = ?
                ORDER BY ea.created_at DESC
            ");
            
            $stmt->execute([$userId, $userId, $userId, $userId]);
            $escrows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate summary statistics
            $totalInEscrow = 0;
            $pendingReleases = 0;
            $completedTransactions = 0;
            
            foreach ($escrows as $escrow) {
                if ($escrow['escrow_status'] === 'funded') {
                    $totalInEscrow += $escrow['balance'];
                }
                if ($escrow['escrow_status'] === 'funded' && $escrow['user_role'] === 'seller') {
                    $pendingReleases += $escrow['balance'];
                }
                if ($escrow['escrow_status'] === 'released' || $escrow['escrow_status'] === 'refunded') {
                    $completedTransactions++;
                }
            }
            
            return [
                'escrows' => $escrows,
                'summary' => [
                    'total_in_escrow' => $totalInEscrow,
                    'pending_releases' => $pendingReleases,
                    'completed_transactions' => $completedTransactions,
                    'active_escrows' => count(array_filter($escrows, function($e) {
                        return in_array($e['escrow_status'], ['funded', 'partially_released']);
                    }))
                ]
            ];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch escrow summary: ' . $e->getMessage()];
        }
    }
    
    // Get payment schedule for an order
    public function getPaymentSchedule($orderId) {
        try {
            // Verify access
            $stmt = $this->pdo->prepare("
                SELECT id FROM orders 
                WHERE id = ? AND (buyer_id = ? OR farmer_id = ? OR ? IN (SELECT id FROM users WHERE role = 'admin'))
            ");
            $stmt->execute([$orderId, $this->user['id'], $this->user['id'], $this->user['id']]);
            
            if (!$stmt->fetch()) {
                return ['error' => 'Order not found or access denied'];
            }
            
            $stmt = $this->pdo->prepare("
                SELECT * FROM payment_schedules 
                WHERE order_id = ? 
                ORDER BY milestone_order
            ");
            $stmt->execute([$orderId]);
            $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['schedule' => $schedule];
            
        } catch (Exception $e) {
            return ['error' => 'Failed to fetch payment schedule: ' . $e->getMessage()];
        }
    }
    
    // Process milestone payment
    public function processMilestonePayment($data) {
        try {
            $scheduleId = $data['schedule_id'];
            $notes = $data['notes'] ?? '';
            
            // Get schedule details
            $stmt = $this->pdo->prepare("
                SELECT ps.*, o.buyer_id, o.farmer_id
                FROM payment_schedules ps
                JOIN orders o ON ps.order_id = o.id
                WHERE ps.id = ?
            ");
            $stmt->execute([$scheduleId]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$schedule) {
                return ['error' => 'Payment schedule not found'];
            }
            
            // Check if milestone conditions are met
            if (!$this->checkMilestoneConditions($schedule)) {
                return ['error' => 'Milestone conditions not yet met'];
            }
            
            // Process the payment release
            $result = $this->releaseFunds([
                'order_id' => $schedule['order_id'],
                'amount' => $schedule['amount'],
                'reason' => "Milestone payment: {$schedule['milestone_name']}"
            ]);
            
            if ($result['success']) {
                // Update schedule status
                $stmt = $this->pdo->prepare("
                    UPDATE payment_schedules 
                    SET status = 'paid', paid_date = NOW(), notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$notes, $scheduleId]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            return ['error' => 'Failed to process milestone payment: ' . $e->getMessage()];
        }
    }
    
    private function checkMilestoneConditions($schedule) {
        // Check if the conditions for this milestone are met
        // This would integrate with supply chain tracking
        
        try {
            switch ($schedule['trigger_condition']) {
                case 'order_confirmed':
                    return true; // Always true if we reach here
                    
                case 'shipped':
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM supply_chain_tracking sct
                        JOIN supply_chain_statuses scs ON sct.status_id = scs.id
                        WHERE sct.order_id = ? AND scs.status_code = 'SHIPPED'
                    ");
                    $stmt->execute([$schedule['order_id']]);
                    return $stmt->fetchColumn() > 0;
                    
                case 'delivered':
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM supply_chain_tracking sct
                        JOIN supply_chain_statuses scs ON sct.status_id = scs.id
                        WHERE sct.order_id = ? AND scs.status_code = 'DELIVERED'
                    ");
                    $stmt->execute([$schedule['order_id']]);
                    return $stmt->fetchColumn() > 0;
                    
                case 'quality_approved':
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM quality_inspections
                        WHERE order_id = ? AND result = 'passed'
                    ");
                    $stmt->execute([$schedule['order_id']]);
                    return $stmt->fetchColumn() > 0;
                    
                default:
                    return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function sendEscrowNotification($orderId, $type, $amount = null) {
        try {
            // Get order and user details
            $stmt = $this->pdo->prepare("
                SELECT o.*, b.email as buyer_email, f.email as farmer_email,
                       b.name as buyer_name, f.name as farmer_name
                FROM orders o
                JOIN users b ON o.buyer_id = b.id
                JOIN users f ON o.farmer_id = f.id
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) return;
            
            $messages = [
                'funds_deposited' => "₹{$amount} has been deposited to escrow for order #{$order['order_number']}",
                'funds_released' => "₹{$amount} has been released from escrow for order #{$order['order_number']}",
                'funds_refunded' => "₹{$amount} has been refunded from escrow for order #{$order['order_number']}"
            ];
            
            $message = $messages[$type] ?? "Escrow update for order #{$order['order_number']}";
            
            // Log notification (integrate with actual notification service)
            error_log("Escrow Notification: $message - Buyer: {$order['buyer_email']}, Farmer: {$order['farmer_email']}");
            
        } catch (Exception $e) {
            error_log("Failed to send escrow notification: " . $e->getMessage());
        }
    }
}

// Handle API requests
$api = new EscrowAPI($pdo, $user);

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'account':
                    $orderId = $_GET['order_id'] ?? null;
                    if (!$orderId) {
                        echo json_encode(['error' => 'Order ID required']);
                        break;
                    }
                    echo json_encode($api->getEscrowAccount($orderId));
                    break;
                    
                case 'summary':
                    echo json_encode($api->getEscrowSummary());
                    break;
                    
                case 'schedule':
                    $orderId = $_GET['order_id'] ?? null;
                    if (!$orderId) {
                        echo json_encode(['error' => 'Order ID required']);
                        break;
                    }
                    echo json_encode($api->getPaymentSchedule($orderId));
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
                case 'deposit':
                    echo json_encode($api->depositFunds($input));
                    break;
                    
                case 'release':
                    echo json_encode($api->releaseFunds($input));
                    break;
                    
                case 'refund':
                    echo json_encode($api->refundFunds($input));
                    break;
                    
                case 'milestone_payment':
                    echo json_encode($api->processMilestonePayment($input));
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
