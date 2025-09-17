<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as buyer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'buyer') {
    header('Location: ../buyer/login.php');
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];

// Function to get active auctions
function getActiveAuctions($conn) {
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
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle bid placement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bid') {
    $auction_id = $_POST['auction_id'];
    $bid_amount = $_POST['bid_amount'];
    
    try {
        // Get buyer ID from users table
        $stmt = $conn->prepare("SELECT b.id FROM buyers b WHERE b.user_id = ?");
        $stmt->execute([$user_id]);
        $buyer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$buyer) {
            $_SESSION['bid_error'] = "Buyer profile not found. Please complete your registration.";
        } else {
            $buyer_id = $buyer['id'];
            
            // Check if bid amount is higher than current bid and get product_id
            $stmt = $conn->prepare("SELECT current_bid, product_id FROM auctions WHERE id = ?");
            $stmt->execute([$auction_id]);
            $auction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$auction) {
                $_SESSION['bid_error'] = "Auction not found.";
            } elseif ($bid_amount <= $auction['current_bid']) {
                $_SESSION['bid_error'] = "Bid amount must be higher than current bid of ₹" . number_format($auction['current_bid'], 2);
            } else {
                // Start transaction
                $conn->beginTransaction();
                
                // Insert bid with product_id for foreign key constraint
                $stmt = $conn->prepare("INSERT INTO bids (product_id, auction_id, buyer_id, bid_amount, quantity, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
                $stmt->execute([$auction['product_id'], $auction_id, $buyer_id, $bid_amount]);
                
                // Update auction current bid
                $stmt = $conn->prepare("UPDATE auctions SET current_bid = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$bid_amount, $auction_id]);
                
                $conn->commit();
                $_SESSION['bid_success'] = "Bid placed successfully! Your bid: ₹" . number_format($bid_amount, 2);
            }
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        $_SESSION['bid_error'] = "Failed to place bid. Please try again. Error: " . $e->getMessage();
    }
    header('Location: auctions.php');
    exit();
}

$active_auctions = getActiveAuctions($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Auctions - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .auctions-container {
            max-width: 1200px;
            margin: 80px auto;
            padding: 20px;
        }
        
        .auctions-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .auctions-header h1 {
            color: #264653;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .auctions-header p {
            color: #666;
            font-size: 1.1rem;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
        
        .alert-danger {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        
        .auction-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
            margin-top: 30px;
        }

        .auction-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .auction-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        
        .auction-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }

        .auction-card .title {
            font-size: 1.3rem;
            margin: 0;
            font-weight: 600;
        }
        
        .auction-body {
            padding: 20px;
        }

        .auction-details {
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .detail-item:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #666;
            font-weight: 500;
        }
        
        .detail-value {
            color: #264653;
            font-weight: 600;
        }

        .current-bid {
            background: #f0fdf4;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            border: 2px solid #bbf7d0;
        }
        
        .current-bid .label {
            color: #166534;
            font-size: 14px;
            font-weight: 500;
        }
        
        .current-bid .amount {
            color: #16a34a;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 5px 0;
        }
        
        .time-remaining {
            background: #fff3cd;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 15px;
            border: 1px solid #ffeaa7;
        }
        
        .time-remaining .label {
            color: #856404;
            font-size: 12px;
        }
        
        .time-remaining .time {
            color: #856404;
            font-weight: 600;
        }

        .bid-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-top: 3px solid #2a9d8f;
        }
        
        .bid-form h4 {
            color: #264653;
            margin: 0 0 15px 0;
            font-size: 1rem;
        }
        
        .bid-input-group {
            display: flex;
            gap: 10px;
            align-items: stretch;
        }

        .bid-form input[type="number"] {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .bid-form input[type="number"]:focus {
            outline: none;
            border-color: #2a9d8f;
        }

        .bid-form button {
            padding: 12px 24px;
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .bid-form button:hover {
            background: linear-gradient(135deg, #219f8b, #1e8a7a);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(42, 157, 143, 0.4);
        }
        
        .no-auctions {
            text-align: center;
            padding: 80px 20px;
            color: #666;
        }
        
        .no-auctions i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-auctions h3 {
            color: #264653;
            margin-bottom: 10px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #2a9d8f;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 30px;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        /* Farmer Contact Section */
        .farmer-contact {
            background: #f0f9ff;
            padding: 20px;
            border-radius: 10px;
            border-top: 3px solid #0ea5e9;
            margin-top: 15px;
        }
        
        .farmer-contact h4 {
            color: #264653;
            margin: 0 0 15px 0;
            font-size: 1rem;
        }
        
        .contact-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-chat {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-chat:hover {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(14, 165, 233, 0.4);
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
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .chat-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-header h4 {
            margin: 0;
            font-size: 1.2rem;
        }
        
        .chat-header small {
            display: block;
            opacity: 0.8;
            margin-top: 5px;
        }
        
        .chat-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: background 0.3s ease;
        }
        
        .chat-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .chat-body {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #f8f9fa;
        }
        
        .chat-input {
            padding: 20px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
            background: white;
            border-radius: 0 0 15px 15px;
        }
        
        .chat-input input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .chat-input input:focus {
            outline: none;
            border-color: #2a9d8f;
        }
        
        .chat-input button {
            background: #2a9d8f;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .chat-input button:hover {
            background: #219f8b;
            transform: scale(1.05);
        }
        
        .message {
            margin-bottom: 15px;
            padding: 10px 15px;
            border-radius: 15px;
            max-width: 70%;
            word-wrap: break-word;
        }
        
        .message.buyer {
            background: #2a9d8f;
            color: white;
            margin-left: auto;
            text-align: right;
        }
        
        .message.farmer {
            background: white;
            color: #333;
            margin-right: auto;
            border: 1px solid #e0e0e0;
        }
        
        .message-time {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .auctions-container {
                margin: 20px;
                padding: 15px;
            }
            
            .auction-grid {
                grid-template-columns: 1fr;
            }
            
            .auctions-header h1 {
                font-size: 2rem;
            }
            
            .chat-modal-content {
                width: 95%;
                height: 80%;
            }
            
            .contact-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="auctions-container">
        <!-- Back to Dashboard -->
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <!-- Page Header -->
        <div class="auctions-header">
            <h1><i class="fas fa-gavel"></i> Live Auctions</h1>
            <p>Bid on fresh crops directly from farmers across India</p>
        </div>
        
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['bid_error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['bid_error']; unset($_SESSION['bid_error']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['bid_success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['bid_success']; unset($_SESSION['bid_success']); ?>
            </div>
        <?php endif; ?>

        <!-- Auctions Grid -->
        <?php if (!empty($active_auctions)): ?>
            <div class="auction-grid">
                <?php foreach ($active_auctions as $auction): ?>
                    <div class="auction-card">
                        <div class="auction-header">
                            <h3 class="title"><?php echo htmlspecialchars($auction['title']); ?></h3>
                        </div>
                        
                        <div class="auction-body">
                            <!-- Auction Details -->
                            <div class="auction-details">
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-leaf"></i> Crop:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($auction['crop_name']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-user"></i> Farmer:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($auction['farmer_username']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-home"></i> Farm:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($auction['farm_name']); ?></span>
                                </div>
                            </div>
                            
                            <!-- Time Remaining -->
                            <div class="time-remaining">
                                <div class="label">Auction Ends</div>
                                <div class="time" data-end-time="<?php echo $auction['end_time']; ?>">
                                    <!-- Timer handled by JavaScript -->
                                </div>
                            </div>
                            
                            <!-- Current Bid -->
                            <div class="current-bid">
                                <div class="label">Current Highest Bid</div>
                                <div class="amount">₹<?php echo number_format($auction['current_bid'], 2); ?></div>
                            </div>
                            
                            <!-- Bid Form -->
                            <div class="bid-form">
                                <h4><i class="fas fa-gavel"></i> Place Your Bid</h4>
                                <form action="" method="post">
                                    <input type="hidden" name="action" value="bid">
                                    <input type="hidden" name="auction_id" value="<?php echo $auction['id']; ?>">
                                    <div class="bid-input-group">
                                        <input type="number" 
                                               name="bid_amount" 
                                               min="<?php echo $auction['current_bid'] + 1; ?>" 
                                               placeholder="Enter bid amount (₹)" 
                                               step="0.01"
                                               required>
                                        <button type="submit">
                                            <i class="fas fa-hammer"></i> Bid Now
                                        </button>
                                    </div>
                                </form>
                                <small style="color: #666; font-size: 12px; margin-top: 8px; display: block;">
                                    Minimum bid: ₹<?php echo number_format($auction['current_bid'] + 1, 2); ?>
                                </small>
                            </div>
                            
                            <!-- Chat with Farmer -->
                            <div class="farmer-contact">
                                <h4><i class="fas fa-comments"></i> Contact Farmer</h4>
                                <div class="contact-actions">
                                    <button class="btn btn-chat" onclick="openChatWithFarmer(<?php echo $auction['id']; ?>, '<?php echo htmlspecialchars($auction['farmer_username']); ?>', '<?php echo htmlspecialchars($auction['farm_name']); ?>')">
                                        <i class="fas fa-comments"></i> Chat with <?php echo htmlspecialchars($auction['farmer_username']); ?>
                                    </button>
                                </div>
                                <small style="color: #666; font-size: 12px; margin-top: 8px; display: block;">
                                    Discuss product details, quality, delivery terms
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-auctions">
                <i class="fas fa-gavel"></i>
                <h3>No Live Auctions</h3>
                <p>There are no active auctions at the moment. Check back later for new opportunities!</p>
                <a href="dashboard.php" class="btn btn-primary" style="margin-top: 20px;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Auto-refresh script -->
    <script>
        // Auto-refresh every 30 seconds to get latest bids
        // Countdown timer functionality for each auction
        const updateCountdowns = () => {
            document.querySelectorAll('.time-remaining .time').forEach(el => {
                const auctionEnd = new Date(el.getAttribute('data-end-time'));
                const diff = auctionEnd - new Date();
                if (diff >= 0) {
                    const hours = Math.floor(diff / 1000 / 60 / 60);
                    const minutes = Math.floor((diff / 1000 / 60) % 60);
                    const seconds = Math.floor((diff / 1000) % 60);
                    el.innerHTML = `${hours}h ${minutes}m ${seconds}s`;
                } else {
                    el.innerHTML = 'Auction Ended';
                }
            });
        };
        setInterval(updateCountdowns, 1000);

        // Function to update auction data using AJAX
        const updateAuctions = () => {
            fetch('api/auction_api.php?action=fetch_live_auctions')
                .then(response => response.json())
                .then(data => {
                    data.auctions.forEach(auction => {
                        document.querySelector(`#auction-${auction.id} .current-bid .amount`).textContent = `₹${auction.current_bid.toFixed(2)}`;
                    });
                });
        };
        setInterval(updateAuctions, 15000);  // Update every 15 seconds
        
        // Add countdown timers for each auction
        document.addEventListener('DOMContentLoaded', function() {
            // This would be enhanced with real-time countdown timers
            console.log('Auctions page loaded with ' + <?php echo count($active_auctions); ?> + ' active auctions');
        });
    </script>
    
    <!-- Chat Modal -->
    <div id="chatModal" class="chat-modal">
        <div class="chat-modal-content">
            <div class="chat-header">
                <div>
                    <h4 id="chatFarmerName">Chat with Farmer</h4>
                    <small id="chatAuctionInfo">Auction Discussion</small>
                </div>
                <button class="chat-close" onclick="closeChatWithFarmer()">&times;</button>
            </div>
            <div class="chat-body" id="chatMessages">
                <div style="text-align: center; color: #666; padding: 20px;">
                    <i class="fas fa-comments" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                    <p>Start a conversation with the farmer</p>
                </div>
            </div>
            <div class="chat-input">
                <input type="text" id="messageInput" placeholder="Type your message..." onkeypress="handleChatKeyPress(event)">
                <button onclick="sendMessageToFarmer()"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
    </div>
    
    <script>
    let currentAuctionId = null;
    let currentFarmerName = null;
    let currentFarmName = null;
    
    function openChatWithFarmer(auctionId, farmerName, farmName) {
        console.log('Opening chat with farmer:', farmerName, 'for auction:', auctionId);
        
        currentAuctionId = auctionId;
        currentFarmerName = farmerName;
        currentFarmName = farmName;
        
        try {
            document.getElementById('chatFarmerName').textContent = `Chat with ${farmerName}`;
            document.getElementById('chatAuctionInfo').textContent = `${farmName} - Auction #${auctionId}`;
            
            const modal = document.getElementById('chatModal');
            if (modal) {
                modal.style.display = 'block';
                console.log('Modal opened successfully');
            } else {
                console.error('Chat modal element not found!');
                return;
            }
            
            // Load existing messages
            loadChatMessagesWithFarmer();
            
            // Focus on input
            setTimeout(() => {
                const input = document.getElementById('messageInput');
                if (input) {
                    input.focus();
                }
            }, 100);
        } catch (error) {
            console.error('Error opening chat modal:', error);
            alert('Error opening chat. Please try again.');
        }
    }
    
    function closeChatWithFarmer() {
        document.getElementById('chatModal').style.display = 'none';
        currentAuctionId = null;
        currentFarmerName = null;
        currentFarmName = null;
    }
    
    function handleChatKeyPress(event) {
        if (event.key === 'Enter') {
            sendMessageToFarmer();
        }
    }
    
    function sendMessageToFarmer() {
        const messageInput = document.getElementById('messageInput');
        const message = messageInput.value.trim();
        
        if (!message || !currentAuctionId) return;
        
        // Add message to chat immediately (optimistic update)
        addMessageToChatUI('buyer', message, new Date());
        messageInput.value = '';
        
        // Send message via AJAX
        const formData = new FormData();
        formData.append('action', 'send_message_to_farmer');
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
                // You could show an error message to the user here
            }
        })
        .catch(error => {
            console.error('Error sending message:', error);
        });
    }
    
    function loadChatMessagesWithFarmer() {
        if (!currentAuctionId) return;
        
        const formData = new FormData();
        formData.append('action', 'get_messages_for_auction');
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
                            <p>Start a conversation with the farmer</p>
                        </div>
                    `;
                } else {
                    data.messages.forEach(msg => {
                        addMessageToChatUI(msg.sender_type, msg.message, new Date(msg.created_at));
                    });
                }
            }
        })
        .catch(error => {
            console.error('Error loading messages:', error);
        });
    }
    
    function addMessageToChatUI(senderType, message, timestamp) {
        const chatMessages = document.getElementById('chatMessages');
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${senderType}`;
        
        messageDiv.innerHTML = `
            <div>${message}</div>
            <div class="message-time">
                ${timestamp.toLocaleTimeString('en-IN', {hour: '2-digit', minute: '2-digit'})}
            </div>
        `;
        
        chatMessages.appendChild(messageDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Auto-refresh chat messages every 5 seconds when chat is open
    setInterval(() => {
        if (currentAuctionId && document.getElementById('chatModal').style.display === 'block') {
            loadChatMessagesWithFarmer();
        }
    }, 5000);
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('chatModal');
        if (event.target === modal) {
            closeChatWithFarmer();
        }
    }
    </script>
</body>
</html>
