<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// Fetch books data
$query_available = $pdo->query("SELECT * FROM books WHERE available = 1");
$available_books = $query_available->fetchAll();

$query_borrowed = $pdo->query("
    SELECT b.title, b.author, t.due_date, u.first_name, u.last_name
    FROM transactions t
    JOIN books b ON t.book_id = b.id
    JOIN users u ON t.user_id = u.id
    WHERE t.returned = 0
");
$borrowed_books = $query_borrowed->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Books - Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
        <h2>
    <img src="../images/logo.png" alt="School Logo" class="school-logo">
    SHS Library
</h2>

            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="books.php" class="active">Books</a>
                <a href="borrowed.php">Borrowed Books</a>
                <a href="notices.php">Notice</a>
                <a href="profile.php">Profile</a>
            </nav>
            <div class="logout">
                <a href="../logout.php">Logout</a>
            </div>
        </div>
        <!-- Main Content -->
        <div class="main-content">
            <header>
                <h1>Books</h1>
            </header>
            <div class="collapsible-container">
                <button class="collapsible">Available Books</button>
                <div class="content">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Genre</th>
                                <th>Book Number</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($available_books as $book): ?>
                                <tr>
                                    <td><?= htmlspecialchars($book['title']) ?></td>
                                    <td><?= htmlspecialchars($book['author']) ?></td>
                                    <td><?= htmlspecialchars($book['genre']) ?></td>
                                    <td><?= htmlspecialchars($book['book_number']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button class="collapsible">Borrowed Books</button>
                <div class="content">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Borrower</th>
                                <th>Due Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowed_books as $book): ?>
                                <tr>
                                    <td><?= htmlspecialchars($book['title']) ?></td>
                                    <td><?= htmlspecialchars($book['first_name'] . ' ' . $book['last_name']) ?></td>
                                    <td><?= htmlspecialchars($book['due_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Collapsible functionality
        const collapsibles = document.querySelectorAll(".collapsible");
        collapsibles.forEach((collapsible) => {
            collapsible.addEventListener("click", function () {
                this.classList.toggle("active");
                const content = this.nextElementSibling;
                content.style.display = content.style.display === "block" ? "none" : "block";
            });
        });
    </script>
</body>
</html>
