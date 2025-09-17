<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$conn = getDB();
$period = $_GET['period'] ?? 7; // Default to 7 days
$crop_id = $_GET['crop_id'] ?? null;
$state = $_GET['state'] ?? null;

try {
    // Build the base query
    $where_conditions = ["mp.date >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
    $params = [$period];
    
    if ($crop_id) {
        $where_conditions[] = "mp.crop_id = ?";
        $params[] = $crop_id;
    }
    
    if ($state) {
        $where_conditions[] = "mp.state = ?";
        $params[] = $state;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get market price trends
    $market_sql = "
        SELECT 
            DATE(mp.date) as date,
            AVG(mp.min_price) as avg_min_price,
            AVG(mp.max_price) as avg_max_price,
            AVG(mp.modal_price) as avg_modal_price,
            c.name as crop_name
        FROM market_prices mp
        JOIN crops c ON mp.crop_id = c.id
        WHERE $where_clause
        GROUP BY DATE(mp.date), c.id
        ORDER BY mp.date ASC
    ";
    
    $stmt = $conn->prepare($market_sql);
    $stmt->execute($params);
    $market_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get platform listing prices for comparison
    $listing_sql = "
        SELECT 
            DATE(pl.created_at) as date,
            AVG(pl.price_per_unit) as avg_listing_price,
            COUNT(*) as listing_count,
            c.name as crop_name
        FROM product_listings pl
        JOIN crops c ON pl.crop_id = c.id
        WHERE pl.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        AND pl.status = 'active'
    ";
    
    $listing_params = [$period];
    
    if ($crop_id) {
        $listing_sql .= " AND pl.crop_id = ?";
        $listing_params[] = $crop_id;
    }
    
    if ($state) {
        $listing_sql .= " AND pl.location_state = ?";
        $listing_params[] = $state;
    }
    
    $listing_sql .= " GROUP BY DATE(pl.created_at), c.id ORDER BY pl.created_at ASC";
    
    $stmt = $conn->prepare($listing_sql);
    $stmt->execute($listing_params);
    $listing_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get price comparison summary
    $comparison_sql = "
        SELECT 
            c.name as crop_name,
            AVG(mp.modal_price) as avg_market_price,
            AVG(pl.price_per_unit) as avg_listing_price,
            COUNT(DISTINCT pl.id) as total_listings,
            COUNT(DISTINCT mp.id) as market_records
        FROM crops c
        LEFT JOIN market_prices mp ON c.id = mp.crop_id AND mp.date >= DATE_SUB(NOW(), INTERVAL ? DAY)
        LEFT JOIN product_listings pl ON c.id = pl.crop_id AND pl.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND pl.status = 'active'
        WHERE (mp.id IS NOT NULL OR pl.id IS NOT NULL)
    ";
    
    $comparison_params = [$period, $period];
    
    if ($crop_id) {
        $comparison_sql .= " AND c.id = ?";
        $comparison_params[] = $crop_id;
    }
    
    if ($state) {
        $comparison_sql .= " AND (mp.state = ? OR pl.location_state = ?)";
        $comparison_params[] = $state;
        $comparison_params[] = $state;
    }
    
    $comparison_sql .= " GROUP BY c.id, c.name HAVING COUNT(DISTINCT pl.id) > 0 ORDER BY c.name";
    
    $stmt = $conn->prepare($comparison_sql);
    $stmt->execute($comparison_params);
    $comparison_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $response = [
        'market_trends' => $market_data,
        'listing_trends' => $listing_data,
        'price_comparison' => $comparison_data,
        'period' => $period,
        'generated_at' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
