<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in and is a buyer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'buyer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if report_id is provided
if (!isset($_GET['report_id'])) {
    echo json_encode(['success' => false, 'message' => 'Report ID is required']);
    exit;
}

$report_id = filter_var($_GET['report_id'], FILTER_VALIDATE_INT);
if ($report_id === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid report ID']);
    exit;
}

try {
    $pdo = getDB();
    
    // Fetch detailed report information
    $stmt = $pdo->prepare("
        SELECT 
            qr.report_id as id,
            qr.quality_grade,
            qr.overall_rating,
            qr.inspection_date,
            qr.report_details,
            qr.inspector_name,
            qr.product_name,
            qr.farmer_name,
            o.id as order_id,
            o.quantity,
            o.total_amount,
            o.created_at as order_date
        FROM quality_reports qr
        JOIN orders o ON qr.order_id = o.id
        WHERE qr.report_id = ? AND o.buyer_id = ?
    ");
    
    $stmt->execute([$report_id, $_SESSION['user_id']]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        echo json_encode(['success' => false, 'message' => 'Report not found or access denied']);
        exit;
    }
    
    // Format the response
    $response = [
        'success' => true,
        'report' => [
            'id' => $report['id'],
            'product_name' => $report['product_name'],
            'farmer_name' => $report['farmer_name'],
            'quality_grade' => $report['quality_grade'],
            'overall_rating' => (int)$report['overall_rating'],
            'inspection_date' => $report['inspection_date'],
            'inspector_name' => $report['inspector_name'],
            'report_details' => $report['report_details'],
            'order_id' => $report['order_id'],
            'quantity' => $report['quantity'],
            'total_amount' => $report['total_amount'],
            'order_date' => $report['order_date']
        ]
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("Database error in get_report_details.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error in get_report_details.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while fetching report details']);
}
?>
