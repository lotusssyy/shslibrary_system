<?php
header('Content-Type: application/json');
session_start();
require '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$other_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$subject = isset($_GET['subject']) ? trim($_GET['subject']) : '';

// Fetch conversation list (grouped by subject + other party)
if ($other_id <= 0) {
    $stmt = $pdo->prepare("
        SELECT m.subject,
               CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END AS other_user_id,
               u.first_name, u.last_name, u.role AS other_role,
               (SELECT m2.body FROM messages m2
                WHERE m2.subject = m.subject
                  AND ((m2.sender_id = m.sender_id AND m2.receiver_id = m.receiver_id)
                       OR (m2.sender_id = m.receiver_id AND m2.receiver_id = m.sender_id))
                ORDER BY m2.created_at DESC LIMIT 1) AS last_message,
               (SELECT m2.created_at FROM messages m2
                WHERE m2.subject = m.subject
                  AND ((m2.sender_id = m.sender_id AND m2.receiver_id = m.receiver_id)
                       OR (m2.sender_id = m.receiver_id AND m2.receiver_id = m.sender_id))
                ORDER BY m2.created_at DESC LIMIT 1) AS last_time,
               (SELECT COUNT(*) FROM messages m2
                WHERE m2.subject = m.subject
                  AND m2.receiver_id = ?
                  AND m2.sender_id = CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
                  AND m2.is_read = 0) AS unread_count
        FROM messages m
        JOIN users u ON u.id = CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
        WHERE m.sender_id = ? OR m.receiver_id = ?
        GROUP BY m.subject, other_user_id
        ORDER BY last_time DESC
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['conversations' => $conversations]);
    exit;
}

// Fetch messages in a specific conversation
if ($subject === '') {
    echo json_encode(['messages' => []]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT m.id, m.sender_id, m.receiver_id, m.subject, m.body, m.is_read, m.created_at,
           s.first_name AS sender_first, s.last_name AS sender_last, s.role AS sender_role
    FROM messages m
    JOIN users s ON m.sender_id = s.id
    WHERE m.subject = ?
      AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
    ORDER BY m.created_at ASC
");
$stmt->execute([$subject, $user_id, $other_id, $other_id, $user_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mark received messages as read
$pdo->prepare("UPDATE messages SET is_read = 1 WHERE subject = ? AND receiver_id = ? AND sender_id = ? AND is_read = 0")
    ->execute([$subject, $user_id, $other_id]);

echo json_encode(['messages' => $messages]);
