<?php
include 'includes/db.php';
require 'includes/PHPMailer/src/PHPMailer.php';
require 'includes/PHPMailer/src/SMTP.php';
require 'includes/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$query = $pdo->prepare("SELECT t.user_id, u.email, b.title, t.due_date 
                        FROM transactions t 
                        JOIN users u ON t.user_id = u.id 
                        JOIN books b ON t.book_id = b.id 
                        WHERE t.action = 'BORROW' AND t.due_date = DATE(NOW())");
$query->execute();
$due_today = $query->fetchAll(PDO::FETCH_ASSOC);

foreach ($due_today as $transaction) {
    $user_id = $transaction['user_id'];
    $email = $transaction['email'];
    $book_title = $transaction['title'];
    $due_date = $transaction['due_date'];

    // Website Notification
    $message = "Your borrowed book '$book_title' is due today ($due_date). Please return it.";
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

        $mail->setFrom('your-email@gmail.com', 'Library System');
        $mail->addAddress($email);

        $mail->Subject = "Book Due Today: $book_title";
        $mail->Body = "Dear Student,\n\nYour borrowed book '$book_title' is due today ($due_date). Please return it to avoid penalties.\n\nRegards,\nSHS Library System";

        $mail->send();
        error_log("Due date email sent to $email for '$book_title'");
    } catch (Exception $e) {
        error_log("Due date email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}
?>