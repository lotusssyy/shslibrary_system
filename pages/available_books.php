<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// Initialize messages
$success_message = '';
$error_message = '';

// Handle book removal (only for admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_book']) && $user_role === 'admin') {
    $book_number = trim($_POST['book_number']);
    try {
        // Start a transaction to ensure data consistency
        $pdo->beginTransaction();

        // Find the book ID based on book_number
        $query = $pdo->prepare("SELECT id FROM books WHERE book_number = ?");
        $query->execute([$book_number]);
        $book = $query->fetch();
        if (!$book) {
            throw new Exception("Book not found.");
        }
        $book_id = $book['id'];

        // Delete related transactions
        $query = $pdo->prepare("DELETE FROM transactions WHERE book_id = ?");
        $query->execute([$book_id]);

        // Delete the book
        $query = $pdo->prepare("DELETE FROM books WHERE book_number = ?");
        $query->execute([$book_number]);

        // Commit the transaction
        $pdo->commit();

        // Set success message
        $success_message = "Book removed successfully.";
    } catch (Exception $e) {
        // Roll back the transaction on error
        $pdo->rollBack();
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
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        /* Table styling to match borrowed_books.php */
        .styled-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .styled-table th, .styled-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        .styled-table th {
            background-color: #003366;
            color: white;
        }
        .styled-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        /* Button styling to match borrowed_books.php */
        .remove-btn {
            background-color: #003366;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 3px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s ease;
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .remove-btn:hover {
            background-color: #ffd700;
        }
        .remove-btn i {
            margin-right: 0;
        }
        @media (min-width: 768px) {
            .remove-btn {
                font-size: 1rem;
            }
        }

        /* Success message styling */
        .alert-success {
            padding: 10px;
            margin: 15px 0;
            border: 1px solid #28a745;
            border-radius: 4px;
            background-color: #d4edda;
            color: #28a745;
            font-size: 0.9rem;
        }

        /* Search bar styling (unchanged) */
        .search-bar-container {
            position: relative;
            margin-bottom: 20px;
        }
        .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }
        .search-bar {
            width: 100%;
            padding: 10px 10px 10px 35px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
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

            <?php if (!empty($success_message)): ?>
                <div class="alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <section>
                <div class="search-bar-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="search-bar" class="search-bar" placeholder="Search for books..." onkeyup="filterBooks()">
                </div>
                <table id="books-table" class="styled-table">
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