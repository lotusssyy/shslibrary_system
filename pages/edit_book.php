<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'librarian'], true)) {
    header('Location: ../index.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>

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
</body>
</html>