<?php
header('Content-Type: application/json');
require '../includes/db.php';

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

if ($user_id <= 0) {
    echo json_encode([]);
    exit;
}

// Fetch notices for the user
$stmt = $pdo->prepare("SELECT message, created_at FROM notices WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$notices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert created_at to 12-hour format
foreach ($notices as &$notice) {
    $notice['message'] = htmlspecialchars($notice['message']);
    $timestamp = strtotime($notice['created_at']);
    $notice['created_at'] = date('Y-m-d h:i:s A', $timestamp); // 12-hour format with AM/PM
}

echo json_encode($notices);
?>