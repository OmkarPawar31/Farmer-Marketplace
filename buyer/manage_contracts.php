<?php
// manage_contracts.php
// This file allows buyers to create and manage contracts

session_start();

// Include database connection
require_once '../config/database.php';

// Check if user is logged in and is a buyer
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] !== 'buyer') {
    header('Location: ../login.php');
    exit();
}

// Get buyer ID
$buyerID = isset($_SESSION['buyer_id']) ? $_SESSION['buyer_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null);

if ($buyerID === null) {
    die("Error: Buyer ID not found in session");
}

// Handle form submission for creating new contract
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_contract') {
    try {
        $db = getDB();
        
        // Generate unique contract ID
        $contractId = 'CON' . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Prepare the INSERT query
        $query = "INSERT INTO contracts (
            contract_id, buyer_id, farmer_id, crop_id, farmer_name, buyer_name,
            product, quantity, unit, amount, price_per_unit, status,
            start_date, end_date, delivery_location, quality_specifications,
            payment_terms, special_conditions, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            $contractId,
            $buyerID,
            $_POST['farmer_id'],
            $_POST['crop_id'],
            $_POST['farmer_name'],
            $_POST['buyer_name'],
            $_POST['product'],
            $_POST['quantity'],
            $_POST['unit'],
            $_POST['amount'],
            $_POST['price_per_unit'],
            'draft',
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['delivery_location'],
            $_POST['quality_specifications'],
            $_POST['payment_terms'],
            $_POST['special_conditions'],
            $buyerID
        ]);
        
        $success_message = "Contract $contractId created successfully!";
        
    } catch (Exception $e) {
        $error_message = "Error creating contract: " . $e->getMessage();
    }
}

// Fetch available farmers and crops for the form
function getFarmers() {
    $db = getDB();
    $query = "SELECT f.id, u.username, f.farm_name 
              FROM farmers f 
              JOIN users u ON f.user_id = u.id 
              WHERE u.status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCrops() {
    $db = getDB();
    $query = "SELECT id, name FROM crops WHERE is_active = TRUE ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$farmers = getFarmers();
$crops = getCrops();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Contracts</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        
        .form-group textarea {
            height: 80px;
            resize: vertical;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .btn {
            background-color: #28a745;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .btn:hover {
            background-color: #218838;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            margin-left: 10px;
        }
        
        .btn-secondary:hover {
            background-color: #545b62;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .navigation {
            margin-bottom: 20px;
        }
        
        .navigation a {
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
        }
        
        .navigation a:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <header>
        <div class="navigation">
            <a href="contracts.php">← Back to Contracts</a>
            <a href="dashboard.php">Dashboard</a>
        </div>
    </header>
    
    <main>
        <h2>Create New Contract</h2>
        
        <?php if (isset($success_message)): ?>
            <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <div class="form-container">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_contract">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="farmer_id">Select Farmer:</label>
                        <select name="farmer_id" id="farmer_id" required>
                            <option value="">Choose a farmer...</option>
                            <?php foreach ($farmers as $farmer): ?>
                                <option value="<?php echo $farmer['id']; ?>">
                                    <?php echo htmlspecialchars($farmer['username'] . ' - ' . ($farmer['farm_name'] ?: 'N/A')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="crop_id">Select Crop:</label>
                        <select name="crop_id" id="crop_id" required>
                            <option value="">Choose a crop...</option>
                            <?php foreach ($crops as $crop): ?>
                                <option value="<?php echo $crop['id']; ?>">
                                    <?php echo htmlspecialchars($crop['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="farmer_name">Farmer Name:</label>
                        <input type="text" name="farmer_name" id="farmer_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="buyer_name">Your Company Name:</label>
                        <input type="text" name="buyer_name" id="buyer_name" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="product">Product Description:</label>
                    <input type="text" name="product" id="product" required placeholder="e.g., Organic Basmati Rice">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="quantity">Quantity:</label>
                        <input type="number" name="quantity" id="quantity" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="unit">Unit:</label>
                        <select name="unit" id="unit" required>
                            <option value="kg">Kilograms (kg)</option>
                            <option value="quintal">Quintal</option>
                            <option value="tonne">Tonne</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="price_per_unit">Price per Unit (₹):</label>
                        <input type="number" name="price_per_unit" id="price_per_unit" step="0.01" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="amount">Total Amount (₹):</label>
                    <input type="number" name="amount" id="amount" step="0.01" required readonly>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Contract Start Date:</label>
                        <input type="date" name="start_date" id="start_date" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">Contract End Date:</label>
                        <input type="date" name="end_date" id="end_date" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="delivery_location">Delivery Location:</label>
                    <textarea name="delivery_location" id="delivery_location"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="quality_specifications">Quality Specifications:</label>
                    <textarea name="quality_specifications" id="quality_specifications"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="payment_terms">Payment Terms:</label>
                    <textarea name="payment_terms" id="payment_terms"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="special_conditions">Special Conditions:</label>
                    <textarea name="special_conditions" id="special_conditions"></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn">Create Contract</button>
                    <button type="reset" class="btn btn-secondary">Clear Form</button>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Auto-calculate total amount
        function calculateTotal() {
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const pricePerUnit = parseFloat(document.getElementById('price_per_unit').value) || 0;
            const total = quantity * pricePerUnit;
            document.getElementById('amount').value = total.toFixed(2);
        }
        
        document.getElementById('quantity').addEventListener('input', calculateTotal);
        document.getElementById('price_per_unit').addEventListener('input', calculateTotal);
        
        // Auto-fill farmer name when farmer is selected
        document.getElementById('farmer_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const farmerName = selectedOption.text.split(' - ')[0];
                document.getElementById('farmer_name').value = farmerName;
            }
        });
        
        // Auto-fill product name when crop is selected
        document.getElementById('crop_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                document.getElementById('product').value = selectedOption.text;
            }
        });
        
        // Set minimum date to today
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('start_date').min = today;
        document.getElementById('end_date').min = today;
        
        // Ensure end date is after start date
        document.getElementById('start_date').addEventListener('change', function() {
            document.getElementById('end_date').min = this.value;
        });
    </script>
</body>
</html>
