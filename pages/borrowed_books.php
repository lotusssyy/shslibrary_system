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
$stmt = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$user_role = $user['role'] ?? 'student';

// Fetch borrowed books with pagination
$count_query = $pdo->prepare("SELECT COUNT(*) FROM transactions t JOIN books b ON t.book_id = b.id WHERE t.user_id = ? AND t.action = 'BORROW' AND t.returned_date IS NULL");
$count_query->execute([$user_id]);
$total_books = $count_query->fetchColumn();
$total_pages = ceil($total_books / $per_page);

$stmt = $pdo->prepare("
    SELECT b.id, b.title, b.author, b.genre, t.borrowed_date
    FROM transactions t
    JOIN books b ON t.book_id = b.id
    WHERE t.user_id = ? AND t.action = 'BORROW' AND t.returned_date IS NULL
    ORDER BY t.borrowed_date DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$user_id, $per_page, $offset]);
$borrowed_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error returning book: " . $e->getMessage();
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
                <a href="available_books.php"><i class="fas fa-book-open"></i> <span>Available Books</span></a>
                <a href="borrowed_books.php" class="active"><i class="fas fa-book-reader"></i> <span>Borrowed Books</span></a>
                <?php if ($user_role === 'admin'): ?>
                    <a href="dashboard.php?tab=add_book"><i class="fas fa-plus"></i> <span>Add Book</span></a>
                    <a href="dashboard.php?tab=add_student"><i class="fas fa-user-plus"></i> <span>Add Student</span></a>
                    <a href="dashboard.php?tab=students"><i class="fas fa-users"></i> <span>Registered Students</span></a>
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
                <p>Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</p>
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
                                <th>Borrowed Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowed_books as $book): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($book['title']); ?></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo htmlspecialchars($book['genre']); ?></td>
                                    <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($book['borrowed_date']))); ?></td>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                            <button type="submit" name="return_book" class="action-btn">Return</button>
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
</body>
</html>