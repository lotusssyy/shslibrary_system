<?php
include '../includes/db.php';
require_once __DIR__ . '/../includes/notification_service.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$user = ['first_name' => '', 'last_name' => '', 'role' => 'student'];
$user_role = 'student';
$is_staff = false;
$success_message = '';
$error_message = '';
if (isset($_SESSION['borrowed_books_success_message'])) {
    $success_message = $_SESSION['borrowed_books_success_message'];
    unset($_SESSION['borrowed_books_success_message']);
}
if (isset($_SESSION['borrowed_books_error_message'])) {
    $error_message = $_SESSION['borrowed_books_error_message'];
    unset($_SESSION['borrowed_books_error_message']);
}
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Fetch user details
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $fetched_user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fetched_user) {
        $error_message = "User not found.";
        error_log("User ID $user_id not found in users table.");
    } else {
        $user = $fetched_user;
        $user_role = $user['role'] ?? 'student';
        $is_staff = in_array($user_role, ['admin', 'librarian'], true);
    }
} catch (PDOException $e) {
    $error_message = "Database error: Unable to fetch user details.";
    error_log("User fetch error: " . $e->getMessage());
}

// Handle staff return action from this page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_book'])) {
    if (!$is_staff) {
        $_SESSION['borrowed_books_error_message'] = "You do not have permission to return books from this page.";
        header('Location: borrowed_books.php');
        exit;
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['borrowed_books_error_message'] = "Your form session expired. Please try again.";
        header('Location: borrowed_books.php');
        exit;
    }

    $transaction_id = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);
    try {
        if (!$transaction_id) {
            throw new Exception("Invalid borrowed book record.");
        }

        $pdo->beginTransaction();
        $transaction_query = $pdo->prepare("
            SELECT t.id, t.book_id, t.user_id, b.title
            FROM transactions t
            JOIN books b ON t.book_id = b.id
            WHERE t.id = ? AND t.action = 'BORROW' AND t.returned_date IS NULL
            FOR UPDATE
        ");
        $transaction_query->execute([$transaction_id]);
        $transaction = $transaction_query->fetch(PDO::FETCH_ASSOC);
        if (!$transaction) {
            throw new Exception("This borrowed book is already returned or no longer exists.");
        }

        $pdo->prepare("UPDATE transactions SET returned_date = NOW() WHERE id = ?")->execute([$transaction_id]);
        $pdo->prepare("UPDATE books SET available = LEAST(available + 1, total_quantity) WHERE id = ?")->execute([$transaction['book_id']]);
        $pdo->commit();

        // Send return notification to student
        $user_query = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $user_query->execute([$transaction['user_id']]);
        $student = $user_query->fetch(PDO::FETCH_ASSOC);
        if ($student) {
            sendTransactionNotification($pdo, $transaction['user_id'], $student['email'], $transaction['title'], 'returned', null);
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['borrowed_books_success_message'] = $transaction['title'] . " marked as returned.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['borrowed_books_error_message'] = "Error returning book: " . $e->getMessage();
        error_log($_SESSION['borrowed_books_error_message']);
    }

    header('Location: borrowed_books.php');
    exit;
}

// Fetch borrowed books with pagination
try {
    $where_clause = $is_staff ? '' : 'AND t.user_id = :user_id';
    $count_query = $pdo->prepare("
        SELECT COUNT(*)
        FROM transactions t
        JOIN books b ON t.book_id = b.id
        JOIN users u ON t.user_id = u.id
        WHERE t.action = 'BORROW' AND t.returned_date IS NULL
        $where_clause
    ");
    if (!$is_staff) {
        $count_query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    }
    $count_query->execute();
    $total_books = $count_query->fetchColumn();
    $total_pages = ceil($total_books / $per_page);

    $query = $pdo->prepare("
        SELECT t.id AS transaction_id,
               b.id, b.title, b.author, b.genre,
               b.available, b.total_quantity,
               t.borrowed_date,
               t.due_date AS due_date,
               DATEDIFF(t.due_date, NOW()) AS days_left,
               u.first_name, u.last_name, u.student_id
        FROM transactions t
        JOIN books b ON t.book_id = b.id
        JOIN users u ON t.user_id = u.id
        WHERE t.action = 'BORROW' AND t.returned_date IS NULL
        $where_clause
        ORDER BY t.borrowed_date DESC
        LIMIT :limit OFFSET :offset
    ");
    if (!$is_staff) {
        $query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    }
    $query->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);
    $query->execute();
    $borrowed_books = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Database error: Unable to fetch borrowed books.";
    error_log("Borrowed books fetch error: " . $e->getMessage());
    $borrowed_books = [];
    $total_pages = 1;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrowed Books - SHS Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <?php include '../includes/sidebar.php'; ?>
        <?php /*
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>
                    <img src="../images/logo.png" alt="School Logo" class="school-logo">
                    SHS Library
                </h2>
            </div>
            <nav>
                <a href="dashboard.php?tab=dashboard"><i class="fas fa-home"></i> <span>Dashboard</span></a>
                <a href="#" id="books-tab" class="active"><i class="fas fa-book"></i> <span>Books</span></a>
                <ul class="sub-menu" id="books-menu" style="display: block;">
                    <li><a href="available_books.php"><i class="fas fa-book-open"></i> <span>Available Books</span></a></li>
                    <li><a href="borrowed_books.php" class="active"><i class="fas fa-book-reader"></i> <span>Borrowed Books</span></a></li>
                    <?php if ($user_role === 'admin'): ?>
                        <li><a href="dashboard.php?tab=add_book"><i class="fas fa-plus"></i> <span>Add Book</span></a></li>
                    <?php endif; ?>
                </ul>
                <?php if ($user_role === 'admin'): ?>
                    <a href="#" id="students-tab"><i class="fas fa-users"></i> <span>Students</span></a>
                    <ul class="sub-menu" id="students-menu" style="display: none;">
                        <li><a href="dashboard.php?tab=add_student"><i class="fas fa-user-plus"></i> <span>Add Student</span></a></li>
                        <li><a href="dashboard.php?tab=students"><i class="fas fa-list"></i> <span>Registered Students</span></a></li>
                    </ul>
                    <a href="dashboard.php?tab=transactions"><i class="fas fa-exchange-alt"></i> <span>Transactions</span></a>
                    <a href="dashboard.php?tab=inventory"><i class="fas fa-boxes"></i> <span>Inventory</span></a>
                <?php endif; ?>
                <a href="notices.php"><i class="fas fa-bell"></i> <span>Notices</span></a>
                <a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a>
                <div class="logout">
                    <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
                </div>
            </nav>
        </div>
        */ ?>

        <!-- Main Content -->
        <div class="main-content">
            <header>
                <h1>Borrowed Books</h1>
                <p>Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] ?? 'User'); ?>!</p>
            </header>

            <?php if ($success_message): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <section class="borrowed-books">
                <?php if (empty($borrowed_books)): ?>
                    <p><?php echo $is_staff ? 'No borrowed books found.' : 'You have no borrowed books.'; ?></p>
                <?php else: ?>
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Genre</th>
                                <?php if ($is_staff): ?>
                                    <th>Student ID</th>
                                    <th>Borrower Name</th>
                                <?php endif; ?>
                                <th>Borrowed</th>
                                <th>Due Date</th>
                                <th>Days Left</th>
                                <?php if ($is_staff): ?>
                                    <th>Copies</th>
                                    <th>Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowed_books as $book): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo htmlspecialchars($book['genre']); ?></td>
                                    <?php if ($is_staff): ?>
                                        <td><?php echo htmlspecialchars($book['student_id'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($book['first_name'] . ' ' . $book['last_name']); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo date('M d, Y', strtotime($book['borrowed_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($book['due_date'])); ?></td>
                                    <td>
                                        <?php
                                        $days = (int)$book['days_left'];
                                        if ($days < 0) {
                                            echo '<span style="color:var(--danger);font-weight:700;">' . abs($days) . 'd overdue</span>';
                                        } elseif ($days <= 3) {
                                            echo '<span style="color:#d97706;font-weight:600;">' . $days . ' day' . ($days !== 1 ? 's' : '') . ' left</span>';
                                        } else {
                                            echo '<span style="color:var(--success);font-weight:600;">' . $days . ' days left</span>';
                                        }
                                        ?>
                                    </td>
                                    <?php if ($is_staff): ?>
                                        <td>
                                            <span style="font-weight:600;color:<?php echo $book['available'] > 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                                <?php echo $book['available']; ?>
                                            </span> / <?php echo $book['total_quantity']; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="action-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="transaction_id" value="<?php echo (int) $book['transaction_id']; ?>">
                                                <button type="submit" name="return_book" class="action-btn"><i class="fas fa-undo"></i> Return</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>">Previous</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>">Next</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>
</body>
</html>
