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

// Handle bulk order creation
if (isset($_POST['create_bulk_order'])) {
    $product_id = $_POST['product_id'];
    $quantity = $_POST['quantity'];
    $target_price = $_POST['target_price'];
    $delivery_date = $_POST['delivery_date'];
    $notes = $_POST['notes'] ?? '';
    
    try {
        $buyer_stmt = $conn->prepare("SELECT id FROM buyers WHERE user_id = ?");
        $buyer_stmt->execute([$user_id]);
        $buyer_id = $buyer_stmt->fetch()['id'];
        
        $stmt = $conn->prepare("
            INSERT INTO bulk_orders (buyer_id, product_id, quantity, target_price, delivery_date, notes, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$buyer_id, $product_id, $quantity, $target_price, $delivery_date, $notes]);
        $success_message = "Bulk order request created successfully!";
    } catch (Exception $e) {
        $error_message = "Error creating bulk order: " . $e->getMessage();
    }
}

// Handle order status updates
if (isset($_POST['cancel_order'])) {
    $order_id = $_POST['order_id'];
    try {
        $stmt = $conn->prepare("
            UPDATE bulk_orders 
            SET status = 'cancelled', updated_at = NOW()
            WHERE id = ? AND buyer_id = (SELECT id FROM buyers WHERE user_id = ?)
        ");
        $stmt->execute([$order_id, $user_id]);
        $success_message = "Bulk order cancelled successfully!";
    } catch (Exception $e) {
        $error_message = "Error cancelling order: " . $e->getMessage();
    }
}

// Get filters
$status_filter = $_GET['status'] ?? 'all';
$sort_by = $_GET['sort'] ?? 'latest';

// Build query conditions
$where_conditions = ["buyer.user_id = ?"];
$params = [$user_id];

if ($status_filter !== 'all') {
    $where_conditions[] = "bo.status = ?";
    $params[] = $status_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Build sort clause
$sort_clause = match($sort_by) {
    'oldest' => 'bo.created_at ASC',
    'quantity_high' => 'bo.quantity DESC',
    'quantity_low' => 'bo.quantity ASC',
    'price_high' => 'bo.target_price DESC',
    'price_low' => 'bo.target_price ASC',
    default => 'bo.created_at DESC'
};

try {
    // Get bulk orders with pagination
    $page = max(1, $_GET['page'] ?? 1);
    $per_page = 15;
    $offset = ($page - 1) * $per_page;
    
    $stmt = $conn->prepare("
        SELECT bo.*, p.title as product_title, p.price_per_unit, c.name as crop_name,
               f.farm_name, u.username as farmer_username, f.state, f.district,
               p.available_quantity
        FROM bulk_orders bo
        JOIN buyers buyer ON bo.buyer_id = buyer.id
        JOIN product_listings p ON bo.product_id = p.id
        JOIN crops c ON p.crop_id = c.id
        JOIN farmers f ON p.farmer_id = f.id
        JOIN users u ON f.user_id = u.id
        WHERE $where_clause
        ORDER BY $sort_clause
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $bulk_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $count_stmt = $conn->prepare("
        SELECT COUNT(*) as total
        FROM bulk_orders bo
        JOIN buyers buyer ON bo.buyer_id = buyer.id
        WHERE $where_clause
    ");
    $count_stmt->execute($params);
    $total_orders = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_orders / $per_page);
    
    // Get statistics
    $stats_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_orders,
            COUNT(CASE WHEN bo.status = 'pending' THEN 1 END) as pending_orders,
            COUNT(CASE WHEN bo.status = 'approved' THEN 1 END) as approved_orders,
            COUNT(CASE WHEN bo.status = 'completed' THEN 1 END) as completed_orders,
            COALESCE(SUM(bo.quantity * bo.target_price), 0) as total_value
        FROM bulk_orders bo
        JOIN buyers buyer ON bo.buyer_id = buyer.id
        WHERE buyer.user_id = ?
    ");
    $stats_stmt->execute([$user_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get available products for bulk orders
    $products_stmt = $conn->prepare("
        SELECT p.id, p.title, c.name as crop_name, p.price_per_unit, p.available_quantity,
               f.farm_name, f.district
        FROM product_listings p
        JOIN crops c ON p.crop_id = c.id
        JOIN farmers f ON p.farmer_id = f.id
        WHERE p.available_quantity > 100
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $products_stmt->execute();
    $available_products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Bulk orders page error: " . $e->getMessage());
    $bulk_orders = [];
    $stats = ['total_orders' => 0, 'pending_orders' => 0, 'approved_orders' => 0, 'completed_orders' => 0, 'total_value' => 0];
    $available_products = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Orders - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .bulk-orders-container {
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
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #264653;
            margin-bottom: 5px;
        }
        
        .action-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .order-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            align-items: end;
        }
        
        .order-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .order-card:hover {
            transform: translateY(-2px);
        }
        
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .order-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2a9d8f;
        }
        
        .order-status {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-completed { background: #d1ecf1; color: #0c5460; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .status-rejected { background: #f5c6cb; color: #721c24; }
        
        .order-details {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        .product-info h3 {
            margin: 0 0 10px 0;
            color: #264653;
            font-size: 1.2rem;
        }
        
        .product-meta {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .order-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 14px;
            border-radius: 6px;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: #000;
        }
        
        .no-orders {
            text-align: center;
            padding: 60px;
            color: #666;
        }
        
        .no-orders i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ccc;
        }
    </style>
</head>
<body>
    <div class="bulk-orders-container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1><i class="fas fa-boxes"></i> Bulk Orders</h1>
                <p>Place and manage large quantity orders with farmers</p>
            </div>
            <div>
                <button onclick="openOrderModal()" class="btn btn-light">
                    <i class="fas fa-plus"></i> New Bulk Order
                </button>
                <a href="dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
        
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?= $success_message ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?= $error_message ?></div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['total_orders']) ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['pending_orders']) ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['approved_orders']) ?></div>
                <div class="stat-label">Approved Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">₹<?= number_format($stats['total_value'], 0) ?></div>
                <div class="stat-label">Total Value</div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="action-section">
            <form method="GET" class="order-form">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Orders</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sort By</label>
                    <select name="sort" class="form-control">
                        <option value="latest" <?= $sort_by === 'latest' ? 'selected' : '' ?>>Latest First</option>
                        <option value="oldest" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="quantity_high" <?= $sort_by === 'quantity_high' ? 'selected' : '' ?>>Highest Quantity</option>
                        <option value="price_high" <?= $sort_by === 'price_high' ? 'selected' : '' ?>>Highest Price</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Orders List -->
        <?php if (empty($bulk_orders)): ?>
            <div class="no-orders">
                <i class="fas fa-boxes"></i>
                <h3>No bulk orders found</h3>
                <p>You haven't placed any bulk orders yet.</p>
                <button onclick="openOrderModal()" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Place Your First Bulk Order
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($bulk_orders as $order): ?>
                <div class="order-card">
                    <div class="order-header">
                        <div class="order-value">₹<?= number_format($order['quantity'] * $order['target_price'], 2) ?></div>
                        <div class="order-status status-<?= $order['status'] ?>">
                            <?= ucfirst($order['status']) ?>
                        </div>
                    </div>
                    
                    <div class="order-details">
                        <div class="product-info">
                            <h3><?= htmlspecialchars($order['product_title']) ?></h3>
                            <div class="product-meta">
                                <p><i class="fas fa-seedling"></i> <?= htmlspecialchars($order['crop_name']) ?></p>
                                <p><i class="fas fa-weight-hanging"></i> Quantity: <?= number_format($order['quantity']) ?> kg</p>
                                <p><i class="fas fa-rupee-sign"></i> Target Price: ₹<?= number_format($order['target_price'], 2) ?>/kg</p>
                                <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($order['farm_name']) ?>, <?= htmlspecialchars($order['district']) ?></p>
                                <p><i class="fas fa-user"></i> <?= htmlspecialchars($order['farmer_username']) ?></p>
                                <p><i class="fas fa-calendar"></i> Delivery: <?= date('d M Y', strtotime($order['delivery_date'])) ?></p>
                                <p><i class="fas fa-clock"></i> Created: <?= date('d M Y, H:i', strtotime($order['created_at'])) ?></p>
                                <?php if ($order['notes']): ?>
                                    <p><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($order['notes']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="order-info">
                            <div class="order-actions">
                                <?php if ($order['status'] === 'pending'): ?>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Are you sure you want to cancel this order?')">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <button type="submit" name="cancel_order" class="btn btn-danger btn-small">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    </form>
                                <?php elseif ($order['status'] === 'approved'): ?>
                                    <span class="btn btn-success btn-small">
                                        <i class="fas fa-check"></i> Approved
                                    </span>
                                <?php elseif ($order['status'] === 'completed'): ?>
                                    <span class="btn btn-info btn-small">
                                        <i class="fas fa-check-double"></i> Completed
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- New Order Modal -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus"></i> Create Bulk Order</h2>
                <span class="close" onclick="closeOrderModal()">&times;</span>
            </div>
            
            <form method="POST" id="bulkOrderForm">
                <div class="form-group">
                    <label for="product_id">Select Product</label>
                    <select name="product_id" id="product_id" class="form-control" required onchange="updateProductInfo()">
                        <option value="">Choose a product...</option>
                        <?php foreach ($available_products as $product): ?>
                            <option value="<?= $product['id'] ?>" 
                                data-price="<?= $product['price_per_unit'] ?>"
                                data-available="<?= $product['available_quantity'] ?>"
                                data-farm="<?= htmlspecialchars($product['farm_name']) ?>"
                                data-location="<?= htmlspecialchars($product['district']) ?>">
                                <?= htmlspecialchars($product['title']) ?> - <?= htmlspecialchars($product['crop_name']) ?>
                                (<?= number_format($product['available_quantity']) ?> kg available)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="productInfo" style="display: none;" class="alert alert-info">
                    <div id="productDetails"></div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="quantity">Quantity (kg)</label>
                        <input type="number" name="quantity" id="quantity" class="form-control" 
                               min="100" required onchange="calculateTotal()">
                        <small class="text-muted">Minimum: 100 kg</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="target_price">Target Price (per kg)</label>
                        <input type="number" name="target_price" id="target_price" class="form-control" 
                               step="0.01" min="0" required onchange="calculateTotal()">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="delivery_date">Required Delivery Date</label>
                    <input type="date" name="delivery_date" id="delivery_date" class="form-control" 
                           min="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="notes">Additional Notes (Optional)</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3" 
                              placeholder="Special requirements, delivery instructions, etc."></textarea>
                </div>
                
                <div id="orderTotal" style="display: none;" class="alert alert-success">
                    <strong>Total Order Value: ₹<span id="totalAmount">0</span></strong>
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="closeOrderModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="create_bulk_order" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Submit Order Request
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openOrderModal() {
            document.getElementById('orderModal').style.display = 'block';
        }
        
        function closeOrderModal() {
            document.getElementById('orderModal').style.display = 'none';
            document.getElementById('bulkOrderForm').reset();
            document.getElementById('productInfo').style.display = 'none';
            document.getElementById('orderTotal').style.display = 'none';
        }
        
        function updateProductInfo() {
            const select = document.getElementById('product_id');
            const option = select.options[select.selectedIndex];
            const info = document.getElementById('productInfo');
            const details = document.getElementById('productDetails');
            
            if (option.value) {
                const price = option.dataset.price;
                const available = option.dataset.available;
                const farm = option.dataset.farm;
                const location = option.dataset.location;
                
                details.innerHTML = `
                    <strong>Farm:</strong> ${farm}, ${location}<br>
                    <strong>Current Price:</strong> ₹${parseFloat(price).toFixed(2)}/kg<br>
                    <strong>Available Quantity:</strong> ${parseInt(available).toLocaleString()} kg
                `;
                
                document.getElementById('target_price').value = price;
                document.getElementById('quantity').max = available;
                info.style.display = 'block';
                calculateTotal();
            } else {
                info.style.display = 'none';
            }
        }
        
        function calculateTotal() {
            const quantity = document.getElementById('quantity').value;
            const price = document.getElementById('target_price').value;
            const totalDiv = document.getElementById('orderTotal');
            const totalAmount = document.getElementById('totalAmount');
            
            if (quantity && price) {
                const total = quantity * price;
                totalAmount.textContent = total.toLocaleString('en-IN', {minimumFractionDigits: 2});
                totalDiv.style.display = 'block';
            } else {
                totalDiv.style.display = 'none';
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('orderModal');
            if (event.target === modal) {
                closeOrderModal();
            }
        }
    </script>
</body>
</html>
