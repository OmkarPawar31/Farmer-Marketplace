<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a farmer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'farmer') {
    header('Location: login.php');
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Delete product
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $product_id = $_POST['product_id'];
        
        try {
            // First check if the product belongs to this farmer
            $check_stmt = $db->prepare("
                SELECT pl.id FROM product_listings pl 
                JOIN farmers f ON pl.farmer_id = f.id 
                WHERE pl.id = ? AND f.user_id = ?
            ");
            $check_stmt->execute([$product_id, $user_id]);
            
            if ($check_stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Product not found or access denied.']);
                exit();
            }
            
            // Delete the product
            $delete_stmt = $db->prepare("DELETE FROM product_listings WHERE id = ?");
            $delete_stmt->execute([$product_id]);
            
            echo json_encode(['success' => true, 'message' => 'Product deleted successfully!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error deleting product.']);
        }
        exit();
    }
    
    // Update product
    if (isset($_POST['action']) && $_POST['action'] === 'update') {
        $product_id = $_POST['product_id'];
        $title = trim($_POST['title']);
        $quantity = $_POST['quantity_available'];
        $price = $_POST['price_per_unit'];
        $description = trim($_POST['description']);
        $quality_grade = $_POST['quality_grade'];
        $organic_certified = isset($_POST['organic_certified']) ? 1 : 0;
        $packaging_available = isset($_POST['packaging_available']) ? 1 : 0;
        $expiry_date = $_POST['expiry_date'] ?: null;
        
        try {
            // Check if the product belongs to this farmer
            $check_stmt = $db->prepare("
                SELECT pl.id FROM product_listings pl 
                JOIN farmers f ON pl.farmer_id = f.id 
                WHERE pl.id = ? AND f.user_id = ?
            ");
            $check_stmt->execute([$product_id, $user_id]);
            
            if ($check_stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Product not found or access denied.']);
                exit();
            }
            
            // Determine listing type based on quantity
            $listing_type = 'vendor'; // Default to vendor
            if ($quantity >= 500) {
                $listing_type = 'company';
            } elseif ($quantity >= 400 && $quantity <= 600) {
                // Allow dual listing for quantities between 400kg and 600kg
                $listing_type = isset($_POST['listing_type']) && in_array($_POST['listing_type'], ['vendor', 'company', 'both']) ? $_POST['listing_type'] : 'vendor';
            }
            
            // Update the product
            $update_stmt = $db->prepare("
                UPDATE product_listings SET 
                    title = ?, 
                    quantity_available = ?, 
                    price_per_unit = ?, 
                    description = ?, 
                    quality_grade = ?, 
                    organic_certified = ?, 
                    packaging_available = ?, 
                    expiry_date = ?,
                    listing_type = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([
                $title, $quantity, $price, $description, $quality_grade, 
                $organic_certified, $packaging_available, $expiry_date, $listing_type, $product_id
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Product updated successfully!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error updating product.']);
        }
        exit();
    }
}

// Fetch farmer's products
$products_stmt = $db->prepare("
    SELECT pl.*, c.name as crop_name, cc.name as category_name
    FROM product_listings pl
    JOIN farmers f ON pl.farmer_id = f.id
    JOIN crops c ON pl.crop_id = c.id
    JOIN crop_categories cc ON c.category_id = cc.id
    WHERE f.user_id = ?
    ORDER BY pl.created_at DESC
");
$products_stmt->execute([$user_id]);
$products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get crops for edit modal
$crops_stmt = $db->prepare("SELECT * FROM crops WHERE is_active = 1 ORDER BY name");
$crops_stmt->execute();
$crops = $crops_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Products - Farmer Marketplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 80px 20px 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }

        .page-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
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
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }

        .product-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .product-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #264653;
            margin: 0;
            flex: 1;
        }

        .product-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-active { background: #f0fdf4; color: #2a9d8f; }
        .status-sold { background: #fef2f2; color: #ef4444; }
        .status-inactive { background: #f3f4f6; color: #6b7280; }

        .product-info {
            margin-bottom: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .info-label {
            color: #666;
            font-size: 14px;
        }

        .info-value {
            font-weight: 500;
            color: #264653;
        }

        .product-description {
            color: #666;
            font-size: 14px;
            line-height: 1.4;
            margin-bottom: 20px;
        }

        .product-actions {
            display: flex;
            gap: 10px;
        }

        .btn-edit, .btn-delete {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .btn-edit {
            background: #2a9d8f;
            color: white;
        }

        .btn-edit:hover {
            background: #219f8b;
            transform: translateY(-2px);
        }

        .btn-delete {
            background: #ef4444;
            color: white;
        }

        .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }

        .no-products {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .no-products i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
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
            max-width: 600px;
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

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
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

        .form-group {
            margin-bottom: 20px;
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
            min-height: 80px;
        }

        .checkbox-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .btn-cancel, .btn-save {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-cancel {
            background: #f8f9fa;
            color: #666;
            border: 1px solid #ddd;
        }

        .btn-cancel:hover {
            background: #e9ecef;
        }

        .btn-save {
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 157, 143, 0.4);
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

        @media (max-width: 768px) {
            .products-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .modal-content {
                margin: 5% auto;
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="page-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-boxes"></i> My Products</h1>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <!-- Products Grid -->
        <div class="products-grid">
            <?php if (empty($products)): ?>
                <div class="no-products" style="grid-column: 1 / -1;">
                    <i class="fas fa-seedling"></i>
                    <h3>No Products Yet</h3>
                    <p>You haven't added any products to your marketplace. <a href="dashboard.php">Add your first product</a> to start selling!</p>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <div class="product-card">
                        <div class="product-header">
                            <h3 class="product-title"><?php echo htmlspecialchars($product['title']); ?></h3>
                            <span class="product-status status-<?php echo $product['status']; ?>">
                                <?php echo ucfirst($product['status']); ?>
                            </span>
                        </div>
                        
                        <div class="product-info">
                            <div class="info-row">
                                <span class="info-label">Crop:</span>
                                <span class="info-value"><?php echo htmlspecialchars($product['crop_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Quantity:</span>
                                <span class="info-value"><?php echo number_format($product['quantity_available'], 2); ?> <?php echo $product['unit']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Price:</span>
                                <span class="info-value">₹<?php echo number_format($product['price_per_unit'], 2); ?>/<?php echo $product['unit']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Quality:</span>
                                <span class="info-value">Grade <?php echo $product['quality_grade']; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Harvest Date:</span>
                                <span class="info-value"><?php echo date('M j, Y', strtotime($product['harvest_date'])); ?></span>
                            </div>
                            <?php if ($product['organic_certified']): ?>
                                <div class="info-row">
                                    <span class="info-label">Certification:</span>
                                    <span class="info-value"><i class="fas fa-leaf" style="color: #2a9d8f;"></i> Organic Certified</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($product['description']): ?>
                            <div class="product-description">
                                <?php echo htmlspecialchars(substr($product['description'], 0, 100)); ?>
                                <?php if (strlen($product['description']) > 100) echo '...'; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="product-actions">
                            <button class="btn-edit" onclick="editProduct(<?php echo $product['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn-delete" onclick="deleteProduct(<?php echo $product['id']; ?>)">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Product</h3>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" id="edit_product_id" name="product_id">
                    
                    <div class="form-group">
                        <label for="edit_title">Product Title *</label>
                        <input type="text" id="edit_title" name="title" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_quantity">Quantity Available *</label>
                        <input type="number" id="edit_quantity" name="quantity_available" step="0.01" min="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_price">Price per Unit (₹) *</label>
                        <input type="number" id="edit_price" name="price_per_unit" step="0.01" min="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_quality">Quality Grade</label>
                        <select id="edit_quality" name="quality_grade">
                            <option value="A">Grade A - Premium</option>
                            <option value="B">Grade B - Standard</option>
                            <option value="C">Grade C - Basic</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_expiry">Best Before Date</label>
                        <input type="date" id="edit_expiry" name="expiry_date">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_description">Description</label>
                        <textarea id="edit_description" name="description" placeholder="Describe your product quality, farming methods, etc."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Additional Options</label>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" id="edit_organic" name="organic_certified" value="1">
                                <label for="edit_organic">Organic Certified</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="edit_packaging" name="packaging_available" value="1">
                                <label for="edit_packaging">Packaging Available</label>
                            </div>
                        </div>
                        
                        <!-- Listing Type Field (only for 400-600kg range) -->
                        <div id="listing_type_container" class="form-group" style="display: none;">
                            <label for="edit_listing_type">Listing Type <i class="fas fa-info-circle" title="This option is available for quantities between 400-600kg"></i></label>
                            <select id="edit_listing_type" name="listing_type">
                                <option value="vendor">Vendor Dashboard (under 500kg)</option>
                                <option value="company">Company Dashboard (500kg+)</option>
                                <option value="both">Both Dashboards</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="button" class="btn-save" onclick="saveProduct()">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </div>
    </div>

    <script>
        // Store products data for editing
        const products = <?php echo json_encode($products); ?>;
        
        // Edit product function
        function editProduct(productId) {
            const product = products.find(p => p.id == productId);
            if (!product) return;
            
            // Populate form fields
            document.getElementById('edit_product_id').value = product.id;
            document.getElementById('edit_title').value = product.title;
            document.getElementById('edit_quantity').value = product.quantity_available;
            document.getElementById('edit_price').value = product.price_per_unit;
            document.getElementById('edit_quality').value = product.quality_grade;
            document.getElementById('edit_expiry').value = product.expiry_date || '';
            document.getElementById('edit_description').value = product.description || '';
            document.getElementById('edit_organic').checked = product.organic_certified == 1;
            document.getElementById('edit_packaging').checked = product.packaging_available == 1;

            // Show/hide listing type based on quantity
            const quantityInput = document.getElementById('edit_quantity');
            const listingTypeContainer = document.getElementById('listing_type_container');
            
            const checkQuantity = () => {
                const quantity = parseFloat(quantityInput.value);
                if (quantity >= 400 && quantity <= 600) {
                    listingTypeContainer.style.display = 'block';
                } else {
                    listingTypeContainer.style.display = 'none';
                }
            };
            
            quantityInput.addEventListener('input', checkQuantity);
            
            // Set initial state
            checkQuantity();
            document.getElementById('edit_listing_type').value = product.listing_type || 'vendor';

            // Show modal
            document.getElementById('editModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        // Close edit modal
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Save product changes
        function saveProduct() {
            const form = document.getElementById('editForm');
            const formData = new FormData(form);
            formData.append('action', 'update');
            
            // Disable save button
            const saveBtn = document.querySelector('.btn-save');
            const originalText = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            fetch('my-products.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeEditModal();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while updating the product', 'error');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalText;
            });
        }
        
        // Delete product function
        function deleteProduct(productId) {
            if (!confirm('Are you sure you want to delete this product? This action cannot be undone.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('product_id', productId);
            
            fetch('my-products.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while deleting the product', 'error');
            });
        }
        
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
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target === modal) {
                closeEditModal();
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeEditModal();
            }
        });
    </script>
</body>
</html>
