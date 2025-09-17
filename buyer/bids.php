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

// Handle bid cancellation
if (isset($_POST['cancel_bid'])) {
    $bid_id = $_POST['bid_id'];
    try {
        $stmt = $conn->prepare("
            DELETE FROM bids 
            WHERE id = ? AND buyer_id = (SELECT id FROM buyers WHERE user_id = ?)
        ");
        $stmt->execute([$bid_id, $user_id]);
        $success_message = "Bid cancelled successfully!";
    } catch (Exception $e) {
        $error_message = "Error cancelling bid: " . $e->getMessage();
    }
}

// Get filters
$status_filter = $_GET['status'] ?? 'all';
$sort_by = $_GET['sort'] ?? 'latest';

// Build query conditions
$where_conditions = ["buyer.user_id = ?"];
$params = [$user_id];

if ($status_filter !== 'all') {
    $where_conditions[] = "a.status = ?";
    $params[] = $status_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Build sort clause
$sort_clause = match($sort_by) {
    'oldest' => 'b.created_at ASC',
    'amount_high' => 'b.bid_amount DESC',
    'amount_low' => 'b.bid_amount ASC',
    default => 'b.created_at DESC'
};

try {
    // Get all bids with pagination
    $page = max(1, $_GET['page'] ?? 1);
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    $stmt = $conn->prepare("
        SELECT b.*, a.product_id, a.status as auction_status, a.end_time, a.current_bid, a.winner_id,
               p.title as product_title, p.price_per_unit, c.name as crop_name,
               f.farm_name, u.username as farmer_username, f.state, f.district,
               b.created_at as bid_date,
               CASE WHEN a.winner_id = b.id THEN 'won' 
                    WHEN a.status = 'ended' AND a.winner_id != b.id THEN 'lost'
                    WHEN a.status = 'active' THEN 'active'
                    ELSE 'expired' END as bid_status
        FROM bids b
        JOIN buyers buyer ON b.buyer_id = buyer.id
        JOIN auctions a ON b.auction_id = a.id  
        JOIN product_listings p ON a.product_id = p.id
        JOIN crops c ON p.crop_id = c.id
        JOIN farmers f ON p.farmer_id = f.id
        JOIN users u ON f.user_id = u.id
        WHERE $where_clause
        ORDER BY $sort_clause
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $bids = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $count_stmt = $conn->prepare("
        SELECT COUNT(*) as total
        FROM bids b
        JOIN buyers buyer ON b.buyer_id = buyer.id
        JOIN auctions a ON b.auction_id = a.id
        WHERE $where_clause
    ");
    $count_stmt->execute($params);
    $total_bids = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_bids / $per_page);
    
    // Get statistics
    $stats_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_bids,
            COUNT(CASE WHEN a.status = 'active' THEN 1 END) as active_bids,
            COUNT(CASE WHEN a.winner_id = b.id THEN 1 END) as won_bids,
            COUNT(CASE WHEN a.status = 'ended' AND a.winner_id != b.id THEN 1 END) as lost_bids,
            COALESCE(SUM(b.bid_amount), 0) as total_bid_amount
        FROM bids b
        JOIN buyers buyer ON b.buyer_id = buyer.id
        JOIN auctions a ON b.auction_id = a.id
        WHERE buyer.user_id = ?
    ");
    $stats_stmt->execute([$user_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Bids page error: " . $e->getMessage());
    $bids = [];
    $stats = ['total_bids' => 0, 'active_bids' => 0, 'won_bids' => 0, 'lost_bids' => 0, 'total_bid_amount' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bids - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .bids-container {
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
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .bid-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .bid-card:hover {
            transform: translateY(-2px);
        }
        
        .bid-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .bid-amount {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2a9d8f;
        }
        
        .bid-status {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active { background: #e3f2fd; color: #1976d2; }
        .status-won { background: #e8f5e8; color: #2e7d32; }
        .status-lost { background: #ffebee; color: #c62828; }
        .status-expired { background: #f5f5f5; color: #616161; }
        
        .bid-details {
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
        
        .bid-info {
            text-align: right;
        }
        
        .bid-actions {
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
        
        .no-bids {
            text-align: center;
            padding: 60px;
            color: #666;
        }
        
        .no-bids i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ccc;
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
            color: #264653;
        }
        
        .pagination .current {
            background: #2a9d8f;
            color: white;
            border-color: #2a9d8f;
        }
    </style>
</head>
<body>
    <div class="bids-container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1><i class="fas fa-gavel"></i> My Bids</h1>
                <p>Track your auction bids and manage bidding activity</p>
            </div>
            <div>
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
                <div class="stat-number"><?= number_format($stats['total_bids']) ?></div>
                <div class="stat-label">Total Bids</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['active_bids']) ?></div>
                <div class="stat-label">Active Bids</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['won_bids']) ?></div>
                <div class="stat-label">Won Auctions</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">₹<?= number_format($stats['total_bid_amount'], 0) ?></div>
                <div class="stat-label">Total Bid Amount</div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters-section">
            <form method="GET" class="filters-grid">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Bids</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active Auctions</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sort By</label>
                    <select name="sort" class="form-control">
                        <option value="latest" <?= $sort_by === 'latest' ? 'selected' : '' ?>>Latest First</option>
                        <option value="oldest" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="amount_high" <?= $sort_by === 'amount_high' ? 'selected' : '' ?>>Highest Amount</option>
                        <option value="amount_low" <?= $sort_by === 'amount_low' ? 'selected' : '' ?>>Lowest Amount</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Bids List -->
        <?php if (empty($bids)): ?>
            <div class="no-bids">
                <i class="fas fa-gavel"></i>
                <h3>No bids found</h3>
                <p>You haven't placed any bids yet.</p>
                <a href="auctions.php" class="btn btn-primary">
                    <i class="fas fa-search"></i> Browse Active Auctions
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($bids as $bid): ?>
                <div class="bid-card">
                    <div class="bid-header">
                        <div class="bid-amount">₹<?= number_format($bid['bid_amount'], 2) ?></div>
                        <div class="bid-status status-<?= $bid['bid_status'] ?>">
                            <?= ucfirst($bid['bid_status']) ?>
                        </div>
                    </div>
                    
                    <div class="bid-details">
                        <div class="product-info">
                            <h3><?= htmlspecialchars($bid['product_title']) ?></h3>
                            <div class="product-meta">
                                <p><i class="fas fa-seedling"></i> <?= htmlspecialchars($bid['crop_name']) ?></p>
                                <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($bid['farm_name']) ?>, <?= htmlspecialchars($bid['district']) ?></p>
                                <p><i class="fas fa-user"></i> <?= htmlspecialchars($bid['farmer_username']) ?></p>
                                <p><i class="fas fa-calendar"></i> Bid placed: <?= date('d M Y, H:i', strtotime($bid['bid_date'])) ?></p>
                                <?php if ($bid['auction_status'] === 'active'): ?>
                                    <p><i class="fas fa-clock"></i> Ends: <?= date('d M Y, H:i', strtotime($bid['end_time'])) ?></p>
                                    <p><i class="fas fa-gavel"></i> Current bid: ₹<?= number_format($bid['current_bid'], 2) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="bid-info">
                            <div class="bid-actions">
                                <?php if ($bid['bid_status'] === 'active'): ?>
                                    <a href="auctions.php?id=<?= $bid['auction_id'] ?>" class="btn btn-primary btn-small">
                                        <i class="fas fa-eye"></i> View Auction
                                    </a>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Are you sure you want to cancel this bid?')">
                                        <input type="hidden" name="bid_id" value="<?= $bid['id'] ?>">
                                        <button type="submit" name="cancel_bid" class="btn btn-danger btn-small">
                                            <i class="fas fa-times"></i> Cancel Bid
                                        </button>
                                    </form>
                                <?php elseif ($bid['bid_status'] === 'won'): ?>
                                    <span class="btn btn-success btn-small">
                                        <i class="fas fa-trophy"></i> You Won!
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&status=<?= $status_filter ?>&sort=<?= $sort_by ?>">Previous</a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>&status=<?= $status_filter ?>&sort=<?= $sort_by ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>&status=<?= $status_filter ?>&sort=<?= $sort_by ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
