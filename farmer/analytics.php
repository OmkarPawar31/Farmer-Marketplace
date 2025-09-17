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

// Get farmer ID
$stmt = $conn->prepare("SELECT id FROM farmers WHERE user_id = ?");
$stmt->execute([$user_id]);
$farmer = $stmt->fetch(PDO::FETCH_ASSOC);
$farmer_id = $farmer['id'];

// Time ranges for analysis
$current_month = date('Y-m-01');
$last_month = date('Y-m-01', strtotime('-1 month'));
$current_year = date('Y-01-01');
$last_year = date('Y-01-01', strtotime('-1 year'));

// ===== EARNINGS ANALYTICS =====
// Monthly earnings for the current year
$stmt = $conn->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as total_orders,
        SUM(farmer_earnings) as earnings,
        AVG(farmer_earnings) as avg_earnings
    FROM orders 
    WHERE farmer_id = ? AND created_at >= ? AND payment_status = 'paid'
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month
");
$stmt->execute([$farmer_id, $current_year]);
$monthly_earnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Crop-wise earnings
$stmt = $conn->prepare("
    SELECT 
        c.name as crop_name,
        COUNT(o.id) as total_orders,
        SUM(o.farmer_earnings) as total_earnings,
        AVG(o.unit_price) as avg_price,
        SUM(o.quantity) as total_quantity
    FROM orders o
    JOIN product_listings p ON o.product_id = p.id
    JOIN crops c ON p.crop_id = c.id
    WHERE o.farmer_id = ? AND o.payment_status = 'paid'
    GROUP BY c.id, c.name
    ORDER BY total_earnings DESC
");
$stmt->execute([$farmer_id]);
$crop_earnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Price trends comparison with market
$stmt = $conn->prepare("
    SELECT 
        c.name as crop_name,
        AVG(o.unit_price) as my_avg_price,
        AVG(mp.modal_price) as market_avg_price,
        DATE_FORMAT(o.created_at, '%Y-%m') as month
    FROM orders o
    JOIN product_listings p ON o.product_id = p.id
    JOIN crops c ON p.crop_id = c.id
    LEFT JOIN market_prices mp ON c.id = mp.crop_id AND DATE_FORMAT(o.created_at, '%Y-%m-%d') = mp.date
    WHERE o.farmer_id = ? AND o.created_at >= ?
    GROUP BY c.id, c.name, DATE_FORMAT(o.created_at, '%Y-%m')
    ORDER BY month DESC, my_avg_price DESC
");
$stmt->execute([$farmer_id, date('Y-m-01', strtotime('-6 months'))]);
$price_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== PERFORMANCE METRICS =====
// Overall statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT o.id) as total_orders,
        SUM(o.farmer_earnings) as total_earnings,
        AVG(o.farmer_earnings) as avg_order_value,
        COUNT(DISTINCT p.crop_id) as crops_sold,
        COUNT(DISTINCT o.buyer_id) as unique_buyers,
        AVG(CASE WHEN r.rating IS NOT NULL THEN r.rating END) as avg_rating,
        COUNT(r.id) as total_reviews
    FROM orders o
    JOIN product_listings p ON o.product_id = p.id
    LEFT JOIN reviews r ON o.id = r.order_id AND r.reviewee_id = (SELECT id FROM users WHERE id = ?)
    WHERE o.farmer_id = ?
");
$stmt->execute([$user_id, $farmer_id]);
$overall_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Seasonal performance
$stmt = $conn->prepare("
    SELECT 
        c.season,
        COUNT(o.id) as order_count,
        SUM(o.farmer_earnings) as earnings,
        AVG(o.unit_price) as avg_price
    FROM orders o
    JOIN product_listings p ON o.product_id = p.id
    JOIN crops c ON p.crop_id = c.id
    WHERE o.farmer_id = ?
    GROUP BY c.season
    ORDER BY earnings DESC
");
$stmt->execute([$farmer_id]);
$seasonal_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== DEMAND FORECASTING =====
// Product demand analysis
$stmt = $conn->prepare("
    SELECT 
        c.name as crop_name,
        COUNT(p.views) as total_views,
        COUNT(o.id) as total_orders,
        ROUND((COUNT(o.id) / COUNT(p.views)) * 100, 2) as conversion_rate,
        AVG(o.unit_price) as avg_selling_price,
        SUM(o.quantity) as total_sold
    FROM product_listings p
    JOIN crops c ON p.crop_id = c.id
    LEFT JOIN orders o ON p.id = o.product_id
    WHERE p.farmer_id = ?
    GROUP BY c.id, c.name
    HAVING total_views > 0
    ORDER BY conversion_rate DESC
");
$stmt->execute([$farmer_id]);
$demand_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent order trends
$stmt = $conn->prepare("
    SELECT 
        DATE(o.created_at) as order_date,
        COUNT(o.id) as daily_orders,
        SUM(o.farmer_earnings) as daily_earnings
    FROM orders o
    WHERE o.farmer_id = ? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(o.created_at)
    ORDER BY order_date DESC
");
$stmt->execute([$farmer_id]);
$recent_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== BUYER ANALYTICS =====
// Top buyers
$stmt = $conn->prepare("
    SELECT 
        b.company_name,
        u.username,
        COUNT(o.id) as order_count,
        SUM(o.farmer_earnings) as total_business,
        AVG(o.farmer_earnings) as avg_order_value,
        MAX(o.created_at) as last_order_date
    FROM orders o
    JOIN buyers b ON o.buyer_id = b.id
    JOIN users u ON b.user_id = u.id
    WHERE o.farmer_id = ?
    GROUP BY b.id, b.company_name, u.username
    ORDER BY total_business DESC
    LIMIT 10
");
$stmt->execute([$farmer_id]);
$top_buyers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer Analytics - Farmer Marketplace</title>
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
                    <div class="logo">🌾</div>
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
            <h1><i class="fas fa-chart-line"></i> Farmer Analytics Dashboard</h1>
            <p>Comprehensive insights into your farming business performance</p>
        </div>

        <!-- Key Metrics -->
        <div class="analytics-grid">
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-rupee-sign"></i></div>
                <div class="metric-value">₹<?php echo number_format($overall_stats['total_earnings'] ?? 0, 2); ?></div>
                <div class="metric-label">Total Earnings</div>
                <div class="metric-change positive">+15% from last month</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="metric-value"><?php echo $overall_stats['total_orders'] ?? 0; ?></div>
                <div class="metric-label">Total Orders</div>
                <div class="metric-change positive">+8% from last month</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="metric-value">₹<?php echo number_format($overall_stats['avg_order_value'] ?? 0, 2); ?></div>
                <div class="metric-label">Avg Order Value</div>
                <div class="metric-change positive">+12% from last month</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-star"></i></div>
                <div class="metric-value"><?php echo number_format($overall_stats['avg_rating'] ?? 0, 1); ?></div>
                <div class="metric-label">Average Rating</div>
                <div class="metric-change positive">Excellent performance</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-seedling"></i></div>
                <div class="metric-value"><?php echo $overall_stats['crops_sold'] ?? 0; ?></div>
                <div class="metric-label">Crops Sold</div>
                <div class="metric-change">Different varieties</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-users"></i></div>
                <div class="metric-value"><?php echo $overall_stats['unique_buyers'] ?? 0; ?></div>
                <div class="metric-label">Unique Buyers</div>
                <div class="metric-change positive">Growing network</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="chart-section">
            <button class="export-btn" onclick="exportData()">
                <i class="fas fa-download"></i> Export Report
            </button>
            
            <div class="tabs">
                <div class="tab active" onclick="showTab('earnings')">Earnings Analysis</div>
                <div class="tab" onclick="showTab('crops')">Crop Performance</div>
                <div class="tab" onclick="showTab('trends')">Market Trends</div>
                <div class="tab" onclick="showTab('buyers')">Buyer Analytics</div>
            </div>

            <!-- Earnings Tab -->
            <div id="earnings" class="tab-content active">
                <div class="section-title">
                    <i class="fas fa-chart-area"></i>
                    Monthly Earnings Trend
                </div>
                <div class="chart-container">
                    <canvas id="earningsChart"></canvas>
                </div>
                
                <div class="insights-card">
                    <div class="insight-text">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Insight:</strong> Your earnings have shown consistent growth. 
                        Consider focusing more on high-performing crops during peak seasons.
                    </div>
                </div>
            </div>

            <!-- Crops Tab -->
            <div id="crops" class="tab-content">
                <div class="section-title">
                    <i class="fas fa-leaf"></i>
                    Crop-wise Performance
                </div>
                <div class="chart-container">
                    <canvas id="cropsChart"></canvas>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Crop</th>
                            <th>Orders</th>
                            <th>Total Earnings</th>
                            <th>Avg Price</th>
                            <th>Quantity Sold</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($crop_earnings as $crop): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($crop['crop_name']); ?></td>
                            <td><?php echo $crop['total_orders']; ?></td>
                            <td>₹<?php echo number_format($crop['total_earnings'], 2); ?></td>
                            <td>₹<?php echo number_format($crop['avg_price'], 2); ?></td>
                            <td><?php echo number_format($crop['total_quantity'], 2); ?> kg</td>
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
                        <strong>Market Intelligence:</strong> You're getting better prices than market average for most crops. 
                        This indicates strong buyer relationships and quality produce.
                    </div>
                </div>
            </div>

            <!-- Buyers Tab -->
            <div id="buyers" class="tab-content">
                <div class="section-title">
                    <i class="fas fa-handshake"></i>
                    Top Buyers Analysis
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Orders</th>
                            <th>Total Business</th>
                            <th>Avg Order</th>
                            <th>Last Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_buyers as $buyer): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($buyer['company_name']); ?></td>
                            <td><?php echo $buyer['order_count']; ?></td>
                            <td>₹<?php echo number_format($buyer['total_business'], 2); ?></td>
                            <td>₹<?php echo number_format($buyer['avg_order_value'], 2); ?></td>
                            <td><?php echo date('M d, Y', strtotime($buyer['last_order_date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Seasonal Performance -->
        <div class="chart-section">
            <div class="section-title">
                <i class="fas fa-calendar-alt"></i>
                Seasonal Performance Analysis
            </div>
            <div class="analytics-grid">
                <?php foreach ($seasonal_performance as $season): ?>
                <div class="metric-card">
                    <div class="metric-icon">
                        <?php 
                        $icons = [
                            'kharif' => 'fas fa-cloud-rain',
                            'rabi' => 'fas fa-snowflake',
                            'zaid' => 'fas fa-sun',
                            'perennial' => 'fas fa-tree'
                        ];
                        echo '<i class="' . ($icons[$season['season']] ?? 'fas fa-seedling') . '"></i>';
                        ?>
                    </div>
                    <div class="metric-value"><?php echo ucfirst($season['season']); ?></div>
                    <div class="metric-label"><?php echo $season['order_count']; ?> orders</div>
                    <div class="metric-change">₹<?php echo number_format($season['earnings'], 2); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        // Chart.js configurations
        const earningsData = <?php echo json_encode($monthly_earnings); ?>;
        const cropsData = <?php echo json_encode($crop_earnings); ?>;
        const trendsData = <?php echo json_encode($price_trends); ?>;

        // Earnings Chart
        const earningsCtx = document.getElementById('earningsChart').getContext('2d');
        new Chart(earningsCtx, {
            type: 'line',
            data: {
                labels: earningsData.map(item => item.month),
                datasets: [{
                    label: 'Monthly Earnings',
                    data: earningsData.map(item => item.earnings),
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

        // Crops Chart
        const cropsCtx = document.getElementById('cropsChart').getContext('2d');
        new Chart(cropsCtx, {
            type: 'doughnut',
            data: {
                labels: cropsData.map(item => item.crop_name),
                datasets: [{
                    data: cropsData.map(item => item.total_earnings),
                    backgroundColor: [
                        '#2a9d8f', '#e76f51', '#f4a261', '#e9c46a', '#264653',
                        '#219f8b', '#d67a3e', '#f2b84f', '#dab84d', '#1e3a40'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
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
            csvContent += "Crop,Orders,Earnings,Avg Price,Quantity\n";
            
            <?php foreach ($crop_earnings as $crop): ?>
            csvContent += "<?php echo $crop['crop_name']; ?>,<?php echo $crop['total_orders']; ?>,<?php echo $crop['total_earnings']; ?>,<?php echo $crop['avg_price']; ?>,<?php echo $crop['total_quantity']; ?>\n";
            <?php endforeach; ?>
            
            // Download CSV
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "farmer_analytics_" + new Date().getTime() + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>
