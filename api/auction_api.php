<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

require_once '../config/database.php';
session_start();

class AuctionAPI {
    private $conn;
    
    public function __construct() {
        $this->conn = getDB();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $endpoint = $_GET['endpoint'] ?? '';
        
        try {
            switch ($endpoint) {
                case 'get_active_auctions':
                    return $this->getActiveAuctions();
                case 'place_bid':
                    return $this->placeBid();
                case 'get_auction_details':
                    return $this->getAuctionDetails();
                case 'get_bid_history':
                    return $this->getBidHistory();
                case 'get_auction_status':
                    return $this->getAuctionStatus();
                case 'end_auction':
                    return $this->endAuction();
                case 'get_time_remaining':
                    return $this->getTimeRemaining();
                default:
                    return $this->error('Invalid endpoint', 404);
            }
        } catch (Exception $e) {
            return $this->error('Server error: ' . $e->getMessage(), 500);
        }
    }
    
    private function getActiveAuctions() {
        $stmt = $this->conn->prepare("
            SELECT a.*, p.title, p.description, p.price_per_unit, p.quantity_available,
                   c.name as crop_name, f.farm_name, u.username as farmer_username,
                   f.location as farm_location,
                   TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining,
                   (SELECT COUNT(*) FROM bids WHERE auction_id = a.id) as total_bids,
                   (SELECT MAX(bid_amount) FROM bids WHERE auction_id = a.id) as highest_bid,
                   (SELECT bu.company_name FROM bids bi 
                    JOIN buyers bu ON bi.buyer_id = bu.id 
                    WHERE bi.auction_id = a.id AND bi.bid_amount = (SELECT MAX(bid_amount) FROM bids WHERE auction_id = a.id)
                    LIMIT 1) as highest_bidder
            FROM auctions a
            JOIN product_listings p ON a.product_id = p.id
            JOIN crops c ON p.crop_id = c.id
            JOIN farmers f ON p.farmer_id = f.id
            JOIN users u ON f.user_id = u.id
            WHERE a.end_time > NOW() AND a.status = 'active'
            ORDER BY a.end_time ASC
        ");
        $stmt->execute();
        $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format auction data
        foreach ($auctions as &$auction) {
            $auction['current_bid'] = $auction['highest_bid'] ?? $auction['start_price'];
            $auction['time_remaining'] = max(0, $auction['seconds_remaining']);
            $auction['is_ending_soon'] = $auction['seconds_remaining'] <= 300; // 5 minutes
            $auction['formatted_end_time'] = date('Y-m-d H:i:s', strtotime($auction['end_time']));
        }
        
        return $this->success($auctions);
    }
    
    private function placeBid() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'buyer') {
            return $this->error('Unauthorized', 401);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['auction_id']) || !isset($input['bid_amount'])) {
            return $this->error('Missing required fields', 400);
        }
        
        $auction_id = $input['auction_id'];
        $bid_amount = floatval($input['bid_amount']);
        $user_id = $_SESSION['user_id'];
        
        try {
            $this->conn->beginTransaction();
            
            // Get buyer ID
            $stmt = $this->conn->prepare("SELECT id FROM buyers WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $buyer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$buyer) {
                throw new Exception('Buyer profile not found');
            }
            
            $buyer_id = $buyer['id'];
            
            // Check auction validity and get current details
            $stmt = $this->conn->prepare("
                SELECT a.*, 
                       TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining,
                       (SELECT MAX(bid_amount) FROM bids WHERE auction_id = a.id) as current_highest_bid
                FROM auctions a 
                WHERE a.id = ? AND a.status = 'active' AND a.end_time > NOW()
            ");
            $stmt->execute([$auction_id]);
            $auction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$auction) {
                throw new Exception('Auction not found or ended');
            }
            
            $current_bid = $auction['current_highest_bid'] ?? $auction['start_price'];
            
            if ($bid_amount <= $current_bid) {
                throw new Exception('Bid must be higher than current bid of ₹' . number_format($current_bid, 2));
            }
            
            // Auto-extend auction if bid placed in last 2 minutes
            $extend_time = '';
            if ($auction['seconds_remaining'] <= 120) {
                $extend_time = ", end_time = DATE_ADD(end_time, INTERVAL 2 MINUTE)";
            }
            
            // Place the bid
            $stmt = $this->conn->prepare("
                INSERT INTO bids (auction_id, buyer_id, bid_amount, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$auction_id, $buyer_id, $bid_amount]);
            
            // Update auction current bid
            $stmt = $this->conn->prepare("
                UPDATE auctions 
                SET current_bid = ?, updated_at = NOW() $extend_time
                WHERE id = ?
            ");
            $stmt->execute([$bid_amount, $auction_id]);
            
            // Log bid activity
            $stmt = $this->conn->prepare("
                INSERT INTO auction_activity_log (auction_id, buyer_id, activity_type, activity_data, created_at)
                VALUES (?, ?, 'bid_placed', ?, NOW())
            ");
            $activity_data = json_encode([
                'bid_amount' => $bid_amount,
                'previous_bid' => $current_bid,
                'auto_extended' => !empty($extend_time)
            ]);
            $stmt->execute([$auction_id, $buyer_id, $activity_data]);
            
            $this->conn->commit();
            
            // Send real-time notification (would integrate with WebSocket server)
            $this->sendRealTimeUpdate($auction_id, [
                'type' => 'new_bid',
                'auction_id' => $auction_id,
                'bid_amount' => $bid_amount,
                'bidder' => $_SESSION['username'] ?? 'Anonymous',
                'timestamp' => date('Y-m-d H:i:s'),
                'auto_extended' => !empty($extend_time)
            ]);
            
            return $this->success([
                'message' => 'Bid placed successfully',
                'bid_amount' => $bid_amount,
                'auction_id' => $auction_id,
                'auto_extended' => !empty($extend_time)
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return $this->error($e->getMessage(), 400);
        }
    }
    
    private function getAuctionDetails() {
        $auction_id = $_GET['auction_id'] ?? null;
        
        if (!$auction_id) {
            return $this->error('Auction ID required', 400);
        }
        
        $stmt = $this->conn->prepare("
            SELECT a.*, p.title, p.description, p.price_per_unit, p.quantity_available,
                   c.name as crop_name, f.farm_name, u.username as farmer_username,
                   f.location as farm_location, f.contact_phone as farmer_phone,
                   TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining,
                   (SELECT COUNT(*) FROM bids WHERE auction_id = a.id) as total_bids,
                   (SELECT MAX(bid_amount) FROM bids WHERE auction_id = a.id) as highest_bid
            FROM auctions a
            JOIN product_listings p ON a.product_id = p.id
            JOIN crops c ON p.crop_id = c.id
            JOIN farmers f ON p.farmer_id = f.id
            JOIN users u ON f.user_id = u.id
            WHERE a.id = ?
        ");
        $stmt->execute([$auction_id]);
        $auction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$auction) {
            return $this->error('Auction not found', 404);
        }
        
        $auction['current_bid'] = $auction['highest_bid'] ?? $auction['start_price'];
        $auction['time_remaining'] = max(0, $auction['seconds_remaining']);
        $auction['is_ending_soon'] = $auction['seconds_remaining'] <= 300;
        $auction['status_text'] = $auction['end_time'] <= date('Y-m-d H:i:s') ? 'ended' : $auction['status'];
        
        return $this->success($auction);
    }
    
    private function getBidHistory() {
        $auction_id = $_GET['auction_id'] ?? null;
        
        if (!$auction_id) {
            return $this->error('Auction ID required', 400);
        }
        
        $stmt = $this->conn->prepare("
            SELECT b.*, bu.company_name as bidder_name, u.username as bidder_username,
                   TIMESTAMPDIFF(SECOND, b.created_at, NOW()) as seconds_ago
            FROM bids b
            JOIN buyers bu ON b.buyer_id = bu.id
            JOIN users u ON bu.user_id = u.id
            WHERE b.auction_id = ?
            ORDER BY b.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$auction_id]);
        $bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format bid data
        foreach ($bids as &$bid) {
            $bid['time_ago'] = $this->timeAgo($bid['seconds_ago']);
            $bid['formatted_time'] = date('M d, Y H:i:s', strtotime($bid['created_at']));
        }
        
        return $this->success($bids);
    }
    
    private function getAuctionStatus() {
        $auction_ids = $_GET['auction_ids'] ?? '';
        
        if (empty($auction_ids)) {
            return $this->error('Auction IDs required', 400);
        }
        
        $ids = explode(',', $auction_ids);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        $stmt = $this->conn->prepare("
            SELECT a.id, a.status, a.current_bid, a.end_time,
                   TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining,
                   (SELECT COUNT(*) FROM bids WHERE auction_id = a.id) as total_bids,
                   (SELECT MAX(bid_amount) FROM bids WHERE auction_id = a.id) as highest_bid
            FROM auctions a
            WHERE a.id IN ($placeholders)
        ");
        $stmt->execute($ids);
        $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $status_updates = [];
        foreach ($auctions as $auction) {
            $status_updates[$auction['id']] = [
                'status' => $auction['status'],
                'current_bid' => $auction['highest_bid'] ?? $auction['current_bid'],
                'time_remaining' => max(0, $auction['seconds_remaining']),
                'total_bids' => $auction['total_bids'],
                'is_ending_soon' => $auction['seconds_remaining'] <= 300,
                'has_ended' => $auction['seconds_remaining'] <= 0
            ];
        }
        
        return $this->success($status_updates);
    }
    
    private function getTimeRemaining() {
        $auction_id = $_GET['auction_id'] ?? null;
        
        if (!$auction_id) {
            return $this->error('Auction ID required', 400);
        }
        
        $stmt = $this->conn->prepare("
            SELECT TIMESTAMPDIFF(SECOND, NOW(), end_time) as seconds_remaining,
                   end_time, status
            FROM auctions 
            WHERE id = ?
        ");
        $stmt->execute([$auction_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return $this->error('Auction not found', 404);
        }
        
        $seconds_remaining = max(0, $result['seconds_remaining']);
        
        return $this->success([
            'seconds_remaining' => $seconds_remaining,
            'time_remaining' => $this->formatTimeRemaining($seconds_remaining),
            'end_time' => $result['end_time'],
            'status' => $result['status'],
            'has_ended' => $seconds_remaining <= 0,
            'is_ending_soon' => $seconds_remaining <= 300
        ]);
    }
    
    private function endAuction() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'farmer') {
            return $this->error('Unauthorized', 401);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $auction_id = $input['auction_id'] ?? null;
        
        if (!$auction_id) {
            return $this->error('Auction ID required', 400);
        }
        
        try {
            $this->conn->beginTransaction();
            
            // Verify auction ownership
            $stmt = $this->conn->prepare("
                SELECT a.*, f.user_id as farmer_user_id
                FROM auctions a
                JOIN product_listings p ON a.product_id = p.id
                JOIN farmers f ON p.farmer_id = f.id
                WHERE a.id = ? AND f.user_id = ?
            ");
            $stmt->execute([$auction_id, $_SESSION['user_id']]);
            $auction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$auction) {
                throw new Exception('Auction not found or unauthorized');
            }
            
            // End the auction
            $stmt = $this->conn->prepare("
                UPDATE auctions 
                SET status = 'completed', end_time = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$auction_id]);
            
            // Find winning bid
            $stmt = $this->conn->prepare("
                SELECT b.*, bu.company_name, u.username as winner_username, u.email as winner_email
                FROM bids b
                JOIN buyers bu ON b.buyer_id = bu.id
                JOIN users u ON bu.user_id = u.id
                WHERE b.auction_id = ? AND b.bid_amount = (
                    SELECT MAX(bid_amount) FROM bids WHERE auction_id = ?
                )
                ORDER BY b.created_at ASC
                LIMIT 1
            ");
            $stmt->execute([$auction_id, $auction_id]);
            $winning_bid = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($winning_bid) {
                // Create auction result record
                $stmt = $this->conn->prepare("
                    INSERT INTO auction_results (auction_id, winning_bid_id, winner_buyer_id, winning_amount, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $auction_id, 
                    $winning_bid['id'], 
                    $winning_bid['buyer_id'], 
                    $winning_bid['bid_amount']
                ]);
            }
            
            $this->conn->commit();
            
            // Send real-time notification
            $this->sendRealTimeUpdate($auction_id, [
                'type' => 'auction_ended',
                'auction_id' => $auction_id,
                'winner' => $winning_bid ? $winning_bid['company_name'] : null,
                'winning_amount' => $winning_bid ? $winning_bid['bid_amount'] : null,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            return $this->success([
                'message' => 'Auction ended successfully',
                'winner' => $winning_bid ? $winning_bid['company_name'] : null,
                'winning_amount' => $winning_bid ? $winning_bid['bid_amount'] : null
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return $this->error($e->getMessage(), 400);
        }
    }
    
    private function sendRealTimeUpdate($auction_id, $data) {
        // In a real implementation, this would push to a WebSocket server
        // For now, we'll store in a session-based notification system
        if (!isset($_SESSION['auction_updates'])) {
            $_SESSION['auction_updates'] = [];
        }
        
        $_SESSION['auction_updates'][] = [
            'auction_id' => $auction_id,
            'data' => $data,
            'timestamp' => time()
        ];
        
        // Keep only last 100 updates
        if (count($_SESSION['auction_updates']) > 100) {
            $_SESSION['auction_updates'] = array_slice($_SESSION['auction_updates'], -100);
        }
    }
    
    private function formatTimeRemaining($seconds) {
        if ($seconds <= 0) {
            return 'Ended';
        }
        
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        if ($days > 0) {
            return sprintf('%dd %02dh %02dm', $days, $hours, $minutes);
        } elseif ($hours > 0) {
            return sprintf('%02dh %02dm %02ds', $hours, $minutes, $secs);
        } else {
            return sprintf('%02dm %02ds', $minutes, $secs);
        }
    }
    
    private function timeAgo($seconds) {
        if ($seconds < 60) {
            return $seconds . ' seconds ago';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . ' minutes ago';
        } elseif ($seconds < 86400) {
            return floor($seconds / 3600) . ' hours ago';
        } else {
            return floor($seconds / 86400) . ' days ago';
        }
    }
    
    private function success($data) {
        return json_encode(['success' => true, 'data' => $data]);
    }
    
    private function error($message, $code = 400) {
        http_response_code($code);
        return json_encode(['success' => false, 'error' => $message, 'code' => $code]);
    }
}

// Handle the request
$api = new AuctionAPI();
echo $api->handleRequest();
?>
