<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Get conversation partner ID from URL
$partner_id = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$product_id = isset($_GET['product']) ? (int)$_GET['product'] : null;

if (!$partner_id) {
    header('Location: ' . ($user_type === 'farmer' ? 'farmer/dashboard.php' : 'buyer/dashboard.php'));
    exit();
}

// Get partner information
$stmt = $conn->prepare("SELECT username, user_type FROM users WHERE id = ?");
$stmt->execute([$partner_id]);
$partner = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$partner) {
    header('Location: ' . ($user_type === 'farmer' ? 'farmer/dashboard.php' : 'buyer/dashboard.php'));
    exit();
}

// Get product information if specified
$product = null;
if ($product_id) {
    $stmt = $conn->prepare("
        SELECT p.*, c.name as crop_name, f.farm_name 
        FROM product_listings p 
        JOIN crops c ON p.crop_id = c.id 
        JOIN farmers f ON p.farmer_id = f.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle message sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    if (!empty($message)) {
        $stmt = $conn->prepare("
            INSERT INTO messages (sender_id, receiver_id, product_id, message, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $partner_id, $product_id, $message]);
    }
    header('Location: chat.php?user=' . $partner_id . ($product_id ? '&product=' . $product_id : ''));
    exit();
}

// Get messages for this conversation
$stmt = $conn->prepare("
    SELECT m.*, u.username as sender_username 
    FROM messages m 
    JOIN users u ON m.sender_id = u.id 
    WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
    " . ($product_id ? " AND (m.product_id = ? OR m.product_id IS NULL)" : "") . "
    ORDER BY m.created_at ASC
");

if ($product_id) {
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id, $product_id]);
} else {
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id]);
}

$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mark messages as read
$stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
$stmt->execute([$partner_id, $user_id]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with <?php echo htmlspecialchars($partner['username']); ?> - Farmer Marketplace</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .chat-container {
            max-width: 800px;
            margin: 80px auto;
            padding: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            height: calc(100vh - 160px);
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            padding: 20px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-partner-info h2 {
            color: #264653;
            margin: 0;
            font-size: 1.5rem;
        }
        
        .chat-partner-info .partner-type {
            color: #666;
            font-size: 14px;
            text-transform: capitalize;
        }
        
        .product-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #2a9d8f;
        }
        
        .product-info h4 {
            color: #264653;
            margin: 0 0 5px 0;
        }
        
        .product-info p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }
        
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .message {
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
        }
        
        .message.own {
            align-items: flex-end;
        }
        
        .message.other {
            align-items: flex-start;
        }
        
        .message-content {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            word-wrap: break-word;
        }
        
        .message.own .message-content {
            background: #2a9d8f;
            color: white;
        }
        
        .message.other .message-content {
            background: white;
            color: #264653;
            border: 1px solid #e0e0e0;
        }
        
        .message-time {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .message-input-container {
            padding: 20px;
            border-top: 2px solid #f0f0f0;
        }
        
        .message-input-form {
            display: flex;
            gap: 10px;
        }
        
        .message-input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            outline: none;
            resize: none;
            font-family: inherit;
        }
        
        .message-input:focus {
            border-color: #2a9d8f;
        }
        
        .send-button {
            background: #2a9d8f;
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        
        .send-button:hover {
            background: #219f8b;
        }
        
        .negotiation-tools {
            background: #fff3cd;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #ffeaa7;
        }
        
        .negotiation-tools h4 {
            color: #856404;
            margin: 0 0 10px 0;
            font-size: 1rem;
        }
        
        .price-suggestion {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .price-input {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 120px;
        }
        
        .quantity-input {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 100px;
        }
        
        .suggest-btn {
            background: #856404;
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .quick-responses {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        
        .quick-response {
            background: #e9ecef;
            border: none;
            padding: 5px 12px;
            border-radius: 15px;
            cursor: pointer;
            font-size: 12px;
            color: #495057;
        }
        
        .quick-response:hover {
            background: #dee2e6;
        }
        
        .back-link {
            color: #2a9d8f;
            text-decoration: none;
            font-size: 14px;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .chat-container {
                margin: 20px;
                height: calc(100vh - 40px);
            }
            
            .message-content {
                max-width: 85%;
            }
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <div class="chat-partner-info">
                <h2><i class="fas fa-<?php echo $partner['user_type'] === 'farmer' ? 'seedling' : 'shopping-cart'; ?>"></i> 
                    <?php echo htmlspecialchars($partner['username']); ?>
                </h2>
                <div class="partner-type"><?php echo ucfirst($partner['user_type']); ?></div>
            </div>
            <div>
                <a href="<?php echo $user_type === 'farmer' ? 'farmer/dashboard.php' : 'buyer/dashboard.php'; ?>" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
        
        <?php if ($product): ?>
        <div class="product-info">
            <h4><i class="fas fa-leaf"></i> <?php echo htmlspecialchars($product['title']); ?></h4>
            <p>
                <strong>Crop:</strong> <?php echo htmlspecialchars($product['crop_name']); ?> | 
                <strong>Price:</strong> ₹<?php echo number_format($product['price_per_unit'], 2); ?>/<?php echo $product['unit']; ?> | 
                <strong>Available:</strong> <?php echo number_format($product['quantity_available']); ?> <?php echo $product['unit']; ?>
            </p>
        </div>
        <?php endif; ?>
        
        <?php if ($product && $user_type === 'buyer'): ?>
        <div class="negotiation-tools">
            <h4><i class="fas fa-handshake"></i> Negotiation Tools</h4>
            <div class="price-suggestion">
                <input type="number" class="price-input" id="suggestedPrice" placeholder="Price" step="0.01">
                <input type="number" class="quantity-input" id="suggestedQuantity" placeholder="Quantity" step="0.1">
                <button class="suggest-btn" onclick="suggestPrice()">Suggest Price</button>
            </div>
            <div class="quick-responses">
                <button class="quick-response" onclick="addQuickResponse('Interested in bulk purchase')">Bulk Purchase</button>
                <button class="quick-response" onclick="addQuickResponse('Can you provide quality certificate?')">Quality Certificate</button>
                <button class="quick-response" onclick="addQuickResponse('What are your delivery terms?')">Delivery Terms</button>
                <button class="quick-response" onclick="addQuickResponse('Can we schedule a farm visit?')">Farm Visit</button>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="messages-container" id="messagesContainer">
            <?php foreach ($messages as $message): ?>
            <div class="message <?php echo $message['sender_id'] == $user_id ? 'own' : 'other'; ?>">
                <div class="message-content">
                    <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                </div>
                <div class="message-time">
                    <?php echo date('M j, Y g:i A', strtotime($message['created_at'])); ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($messages)): ?>
            <div style="text-align: center; color: #666; padding: 40px;">
                <i class="fas fa-comments" style="font-size: 3rem; margin-bottom: 20px; opacity: 0.5;"></i>
                <p>Start the conversation by sending a message below.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="message-input-container">
            <form method="post" class="message-input-form">
                <textarea name="message" class="message-input" placeholder="Type your message..." rows="1" required id="messageInput"></textarea>
                <button type="submit" class="send-button">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
    </div>
    
    <script>
        let lastMessageId = <?php echo end($messages)['id'] ?? 0; ?>;

        function scrollToBottom() {
            const container = document.getElementById('messagesContainer');
            container.scrollTop = container.scrollHeight;
        }

        function renderMessage(msg) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'message ' + (msg.sender_id == <?php echo $user_id; ?> ? 'own' : 'other');
            
            const messageContent = document.createElement('div');
            messageContent.className = 'message-content';
            messageContent.innerHTML = msg.message.replace(/\n/g, '<br>');
            
            const messageTime = document.createElement('div');
            messageTime.className = 'message-time';
            messageTime.textContent = new Date(msg.created_at).toLocaleTimeString('en-US', { hour: 'numeric', minute: 'numeric', hour12: true });

            messageDiv.appendChild(messageContent);
            messageDiv.appendChild(messageTime);
            
            document.getElementById('messagesContainer').appendChild(messageDiv);
        }

        function fetchMessages() {
            const url = `api/messages.php?user=<?php echo $partner_id; ?>&product=<?php echo $product_id; ?>&last_message_id=${lastMessageId}`;
            fetch(url)
                .then(response => response.json())
                .then(newMessages => {
                    if (newMessages.length > 0) {
                        newMessages.forEach(renderMessage);
                        lastMessageId = newMessages[newMessages.length - 1].id;
                        scrollToBottom();
                    }
                });
        }

        document.querySelector('.message-input-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const messageInput = document.getElementById('messageInput');
            const message = messageInput.value.trim();
            
            if (message) {
                const formData = new FormData();
                formData.append('message', message);
                
                fetch(`api/messages.php?user=<?php echo $partner_id; ?>&product=<?php echo $product_id; ?>`, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageInput.value = '';
                        fetchMessages();
                    }
                });
            }
        });

        // Auto-scroll to bottom of messages
        window.onload = function() {
            scrollToBottom();
        };
        
        // Auto-resize textarea
        const messageInput = document.getElementById('messageInput');
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
        
        // Submit form on Enter (but not Shift+Enter)
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.closest('form').submit();
            }
        });
        
        // Negotiation tools functions
        function suggestPrice() {
            const price = document.getElementById('suggestedPrice').value;
            const quantity = document.getElementById('suggestedQuantity').value;
            
            if (price && quantity) {
                const message = `I'd like to offer ₹${price} per unit for ${quantity} units. Total: ₹${(price * quantity).toFixed(2)}`;
                document.getElementById('messageInput').value = message;
                document.getElementById('messageInput').focus();
            } else {
                alert('Please enter both price and quantity.');
            }
        }
        
        function addQuickResponse(text) {
            document.getElementById('messageInput').value = text;
            document.getElementById('messageInput').focus();
        }
        
        // Auto-refresh messages every 5 seconds
        setInterval(fetchMessages, 5000);
    </script>
</body>
</html>
