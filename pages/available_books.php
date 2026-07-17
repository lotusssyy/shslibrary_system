<?php
include '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$genre_filter = isset($_GET['genre']) ? trim($_GET['genre']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Fetch distinct genres for filter
$genres_stmt = $pdo->query("SELECT DISTINCT genre FROM books WHERE available > 0 ORDER BY genre");
$available_genres = $genres_stmt->fetchAll(PDO::FETCH_COLUMN);

// Loan period mapping
$loan_periods = [
    'Fiction' => 14, 'Non-Fiction' => 21, 'Science' => 10,
    'History' => 14, 'Biography' => 7, 'Narrative' => 1,
];

// Fetch user details
$stmt = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ../index.php');
    exit;
}
$user_role = $user['role'];
$is_staff = in_array($user_role, ['admin', 'librarian'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_book'])) {
    if (!$is_staff) {
        $error_message = 'You do not have permission to remove books.';
    } elseif (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Your form session expired. Please try again.';
    } else {
        $book_id = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT);
        try {
            if (!$book_id) {
                throw new Exception('Invalid book selected.');
            }

            $pdo->beginTransaction();
            $book_query = $pdo->prepare('SELECT id FROM books WHERE id = ?');
            $book_query->execute([$book_id]);
            if (!$book_query->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Book not found.');
            }

            $borrow_query = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE book_id = ? AND action = 'BORROW' AND returned_date IS NULL");
            $borrow_query->execute([$book_id]);
            if ((int) $borrow_query->fetchColumn() > 0) {
                throw new Exception('Cannot remove this book while it is borrowed.');
            }

            $pdo->prepare('DELETE FROM transactions WHERE book_id = ?')->execute([$book_id]);
            $pdo->prepare('DELETE FROM books WHERE id = ?')->execute([$book_id]);
            $pdo->commit();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $success_message = 'Book removed successfully.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = 'Unable to remove book: ' . $e->getMessage();
        }
    }
}

// Fetch books with pagination
$where_parts = ["available > 0"];
$params = [];
if ($search) {
    $where_parts[] = "(title LIKE :search OR author LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($genre_filter) {
    $where_parts[] = "genre = :genre";
    $params[':genre'] = $genre_filter;
}
$where_clause = "WHERE " . implode(" AND ", $where_parts);

$count_query = $pdo->prepare("SELECT COUNT(*) FROM books $where_clause");
foreach ($params as $k => $v) {
    $count_query->bindValue($k, $v, PDO::PARAM_STR);
}
$count_query->execute();
$total_books = $count_query->fetchColumn();
$total_pages = ceil($total_books / $per_page);

$stmt = $pdo->prepare("SELECT id, title, author, genre, barcode, book_number, available, total_quantity FROM books $where_clause ORDER BY title LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Books - SHS Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <?php include '../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <header>
                <h1>Available Books</h1>
                <p>Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</p>
            </header>

            <?php if ($success_message): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="search-bar-container" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <div style="flex:1;min-width:200px;position:relative;">
                    <i class="fas fa-search search-icon"></i>
                    <form method="GET" style="width:100%;">
                        <input type="text" name="search" class="search-bar" placeholder="Search by title or author..." value="<?php echo htmlspecialchars($search); ?>">
                        <?php if ($genre_filter): ?><input type="hidden" name="genre" value="<?php echo htmlspecialchars($genre_filter); ?>"><?php endif; ?>
                    </form>
                </div>
                <form method="GET">
                    <?php if ($search): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>"><?php endif; ?>
                    <select name="genre" onchange="this.form.submit()" style="padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:0.9rem;font-family:inherit;background:white;cursor:pointer;">
                        <option value="">All Genres</option>
                        <?php foreach ($available_genres as $g): ?>
                            <option value="<?php echo htmlspecialchars($g); ?>" <?php echo $genre_filter === $g ? 'selected' : ''; ?>><?php echo htmlspecialchars($g); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if (!in_array($user_role, ['admin', 'librarian'], true)): ?>
                <p class="info-message">To borrow a book, please use the RFID and barcode scanner at the library counter.</p>
            <?php endif; ?>

            <?php if (empty($books)): ?>
                <p>No books available<?php echo $search ? " for '$search'" : ''; ?>.</p>
            <?php else: ?>
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Genre</th>
                            <th>Copies</th>
                            <th>Loan Period</th>
                            <?php if ($is_staff): ?><th>Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($books as $book): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($book['author']); ?></td>
                                <td><span class="genre-badge"><?php echo htmlspecialchars($book['genre']); ?></span></td>
                                <td>
                                    <span style="font-weight:600;color:<?php echo $book['available'] > 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                        <?php echo $book['available']; ?>
                                    </span> / <?php echo $book['total_quantity']; ?>
                                </td>
                                <td>
                                    <?php
                                    $days = $loan_periods[$book['genre']] ?? 7;
                                    echo $days . ' day' . ($days !== 1 ? 's' : '');
                                    ?>
                                </td>
                                <?php if ($is_staff): ?>
                                    <td>
                                        <form method="POST" class="action-form">
                                            <input type="hidden" name="book_id" value="<?php echo (int) $book['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <button type="submit" name="remove_book" class="remove-btn" onclick="return confirm('Remove this book? This cannot be undone.');">
                                                <i class="fas fa-trash"></i> Remove
                                            </button>
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
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&genre=<?php echo urlencode($genre_filter); ?>">Previous</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&genre=<?php echo urlencode($genre_filter); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&genre=<?php echo urlencode($genre_filter); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
