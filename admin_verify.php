<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db.php';

$verificationCode = $_GET['verification_code'];
$rfidNumber = $_GET['rfid_number'];

try {
    $query = $pdo->prepare("SELECT id FROM users WHERE verification_token = ? AND status = 'pending'");
    $query->execute([$verificationCode]);
    
    if ($query->rowCount() > 0) {
        $user = $query->fetch(PDO::FETCH_ASSOC);
        
        // Update user status and RFID number
        $updateQuery = $pdo->prepare("UPDATE users SET status = 'active', rfid_number = ? WHERE id = ?");
        $updateQuery->execute([$rfidNumber, $user['id']]);
        
        echo "VERIFIED";
    } else {
        echo "FAILED";
    }
} catch (Exception $e) {
    echo "ERROR";
}
?>