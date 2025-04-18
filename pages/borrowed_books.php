<?php
include '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Fetch user details
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $error_message = "User not found.";
        error_log("User ID $user_id not found in users table.");
    }
    $user_role = $user['role'] ?? 'student';
} catch (PDOException $e) {
    $error_message = "Database error: Unable to fetch user details.";
    error_log("User fetch error: " . $e->getMessage());
}

// Fetch borrowed books with pagination
try {
    $where_clause = $user_role === 'admin' ? '' : 'AND t.user_id = :user_id';
    $count_query = $pdo->prepare("
        SELECT COUNT(*)
        FROM transactions t
        JOIN books b ON t.book_id = b.id
        JOIN users u ON t.user_id = u.id
        WHERE t.action = 'BORROW' AND t.returned_date IS NULL
        $where_clause
    ");
    if ($user_role !== 'admin') {
        $count_query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    }
    $count_query->execute();
    $total_books = $count_query->fetchColumn();
    $total_pages = ceil($total_books / $per_page);

    $query = $pdo->prepare("
        SELECT b.id, b.title, b.author, b.genre,
               t.borrowed_date,
               CASE
                   WHEN b.genre = 'Narrative' THEN DATE_ADD(t.borrowed_date, INTERVAL 1 DAY)
                   ELSE DATE_ADD(t.borrowed_date, INTERVAL 7 DAY)
               END AS due_date,
               u.first_name, u.last_name, u.student_id
        FROM transactions t
        JOIN books b ON t.book_id = b.id
        JOIN users u ON t.user_id = u.id
        WHERE t.action = 'BORROW' AND t.returned_date IS NULL
        $where_clause
        ORDER BY t.borrowed_date DESC
        LIMIT :limit OFFSET :offset
    ");
    if ($user_role !== 'admin') {
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

// Handle return book
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_book'])) {
    $book_id = (int)$_POST['book_id'];
    try {
        $pdo->beginTransaction();

        $check_stmt = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND book_id = ? AND action = 'BORROW' AND returned_date IS NULL");
        $check_stmt->execute([$user_id, $book_id]);
        if (!$check_stmt->fetch()) {
            throw new Exception("This book is not borrowed by you.");
        }

        $stmt = $pdo->prepare("UPDATE transactions SET returned_date = NOW() WHERE user_id = ? AND book_id = ? AND action = 'BORROW' AND returned_date IS NULL");
        $stmt->execute([$user_id, $book_id]);

        $stmt = $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?");
        $stmt->execute([$book_id]);

        $pdo->commit();
        $success_message = "Book returned successfully!";

        // Refresh borrowed books
        try {
            $where_clause = $user_role === 'admin' ? '' : 'AND t.user_id = :user_id';
            $count_query = $pdo->prepare("
                SELECT COUNT(*)
                FROM transactions t
                JOIN books b ON t.book_id = b.id
                JOIN users u ON t.user_id = u.id
                WHERE t.action = 'BORROW' AND t.returned_date IS NULL
                $where_clause
            ");
            if ($user_role !== 'admin') {
                $count_query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            }
            $count_query->execute();
            $total_books = $count_query->fetchColumn();
            $total_pages = ceil($total_books / $per_page);

            $query = $pdo->prepare("
                SELECT b.id, b.title, b.author, b.genre,
                       t.borrowed_date,
                       CASE
                           WHEN b.genre = 'Narrative' THEN DATE_ADD(t.borrowed_date, INTERVAL 1 DAY)
                           ELSE DATE_ADD(t.borrowed_date, INTERVAL 7 DAY)
                       END AS due_date,
                       u.first_name, u.last_name, u.student_id
                FROM transactions t
                JOIN books b ON t.book_id = b.id
                JOIN users u ON t.user_id = u.id
                WHERE t.action = 'BORROW' AND t.returned_date IS NULL
                $where_clause
                ORDER BY t.borrowed_date DESC
                LIMIT :limit OFFSET :offset
            ");
            if ($user_role !== 'admin') {
                $query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            }
            $query->bindValue(':limit', $per_page, PDO::PARAM_INT);
            $query->bindValue(':offset', $offset, PDO::PARAM_INT);
            $query->execute();
            $borrowed_books = $query->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error_message = "Database error: Unable to refresh borrowed books.";
            error_log("Borrowed books refresh error: " . $e->getMessage());
            $borrowed_books = [];
            $total_pages = 1;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error returning book: " . $e->getMessage();
        error_log("Return book error: " . $e->getMessage());
    }
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
                    <p>You have no borrowed books.</p>
                <?php else: ?>
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Genre</th>
                                <?php if ($user_role === 'admin'): ?>
                                    <th>Student ID</th>
                                    <th>Borrower Name</th>
                                <?php endif; ?>
                                <th>Borrowed Date</th>
                                <th>Due Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowed_books as $book): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($book['title']); ?></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo htmlspecialchars($book['genre']); ?></td>
                                    <?php if ($user_role === 'admin'): ?>
                                        <td><?php echo htmlspecialchars($book['student_id'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($book['first_name'] . ' ' . $book['last_name']); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($book['borrowed_date']))); ?></td>
                                    <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($book['due_date']))); ?></td>
                                    <td>
                                        <form method="POST" class="action-form">
                                            <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                            <button type="submit" name="return_book" class="action-btn"><i class="fas fa-undo"></i> Return</button>
                                        </form>
                                    </td>
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

    <script>
        const booksTab = document.getElementById('books-tab');
        const booksMenu = document.getElementById('books-menu');
        booksTab.addEventListener('click', function (e) {
            e.preventDefault();
            booksMenu.style.display = booksMenu.style.display === 'block' ? 'none' : 'block';
        });

        <?php if ($user_role === 'admin'): ?>
        const studentsTab = document.getElementById('students-tab');
        const studentsMenu = document.getElementById('students-menu');
        if (studentsTab) {
            studentsTab.addEventListener('click', function (e) {
                e.preventDefault();
                studentsMenu.style.display = studentsMenu.style.display === 'block' ? 'none' : 'block';
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>