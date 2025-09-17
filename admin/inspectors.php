<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$db = getDB();

// Handle inspector actions
if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_inspector':
                $stmt = $db->prepare("
                    INSERT INTO inspectors (inspector_name, inspector_code, email, phone, inspector_type, 
                    company_organization, license_number, specialization, experience_years, 
                    location_state, location_district) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['inspector_name'], $_POST['inspector_code'], $_POST['email'], $_POST['phone'],
                    $_POST['inspector_type'], $_POST['company_organization'], $_POST['license_number'],
                    $_POST['specialization'], $_POST['experience_years'], $_POST['location_state'], $_POST['location_district']
                ]);
                $success_message = "Inspector added successfully!";
                break;
                
            case 'update_status':
                $stmt = $db->prepare("UPDATE inspectors SET status = ? WHERE id = ?");
                $stmt->execute([$_POST['status'], $_POST['inspector_id']]);
                $success_message = "Inspector status updated!";
                break;
        }
    }
}

// Get all inspectors
$inspectors = $db->query("
    SELECT i.*, 
           COUNT(qr.report_id) as total_reports,
           AVG(qr.overall_rating) as avg_rating_given
    FROM inspectors i 
    LEFT JOIN quality_reports qr ON i.id = qr.inspector_id 
    GROUP BY i.id 
    ORDER BY i.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get inspection statistics
$stats = $db->query("
    SELECT 
        COUNT(DISTINCT i.id) as total_inspectors,
        COUNT(DISTINCT CASE WHEN i.status = 'active' THEN i.id END) as active_inspectors,
        COUNT(qr.report_id) as total_inspections,
        AVG(qr.overall_rating) as avg_quality_rating
    FROM inspectors i 
    LEFT JOIN quality_reports qr ON i.id = qr.inspector_id
")->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspector Management - Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .admin-header {
            background: #2c3e50;
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
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
        
        .inspectors-table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .inspectors-table th,
        .inspectors-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .inspectors-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-active { background: #27ae60; color: white; }
        .status-inactive { background: #95a5a6; color: white; }
        .status-suspended { background: #e74c3c; color: white; }
        
        .type-badge {
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .type-third_party { background: #3498db; color: white; }
        .type-platform { background: #9b59b6; color: white; }
        .type-government { background: #e67e22; color: white; }
        .type-buyer_representative { background: #1abc9c; color: white; }
        
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .btn {
            background: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .btn:hover {
            background: #2980b9;
        }
        
        .btn-success { background: #27ae60; }
        .btn-danger { background: #e74c3c; }
        .btn-warning { background: #f39c12; }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
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
            padding: 20px;
            border-radius: 10px;
            width: 80%;
            max-width: 600px;
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <div class="container">
            <h1>Inspector Management</h1>
            <p>Manage quality inspectors and inspection processes</p>
        </div>
    </div>

    <div class="container">
        <?php if (isset($success_message)): ?>
            <div class="success-message"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_inspectors']; ?></div>
                <div>Total Inspectors</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_inspectors']; ?></div>
                <div>Active Inspectors</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_inspections']; ?></div>
                <div>Total Inspections</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['avg_quality_rating'], 1); ?></div>
                <div>Avg Quality Rating</div>
            </div>
        </div>

        <!-- Add New Inspector Form -->
        <div class="form-container">
            <h2>Add New Inspector</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_inspector">
                <div class="form-grid">
                    <div>
                        <div class="form-group">
                            <label>Inspector Name *</label>
                            <input type="text" name="inspector_name" required>
                        </div>
                        <div class="form-group">
                            <label>Inspector Code *</label>
                            <input type="text" name="inspector_code" required>
                        </div>
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" name="phone">
                        </div>
                        <div class="form-group">
                            <label>Inspector Type *</label>
                            <select name="inspector_type" required>
                                <option value="">Select Type</option>
                                <option value="third_party">Third Party</option>
                                <option value="platform">Platform</option>
                                <option value="buyer_representative">Buyer Representative</option>
                                <option value="government">Government</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <div class="form-group">
                            <label>Company/Organization</label>
                            <input type="text" name="company_organization">
                        </div>
                        <div class="form-group">
                            <label>License Number</label>
                            <input type="text" name="license_number">
                        </div>
                        <div class="form-group">
                            <label>Specialization</label>
                            <textarea name="specialization" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Experience (Years)</label>
                            <input type="number" name="experience_years" min="0">
                        </div>
                        <div class="form-group">
                            <label>State</label>
                            <input type="text" name="location_state">
                        </div>
                        <div class="form-group">
                            <label>District</label>
                            <input type="text" name="location_district">
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn">Add Inspector</button>
            </form>
        </div>

        <!-- Inspectors List -->
        <div class="inspectors-table">
            <table style="width: 100%;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Company</th>
                        <th>Location</th>
                        <th>Experience</th>
                        <th>Inspections</th>
                        <th>Avg Rating</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inspectors as $inspector): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($inspector['inspector_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($inspector['email']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($inspector['inspector_code']); ?></td>
                            <td>
                                <span class="type-badge type-<?php echo $inspector['inspector_type']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $inspector['inspector_type'])); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($inspector['company_organization']); ?></td>
                            <td><?php echo htmlspecialchars($inspector['location_district'] . ', ' . $inspector['location_state']); ?></td>
                            <td><?php echo $inspector['experience_years']; ?> years</td>
                            <td><?php echo $inspector['total_reports']; ?></td>
                            <td><?php echo $inspector['avg_rating_given'] ? number_format($inspector['avg_rating_given'], 1) : 'N/A'; ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $inspector['status']; ?>">
                                    <?php echo ucfirst($inspector['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-warning" onclick="changeStatus(<?php echo $inspector['id']; ?>, '<?php echo $inspector['status']; ?>')">
                                    Change Status
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Status Change Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <h3>Change Inspector Status</h3>
            <form method="POST" id="statusForm">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="inspector_id" id="inspectorId">
                <div class="form-group">
                    <label>New Status</label>
                    <select name="status" id="newStatus">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-success">Update Status</button>
                <button type="button" class="btn" onclick="closeModal()">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function changeStatus(inspectorId, currentStatus) {
            document.getElementById('inspectorId').value = inspectorId;
            document.getElementById('newStatus').value = currentStatus;
            document.getElementById('statusModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('statusModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('statusModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
