<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Initialize database connection
$pdo = getDB();

// Check if user is logged in and is a buyer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'buyer') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Buyer';

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 12;
$offset = ($page - 1) * $records_per_page;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$location_filter = isset($_GET['location']) ? trim($_GET['location']) : '';
$category_filter = isset($_GET['category']) ? trim($_GET['category']) : '';
$rating_filter = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'rating_desc';

// Build WHERE clause for filtering
$where_conditions = ["u.user_type = 'farmer'", "u.status = 'active'"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.business_name LIKE ? OR fs.specialization LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($location_filter)) {
    $where_conditions[] = "u.location LIKE ?";
    $params[] = "%$location_filter%";
}

if (!empty($category_filter)) {
    $where_conditions[] = "fs.specialization LIKE ?";
    $params[] = "%$category_filter%";
}

if ($rating_filter > 0) {
    $where_conditions[] = "fs.rating >= ?";
    $params[] = $rating_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Determine ORDER BY clause
$order_clause = "fs.rating DESC";
switch ($sort_by) {
    case 'name_asc':
        $order_clause = "u.full_name ASC";
        break;
    case 'name_desc':
        $order_clause = "u.full_name DESC";
        break;
    case 'location_asc':
        $order_clause = "u.location ASC";
        break;
    case 'rating_asc':
        $order_clause = "fs.rating ASC";
        break;
    case 'rating_desc':
    default:
        $order_clause = "fs.rating DESC";
        break;
}

// Get total count for pagination
try {
    $count_sql = "SELECT COUNT(DISTINCT u.id) as total 
                  FROM users u 
                  LEFT JOIN farmer_stats fs ON u.id = fs.farmer_id 
                  WHERE $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $records_per_page);
} catch (PDOException $e) {
    $total_records = 0;
    $total_pages = 0;
}

// Get suppliers/farmers data
try {
    $sql = "SELECT DISTINCT u.id, u.full_name, u.business_name, u.email, u.phone, 
                   u.location, u.profile_image, u.created_at,
                   fs.total_products, fs.total_orders, fs.rating, fs.reviews_count,
                   fs.specialization, fs.verified_status
            FROM users u 
            LEFT JOIN farmer_stats fs ON u.id = fs.farmer_id 
            WHERE $where_clause 
            ORDER BY $order_clause 
            LIMIT $records_per_page OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
    $error_message = "Error fetching suppliers: " . $e->getMessage();
}

// Get unique locations and categories for filters
try {
    $locations_sql = "SELECT DISTINCT location FROM users WHERE user_type = 'farmer' AND location IS NOT NULL AND location != '' ORDER BY location";
    $locations_stmt = $pdo->query($locations_sql);
    $locations = $locations_stmt->fetchAll(PDO::FETCH_COLUMN);

    $categories_sql = "SELECT DISTINCT specialization FROM farmer_stats WHERE specialization IS NOT NULL AND specialization != '' ORDER BY specialization";
    $categories_stmt = $pdo->query($categories_sql);
    $categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $locations = [];
    $categories = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .suppliers-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            background: linear-gradient(135deg, #2E8B57, #90EE90);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }

        .page-header h1 {
            margin: 0;
            font-size: 2.5rem;
        }

        .page-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }

        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }

        .filter-group input, .filter-group select {
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .filter-group input:focus, .filter-group select:focus {
            outline: none;
            border-color: #2E8B57;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #2E8B57;
            color: white;
        }

        .btn-primary:hover {
            background: #1e5d3a;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .suppliers-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-card .icon {
            font-size: 2rem;
            color: #2E8B57;
            margin-bottom: 10px;
        }

        .stat-card .number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .stat-card .label {
            color: #666;
            font-size: 0.9rem;
        }

        .suppliers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .supplier-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .supplier-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .supplier-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
        }

        .supplier-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #2E8B57;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .profile-info h3 {
            margin: 0 0 5px 0;
            color: #333;
            font-size: 1.2rem;
        }

        .profile-info .business-name {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .rating {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .stars {
            color: #ffc107;
        }

        .rating-text {
            font-size: 0.9rem;
            color: #666;
        }

        .verified-badge {
            background: #28a745;
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 10px;
        }

        .supplier-details {
            padding: 20px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }

        .detail-label {
            color: #666;
            font-weight: 500;
        }

        .detail-value {
            color: #333;
            font-weight: 600;
        }

        .specialization-tags {
            margin: 15px 0;
        }

        .tag {
            display: inline-block;
            background: #e8f5e8;
            color: #2E8B57;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 0.8rem;
            margin-right: 5px;
            margin-bottom: 5px;
        }

        .supplier-actions {
            display: flex;
            gap: 10px;
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 0.85rem;
            flex: 1;
            text-align: center;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #2E8B57;
            color: #2E8B57;
        }

        .btn-outline:hover {
            background: #2E8B57;
            color: white;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }

        .pagination a, .pagination span {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
        }

        .pagination a:hover {
            background: #2E8B57;
            color: white;
            border-color: #2E8B57;
        }

        .pagination .current {
            background: #2E8B57;
            color: white;
            border-color: #2E8B57;
        }

        .no-suppliers {
            text-align: center;
            padding: 50px;
            color: #666;
        }

        .no-suppliers i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ddd;
        }

        @media (max-width: 768px) {
            .suppliers-container {
                padding: 10px;
            }

            .filters-row {
                grid-template-columns: 1fr;
            }

            .suppliers-grid {
                grid-template-columns: 1fr;
            }

            .suppliers-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-actions {
                justify-content: stretch;
            }

            .filter-actions .btn {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <div class="suppliers-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-users"></i> Suppliers Directory</h1>
            <p>Connect with trusted farmers and suppliers for your business needs</p>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" action="">
                <div class="filters-row">
                    <div class="filter-group">
                        <label for="search">Search Suppliers</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by name, business, or specialization...">
                    </div>
                    
                    <div class="filter-group">
                        <label for="location">Location</label>
                        <select id="location" name="location">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo htmlspecialchars($location); ?>" 
                                        <?php echo $location_filter === $location ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($location); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" 
                                        <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="rating">Minimum Rating</label>
                        <select id="rating" name="rating">
                            <option value="0">Any Rating</option>
                            <option value="1" <?php echo $rating_filter === 1 ? 'selected' : ''; ?>>1+ Stars</option>
                            <option value="2" <?php echo $rating_filter === 2 ? 'selected' : ''; ?>>2+ Stars</option>
                            <option value="3" <?php echo $rating_filter === 3 ? 'selected' : ''; ?>>3+ Stars</option>
                            <option value="4" <?php echo $rating_filter === 4 ? 'selected' : ''; ?>>4+ Stars</option>
                            <option value="5" <?php echo $rating_filter === 5 ? 'selected' : ''; ?>>5 Stars</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="sort">Sort By</label>
                        <select id="sort" name="sort">
                            <option value="rating_desc" <?php echo $sort_by === 'rating_desc' ? 'selected' : ''; ?>>Highest Rated</option>
                            <option value="rating_asc" <?php echo $sort_by === 'rating_asc' ? 'selected' : ''; ?>>Lowest Rated</option>
                            <option value="name_asc" <?php echo $sort_by === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                            <option value="name_desc" <?php echo $sort_by === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                            <option value="location_asc" <?php echo $sort_by === 'location_asc' ? 'selected' : ''; ?>>Location</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="suppliers.php" class="btn btn-secondary">
                        <i class="fas fa-refresh"></i> Clear Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Statistics -->
        <div class="suppliers-stats">
            <div class="stat-card">
                <div class="icon"><i class="fas fa-users"></i></div>
                <div class="number"><?php echo number_format($total_records); ?></div>
                <div class="label">Total Suppliers</div>
            </div>
            <div class="stat-card">
                <div class="icon"><i class="fas fa-map-marker-alt"></i></div>
                <div class="number"><?php echo count($locations); ?></div>
                <div class="label">Locations</div>
            </div>
            <div class="stat-card">
                <div class="icon"><i class="fas fa-tags"></i></div>
                <div class="number"><?php echo count($categories); ?></div>
                <div class="label">Categories</div>
            </div>
            <div class="stat-card">
                <div class="icon"><i class="fas fa-star"></i></div>
                <div class="number">
                    <?php 
                    // Calculate average rating
                    $avg_rating = 0;
                    if (!empty($suppliers)) {
                        $total_rating = array_sum(array_column($suppliers, 'rating'));
                        $avg_rating = $total_rating / count($suppliers);
                    }
                    echo number_format($avg_rating, 1);
                    ?>
                </div>
                <div class="label">Avg Rating</div>
            </div>
        </div>

        <!-- Suppliers Grid -->
        <?php if (!empty($suppliers)): ?>
            <div class="suppliers-grid">
                <?php foreach ($suppliers as $supplier): ?>
                    <div class="supplier-card">
                        <div class="supplier-header">
                            <div class="supplier-profile">
                                <div class="profile-avatar">
                                    <?php if (!empty($supplier['profile_image'])): ?>
                                        <img src="../uploads/profiles/<?php echo htmlspecialchars($supplier['profile_image']); ?>" 
                                             alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($supplier['full_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="profile-info">
                                    <h3>
                                        <?php echo htmlspecialchars($supplier['full_name']); ?>
                                        <?php if ($supplier['verified_status'] === 'verified'): ?>
                                            <span class="verified-badge">
                                                <i class="fas fa-check"></i> Verified
                                            </span>
                                        <?php endif; ?>
                                    </h3>
                                    <?php if (!empty($supplier['business_name'])): ?>
                                        <div class="business-name"><?php echo htmlspecialchars($supplier['business_name']); ?></div>
                                    <?php endif; ?>
                                    <div class="rating">
                                        <div class="stars">
                                            <?php
                                            $rating = floatval($supplier['rating']);
                                            for ($i = 1; $i <= 5; $i++) {
                                                if ($i <= $rating) {
                                                    echo '<i class="fas fa-star"></i>';
                                                } elseif ($i - 0.5 <= $rating) {
                                                    echo '<i class="fas fa-star-half-alt"></i>';
                                                } else {
                                                    echo '<i class="far fa-star"></i>';
                                                }
                                            }
                                            ?>
                                        </div>
                                        <span class="rating-text">
                                            <?php echo number_format($rating, 1); ?> 
                                            (<?php echo number_format($supplier['reviews_count']); ?> reviews)
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="supplier-details">
                            <div class="detail-row">
                                <span class="detail-label">Location:</span>
                                <span class="detail-value">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?php echo htmlspecialchars($supplier['location'] ?: 'Not specified'); ?>
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">Products:</span>
                                <span class="detail-value">
                                    <?php echo number_format($supplier['total_products'] ?: 0); ?> items
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">Orders Completed:</span>
                                <span class="detail-value">
                                    <?php echo number_format($supplier['total_orders'] ?: 0); ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($supplier['specialization'])): ?>
                                <div class="specialization-tags">
                                    <?php 
                                    $specializations = explode(',', $supplier['specialization']);
                                    foreach ($specializations as $spec): 
                                        $spec = trim($spec);
                                        if (!empty($spec)):
                                    ?>
                                        <span class="tag"><?php echo htmlspecialchars($spec); ?></span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="supplier-actions">
                                <a href="tel:<?php echo htmlspecialchars($supplier['phone']); ?>" 
                                   class="btn btn-outline btn-sm">
                                    <i class="fas fa-phone"></i> Call
                                </a>
                                <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" 
                                   class="btn btn-outline btn-sm">
                                    <i class="fas fa-envelope"></i> Email
                                </a>
                                <button onclick="viewSupplierProducts(<?php echo $supplier['id']; ?>)" 
                                        class="btn btn-primary btn-sm">
                                    <i class="fas fa-shopping-bag"></i> Products
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-suppliers">
                <i class="fas fa-users-slash"></i>
                <h3>No Suppliers Found</h3>
                <p>Try adjusting your search criteria or filters to find suppliers.</p>
                <a href="suppliers.php" class="btn btn-primary">Clear All Filters</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function viewSupplierProducts(supplierId) {
            // Redirect to marketplace with supplier filter
            window.location.href = `../marketplace.php?supplier=${supplierId}`;
        }

        // Auto-submit form on filter changes (optional)
        document.addEventListener('DOMContentLoaded', function() {
            const filterSelects = document.querySelectorAll('#location, #category, #rating, #sort');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    // Optionally auto-submit form on change
                    // this.form.submit();
                });
            });
        });
    </script>
</body>
</html>
