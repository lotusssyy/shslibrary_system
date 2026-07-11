<?php
header('Content-Type: application/json');
include __DIR__ . '/../includes/db.php';
require __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$input = json_decode(file_get_contents('php://input'), true);
$rfid_number = isset($input['rfid_number']) ? trim($input['rfid_number']) : '';
$action = isset($input['action']) ? strtoupper(trim($input['action'])) : '';
$barcode = isset($input['barcode']) ? trim($input['barcode']) : '';

if (empty($rfid_number) || empty($action) || empty($barcode) || !in_array($action, ['BORROW','RETURN'])) {
    http_response_code(400);
    echo json_encode(['status'=>'error','error'=>'MISSING_PARAMETERS']);
    exit;
}

try {
    $pdo->beginTransaction();

    $user_query = $pdo->prepare("SELECT id, email FROM users WHERE rfid_number = ?");
    $user_query->execute([$rfid_number]);
    $user = $user_query->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('USER_NOT_FOUND');
    }
    $user_id = $user['id'];
    $user_email = $user['email'];

    $book_query = $pdo->prepare("SELECT id, genre, title, available, total_quantity FROM books WHERE barcode = ?");
    $book_query->execute([$barcode]);
    $book = $book_query->fetch(PDO::FETCH_ASSOC);
    if (!$book) {
        throw new Exception('BOOK_NOT_FOUND');
    }
    $book_id = $book['id'];
    $book_genre = $book['genre'];
    $book_title = $book['title'];
    $book_available = (int)$book['available'];
    $total_quantity = (int)$book['total_quantity'];

    $due_date = date('Y-m-d', strtotime('+7 days'));
    switch (strtoupper($book_genre)) {
        case 'FICTION': $due_date = date('Y-m-d', strtotime('+14 days')); break;
        case 'NON-FICTION': $due_date = date('Y-m-d', strtotime('+21 days')); break;
        case 'SCIENCE': $due_date = date('Y-m-d', strtotime('+10 days')); break;
        case 'HISTORY': $due_date = date('Y-m-d', strtotime('+14 days')); break;
        case 'BIOGRAPHY': $due_date = date('Y-m-d', strtotime('+7 days')); break;
        case 'NARRATIVE': $due_date = date('Y-m-d', strtotime('+1 day')); break;
    }

    if ($action === 'BORROW') {
        if ($book_available <= 0) throw new Exception('NO_BOOKS_AVAILABLE');
        $update_book = $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?");
        $update_book->execute([$book_id]);
        $trans_query = $pdo->prepare("INSERT INTO transactions (user_id, book_id, action, borrowed_date, due_date) VALUES (?, ?, 'BORROW', NOW(), ?)");
        $trans_query->execute([$user_id, $book_id, $due_date]);
        $pdo->commit();
        notifyStudent($user_id, $user_email, $book_title, 'borrowed', $due_date);
        echo json_encode(['status'=>'ok','result'=>'BORROW_SUCCESS','due_date'=>$due_date]);
        exit;
    } else {
        if ($book_available >= $total_quantity) throw new Exception('BOOK_ALREADY_RETURNED');
        $check_borrow = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND book_id = ? AND action = 'BORROW' AND returned_date IS NULL ORDER BY id DESC LIMIT 1");
        $check_borrow->execute([$user_id, $book_id]);
        $borrow_record = $check_borrow->fetch(PDO::FETCH_ASSOC);
        if (!$borrow_record) throw new Exception('NO_BORROW_RECORD');
        $update_trans = $pdo->prepare("UPDATE transactions SET returned_date = NOW() WHERE id = ?");
        $update_trans->execute([$borrow_record['id']]);
        $update_book = $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?");
        $update_book->execute([$book_id]);
        $pdo->commit();
        notifyStudent($user_id, $user_email, $book_title, 'returned', null);
        echo json_encode(['status'=>'ok','result'=>'RETURN_SUCCESS']);
        exit;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    exit;
}

function notifyStudent($user_id, $email, $book_title, $action, $due_date) {
    global $pdo;
    // Insert notice
    try {
        $message = ($action === 'borrowed') ? "You have borrowed '$book_title'. Due date: $due_date." : "You have returned '$book_title'.";
        $notice_query = $pdo->prepare("INSERT INTO notices (user_id, message, created_at) VALUES (?, ?, NOW())");
        $notice_query->execute([$user_id, $message]);

        // Try to send email (best-effort)
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'libraryuclm@gmail.com';
        $mail->Password = 'crof wdsk aiky vays';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->setFrom('libraryuclm@gmail.com', 'SHS Library System');
        $mail->addAddress($email);
        $mail->Subject = ($action === 'borrowed') ? "Book Borrowed: $book_title" : "Book Returned: $book_title";
        $mail->Body = $message;
        $mail->send();
    } catch (Exception $e) {
        error_log('Email failed: ' . $e->getMessage());
    }
}
