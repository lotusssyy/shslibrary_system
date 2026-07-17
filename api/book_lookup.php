<?php
header('Content-Type: application/json');
include __DIR__ . '/../includes/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$rfid_number = isset($input['rfid_number']) ? trim($input['rfid_number']) : '';
$action = isset($input['action']) ? strtoupper(trim($input['action'])) : '';
$barcode = isset($input['barcode']) ? trim($input['barcode']) : '';

if (empty($rfid_number) || empty($action) || empty($barcode) || !in_array($action, ['BORROW', 'RETURN'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'MISSING_PARAMETERS']);
    exit;
}

try {
    $user_query = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE rfid_number = ?");
    $user_query->execute([$rfid_number]);
    $user = $user_query->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('USER_NOT_FOUND');
    }
    $user_id = $user['id'];

    $book_query = $pdo->prepare("SELECT id, title, author, genre, available FROM books WHERE barcode = ?");
    $book_query->execute([$barcode]);
    $book = $book_query->fetch(PDO::FETCH_ASSOC);
    if (!$book) {
        throw new Exception('BOOK_NOT_FOUND');
    }

    $result = [
        'status' => 'ok',
        'title' => $book['title'],
        'author' => $book['author'],
        'genre' => $book['genre'],
        'available' => (int)$book['available']
    ];

    if ($action === 'BORROW') {
        $overdue_query = $pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM transactions
             WHERE user_id = ? AND action = 'BORROW' AND returned_date IS NULL AND due_date < CURDATE()"
        );
        $overdue_query->execute([$user_id]);
        $overdue_count = (int)$overdue_query->fetch(PDO::FETCH_ASSOC)['cnt'];

        $borrow_query = $pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM transactions
             WHERE user_id = ? AND action = 'BORROW' AND returned_date IS NULL"
        );
        $borrow_query->execute([$user_id]);
        $borrow_count = (int)$borrow_query->fetch(PDO::FETCH_ASSOC)['cnt'];

        $result['has_overdue'] = $overdue_count > 0;
        $result['overdue_count'] = $overdue_count;
        $result['borrow_count'] = $borrow_count;
        $result['borrow_limit'] = BORROW_LIMIT;

        if ($overdue_count > 0) {
            $result['blocked'] = true;
            $result['block_reason'] = 'OVERDUE_BLOCK';
        } elseif ($borrow_count >= BORROW_LIMIT) {
            $result['blocked'] = true;
            $result['block_reason'] = 'BORROW_LIMIT_REACHED';
        } else {
            $result['blocked'] = false;
        }
    } else {
        $return_query = $pdo->prepare(
            "SELECT t.id, t.borrowed_date, t.due_date
             FROM transactions t
             WHERE t.user_id = ? AND t.book_id = ? AND t.action = 'BORROW' AND t.returned_date IS NULL
             ORDER BY t.id DESC LIMIT 1"
        );
        $return_query->execute([$user_id, $book['id']]);
        $borrow_record = $return_query->fetch(PDO::FETCH_ASSOC);

        if ($borrow_record) {
            $result['can_return'] = true;
            $result['borrow_record_id'] = (int)$borrow_record['id'];
            $result['borrowed_date'] = $borrow_record['borrowed_date'];
            $result['due_date'] = $borrow_record['due_date'];

            if (date('Y-m-d') > $borrow_record['due_date']) {
                $days_overdue = (int)floor((strtotime('now') - strtotime($borrow_record['due_date'])) / 86400);
                $result['is_overdue'] = true;
                $result['days_overdue'] = $days_overdue;
                $result['fine_amount'] = round($days_overdue * FINE_PER_DAY, 2);
            } else {
                $result['is_overdue'] = false;
            }
        } else {
            $result['can_return'] = false;
        }
    }

    echo json_encode($result);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
