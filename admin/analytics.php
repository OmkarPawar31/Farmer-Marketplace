<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$conn = getDB();

// ===== PLATFORM STATISTICS =====
try {
    // Total users by type
    $stmt = $conn->query("SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type");
    $user_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Total transactions and revenue
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_orders,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_order_value,
            SUM(commission_amount) as platform_revenue
        FROM orders 
        WHERE payment_status = 'paid'
    ");
    $financial_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Monthly trends
    $stmt = $conn->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as order_count,
            SUM(total_amount) as revenue,
            SUM(commission_amount) as platform_earnings
        FROM orders 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month
    ");
    $monthly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // User growth trends
    $stmt = $conn->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as new_users,
            user_type
        FROM users 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m'), user_type
        ORDER BY month
    ");
    $user_growth = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top performing crops
    $stmt = $conn->query("
        SELECT 
            c.name as crop_name,
            COUNT(o.id) as order_count,
            SUM(o.total_amount) as revenue,
            AVG(o.unit_price) as avg_price
        FROM orders o
        JOIN product_listings pl ON o.product_id = pl.id
        JOIN crops c ON pl.crop_id = c.id
        WHERE o.payment_status = 'paid'
        GROUP BY c.id, c.name
        ORDER BY revenue DESC
        LIMIT 10
    ");
    $top_crops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Geographic distribution
    $stmt = $conn->query("
        SELECT 
            f.state,
            COUNT(DISTINCT f.id) as farmer_count,
            COUNT(o.id) as order_count,
            SUM(o.total_amount) as state_revenue
        FROM farmers f
        LEFT JOIN orders o ON f.id = o.farmer_id AND o.payment_status = 'paid'
        GROUP BY f.state
        ORDER BY state_revenue DESC
        LIMIT 10
    ");
    $geographic_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent activities
    $stmt = $conn->query("
        SELECT 
            o.order_number,
            u1.username as buyer_name,
            u2.username as farmer_name,
            o.total_amount,
            o.order_status,
            o.created_at
        FROM orders o
        JOIN buyers b ON o.buyer_id = b.id
        JOIN users u1 ON b.user_id = u1.id
        JOIN farmers f ON o.farmer_id = f.id
        JOIN users u2 ON f.user_id = u2.id
        ORDER BY o.created_at DESC
        LIMIT 20
    ");
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Initialize empty arrays if queries fail
    $user_stats = [];
    $financial_stats = ['total_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0, 'platform_revenue' => 0];
    $monthly_trends = [];
    $user_growth = [];
    $top_crops = [];
    $geographic_stats = [];
    $recent_activities = [];
}

// Calculate totals
$total_users = array_sum(array_column($user_stats, 'count'));
$total_farmers = 0;
$total_buyers = 0;
foreach ($user_stats as $stat) {
    if ($stat['user_type'] === 'farmer') $total_farmers = $stat['count'];
    if ($stat['user_type'] === 'buyer') $total_buyers = $stat['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Analytics - Farmer Marketplace</title>
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
            margin-bottom: 20px;
            cursor: pointer;
            font-weight: 500;
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

    </style>
</head>
<body>
    <header class="header">
        <nav class="navbar">
            <div class="container">
                <div class="nav-brand">
                    <div class="logo">📊</div>
                    <span class="brand-name">FarmConnect Admin</span>
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
        <div class="analytics-header">
            <h1><i class="fas fa-chart-line"></i> Admin Analytics Dashboard</h1>
            <p>Comprehensive insights for platform management</p>
        </div>

        <div class="analytics-grid">
            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="metric-value" id="totalTransactions">0</div>
                <div class="metric-label">Total Transactions</div>
            </div>

            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-rupee-sign"></i></div>
                <div class="metric-value" id="totalRevenue">₹0</div>
                <div class="metric-label">Total Revenue</div>
            </div>

            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-users"></i></div>
                <div class="metric-value" id="totalUsers">0</div>
                <div class="metric-label">Total Users</div>
            </div>

            <div class="metric-card">
                <div class="metric-icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="metric-value" id="totalOrders">0</div>
                <div class="metric-label">Total Orders</div>
            </div>
        </div>

        <div class="chart-section">
            <button class="export-btn" onclick="exportPlatformData()">
                <i class="fas fa-download"></i> Export Report
            </button>

            <div class="tabs">
                <div class="tab active" onclick="showTab('transactions')">Transactions</div>
                <div class="tab" onclick="showTab('revenue')">Revenue</div>
                <div class="tab" onclick="showTab('user-growth')">User Growth</div>
            </div>

            <div id="transactions" class="tab-content active">
                <div class="section-title">
                    <i class="fas fa-exchange-alt"></i>
                    Monthly Transactions Trend
                </div>
                <div class="chart-container">
                    <canvas id="transactionsChart"></canvas>
                </div>
            </div>

            <div id="revenue" class="tab-content">
                <div class="section-title">
                    <i class="fas fa-coins"></i>
                    Monthly Revenue Trend
                </div>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <div id="user-growth" class="tab-content">
                <div class="section-title">
                    <i class="fas fa-user-plus"></i>
                    User Growth Analysis
                </div>
                <div class="chart-container">
                    <canvas id="userGrowthChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mocked Data - replace with actual server-side logic
            const mockData = {
                totalUsers: 1500,
                totalTransactions: 925,
                totalRevenue: 5000000, // in rupees
                totalOrders: 1200,
                transactionTrends: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    dataset: [150, 200, 175, 225, 300, 275]
                },
                revenueTrends: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    dataset: [500000, 800000, 750000, 925000, 1025000, 975000]
                },
                userGrowth: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    dataset: [100, 150, 200, 180, 220, 250]
                }
            }

            document.getElementById('totalUsers').textContent = mockData.totalUsers;
            document.getElementById('totalTransactions').textContent = mockData.totalTransactions;
            document.getElementById('totalRevenue').textContent = `₹${mockData.totalRevenue.toLocaleString()}`;
            document.getElementById('totalOrders').textContent = mockData.totalOrders;

            // Transactions Chart
            const transactionsCtx = document.getElementById('transactionsChart').getContext('2d');
            new Chart(transactionsCtx, {
                type: 'line',
                data: {
                    labels: mockData.transactionTrends.labels,
                    datasets: [{
                        label: 'Monthly Transactions',
                        data: mockData.transactionTrends.dataset,
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
                            beginAtZero: true
                        }
                    }
                }
            });

            // Revenue Chart
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: mockData.revenueTrends.labels,
                    datasets: [{
                        label: 'Monthly Revenue',
                        data: mockData.revenueTrends.dataset,
                        borderColor: '#e76f51',
                        backgroundColor: 'rgba(231, 111, 81, 0.1)',
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

            // User Growth Chart
            const userGrowthCtx = document.getElementById('userGrowthChart').getContext('2d');
            new Chart(userGrowthCtx, {
                type: 'line',
                data: {
                    labels: mockData.userGrowth.labels,
                    datasets: [{
                        label: 'New Users',
                        data: mockData.userGrowth.dataset,
                        borderColor: '#f4a261',
                        backgroundColor: 'rgba(244, 162, 97, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

        });

        function showTab(tabName) {
            const contents = document.querySelectorAll('.tab-content');
            const tabs = document.querySelectorAll('.tab');

            contents.forEach(content => {
                content.classList.remove('active');
            });

            tabs.forEach(tab => {
                tab.classList.remove('active');
            });

            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        function exportPlatformData() {
            alert('Export functionality to be implemented.');
        }
    </script>
</body>
</html>

