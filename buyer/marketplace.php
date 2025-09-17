<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'buyer') {
    header('Location: login.php');
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get buyer type
$stmt = $conn->prepare("SELECT business_type FROM buyers WHERE user_id = ?");
$stmt->execute([$user_id]);
$buyer = $stmt->fetch(PDO::FETCH_ASSOC);
$buyer_type = $buyer['business_type'] ?? 'vendor';

// Determine which products to show
$listing_types_to_show = [];
if ($buyer_type === 'company') {
    $listing_types_to_show = ['company', 'both'];
} elseif ($buyer_type === 'vendor') {
    $listing_types_to_show = ['vendor', 'both'];
} else { // both
    $listing_types_to_show = ['vendor', 'company', 'both'];
}

$placeholders = implode(',', array_fill(0, count($listing_types_to_show), '?'));

// Get available products
$stmt = $conn->prepare("
    SELECT p.*, c.name as crop_name, cc.name as category_name, f.farm_name, 
           u.username as farmer_username, f.state, f.district
    FROM product_listings p 
    JOIN crops c ON p.crop_id = c.id
    LEFT JOIN crop_categories cc ON c.category_id = cc.id
    JOIN farmers f ON p.farmer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE p.status = 'active' AND p.quantity_available > 0 AND p.listing_type IN ($placeholders)
    ORDER BY p.created_at DESC 
");
$stmt->execute($listing_types_to_show);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!-- HTML for the marketplace will go here -->

