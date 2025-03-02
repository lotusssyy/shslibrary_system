<?php
header("Content-Type: text/plain");
require_once 'includes/db.php';

$rfid_number = isset($_GET['rfid_number']) ? trim($_GET['rfid_number']) : '';
if (empty($rfid_number)) {
    echo "MISSING_RFID";
    exit();
}

$check_stmt = $pdo->prepare("SELECT role FROM users WHERE rfid_number = ?");
$check_stmt->execute([$rfid_number]);
$user = $check_stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo $user['role'] === 'admin' ? "VALID_ADMIN" : "VALID";
} else {
    echo "INVALID";
}
?>