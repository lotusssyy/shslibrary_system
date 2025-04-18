<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
$success_message = '';
$error_message = '';

// Fetch book details
$query = $pdo->prepare("SELECT * FROM books WHERE id = ?");
$query->execute([$book_id]);
$book = $query->fetch();
if (!$book) {
    $error_message = "Book not found.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_book'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_SESSION['csrf_token'])) {
        $error_message = "CSRF token validation failed.";
    } else {
        $title = trim($_POST['title']);
        $author = trim($_POST['author']);
        $genre = trim($_POST['genre']);
        $barcode = trim($_POST['barcode']);
        $book_number = trim($_POST['book_number']);

        // Validate barcode and book number uniqueness (excluding current book)
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM books WHERE (barcode = ? OR book_number = ?) AND id != (SELECT id FROM books WHERE id = ?)");
        $check_stmt->execute([$barcode, $book_number, $book_id]);
        if ($check_stmt->fetchColumn() > 0) {
            $error_message = "Barcode or Book Number already exists.";
        } else {
            try {
                $query = $pdo->prepare("
                    UPDATE books
                    SET title = ?, author = ?, genre = ?, barcode = ?, book_number = ?
                    WHERE id = ?
                ");
                $query->execute([$title, $author, $genre, $barcode, $book_number, $book_id]);
                $success_message = "Book updated successfully.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                error_log("Admin updated book ID $book_id on " . date('Y-m-d H:i:s'));
            } catch (PDOException $e) {
                $error_message = "Error updating book: " . $e->getMessage();
                error_log($error_message);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Book - SHS Library</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        .cancel-btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-left: 10px;
            transition: background-color 0.3s ease;
        }
        .cancel-btn:hover {
            background-color: #5a6268;
        }
    </style>
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
                    <li><a href="borrowed_books.php"><i class="fas fa-book-reader"></i> <span>Borrowed Books</span></a></li>
                    <li><a href="dashboard.php?tab=add_book"><i class="fas fa-plus"></i> <span>Add Book</span></a></li>
                </ul>
                <a href="#" id="students-tab"><i class="fas fa-users"></i> <span>Students</span></a>
                <ul class="sub-menu" id="students-menu" style="display: none;">
                    <li><a href="dashboard.php?tab=add_student"><i class="fas fa-user-plus"></i> <span>Add Student</span></a></li>
                    <li><a href="dashboard.php?tab=students"><i class="fas fa-list"></i> <span>Registered Students</span></a></li>
                </ul>
                <a href="dashboard.php?tab=transactions" id="transactions-tab"><i class="fas fa-exchange-alt"></i> <span>Transactions</span></a>
                <a href="dashboard.php?tab=inventory" id="inventory-tab"><i class="fas fa-boxes"></i> <span>Inventory</span></a>
                <a href="notices.php"><i class="fas fa-bell"></i> <span>Notices</span></a>
                <a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a>
            </nav>
            <div class="logout">
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <header>
                <h1>Edit Book</h1>
                <p>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>!</p>
            </header>
            <?php if ($error_message): ?>
                <div class="alert-error"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if ($book): ?>
                <section class="admin-section">
                    <h2>Edit Book Details</h2>
                    <form method="POST" id="edit-book-form">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="form-group">
                            <label>Title:</label>
                            <input type="text" name="title" value="<?= htmlspecialchars($book['title']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Author:</label>
                            <input type="text" name="author" value="<?= htmlspecialchars($book['author']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Genre:</label>
                            <select name="genre" required>
                                <option value="Fiction" <?= $book['genre'] === 'Fiction' ? 'selected' : '' ?>>Fiction</option>
                                <option value="Non-Fiction" <?= $book['genre'] === 'Non-Fiction' ? 'selected' : '' ?>>Non-Fiction</option>
                                <option value="Science" <?= $book['genre'] === 'Science' ? 'selected' : '' ?>>Science</option>
                                <option value="History" <?= $book['genre'] === 'History' ? 'selected' : '' ?>>History</option>
                                <option value="Biography" <?= $book['genre'] === 'Biography' ? 'selected' : '' ?>>Biography</option>
                                <option value="Narrative" <?= $book['genre'] === 'Narrative' ? 'selected' : '' ?>>Narrative</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Barcode:</label>
                            <input type="text" name="barcode" value="<?= htmlspecialchars($book['barcode']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Book Number:</label>
                            <input type="text" name="book_number" value="<?= htmlspecialchars($book['book_number']) ?>" required>
                        </div>
                        <button type="submit" name="edit_book">Save Changes</button>
                        <a href="dashboard.php?tab=inventory" class="cancel-btn">Cancel</a>
                    </form>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const booksTab = document.getElementById('books-tab');
        const booksMenu = document.getElementById('books-menu');
        booksTab.addEventListener('click', function (e) {
            e.preventDefault();
            booksMenu.style.display = booksMenu.style.display === 'block' ? 'none' : 'block';
        });

        const studentsTab = document.getElementById('students-tab');
        const studentsMenu = document.getElementById('students-menu');
        studentsTab.addEventListener('click', function (e) {
            e.preventDefault();
            studentsMenu.style.display = studentsMenu.style.display === 'block' ? 'none' : 'block';
        });
    </script>
</body>
</html>