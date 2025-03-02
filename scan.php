<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['clear_rfid']) && $_POST['clear_rfid'] === 'true') {
        file_put_contents('last_rfid.txt', ''); // Clear RFID file
        echo json_encode(['status' => 'cleared']);
    } elseif (isset($_POST['clear_barcode']) && $_POST['clear_barcode'] === 'true') {
        file_put_contents('last_barcode.txt', ''); // Clear barcode file
        echo json_encode(['status' => 'cleared']);
    } elseif (isset($_POST['barcode_scan']) && $_POST['barcode_scan'] === 'true') {
        $barcode = file_get_contents('last_barcode.txt');
        if ($barcode) {
            echo json_encode(['barcode' => trim($barcode)]);
        } else {
            echo json_encode(['error' => 'No barcode available']);
        }
    } elseif (isset($_POST['barcode'])) {
        file_put_contents('last_barcode.txt', trim($_POST['barcode']));
        echo json_encode(['barcode' => trim($_POST['barcode'])]);
    } elseif (isset($_POST['rfid_scan']) && $_POST['rfid_scan'] === 'true') {
        $rfid = file_get_contents('last_rfid.txt');
        if ($rfid) {
            echo json_encode(['rfid_number' => trim($rfid)]);
        } else {
            echo json_encode(['error' => 'No RFID available']);
        }
    } elseif (isset($_POST['rfid_number'])) {
        file_put_contents('last_rfid.txt', trim($_POST['rfid_number']));
        echo json_encode(['rfid_number' => trim($_POST['rfid_number'])]);
    } else {
        echo json_encode(['error' => 'Invalid request']);
    }
} else {
    echo json_encode(['error' => 'Method not allowed']);
}
?>