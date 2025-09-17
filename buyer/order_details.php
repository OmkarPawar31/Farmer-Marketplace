<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a buyer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'buyer') {
    echo '<div style="text-align: center; padding: 40px; color: #ef4444;">Unauthorized access</div>';
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get buyer ID
$stmt = $conn->prepare("SELECT id FROM buyers WHERE user_id = ?");
$stmt->execute([$user_id]);
$buyer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$buyer) {
    echo '<div style="text-align: center; padding: 40px; color: #ef4444;">Buyer profile not found</div>';
    exit();
}

$buyer_id = $buyer['id'];
$order_id = intval($_GET['id'] ?? 0);

if ($order_id <= 0) {
    echo '<div style="text-align: center; padding: 40px; color: #ef4444;">Invalid order ID</div>';
    exit();
}

// Get order details
$query = "
    SELECT o.*, p.title as product_title, p.unit, p.description as product_description,
           p.quality_grade, p.organic_certified, p.images as product_images,
           c.name as crop_name, cc.name as category_name,
           f.farm_name, f.state as farmer_state, f.district as farmer_district,
           f.village as farmer_village, f.pincode as farmer_pincode,
           u.username as farmer_username, u.phone as farmer_phone, u.email as farmer_email
    FROM orders o
    JOIN buyers b ON o.buyer_id = b.id
    JOIN product_listings p ON o.product_id = p.id
    JOIN crops c ON p.crop_id = c.id
    LEFT JOIN crop_categories cc ON c.category_id = cc.id
    JOIN farmers f ON o.farmer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE o.id = ? AND b.id = ?
";

$stmt = $conn->prepare($query);
$stmt->execute([$order_id, $buyer_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo '<div style="text-align: center; padding: 40px; color: #ef4444;">Order not found</div>';
    exit();
}

// Calculate commission and farmer earnings
$commission_amount = $order['commission_amount'] ?? (($order['total_amount'] * 2.5) / 100);
$farmer_earnings = $order['farmer_earnings'] ?? ($order['total_amount'] - $commission_amount);

// Parse product images
$product_images = [];
if (!empty($order['product_images'])) {
    $product_images = json_decode($order['product_images'], true) ?? [];
}
?>

<style>
.order-detail-container {
    max-width: 100%;
}

.order-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 25px;
    text-align: center;
}

.order-id {
    font-size: 1.2rem;
    font-weight: 700;
    color: #264653;
    margin-bottom: 5px;
}

.order-status {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 500;
    text-transform: uppercase;
    display: inline-block;
}

.status-pending { background: #fff7ed; color: #f59e0b; }
.status-confirmed { background: #f0f9ff; color: #3b82f6; }
.status-processing { background: #f3e8ff; color: #8b5cf6; }
.status-shipped { background: #fef3c7; color: #d97706; }
.status-delivered { background: #f0fdf4; color: #16a34a; }
.status-cancelled { background: #fef2f2; color: #ef4444; }

.details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
    margin-bottom: 25px;
}

.detail-section {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #264653;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e0e0e0;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
    padding: 8px 0;
}

.detail-label {
    font-weight: 500;
    color: #666;
    flex: 1;
}

.detail-value {
    font-weight: 600;
    color: #264653;
    flex: 2;
    text-align: right;
}

.pricing-breakdown {
    background: white;
    border: 2px solid #e0e0e0;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 25px;
}

.pricing-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
}

.pricing-row:last-child {
    border-bottom: none;
    font-weight: 700;
    font-size: 1.1rem;
    color: #2a9d8f;
    border-top: 2px solid #e0e0e0;
    margin-top: 10px;
    padding-top: 15px;
}

.product-images {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.product-image {
    width: 80px;
    height: 80px;
    border-radius: 8px;
    object-fit: cover;
    border: 2px solid #e0e0e0;
    cursor: pointer;
    transition: transform 0.3s ease;
}

.product-image:hover {
    transform: scale(1.1);
}

.timeline {
    background: white;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 25px;
}

.timeline-item {
    display: flex;
    align-items: center;
    padding: 10px 0;
    position: relative;
}

.timeline-item:not(:last-child)::after {
    content: '';
    position: absolute;
    left: 15px;
    top: 40px;
    width: 2px;
    height: 30px;
    background: #e0e0e0;
}

.timeline-icon {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    font-size: 12px;
}

.timeline-completed {
    background: #2a9d8f;
    color: white;
}

.timeline-current {
    background: #f59e0b;
    color: white;
}

.timeline-pending {
    background: #e0e0e0;
    color: #666;
}

.timeline-content {
    flex: 1;
}

.timeline-title {
    font-weight: 600;
    color: #264653;
    margin-bottom: 2px;
}

.timeline-date {
    font-size: 12px;
    color: #666;
}

.action-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-top: 25px;
}

.btn-action {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
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
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

@media (max-width: 768px) {
    .details-grid {
        grid-template-columns: 1fr;
    }
    
    .detail-row {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .detail-value {
        text-align: left;
        margin-top: 5px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
</style>

<div class="order-detail-container">
    <!-- Order Header -->
    <div class="order-header">
        <div class="order-id">Order #<?php echo htmlspecialchars($order['order_number']); ?></div>
        <span class="order-status status-<?php echo $order['order_status']; ?>">
            <?php echo ucfirst($order['order_status']); ?>
        </span>
    </div>

    <!-- Details Grid -->
    <div class="details-grid">
        <!-- Product Information -->
        <div class="detail-section">
            <h3 class="section-title"><i class="fas fa-leaf"></i> Product Details</h3>
            
            <div class="detail-row">
                <span class="detail-label">Product:</span>
                <span class="detail-value"><?php echo htmlspecialchars($order['product_title']); ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Crop Type:</span>
                <span class="detail-value"><?php echo htmlspecialchars($order['crop_name']); ?></span>
            </div>
            
            <?php if (!empty($order['category_name'])): ?>
            <div class="detail-row">
                <span class="detail-label">Category:</span>
                <span class="detail-value"><?php echo htmlspecialchars($order['category_name']); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="detail-row">
                <span class="detail-label">Quality Grade:</span>
                <span class="detail-value">Grade <?php echo htmlspecialchars($order['quality_grade']); ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Organic:</span>
                <span class="detail-value"><?php echo $order['organic_certified'] ? 'Yes' : 'No'; ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Quantity:</span>
                <span class="detail-value"><?php echo number_format($order['quantity'], 2); ?> <?php echo $order['unit']; ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Unit Price:</span>
                <span class="detail-value">₹<?php echo number_format($order['unit_price'], 2); ?>/<?php echo $order['unit']; ?></span>
            </div>
            
            <?php if (!empty($product_images)): ?>
            <div class="detail-row">
                <span class="detail-label">Images:</span>
                <div class="product-images">
                    <?php foreach (array_slice($product_images, 0, 3) as $image): ?>
                        <img src="../<?php echo htmlspecialchars($image); ?>" alt="Product" class="product-image">
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Farmer Information -->
        <div class="detail-section">
            <h3 class="section-title"><i class="fas fa-user"></i> Farmer Details</h3>
            
            <div class="detail-row">
                <span class="detail-label">Farm Name:</span>
                <span class="detail-value"><?php echo htmlspecialchars($order['farm_name']); ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Farmer:</span>
                <span class="detail-value"><?php echo htmlspecialchars($order['farmer_username']); ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Location:</span>
                <span class="detail-value">
                    <?php 
                    $location_parts = array_filter([
                        $order['farmer_village'],
                        $order['farmer_district'],
                        $order['farmer_state']
                    ]);
                    echo htmlspecialchars(implode(', ', $location_parts));
                    ?>
                </span>
            </div>
            
            <?php if (!empty($order['farmer_pincode'])): ?>
            <div class="detail-row">
                <span class="detail-label">Pincode:</span>
                <span class="detail-value"><?php echo htmlspecialchars($order['farmer_pincode']); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="detail-row">
                <span class="detail-label">Contact:</span>
                <span class="detail-value"><?php echo htmlspecialchars($order['farmer_phone']); ?></span>
            </div>
        </div>
    </div>

    <!-- Pricing Breakdown -->
    <div class="pricing-breakdown">
        <h3 class="section-title"><i class="fas fa-calculator"></i> Pricing Breakdown</h3>
        
        <div class="pricing-row">
            <span>Subtotal (<?php echo number_format($order['quantity'], 2); ?> <?php echo $order['unit']; ?> × ₹<?php echo number_format($order['unit_price'], 2); ?>):</span>
            <span>₹<?php echo number_format($order['quantity'] * $order['unit_price'], 2); ?></span>
        </div>
        
        <div class="pricing-row">
            <span>Platform Commission (<?php echo number_format($order['commission_rate'] ?? 2.5, 1); ?>%):</span>
            <span>₹<?php echo number_format($commission_amount, 2); ?></span>
        </div>
        
        <div class="pricing-row">
            <span>Farmer Earnings:</span>
            <span>₹<?php echo number_format($farmer_earnings, 2); ?></span>
        </div>
        
        <div class="pricing-row">
            <span>Total Amount:</span>
            <span>₹<?php echo number_format($order['total_amount'], 2); ?></span>
        </div>
    </div>

    <!-- Order Timeline -->
    <div class="timeline">
        <h3 class="section-title"><i class="fas fa-clock"></i> Order Timeline</h3>
        
        <?php
        $timeline_items = [
            ['status' => 'pending', 'title' => 'Order Placed', 'icon' => 'fas fa-shopping-cart'],
            ['status' => 'confirmed', 'title' => 'Order Confirmed', 'icon' => 'fas fa-check-circle'],
            ['status' => 'processing', 'title' => 'Processing', 'icon' => 'fas fa-cog'],
            ['status' => 'shipped', 'title' => 'Shipped', 'icon' => 'fas fa-truck'],
            ['status' => 'delivered', 'title' => 'Delivered', 'icon' => 'fas fa-box-open']
        ];
        
        $current_status = $order['order_status'];
        $status_order = ['pending', 'confirmed', 'processing', 'shipped', 'delivered'];
        $current_index = array_search($current_status, $status_order);
        
        foreach ($timeline_items as $index => $item):
            $is_completed = $index < $current_index || ($current_status === 'delivered' && $item['status'] === 'delivered');
            $is_current = $item['status'] === $current_status;
            $class = $is_completed ? 'timeline-completed' : ($is_current ? 'timeline-current' : 'timeline-pending');
        ?>
        <div class="timeline-item">
            <div class="timeline-icon <?php echo $class; ?>">
                <i class="<?php echo $item['icon']; ?>"></i>
            </div>
            <div class="timeline-content">
                <div class="timeline-title"><?php echo $item['title']; ?></div>
                <?php if ($item['status'] === 'pending'): ?>
                    <div class="timeline-date"><?php echo date('d M Y, H:i', strtotime($order['created_at'])); ?></div>
                <?php elseif ($is_completed || $is_current): ?>
                    <div class="timeline-date">
                        <?php 
                        if ($item['status'] === $current_status) {
                            echo date('d M Y, H:i', strtotime($order['updated_at'] ?? $order['created_at']));
                        } else {
                            echo 'Completed';
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Delivery Information -->
    <?php if (!empty($order['delivery_address'])): ?>
    <div class="detail-section">
        <h3 class="section-title"><i class="fas fa-map-marker-alt"></i> Delivery Information</h3>
        
        <div class="detail-row">
            <span class="detail-label">Delivery Address:</span>
            <span class="detail-value"><?php echo htmlspecialchars($order['delivery_address']); ?></span>
        </div>
        
        <?php if (!empty($order['delivery_date'])): ?>
        <div class="detail-row">
            <span class="detail-label">Expected Delivery:</span>
            <span class="detail-value"><?php echo date('d M Y', strtotime($order['delivery_date'])); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Order Dates -->
    <div class="detail-section">
        <h3 class="section-title"><i class="fas fa-calendar"></i> Important Dates</h3>
        
        <div class="detail-row">
            <span class="detail-label">Order Date:</span>
            <span class="detail-value"><?php echo date('d M Y, H:i A', strtotime($order['created_at'])); ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Last Updated:</span>
            <span class="detail-value"><?php echo date('d M Y, H:i A', strtotime($order['updated_at'])); ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Payment Method:</span>
            <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Payment Status:</span>
            <span class="detail-value"><?php echo ucfirst($order['payment_status']); ?></span>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <?php if ($order['order_status'] === 'pending'): ?>
            <button class="btn-action btn-danger" onclick="cancelOrderFromDetails(<?php echo $order['id']; ?>)">
                <i class="fas fa-times"></i> Cancel Order
            </button>
        <?php endif; ?>
        
        <?php if (in_array($order['order_status'], ['confirmed', 'processing', 'shipped'])): ?>
            <button class="btn-action btn-primary" onclick="trackOrderFromDetails('<?php echo $order['order_number']; ?>')">
                <i class="fas fa-truck"></i> Track Order
            </button>
        <?php endif; ?>
        
        <?php if ($order['order_status'] === 'delivered'): ?>
            <button class="btn-action btn-secondary" onclick="downloadInvoice(<?php echo $order['id']; ?>)">
                <i class="fas fa-file-pdf"></i> Download Invoice
            </button>
        <?php endif; ?>
        
        <a href="tel:<?php echo htmlspecialchars($order['farmer_phone']); ?>" class="btn-action btn-secondary">
            <i class="fas fa-phone"></i> Contact Farmer
        </a>
    </div>
</div>

<script>
function cancelOrderFromDetails(orderId) {
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
                parent.location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error cancelling order');
        });
    }
}

function trackOrderFromDetails(orderNumber) {
    alert('Tracking feature will be implemented. Order: ' + orderNumber);
}

function downloadInvoice(orderId) {
    window.open('generate_invoice.php?order_id=' + orderId, '_blank');
}
</script>
