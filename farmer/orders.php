<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a farmer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'farmer') {
    header('Location: login.php');
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];

// Pagination settings
$orders_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $orders_per_page;

// Status filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$status_condition = $status_filter !== 'all' ? "AND o.order_status = :status" : "";

// Date range filter
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$date_condition = '';
if ($date_from && $date_to) {
    $date_condition = "AND DATE(o.created_at) BETWEEN :date_from AND :date_to";
} elseif ($date_from) {
    $date_condition = "AND DATE(o.created_at) >= :date_from";
} elseif ($date_to) {
    $date_condition = "AND DATE(o.created_at) <= :date_to";
}

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM orders o 
    JOIN farmers f ON o.farmer_id = f.id 
    WHERE f.user_id = :user_id 
    $status_condition 
    $date_condition
";
$count_stmt = $conn->prepare($count_query);
$count_stmt->bindParam(':user_id', $user_id);
if ($status_filter !== 'all') {
    $count_stmt->bindParam(':status', $status_filter);
}
if ($date_from && $date_to) {
    $count_stmt->bindParam(':date_from', $date_from);
    $count_stmt->bindParam(':date_to', $date_to);
} elseif ($date_from) {
    $count_stmt->bindParam(':date_from', $date_from);
} elseif ($date_to) {
    $count_stmt->bindParam(':date_to', $date_to);
}
$count_stmt->execute();
$total_orders = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_orders / $orders_per_page);

// Get orders with filters and pagination
$orders_query = "
    SELECT o.*, 
           p.title as product_title, 
           p.images as product_images,
           b.company_name as buyer_name, 
           u.username as buyer_username,
           u.email as buyer_email,
           u.phone as buyer_phone,
           c.name as crop_name
    FROM orders o 
    JOIN farmers f ON o.farmer_id = f.id 
    JOIN product_listings p ON o.product_id = p.id
    JOIN buyers b ON o.buyer_id = b.id
    JOIN users u ON b.user_id = u.id
    JOIN crops c ON p.crop_id = c.id
    WHERE f.user_id = :user_id 
    $status_condition 
    $date_condition
    ORDER BY o.created_at DESC 
    LIMIT :limit OFFSET :offset
";

$orders_stmt = $conn->prepare($orders_query);
$orders_stmt->bindParam(':user_id', $user_id);
$orders_stmt->bindParam(':limit', $orders_per_page, PDO::PARAM_INT);
$orders_stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
if ($status_filter !== 'all') {
    $orders_stmt->bindParam(':status', $status_filter);
}
if ($date_from && $date_to) {
    $orders_stmt->bindParam(':date_from', $date_from);
    $orders_stmt->bindParam(':date_to', $date_to);
} elseif ($date_from) {
    $orders_stmt->bindParam(':date_from', $date_from);
} elseif ($date_to) {
    $orders_stmt->bindParam(':date_to', $date_to);
}
$orders_stmt->execute();
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get order statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN order_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_orders,
        SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
        SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
        COALESCE(SUM(farmer_earnings), 0) as total_earnings,
        COALESCE(SUM(CASE WHEN order_status = 'delivered' THEN farmer_earnings ELSE 0 END), 0) as confirmed_earnings
    FROM orders o 
    JOIN farmers f ON o.farmer_id = f.id 
    WHERE f.user_id = ?
";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute([$user_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Handle order status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $order_id = $_POST['order_id'];
    $new_status = $_POST['new_status'];
    
    // Verify that this order belongs to the current farmer
    $verify_stmt = $conn->prepare("
        SELECT o.id FROM orders o 
        JOIN farmers f ON o.farmer_id = f.id 
        WHERE o.id = ? AND f.user_id = ?
    ");
    $verify_stmt->execute([$order_id, $user_id]);
    
    if ($verify_stmt->fetch()) {
        $update_stmt = $conn->prepare("UPDATE orders SET order_status = ?, updated_at = NOW() WHERE id = ?");
        if ($update_stmt->execute([$new_status, $order_id])) {
            $success_message = "Order status updated successfully!";
        } else {
            $error_message = "Failed to update order status.";
        }
    } else {
        $error_message = "Invalid order or access denied.";
    }
    
    // Refresh the page to show updated data
    header("Location: orders.php?" . $_SERVER['QUERY_STRING']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Management - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .orders-container {
            max-width: 1400px;
            margin: 80px auto;
            padding: 20px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .page-header h1 {
            font-size: 2rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            font-size: 2rem;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #2a9d8f, #e76f51);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #264653;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            margin-bottom: 8px;
            color: #264653;
            font-weight: 500;
            font-size: 14px;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #2a9d8f;
            box-shadow: 0 0 0 3px rgba(42, 157, 143, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 157, 143, 0.4);
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #666;
            border: 1px solid #ddd;
        }
        
        .btn-secondary:hover {
            background: #e9ecef;
        }
        
        .orders-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .section-header {
            padding: 25px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #264653;
            margin: 0;
        }
        
        .orders-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .orders-table th,
        .orders-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .orders-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #264653;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .orders-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .order-id {
            font-weight: 600;
            color: #2a9d8f;
        }
        
        .product-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .product-image {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #f0f0f0;
        }
        
        .product-details {
            flex: 1;
        }
        
        .product-title {
            font-weight: 500;
            color: #264653;
            margin-bottom: 4px;
        }
        
        .product-crop {
            font-size: 12px;
            color: #666;
        }
        
        .buyer-info {
            color: #264653;
        }
        
        .buyer-company {
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .buyer-contact {
            font-size: 12px;
            color: #666;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize;
        }
        
        .status-pending {
            background: #fff7ed;
            color: #f59e0b;
        }
        
        .status-confirmed {
            background: #f0fdf4;
            color: #2a9d8f;
        }
        
        .status-delivered {
            background: #f0f9ff;
            color: #3b82f6;
        }
        
        .status-cancelled {
            background: #fef2f2;
            color: #ef4444;
        }
        
        .amount {
            font-weight: 600;
            color: #264653;
        }
        
        .earnings {
            font-size: 12px;
            color: #2a9d8f;
        }
        
        .date-info {
            color: #666;
        }
        
        .order-date {
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .order-time {
            font-size: 12px;
        }
        
        .action-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 12px;
            background: white;
            cursor: pointer;
        }
        
        .action-select:focus {
            outline: none;
            border-color: #2a9d8f;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 25px;
            border-top: 2px solid #f0f0f0;
        }
        
        .pagination a,
        .pagination span {
            padding: 10px 15px;
            border-radius: 8px;
            text-decoration: none;
            color: #666;
            border: 1px solid #ddd;
            transition: all 0.3s ease;
        }
        
        .pagination a:hover {
            background: #2a9d8f;
            color: white;
            border-color: #2a9d8f;
        }
        
        .pagination .current {
            background: #2a9d8f;
            color: white;
            border-color: #2a9d8f;
        }
        
        .no-orders {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .no-orders i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }
        
        .order-actions {
            display: flex;
            gap: 5px;
        }
        
        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-view {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .btn-view:hover {
            background: #1976d2;
            color: white;
        }
        
        @media (max-width: 768px) {
            .orders-container {
                margin: 20px 10px;
                padding: 10px;
            }
            
            .page-header {
                flex-direction: column;
                text-align: center;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .orders-table {
                font-size: 12px;
            }
            
            .orders-table th,
            .orders-table td {
                padding: 8px;
            }
            
            .product-info {
                flex-direction: column;
                text-align: center;
            }
            
            .pagination {
                flex-wrap: wrap;
            }
        }
        
        /* Order Details Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .detail-label {
            font-weight: 500;
            color: #264653;
        }
        
        .detail-value {
            color: #666;
        }
        
        .close {
            color: white;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="orders-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-receipt"></i> Orders Management</h1>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
        <!-- Order Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="stat-number"><?php echo number_format($stats['total_orders']); ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?php echo number_format($stats['pending_orders']); ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo number_format($stats['confirmed_orders']); ?></div>
                <div class="stat-label">Confirmed Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-truck"></i></div>
                <div class="stat-number"><?php echo number_format($stats['delivered_orders']); ?></div>
                <div class="stat-label">Delivered Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
                <div class="stat-number">₹<?php echo number_format($stats['total_earnings'], 2); ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-money-check-alt"></i></div>
                <div class="stat-number">₹<?php echo number_format($stats['confirmed_earnings'], 2); ?></div>
                <div class="stat-label">Confirmed Earnings</div>
            </div>
        </div>
        
        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" action="orders.php">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="status">Order Status</label>
                        <select name="status" id="status">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="date_from">From Date</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="date_to">To Date</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="orders.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Orders Section -->
        <div class="orders-section">
            <div class="section-header">
                <h2 class="section-title">All Orders (<?php echo number_format($total_orders); ?>)</h2>
                <div class="section-actions">
                    <?php if ($total_orders > 0): ?>
                        <span class="text-muted">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (count($orders) > 0): ?>
            <div style="overflow-x: auto;">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Product</th>
                            <th>Buyer</th>
                            <th>Quantity</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Order Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): 
                            $product_images = json_decode($order['product_images'], true);
                            $first_image = $product_images && count($product_images) > 0 ? $product_images[0] : null;
                        ?>
                        <tr>
                            <td>
                                <span class="order-id">#<?php echo htmlspecialchars($order['order_number']); ?></span>
                            </td>
                            <td>
                                <div class="product-info">
                                    <?php if ($first_image): ?>
                                        <img src="../uploads/<?php echo htmlspecialchars($first_image); ?>" 
                                             alt="<?php echo htmlspecialchars($order['product_title']); ?>" 
                                             class="product-image">
                                    <?php else: ?>
                                        <div class="product-image" style="background: #f0f0f0; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-image" style="color: #ccc;"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="product-details">
                                        <div class="product-title"><?php echo htmlspecialchars($order['product_title']); ?></div>
                                        <div class="product-crop"><?php echo htmlspecialchars($order['crop_name']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="buyer-info">
                                    <div class="buyer-company"><?php echo htmlspecialchars($order['buyer_name'] ?: $order['buyer_username']); ?></div>
                                    <div class="buyer-contact">
                                        <?php if ($order['buyer_email']): ?>
                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($order['buyer_email']); ?><br>
                                        <?php endif; ?>
                                        <?php if ($order['buyer_phone']): ?>
                                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($order['buyer_phone']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php echo number_format($order['quantity'], 2); ?> <?php echo htmlspecialchars($order['unit']); ?>
                            </td>
                            <td>
                                <div class="amount">₹<?php echo number_format($order['total_amount'], 2); ?></div>
                                <div class="earnings">Earning: ₹<?php echo number_format($order['farmer_earnings'], 2); ?></div>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $order['order_status']; ?>">
                                    <?php echo ucfirst($order['order_status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="date-info">
                                    <div class="order-date"><?php echo date('M j, Y', strtotime($order['created_at'])); ?></div>
                                    <div class="order-time"><?php echo date('h:i A', strtotime($order['created_at'])); ?></div>
                                </div>
                            </td>
                            <td>
                                <div class="order-actions">
                                    <button onclick="viewOrderDetails(<?php echo $order['id']; ?>)" class="action-btn btn-view" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (in_array($order['order_status'], ['pending', 'confirmed'])): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to update this order status?');">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                            <select name="new_status" class="action-select" onchange="this.form.submit()">
                                                <option value="">Update Status</option>
                                                <?php if ($order['order_status'] === 'pending'): ?>
                                                    <option value="confirmed">Confirm Order</option>
                                                    <option value="cancelled">Cancel Order</option>
                                                <?php elseif ($order['order_status'] === 'confirmed'): ?>
                                                    <option value="delivered">Mark Delivered</option>
                                                    <option value="cancelled">Cancel Order</option>
                                                <?php endif; ?>
                                            </select>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1<?php echo $status_filter !== 'all' ? '&status=' . $status_filter : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>">&laquo; First</a>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $status_filter !== 'all' ? '&status=' . $status_filter : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>">&lsaquo; Previous</a>
                <?php endif; ?>
                
                <?php 
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++): 
                ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?><?php echo $status_filter !== 'all' ? '&status=' . $status_filter : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $status_filter !== 'all' ? '&status=' . $status_filter : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>">Next &rsaquo;</a>
                    <a href="?page=<?php echo $total_pages; ?><?php echo $status_filter !== 'all' ? '&status=' . $status_filter : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>">Last &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="no-orders">
                <i class="fas fa-inbox"></i>
                <h3>No Orders Found</h3>
                <p>You don't have any orders matching the current filters. Try adjusting your filters or check back later.</p>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Order Details Modal -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Order Details</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="orderDetails">
                <!-- Order details will be loaded here -->
            </div>
        </div>
    </div>
    
    <script>
        // View order details function
        function viewOrderDetails(orderId) {
            // In a real implementation, you would fetch order details via AJAX
            // For now, we'll show a simple modal with basic info
            const modal = document.getElementById('orderModal');
            const detailsDiv = document.getElementById('orderDetails');
            
            // Find the order data from the current page
            const orderRow = document.querySelector(`button[onclick="viewOrderDetails(${orderId})"]`).closest('tr');
            const cells = orderRow.getElementsByTagName('td');
            
            detailsDiv.innerHTML = `
                <div class="detail-row">
                    <span class="detail-label">Order ID:</span>
                    <span class="detail-value">${cells[0].textContent.trim()}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Product:</span>
                    <span class="detail-value">${cells[1].querySelector('.product-title').textContent}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Crop:</span>
                    <span class="detail-value">${cells[1].querySelector('.product-crop').textContent}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Buyer:</span>
                    <span class="detail-value">${cells[2].querySelector('.buyer-company').textContent}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Quantity:</span>
                    <span class="detail-value">${cells[3].textContent.trim()}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Amount:</span>
                    <span class="detail-value">${cells[4].querySelector('.amount').textContent}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Your Earnings:</span>
                    <span class="detail-value">${cells[4].querySelector('.earnings').textContent}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">${cells[5].textContent.trim()}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Order Date:</span>
                    <span class="detail-value">${cells[6].querySelector('.order-date').textContent} at ${cells[6].querySelector('.order-time').textContent}</span>
                </div>
            `;
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            document.getElementById('orderModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('orderModal');
            if (event.target === modal) {
                closeModal();
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
        
        // Auto-submit form on filter change (optional)
        document.getElementById('status').addEventListener('change', function() {
            // Uncomment the line below if you want automatic form submission on status change
            // this.form.submit();
        });
        
        // Success/Error message handling
        <?php if (isset($success_message)): ?>
            alert('<?php echo htmlspecialchars($success_message); ?>');
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            alert('<?php echo htmlspecialchars($error_message); ?>');
        <?php endif; ?>
    </script>
</body>
</html>