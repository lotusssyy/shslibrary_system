<?php
$host = '127.0.0.1';
$username = 'root';
$password = '';
$dbname = 'library_management';

define('BORROW_LIMIT', 5);
define('FINE_PER_DAY', 5.00);

date_default_timezone_set('Asia/Manila');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>