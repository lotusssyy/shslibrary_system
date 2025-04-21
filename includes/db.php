<?php
$jawSDBUrl = getenv("JAWSDB_URL");
if ($jawSDBUrl === false) {
    error_log("Error: JAWSDB_URL environment variable not set.");
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration error: JAWSDB_URL not set']);
    exit;
}

$url = parse_url($jawSDBUrl);
if ($url === false) {
    error_log("Error: Failed to parse JAWSDB_URL: " . $jawSDBUrl);
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration error: Invalid JAWSDB_URL']);
    exit;
}

$host = $url["host"] ?? null;
$username = $url["user"] ?? null;
$password = $url["pass"] ?? null;
$dbname = isset($url["path"]) ? substr($url["path"], 1) : null;
$port = $url["port"] ?? 3306;

if (!$host || !$username || !$dbname) {
    error_log("Error: Missing required components in JAWSDB_URL. Parsed: " . json_encode($url));
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration error: Missing required components']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}
?>