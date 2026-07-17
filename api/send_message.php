<?php
header('Content-Type: application/json');
session_start();
require '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$sender_id = (int)$_SESSION['user_id'];
$sender_role = $_SESSION['role'] ?? 'student';
$receiver_id = isset($input['receiver_id']) ? (int)$input['receiver_id'] : 0;
$subject = trim($input['subject'] ?? '');
$body = trim($input['body'] ?? '');

if ($subject === '' || $body === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Subject and message body are required']);
    exit;
}

if ($receiver_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid receiver']);
    exit;
}

// Validate receiver exists
$stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
$stmt->execute([$receiver_id]);
$receiver = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$receiver) {
    http_response_code(400);
    echo json_encode(['error' => 'Receiver not found']);
    exit;
}

// Students can only message librarians, librarians can message anyone
if ($sender_role === 'student' && $receiver['role'] !== 'librarian') {
    http_response_code(403);
    echo json_encode(['error' => 'Students can only message librarians']);
    exit;
}

$stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, subject, body) VALUES (?, ?, ?, ?)");
$stmt->execute([$sender_id, $receiver_id, $subject, $body]);

echo json_encode(['status' => 'ok', 'message_id' => $pdo->lastInsertId()]);
