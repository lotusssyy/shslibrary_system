<?php
include 'includes/db.php';
require 'includes/PHPMailer/src/PHPMailer.php';
require 'includes/PHPMailer/src/SMTP.php';
require 'includes/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

error_reporting(E_ALL);
ini_set('display_errors', 1); // Enable for debugging

// Query for books due today
$query_today = $pdo->prepare("SELECT t.user_id, u.email, b.title, t.due_date 
                             FROM transactions t 
                             JOIN users u ON t.user_id = u.id 
                             JOIN books b ON t.book_id = b.id 
                             WHERE t.action = 'BORROW' AND t.due_date = DATE(NOW())");
$query_today->execute();
$due_today = $query_today->fetchAll(PDO::FETCH_ASSOC);

// Query for books due tomorrow (one day before reminder)
$query_tomorrow = $pdo->prepare("SELECT t.user_id, u.email, b.title, t.due_date 
                                FROM transactions t 
                                JOIN users u ON t.user_id = u.id 
                                JOIN books b ON t.book_id = b.id 
                                WHERE t.action = 'BORROW' AND t.due_date = DATE(NOW() + INTERVAL 1 DAY)");
$query_tomorrow->execute();
$due_tomorrow = $query_tomorrow->fetchAll(PDO::FETCH_ASSOC);

// Process notifications for today
foreach ($due_today as $transaction) {
    $user_id = $transaction['user_id'];
    $email = $transaction['email'];
    $book_title = $transaction['title'];
    $due_date = $transaction['due_date'];

    $message = "Your borrowed book '$book_title' is due today ($due_date). Please return it to avoid penalties.";
    $notice_query = $pdo->prepare("INSERT INTO notices (user_id, message, created_at) VALUES (?, ?, NOW())");
    $notice_query->execute([$user_id, $message]);

    sendEmailNotification($email, $book_title, $due_date, "due today");
}

// Process reminders for tomorrow
foreach ($due_tomorrow as $transaction) {
    $user_id = $transaction['user_id'];
    $email = $transaction['email'];
    $book_title = $transaction['title'];
    $due_date = $transaction['due_date'];

    $message = "Reminder: Your borrowed book '$book_title' is due tomorrow ($due_date). Please plan to return it.";
    $notice_query = $pdo->prepare("INSERT INTO notices (user_id, message, created_at) VALUES (?, ?, NOW())");
    $notice_query->execute([$user_id, $message]);

    sendEmailNotification($email, $book_title, $due_date, "due tomorrow");
}

function sendEmailNotification($email, $book_title, $due_date, $context) {
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

        $subject = ($context === "due today") 
            ? "Book Due Today: $book_title"
            : "Reminder: Book Due Tomorrow: $book_title";
        $body = ($context === "due today") 
            ? "Dear Student,\n\nYour borrowed book '$book_title' is due today ($due_date). Please return it to avoid penalties.\n\nRegards,\nSHS Library System"
            : "Dear Student,\n\nThis is a reminder that your borrowed book '$book_title' is due tomorrow ($due_date). Please plan to return it.\n\nRegards,\nSHS Library System";
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        error_log("{$context} email sent to $email for '$book_title'");
    } catch (Exception $e) {
        error_log("{$context} email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}
?>