<?php
include 'includes/db.php';
require 'includes/PHPMailer/src/PHPMailer.php';
require 'includes/PHPMailer/src/SMTP.php';
require 'includes/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

error_reporting(E_ALL);
ini_set('display_errors', 1); // Enable for debugging

// Set timezone to Philippine Time (PHT, UTC+8)
date_default_timezone_set('Asia/Manila');

// Get current time in PHT
$currentHour = (int) date('H'); // Hour in 24-hour format (0-23)
$currentMinute = (int) date('i'); // Minutes (0-59)
$currentDate = date('Y-m-d');
error_log("Current PHT: " . date('Y-m-d H:i:s'));

// Define target notification times in PHT (24-hour format)
$targetTimes = [6, 17, 0]; // 6:00 AM, 5:00 PM, 12:00 AM

// Check if current time matches one of the target times (within a 10-minute window)
$isTargetTime = false;
foreach ($targetTimes as $targetHour) {
    if ($currentHour === $targetHour && $currentMinute <= 10) { // Run within first 10 minutes of the hour
        $isTargetTime = true;
        break;
    }
    // Special case for 12:00 AM (midnight transition)
    if ($targetHour === 0 && $currentHour === 23 && $currentMinute >= 50) {
        $isTargetTime = true; // Allow late execution for midnight
    }
}

if (!$isTargetTime) {
    error_log("Not a target notification time. Exiting.");
    exit; // Exit if not one of the target times
}

error_log("Sending notifications at " . date('Y-m-d H:i:s') . " PHT");

// Query for books due today
$query_today = $pdo->prepare("SELECT t.user_id, u.email, b.title, t.due_date, b.genre 
                             FROM transactions t 
                             JOIN users u ON t.user_id = u.id 
                             JOIN books b ON t.book_id = b.id 
                             WHERE t.action = 'BORROW' AND t.due_date = DATE(NOW()) 
                             AND t.returned_date IS NULL");
$query_today->execute();
$due_today = $query_today->fetchAll(PDO::FETCH_ASSOC);

// Query for books due tomorrow (one day before reminder)
$query_tomorrow = $pdo->prepare("SELECT t.user_id, u.email, b.title, t.due_date, b.genre 
                                FROM transactions t 
                                JOIN users u ON t.user_id = u.id 
                                JOIN books b ON t.book_id = b.id 
                                WHERE t.action = 'BORROW' AND t.due_date = DATE(NOW() + INTERVAL 1 DAY) 
                                AND t.returned_date IS NULL");
$query_tomorrow->execute();
$due_tomorrow = $query_tomorrow->fetchAll(PDO::FETCH_ASSOC);

// Query for overdue books
$query_overdue = $pdo->prepare("SELECT t.user_id, u.email, b.title, t.due_date, b.genre 
                               FROM transactions t 
                               JOIN users u ON t.user_id = u.id 
                               JOIN books b ON t.book_id = b.id 
                               WHERE t.action = 'BORROW' AND t.due_date < DATE(NOW()) 
                               AND t.returned_date IS NULL");
$query_overdue->execute();
$overdue_books = $query_overdue->fetchAll(PDO::FETCH_ASSOC);

// Debug: Log the number of records found
error_log("Due today: " . count($due_today) . ", Due tomorrow: " . count($due_tomorrow) . ", Overdue: " . count($overdue_books));

// Process notifications for today
foreach ($due_today as $transaction) {
    $user_id = $transaction['user_id'];
    $email = $transaction['email'];
    $book_title = $transaction['title'];
    $due_date = $transaction['due_date'];
    $genre = $transaction['genre'];

    $message = "Your borrowed book '$book_title' (Genre: $genre) is due today ($due_date). Please return it to avoid penalties.";
    $notice_query = $pdo->prepare("INSERT INTO notices (user_id, message, created_at) VALUES (?, ?, NOW())");
    $notice_query->execute([$user_id, $message]);
    sendEmailNotification($email, $book_title, $due_date, "due today", $genre);
    error_log("Notified $email for book '$book_title' (Genre: $genre) due today");
}

// Process reminders for tomorrow
foreach ($due_tomorrow as $transaction) {
    $user_id = $transaction['user_id'];
    $email = $transaction['email'];
    $book_title = $transaction['title'];
    $due_date = $transaction['due_date'];
    $genre = $transaction['genre'];

    $message = "Reminder: Your borrowed book '$book_title' (Genre: $genre) is due tomorrow ($due_date). Please plan to return it.";
    $notice_query = $pdo->prepare("INSERT INTO notices (user_id, message, created_at) VALUES (?, ?, NOW())");
    $notice_query->execute([$user_id, $message]);
    sendEmailNotification($email, $book_title, $due_date, "due tomorrow", $genre);
    error_log("Reminded $email for book '$book_title' (Genre: $genre) due tomorrow");
}

// Process notifications for overdue books
foreach ($overdue_books as $transaction) {
    $user_id = $transaction['user_id'];
    $email = $transaction['email'];
    $book_title = $transaction['title'];
    $due_date = $transaction['due_date'];
    $genre = $transaction['genre'];

    $overdue_days = floor((strtotime('now') - strtotime($due_date)) / (60 * 60 * 24));
    $message = "Your borrowed book '$book_title' (Genre: $genre) is overdue since $due_date ($overdue_days day(s) late). Please return it immediately to avoid penalties.";
    $notice_query = $pdo->prepare("INSERT INTO notices (user_id, message, created_at) VALUES (?, ?, NOW())");
    $notice_query->execute([$user_id, $message]);
    sendEmailNotification($email, $book_title, $due_date, "overdue", $genre);
    error_log("Notified $email for overdue book '$book_title' (Genre: $genre) ($overdue_days days late)");
}

function sendEmailNotification($email, $book_title, $due_date, $context, $genre) {
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
            : ($context === "due tomorrow" 
                ? "Reminder: Book Due Tomorrow: $book_title"
                : "Overdue Book: $book_title");
        $body = ($context === "due today") 
            ? "Dear Student,\n\nYour borrowed book '$book_title' (Genre: $genre) is due today ($due_date). Please return it to avoid penalties.\n\nRegards,\nSHS Library System"
            : ($context === "due tomorrow" 
                ? "Dear Student,\n\nThis is a reminder that your borrowed book '$book_title' (Genre: $genre) is due tomorrow ($due_date). Please plan to return it.\n\nRegards,\nSHS Library System"
                : "Dear Student,\n\nYour borrowed book '$book_title' (Genre: $genre) is overdue since $due_date. Please return it immediately to avoid penalties.\n\nRegards,\nSHS Library System");
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        error_log("{$context} email sent to $email for '$book_title' (Genre: $genre)");
    } catch (Exception $e) {
        error_log("{$context} email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}
?>