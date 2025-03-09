<?php
header("Content-Type: text/plain");
require 'includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1); // Enable for debugging

$rfid_number = isset($_POST['rfid_number']) ? trim($_POST['rfid_number']) : '';
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$barcode = isset($_POST['barcode']) ? trim($_POST['barcode']) : '';

if (empty($rfid_number) || empty($action) || empty($barcode) || !in_array(strtoupper($action), ['BORROW', 'RETURN'])) {
    error_log("Missing parameters: rfid_number=$rfid_number, action=$action, barcode=$barcode");
    echo "MISSING_PARAMETERS";
    exit;
}

try {
    $pdo->beginTransaction();
    error_log("Starting transaction for RFID: $rfid_number, Action: $action, Barcode: $barcode");

    // Validate user
    $user_query = $pdo->prepare("SELECT id, email FROM users WHERE rfid_number = ?");
    $user_query->execute([$rfid_number]);
    $user = $user_query->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception("USER_NOT_FOUND");
    }
    $user_id = $user['id'];
    $user_email = $user['email'];

    // Validate book
    $book_query = $pdo->prepare("SELECT id, genre, title, available, total_quantity FROM books WHERE barcode = ?");
    $book_query->execute([$barcode]);
    $book = $book_query->fetch(PDO::FETCH_ASSOC);
    if (!$book) {
        throw new Exception("BOOK_NOT_FOUND");
    }
    $book_id = $book['id'];
    $book_genre = $book['genre'];
    $book_title = $book['title'];
    $book_available = $book['available'];
    $total_quantity = $book['total_quantity'];

    // Calculate due date based on genre
    $due_date = date('Y-m-d', strtotime("+7 days"));
    switch (strtoupper($book_genre)) {
        case 'FICTION':
            $due_date = date('Y-m-d', strtotime("+14 days"));
            break;
        case 'NON-FICTION':
            $due_date = date('Y-m-d', strtotime("+21 days"));
            break;
        case 'SCIENCE':
            $due_date = date('Y-m-d', strtotime("+10 days"));
            break;
        case 'HISTORY':
            $due_date = date('Y-m-d', strtotime("+14 days"));
            break;
        case 'BIOGRAPHY':
            $due_date = date('Y-m-d', strtotime("+7 days"));
            break;
    }

    if (strtoupper($action) == "BORROW") {
        if ($book_available <= 0) {
            throw new Exception("NO_BOOKS_AVAILABLE");
        }
        $update_book = $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?");
        $update_book->execute([$book_id]);

        $trans_query = $pdo->prepare("INSERT INTO transactions (user_id, book_id, action, borrowed_date, due_date) VALUES (?, ?, 'BORROW', NOW(), ?)");
        $trans_query->execute([$user_id, $book_id, $due_date]);

        $pdo->commit();
        echo "BORROW_SUCCESS";
        notifyStudent($user_id, $user_email, $book_title, "borrowed", $due_date);
    } elseif (strtoupper($action) == "RETURN") {
        if ($book_available >= $total_quantity) {
            throw new Exception("BOOK_ALREADY_RETURNED");
        }

        // Check if a BORROW transaction exists
        $check_borrow = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND book_id = ? AND action = 'BORROW' AND returned_date IS NULL");
        $check_borrow->execute([$user_id, $book_id]);
        $borrow_record = $check_borrow->fetch(PDO::FETCH_ASSOC);
        if (!$borrow_record) {
            throw new Exception("NO_BORROW_RECORD");
        }

        // Update the book availability
        $update_book = $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?");
        $update_book->execute([$book_id]);

        // Update the existing BORROW transaction with returned_date
        $trans_query = $pdo->prepare("UPDATE transactions SET returned_date = NOW() WHERE id = ?");
        $trans_query->execute([$borrow_record['id']]);

        $pdo->commit();
        echo "RETURN_SUCCESS";
        notifyStudent($user_id, $user_email, $book_title, "returned", null);
    } else {
        throw new Exception("INVALID_ACTION");
    }
} catch (Exception $e) {
    $pdo->rollBack();
    $error_message = $e->getMessage();
    error_log("Transaction failed: $error_message");
    echo $error_message;
}

function notifyStudent($user_id, $email, $book_title, $action, $due_date) {
    global $pdo;

    error_log("Sending notification to $email for $action of '$book_title'");

    $message = ($action === "borrowed") 
        ? "You have borrowed '$book_title'. Due date: $due_date."
        : "You have returned '$book_title'.";
    $notice_query = $pdo->prepare("INSERT INTO notices (user_id, message, created_at) VALUES (?, ?, NOW())");
    $notice_query->execute([$user_id, $message]);

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'libraryuclm@gmail.com';
        $mail->Password = 'crof wdsk aiky vays'; // Verify App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('libraryuclm@gmail.com', 'SHS Library System');
        $mail->addAddress($email);

        $subject = ($action === "borrowed") 
            ? "Book Borrowed: $book_title"
            : "Book Returned: $book_title";
        $body = ($action === "borrowed") 
            ? "Dear Student,\n\nYou have successfully borrowed '$book_title'. Please return it by $due_date.\n\nRegards,\nSHS Library System"
            : "Dear Student,\n\nYou have successfully returned '$book_title'.\n\nRegards,\nSHS Library System";
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        error_log("Email sent to $email for $action of '$book_title'");
    } catch (Exception $e) {
        error_log("Email failed: " . $e->getMessage());
    }
}
?>