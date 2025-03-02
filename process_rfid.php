<?php
header("Content-Type: text/plain");

require 'vendor/autoload.php';
require 'includes/db.php'; // Use JawsDB connection

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Use PDO from db.php instead of mysqli
if (empty($pdo)) {
    die("Connection failed: PDO not initialized");
}

$rfid_number = isset($_POST['rfid_number']) ? trim($_POST['rfid_number']) : '';
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$barcode = isset($_POST['barcode']) ? trim($_POST['barcode']) : '';

if (empty($rfid_number) || empty($action) || empty($barcode)) {
    echo "MISSING_PARAMETERS";
    exit();
}

$pdo->beginTransaction();

try {
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
    $due_date = date('Y-m-d', strtotime("+7 days")); // Default 7 days
    switch ($book_genre) {
        case 'Fiction':
            $due_date = date('Y-m-d', strtotime("+14 days"));
            break;
        case 'Non-Fiction':
            $due_date = date('Y-m-d', strtotime("+21 days"));
            break;
        case 'Science':
            $due_date = date('Y-m-d', strtotime("+10 days"));
            break;
        case 'History':
            $due_date = date('Y-m-d', strtotime("+14 days"));
            break;
        case 'Biography':
            $due_date = date('Y-m-d', strtotime("+7 days"));
            break;
    }

    if ($action == "BORROW") {
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
    } elseif ($action == "RETURN") {
        if ($book_available >= $total_quantity) {
            throw new Exception("BOOK_ALREADY_RETURNED");
        }
        $update_book = $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?");
        $update_book->execute([$book_id]);

        $trans_query = $pdo->prepare("INSERT INTO transactions (user_id, book_id, action) VALUES (?, ?, 'RETURN')");
        $trans_query->execute([$user_id, $book_id]);

        $pdo->commit();
        echo "RETURN_SUCCESS";
        
        notifyStudent($user_id, $user_email, $book_title, "returned", null);
    } else {
        throw new Exception("INVALID_ACTION");
    }

} catch (Exception $e) {
    $pdo->rollBack();
    echo $e->getMessage();
}

// Notification function with PHPMailer
function notifyStudent($user_id, $email, $book_title, $action, $due_date) {
    global $pdo;

    error_log("Sending notification to $email for $action of '$book_title'");

    $message = $action === "borrowed" 
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
        $mail->Password = 'crof wdsk aiky vays'; // Ensure this is your App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('libraryuclm@gmail.com', 'SHS Library System');
        $mail->addAddress($email);

        $subject = $action === "borrowed" 
            ? "Book Borrowed: $book_title"
            : "Book Returned: $book_title";
        $body = $action === "borrowed" 
            ? "Dear Student,\n\nYou have successfully borrowed '$book_title'. Please return it by $due_date.\n\nRegards,\nSHS Library System"
            : "Dear Student,\n\nYou have successfully returned '$book_title'.\n\nRegards,\nSHS Library System";
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        error_log("Email sent to $email for $action of '$book_title'");
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}
?>