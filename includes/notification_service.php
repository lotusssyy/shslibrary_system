<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function sendTransactionNotification(PDO $pdo, int $user_id, string $email, string $book_title, string $action, ?string $due_date): bool {
    $message = $action === 'borrowed'
        ? "You have borrowed '$book_title'. Due date: $due_date."
        : "You have returned '$book_title'.";

    $notice_query = $pdo->prepare('INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 0, NOW())');
    $notice_query->execute([$user_id, $message]);

    $smtp_username = getenv('SMTP_USERNAME');
    $smtp_password = getenv('SMTP_PASSWORD');
    if (!$smtp_username || !$smtp_password) {
        error_log('Email notification skipped: SMTP_USERNAME or SMTP_PASSWORD is not configured.');
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mail->setFrom($smtp_username, 'SHS Library System');
        $mail->addAddress($email);
        $mail->Subject = $action === 'borrowed' ? "Book Borrowed: $book_title" : "Book Returned: $book_title";
        $mail->Body = $action === 'borrowed'
            ? "Dear Student,\n\nYou have successfully borrowed '$book_title'. Please return it by $due_date.\n\nRegards,\nSHS Library System"
            : "Dear Student,\n\nYou have successfully returned '$book_title'.\n\nRegards,\nSHS Library System";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Email notification failed: ' . $e->getMessage());
        return false;
    }
}
