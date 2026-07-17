<?php
include '../includes/db.php';
include '../includes/notification_service.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_role = $_SESSION['role'] ?? 'student';
$is_staff = in_array($user_role, ['admin', 'librarian'], true);
if (!$is_staff) {
    header('Location: dashboard.php?tab=dashboard');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success_message = '';
$error_message = '';
if (isset($_SESSION['transaction_success_message'])) {
    $success_message = $_SESSION['transaction_success_message'];
    unset($_SESSION['transaction_success_message']);
}
if (isset($_SESSION['transaction_error_message'])) {
    $error_message = $_SESSION['transaction_error_message'];
    unset($_SESSION['transaction_error_message']);
}

function dueDateForGenre(string $genre): string {
    $days = [
        'FICTION' => 14,
        'NON-FICTION' => 21,
        'SCIENCE' => 10,
        'HISTORY' => 14,
        'BIOGRAPHY' => 7,
        'NARRATIVE' => 1,
    ];
    $days_to_add = $days[strtoupper($genre)] ?? 7;
    return date('Y-m-d', strtotime("+$days_to_add days"));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Your form session expired. Please try again.';
    } else {
        $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $book_id = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT);
        $action = strtoupper(trim($_POST['action'] ?? ''));

        try {
            if (!$student_id || !$book_id || !in_array($action, ['BORROW', 'RETURN'], true)) {
                throw new Exception('Select a student, a book, and an action.');
            }

            $pdo->beginTransaction();
            $student_query = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ? AND role = 'student'");
            $student_query->execute([$student_id]);
            $student = $student_query->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                throw new Exception('Selected student was not found.');
            }

            $book_query = $pdo->prepare('SELECT id, title, genre, available, total_quantity FROM books WHERE id = ? FOR UPDATE');
            $book_query->execute([$book_id]);
            $book = $book_query->fetch(PDO::FETCH_ASSOC);
            if (!$book) {
                throw new Exception('Selected book was not found.');
            }

            if ($action === 'BORROW') {
                if ((int) $book['available'] < 1) {
                    throw new Exception('This book is not currently available.');
                }
                $due_date = dueDateForGenre($book['genre']);
                $pdo->prepare('UPDATE books SET available = available - 1 WHERE id = ?')->execute([$book_id]);
                $pdo->prepare("INSERT INTO transactions (user_id, book_id, action, borrowed_date, due_date) VALUES (?, ?, 'BORROW', NOW(), ?)")
                    ->execute([$student_id, $book_id, $due_date]);
                $success_message = htmlspecialchars($student['first_name'] . ' ' . $student['last_name'])
                    . ' borrowed ' . htmlspecialchars($book['title']) . ". Due: $due_date.";
            } else {
                $transaction_query = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND book_id = ? AND action = 'BORROW' AND returned_date IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $transaction_query->execute([$student_id, $book_id]);
                $transaction = $transaction_query->fetch(PDO::FETCH_ASSOC);
                if (!$transaction) {
                    throw new Exception('This student has no active borrow record for the selected book.');
                }
                $pdo->prepare('UPDATE transactions SET returned_date = NOW() WHERE id = ?')->execute([$transaction['id']]);
                $pdo->prepare('UPDATE books SET available = LEAST(available + 1, total_quantity) WHERE id = ?')->execute([$book_id]);
                $success_message = htmlspecialchars($student['first_name'] . ' ' . $student['last_name'])
                    . ' returned ' . htmlspecialchars($book['title']) . '.';
            }

            $pdo->commit();
            try {
                $email_sent = sendTransactionNotification(
                    $pdo,
                    (int) $student['id'],
                    $student['email'],
                    $book['title'],
                    $action === 'BORROW' ? 'borrowed' : 'returned',
                    $action === 'BORROW' ? $due_date : null
                );
                $success_message .= $email_sent ? ' Email notification sent.' : ' In-app notice created; email was not sent.';
            } catch (PDOException $notification_error) {
                error_log('Transaction notification failed: ' . $notification_error->getMessage());
                $success_message .= ' Notification was not sent.';
            }
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['transaction_success_message'] = $success_message;
            header('Location: test_transactions.php');
            exit;
        } catch (Exception $e) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (PDOException $rollback_error) {
                error_log('Transaction rollback failed: ' . $rollback_error->getMessage());
            }
            $_SESSION['transaction_error_message'] = $e->getMessage();
            header('Location: test_transactions.php');
            exit;
        }
    }
}

try {
    $students = $pdo->query("SELECT id, first_name, last_name, student_id FROM users WHERE role = 'student' ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
    $books = $pdo->query('SELECT id, title, author, available, total_quantity FROM books ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $students = [];
    $books = [];
    $error_message = $error_message ?: 'Database connection was lost. Please refresh the page and try again.';
    error_log('Unable to load transaction form options: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Borrow/Return - SHS Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        <div class="main-content">
            <header>
                <h1>Test Borrow/Return</h1>
                <p>Hardware-free transaction simulator for testing the library workflow.</p>
            </header>
            <?php if ($success_message): ?><div class="alert-success"><?php echo $success_message; ?></div><?php endif; ?>
            <?php if ($error_message): ?><div class="alert-error"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
            <section class="admin-section">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="form-group">
                        <label for="student_id">Student</label>
                        <select id="student_id" name="student_id" required>
                            <option value="">Select a student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo (int) $student['id']; ?>"><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' (' . ($student['student_id'] ?: 'No ID') . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="book_id">Book</label>
                        <select id="book_id" name="book_id" required>
                            <option value="">Select a book</option>
                            <?php foreach ($books as $book): ?>
                                <option value="<?php echo (int) $book['id']; ?>"><?php echo htmlspecialchars($book['title'] . ' — ' . $book['author'] . ' (Available: ' . $book['available'] . '/' . $book['total_quantity'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="action">Action</label>
                        <select id="action" name="action" required>
                            <option value="BORROW">Borrow selected book</option>
                            <option value="RETURN">Return selected book</option>
                        </select>
                    </div>
                    <button type="submit"><i class="fas fa-flask"></i> Run Test Transaction</button>
                </form>
            </section>
        </div>
    </div>
</body>
</html>
