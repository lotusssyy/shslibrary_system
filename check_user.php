<?php
header("Content-Type: text/plain");

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "library_management";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$rfid_number = isset($_REQUEST['rfid_number']) ? trim($conn->real_escape_string($_REQUEST['rfid_number'])) : '';
if (empty($rfid_number)) {
    echo "MISSING_RFID";
    exit();
}

$check_stmt = $conn->prepare("SELECT role FROM users WHERE rfid_number = ?");
$check_stmt->bind_param("s", $rfid_number);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    $user = $check_result->fetch_assoc();
    echo $user['role'] === 'admin' ? "VALID_ADMIN" : "VALID";
} else {
    echo "INVALID";
}

$check_stmt->close();
$conn->close();
?>