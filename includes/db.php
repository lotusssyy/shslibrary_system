<?php
// Load .env file into environment variables (if not already set)
function loadEnv(string $path): void {
    if (!file_exists($path) || getenv('SMTP_USERNAME') !== false) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = trim($parts[0]);
        $value = trim($parts[1], " \t\n\r\0\x0B\"'");
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

$envPath = __DIR__ . '/../.env';
loadEnv($envPath);

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