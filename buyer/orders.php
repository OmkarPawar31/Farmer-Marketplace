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

// Get buyer ID
$stmt = $conn->prepare("SELECT id FROM buyers WHERE user_id = ?");
$stmt->execute([$user_id]);
$buyer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$buyer) {
    die("Buyer profile not found");
}

$buyer_id = $buyer['id'];

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'latest';
$page = intval($_GET['page'] ?? 1);
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build WHERE conditions
$where_conditions = ["b.id = ?"];
$params = [$buyer_id];

if ($status_filter !== 'all') {
    $where_conditions[] = "o.order_status = ?";
    $params[] = $status_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(o.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($search)) {
    $where_conditions[] = "(p.title LIKE ? OR f.farm_name LIKE ? OR o.order_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

// Order by clause
$order_by = match($sort_by) {
    'oldest' => 'o.created_at ASC',
    'amount_high' => 'o.total_amount DESC',
    'amount_low' => 'o.total_amount ASC',
    'status' => 'o.order_status ASC, o.created_at DESC',
    'latest' => 'o.created_at DESC',
    default => 'o.created_at DESC'
};

// Get orders with pagination
$query = "
    SELECT o.*, p.title as product_title, p.unit, c.name as crop_name, 
           f.farm_name, f.state as farmer_state, f.district as farmer_district,
           u.username as farmer_username, u.phone as farmer_phone
    FROM orders o
    JOIN buyers b ON o.buyer_id = b.id
    JOIN product_listings p ON o.product_id = p.id
    JOIN crops c ON p.crop_id = c.id
    JOIN farmers f ON o.farmer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE $where_clause
    ORDER BY $order_by
    LIMIT $per_page OFFSET $offset
";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM orders o
    JOIN buyers b ON o.buyer_id = b.id
    JOIN product_listings p ON o.product_id = p.id
    JOIN crops c ON p.crop_id = c.id
    JOIN farmers f ON o.farmer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE $where_clause
";

$count_stmt = $conn->prepare($count_query);
$count_stmt->execute($params);
$total_orders = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_orders / $per_page);

// Get order statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_orders,
        SUM(o.total_amount) as total_spent,
        COUNT(CASE WHEN o.order_status = 'pending' THEN 1 END) as pending_orders,
        COUNT(CASE WHEN o.order_status = 'delivered' THEN 1 END) as delivered_orders,
        COUNT(CASE WHEN o.order_status = 'cancelled' THEN 1 END) as cancelled_orders
    FROM orders o
    JOIN buyers b ON o.buyer_id = b.id
    WHERE b.id = ?
";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute([$buyer_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - Farmer Marketplace</title>
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
        }
        
        .header-content h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .header-stats {
            display: flex;
            gap: 30px;
            font-size: 14px;
        }
        
        .header-stat {
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #e9c46a;
        }
        
        .back-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
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
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 500;
            color: #264653;
            margin-bottom: 5px;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #2a9d8f;
        }
        
        .filter-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }
        
        .btn-filter {
            padding: 10px 20px;
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 157, 143, 0.4);
        }
        
        .btn-clear {
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
        }
        
        .btn-clear:hover {
            background: #5a6268;
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
        }
        
        .results-info {
            color: #666;
            font-size: 14px;
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
        }
        
        .order-card {
            background: #f8f9fa;
            margin: 15px;
            border-radius: 10px;
            padding: 20px;
            display: none;
        }
        
        .order-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
        }
        
        .detail-label {
            font-weight: 500;
            color: #666;
        }
        
        .detail-value {
            font-weight: 600;
            color: #264653;
        }
        
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .status-pending { background: #fff7ed; color: #f59e0b; }
        .status-confirmed { background: #f0f9ff; color: #3b82f6; }
        .status-processing { background: #f3e8ff; color: #8b5cf6; }
        .status-shipped { background: #fef3c7; color: #d97706; }
        .status-delivered { background: #f0fdf4; color: #16a34a; }
        .status-cancelled { background: #fef2f2; color: #ef4444; }
        
        .order-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-view {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .btn-view:hover {
            background: #bbdefb;
        }
        
        .btn-cancel {
            background: #ffebee;
            color: #d32f2f;
        }
        
        .btn-cancel:hover {
            background: #ffcdd2;
        }
        
        .btn-track {
            background: #e8f5e8;
            color: #388e3c;
        }
        
        .btn-track:hover {
            background: #c8e6c9;
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
            padding: 8px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            text-decoration: none;
            color: #264653;
            font-weight: 500;
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
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .empty-icon {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 20px;
        }
        
        /* Modal Styles */
        .modal {
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
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 700px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #264653;
        }
        
        .close {
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
        }
        
        .close:hover {
            color: #000;
        }
        
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            .header-stats {
                justify-content: center;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .orders-table {
                display: none;
            }
            
            .order-card {
                display: block;
            }
            
            .filter-actions {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="orders-container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <h1><i class="fas fa-list-alt"></i> My Orders</h1>
                <div class="header-stats">
                    <div class="header-stat">
                        <div class="stat-number"><?php echo number_format($stats['total_orders']); ?></div>
                        <div>Total Orders</div>
                    </div>
                    <div class="header-stat">
                        <div class="stat-number">₹<?php echo number_format($stats['total_spent'], 0); ?></div>
                        <div>Total Spent</div>
                    </div>
                    <div class="header-stat">
                        <div class="stat-number"><?php echo number_format($stats['pending_orders']); ?></div>
                        <div>Pending</div>
                    </div>
                    <div class="header-stat">
                        <div class="stat-number"><?php echo number_format($stats['delivered_orders']); ?></div>
                        <div>Delivered</div>
                    </div>
                </div>
            </div>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="status">Order Status</label>
                        <select name="status" id="status">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="shipped" <?php echo $status_filter === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                            <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_from">Date From</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_to">Date To</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" name="search" id="search" placeholder="Order ID, Product, Farmer..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="sort">Sort By</label>
                        <select name="sort" id="sort">
                            <option value="latest" <?php echo $sort_by === 'latest' ? 'selected' : ''; ?>>Latest First</option>
                            <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                            <option value="amount_high" <?php echo $sort_by === 'amount_high' ? 'selected' : ''; ?>>Amount: High to Low</option>
                            <option value="amount_low" <?php echo $sort_by === 'amount_low' ? 'selected' : ''; ?>>Amount: Low to High</option>
                            <option value="status" <?php echo $sort_by === 'status' ? 'selected' : ''; ?>>Status</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="orders.php" class="btn-clear">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Orders Section -->
        <div class="orders-section">
            <div class="section-header">
                <h2 class="section-title">Order History</h2>
                <div class="results-info">
                    Showing <?php echo count($orders); ?> of <?php echo $total_orders; ?> orders
                </div>
            </div>

            <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <h3>No Orders Found</h3>
                    <p>You haven't placed any orders yet or no orders match your filters.</p>
                    <a href="dashboard.php" class="btn-filter" style="margin-top: 20px;">
                        <i class="fas fa-plus"></i> Start Shopping
                    </a>
                </div>
            <?php else: ?>
                <!-- Desktop Table View -->
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
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
                        <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($order['product_title']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($order['crop_name']); ?></small>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <?php echo htmlspecialchars($order['farm_name']); ?><br>
                                    <small><?php echo htmlspecialchars($order['farmer_district']); ?>, <?php echo htmlspecialchars($order['farmer_state']); ?></small>
                                </div>
                            </td>
                            <td><?php echo number_format($order['quantity'], 1); ?> <?php echo $order['unit']; ?></td>
                            <td><strong>₹<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                            <td>
                                <span class="status-badge status-<?php echo $order['order_status']; ?>">
                                    <?php echo ucfirst($order['order_status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d M Y', strtotime($order['created_at'])); ?></td>
                            <td>
                                <div class="order-actions">
                                    <button class="btn-action btn-view" onclick="viewOrder(<?php echo $order['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php if ($order['order_status'] === 'pending'): ?>
                                        <button class="btn-action btn-cancel" onclick="cancelOrder(<?php echo $order['id']; ?>)">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    <?php endif; ?>
                                    <?php if (in_array($order['order_status'], ['confirmed', 'processing', 'shipped'])): ?>
                                        <button class="btn-action btn-track" onclick="trackOrder('<?php echo $order['order_number']; ?>')">
                                            <i class="fas fa-truck"></i> Track
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Mobile Card View -->
                <?php foreach ($orders as $order): ?>
                <div class="order-card">
                    <div class="order-details">
                        <div class="detail-item">
                            <span class="detail-label">Order ID:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="status-badge status-<?php echo $order['order_status']; ?>">
                                <?php echo ucfirst($order['order_status']); ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Product:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($order['product_title']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Farmer:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($order['farm_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Quantity:</span>
                            <span class="detail-value"><?php echo number_format($order['quantity'], 1); ?> <?php echo $order['unit']; ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Amount:</span>
                            <span class="detail-value">₹<?php echo number_format($order['total_amount'], 2); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Date:</span>
                            <span class="detail-value"><?php echo date('d M Y', strtotime($order['created_at'])); ?></span>
                        </div>
                    </div>
                    
                    <div class="order-actions">
                        <button class="btn-action btn-view" onclick="viewOrder(<?php echo $order['id']; ?>)">
                            <i class="fas fa-eye"></i> View Details
                        </button>
                        <?php if ($order['order_status'] === 'pending'): ?>
                            <button class="btn-action btn-cancel" onclick="cancelOrder(<?php echo $order['id']; ?>)">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        <?php endif; ?>
                        <?php if (in_array($order['order_status'], ['confirmed', 'processing', 'shipped'])): ?>
                            <button class="btn-action btn-track" onclick="trackOrder('<?php echo $order['order_number']; ?>')">
                                <i class="fas fa-truck"></i> Track
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Order Details</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div id="orderDetails">
                <!-- Order details will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        function viewOrder(orderId) {
            // Show loading
            document.getElementById('orderDetails').innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            document.getElementById('orderModal').style.display = 'block';
            
            // Fetch order details
            fetch(`order_details.php?id=${orderId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('orderDetails').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('orderDetails').innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading order details</div>';
                });
        }
        
        function cancelOrder(orderId) {
            if (confirm('Are you sure you want to cancel this order?')) {
                fetch('cancel_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ order_id: orderId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Order cancelled successfully');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error cancelling order');
                });
            }
        }
        
        function trackOrder(orderNumber) {
            alert('Tracking feature will be implemented. Order: ' + orderNumber);
        }
        
        function closeModal() {
            document.getElementById('orderModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('orderModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
