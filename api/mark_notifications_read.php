<?php
header('Content-Type: application/json');
require '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("UPDATE notifications SET read_status = 1 WHERE user_id = ? AND read_status = 0");
    $stmt->execute([$user_id]);
    $affected = $stmt->rowCount();
    echo json_encode(['success' => true, 'marked' => $affected]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
