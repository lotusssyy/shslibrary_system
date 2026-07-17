<?php
header('Content-Type: application/json');

$barcode = isset($_POST['barcode']) ? trim($_POST['barcode']) : '';

if ($barcode === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No barcode provided.']);
    exit;
}

$queueFile = __DIR__ . '/../barcode_queue.txt';

$existing = '';
if (file_exists($queueFile)) {
    $existing = file_get_contents($queueFile);
}

if ($existing !== '' && substr($existing, -1) !== "\n") {
    $existing .= "\n";
}

$written = file_put_contents($queueFile, $existing . $barcode . "\n");

if ($written === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to write barcode to queue.']);
    exit;
}

$lines = array_filter(explode("\n", file_get_contents($queueFile)), function($l) { return trim($l) !== ''; });
$position = count($lines);

echo json_encode(['status' => 'ok', 'barcode' => $barcode, 'queue_position' => $position]);
