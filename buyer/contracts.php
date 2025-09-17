<?php
// contracts.php
// This file manages the display and handling of buyer contracts

session_start();

// Include database connection
require_once '../config/database.php';

// Check if user is logged in and is a buyer
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] !== 'buyer') {
    header('Location: ../login.php');
    exit();
}

// Function to fetch contract details
function fetchContracts() {
    $db = getDB();
    
    // Check if buyer_id exists in session, if not use user_id
    $buyerID = isset($_SESSION['buyer_id']) ? $_SESSION['buyer_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null);
    
    if ($buyerID === null) {
        throw new Exception("Buyer ID not found in session");
    }
    
    $query = "SELECT contract_id, farmer_name, product, quantity, amount, status, start_date, end_date FROM contracts WHERE buyer_id = ? ORDER BY start_date DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $buyerID, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch contracts with error handling
try {
    $contracts = fetchContracts();
} catch (Exception $e) {
    // Log the error and set contracts to empty array
    error_log("Error fetching contracts: " . $e->getMessage());
    $contracts = [];
    $error_message = "Unable to load contracts at this time. Please try again later.";
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Contracts</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        main {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h2 {
            color: #333;
            margin-bottom: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        
        tr:hover {
            background-color: #f5f5f5;
        }
        
        .navigation a:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <header>
        <div class="navigation" style="background-color: #f8f9fa; padding: 15px; margin-bottom: 20px;">
            <a href="manage_contracts.php" style="background-color: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; margin-right: 10px;">+ Create New Contract</a>
            <a href="dashboard.php" style="background-color: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px;">Dashboard</a>
        </div>
    </header>
    <main>
        <h2>My Contracts</h2>
        <?php if (isset($error_message)): ?>
            <div class="error-message" style="background-color: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        <div class="contracts-stats">
            <!-- Stats about contracts can be placed here -->
        </div>
        <section class="contract-list">
            <table>
                <thead>
                    <tr>
                        <th>Contract ID</th>
                        <th>Farmer Name</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($contracts)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">No contracts found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($contracts as $contract): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($contract['contract_id']); ?></td>
                                <td><?php echo htmlspecialchars($contract['farmer_name']); ?></td>
                                <td><?php echo htmlspecialchars($contract['product']); ?></td>
                                <td><?php echo htmlspecialchars($contract['quantity']); ?></td>
                                <td><?php echo htmlspecialchars($contract['amount']); ?></td>
                                <td><?php echo htmlspecialchars($contract['status']); ?></td>
                                <td><?php echo htmlspecialchars($contract['start_date']); ?></td>
                                <td><?php echo htmlspecialchars($contract['end_date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
    <footer>
        <!-- Footer content -->
    </footer>
</body>
</html>
