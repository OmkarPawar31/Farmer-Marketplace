<?php

session_start();
header('Content-Type: application/json');

require_once '../config/database.php';

// Check if user is logged in and is a farmer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'farmer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// Validate required fields
$required = ['crop_id', 'title', 'quantity_available', 'unit', 'price_per_unit', 'harvest_date'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        echo json_encode(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required.']);
        exit();
    }
}

// Get farmer id
$stmt = $db->prepare("SELECT id FROM farmers WHERE user_id = ?");
$stmt->execute([$user_id]);
$farmer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$farmer) {
    echo json_encode(['success' => false, 'message' => 'Farmer not found.']);
    exit();
}
$farmer_id = $farmer['id'];

// Prepare data
$title = trim($_POST['title']);
$crop_id = $_POST['crop_id'];
$quantity = $_POST['quantity_available'];
$unit = $_POST['unit'];
$price = $_POST['price_per_unit'];
$quality_grade = $_POST['quality_grade'] ?? 'A';
$minimum_order = $_POST['minimum_order'] ?? null;
$harvest_date = $_POST['harvest_date'];
$expiry_date = $_POST['expiry_date'] ?? null;
$organic_certified = isset($_POST['organic_certified']) ? 1 : 0;
$packaging_available = isset($_POST['packaging_available']) ? 1 : 0;
$description = $_POST['description'] ?? null;

// Determine listing type based on quantity thresholds
$listing_type = 'vendor'; // Default to vendor
if ($quantity >= 500) {
    $listing_type = 'company';
} elseif ($quantity >= 400 && $quantity <= 600) {
    // Allow dual listing for quantities between 400kg and 600kg
    $listing_type = isset($_POST['listing_type']) && in_array($_POST['listing_type'], ['vendor', 'company', 'both']) ? $_POST['listing_type'] : 'vendor';
}

try {
    $stmt = $db->prepare("
        INSERT INTO product_listings (
            farmer_id, crop_id, title, quantity_available, unit, price_per_unit, quality_grade,
            minimum_order, harvest_date, expiry_date, organic_certified, packaging_available, description, listing_type, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ");
    $stmt->execute([
        $farmer_id, $crop_id, $title, $quantity, $unit, $price, $quality_grade,
        $minimum_order, $harvest_date, $expiry_date, $organic_certified, $packaging_available, $description, $listing_type
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}