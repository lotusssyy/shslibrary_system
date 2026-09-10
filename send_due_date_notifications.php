<?php
include 'includes/db.php';

// Use Composer autoloader instead of direct requires
require 'vendor/autoload.php'; // Assumes vendor/ is in the root directory
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

error_reporting(E_ALL);

// Set timezone to Philippine Time (PHT, UTC+8)
date_default_timezone_set('Asia/Manila');

$currentDate = date('Y-m-d');
error_log("Running overdue notifications at " . date('Y-m-d H:i:s') . " PHT");

// Helper: check if user was already notified about this book today
function alreadyNotifiedToday(PDO $pdo, int $user_id, string $book_title, string $currentDate): bool {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE user_id = ? AND message LIKE ? AND DATE(created_at) = ?"
    );
    $check->execute([$user_id, "%'$book_title'%", $currentDate]);
    return (int)$check->fetchColumn() > 0;
}

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

    if (alreadyNotifiedToday($pdo, $user_id, $book_title, $currentDate)) {
        error_log("Skipping duplicate due-today notification for $email / '$book_title'");
        continue;
    }

    $message = "Your borrowed book '$book_title' (Genre: $genre) is due today ($due_date). Please return it to avoid penalties.";
    $notice_query = $pdo->prepare("INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 0, NOW())");
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

    if (alreadyNotifiedToday($pdo, $user_id, $book_title, $currentDate)) {
        error_log("Skipping duplicate due-tomorrow notification for $email / '$book_title'");
        continue;
    }

    $message = "Reminder: Your borrowed book '$book_title' (Genre: $genre) is due tomorrow ($due_date). Please plan to return it.";
    $notice_query = $pdo->prepare("INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 0, NOW())");
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

    if (alreadyNotifiedToday($pdo, $user_id, $book_title, $currentDate)) {
        error_log("Skipping duplicate overdue notification for $email / '$book_title'");
        continue;
    }

    $overdue_days = floor((strtotime('now') - strtotime($due_date)) / (60 * 60 * 24));
    $message = "Your borrowed book '$book_title' (Genre: $genre) is overdue since $due_date ($overdue_days day(s) late). Please return it immediately to avoid penalties.";
    $notice_query = $pdo->prepare("INSERT INTO notifications (user_id, message, read_status, created_at) VALUES (?, ?, 0, NOW())");
    $notice_query->execute([$user_id, $message]);
    sendEmailNotification($email, $book_title, $due_date, "overdue", $genre);
    error_log("Notified $email for overdue book '$book_title' (Genre: $genre) ($overdue_days days late)");
}

function sendEmailNotification($email, $book_title, $due_date, $context, $genre) {
    $smtp_username = getenv('SMTP_USERNAME');
    $smtp_password = getenv('SMTP_PASSWORD');
    if (!$smtp_username || !$smtp_password) {
        error_log("Email skipped for $email: SMTP credentials not configured.");
        return;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);

        $mail->setFrom($smtp_username, 'SHS Library System');
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