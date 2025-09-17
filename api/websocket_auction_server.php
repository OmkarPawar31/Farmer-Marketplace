<?php
/**
 * WebSocket Server for Real-time Auction Updates
 * Handles live bidding updates, countdown timers, and auction notifications
 */

require_once '../vendor/autoload.php';
require_once '../config/database.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class AuctionWebSocketServer implements MessageComponentInterface {
    protected $clients;
    protected $auctions;
    protected $conn;
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->auctions = [];
        
        // Database connection
        $database = new Database();
        $this->conn = $database->getConnection();
        
        // Start background processes
        $this->startAuctionMonitoring();
        $this->startAutoBidProcessor();
    }
    
    public function onOpen(ConnectionInterface $connection) {
        $this->clients->attach($connection);
        $connection->auctions = [];
        $connection->user_id = null;
        $connection->session_id = null;
        
        echo "New connection! ({$connection->resourceId})\n";
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['type'])) {
            $this->sendError($from, 'Invalid message format');
            return;
        }
        
        switch ($data['type']) {
            case 'authenticate':
                $this->handleAuthentication($from, $data);
                break;
                
            case 'join_auction':
                $this->handleJoinAuction($from, $data);
                break;
                
            case 'leave_auction':
                $this->handleLeaveAuction($from, $data);
                break;
                
            case 'place_bid':
                $this->handlePlaceBid($from, $data);
                break;
                
            case 'heartbeat':
                $this->handleHeartbeat($from, $data);
                break;
                
            case 'get_auction_status':
                $this->handleGetAuctionStatus($from, $data);
                break;
                
            case 'enable_notifications':
                $this->handleEnableNotifications($from, $data);
                break;
                
            default:
                $this->sendError($from, 'Unknown message type');
        }
    }
    
    public function onClose(ConnectionInterface $connection) {
        // Remove from all auctions
        foreach ($connection->auctions as $auction_id) {
            $this->removeFromAuction($connection, $auction_id);
        }
        
        $this->clients->detach($connection);
        echo "Connection {$connection->resourceId} has disconnected\n";
    }
    
    public function onError(ConnectionInterface $connection, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $connection->close();
    }
    
    private function handleAuthentication(ConnectionInterface $connection, $data) {
        $session_id = $data['session_id'] ?? null;
        $user_id = $data['user_id'] ?? null;
        
        if (!$session_id || !$user_id) {
            $this->sendError($connection, 'Session ID and User ID required');
            return;
        }
        
        // Validate session
        $query = "SELECT * FROM auction_sessions WHERE session_id = ? AND user_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$session_id, $user_id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$session) {
            $this->sendError($connection, 'Invalid session');
            return;
        }
        
        $connection->user_id = $user_id;
        $connection->session_id = $session_id;
        
        // Update session as active
        $update_query = "UPDATE auction_sessions SET is_active = 1, last_activity = CURRENT_TIMESTAMP WHERE session_id = ?";
        $update_stmt = $this->conn->prepare($update_query);
        $update_stmt->execute([$session_id]);
        
        $this->sendSuccess($connection, 'authenticated', ['user_id' => $user_id]);
    }
    
    private function handleJoinAuction(ConnectionInterface $connection, $data) {
        if (!$connection->user_id) {
            $this->sendError($connection, 'Authentication required');
            return;
        }
        
        $auction_id = $data['auction_id'] ?? null;
        if (!$auction_id) {
            $this->sendError($connection, 'Auction ID required');
            return;
        }
        
        // Validate auction exists and is active
        $query = "SELECT * FROM auctions WHERE id = ? AND status = 'active' AND end_time > NOW()";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$auction_id]);
        $auction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$auction) {
            $this->sendError($connection, 'Auction not found or ended');
            return;
        }
        
        // Add to auction
        if (!isset($this->auctions[$auction_id])) {
            $this->auctions[$auction_id] = [];
        }
        
        $this->auctions[$auction_id][$connection->resourceId] = $connection;
        $connection->auctions[] = $auction_id;
        
        // Record participation
        $this->recordAuctionParticipation($auction_id, $connection->user_id);
        
        // Send current auction status
        $this->sendAuctionStatus($connection, $auction_id);
        
        // Notify others about new participant
        $this->broadcastToAuction($auction_id, [
            'type' => 'user_joined',
            'user_id' => $connection->user_id,
            'participant_count' => count($this->auctions[$auction_id])
        ], $connection);
        
        echo "User {$connection->user_id} joined auction {$auction_id}\n";
    }
    
    private function handleLeaveAuction(ConnectionInterface $connection, $data) {
        $auction_id = $data['auction_id'] ?? null;
        if (!$auction_id) {
            $this->sendError($connection, 'Auction ID required');
            return;
        }
        
        $this->removeFromAuction($connection, $auction_id);
    }
    
    private function handlePlaceBid(ConnectionInterface $connection, $data) {
        if (!$connection->user_id) {
            $this->sendError($connection, 'Authentication required');
            return;
        }
        
        $auction_id = $data['auction_id'] ?? null;
        $bid_amount = $data['bid_amount'] ?? null;
        $quantity = $data['quantity'] ?? 1;
        
        if (!$auction_id || !$bid_amount) {
            $this->sendError($connection, 'Auction ID and bid amount required');
            return;
        }
        
        // Process bid through API (reuse existing logic)
        $bid_result = $this->processBid($auction_id, $connection->user_id, $bid_amount, $quantity);
        
        if ($bid_result['success']) {
            // Broadcast bid update to all auction participants
            $this->broadcastBidUpdate($auction_id, $bid_result['data']);
            
            // Send confirmation to bidder
            $this->sendSuccess($connection, 'bid_placed', $bid_result['data']);
            
            // Process auto-bids
            $this->processAutoBidsForAuction($auction_id, $bid_amount, $connection->user_id);
            
        } else {
            $this->sendError($connection, $bid_result['message']);
        }
    }
    
    private function handleHeartbeat(ConnectionInterface $connection, $data) {
        if (!$connection->session_id) {
            return;
        }
        
        // Update session activity
        $query = "UPDATE auction_sessions SET last_activity = CURRENT_TIMESTAMP WHERE session_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$connection->session_id]);
        
        $this->sendSuccess($connection, 'heartbeat_ack', ['timestamp' => time()]);
    }
    
    private function handleGetAuctionStatus(ConnectionInterface $connection, $data) {
        $auction_id = $data['auction_id'] ?? null;
        if (!$auction_id) {
            $this->sendError($connection, 'Auction ID required');
            return;
        }
        
        $this->sendAuctionStatus($connection, $auction_id);
    }
    
    private function sendAuctionStatus(ConnectionInterface $connection, $auction_id) {
        $query = "
            SELECT 
                a.*,
                pl.title as product_title,
                pl.image_url,
                c.name as crop_name,
                f.farm_name,
                TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining,
                (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) as total_bids,
                (SELECT COUNT(DISTINCT b.buyer_id) FROM bids b WHERE b.auction_id = a.id) as unique_bidders
            FROM auctions a
            JOIN product_listings pl ON a.product_id = pl.id
            JOIN crops c ON pl.crop_id = c.id
            JOIN farmers f ON pl.farmer_id = f.id
            WHERE a.id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$auction_id]);
        $auction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($auction) {
            $auction['seconds_remaining'] = max(0, (int)$auction['seconds_remaining']);
            $auction['current_bid'] = (float)$auction['current_bid'];
            $auction['start_price'] = (float)$auction['start_price'];
            $auction['bid_increment'] = (float)$auction['bid_increment'];
            $auction['participant_count'] = isset($this->auctions[$auction_id]) ? count($this->auctions[$auction_id]) : 0;
            
            // Get recent bid activity
            $bid_query = "
                SELECT 
                    b.bid_amount,
                    b.created_at,
                    u.username as bidder_username
                FROM bids b
                JOIN buyers buy ON b.buyer_id = buy.id
                JOIN users u ON buy.user_id = u.id
                WHERE b.auction_id = ?
                ORDER BY b.created_at DESC
                LIMIT 5
            ";
            
            $bid_stmt = $this->conn->prepare($bid_query);
            $bid_stmt->execute([$auction_id]);
            $auction['recent_bids'] = $bid_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendSuccess($connection, 'auction_status', $auction);
        } else {
            $this->sendError($connection, 'Auction not found');
        }
    }
    
    private function processBid($auction_id, $user_id, $bid_amount, $quantity) {
        try {
            // Get buyer ID
            $buyer_query = "SELECT id FROM buyers WHERE user_id = ?";
            $buyer_stmt = $this->conn->prepare($buyer_query);
            $buyer_stmt->execute([$user_id]);
            $buyer_result = $buyer_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$buyer_result) {
                return ['success' => false, 'message' => 'Buyer profile not found'];
            }
            
            $buyer_id = $buyer_result['id'];
            
            // Validate auction
            $auction_query = "SELECT * FROM auctions WHERE id = ? AND status = 'active' AND end_time > NOW()";
            $auction_stmt = $this->conn->prepare($auction_query);
            $auction_stmt->execute([$auction_id]);
            $auction = $auction_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$auction) {
                return ['success' => false, 'message' => 'Auction not found or has ended'];
            }
            
            // Validate bid amount
            $min_bid = max($auction['current_bid'] + $auction['bid_increment'], $auction['start_price']);
            if ($bid_amount < $min_bid) {
                return ['success' => false, 'message' => "Minimum bid is ₹" . number_format($min_bid, 2)];
            }
            
            $this->conn->beginTransaction();
            
            // Insert bid
            $bid_query = "
                INSERT INTO bids (auction_id, buyer_id, bid_amount, quantity, ip_address, user_agent, bid_increment, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ";
            
            $bid_increment = $bid_amount - $auction['current_bid'];
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $bid_stmt = $this->conn->prepare($bid_query);
            $bid_stmt->execute([
                $auction_id, $buyer_id, $bid_amount, $quantity, 
                $ip_address, $user_agent, $bid_increment
            ]);
            
            $bid_id = $this->conn->lastInsertId();
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'data' => [
                    'bid_id' => $bid_id,
                    'auction_id' => $auction_id,
                    'bid_amount' => $bid_amount,
                    'bidder_id' => $user_id,
                    'timestamp' => time()
                ]
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Failed to place bid: ' . $e->getMessage()];
        }
    }
    
    private function broadcastBidUpdate($auction_id, $bid_data) {
        if (!isset($this->auctions[$auction_id])) {
            return;
        }
        
        $message = [
            'type' => 'bid_update',
            'auction_id' => $auction_id,
            'bid_amount' => $bid_data['bid_amount'],
            'bidder_id' => $bid_data['bidder_id'],
            'timestamp' => $bid_data['timestamp']
        ];
        
        $this->broadcastToAuction($auction_id, $message);
    }
    
    private function broadcastToAuction($auction_id, $message, ConnectionInterface $exclude = null) {
        if (!isset($this->auctions[$auction_id])) {
            return;
        }
        
        foreach ($this->auctions[$auction_id] as $connection) {
            if ($exclude && $connection === $exclude) {
                continue;
            }
            
            $connection->send(json_encode($message));
        }
    }
    
    private function removeFromAuction(ConnectionInterface $connection, $auction_id) {
        if (isset($this->auctions[$auction_id][$connection->resourceId])) {
            unset($this->auctions[$auction_id][$connection->resourceId]);
            
            // Remove from connection's auction list
            $key = array_search($auction_id, $connection->auctions);
            if ($key !== false) {
                unset($connection->auctions[$key]);
            }
            
            // Notify others about user leaving
            $this->broadcastToAuction($auction_id, [
                'type' => 'user_left',
                'user_id' => $connection->user_id,
                'participant_count' => count($this->auctions[$auction_id])
            ]);
            
            // Clean up empty auction rooms
            if (empty($this->auctions[$auction_id])) {
                unset($this->auctions[$auction_id]);
            }
        }
    }
    
    private function recordAuctionParticipation($auction_id, $user_id) {
        $query = "
            INSERT INTO auction_participants (auction_id, user_id, joined_at, is_active)
            VALUES (?, ?, NOW(), 1)
            ON DUPLICATE KEY UPDATE
            is_active = 1,
            rejoined_count = rejoined_count + 1
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$auction_id, $user_id]);
    }
    
    private function processAutoBidsForAuction($auction_id, $current_bid, $exclude_user_id) {
        // Get buyer ID for excluded user
        $buyer_query = "SELECT id FROM buyers WHERE user_id = ?";
        $buyer_stmt = $this->conn->prepare($buyer_query);
        $buyer_stmt->execute([$exclude_user_id]);
        $buyer_result = $buyer_stmt->fetch(PDO::FETCH_ASSOC);
        $exclude_buyer_id = $buyer_result['id'] ?? null;
        
        // Get active auto-bids
        $query = "
            SELECT ab.*, b.user_id
            FROM auto_bids ab
            JOIN buyers b ON ab.buyer_id = b.id
            WHERE ab.auction_id = ? 
            AND ab.is_active = 1 
            AND ab.buyer_id != ?
            AND ab.max_bid_amount > ?
            ORDER BY ab.created_at ASC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$auction_id, $exclude_buyer_id, $current_bid]);
        $auto_bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($auto_bids as $auto_bid) {
            $next_bid = $current_bid + $auto_bid['increment_amount'];
            
            if ($next_bid <= $auto_bid['max_bid_amount']) {
                $bid_result = $this->processBid($auction_id, $auto_bid['user_id'], $next_bid, 1);
                
                if ($bid_result['success']) {
                    // Mark as auto-bid
                    $update_query = "UPDATE bids SET auto_bid = 1, message = 'Auto-bid' WHERE id = ?";
                    $update_stmt = $this->conn->prepare($update_query);
                    $update_stmt->execute([$bid_result['data']['bid_id']]);
                    
                    // Broadcast auto-bid update
                    $bid_result['data']['auto_bid'] = true;
                    $this->broadcastBidUpdate($auction_id, $bid_result['data']);
                    
                    $current_bid = $next_bid;
                    
                    // Update auto-bid stats
                    $stats_query = "UPDATE auto_bids SET total_bids_placed = total_bids_placed + 1 WHERE id = ?";
                    $stats_stmt = $this->conn->prepare($stats_query);
                    $stats_stmt->execute([$auto_bid['id']]);
                }
            }
        }
    }
    
    private function startAuctionMonitoring() {
        // Start timer to monitor auction endings and send countdown updates
        $this->conn->exec("SET SESSION sql_mode = ''");
        
        // This would typically be run as a separate process or cron job
        // For demonstration, we'll use a simple timer approach
        register_shutdown_function([$this, 'stopAuctionMonitoring']);
    }
    
    private function startAutoBidProcessor() {
        // Background process for handling auto-bid queues
        // In production, this should be a separate service
    }
    
    public function stopAuctionMonitoring() {
        echo "Auction monitoring stopped\n";
    }
    
    private function sendSuccess(ConnectionInterface $connection, $type, $data = []) {
        $message = [
            'success' => true,
            'type' => $type,
            'data' => $data,
            'timestamp' => time()
        ];
        
        $connection->send(json_encode($message));
    }
    
    private function sendError(ConnectionInterface $connection, $message) {
        $error = [
            'success' => false,
            'type' => 'error',
            'message' => $message,
            'timestamp' => time()
        ];
        
        $connection->send(json_encode($error));
    }
    
    // Periodic tasks (should be run by external scheduler)
    public function checkAuctionEndings() {
        $query = "
            SELECT a.*, COUNT(b.id) as bid_count
            FROM auctions a
            LEFT JOIN bids b ON a.id = b.auction_id
            WHERE a.status = 'active' 
            AND a.end_time <= NOW()
            GROUP BY a.id
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $ending_auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($ending_auctions as $auction) {
            $this->endAuction($auction);
        }
    }
    
    private function endAuction($auction) {
        $auction_id = $auction['id'];
        
        // Get winning bid
        $winning_bid_query = "
            SELECT b.*, buy.user_id
            FROM bids b
            JOIN buyers buy ON b.buyer_id = buy.id
            WHERE b.auction_id = ? AND b.status = 'active'
            ORDER BY b.bid_amount DESC, b.created_at ASC
            LIMIT 1
        ";
        
        $bid_stmt = $this->conn->prepare($winning_bid_query);
        $bid_stmt->execute([$auction_id]);
        $winning_bid = $bid_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update auction status
        if ($winning_bid) {
            $update_query = "UPDATE auctions SET status = 'completed', winner_id = ?, final_price = ? WHERE id = ?";
            $update_stmt = $this->conn->prepare($update_query);
            $update_stmt->execute([$winning_bid['buyer_id'], $winning_bid['bid_amount'], $auction_id]);
            
            // Broadcast auction end with winner
            $this->broadcastToAuction($auction_id, [
                'type' => 'auction_ended',
                'auction_id' => $auction_id,
                'winner_id' => $winning_bid['user_id'],
                'final_price' => $winning_bid['bid_amount'],
                'total_bids' => $auction['bid_count']
            ]);
        } else {
            // No bids - auction failed
            $update_query = "UPDATE auctions SET status = 'failed' WHERE id = ?";
            $update_stmt = $this->conn->prepare($update_query);
            $update_stmt->execute([$auction_id]);
            
            $this->broadcastToAuction($auction_id, [
                'type' => 'auction_ended',
                'auction_id' => $auction_id,
                'status' => 'failed',
                'message' => 'No bids received'
            ]);
        }
        
        // Clean up auction room
        unset($this->auctions[$auction_id]);
    }
    
    public function sendCountdownUpdates() {
        foreach ($this->auctions as $auction_id => $connections) {
            if (empty($connections)) continue;
            
            // Get auction time remaining
            $query = "SELECT TIMESTAMPDIFF(SECOND, NOW(), end_time) as seconds_remaining FROM auctions WHERE id = ? AND status = 'active'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$auction_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $seconds_remaining = max(0, (int)$result['seconds_remaining']);
                
                $this->broadcastToAuction($auction_id, [
                    'type' => 'countdown_update',
                    'auction_id' => $auction_id,
                    'seconds_remaining' => $seconds_remaining
                ]);
                
                // Check for auction ending warnings
                if ($seconds_remaining <= 300 && $seconds_remaining > 290) { // 5 minutes warning
                    $this->broadcastToAuction($auction_id, [
                        'type' => 'auction_warning',
                        'auction_id' => $auction_id,
                        'message' => 'Auction ending in 5 minutes!',
                        'seconds_remaining' => $seconds_remaining
                    ]);
                } elseif ($seconds_remaining <= 60 && $seconds_remaining > 50) { // 1 minute warning
                    $this->broadcastToAuction($auction_id, [
                        'type' => 'auction_warning',
                        'auction_id' => $auction_id,
                        'message' => 'Auction ending in 1 minute!',
                        'seconds_remaining' => $seconds_remaining
                    ]);
                }
            }
        }
    }
}

// Start WebSocket server
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new AuctionWebSocketServer()
        )
    ),
    8080
);

echo "WebSocket auction server started on port 8080\n";
$server->run();
?>
