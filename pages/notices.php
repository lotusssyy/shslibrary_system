<?php
include '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user details
$stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Fetch notices for the user
$stmt = $pdo->prepare("SELECT message, created_at FROM notices WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$notices = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notices - SHS Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        .notice-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: #fff;
        }
        .notice-table th, .notice-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        .notice-table th {
            background-color: #003366;
            color: white;
            font-weight: bold;
        }
        .notice-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .no-notices {
            margin-top: 20px;
            color: #666;
            font-style: italic;
        }
        .main-content {
            padding: 20px;
        }
        header {
            background-color: #003366;
            color: white;
            padding: 15px;
            margin-bottom: 20px;
        }
        header h1 {
            margin: 0;
            font-size: 1.5em;
        }
        header p {
            margin: 5px 0 0;
            font-size: 0.9em;
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
                <a href="#" id="books-tab"><i class="fas fa-book"></i> <span>Books</span></a>
                <ul class="sub-menu" id="books-menu" style="display: none;">
                    <li><a href="available_books.php"><i class="fas fa-book-open"></i> <span>Available Books</span></a></li>
                    <li><a href="borrowed_books.php"><i class="fas fa-book-reader"></i> <span>Borrowed Books</span></a></li>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <li><a href="dashboard.php?tab=add_book"><i class="fas fa-plus"></i> <span>Add Book</span></a></li>
                    <?php endif; ?>
                </ul>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="#" id="students-tab"><i class="fas fa-users"></i> <span>Students</span></a>
                    <ul class="sub-menu" id="students-menu" style="display: none;">
                        <li><a href="dashboard.php?tab=add_student"><i class="fas fa-user-plus"></i> <span>Add Student</span></a></li>
                    </ul>
                <?php endif; ?>
                <a href="notices.php" class="active"><i class="fas fa-bell"></i> <span>Notices</span></a>
                <a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a>
            </nav>
            <div class="logout">
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <header>
                <h1>Notices</h1>
                <p>Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</p>
            </header>
            <section class="notices-list">
                <?php if (empty($notices)): ?>
                    <p class="no-notices">No notices available.</p>
                <?php else: ?>
                    <table class="notice-table">
                        <thead>
                            <tr>
                                <th>Message</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notices as $notice): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($notice['message']); ?></td>
                                    <td><?php echo htmlspecialchars($notice['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

        <?php if ($_SESSION['role'] === 'admin'): ?>
        const studentsTab = document.getElementById('students-tab');
        const studentsMenu = document.getElementById('students-menu');
        studentsTab.addEventListener('click', function (e) {
            e.preventDefault();
            studentsMenu.style.display = studentsMenu.style.display === 'block' ? 'none' : 'block';
        });
        <?php endif; ?>
    </script>
</body>
</html>