<?php
header('Content-Type: application/json');
require '../includes/db.php';

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

if ($user_id <= 0) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, message, read_status, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$notices = $stmt->fetchAll(PDO::FETCH_ASSOC);

function timeAgo($datetime) {
    $now = time();
    $then = strtotime($datetime);
    if (!$then) return 'Just now';
    $diff = $now - $then;
    if ($diff < 0) return 'Just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $then);
}

foreach ($notices as &$notice) {
    $raw_date = $notice['created_at'];
    $notice['message'] = htmlspecialchars($notice['message']);
    $notice['created_at'] = date('M d, Y \a\t h:i A', strtotime($raw_date));
    $notice['time_ago'] = timeAgo($raw_date);
}

echo json_encode($notices);
