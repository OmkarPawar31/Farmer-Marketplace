<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

if (!isset($_GET['q']) || strlen($_GET['q']) < 2) {
    echo json_encode([]);
    exit();
}

$conn = getDB();
$query = '%' . $_GET['q'] . '%';

try {
    // Search in crops, categories, and locations
    $sql = "
        (SELECT DISTINCT c.name as name, 'crop' as type FROM crops c WHERE c.name LIKE ? AND c.is_active = 1 LIMIT 5)
        UNION
        (SELECT DISTINCT cc.name as name, 'category' as type FROM crop_categories cc WHERE cc.name LIKE ? AND cc.is_active = 1 LIMIT 5)
        UNION
        (SELECT DISTINCT pl.location_state as name, 'state' as type FROM product_listings pl WHERE pl.location_state LIKE ? AND pl.status = 'active' LIMIT 5)
        UNION
        (SELECT DISTINCT pl.location_district as name, 'district' as type FROM product_listings pl WHERE pl.location_district LIKE ? AND pl.status = 'active' LIMIT 5)
        UNION
        (SELECT DISTINCT pl.title as name, 'product' as type FROM product_listings pl WHERE pl.title LIKE ? AND pl.status = 'active' LIMIT 5)
        ORDER BY name
        LIMIT 10
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$query, $query, $query, $query, $query]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
