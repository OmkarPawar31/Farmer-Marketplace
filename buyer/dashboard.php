
<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a buyer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'buyer') {
    header('Location: login.php');
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];

// Initialize default values
$stats = ['total_orders' => 0, 'total_spent' => 0, 'pending_orders' => 0, 'unique_suppliers' => 0];
$recent_orders = [];
$available_products = [];
$pending_bids = [];

try {
    // Get buyer statistics
    $stmt = $conn->prepare("
        SELECT 
            (SELECT count(*) FROM orders o JOIN buyers b ON o.buyer_id = b.id WHERE b.user_id = ?) as total_orders,
            (SELECT COALESCE(SUM(total_amount), 0) FROM orders o JOIN buyers b ON o.buyer_id = b.id WHERE b.user_id = ?) as total_spent,
            (SELECT count(*) FROM orders o JOIN buyers b ON o.buyer_id = b.id WHERE b.user_id = ? AND o.order_status = 'pending') as pending_orders,
            (SELECT count(DISTINCT o.farmer_id) FROM orders o JOIN buyers b ON o.buyer_id = b.id WHERE b.user_id = ?) as unique_suppliers
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $stats = $result;
    }
} catch (Exception $e) {
    // Log error or handle it appropriately
    error_log("Dashboard stats query error: " . $e->getMessage());
}

try {
    // Get recent orders
    $stmt = $conn->prepare("
        SELECT o.*, p.title as product_title, f.farm_name, u.username as farmer_username, u.id as farmer_user_id,
               c.name as crop_name, o.created_at as order_date
        FROM orders o 
        JOIN buyers b ON o.buyer_id = b.id 
        JOIN product_listings p ON o.product_id = p.id
        JOIN farmers f ON o.farmer_id = f.id
        JOIN users u ON f.user_id = u.id
        JOIN crops c ON p.crop_id = c.id
        WHERE b.user_id = ? 
        ORDER BY o.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Dashboard recent orders query error: " . $e->getMessage());
}

try {
    // Get available products for procurement
    $stmt = $conn->prepare("
        SELECT p.*, c.name as crop_name, cc.name as category_name, f.farm_name, 
               u.username as farmer_username, f.state, f.district
        FROM product_listings p 
        JOIN crops c ON p.crop_id = c.id
        LEFT JOIN crop_categories cc ON c.category_id = cc.id
        JOIN farmers f ON p.farmer_id = f.id
        JOIN users u ON f.user_id = u.id
        WHERE p.status = 'active' AND p.quantity_available > 0
        ORDER BY p.created_at DESC 
        LIMIT 12
    ");
    $stmt->execute();
    $available_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Dashboard available products query error: " . $e->getMessage());
}

try {
    // Get pending bids (updated to work with auctions)
    $stmt = $conn->prepare("
        SELECT b.*, a.product_id, p.title as product_title, f.farm_name, u.username as farmer_username,
               b.bid_amount, b.created_at
        FROM bids b
        JOIN buyers buyer ON b.buyer_id = buyer.id
        JOIN auctions a ON b.auction_id = a.id
        JOIN product_listings p ON a.product_id = p.id
        JOIN farmers f ON p.farmer_id = f.id
        JOIN users u ON f.user_id = u.id
        WHERE buyer.user_id = ? AND a.status = 'active'
        ORDER BY b.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $pending_bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Dashboard pending bids query error: " . $e->getMessage());
    // If bids table doesn't exist or has different structure, keep it empty
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Dashboard - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .dashboard-container {
            max-width: 1400px;
            margin: 80px auto;
            padding: 20px;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-info h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .company-info {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #2a9d8f, #e76f51);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #264653;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .dashboard-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #264653;
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .product-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
        }
        
        .product-header {
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .product-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #264653;
            margin-bottom: 5px;
        }
        
        .product-farmer {
            color: #666;
            font-size: 14px;
        }
        
        .product-details {
            padding: 20px;
        }
        
        .product-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2a9d8f;
            margin-bottom: 10px;
        }
        
        .product-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .info-item {
            font-size: 14px;
        }
        
        .info-label {
            color: #666;
            font-weight: 500;
        }
        
        .info-value {
            color: #264653;
        }
        
        .product-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 14px;
            border-radius: 6px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
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
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-pending { background: #fff7ed; color: #f59e0b; }
        .status-confirmed { background: #f0f9ff; color: #3b82f6; }
        .status-delivered { background: #f0fdf4; color: #16a34a; }
        .status-cancelled { background: #fef2f2; color: #ef4444; }
        
        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 157, 143, 0.4);
        }
        
        .action-btn.secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
        }
        
        .verification-banner {
            background: #fff3cd;
            color: #856404;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #ffeaa7;
        }
        
        .bid-item {
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .bid-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .bid-amount {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2a9d8f;
        }
        
        .bid-details {
            font-size: 14px;
            color: #666;
        }
        
        /* Order Modal Styles */
        .order-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
        }
        
        .modal-header {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #264653;
            margin-bottom: 10px;
        }
        
        .product-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }
        
        .info-row label {
            font-weight: 500;
            color: #666;
        }
        
        .info-row span {
            font-weight: 600;
            color: #264653;
        }
        
        .order-form {
            margin-top: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #264653;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #2a9d8f;
        }
        
        .total-display {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 25px;
        }
        
        .total-amount {
            font-size: 2rem;
            font-weight: 700;
            color: #2a9d8f;
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }
        
        .modal-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 157, 143, 0.4);
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        .close-btn {
            position: absolute;
            top: 20px;
            right: 25px;
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
        }
        
        .close-btn:hover {
            color: #000;
        }

        @media (max-width: 968px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-header {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="header-info">
                <h1><i class="fas fa-shopping-cart"></i> Welcome, <?php echo htmlspecialchars($_SESSION['company_name']); ?></h1>
                <div class="company-info">
                    <p><?php echo ucfirst($_SESSION['business_type']); ?> • <?php echo htmlspecialchars($_SESSION['username']); ?></p>
                    <?php if ($_SESSION['verification_status'] === 'pending'): ?>
                        <p><i class="fas fa-clock"></i> Account verification pending</p>
                    <?php elseif ($_SESSION['verification_status'] === 'verified'): ?>
                        <p><i class="fas fa-check-circle"></i> Verified Business</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="header-actions">
                <a href="marketplace.php" class="action-btn">
                    <i class="fas fa-search"></i> Browse Products
                </a>
                <a href="orders.php" class="action-btn secondary">
                    <i class="fas fa-list"></i> My Orders
                </a>
                <a href="../logout.php" class="action-btn secondary">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <?php if ($_SESSION['verification_status'] === 'unverified'): ?>
        <div class="verification-banner">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Account Verification Required:</strong> Please complete your business verification to access all features. 
            <a href="verification.php" style="color: #856404; text-decoration: underline;">Complete Verification</a>
        </div>
        <?php endif; ?>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['total_orders']); ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-rupee-sign"></i>
                </div>
                <div class="stat-number">₹<?php echo number_format($stats['total_spent'], 0); ?></div>
                <div class="stat-label">Total Spent</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['pending_orders']); ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['unique_suppliers']); ?></div>
                <div class="stat-label">Suppliers</div>
            </div>
        </div>

        <!-- Main Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Recent Orders -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-history"></i> Recent Orders</h2>
                    <a href="orders.php" class="btn btn-outline btn-small">View All</a>
                </div>
                
                <?php if (empty($recent_orders)): ?>
                    <p style="text-align: center; color: #666; padding: 40px;">No orders yet. <a href="marketplace.php">Start browsing products</a></p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Farmer</th>
                                <th>Quantity</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($order['product_title']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($order['crop_name']); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($order['farm_name']); ?><br>
                                    <small><?php echo htmlspecialchars($order['farmer_username']); ?></small>
                                </td>
                                <td><?php echo number_format($order['quantity'], 1); ?> <?php echo $order['unit'] ?? 'kg'; ?></td>
                                <td>₹<?php echo number_format($order['total_amount'], 0); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $order['order_status']; ?>">
                                        <?php echo ucfirst($order['order_status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d M Y', strtotime($order['order_date'])); ?></td>
                                <td>
                                    <a href="../chat.php?user=<?php echo $order['farmer_user_id']; ?>&product=<?php echo $order['product_id']; ?>" class="action-btn" style="background: #e76f51; padding: 5px 10px; font-size: 12px;">
                                        <i class="fas fa-comments"></i> Chat
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Pending Bids -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-gavel"></i> Pending Bids</h2>
                    <a href="bids.php" class="btn btn-outline btn-small">View All</a>
                </div>
                
                <?php if (empty($pending_bids)): ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No pending bids</p>
                <?php else: ?>
                    <?php foreach ($pending_bids as $bid): ?>
                    <div class="bid-item">
                        <div class="bid-header">
                            <div class="bid-amount">₹<?php echo number_format($bid['bid_amount'], 2); ?></div>
                            <small><?php echo date('d M Y', strtotime($bid['created_at'])); ?></small>
                        </div>
                        <div class="bid-details">
                            <strong><?php echo htmlspecialchars($bid['product_title']); ?></strong><br>
                            Quantity: <?php echo number_format($bid['quantity']); ?> kg<br>
                            Farmer: <?php echo htmlspecialchars($bid['farmer_username']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Available Products for Procurement -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-leaf"></i> Available Products</h2>
                <a href="marketplace.php" class="btn btn-outline btn-small">View All Products</a>
            </div>
            
            <div class="product-grid">
                <?php foreach ($available_products as $product): ?>
                <div class="product-card">
                    <div class="product-header">
                        <div class="product-title"><?php echo htmlspecialchars($product['title']); ?></div>
                        <div class="product-farmer">
                            <i class="fas fa-map-marker-alt"></i> 
                            <?php echo htmlspecialchars($product['farm_name']); ?>, 
                            <?php echo htmlspecialchars($product['district']); ?>
                        </div>
                    </div>
                    <div class="product-details">
                        <div class="product-price">₹<?php echo number_format($product['price_per_unit'], 2); ?>/<?php echo $product['unit']; ?></div>
                        
                        <div class="product-info">
                            <div class="info-item">
                                <div class="info-label">Available</div>
                                <div class="info-value"><?php echo number_format($product['quantity_available']); ?> <?php echo $product['unit']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Quality</div>
                                <div class="info-value">Grade <?php echo $product['quality_grade']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Harvest Date</div>
                                <div class="info-value"><?php echo $product['harvest_date'] ? date('d M Y', strtotime($product['harvest_date'])) : 'N/A'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Organic</div>
                                <div class="info-value"><?php echo $product['organic_certified'] ? 'Yes' : 'No'; ?></div>
                            </div>
                        </div>
                        
                        <div class="product-actions">
                            <button class="btn btn-primary btn-small" onclick="showBidModal(<?php echo $product['id']; ?>)">
                                <i class="fas fa-gavel"></i> Bid
                            </button>
                            <button class="btn btn-secondary btn-small" onclick="showOrderModal(<?php echo $product['id']; ?>)">
                                <i class="fas fa-shopping-cart"></i> Order
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="marketplace.php" class="action-btn">
                <i class="fas fa-search"></i> Browse All Products
            </a>
            <a href="bulk-orders.php" class="action-btn">
                <i class="fas fa-boxes"></i> Bulk Orders
            </a>
            <a href="suppliers.php" class="action-btn">
                <i class="fas fa-handshake"></i> Manage Suppliers
            </a>
            <a href="contracts.php" class="action-btn">
                <i class="fas fa-file-contract"></i> Contract Farming
            </a>
            <a href="quality-reports.php" class="action-btn">
                <i class="fas fa-clipboard-check"></i> Quality Reports
            </a>
            <a href="analytics.php" class="action-btn">
                <i class="fas fa-chart-line"></i> Analytics
            </a>
        </div>
    </div>

    <!-- Order Modal -->
    <div id="orderModal" class="order-modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeOrderModal()">&times;</span>
            <div class="modal-header">
                <h2 class="modal-title">Place Direct Order</h2>
                <p style="color: #666; margin: 0;">Order directly from farmer at listed price</p>
            </div>
            
            <div class="product-info-grid">
                <div class="info-row">
                    <label>Product:</label>
                    <span id="orderProductTitle"></span>
                </div>
                <div class="info-row">
                    <label>Farmer:</label>
                    <span id="orderFarmerName"></span>
                </div>
                <div class="info-row">
                    <label>Price:</label>
                    <span id="orderPrice"></span> per <span id="orderUnit"></span>
                </div>
                <div class="info-row">
                    <label>Available:</label>
                    <span id="orderAvailable"></span>
                </div>
                <div class="info-row">
                    <label>Min. Order:</label>
                    <span id="orderMinQuantity"></span>
                </div>
                <div class="info-row">
                    <label>Quality:</label>
                    <span id="orderQuality">Grade A</span>
                </div>
            </div>
            
            <form id="orderForm" class="order-form" onsubmit="event.preventDefault(); submitOrder();">
                <div class="form-group">
                    <label for="orderQuantity">Quantity Required:</label>
                    <input type="number" id="orderQuantity" name="quantity" min="1" step="0.1" 
                           placeholder="Enter quantity" onchange="calculateTotal()" required>
                </div>
                
                <div class="form-group">
                    <label for="deliveryAddress">Delivery Address:</label>
                    <input type="text" id="deliveryAddress" name="delivery_address" 
                           placeholder="Enter delivery address" required>
                </div>
                
                <div class="total-display">
                    <div style="color: #666; margin-bottom: 5px;">Total Amount</div>
                    <div class="total-amount" id="orderTotal">₹0.00</div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="modal-btn btn-cancel" onclick="closeOrderModal()">Cancel</button>
                    <button type="submit" class="modal-btn btn-submit" id="submitOrderBtn">
                        <i class="fas fa-shopping-cart"></i> Place Order
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showBidModal(productId) {
            // Redirect to auctions page where bidding functionality is available
            window.location.href = 'auctions.php';
        }
        
        let currentProductId = null;
        let currentProduct = null;
        
        function showOrderModal(productId) {
            currentProductId = productId;
            
            // Find product details from the available products
            const products = <?php echo json_encode($available_products); ?>;
            currentProduct = products.find(p => p.id == productId);
            
            if (currentProduct) {
                // Update modal with product details
                document.getElementById('orderProductTitle').textContent = currentProduct.title;
                document.getElementById('orderFarmerName').textContent = currentProduct.farm_name;
                document.getElementById('orderPrice').textContent = '₹' + parseFloat(currentProduct.price_per_unit).toFixed(2);
                document.getElementById('orderUnit').textContent = currentProduct.unit;
                document.getElementById('orderAvailable').textContent = parseFloat(currentProduct.quantity_available).toFixed(1) + ' ' + currentProduct.unit;
                document.getElementById('orderMinQuantity').textContent = currentProduct.minimum_order || '1';
                document.getElementById('orderQuality').textContent = 'Grade ' + (currentProduct.quality_grade || 'A');
                
                // Set max quantity and defaults
                document.getElementById('orderQuantity').max = currentProduct.quantity_available;
                document.getElementById('orderQuantity').value = currentProduct.minimum_order || 1;
                
                // Calculate initial total
                calculateTotal();
                
                // Show modal
                document.getElementById('orderModal').style.display = 'block';
            }
        }
        
        function closeOrderModal() {
            document.getElementById('orderModal').style.display = 'none';
            document.getElementById('orderForm').reset();
        }
        
        function calculateTotal() {
            const quantity = parseFloat(document.getElementById('orderQuantity').value) || 0;
            const price = parseFloat(currentProduct.price_per_unit);
            const total = quantity * price;
            
            document.getElementById('orderTotal').textContent = '₹' + total.toFixed(2);
        }
        
        function submitOrder() {
            const form = document.getElementById('orderForm');
            const formData = new FormData(form);
            formData.append('product_id', currentProductId);
            formData.append('action', 'place_order');
            
            // Show loading state
            const submitBtn = document.getElementById('submitOrderBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
            
            fetch('process_order.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Order placed successfully! Order ID: ' + data.order_id);
                    closeOrderModal();
                    // Refresh the page to update the stats
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while placing the order.');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('orderModal');
            if (event.target == modal) {
                closeOrderModal();
            }
        }
    </script>
</body>
</html>
