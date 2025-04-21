<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure no output before JSON response
ob_start();

// Include database connection
$db_included = include 'includes/db.php';
if (!$db_included || !isset($pdo)) {
    error_log("Error: Failed to include db.php or PDO not set.");
    http_response_code(500);
    echo json_encode(['error' => 'Failed to establish database connection']);
    ob_end_flush();
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['clear_rfid']) && $_POST['clear_rfid'] === 'true') {
            file_put_contents('last_rfid.txt', ''); // Clear RFID file
            echo json_encode(['status' => 'cleared']);
        } elseif (isset($_POST['clear_barcode']) && $_POST['clear_barcode'] === 'true') {
            file_put_contents('last_barcode.txt', ''); // Clear barcode file
            echo json_encode(['status' => 'cleared']);
        } elseif (isset($_POST['barcode_scan']) && $_POST['barcode_scan'] === 'true') {
            $barcode = file_get_contents('last_barcode.txt');
            if ($barcode) {
                // Validate barcode against the books table
                $stmt = $pdo->prepare("SELECT * FROM books WHERE barcode = ?");
                $stmt->execute([trim($barcode)]);
                $book = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($book) {
                    echo json_encode([
                        'status' => 'success',
                        'barcode' => trim($barcode),
                        'book' => $book
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode([
                        'error' => 'Book not found for barcode: ' . trim($barcode)
                    ]);
                }
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'No barcode available']);
            }
        } elseif (isset($_POST['barcode'])) {
            $barcode = trim($_POST['barcode']);
            file_put_contents('last_barcode.txt', $barcode); // Store barcode
            // Validate barcode against the books table
            $stmt = $pdo->prepare("SELECT * FROM books WHERE barcode = ?");
            $stmt->execute([$barcode]);
            $book = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($book) {
                echo json_encode([
                    'status' => 'success',
                    'barcode' => $barcode,
                    'book' => $book
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Book not found for barcode: ' . $barcode
                ]);
            }
        } elseif (isset($_POST['rfid_scan']) && $_POST['rfid_scan'] === 'true') {
            $rfid = file_get_contents('last_rfid.txt');
            if ($rfid) {
                // Validate RFID against the users table
                $stmt = $pdo->prepare("SELECT * FROM users WHERE rfid_number = ?");
                $stmt->execute([trim($rfid)]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    echo json_encode([
                        'status' => 'success',
                        'rfid_number' => trim($rfid),
                        'user' => $user
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode([
                        'error' => 'User not found for RFID: ' . trim($rfid)
                    ]);
                }
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'No RFID available']);
            }
        } elseif (isset($_POST['rfid_number'])) {
            $rfid = trim($_POST['rfid_number']);
            file_put_contents('last_rfid.txt', $rfid); // Store RFID
            // Validate RFID against the users table
            $stmt = $pdo->prepare("SELECT * FROM users WHERE rfid_number = ?");
            $stmt->execute([$rfid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                echo json_encode([
                    'status' => 'success',
                    'rfid_number' => $rfid,
                    'user' => $user
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'error' => 'User not found for RFID: ' . $rfid
                ]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid request']);
        }
    } catch (PDOException $e) {
        error_log("Scan error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("General error in scan.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

ob_end_flush();
?>