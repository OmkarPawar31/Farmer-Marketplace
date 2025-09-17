<?php
/**
 * Real-time Auction API with WebSocket support
 * Handles advanced auction features including auto-bidding, watchlist, and real-time updates
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../config/config.php';

class RealtimeAuctionAPI {
    private $conn;
    private $user_id;
    private $user_type;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        
        // Get user from session or token
        session_start();
        $this->user_id = $_SESSION['user_id'] ?? null;
        $this->user_type = $_SESSION['user_type'] ?? null;
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $endpoint = $_GET['endpoint'] ?? '';
        
        try {
            switch ($endpoint) {
                case 'active_auctions':
                    return $this->getActiveAuctions();
                case 'auction_details':
                    return $this->getAuctionDetails();
                case 'place_bid':
                    return $this->placeBid();
                case 'auto_bid':
                    return $this->manageAutoBid();
                case 'watchlist':
                    return $this->manageWatchlist();
                case 'auction_activity':
                    return $this->getAuctionActivity();
                case 'user_bids':
                    return $this->getUserBids();
                case 'auction_analytics':
                    return $this->getAuctionAnalytics();
                case 'create_session':
                    return $this->createAuctionSession();
                case 'heartbeat':
                    return $this->updateHeartbeat();
                case 'leaderboard':
                    return $this->getAuctionLeaderboard();
                case 'bid_history':
                    return $this->getBidHistory();
                case 'extend_auction':
                    return $this->extendAuction();
                case 'dispute':
                    return $this->manageDispute();
                default:
                    return $this->sendError('Invalid endpoint', 404);
            }
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), 500);
        }
    }
    
    private function getActiveAuctions() {
        $category_id = $_GET['category_id'] ?? null;
        $search = $_GET['search'] ?? '';
        $min_price = $_GET['min_price'] ?? 0;
        $max_price = $_GET['max_price'] ?? 999999;
        $sort_by = $_GET['sort_by'] ?? 'end_time';
        $sort_order = $_GET['sort_order'] ?? 'ASC';
        $page = max(1, $_GET['page'] ?? 1);
        $per_page = min(50, $_GET['per_page'] ?? 20);
        $offset = ($page - 1) * $per_page;
        
        $where_conditions = ["a.status = 'active'", "a.end_time > NOW()"];
        $params = [];
        
        if ($category_id) {
            $where_conditions[] = "a.auction_category_id = ?";
            $params[] = $category_id;
        }
        
        if ($search) {
            $where_conditions[] = "(pl.title LIKE ? OR c.name LIKE ? OR f.farm_name LIKE ?)";
            $search_term = "%$search%";
            $params = array_merge($params, [$search_term, $search_term, $search_term]);
        }
        
        if ($min_price > 0) {
            $where_conditions[] = "a.current_bid >= ?";
            $params[] = $min_price;
        }
        
        if ($max_price < 999999) {
            $where_conditions[] = "a.current_bid <= ?";
            $params[] = $max_price;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Valid sort options
        $valid_sorts = ['end_time', 'current_bid', 'total_bids', 'created_at', 'start_price'];
        if (!in_array($sort_by, $valid_sorts)) {
            $sort_by = 'end_time';
        }
        $sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';
        
        $query = "
            SELECT 
                a.*,
                pl.title as product_title,
                pl.description as product_description,
                pl.quantity_available,
                pl.unit,
                pl.image_url,
                c.name as crop_name,
                f.farm_name,
                f.location as farm_location,
                u.username as farmer_username,
                ac.name as category_name,
                TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining,
                (SELECT COUNT(*) FROM auction_watchlist aw WHERE aw.auction_id = a.id AND aw.user_id = ?) as is_watched,
                (SELECT ab.max_bid_amount FROM auto_bids ab WHERE ab.auction_id = a.id AND ab.buyer_id = ? AND ab.is_active = 1) as auto_bid_max
            FROM active_auctions a
            LEFT JOIN auction_categories ac ON a.auction_category_id = ac.id
            WHERE $where_clause
            ORDER BY a.$sort_by $sort_order
            LIMIT ? OFFSET ?
        ";
        
        $buyer_id = null;
        if ($this->user_type === 'buyer') {
            $buyer_query = "SELECT id FROM buyers WHERE user_id = ?";
            $buyer_stmt = $this->conn->prepare($buyer_query);
            $buyer_stmt->execute([$this->user_id]);
            $buyer_result = $buyer_stmt->fetch(PDO::FETCH_ASSOC);
            $buyer_id = $buyer_result['id'] ?? null;
        }
        
        $params = array_merge([$this->user_id, $buyer_id], $params, [$per_page, $offset]);
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM active_auctions a WHERE $where_clause";
        $count_params = array_slice($params, 2, -2); // Remove user_id, buyer_id, limit, offset
        $count_stmt = $this->conn->prepare($count_query);
        $count_stmt->execute($count_params);
        $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Format auction data
        $formatted_auctions = array_map([$this, 'formatAuctionData'], $auctions);
        
        return $this->sendSuccess([
            'auctions' => $formatted_auctions,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => (int)$total,
                'total_pages' => ceil($total / $per_page)
            ]
        ]);
    }
    
    private function getAuctionDetails() {
        $auction_id = $_GET['auction_id'] ?? null;
        if (!$auction_id) {
            return $this->sendError('Auction ID is required');
        }
        
        // Get auction details
        $query = "
            SELECT 
                a.*,
                pl.title as product_title,
                pl.description as product_description,
                pl.quantity_available,
                pl.unit,
                pl.image_url,
                pl.quality_grade,
                pl.organic_certified,
                c.name as crop_name,
                c.category as crop_category,
                f.farm_name,
                f.location as farm_location,
                f.contact_phone,
                u.username as farmer_username,
                u.email as farmer_email,
                ac.name as category_name,
                TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining,
                (SELECT COUNT(*) FROM auction_watchlist aw WHERE aw.auction_id = a.id AND aw.user_id = ?) as is_watched
            FROM auctions a
            JOIN product_listings pl ON a.product_id = pl.id
            JOIN crops c ON pl.crop_id = c.id
            JOIN farmers f ON pl.farmer_id = f.id
            JOIN users u ON f.user_id = u.id
            LEFT JOIN auction_categories ac ON a.auction_category_id = ac.id
            WHERE a.id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$this->user_id, $auction_id]);
        $auction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$auction) {
            return $this->sendError('Auction not found', 404);
        }
        
        // Get current leading bid
        $bid_query = "
            SELECT 
                b.*,
                u.username as bidder_username,
                buy.company_name as bidder_company
            FROM bids b
            JOIN buyers buy ON b.buyer_id = buy.id
            JOIN users u ON buy.user_id = u.id
            WHERE b.auction_id = ? AND b.status = 'active'
            ORDER BY b.bid_amount DESC, b.created_at ASC
            LIMIT 1
        ";
        
        $bid_stmt = $this->conn->prepare($bid_query);
        $bid_stmt->execute([$auction_id]);
        $leading_bid = $bid_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get auction participants
        $participants_query = "
            SELECT 
                ap.user_id,
                u.username,
                ap.highest_bid,
                ap.total_bids,
                ap.joined_at
            FROM auction_participants ap
            JOIN users u ON ap.user_id = u.id
            WHERE ap.auction_id = ? AND ap.is_active = 1
            ORDER BY ap.highest_bid DESC
        ";
        
        $participants_stmt = $this->conn->prepare($participants_query);
        $participants_stmt->execute([$auction_id]);
        $participants = $participants_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get user's auto-bid status if buyer
        $auto_bid = null;
        if ($this->user_type === 'buyer') {
            $buyer_query = "SELECT id FROM buyers WHERE user_id = ?";
            $buyer_stmt = $this->conn->prepare($buyer_query);
            $buyer_stmt->execute([$this->user_id]);
            $buyer_result = $buyer_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($buyer_result) {
                $auto_bid_query = "SELECT * FROM auto_bids WHERE auction_id = ? AND buyer_id = ? AND is_active = 1";
                $auto_bid_stmt = $this->conn->prepare($auto_bid_query);
                $auto_bid_stmt->execute([$auction_id, $buyer_result['id']]);
                $auto_bid = $auto_bid_stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        
        // Update view count in analytics
        $this->updateAuctionAnalytics($auction_id, 'view');
        
        $formatted_auction = $this->formatAuctionData($auction);
        $formatted_auction['leading_bid'] = $leading_bid;
        $formatted_auction['participants'] = $participants;
        $formatted_auction['auto_bid'] = $auto_bid;
        
        return $this->sendSuccess($formatted_auction);
    }
    
    private function placeBid() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->sendError('POST method required');
        }
        
        if ($this->user_type !== 'buyer') {
            return $this->sendError('Only buyers can place bids');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $auction_id = $input['auction_id'] ?? null;
        $bid_amount = $input['bid_amount'] ?? null;
        $quantity = $input['quantity'] ?? 1;
        
        if (!$auction_id || !$bid_amount) {
            return $this->sendError('Auction ID and bid amount are required');
        }
        
        // Get buyer ID
        $buyer_query = "SELECT id FROM buyers WHERE user_id = ?";
        $buyer_stmt = $this->conn->prepare($buyer_query);
        $buyer_stmt->execute([$this->user_id]);
        $buyer_result = $buyer_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$buyer_result) {
            return $this->sendError('Buyer profile not found');
        }
        
        $buyer_id = $buyer_result['id'];
        
        // Validate auction
        $auction_query = "SELECT * FROM auctions WHERE id = ? AND status = 'active' AND end_time > NOW()";
        $auction_stmt = $this->conn->prepare($auction_query);
        $auction_stmt->execute([$auction_id]);
        $auction = $auction_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$auction) {
            return $this->sendError('Auction not found or has ended');
        }
        
        // Validate bid amount
        $min_bid = max($auction['current_bid'] + $auction['bid_increment'], $auction['start_price']);
        if ($bid_amount < $min_bid) {
            return $this->sendError("Minimum bid is ₹" . number_format($min_bid, 2));
        }
        
        // Check if reserve price is met (if applicable)
        if ($auction['auction_type'] === 'reserve' && $bid_amount < $auction['reserve_price']) {
            return $this->sendError("Bid must meet reserve price of ₹" . number_format($auction['reserve_price'], 2));
        }
        
        try {
            $this->conn->beginTransaction();
            
            // Insert bid
            $bid_query = "
                INSERT INTO bids (auction_id, buyer_id, bid_amount, quantity, message, ip_address, user_agent, bid_increment, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ";
            
            $bid_increment = $bid_amount - $auction['current_bid'];
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $message = $input['message'] ?? null;
            
            $bid_stmt = $this->conn->prepare($bid_query);
            $bid_stmt->execute([
                $auction_id, $buyer_id, $bid_amount, $quantity, 
                $message, $ip_address, $user_agent, $bid_increment
            ]);
            
            $bid_id = $this->conn->lastInsertId();
            
            // Check if auction needs to be extended
            $time_remaining = strtotime($auction['end_time']) - time();
            if ($time_remaining <= ($auction['auto_extend_minutes'] * 60) && $auction['extended_count'] < $auction['max_extensions']) {
                $new_end_time = date('Y-m-d H:i:s', strtotime($auction['end_time']) + ($auction['auto_extend_minutes'] * 60));
                
                $extend_query = "UPDATE auctions SET end_time = ?, extended_count = extended_count + 1 WHERE id = ?";
                $extend_stmt = $this->conn->prepare($extend_query);
                $extend_stmt->execute([$new_end_time, $auction_id]);
                
                // Log extension
                $this->logActivity($auction_id, $this->user_id, 'auction_extended', $bid_amount, 'Auction extended by ' . $auction['auto_extend_minutes'] . ' minutes');
            }
            
            $this->conn->commit();
            
            // Process auto-bids from other buyers
            $this->processAutoBids($auction_id, $bid_amount, $buyer_id);
            
            return $this->sendSuccess([
                'bid_id' => $bid_id,
                'message' => 'Bid placed successfully',
                'current_bid' => $bid_amount,
                'time_remaining' => max(0, strtotime($auction['end_time']) - time())
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            return $this->sendError('Failed to place bid: ' . $e->getMessage());
        }
    }
    
    private function manageAutoBid() {
        if ($this->user_type !== 'buyer') {
            return $this->sendError('Only buyers can manage auto-bids');
        }
        
        $method = $_SERVER['REQUEST_METHOD'];
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Get buyer ID
        $buyer_query = "SELECT id FROM buyers WHERE user_id = ?";
        $buyer_stmt = $this->conn->prepare($buyer_query);
        $buyer_stmt->execute([$this->user_id]);
        $buyer_result = $buyer_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$buyer_result) {
            return $this->sendError('Buyer profile not found');
        }
        
        $buyer_id = $buyer_result['id'];
        
        if ($method === 'POST') {
            // Create/update auto-bid
            $auction_id = $input['auction_id'] ?? null;
            $max_bid_amount = $input['max_bid_amount'] ?? null;
            $increment_amount = $input['increment_amount'] ?? 1.00;
            
            if (!$auction_id || !$max_bid_amount) {
                return $this->sendError('Auction ID and max bid amount are required');
            }
            
            $query = "
                INSERT INTO auto_bids (auction_id, buyer_id, max_bid_amount, increment_amount, is_active)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                max_bid_amount = VALUES(max_bid_amount),
                increment_amount = VALUES(increment_amount),
                is_active = 1,
                updated_at = CURRENT_TIMESTAMP
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$auction_id, $buyer_id, $max_bid_amount, $increment_amount]);
            
            return $this->sendSuccess(['message' => 'Auto-bid configured successfully']);
            
        } elseif ($method === 'DELETE') {
            // Disable auto-bid
            $auction_id = $_GET['auction_id'] ?? null;
            if (!$auction_id) {
                return $this->sendError('Auction ID is required');
            }
            
            $query = "UPDATE auto_bids SET is_active = 0 WHERE auction_id = ? AND buyer_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$auction_id, $buyer_id]);
            
            return $this->sendSuccess(['message' => 'Auto-bid disabled']);
            
        } else {
            // Get auto-bids
            $query = "
                SELECT 
                    ab.*,
                    a.product_id,
                    pl.title as product_title,
                    a.current_bid,
                    a.end_time,
                    a.status as auction_status
                FROM auto_bids ab
                JOIN auctions a ON ab.auction_id = a.id
                JOIN product_listings pl ON a.product_id = pl.id
                WHERE ab.buyer_id = ? AND ab.is_active = 1
                ORDER BY ab.updated_at DESC
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$buyer_id]);
            $auto_bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->sendSuccess(['auto_bids' => $auto_bids]);
        }
    }
    
    private function manageWatchlist() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        if ($method === 'POST') {
            // Add to watchlist
            $input = json_decode(file_get_contents('php://input'), true);
            $auction_id = $input['auction_id'] ?? null;
            $notifications = $input['notifications'] ?? ['bid_outbid' => true, 'ending_soon' => true, 'won' => true];
            
            if (!$auction_id) {
                return $this->sendError('Auction ID is required');
            }
            
            $query = "
                INSERT INTO auction_watchlist (auction_id, user_id, notification_preferences)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                notification_preferences = VALUES(notification_preferences)
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$auction_id, $this->user_id, json_encode($notifications)]);
            
            return $this->sendSuccess(['message' => 'Added to watchlist']);
            
        } elseif ($method === 'DELETE') {
            // Remove from watchlist
            $auction_id = $_GET['auction_id'] ?? null;
            if (!$auction_id) {
                return $this->sendError('Auction ID is required');
            }
            
            $query = "DELETE FROM auction_watchlist WHERE auction_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$auction_id, $this->user_id]);
            
            return $this->sendSuccess(['message' => 'Removed from watchlist']);
            
        } else {
            // Get watchlist
            $query = "
                SELECT 
                    aw.*,
                    a.product_id,
                    pl.title as product_title,
                    a.current_bid,
                    a.end_time,
                    a.status as auction_status,
                    TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining
                FROM auction_watchlist aw
                JOIN auctions a ON aw.auction_id = a.id
                JOIN product_listings pl ON a.product_id = pl.id
                WHERE aw.user_id = ?
                ORDER BY a.end_time ASC
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $watchlist = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->sendSuccess(['watchlist' => $watchlist]);
        }
    }
    
    private function getAuctionActivity() {
        $auction_id = $_GET['auction_id'] ?? null;
        if (!$auction_id) {
            return $this->sendError('Auction ID is required');
        }
        
        $limit = min(100, $_GET['limit'] ?? 50);
        
        $query = "
            SELECT 
                aal.*,
                u.username,
                u.user_type
            FROM auction_activity_log aal
            JOIN users u ON aal.user_id = u.id
            WHERE aal.auction_id = ?
            ORDER BY aal.created_at DESC
            LIMIT ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$auction_id, $limit]);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->sendSuccess(['activities' => $activities]);
    }
    
    private function getAuctionLeaderboard() {
        $auction_id = $_GET['auction_id'] ?? null;
        if (!$auction_id) {
            return $this->sendError('Auction ID is required');
        }
        
        $query = "
            SELECT * FROM auction_leaderboard 
            WHERE auction_id = ?
            ORDER BY rank_position ASC
            LIMIT 10
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$auction_id]);
        $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->sendSuccess(['leaderboard' => $leaderboard]);
    }
    
    private function getBidHistory() {
        $auction_id = $_GET['auction_id'] ?? null;
        if (!$auction_id) {
            return $this->sendError('Auction ID is required');
        }
        
        $page = max(1, $_GET['page'] ?? 1);
        $per_page = min(50, $_GET['per_page'] ?? 20);
        $offset = ($page - 1) * $per_page;
        
        $query = "
            SELECT 
                b.*,
                u.username as bidder_username,
                buy.company_name as bidder_company
            FROM bids b
            JOIN buyers buy ON b.buyer_id = buy.id
            JOIN users u ON buy.user_id = u.id
            WHERE b.auction_id = ?
            ORDER BY b.bid_amount DESC, b.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$auction_id, $per_page, $offset]);
        $bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM bids WHERE auction_id = ?";
        $count_stmt = $this->conn->prepare($count_query);
        $count_stmt->execute([$auction_id]);
        $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        return $this->sendSuccess([
            'bids' => $bids,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => (int)$total,
                'total_pages' => ceil($total / $per_page)
            ]
        ]);
    }
    
    private function getUserBids() {
        if ($this->user_type !== 'buyer') {
            return $this->sendError('Only buyers can view bid history');
        }
        
        // Get buyer ID
        $buyer_query = "SELECT id FROM buyers WHERE user_id = ?";
        $buyer_stmt = $this->conn->prepare($buyer_query);
        $buyer_stmt->execute([$this->user_id]);
        $buyer_result = $buyer_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$buyer_result) {
            return $this->sendError('Buyer profile not found');
        }
        
        $status = $_GET['status'] ?? 'all'; // all, active, won, lost
        $page = max(1, $_GET['page'] ?? 1);
        $per_page = min(50, $_GET['per_page'] ?? 20);
        $offset = ($page - 1) * $per_page;
        
        $where_conditions = ["b.buyer_id = ?"];
        $params = [$buyer_result['id']];
        
        if ($status === 'active') {
            $where_conditions[] = "b.status = 'active' AND a.status = 'active'";
        } elseif ($status === 'won') {
            $where_conditions[] = "a.winner_id = b.buyer_id AND a.status = 'completed'";
        } elseif ($status === 'lost') {
            $where_conditions[] = "b.status = 'rejected' OR (a.status = 'completed' AND a.winner_id != b.buyer_id)";
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "
            SELECT 
                b.*,
                a.product_id,
                a.status as auction_status,
                a.end_time,
                a.current_bid as auction_current_bid,
                a.winner_id,
                pl.title as product_title,
                pl.image_url,
                c.name as crop_name,
                f.farm_name,
                TIMESTAMPDIFF(SECOND, NOW(), a.end_time) as seconds_remaining,
                CASE 
                    WHEN a.winner_id = b.buyer_id AND a.status = 'completed' THEN 'won'
                    WHEN b.status = 'active' AND a.status = 'active' THEN 'active'
                    ELSE 'lost'
                END as bid_status
            FROM bids b
            JOIN auctions a ON b.auction_id = a.id
            JOIN product_listings pl ON a.product_id = pl.id
            JOIN crops c ON pl.crop_id = c.id
            JOIN farmers f ON pl.farmer_id = f.id
            WHERE $where_clause
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params = array_merge($params, [$per_page, $offset]);
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->sendSuccess(['bids' => $bids]);
    }
    
    private function createAuctionSession() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->sendError('POST method required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $auction_id = $input['auction_id'] ?? null;
        
        if (!$auction_id) {
            return $this->sendError('Auction ID is required');
        }
        
        $session_id = uniqid('auction_', true);
        $connection_data = [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $query = "
            INSERT INTO auction_sessions (auction_id, session_id, user_id, connection_data)
            VALUES (?, ?, ?, ?)
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$auction_id, $session_id, $this->user_id, json_encode($connection_data)]);
        
        return $this->sendSuccess([
            'session_id' => $session_id,
            'message' => 'Session created successfully'
        ]);
    }
    
    private function updateHeartbeat() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->sendError('POST method required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $session_id = $input['session_id'] ?? null;
        
        if (!$session_id) {
            return $this->sendError('Session ID is required');
        }
        
        $query = "UPDATE auction_sessions SET last_activity = CURRENT_TIMESTAMP WHERE session_id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$session_id, $this->user_id]);
        
        if ($stmt->rowCount() === 0) {
            return $this->sendError('Invalid session', 404);
        }
        
        return $this->sendSuccess(['message' => 'Heartbeat updated']);
    }
    
    // Helper methods
    private function processAutoBids($auction_id, $current_bid, $exclude_buyer_id) {
        $query = "
            SELECT ab.*, b.id as buyer_table_id
            FROM auto_bids ab
            JOIN buyers b ON ab.buyer_id = b.id
            WHERE ab.auction_id = ? 
            AND ab.is_active = 1 
            AND ab.buyer_id != ?
            AND ab.max_bid_amount > ?
            ORDER BY ab.max_bid_amount DESC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$auction_id, $exclude_buyer_id, $current_bid]);
        $auto_bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($auto_bids as $auto_bid) {
            $next_bid = $current_bid + $auto_bid['increment_amount'];
            
            if ($next_bid <= $auto_bid['max_bid_amount']) {
                // Place auto-bid
                $bid_query = "
                    INSERT INTO bids (auction_id, buyer_id, bid_amount, quantity, message, auto_bid, status)
                    VALUES (?, ?, ?, 1, 'Auto-bid', 1, 'active')
                ";
                
                try {
                    $bid_stmt = $this->conn->prepare($bid_query);
                    $bid_stmt->execute([$auction_id, $auto_bid['buyer_id'], $next_bid]);
                    
                    // Update auto-bid stats
                    $update_query = "UPDATE auto_bids SET total_bids_placed = total_bids_placed + 1 WHERE id = ?";
                    $update_stmt = $this->conn->prepare($update_query);
                    $update_stmt->execute([$auto_bid['id']]);
                    
                    // Log auto-bid activity
                    $this->logActivity($auction_id, $auto_bid['buyer_table_id'], 'auto_bid_triggered', $next_bid);
                    
                    $current_bid = $next_bid;
                    
                } catch (Exception $e) {
                    error_log("Auto-bid failed: " . $e->getMessage());
                }
            }
        }
    }
    
    private function logActivity($auction_id, $user_id, $activity_type, $bid_amount = null, $message = null) {
        $query = "
            INSERT INTO auction_activity_log (auction_id, user_id, activity_type, bid_amount, message, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $auction_id, 
            $user_id, 
            $activity_type, 
            $bid_amount, 
            $message,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
    
    private function updateAuctionAnalytics($auction_id, $action) {
        switch ($action) {
            case 'view':
                $query = "
                    INSERT INTO auction_analytics (auction_id, total_views, unique_viewers)
                    VALUES (?, 1, 1)
                    ON DUPLICATE KEY UPDATE
                    total_views = total_views + 1,
                    unique_viewers = unique_viewers + 1
                ";
                break;
        }
        
        if (isset($query)) {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$auction_id]);
        }
    }
    
    private function formatAuctionData($auction) {
        $auction['seconds_remaining'] = max(0, (int)$auction['seconds_remaining']);
        $auction['current_bid'] = (float)$auction['current_bid'];
        $auction['start_price'] = (float)$auction['start_price'];
        $auction['reserve_price'] = (float)($auction['reserve_price'] ?? 0);
        $auction['bid_increment'] = (float)$auction['bid_increment'];
        $auction['total_bids'] = (int)$auction['total_bids'];
        $auction['unique_bidders'] = (int)$auction['unique_bidders'];
        $auction['is_watched'] = (bool)($auction['is_watched'] ?? false);
        $auction['auto_bid_max'] = $auction['auto_bid_max'] ? (float)$auction['auto_bid_max'] : null;
        
        // Calculate auction status
        if ($auction['seconds_remaining'] <= 0 && $auction['status'] === 'active') {
            $auction['display_status'] = 'ended';
        } elseif ($auction['seconds_remaining'] <= 300) { // 5 minutes
            $auction['display_status'] = 'ending_soon';
        } else {
            $auction['display_status'] = $auction['status'];
        }
        
        return $auction;
    }
    
    private function sendSuccess($data, $message = 'Success') {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ]);
        exit;
    }
    
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'timestamp' => time()
        ]);
        exit;
    }
}

// Initialize and handle request
$api = new RealtimeAuctionAPI();
$api->handleRequest();
?>
