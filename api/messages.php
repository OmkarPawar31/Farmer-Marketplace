<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];
$partner_id = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$product_id = isset($_GET['product']) ? (int)$_GET['product'] : null;
$last_message_id = isset($_GET['last_message_id']) ? (int)$_GET['last_message_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    if (!empty($message)) {
        $stmt = $conn->prepare("
            INSERT INTO messages (sender_id, receiver_id, product_id, message, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $partner_id, $product_id, $message]);
        echo json_encode(['success' => true, 'message_id' => $conn->lastInsertId()]);
    }
    exit();
}

$stmt = $conn->prepare("
    SELECT m.*, u.username as sender_username
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
    " . ($product_id ? " AND (m.product_id = ? OR m.product_id IS NULL)" : "") . "
    AND m.id > ?
    ORDER BY m.created_at ASC
");

if ($product_id) {
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id, $product_id, $last_message_id]);
} else {
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id, $last_message_id]);
}

$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mark messages as read
$stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
$stmt->execute([$partner_id, $user_id]);

echo json_encode($messages);

