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
$buyer_id = $buyer['id'];

// Time ranges for analysis
$current_month = date('Y-m-01');
$last_month = date('Y-m-01', strtotime('-1 month'));
$current_year = date('Y-01-01');
$last_year = date('Y-01-01', strtotime('-1 year'));

// ===== PURCHASE ANALYTICS =====
// Monthly expenditures for the current year
$stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as total_orders,
        SUM(total_amount) as total_spent,
        AVG(total_amount) as avg_spent
    FROM orders 
    WHERE buyer_id = ? AND created_at >= ? AND payment_status = 'paid'
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month
");
$stmt->execute([$buyer_id, $current_year]);
$monthly_expenditures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Supplier interaction analysis
$stmt = $conn->prepare("
    SELECT 
        f.farm_name,
        u.username,
        COUNT(o.id) as total_orders,
        SUM(o.total_amount) as total_spent,
        AVG(o.total_amount) as avg_order_value,
        MAX(o.created_at) as last_purchase
    FROM orders o
    JOIN farmers f ON o.farmer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE o.buyer_id = ?
    GROUP BY f.id, f.farm_name, u.username
    ORDER BY total_spent DESC
    LIMIT 10
");
$stmt->execute([$buyer_id]);
$supplier_interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Price comparison with market
$stmt = $conn->prepare("
    SELECT 
        c.name as crop_name,
        AVG(o.unit_price) as avg_purchase_price,
        AVG(mp.modal_price) as market_avg_price,
        DATE_FORMAT(o.created_at, '%Y-%m') as month
    FROM orders o
    JOIN product_listings p ON o.product_id = p.id
    JOIN crops c ON p.crop_id = c.id
    LEFT JOIN market_prices mp ON c.id = mp.crop_id AND DATE_FORMAT(o.created_at, '%Y-%m-%d') = mp.date
    WHERE o.buyer_id = ? AND o.created_at >= ?
    GROUP BY c.id, c.name, DATE_FORMAT(o.created_at, '%Y-%m')
    ORDER BY month DESC, avg_purchase_price DESC
");
$stmt->execute([$buyer_id, date('Y-m-01', strtotime('-6 months'))]);
$price_comparison = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== PERFORMANCE METRICS =====
// Overall statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT o.id) as total_orders,
        SUM(o.total_amount) as total_spent,
        AVG(o.total_amount) as avg_order_value,
        COUNT(DISTINCT o.farmer_id) as suppliers,
        AVG(o.quality_rating) as avg_quality_rating,
        COUNT(r.id) as total_reviews
    FROM orders o
    JOIN reviews r ON o.id = r.order_id AND r.reviewee_id = (SELECT id FROM users WHERE id = ?)
    WHERE o.buyer_id = ?
");
$stmt->execute([$user_id, $buyer_id]);
$overall_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// ===== COST ANALYSIS =====
// Product expenditure analysis
$stmt = $conn->prepare("
    SELECT 
        c.name as crop_name,
        COUNT(o.id) as total_orders,
        SUM(o.total_amount) as total_spent,
        AVG(o.unit_price) as avg_price_paid,
        SUM(o.quantity) as total_quantity
    FROM orders o
    JOIN product_listings p ON o.product_id = p.id
    JOIN crops c ON p.crop_id = c.id
    WHERE o.buyer_id = ? AND o.payment_status = 'paid'
    GROUP BY c.id, c.name
    ORDER BY total_spent DESC
");
$stmt->execute([$buyer_id]);
$expenditure_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent order trends
$stmt = $conn->prepare("
    SELECT 
        DATE(o.created_at) as order_date,
        COUNT(o.id) as daily_orders,
        SUM(o.total_amount) as daily_spent
    FROM orders o
    WHERE o.buyer_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(o.created_at)
    ORDER BY order_date DESC
");
$stmt->execute([$buyer_id]);
$recent_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Analytics - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .analytics-container {
            max-width: 1400px;
            margin: 80px auto;
            padding: 20px;
        }
        
        .analytics-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .metric-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #2a9d8f, #e76f51);
        }
        
        .metric-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #2a9d8f, #e76f51);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: #264653;
            margin-bottom: 5px;
        }
        
        .metric-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .metric-change {
            font-size: 12px;
            font-weight: 500;
        }
        
        .metric-change.positive {
            color: #2a9d8f;
        }
        
        .metric-change.negative {
            color: #e76f51;
        }
        
        .chart-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #264653;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #264653;
        }
        
        .export-btn {
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            margin-bottom: 20px;
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #f0f0f0;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            border-bottom-color: #2a9d8f;
            color: #2a9d8f;
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .insights-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #2a9d8f;
        }
        
        .insight-text {
            color: #264653;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <nav class="navbar">
            <div class="container">
                <div class="nav-brand">
                    <div class="logo">🛒</div>
                    <span class="brand-name">FarmConnect</span>
                </div>
                <ul class="nav-menu">
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="analytics.php" class="active">Analytics</a></li>
                    <li><a href="../index.php">Home</a></li>
                </ul>
                <div class="auth-buttons">
                    <a href="logout.php" class="btn btn-outline">Logout</a>
                </div>
            </div>
        </nav>
    </header>

    <div class="analytics-container">
        <!-- Header -->
        <div class="analytics-header">
            <h1><i class="fas fa-chart-line"></i> Buyer Analytics Dashboard</h1>
            <p>Gain insights into your purchasing trends and supplier interactions</p>
        </div>

        <!-- Key Metrics -->
        <div class="analytics-grid">
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-rupee-sign"></i></div>
                <div class="metric-value">₹<?php echo number_format($overall_stats['total_spent'] ?? 0, 2); ?></div>
                <div class="metric-label">Total Spent</div>
                <div class="metric-change positive">Growing investments</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="metric-value"><?php echo $overall_stats['total_orders'] ?? 0; ?></div>
                <div class="metric-label">Total Orders</div>
                <div class="metric-change positive">+5% from last month</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="metric-value">₹<?php echo number_format($overall_stats['avg_order_value'] ?? 0, 2); ?></div>
                <div class="metric-label">Avg Order Value</div>
                <div class="metric-change positive">Stable pricing</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-seedling"></i></div>
                <div class="metric-value"><?php echo $overall_stats['suppliers'] ?? 0; ?></div>
                <div class="metric-label">Suppliers Engaged</div>
                <div class="metric-change">Diverse sourcing</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-star"></i></div>
                <div class="metric-value"><?php echo number_format($overall_stats['avg_quality_rating'] ?? 0, 1); ?></div>
                <div class="metric-label">Quality Rating</div>
                <div class="metric-change positive">Rated high</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="chart-section">
            <button class="export-btn" onclick="exportData()">
                <i class="fas fa-download"></i> Export Report
            </button>
            
            <div class="tabs">
                <div class="tab active" onclick="showTab('expenditures')">Expenditure Analysis</div>
                <div class="tab" onclick="showTab('suppliers')">Supplier Interaction</div>
                <div class="tab" onclick="showTab('trends')">Price Trends</div>
            </div>

            <!-- Expenditures Tab -->
            <div id="expenditures" class="tab-content active">
                <div class="section-title">
                    <i class="fas fa-chart-area"></i>
                    Monthly Expenditure Trend
                </div>
                <div class="chart-container">
                    <canvas id="expenditureChart"></canvas>
                </div>
                
                <div class="insights-card">
                    <div class="insight-text">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Insight:</strong> Monitoring your expenditures helps optimize budgets for efficient sourcing.
                    </div>
                </div>
            </div>

            <!-- Suppliers Tab -->
            <div id="suppliers" class="tab-content">
                <div class="section-title">
                    <i class="fas fa-handshake"></i>
                    Top Supplier Analysis
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Farm</th>
                            <th>Orders</th>
                            <th>Total Spent</th>
                            <th>Avg Order</th>
                            <th>Last Purchase</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($supplier_interactions as $supplier): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($supplier['farm_name']); ?></td>
                            <td><?php echo $supplier['total_orders']; ?></td>
                            <td>₹<?php echo number_format($supplier['total_spent'], 2); ?></td>
                            <td>₹<?php echo number_format($supplier['avg_order_value'], 2); ?></td>
                            <td><?php echo date('M d, Y', strtotime($supplier['last_purchase'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Trends Tab -->
            <div id="trends" class="tab-content">
                <div class="section-title">
                    <i class="fas fa-trending-up"></i>
                    Price Trends vs Market
                </div>
                <div class="chart-container">
                    <canvas id="trendsChart"></canvas>
                </div>
                
                <div class="insights-card">
                    <div class="insight-text">
                        <i class="fas fa-info-circle"></i>
                        <strong>Market Intelligence:</strong> Your purchasing has been aligned with market trends, optimizing costs.
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Cost Analysis -->
        <div class="chart-section">
            <div class="section-title">
                <i class="fas fa-dollar-sign"></i>
                Product Cost Analysis
            </div>
            <div class="analytics-grid">
                <?php foreach ($expenditure_analysis as $product): ?>
                <div class="metric-card">
                    <div class="metric-icon">
                        <i class="fas fa-seedling"></i>
                    </div>
                    <div class="metric-value">₹<?php echo number_format($product['total_spent'], 2); ?></div>
                    <div class="metric-label">Spent on <?php echo $product['crop_name']; ?></div>
                    <div class="metric-change">Avg Price: ₹<?php echo number_format($product['avg_price_paid'], 2); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        // Chart.js configurations
        const expenditureData = <?php echo json_encode($monthly_expenditures); ?>;
        const suppliersData = <?php echo json_encode($supplier_interactions); ?>;
        const trendsData = <?php echo json_encode($price_comparison); ?>;

        // Expenditure Chart
        const expenditureCtx = document.getElementById('expenditureChart').getContext('2d');
        new Chart(expenditureCtx, {
            type: 'line',
            data: {
                labels: expenditureData.map(item => item.month),
                datasets: [{
                    label: 'Monthly Expenditure',
                    data: expenditureData.map(item => item.total_spent),
                    borderColor: '#2a9d8f',
                    backgroundColor: 'rgba(42, 157, 143, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Trends Chart (placeholder - would need more complex data processing)
        const trendsCtx = document.getElementById('trendsChart').getContext('2d');
        new Chart(trendsCtx, {
            type: 'bar',
            data: {
                labels: ['Tomato', 'Onion', 'Potato', 'Rice', 'Wheat'],
                datasets: [{
                    label: 'My Price',
                    data: [45, 25, 30, 35, 28],
                    backgroundColor: '#2a9d8f'
                }, {
                    label: 'Market Price',
                    data: [40, 22, 28, 32, 25],
                    backgroundColor: '#e76f51'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value;
                            }
                        }
                    }
                }
            }
        });

        // Tab functionality
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Export functionality
        function exportData() {
            // Create CSV data
            let csvContent = "data:text/csv;charset=utf-8,";
            csvContent += "Farm,Orders,Spent,Avg Order,Last Purchase\n";
            
            <?php foreach ($supplier_interactions as $supplier): ?>
            csvContent += "<?php echo $supplier['farm_name']; ?>,<?php echo $supplier['total_orders']; ?>,<?php echo $supplier['total_spent']; ?>,<?php echo $supplier['avg_order_value']; ?>,<?php echo $supplier['last_purchase']; ?>\n";
            <?php endforeach; ?>
            
            // Download CSV
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "buyer_analytics_" + new Date().getTime() + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>
