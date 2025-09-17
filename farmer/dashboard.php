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

// Get farmer statistics
$stmt = $conn->prepare("
    SELECT 
        (SELECT count(*) FROM product_listings WHERE farmer_id = (SELECT id FROM farmers WHERE user_id = ?)) as total_listings,
        (SELECT count(*) FROM orders o JOIN farmers f ON o.farmer_id = f.id WHERE f.user_id = ?) as total_orders,
        (SELECT COALESCE(SUM(farmer_earnings), 0) FROM orders o JOIN farmers f ON o.farmer_id = f.id WHERE f.user_id = ?) as total_earnings,
        (SELECT count(*) FROM product_listings pl JOIN farmers f ON pl.farmer_id = f.id WHERE f.user_id = ? AND pl.status = 'active') as active_listings
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent orders
$stmt = $conn->prepare("
    SELECT o.*, p.title as product_title, b.company_name as buyer_name, u.username as buyer_username, u.id as buyer_user_id
    FROM orders o 
    JOIN farmers f ON o.farmer_id = f.id 
    JOIN product_listings p ON o.product_id = p.id
    JOIN buyers b ON o.buyer_id = b.id
    JOIN users u ON b.user_id = u.id
    WHERE f.user_id = ? 
    ORDER BY o.created_at DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active listings
$stmt = $conn->prepare("
    SELECT p.*, c.name as crop_name 
    FROM product_listings p 
    JOIN farmers f ON p.farmer_id = f.id 
    JOIN crops c ON p.crop_id = c.id
    WHERE f.user_id = ? AND p.status = 'active'
    ORDER BY p.created_at DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$active_listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get crop categories and crops for the form
$categories_stmt = $conn->prepare("SELECT * FROM crop_categories WHERE is_active = 1 ORDER BY name");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$crops_stmt = $conn->prepare("SELECT * FROM crops WHERE is_active = 1 ORDER BY name");
$crops_stmt->execute();
$crops = $crops_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer Dashboard - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .dashboard-container {
            max-width: 1200px;
            margin: 80px auto;
            padding: 20px;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
        }
        
        .dashboard-header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .farm-info {
            opacity: 0.9;
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
        
        .dashboard-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #264653;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
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
        
        .status-active { background: #f0fdf4; color: #2a9d8f; }
        .status-pending { background: #fff7ed; color: #f59e0b; }
        .status-delivered { background: #f0f9ff; color: #3b82f6; }
        .status-cancelled { background: #fef2f2; color: #ef4444; }
        
        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 157, 143, 0.4);
        }
        
        .no-data {
            text-align: center;
            color: #666;
            padding: 40px;
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
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            border: none;
            background: none;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #264653;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2a9d8f;
            box-shadow: 0 0 0 3px rgba(42, 157, 143, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }

        .required {
            color: #e76f51;
        }

        /* Image upload styles removed */

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .btn-cancel {
            padding: 12px 24px;
            background: #f8f9fa;
            color: #666;
            border: 1px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: #e9ecef;
        }

        .btn-submit {
            padding: 12px 24px;
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 157, 143, 0.4);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 9999;
            transform: translateX(400px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 300px;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .notification.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .notification.info {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .notification i {
            font-size: 1.2rem;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                margin: 5% auto;
                width: 95%;
            }
            
            .notification {
                right: 10px;
                left: 10px;
                min-width: auto;
                transform: translateY(-100px);
            }
            
            .notification.show {
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <h1><i class="fas fa-tractor"></i> Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>
            <div class="farm-info">
                <p><i class="fas fa-seedling"></i> <?php echo htmlspecialchars($_SESSION['farm_name']); ?></p>
                <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($_SESSION['farm_address']); ?></p>
                <p><i class="fas fa-expand-arrows-alt"></i> <?php echo number_format($_SESSION['farm_size'], 2); ?> acres</p>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-list"></i></div>
                <div class="stat-number"><?php echo number_format($stats['total_listings']); ?></div>
                <div class="stat-label">Total Listings</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="stat-number"><?php echo number_format($stats['total_orders']); ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
                <div class="stat-number">₹<?php echo number_format($stats['total_earnings'], 2); ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-eye"></i></div>
                <div class="stat-number"><?php echo number_format($stats['active_listings']); ?></div>
                <div class="stat-label">Active Listings</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Quick Actions</h2>
            </div>
            <div class="quick-actions">
                <button onclick="openProductModal()" class="action-btn" style="border: none; cursor: pointer;">
                    <i class="fas fa-plus"></i> Add New Product
                </button>
                <a href="my-products.php" class="action-btn">
                    <i class="fas fa-boxes"></i> Manage Products
                </a>
                <a href="orders.php" class="action-btn">
                    <i class="fas fa-receipt"></i> View Orders
                </a>
                <a href="profile.php" class="action-btn">
                    <i class="fas fa-user-edit"></i> Edit Profile
                </a>
                <a href="logout.php" class="action-btn" style="background: linear-gradient(135deg, #e76f51, #f4a261);">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
                <a href="auction_management.php" class="action-btn" style="background: linear-gradient(135deg, #e76f51, #f4a261);">
                    <i class="fas fa-gavel"></i> Auction Management
                </a>
            </div>
        </div>
        
        <!-- Recent Orders -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Recent Orders</h2>
                <a href="orders.php" class="btn btn-outline">View All</a>
            </div>
            <?php if (count($recent_orders) > 0): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Product</th>
                        <th>Buyer</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_orders as $order): ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($order['order_number']); ?></td>
                        <td><?php echo htmlspecialchars($order['product_title']); ?></td>
                        <td><?php echo htmlspecialchars($order['buyer_name'] ?: $order['buyer_username']); ?></td>
                        <td>₹<?php echo number_format($order['total_amount'], 2); ?></td>
                        <td><span class="status-badge status-<?php echo $order['order_status']; ?>"><?php echo ucfirst($order['order_status']); ?></span></td>
                        <td><?php echo date('M j, Y', strtotime($order['created_at'])); ?></td>
                        <td>
                            <a href="../chat.php?user=<?php echo $order['buyer_user_id']; ?>&product=<?php echo $order['product_id']; ?>" class="action-btn" style="background: #e76f51; padding: 5px 10px; font-size: 12px;">
                                <i class="fas fa-comments"></i> Chat
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-inbox" style="font-size: 3rem; color: #ddd; margin-bottom: 15px;"></i>
                <p>No orders yet. Start by adding products to your marketplace!</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Photo Gallery Section -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-images"></i> Crop Photo Gallery</h2>
                <a href="gallery.php" class="btn btn-outline">View All Photos</a>
            </div>
            <div class="photo-gallery">
                <?php 
                // Get photos from active listings
                $stmt = $conn->prepare("
                    SELECT p.images, p.title, c.name as crop_name 
                    FROM product_listings p 
                    JOIN farmers f ON p.farmer_id = f.id 
                    JOIN crops c ON p.crop_id = c.id
                    WHERE f.user_id = ? AND p.status = 'active' AND p.images IS NOT NULL
                    ORDER BY p.created_at DESC 
                    LIMIT 6
                ");
                $stmt->execute([$user_id]);
                $gallery_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($gallery_items) > 0): 
                    foreach ($gallery_items as $item): 
                        $images = json_decode($item['images'], true);
                        if ($images && count($images) > 0): ?>
                            <div class="gallery-item">
                                <img src="../uploads/<?php echo htmlspecialchars($images[0]); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                                <div class="gallery-overlay">
                                    <div class="gallery-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                    <div class="gallery-crop"><?php echo htmlspecialchars($item['crop_name']); ?></div>
                                </div>
                            </div>
                <?php endif; endforeach; else: ?>
                    <div class="no-data">
                        <i class="fas fa-camera" style="font-size: 3rem; color: #ddd; margin-bottom: 15px;"></i>
                        <p>No photos yet. <a href="add-product.php">Add products with photos</a> to showcase your crops!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Real-time Inventory Management -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-warehouse"></i> Real-time Inventory</h2>
                <button class="btn btn-primary" onclick="refreshInventory()"><i class="fas fa-sync"></i> Refresh</button>
            </div>
            <div class="inventory-grid" id="inventoryGrid">
                <?php 
                $stmt = $conn->prepare("
                    SELECT p.id, p.title, p.quantity_available, p.unit, p.price_per_unit, c.name as crop_name, p.images
                    FROM product_listings p 
                    JOIN farmers f ON p.farmer_id = f.id 
                    JOIN crops c ON p.crop_id = c.id
                    WHERE f.user_id = ? AND p.status = 'active'
                    ORDER BY p.updated_at DESC
                ");
                $stmt->execute([$user_id]);
                $inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($inventory_items as $item): 
                    $images = json_decode($item['images'], true); ?>
                    <div class="inventory-card" data-product-id="<?php echo $item['id']; ?>">
                        <div class="inventory-image">
                            <?php if ($images && count($images) > 0): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($images[0]); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                            <?php else: ?>
                                <div class="no-image"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="inventory-details">
                            <h4><?php echo htmlspecialchars($item['title']); ?></h4>
                            <p class="crop-name"><?php echo htmlspecialchars($item['crop_name']); ?></p>
                            <div class="quantity-control">
                                <label>Quantity:</label>
                                <input type="number" 
                                       value="<?php echo $item['quantity_available']; ?>" 
                                       data-product-id="<?php echo $item['id']; ?>" 
                                       class="inventory-input" 
                                       step="0.01" 
                                       min="0" />
                                <span class="unit"><?php echo $item['unit']; ?></span>
                            </div>
                            <div class="price-info">
                                <span class="price">₹<?php echo number_format($item['price_per_unit'], 2); ?></span>
                                <span class="per-unit">per <?php echo $item['unit']; ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Active Listings -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Active Product Listings</h2>
                <a href="my-products.php" class="btn btn-outline">Manage All</a>
            </div>
            <?php if (count($active_listings) > 0): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Crop</th>
                        <th>Quantity</th>
                        <th>Price/Unit</th>
                        <th>Views</th>
                        <th>Posted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_listings as $listing): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($listing['title']); ?></td>
                        <td><?php echo htmlspecialchars($listing['crop_name']); ?></td>
                        <td><?php echo number_format($listing['quantity_available'], 2); ?> <?php echo $listing['unit']; ?></td>
                        <td>₹<?php echo number_format($listing['price_per_unit'], 2); ?></td>
                        <td><?php echo number_format($listing['views']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($listing['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-seedling" style="font-size: 3rem; color: #ddd; margin-bottom: 15px;"></i>
                <p>No active listings. <a href="add-product.php">Add your first product</a> to start selling!</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Product Modal -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus-circle"></i> Add New Product</h2>
                <span class="close" onclick="closeProductModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="productForm" action="submit_product.php" method="post" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="crop">Crop <span class="required">*</span></label>
                            <select name="crop_id" id="crop" required>
                                <option value="">Select Crop</option>
                                <?php foreach ($crops as $crop): ?>
                                    <option value="<?php echo $crop['id']; ?>"><?php echo htmlspecialchars($crop['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="quality_grade">Quality Grade</label>
                            <select name="quality_grade" id="quality_grade">
                                <option value="A">Grade A - Premium</option>
                                <option value="B">Grade B - Standard</option>
                                <option value="C">Grade C - Basic</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="title">Product Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" placeholder="e.g., Fresh Organic Tomatoes" required>
                        <small>Enter a descriptive title for your product listing</small>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="quantity">Quantity Available <span class="required">*</span></label>
                            <input type="number" id="quantity" name="quantity_available" step="0.01" min="0.01" placeholder="100" required>
                        </div>
                        <div class="form-group">
                            <label for="unit">Unit <span class="required">*</span></label>
                            <select name="unit" id="unit" required>
                                <option value="kg">Kilogram (kg)</option>
                                <option value="quintal">Quintal</option>
                                <option value="tonne">Tonne</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="price">Price per Unit (₹) <span class="required">*</span></label>
                            <input type="number" id="price" name="price_per_unit" step="0.01" min="0.01" placeholder="50.00" required>
                        </div>
                        <div class="form-group">
                            <label for="minimum_order">Minimum Order Quantity</label>
                            <input type="number" id="minimum_order" name="minimum_order" step="0.01" min="0" placeholder="10">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="harvest_date">Harvest Date <span class="required">*</span></label>
                            <input type="date" id="harvest_date" name="harvest_date" required>
                        </div>
                        <div class="form-group">
                            <label for="expiry_date">Best Before Date</label>
                            <input type="date" id="expiry_date" name="expiry_date">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="organic_certified">
                                <input type="checkbox" id="organic_certified" name="organic_certified" value="1">
                                Organic Certified
                            </label>
                        </div>
                        <div class="form-group">
                            <label for="packaging_available">
                                <input type="checkbox" id="packaging_available" name="packaging_available" value="1">
                                Packaging Available
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="description">Product Description</label>
                        <textarea id="description" name="description" placeholder="Describe your product quality, farming methods, storage conditions, etc."></textarea>
                        <small>Include details about quality, farming methods, and any certifications</small>
                    </div>
                    
                    <!-- Image upload functionality removed -->
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeProductModal()">Cancel</button>
                <button type="submit" form="productForm" class="btn-submit">
                    <i class="fas fa-save"></i> Save Product
                </button>
            </div>
        </div>
    </div>

    <script>
        // Modal Functions
        function openProductModal() {
            document.getElementById('productModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeProductModal() {
            document.getElementById('productModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('productForm').reset();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('productModal');
            if (event.target === modal) {
                closeProductModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeProductModal();
            }
        });

        // Form validation and AJAX submission
        document.getElementById('productForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = document.querySelector('.btn-submit');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            const formData = new FormData(this);
            
            fetch('submit_product.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Product added successfully!', 'success');
                    closeProductModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(data.message || 'Error adding product', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while adding the product', 'error');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Auto-set minimum harvest date to today
        document.getElementById('harvest_date').min = new Date().toISOString().split('T')[0];
        
        // Notification function
        function showNotification(message, type = 'info') {
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            let icon = 'fas fa-info-circle';
            if (type === 'success') icon = 'fas fa-check-circle';
            if (type === 'error') icon = 'fas fa-exclamation-circle';
            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
            `;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification && notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 4000);
        }
    </script>
</body>
</html>
