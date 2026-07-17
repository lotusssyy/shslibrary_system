<?php
header('Content-Type: application/json');
session_start();
require '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$count = (int)$stmt->fetchColumn();

echo json_encode(['count' => $count]);
