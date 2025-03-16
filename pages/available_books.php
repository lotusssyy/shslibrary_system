<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// Handle book removal (only for admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_book']) && $user_role === 'admin') {
    $book_number = trim($_POST['book_number']);
    try {
        $query = $pdo->prepare("DELETE FROM books WHERE book_number = ?");
        $query->execute([$book_number]);
        header('Location: available_books.php');
        exit;
    } catch (PDOException $e) {
        $error_message = "Error removing book: " . $e->getMessage();
        error_log($error_message);
    }
}

// Fetch available books
$query = $pdo->query("SELECT * FROM books WHERE available = 1");
$available_books = $query->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Books - SHS Library</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css"> <!-- Ensure this path is correct -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        /* Fallback in case admin-dashboard.css doesn't load */
        .remove-btn {
            background-color: #003366; /* Blue to match dashboard.php */
            color: white; /* White text */
            border: none; /* Remove default border */
            padding: 6px 12px; /* Increased for larger size */
            border-radius: 3px; /* Rounded corners */
            cursor: pointer; /* Hand cursor on hover */
            display: inline-flex; /* Use inline-flex to keep it centered */
            align-items: center; /* Center items vertically */
            gap: 6px; /* Increased gap for larger size */
            transition: background-color 0.3s ease; /* Smooth hover effect */
            font-size: 0.9rem; /* Match table font size */
            vertical-align: middle; /* Ensure vertical centering */
        }

        .remove-btn:hover {
            background-color: #ffd700; /* Yellow hover to match admin button hover */
        }

        .remove-btn i {
            margin-right: 0; /* Remove margin for tighter spacing with adjusted padding */
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
                    <li><a href="available_books.php" class="active"><i class="fas fa-book-open"></i> <span>Available Books</span></a></li>
                    <li><a href="borrowed_books.php"><i class="fas fa-book-reader"></i> <span>Borrowed Books</span></a></li>
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
                <?php endif; ?>
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
                <h1>Available Books</h1>
            </header>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <section>
                <div class="search-bar-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="search-bar" class="search-bar" placeholder="Search for books..." onkeyup="filterBooks()">
                </div>
                <table id="books-table" class="student-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Genre</th>
                            <th>Book Number</th>
                            <?php if ($user_role === 'admin'): ?>
                                <th>Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($available_books)): ?>
                            <tr>
                                <td colspan="<?php echo $user_role === 'admin' ? 5 : 4; ?>">No available books found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($available_books as $book): ?>
                                <tr>
                                    <td><?= htmlspecialchars($book['title']) ?></td>
                                    <td><?= htmlspecialchars($book['author']) ?></td>
                                    <td><?= htmlspecialchars($book['genre']) ?></td>
                                    <td><?= htmlspecialchars($book['book_number']) ?></td>
                                    <?php if ($user_role === 'admin'): ?>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="book_number" value="<?= htmlspecialchars($book['book_number']) ?>">
                                                <button type="submit" name="remove_book" class="remove-btn"><i class="fas fa-trash"></i> Remove</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
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
        studentsTab.addEventListener('click', function (e) {
            e.preventDefault();
            studentsMenu.style.display = studentsMenu.style.display === 'block' ? 'none' : 'block';
        });
        <?php endif; ?>

        function filterBooks() {
            const searchInput = document.getElementById('search-bar').value.toLowerCase();
            const tableRows = document.querySelectorAll('#books-table tbody tr');
            tableRows.forEach(row => {
                const rowText = row.textContent.toLowerCase();
                row.style.display = rowText.includes(searchInput) ? '' : 'none';
            });
        }
    </script>
</body>
</html>