<?php
// Support Heroku JAWSDB_URL or fall back to local XAMPP defaults
$databaseUrl = getenv("JAWSDB_URL");
if ($databaseUrl) {
    $url = parse_url($databaseUrl);
    $host = $url["host"];
    $username = $url["user"];
    $password = $url["pass"];
    $dbname = substr($url["path"], 1);
} else {
    // Local XAMPP defaults — adjust if your local MySQL uses different credentials
    $host = '127.0.0.1';
    $username = 'root';
    $password = '';
    $dbname = 'library_system';
}
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>