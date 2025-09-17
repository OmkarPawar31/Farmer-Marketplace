<?php
// quality-reports.php
// This file manages the display of product quality reports for buyers

// Start session and include database connection
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a buyer
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] !== 'buyer') {
    header('Location: ../login.php');
    exit();
}

// Get database connection
$db = getDB();

// Function to fetch quality reports
function fetchQualityReports() {
    global $db;
    $buyerID = $_SESSION['user_id'];
    $query = "SELECT qr.report_id, qr.product_name, qr.farmer_name, qr.quality_grade, qr.inspection_date, qr.inspector_name, qr.report_details, qr.overall_rating FROM quality_reports qr JOIN orders o ON qr.order_id = o.id WHERE o.buyer_id = ? ORDER BY qr.inspection_date DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $buyerID, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt;
}

// Function to get quality statistics
function getQualityStats() {
    global $db;
    $buyerID = $_SESSION['user_id'];
    
    $stats = [];
    
    // Total reports
    $query = "SELECT COUNT(*) as total FROM quality_reports qr JOIN orders o ON qr.order_id = o.id WHERE o.buyer_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $buyerID, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_reports'] = $result['total'];
    
    // Average rating
    $query = "SELECT AVG(overall_rating) as avg_rating FROM quality_reports qr JOIN orders o ON qr.order_id = o.id WHERE o.buyer_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $buyerID, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['avg_rating'] = round($result['avg_rating'], 2);
    
    // Grade distribution
    $query = "SELECT quality_grade, COUNT(*) as count FROM quality_reports qr JOIN orders o ON qr.order_id = o.id WHERE o.buyer_id = ? GROUP BY quality_grade";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $buyerID, PDO::PARAM_INT);
    $stmt->execute();
    $stats['grade_distribution'] = [];
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['grade_distribution'][$row['quality_grade']] = $row['count'];
    }
    
    return $stats;
}

// Fetch reports and stats
$reports = fetchQualityReports();
$stats = getQualityStats();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quality Reports</title>
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <header>
        <nav class="navbar">
            <div class="nav-container">
                <div class="nav-logo">
                    <h3>Farmer Marketplace</h3>
                </div>
                <div class="nav-menu">
                    <a href="dashboard.php" class="nav-link">Dashboard</a>
                    <a href="orders.php" class="nav-link">Orders</a>
                    <a href="quality-reports.php" class="nav-link active">Quality Reports</a>
                    <a href="analytics.php" class="nav-link">Analytics</a>
                    <a href="logout.php" class="nav-link">Logout</a>
                </div>
            </div>
        </nav>
    </header>

    <main class="main-content">
        <div class="page-header">
            <h1>Quality Reports</h1>
            <p>Monitor the quality of products you've purchased</p>
        </div>

        <div class="quality-stats">
            <div class="stat-card">
                <h3>Total Reports</h3>
                <p class="stat-number"><?php echo $stats['total_reports']; ?></p>
            </div>
            <div class="stat-card">
                <h3>Average Rating</h3>
                <p class="stat-number"><?php echo $stats['avg_rating']; ?>/5</p>
            </div>
            <div class="stat-card">
                <h3>Grade Distribution</h3>
                <div class="grade-chart">
                    <canvas id="gradeChart" width="200" height="100"></canvas>
                </div>
            </div>
        </div>

        <div class="filters">
            <select id="gradeFilter">
                <option value="">All Grades</option>
                <option value="A">Grade A</option>
                <option value="B">Grade B</option>
                <option value="C">Grade C</option>
                <option value="D">Grade D</option>
            </select>
            <select id="ratingFilter">
                <option value="">All Ratings</option>
                <option value="5">5 Stars</option>
                <option value="4">4+ Stars</option>
                <option value="3">3+ Stars</option>
                <option value="2">2+ Stars</option>
                <option value="1">1+ Stars</option>
            </select>
            <input type="text" id="searchFilter" placeholder="Search by product or farmer...">
        </div>

        <div class="reports-container">
            <table class="reports-table">
                <thead>
                    <tr>
                        <th>Report ID</th>
                        <th>Product</th>
                        <th>Farmer</th>
                        <th>Quality Grade</th>
                        <th>Rating</th>
                        <th>Inspection Date</th>
                        <th>Inspector</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($reports->rowCount() > 0): ?>
                        <?php while($report = $reports->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr class="report-row" 
                                data-grade="<?php echo strtolower($report['quality_grade']); ?>"
                                data-rating="<?php echo $report['overall_rating']; ?>"
                                data-search="<?php echo strtolower($report['product_name'] . ' ' . $report['farmer_name']); ?>">
                                <td><?php echo htmlspecialchars($report['report_id']); ?></td>
                                <td><?php echo htmlspecialchars($report['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($report['farmer_name']); ?></td>
                                <td>
                                    <span class="grade-badge grade-<?php echo strtolower($report['quality_grade']); ?>">
                                        <?php echo htmlspecialchars($report['quality_grade']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="rating">
                                        <?php for($i = 1; $i <= 5; $i++): ?>
                                            <span class="star <?php echo $i <= $report['overall_rating'] ? 'filled' : ''; ?>">★</span>
                                        <?php endfor; ?>
                                        <span class="rating-number">(<?php echo $report['overall_rating']; ?>)</span>
                                    </div>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($report['inspection_date'])); ?></td>
                                <td><?php echo htmlspecialchars($report['inspector_name']); ?></td>
                                <td>
                                    <button class="btn btn-secondary btn-sm view-details" 
                                            data-report-id="<?php echo $report['report_id']; ?>">
                                        View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="no-data">
                                <div class="empty-state">
                                    <h3>No Quality Reports Found</h3>
                                    <p>Quality reports will appear here once your orders are inspected.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Report Details Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Quality Report Details</h2>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body" id="reportDetails">
                <!-- Report details will be loaded here -->
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="footer-content">
            <p>&copy; 2024 Farmer Marketplace. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Grade distribution chart
        const gradeData = <?php echo json_encode($stats['grade_distribution']); ?>;
        const ctx = document.getElementById('gradeChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(gradeData),
                datasets: [{
                    data: Object.values(gradeData),
                    backgroundColor: [
                        '#4CAF50', // Grade A - Green
                        '#2196F3', // Grade B - Blue
                        '#FF9800', // Grade C - Orange
                        '#F44336'  // Grade D - Red
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

        // Filter functionality
        document.getElementById('gradeFilter').addEventListener('change', filterReports);
        document.getElementById('ratingFilter').addEventListener('change', filterReports);
        document.getElementById('searchFilter').addEventListener('input', filterReports);

        function filterReports() {
            const gradeFilter = document.getElementById('gradeFilter').value.toLowerCase();
            const ratingFilter = parseInt(document.getElementById('ratingFilter').value) || 0;
            const searchFilter = document.getElementById('searchFilter').value.toLowerCase();
            
            const rows = document.querySelectorAll('.report-row');
            
            rows.forEach(row => {
                const grade = row.dataset.grade;
                const rating = parseInt(row.dataset.rating);
                const searchText = row.dataset.search;
                
                let show = true;
                
                if (gradeFilter && grade !== gradeFilter) show = false;
                if (ratingFilter && rating < ratingFilter) show = false;
                if (searchFilter && !searchText.includes(searchFilter)) show = false;
                
                row.style.display = show ? '' : 'none';
            });
        }

        // Modal functionality
        const modal = document.getElementById('reportModal');
        const closeBtn = document.querySelector('.close');

        document.querySelectorAll('.view-details').forEach(button => {
            button.addEventListener('click', function() {
                const reportId = this.dataset.reportId;
                loadReportDetails(reportId);
                modal.style.display = 'block';
            });
        });

        closeBtn.addEventListener('click', function() {
            modal.style.display = 'none';
        });

        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        function loadReportDetails(reportId) {
            fetch(`get_report_details.php?report_id=${reportId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('reportDetails').innerHTML = `
                            <div class="report-detail">
                                <h3>${data.report.product_name}</h3>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <label>Farmer:</label>
                                        <span>${data.report.farmer_name}</span>
                                    </div>
                                    <div class="detail-item">
                                        <label>Quality Grade:</label>
                                        <span class="grade-badge grade-${data.report.quality_grade.toLowerCase()}">${data.report.quality_grade}</span>
                                    </div>
                                    <div class="detail-item">
                                        <label>Overall Rating:</label>
                                        <div class="rating">
                                            ${Array.from({length: 5}, (_, i) => 
                                                `<span class="star ${i < data.report.overall_rating ? 'filled' : ''}">★</span>`
                                            ).join('')}
                                            <span class="rating-number">(${data.report.overall_rating})</span>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <label>Inspection Date:</label>
                                        <span>${new Date(data.report.inspection_date).toLocaleDateString()}</span>
                                    </div>
                                    <div class="detail-item">
                                        <label>Inspector:</label>
                                        <span>${data.report.inspector_name}</span>
                                    </div>
                                </div>
                                <div class="report-details-section">
                                    <h4>Report Details</h4>
                                    <p>${data.report.report_details || 'No additional details provided.'}</p>
                                </div>
                            </div>
                        `;
                    } else {
                        document.getElementById('reportDetails').innerHTML = '<p>Error loading report details.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('reportDetails').innerHTML = '<p>Error loading report details.</p>';
                });
        }
    </script>

    <style>
        .quality-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            color: #4CAF50;
            margin: 10px 0;
        }

        .grade-chart {
            height: 150px;
            position: relative;
        }

        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filters select,
        .filters input {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .reports-table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .reports-table th,
        .reports-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .reports-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .grade-badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .grade-a { background: #4CAF50; color: white; }
        .grade-b { background: #2196F3; color: white; }
        .grade-c { background: #FF9800; color: white; }
        .grade-d { background: #F44336; color: white; }

        .rating {
            display: flex;
            align-items: center;
            gap: 2px;
        }

        .star {
            color: #ddd;
            font-size: 16px;
        }

        .star.filled {
            color: #FFD700;
        }

        .rating-number {
            margin-left: 5px;
            font-size: 12px;
            color: #666;
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
            padding: 0;
            border-radius: 10px;
            width: 80%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 20px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-item label {
            font-weight: bold;
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
        }

        .report-details-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-data {
            text-align: center;
        }

        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
            }
            
            .reports-table {
                font-size: 12px;
            }
            
            .modal-content {
                width: 95%;
                margin: 2% auto;
            }
        }
    </style>
</body>
</html>
