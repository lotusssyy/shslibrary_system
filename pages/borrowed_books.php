<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';

// Fetch borrowed books based on user role
if ($user_role === 'admin') {
    // For admins: Show all borrowed books across all users
    $query = $pdo->prepare("
        SELECT b.title, b.author, t.due_date, u.student_id, u.first_name, u.last_name 
        FROM transactions t 
        JOIN books b ON t.book_id = b.id 
        JOIN users u ON t.user_id = u.id 
        WHERE t.action = 'BORROW' 
        AND NOT EXISTS (
            SELECT 1 
            FROM transactions t2 
            WHERE t2.book_id = t.book_id 
            AND t2.user_id = t.user_id 
            AND t2.action = 'RETURN' 
            AND t2.id > t.id
        )
        ORDER BY t.due_date ASC
    ");
    $query->execute();
} else {
    // For students: Show only their own borrowed books
    $query = $pdo->prepare("
        SELECT b.title, b.author, t.due_date, u.first_name, u.last_name 
        FROM transactions t 
        JOIN books b ON t.book_id = b.id 
        JOIN users u ON t.user_id = u.id 
        WHERE t.action = 'BORROW' 
        AND t.user_id = ? 
        AND NOT EXISTS (
            SELECT 1 
            FROM transactions t2 
            WHERE t2.book_id = t.book_id 
            AND t2.user_id = t.user_id 
            AND t2.action = 'RETURN' 
            AND t2.id > t.id
        )
        ORDER BY t.due_date ASC
    ");
    $query->execute([$user_id]);
}
$borrowed_books = $query->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrowed Books - SHS Library</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
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
                    <a href="#" id="transactions-tab"><i class="fas fa-exchange-alt"></i> <span>Transactions</span></a>
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
                <h1>Borrowed Books</h1>
            </header>
            <section>
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Author</th>
                            <?php if ($user_role === 'admin'): ?>
                                <th>Student ID</th>
                                <th>Borrower Name</th>
                            <?php endif; ?>
                            <th>Due Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($borrowed_books)): ?>
                            <tr>
                                <td colspan="<?php echo $user_role === 'admin' ? '5' : '3'; ?>">No borrowed books found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($borrowed_books as $book): ?>
                                <tr>
                                    <td><?= htmlspecialchars($book['title']) ?></td>
                                    <td><?= htmlspecialchars($book['author']) ?></td>
                                    <?php if ($user_role === 'admin'): ?>
                                        <td><?= htmlspecialchars($book['student_id'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($book['first_name'] . ' ' . $book['last_name']) ?></td>
                                    <?php endif; ?>
                                    <td><?= htmlspecialchars($book['due_date']) ?></td>
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
        if (studentsTab) {
            studentsTab.addEventListener('click', function (e) {
                e.preventDefault();
                studentsMenu.style.display = studentsMenu.style.display === 'block' ? 'none' : 'block';
            });
        }

        const transactionsTab = document.getElementById('transactions-tab');
        if (transactionsTab) {
            transactionsTab.addEventListener('click', function (e) {
                e.preventDefault();
                window.location.href = 'dashboard.php?tab=transactions';
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>