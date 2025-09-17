<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

if (!isset($_GET['state']) || empty($_GET['state'])) {
    echo json_encode([]);
    exit();
}

$conn = getDB();
$state = $_GET['state'];

try {
    $sql = "
        SELECT DISTINCT location_district 
        FROM product_listings 
        WHERE location_state = ? 
        AND location_district IS NOT NULL 
        AND status = 'active' 
        ORDER BY location_district
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$state]);
    $districts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($districts);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
