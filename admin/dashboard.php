<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$db = getDB();

// Get dashboard statistics
$stats = [];

// Total users by type
$userStats = $db->query("
    SELECT user_type, COUNT(*) as count 
    FROM users 
    WHERE status = 'active' 
    GROUP BY user_type
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Total inspectors
$inspectorStats = $db->query("
    SELECT status, COUNT(*) as count 
    FROM inspectors 
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Total quality reports
$totalReports = $db->query("SELECT COUNT(*) FROM quality_reports")->fetchColumn();

// Recent activity
$recentReports = $db->query("
    SELECT qr.*, i.inspector_name, i.inspector_type 
    FROM quality_reports qr 
    LEFT JOIN inspectors i ON qr.inspector_id = i.id 
    ORDER BY qr.created_at DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .admin-header {
            background: #2c3e50;
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
        }
        
        .admin-nav {
            background: #34495e;
            padding: 15px 0;
            margin-bottom: 30px;
        }
        
        .admin-nav ul {
            list-style: none;
            display: flex;
            gap: 30px;
            margin: 0;
            padding: 0;
        }
        
        .admin-nav a {
            color: white;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .admin-nav a:hover,
        .admin-nav a.active {
            background: #3498db;
        }
        
        .stats-grid {
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
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #3498db;
        }
        
        .recent-activity {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .activity-item {
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .welcome-message {
            background: #3498db;
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <div class="container">
            <h1>Admin Dashboard</h1>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</p>
        </div>
    </div>

    <div class="admin-nav">
        <div class="container">
            <ul>
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="inspectors.php">Inspectors</a></li>
                <li><a href="users.php">Users</a></li>
                <li><a href="reports.php">Reports</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </div>
    </div>

    <div class="container">
        <div class="welcome-message">
            <h2>Welcome to the Admin Panel</h2>
            <p>Manage your farmer marketplace platform from here. Monitor users, inspectors, and quality reports.</p>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $userStats['farmer'] ?? 0; ?></div>
                <div>Total Farmers</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $userStats['buyer'] ?? 0; ?></div>
                <div>Total Buyers</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $inspectorStats['active'] ?? 0; ?></div>
                <div>Active Inspectors</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $totalReports; ?></div>
                <div>Quality Reports</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Quick Actions</h3>
                <p><a href="inspectors.php" style="color: #3498db;">Manage Inspectors</a></p>
                <p><a href="users.php" style="color: #3498db;">View All Users</a></p>
                <p><a href="reports.php" style="color: #3498db;">Quality Reports</a></p>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="recent-activity">
            <h2>Recent Quality Reports</h2>
            <?php if (!empty($recentReports)): ?>
                <?php foreach ($recentReports as $report): ?>
                    <div class="activity-item">
                        <strong><?php echo htmlspecialchars($report['product_name']); ?></strong> 
                        - Grade: <?php echo htmlspecialchars($report['quality_grade']); ?>
                        - Rating: <?php echo $report['overall_rating']; ?>/5
                        <?php if ($report['inspector_name']): ?>
                            - Inspector: <?php echo htmlspecialchars($report['inspector_name']); ?>
                        <?php endif; ?>
                        <br>
                        <small>Inspected on: <?php echo date('M d, Y', strtotime($report['inspection_date'])); ?></small>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No quality reports yet.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
