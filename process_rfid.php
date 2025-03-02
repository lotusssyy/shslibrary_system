<?php
header("Content-Type: text/plain");

require 'vendor/autoload.php';
require 'includes/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "library_management";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$rfid_number = isset($_POST['rfid_number']) ? trim($_POST['rfid_number']) : '';
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$barcode = isset($_POST['barcode']) ? trim($_POST['barcode']) : '';

if (empty($rfid_number) || empty($action) || empty($barcode)) {
    echo "MISSING_PARAMETERS";
    exit();
}

$conn->begin_transaction();

try {
    error_log("Starting transaction for RFID: $rfid_number, Action: $action, Barcode: $barcode");

    // Validate user
    $user_query = $conn->prepare("SELECT id, email FROM users WHERE rfid_number = ?");
    $user_query->bind_param("s", $rfid_number);
    $user_query->execute();
    $user_result = $user_query->get_result();
    
    if ($user_result->num_rows == 0) {
        throw new Exception("USER_NOT_FOUND");
    }
    $user = $user_result->fetch_assoc();
    $user_id = $user['id'];
    $user_email = $user['email'];

    // Validate book
    $book_query = $conn->prepare("SELECT id, genre, title, available, total_quantity FROM books WHERE barcode = ?");
    $book_query->bind_param("s", $barcode);
    $book_query->execute();
    $book_result = $book_query->get_result();
    
    if ($book_result->num_rows == 0) {
        throw new Exception("BOOK_NOT_FOUND");
    }
    $book = $book_result->fetch_assoc();
    $book_id = $book['id'];
    $book_genre = $book['genre'];
    $book_title = $book['title'];
    $book_available = $book['available'];
    $total_quantity = $book['total_quantity'];

    // Calculate due date based on genre
    $due_date = date('Y-m-d', strtotime("+7 days")); // Default 7 days
    switch ($book_genre) {
        case 'Fiction':
            $due_date = date('Y-m-d', strtotime("+14 days")); // 2 weeks
            break;
        case 'Non-Fiction':
            $due_date = date('Y-m-d', strtotime("+21 days")); // 3 weeks
            break;
        case 'Science':
            $due_date = date('Y-m-d', strtotime("+10 days")); // 10 days
            break;
        case 'History':
            $due_date = date('Y-m-d', strtotime("+14 days")); // 2 weeks
            break;
        case 'Biography':
            $due_date = date('Y-m-d', strtotime("+7 days")); // 1 week
            break;
    }

    if ($action == "BORROW") {
        if ($book_available <= 0) {
            throw new Exception("NO_BOOKS_AVAILABLE");
        }
        // Decrease available quantity
        $update_book = $conn->prepare("UPDATE books SET available = available - 1 WHERE id = ?");
        $update_book->bind_param("i", $book_id);
        $update_book->execute();

        // Record transaction with borrowed_date
        $trans_query = $conn->prepare("INSERT INTO transactions (user_id, book_id, action, borrowed_date, due_date) VALUES (?, ?, 'BORROW', NOW(), ?)");
        $trans_query->bind_param("iis", $user_id, $book_id, $due_date);
        $trans_query->execute();

        $conn->commit();
        echo "BORROW_SUCCESS";
        
        // Send notification
        notifyStudent($user_id, $user_email, $book_title, "borrowed", $due_date);
    } elseif ($action == "RETURN") {
        if ($book_available >= $total_quantity) {
            throw new Exception("BOOK_ALREADY_RETURNED");
        }
        // Increase available quantity
        $update_book = $conn->prepare("UPDATE books SET available = available + 1 WHERE id = ?");
        $update_book->bind_param("i", $book_id);
        $update_book->execute();

        // Record transaction (no borrowed_date for RETURN)
        $trans_query = $conn->prepare("INSERT INTO transactions (user_id, book_id, action) VALUES (?, ?, 'RETURN')");
        $trans_query->bind_param("ii", $user_id, $book_id);
        $trans_query->execute();

        $conn->commit();
        echo "RETURN_SUCCESS";
        
        // Send notification
        notifyStudent($user_id, $user_email, $book_title, "returned", null);
    } else {
        throw new Exception("INVALID_ACTION");
    }

} catch (Exception $e) {
    $conn->rollback();
    echo $e->getMessage();
}

$conn->close();

// Notification function with PHPMailer
function notifyStudent($user_id, $email, $book_title, $action, $due_date) {
    global $pdo;

    error_log("Sending notification to $email for $action of '$book_title'");

    // Website Notification (Notices)
    $message = $action === "borrowed" 
        ? "You have borrowed '$book_title'. Due date: $due_date."
        : "You have returned '$book_title'.";
    $notice_query = $pdo->prepare("INSERT INTO notices (user_id, message, created_at) VALUES (?, ?, NOW())");
    $notice_query->execute([$user_id, $message]);

    // Email Notification with PHPMailer
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'libraryuclm@gmail.com'; // Replace with your Gmail
        $mail->Password = 'crof wdsk aiky vays'; // Replace with App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('your-email@gmail.com', 'SHS Library System');
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