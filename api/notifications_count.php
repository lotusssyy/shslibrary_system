<?php
header('Content-Type: application/json');
require '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_status = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $count = (int) $stmt->fetchColumn();
    echo json_encode(['count' => $count]);
} catch (PDOException $e) {
    echo json_encode(['count' => 0]);
}
