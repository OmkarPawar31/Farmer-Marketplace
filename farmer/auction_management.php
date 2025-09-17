<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as farmer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'farmer') {
    header('Location: ../farmer/login.php');
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];

// Function to get ongoing farmer auctions with bid details
function getFarmerAuctions($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT a.*, p.title, c.name as crop_name, p.quantity_available, p.unit,
               (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id AND b.status = 'active') as total_bids,
               (SELECT COUNT(DISTINCT b.buyer_id) FROM bids b WHERE b.auction_id = a.id AND b.status = 'active') as unique_bidders
        FROM auctions a
        JOIN product_listings p ON a.product_id = p.id
        JOIN crops c ON p.crop_id = c.id
        JOIN farmers f ON p.farmer_id = f.id
        WHERE f.user_id = ? AND a.status = 'active'
        ORDER BY a.end_time DESC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get bids for a specific auction
function getAuctionBids($conn, $auction_id) {
    $stmt = $conn->prepare("
        SELECT b.*, u.username, u.phone, bu.company_name, bu.business_type,
               CASE WHEN b.bid_amount = (SELECT MAX(bid_amount) FROM bids WHERE auction_id = ? AND status = 'active') 
                    THEN 1 ELSE 0 END as is_highest_bid
        FROM bids b
        JOIN buyers bu ON b.buyer_id = bu.id
        JOIN users u ON bu.user_id = u.id
        WHERE b.auction_id = ? AND b.status = 'active'
        ORDER BY b.bid_amount DESC, b.created_at ASC
    ");
    $stmt->execute([$auction_id, $auction_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get highest bidder for chat
function getHighestBidder($conn, $auction_id) {
    $stmt = $conn->prepare("
        SELECT b.*, u.username, u.phone, u.email, bu.company_name, bu.business_type
        FROM bids b
        JOIN buyers bu ON b.buyer_id = bu.id
        JOIN users u ON bu.user_id = u.id
        WHERE b.auction_id = ? AND b.status = 'active'
        ORDER BY b.bid_amount DESC, b.created_at ASC
        LIMIT 1
    ");
    $stmt->execute([$auction_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Function to create a new auction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $product_id = $_POST['product_id'];
    $start_price = $_POST['start_price'];
    $end_time = $_POST['end_time'];
    
    // Validation
    $errors = [];
    if (empty($product_id)) {
        $errors[] = "Please select a product.";
    }
    if (empty($start_price) || $start_price <= 0) {
        $errors[] = "Please enter a valid starting price.";
    }
    if (empty($end_time)) {
        $errors[] = "Please select an end time.";
    } else {
        $end_timestamp = strtotime($end_time);
        if ($end_timestamp <= time()) {
            $errors[] = "End time must be in the future.";
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['auction_error'] = implode(" ", $errors);
        header('Location: auction_management.php');
        exit();
    }
    
    try {
        // Check if product exists and belongs to farmer
        $stmt = $conn->prepare("
            SELECT p.id, p.title, p.status 
            FROM product_listings p 
            JOIN farmers f ON p.farmer_id = f.id 
            WHERE p.id = ? AND f.user_id = ? AND p.status = 'active'
        ");
        $stmt->execute([$product_id, $user_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            $_SESSION['auction_error'] = "Product not found or you don't have permission to create auction for this product.";
            header('Location: auction_management.php');
            exit();
        }
        
        // Check if auction already exists for this product
        $stmt = $conn->prepare("SELECT id FROM auctions WHERE product_id = ? AND status = 'active' AND end_time > NOW()");
        $stmt->execute([$product_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $_SESSION['auction_error'] = "An active auction already exists for this product.";
            header('Location: auction_management.php');
            exit();
        }
        
        // Create auction
        $stmt = $conn->prepare("
            INSERT INTO auctions (product_id, start_price, current_bid, end_time, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, 'active', NOW(), NOW())
        ");
        $stmt->execute([$product_id, $start_price, $start_price, $end_time]);
        
        $auction_id = $conn->lastInsertId();
        $_SESSION['auction_success'] = "Auction created successfully! Auction ID: #{$auction_id}";
        
    } catch (Exception $e) {
        $_SESSION['auction_error'] = "Failed to create auction. Error: " . $e->getMessage();
        error_log("Auction creation error: " . $e->getMessage());
    }
    
    header('Location: auction_management.php');
    exit();
}

$farmer_auctions = getFarmerAuctions($conn, $user_id);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auction Management - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .auction-management-container {
            max-width: 1200px;
            margin: 80px auto;
            padding: 20px;
        }
        .create-auction {
            margin-bottom: 30px;
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        .create-auction h2 {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: #264653;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #264653;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
        }
        .table th, .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #264653;
        }
        .status-active {
            color: #10b981;
        }
        .status-completed {
            color: #6366f1;
        }
        
        /* Enhanced Auction Cards */
        .auction-card {
            background: white;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .auction-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 25px;
        }
        
        .auction-header h3 {
            margin: 0 0 15px 0;
            font-size: 1.4rem;
        }
        
        .auction-stats {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .auction-body {
            padding: 25px;
        }
        
        .auction-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 5px 0;
        }
        
        .detail-row:last-child {
            margin-bottom: 0;
        }
        
        .detail-row .label {
            font-weight: 600;
            color: #666;
        }
        
        .detail-row .value {
            color: #264653;
            font-weight: 500;
        }
        
        /* Bids Section */
        .bids-section h4 {
            color: #264653;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .bid-item {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .bid-item.highest-bid {
            background: linear-gradient(135deg, #fff7ed 0%, #fef3c7 100%);
            border-color: #f59e0b;
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.2);
        }
        
        .bid-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .bidder-info strong {
            font-size: 1.1rem;
            color: #264653;
            display: block;
            margin-bottom: 5px;
        }
        
        .highest-badge {
            background: #f59e0b;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin: 5px 0;
            display: inline-block;
        }
        
        .bidder-info small {
            color: #666;
            font-size: 13px;
        }
        
        .bid-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2a9d8f;
        }
        
        .bid-meta {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
            font-size: 14px;
            color: #666;
        }
        
        .chat-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .btn-chat {
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-chat:hover {
            background: linear-gradient(135deg, #219f8b, #1e8a7a);
            transform: translateY(-2px);
        }
        
        .btn-call {
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-call:hover {
            background: linear-gradient(135deg, #15803d, #166534);
            transform: translateY(-2px);
        }
        
        .no-bids {
            text-align: center;
            padding: 40px 20px;
            color: #666;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .no-bids i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        .no-auctions {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .no-auctions i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .no-auctions h3 {
            color: #264653;
            margin-bottom: 10px;
        }
        
        /* Chat Modal */
        .chat-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        
        .chat-modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            width: 90%;
            max-width: 500px;
            height: 600px;
            border-radius: 15px;
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            background: #264653;
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .chat-body {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
        }
        
        .chat-input {
            padding: 20px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
        }
        
        .chat-input input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 25px;
        }
        
        .chat-input button {
            background: #2a9d8f;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .auction-stats {
                flex-direction: column;
                gap: 10px;
            }
            
            .bid-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .bid-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .chat-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="auction-management-container">
    <h1>Auction Management</h1>
    <div class="create-auction">
        <h2>Create New Auction</h2>
        <?php if (isset($_SESSION['auction_error'])): ?>
            <div class="alert alert-danger">
                <?php echo $_SESSION['auction_error']; unset($_SESSION['auction_error']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['auction_success'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['auction_success']; unset($_SESSION['auction_success']); ?>
            </div>
        <?php endif; ?>
        <form action="" method="post">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label for="product_id">Select Product</label>
                <select name="product_id" required>
                    <option value="">-- Select Product --</option>
                    <?php
                    $stmt = $conn->prepare("SELECT id, title FROM product_listings WHERE farmer_id = (SELECT id FROM farmers WHERE user_id = ?) AND status = 'active'");
                    $stmt->execute([$user_id]);
                    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>"><?php echo $product['title']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="start_price">Starting Price (₹)</label>
                <input type="number" name="start_price" min="1" required>
            </div>
            <div class="form-group">
                <label for="end_time">End Time</label>
                <input type="datetime-local" name="end_time" required>
            </div>
            <button type="submit" class="btn btn-primary">Create Auction</button>
        </form>
    </div>

    <h2>Ongoing Auctions & Bids</h2>
    <?php if (!empty($farmer_auctions)): ?>
        <?php foreach ($farmer_auctions as $auction): ?>
            <div class="auction-card">
                <div class="auction-header">
                    <h3><?php echo htmlspecialchars($auction['title']); ?></h3>
                    <div class="auction-stats">
                        <span class="stat-item">
                            <i class="fas fa-gavel"></i>
                            Current Bid: ₹<?php echo number_format($auction['current_bid'], 2); ?>
                        </span>
                        <span class="stat-item">
                            <i class="fas fa-users"></i>
                            <?php echo $auction['unique_bidders']; ?> Bidders
                        </span>
                        <span class="stat-item">
                            <i class="fas fa-clock"></i>
                            Ends: <?php echo date('d M Y H:i', strtotime($auction['end_time'])); ?>
                        </span>
                    </div>
                </div>
                
                <div class="auction-body">
                    <div class="auction-details">
                        <div class="detail-row">
                            <span class="label">Crop:</span>
                            <span class="value"><?php echo htmlspecialchars($auction['crop_name']); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Quantity:</span>
                            <span class="value"><?php echo number_format($auction['quantity_available']); ?> <?php echo $auction['unit']; ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Starting Price:</span>
                            <span class="value">₹<?php echo number_format($auction['start_price'], 2); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="label">Total Bids:</span>
                            <span class="value"><?php echo $auction['total_bids']; ?></span>
                        </div>
                    </div>
                    
                    <?php if ($auction['total_bids'] > 0): ?>
                        <div class="bids-section">
                            <h4><i class="fas fa-list"></i> Bid History</h4>
                            <div class="bids-list">
                                <?php 
                                $auction_bids = getAuctionBids($conn, $auction['id']);
                                foreach ($auction_bids as $index => $bid): 
                                ?>
                                    <div class="bid-item <?php echo $bid['is_highest_bid'] ? 'highest-bid' : ''; ?>">
                                        <div class="bid-header">
                                            <div class="bidder-info">
                                                <strong><?php echo htmlspecialchars($bid['company_name']); ?></strong>
                                                <?php if ($bid['is_highest_bid']): ?>
                                                    <span class="highest-badge"><i class="fas fa-crown"></i> Highest Bid</span>
                                                <?php endif; ?>
                                                <small><?php echo htmlspecialchars($bid['business_type']); ?></small>
                                            </div>
                                            <div class="bid-amount">
                                                ₹<?php echo number_format($bid['bid_amount'], 2); ?>
                                            </div>
                                        </div>
                                        <div class="bid-meta">
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($bid['username']); ?></span>
                                            <span><i class="fas fa-clock"></i> <?php echo date('d M Y H:i', strtotime($bid['created_at'])); ?></span>
                                            <?php if ($bid['is_highest_bid']): ?>
                                                <div class="chat-actions">
                                                    <button class="btn btn-chat" onclick="openChat(<?php echo $bid['buyer_id']; ?>, '<?php echo htmlspecialchars($bid['company_name']); ?>', <?php echo $auction['id']; ?>)">
                                                        <i class="fas fa-comments"></i> Chat with Highest Bidder
                                                    </button>
                                                    <a href="tel:<?php echo $bid['phone']; ?>" class="btn btn-call">
                                                        <i class="fas fa-phone"></i> Call Now
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-bids">
                            <i class="fas fa-info-circle"></i>
                            <p>No bids placed yet. Auction is live and waiting for bidders.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="no-auctions">
            <i class="fas fa-gavel"></i>
            <h3>No Active Auctions</h3>
            <p>Create your first auction to start receiving bids from buyers.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Chat Modal -->
<div id="chatModal" class="chat-modal">
    <div class="chat-modal-content">
        <div class="chat-header">
            <div>
                <h4 id="chatCompanyName">Chat with Buyer</h4>
                <small id="chatAuctionInfo">Auction Discussion</small>
            </div>
            <button class="chat-close" onclick="closeChat()">&times;</button>
        </div>
        <div class="chat-body" id="chatMessages">
            <div style="text-align: center; color: #666; padding: 20px;">
                <i class="fas fa-comments" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                <p>Start a conversation with the highest bidder</p>
            </div>
        </div>
        <div class="chat-input">
            <input type="text" id="messageInput" placeholder="Type your message..." onkeypress="handleKeyPress(event)">
            <button onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>
</div>

<script>
let currentBuyerId = null;
let currentAuctionId = null;

function openChat(buyerId, companyName, auctionId) {
    currentBuyerId = buyerId;
    currentAuctionId = auctionId;
    
    document.getElementById('chatCompanyName').textContent = `Chat with ${companyName}`;
    document.getElementById('chatAuctionInfo').textContent = `Auction #${auctionId} Discussion`;
    document.getElementById('chatModal').style.display = 'block';
    
    // Load existing messages
    loadChatMessages();
    
    // Focus on input
    document.getElementById('messageInput').focus();
}

function closeChat() {
    document.getElementById('chatModal').style.display = 'none';
    currentBuyerId = null;
    currentAuctionId = null;
}

function handleKeyPress(event) {
    if (event.key === 'Enter') {
        sendMessage();
    }
}

function sendMessage() {
    const messageInput = document.getElementById('messageInput');
    const message = messageInput.value.trim();
    
    if (!message || !currentBuyerId) return;
    
    // Add message to chat immediately (optimistic update)
    addMessageToChat('farmer', message, new Date());
    messageInput.value = '';
    
    // Send message via AJAX
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('receiver_id', currentBuyerId);
    formData.append('auction_id', currentAuctionId);
    formData.append('message', message);
    
    fetch('../api/chat_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Failed to send message:', data.error);
        }
    })
    .catch(error => {
        console.error('Error sending message:', error);
    });
}

function loadChatMessages() {
    if (!currentBuyerId || !currentAuctionId) return;
    
    const formData = new FormData();
    formData.append('action', 'get_messages');
    formData.append('buyer_id', currentBuyerId);
    formData.append('auction_id', currentAuctionId);
    
    fetch('../api/chat_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const chatMessages = document.getElementById('chatMessages');
            chatMessages.innerHTML = '';
            
            if (data.messages.length === 0) {
                chatMessages.innerHTML = `
                    <div style="text-align: center; color: #666; padding: 20px;">
                        <i class="fas fa-comments" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                        <p>Start a conversation with the highest bidder</p>
                    </div>
                `;
            } else {
                data.messages.forEach(msg => {
                    addMessageToChat(msg.sender_type, msg.message, new Date(msg.created_at));
                });
            }
        }
    })
    .catch(error => {
        console.error('Error loading messages:', error);
    });
}

function addMessageToChat(senderType, message, timestamp) {
    const chatMessages = document.getElementById('chatMessages');
    const messageDiv = document.createElement('div');
    messageDiv.style.cssText = `
        margin-bottom: 15px;
        padding: 10px 15px;
        border-radius: 15px;
        max-width: 70%;
        ${senderType === 'farmer' ? 
          'background: #2a9d8f; color: white; margin-left: auto; text-align: right;' : 
          'background: #f1f3f4; color: #333; margin-right: auto;'
        }
    `;
    
    messageDiv.innerHTML = `
        <div>${message}</div>
        <small style="opacity: 0.7; font-size: 11px;">
            ${timestamp.toLocaleTimeString('en-IN', {hour: '2-digit', minute: '2-digit'})}
        </small>
    `;
    
    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// Auto-refresh chat messages every 5 seconds when chat is open
setInterval(() => {
    if (currentBuyerId && document.getElementById('chatModal').style.display === 'block') {
        loadChatMessages();
    }
}, 5000);

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('chatModal');
    if (event.target === modal) {
        closeChat();
    }
}

// Auto-refresh auction data every 30 seconds
setInterval(() => {
    location.reload();
}, 30000);
</script>

</body>
</html>

