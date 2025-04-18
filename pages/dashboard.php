DASHBOARD.PHP:

<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// Initialize CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';

// Initialize messages
$success_message = '';
$error_message = '';

// Fetch user details
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
    $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $error_message = "User not found.";
        error_log("User ID $user_id not found in users table.");
    }
} catch (PDOException $e) {
    $error_message = "Database error: Unable to fetch user details.";
    error_log("User fetch error: " . $e->getMessage());
}

// Fetch data for student dashboard
$borrowed_count = 0;
$due_soon_count = 0;
$recent_books = [];
$books_borrowed_month = 0;
$total_books_available = 0;
if ($user_role === 'student') {
    try {
        // Count borrowed books
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND returned_date IS NULL");
        $stmt->execute([$user_id]);
        $borrowed_count = $stmt->fetchColumn();

        // Count books due soon (within 7 days)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND returned_date IS NULL AND borrowed_date <= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$user_id]);
        $due_soon_count = $stmt->fetchColumn();

        // Fetch recent books
        $stmt = $pdo->prepare("SELECT id, title, author FROM books ORDER BY id DESC LIMIT 6");
        $stmt->execute();
        $recent_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Books borrowed this month
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND YEAR(borrowed_date) = YEAR(NOW()) AND MONTH(borrowed_date) = MONTH(NOW())");
        $stmt->execute([$user_id]);
        $books_borrowed_month = $stmt->fetchColumn();

        // Total books available
        $stmt = $pdo->query("SELECT SUM(available) FROM books");
        $total_books_available = $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        $error_message = "Database error: Unable to fetch student data.";
        error_log("Student data fetch error: " . $e->getMessage());
    }
}

// Dynamic greeting based on server's default timezone
$hour = (int) date('H');
$greeting = $hour < 12 ? "Good Morning" : ($hour < 18 ? "Good Afternoon" : "Good Evening");

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 'admin') {
    // Add student
    if (isset($_POST['add_student'])) {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = password_hash(trim($_POST['password'] ?? ''), PASSWORD_DEFAULT);
        $rfid_number = trim($_POST['rfid_number'] ?? '');
        $student_id = trim($_POST['student_id'] ?? '');
        $course = trim($_POST['course'] ?? '');
        $year_level = isset($_POST['year_level']) ? (int) trim($_POST['year_level']) : 0;

        try {
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE student_id = ?");
            $check_stmt->execute([$student_id]);
            if ($check_stmt->fetchColumn() > 0) {
                $error_message = "Student ID '$student_id' already exists. Please use a unique ID.";
            } else {
                $valid_year_levels = [11, 12];
                if ($year_level === 0 || !in_array($year_level, $valid_year_levels)) {
                    $error_message = "Invalid year level selected. Please choose Grade 11 or Grade 12.";
                    error_log("Validation failed: Invalid year_level: '$year_level'");
                } else {
                    $query = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password, rfid_number, student_id, course, year_level, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'student')");
                    $query->execute([$first_name, $last_name, $email, $password, $rfid_number, $student_id, $course, $year_level]);
                    $success_message = "Student added successfully.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Error adding student: " . $e->getMessage();
            error_log($error_message);
        }
    }

    // Remove student
    if (isset($_POST['remove_student'])) {
        $student_id = trim($_POST['student_id'] ?? '');
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare("SELECT id FROM users WHERE student_id = ? AND role = 'student'");
            $query->execute([$student_id]);
            $student = $query->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                throw new Exception("Student not found.");
            }
            $internal_student_id = $student['id'];
            $query = $pdo->prepare("DELETE FROM transactions WHERE user_id = ?");
            $query->execute([$internal_student_id]);
            $query = $pdo->prepare("DELETE FROM notices WHERE user_id = ?");
            $query->execute([$internal_student_id]);
            $query = $pdo->prepare("DELETE FROM users WHERE student_id = ? AND role = 'student'");
            $query->execute([$student_id]);
            $pdo->commit();
            $success_message = "Student removed successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error removing student: " . $e->getMessage();
            error_log($error_message);
        }
    }

    // Add book
    if (isset($_POST['add_book'])) {
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $genre = trim($_POST['genre'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $book_number = trim($_POST['book_number'] ?? '');

        try {
            $query = $pdo->prepare("INSERT INTO books (title, author, genre, barcode, book_number, available, total_quantity) VALUES (?, ?, ?, ?, ?, 1, 1)");
            $query->execute([$title, $author, $genre, $barcode, $book_number]);
            $success_message = "Book added successfully.";
        } catch (PDOException $e) {
            $error_message = "Error adding book: " . $e->getMessage();
            error_log($error_message);
        }
    }

    // Remove book
    if (isset($_POST['remove_book'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_SESSION['csrf_token'])) {
            $error_message = "CSRF token validation failed.";
            error_log($error_message);
        } else {
            $book_id = trim($_POST['book_id'] ?? '');
            try {
                $pdo->beginTransaction();
                $query = $pdo->prepare("SELECT available FROM books WHERE id = ?");
                $query->execute([$book_id]);
                $book = $query->fetch(PDO::FETCH_ASSOC);
                if (!$book) {
                    throw new Exception("Book not found.");
                }
                if ($book['available'] == 0) {
                    throw new Exception("Cannot remove book: It is currently borrowed.");
                }
                $query = $pdo->prepare("DELETE FROM transactions WHERE book_id = ?");
                $query->execute([$book_id]);
                $query = $pdo->prepare("DELETE FROM books WHERE id = ?");
                $query->execute([$book_id]);
                $pdo->commit();
                $success_message = "Book removed successfully.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                error_log("Admin removed book ID $book_id");
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = "Error removing book: " . $e->getMessage();
                error_log($error_message);
            }
        }
    }

    // Reset transactions
    if (isset($_POST['reset_transactions'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_SESSION['csrf_token'])) {
            $error_message = "CSRF token validation failed.";
            error_log($error_message);
        } else {
            try {
                $pdo->beginTransaction();
                $query = $pdo->prepare("DELETE FROM transactions");
                $query->execute();
                $pdo->commit();
                $success_message = "All transactions have been reset successfully.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                error_log("Admin reset all transactions");
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error_message = "Error resetting transactions: " . $e->getMessage();
                error_log($error_message);
            }
        }
    }

    // Reset books
    if (isset($_POST['reset_books'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_SESSION['csrf_token'])) {
            $error_message = "CSRF token validation failed.";
            error_log($error_message);
        } else {
            try {
                $pdo->beginTransaction();
                $query = $pdo->prepare("DELETE FROM transactions");
                $query->execute();
                $query = $pdo->prepare("DELETE FROM books");
                $query->execute();
                $pdo->commit();
                $success_message = "All books have been removed successfully.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                error_log("Admin reset all books");
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error_message = "Error resetting books: " . $e->getMessage();
                error_log($error_message);
            }
        }
    }
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .alert-success {
            padding: 10px;
            margin: 15px 0;
            border: 1px solid #28a745;
            border-radius: 4px;
            background-color: #d4edda;
            color: #28a745;
            font-size: 0.9rem;
        }
        .alert-error {
            padding: 10px;
            margin: 15px 0;
            border: 1px solid #dc3545;
            border-radius: 4px;
            background-color: #f8d7da;
            color: #dc3545;
            font-size: 0.9rem;
        }
        /* Sidebar Styles (adjusted to avoid overriding .logout styles) */
        .sidebar {
            background: #003366;
            color: white;
            width: 250px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 20px;
            text-align: center;
        }
        .sidebar-header h2 {
            margin: 0;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .school-logo {
            width: 40px;
            height: 40px;
        }
        .sidebar nav {
            padding: 20px 0;
        }
        .sidebar nav a:not(.logout a) {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            font-size: 1rem;
            transition: background 0.2s;
        }
        .sidebar nav a:not(.logout a) i {
            margin-right: 10px;
            font-size: 1.2rem;
        }
        .sidebar nav a:not(.logout a):hover {
            background: #ffd700;
            color: #003366;
        }
        .sidebar nav a.active {
            background: #005588;
            color: white;
        }
        /* Welcome Widget */
        .welcome-widget {
            background: linear-gradient(135deg, #003366 0%, #005588 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }
        .welcome-widget h1, .welcome-widget p {
            color: white;
        }
        .avatar-initials {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #ffd700 0%, #ffaa00 100%);
            color: #003366;
            font-size: 2rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin: 0 auto 10px;
        }
        .quick-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        .action-btn {
            padding: 8px 16px;
            background: #ffd700;
            color: #003366;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .action-btn:hover {
            background: #ffaa00;
        }
        .mini-search {
            display: flex;
            gap: 5px;
        }
        .mini-search input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 200px;
        }
        .mini-search button {
            padding: 8px;
            background: #ffd700;
            color: #003366;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        /* Recent Books Grid */
        .recent-books {
            margin: 20px 0;
        }
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .book-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: transform 0.2s;
        }
        .book-card:hover {
            transform: scale(1.05);
        }
        .book-icon {
            font-size: 3rem;
            color: #003366;
            background: linear-gradient(135deg, #f8f9fa 0%, #e0e0e0 100%);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .book-card h3 {
            font-size: 1rem;
            margin: 10px 0 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .book-card p {
            font-size: 0.9rem;
            color: #666;
        }
        .view-btn {
            display: inline-block;
            padding: 6px 12px;
            background: #003366;
            color: white;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .view-btn:hover {
            background: #ffd700;
            color: #003366;
        }
        /* Quick Stats */
        .quick-stats {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }
        .stat-card {
            text-align: center;
            padding: 10px;
        }
        .stat-card i {
            font-size: 1.5rem;
            color: #003366;
            margin-bottom: 10px;
            display: block;
        }
        .stat-card h3 {
            font-size: 1.1rem;
            margin: 0 0 5px;
            color: #333;
            font-weight: 500;
        }
        .stat-card p {
            font-size: 1.4rem;
            color: #003366;
            margin: 0;
            font-weight: bold;
        }
        /* Admin Dashboard Cards */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        .card h3 {
            font-size: 1.2rem;
            margin: 0 0 10px;
        }
        .card p {
            font-size: 1.5rem;
            color: #003366;
            margin: 0;
        }
        /* Admin Sections */
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .form-group .scan-btn {
            display: block;
            margin-top: 10px;
            padding: 8px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .form-group .scan-btn:hover {
            background: #ffd700;
        }
        button[type="submit"] {
            padding: 10px 20px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button[type="submit"]:hover {
            background: #ffd700;
            color: #003366;
        }
        .reset-btn {
            background-color: #003366;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 3px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        .reset-btn:hover {
            background-color: #ffd700;
            color: #003366;
        }
        .transaction-table, .inventory-table, .student-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .transaction-table th, .transaction-table td,
        .inventory-table th, .inventory-table td,
        .student-table th, .student-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        .transaction-table th, .inventory-table th, .student-table th {
            background-color: #003366;
            color: white;
        }
        .transaction-table tr:nth-child(even),
        .inventory-table tr:nth-child(even),
        .student-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .inventory-table th:nth-child(8), .inventory-table td:nth-child(8) {
            text-align: center;
        }
        .remove-btn, .edit-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .remove-btn {
            background: #dc3545;
            color: white;
        }
        .remove-btn:hover {
            background: #c82333;
        }
        .edit-btn {
            background: #007bff;
            color: white;
        }
        .edit-btn:hover {
            background: #0056b3;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        .action-form {
            display: inline;
        }
        .filter-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-form .form-group {
            display: flex;
            flex-direction: column;
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .pagination a {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #003366;
        }
        .pagination a.active {
            background: #003366;
            color: white;
        }
        .pagination a:hover {
            background: #ffd700;
            color: #003366;
        }
        .chart {
            margin: 20px 0;
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
                    SHS LIBRARY
                </h2>
            </div>
            <nav>
                <a href="dashboard.php?tab=dashboard" class="<?= $active_tab === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-home"></i> <span>Dashboard</span></a>
                <a href="available_books.php"><i class="fas fa-book-open"></i> <span>Available Books</span></a>
                <a href="borrowed_books.php"><i class="fas fa-book-reader"></i> <span>Borrowed Books</span></a>
                <?php if ($user_role === 'admin'): ?>
                    <a href="dashboard.php?tab=add_book" class="<?= $active_tab === 'add_book' ? 'active' : '' ?>"><i class="fas fa-plus"></i> <span>Add Book</span></a>
                    <a href="dashboard.php?tab=add_student" class="<?= $active_tab === 'add_student' ? 'active' : '' ?>"><i class="fas fa-user-plus"></i> <span>Add Student</span></a>
                    <a href="dashboard.php?tab=students" class="<?= $active_tab === 'students' ? 'active' : '' ?>"><i class="fas fa-users"></i> <span>Registered Students</span></a>
                    <a href="dashboard.php?tab=transactions" class="<?= $active_tab === 'transactions' ? 'active' : '' ?>"><i class="fas fa-exchange-alt"></i> <span>Transactions</span></a>
                    <a href="dashboard.php?tab=inventory" class="<?= $active_tab === 'inventory' ? 'active' : '' ?>"><i class="fas fa-boxes"></i> <span>Inventory</span></a>
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
            <?php if ($active_tab === 'dashboard'): ?>
                <?php if ($user_role === 'student'): ?>
                    <header>
                        <div class="welcome-widget">
                            <div class="avatar-initials">
                                <?php
                                $initials = strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? '', 0, 1));
                                echo htmlspecialchars($initials);
                                ?>
                            </div>
                            <h1><?php echo htmlspecialchars($greeting . ', ' . ($user['first_name'] ?? 'User') . '!'); ?></h1>
                            <p>Your Library at a Glance<?php echo $due_soon_count ? " - <strong>$due_soon_count book(s) due soon</strong>" : ''; ?></p>
                            <div class="quick-actions">
                                <a href="borrowed_books.php" class="action-btn"><i class="fas fa-book-reader"></i> My Books (<?php echo $borrowed_count; ?>)</a>
                                <form action="available_books.php" method="GET" class="mini-search">
                                    <input type="text" name="search" placeholder="Find a book..." required>
                                    <button type="submit"><i class="fas fa-search"></i></button>
                                </form>
                            </div>
                        </div>
                    </header>
                    <section class="quick-stats">
                        <div class="stat-card">
                            <i class="fas fa-book"></i>
                            <h3>Borrowed This Month</h3>
                            <p><?php echo $books_borrowed_month; ?></p>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-book"></i>
                            <h3>Books Available</h3>
                            <p><?php echo $total_books_available; ?></p>
                        </div>
                    </section>
                    <section class="recent-books">
                        <h2>Recently Added Books</h2>
                        <div class="books-grid">
                            <?php if (empty($recent_books)): ?>
                                <p>No recent books available.</p>
                            <?php else: ?>
                                <?php foreach ($recent_books as $book): ?>
                                    <div class="book-card">
                                        <i class="fas fa-book book-icon"></i>
                                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                        <p><?php echo htmlspecialchars($book['author']); ?></p>
                                        <a href="available_books.php" class="view-btn">View Details</a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php else: ?>
                    <header>
                        <h1>Dashboard</h1>
                        <p>Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] ?? 'Admin'); ?>!</p>
                    </header>
                    <section class="dashboard-cards">
                        <div class="card">
                            <h3>Total Students</h3>
                            <p><?php
                                try {
                                    $query = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
                                    echo $query->fetchColumn();
                                } catch (PDOException $e) {
                                    echo "N/A";
                                    error_log("Total students query error: " . $e->getMessage());
                                }
                            ?></p>
                        </div>
                        <div class="card">
                            <h3>Total Books</h3>
                            <p><?php
                                try {
                                    $query = $pdo->query("SELECT COUNT(*) FROM books");
                                    echo $query->fetchColumn();
                                } catch (PDOException $e) {
                                    echo "N/A";
                                    error_log("Total books query error: " . $e->getMessage());
                                }
                            ?></p>
                        </div>
                        <div class="card">
                            <h3>Total Transactions</h3>
                            <p><?php
                                try {
                                    $query = $pdo->query("SELECT COUNT(*) FROM transactions");
                                    echo $query->fetchColumn();
                                } catch (PDOException $e) {
                                    echo "N/A";
                                    error_log("Total transactions query error: " . $e->getMessage());
                                }
                            ?></p>
                        </div>
                    </section>
                    <section class="chart">
                        <h2>Monthly Transactions</h2>
                        <canvas id="transactionsChart" style="max-height: 300px;"></canvas>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($user_role === 'admin'): ?>
                <?php if ($active_tab === 'add_student'): ?>
                    <section class="admin-section">
                        <h2>Add Student</h2>
                        <form method="POST" id="add-student-form">
                            <div class="form-group">
                                <label>First Name:</label>
                                <input type="text" name="first_name" required>
                            </div>
                            <div class="form-group">
                                <label>Last Name:</label>
                                <input type="text" name="last_name" required>
                            </div>
                            <div class="form-group">
                                <label>Email:</label>
                                <input type="text" name="email" required>
                            </div>
                            <div class="form-group">
                                <label>Password:</label>
                                <input type="password" name="password" required>
                            </div>
                            <div class="form-group">
                                <label>RFID Number:</label>
                                <input type="text" name="rfid_number" id="rfid_input" required>
                                <button type="button" id="scan-rfid-btn" class="scan-btn">Scan RFID</button>
                            </div>
                            <div class="form-group">
                                <label>Student ID:</label>
                                <input type="text" name="student_id" required>
                            </div>
                            <div class="form-group">
                                <label>Course/Strand:</label>
                                <select name="course" required>
                                    <option value="">Select Course/Strand</option>
                                    <option value="STEM">STEM</option>
                                    <option value="STEM Maritime">STEM Maritime</option>
                                    <option value="ABM">ABM</option>
                                    <option value="HUMSS">HUMSS</option>
                                    <option value="GAS">GAS</option>
                                    <option value="TVL">TVL</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Year Level:</label>
                                <select name="year_level" required>
                                    <option value="">Select Year Level</option>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            <button type="submit" name="add_student">Add Student</button>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'students'): ?>
                    <section class="admin-section">
                        <h2>Registered Students</h2>
                        <?php
                        try {
                            $query = $pdo->query("SELECT id, first_name, last_name, email, student_id, course, year_level FROM users WHERE role = 'student' ORDER BY last_name, first_name");
                            $students = $query->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $students = [];
                            $error_message = "Error loading students: " . $e->getMessage();
                            error_log($error_message);
                        }
                        if (empty($students)) {
                            echo "<p>No students registered in the database.</p>";
                        } else {
                        ?>
                        <table class="student-table" id="student-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>ID Number</th>
                                    <th>Course</th>
                                    <th>Year Level</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <?php $student_id = htmlspecialchars($student['student_id']); ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><?php echo $student_id; ?></td>
                                        <td><?php echo htmlspecialchars($student['course']); ?></td>
                                        <td><?php echo htmlspecialchars($student['year_level'] == 11 ? 'Grade 11' : ($student['year_level'] == 12 ? 'Grade 12' : 'Unknown')); ?></td>
                                        <td>
                                            <form method="POST" class="action-form">
                                                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                                <button type="submit" name="remove_student" class="remove-btn"><i class="fas fa-trash"></i> Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php } ?>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'add_book'): ?>
                    <section class="admin-section">
                        <h2>Add Book</h2>
                        <form method="POST" id="add-book-form">
                            <div class="form-group">
                                <label>Title:</label>
                                <input type="text" name="title" required>
                            </div>
                            <div class="form-group">
                                <label>Author:</label>
                                <input type="text" name="author" required>
                            </div>
                            <div class="form-group">
                                <label>Genre:</label>
                                <select name="genre" required>
                                    <option value="">Select Genre</option>
                                    <option value="Fiction">Fiction</option>
                                    <option value="Non-Fiction">Non-Fiction</option>
                                    <option value="Science">Science</option>
                                    <option value="History">History</option>
                                    <option value="Biography">Biography</option>
                                    <option value="Narrative">Narrative</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Barcode:</label>
                                <input type="text" name="barcode" id="barcode_input" required>
                                <button type="button" id="scan-barcode-btn" class="scan-btn">Scan Barcode</button>
                            </div>
                            <div class="form-group">
                                <label>Book Number:</label>
                                <input type="text" name="book_number" required>
                            </div>
                            <button type="submit" name="add_book">Add Book</button>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'transactions'): ?>
                    <section class="admin-section">
                        <h2>Transactions</h2>
                        <?php
                        try {
                            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
                            $per_page = 20;
                            $offset = ($page - 1) * $per_page;

                            $count_query = $pdo->query("SELECT COUNT(*) FROM transactions WHERE action IN ('BORROW', 'RETURN')");
                            $total_transactions = $count_query->fetchColumn();
                            $total_pages = ceil($total_transactions / $per_page);

                            $query = $pdo->prepare("
                                SELECT t.id, t.action, COALESCE(t.borrowed_date, t.returned_date) AS transaction_date, u.first_name, u.last_name, u.email, u.student_id
                                FROM transactions t
                                JOIN users u ON t.user_id = u.id
                                WHERE t.action IN ('BORROW', 'RETURN')
                                ORDER BY COALESCE(t.borrowed_date, t.returned_date) DESC
                                LIMIT :limit OFFSET :offset
                            ");
                            $query->bindValue(':limit', $per_page, PDO::PARAM_INT);
                            $query->bindValue(':offset', $offset, PDO::PARAM_INT);
                            $query->execute();
                            $transactions = $query->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $error_message = "Error loading transactions: " . $e->getMessage();
                            $transactions = [];
                            error_log($error_message);
                        }
                        if (empty($transactions)) {
                            echo "<p class='no-transactions'>No transactions recorded.</p>";
                        } else {
                        ?>
                        <table class="transaction-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Email</th>
                                    <th>Student ID</th>
                                    <th>Action</th>
                                    <th>Transaction Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['email']); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['student_id'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['action']); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['transaction_date'] ? date('Y-m-d H:i:s', strtotime($transaction['transaction_date'])) : 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?tab=transactions&page=<?= $page - 1 ?>">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?tab=transactions&page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?tab=transactions&page=<?= $page + 1 ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php } ?>
                        <div class="reset-button-container">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" name="reset_transactions" class="reset-btn" onclick="return confirm('Are you sure you want to reset all transactions? This action cannot be undone.');">
                                    <i class="fas fa-undo"></i> Reset Transactions
                                </button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'inventory'): ?>
                    <section class="admin-section">
                        <h2>Inventory</h2>
                        <form method="GET" class="filter-form">
                            <input type="hidden" name="tab" value="inventory">
                            <input type="hidden" name="page" value="<?= isset($_GET['page']) ? (int)$_GET['page'] : 1 ?>">
                            <div class="form-group">
                                <label>Search:</label>
                                <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search by title or author">
                            </div>
                            <div class="form-group">
                                <label>Genre:</label>
                                <select name="genre_filter">
                                    <option value="">All Genres</option>
                                    <?php
                                    try {
                                        $genre_query = $pdo->query("SELECT DISTINCT genre FROM books ORDER BY genre");
                                        $genres = $genre_query->fetchAll(PDO::FETCH_COLUMN);
                                    } catch (PDOException $e) {
                                        $genres = [];
                                        error_log("Genre query error: " . $e->getMessage());
                                    }
                                    foreach ($genres as $genre):
                                    ?>
                                        <option value="<?= htmlspecialchars($genre) ?>" <?= ($_GET['genre_filter'] ?? '') === $genre ? 'selected' : '' ?>><?= htmlspecialchars($genre) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit">Filter</button>
                        </form>
                        <?php
                        try {
                            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
                            $per_page = 20;
                            $offset = ($page - 1) * $per_page;

                            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                            $genre_filter = isset($_GET['genre_filter']) ? trim($_GET['genre_filter']) : '';
                            $where_clause = [];
                            $params = [];
                            if ($search) {
                                $where_clause[] = "(title LIKE :search OR author LIKE :search)";
                                $params[':search'] = "%$search%";
                            }
                            if ($genre_filter) {
                                $where_clause[] = "genre = :genre";
                                $params[':genre'] = $genre_filter;
                            }
                            $where_sql = $where_clause ? 'WHERE ' . implode(' AND ', $where_clause) : '';

                            $count_query = $pdo->prepare("SELECT COUNT(*) FROM books $where_sql");
                            $count_query->execute($params);
                            $total_books = $count_query->fetchColumn();
                            $total_pages = ceil($total_books / $per_page);

                            $query = $pdo->prepare("
                                SELECT id, title, author, genre, barcode, book_number, available, total_quantity
                                FROM books
                                $where_sql
                                ORDER BY title
                                LIMIT :limit OFFSET :offset
                            ");
                            foreach ($params as $key => $value) {
                                $query->bindValue($key, $value);
                            }
                            $query->bindValue(':limit', $per_page, PDO::PARAM_INT);
                            $query->bindValue(':offset', $offset, PDO::PARAM_INT);
                            $query->execute();
                            $books = $query->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $error_message = "Error loading inventory: " . $e->getMessage();
                            $books = [];
                            error_log($error_message);
                        }
                        if (empty($books)) {
                            echo "<p class='no-records'>No books in the inventory.</p>";
                        } else {
                        ?>
                        <table class="inventory-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Author</th>
                                    <th>Genre</th>
                                    <th>Barcode</th>
                                    <th>Book Number</th>
                                    <th>Availability</th>
                                    <th>Total Quantity</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($books as $book): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($book['title']); ?></td>
                                        <td><?php echo htmlspecialchars($book['author']); ?></td>
                                        <td><?php echo htmlspecialchars($book['genre']); ?></td>
                                        <td><?php echo htmlspecialchars($book['barcode']); ?></td>
                                        <td><?php echo htmlspecialchars($book['book_number']); ?></td>
                                        <td><?php echo $book['available'] > 0 ? 'Available' : 'Borrowed'; ?></td>
                                        <td><?php echo htmlspecialchars($book['total_quantity']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="GET" action="edit_book.php" class="action-form">
                                                    <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                    <button type="submit" class="edit-btn"><i class="fas fa-edit"></i> Edit</button>
                                                </form>
                                                <form method="POST" class="action-form">
                                                    <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <button type="submit" name="remove_book" class="remove-btn" onclick="return confirm('Are you sure you want to remove this book?');">
                                                        <i class="fas fa-trash"></i> Remove
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?tab=inventory&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&genre_filter=<?= urlencode($genre_filter) ?>">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?tab=inventory&page=<?= $i ?>&search=<?= urlencode($search) ?>&genre_filter=<?= urlencode($genre_filter) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?tab=inventory&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&genre_filter=<?= urlencode($genre_filter) ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php } ?>
                        <div class="reset-button-container">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" name="reset_books" class="reset-btn" onclick="return confirm('Are you sure you want to remove all books? This action cannot be undone.');">
                                    <i class="fas fa-undo"></i> Reset Books
                                </button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const transactionsChart = document.getElementById('transactionsChart');
        if (transactionsChart) {
            fetch('../api/getMonthlyTransactions.php')
                .then(response => response.json())
                .then(data => {
                    const ctx = transactionsChart.getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.labels || [],
                            datasets: [{
                                label: 'Borrow Transactions',
                                data: data.values || [],
                                borderColor: 'rgba(52, 152, 219, 1)',
                                backgroundColor: 'rgba(52, 152, 219, 0.2)',
                                borderWidth: 2,
                                fill: true,
                                pointRadius: 5,
                                pointBackgroundColor: 'rgba(52, 152, 219, 1)'
                            }]
                        },
                        options: {
                            responsive: true;
                            maintainAspectRatio: false;
                            scales: {
                                x: { title: { display: true, text: 'Month' } },
                                y: { title: { display: true, text: 'Number of Transactions' }, beginAtZero: true, ticks: { stepSize: 1 } }
                            },
                            plugins: { legend: { display: true } }
                        }
                    });
                })
                .catch(error => console.error('Error loading chart data:', error));
        }

        const scanRfidBtn = document.getElementById('scan-rfid-btn');
        if (scanRfidBtn) {
            scanRfidBtn.addEventListener('click', function() {
                const rfidInput = document.getElementById('rfid_input');
                rfidInput.value = "Scanning...";
                let attempts = 0;
                const maxAttempts = 40;
                const pollRFID = setInterval(() => {
                    fetch('../scan.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'rfid_scan=true'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.rfid_number) {
                            rfidInput.value = data.rfid_number;
                            clearInterval(pollRFID);
                        }
                        attempts++;
                        if (attempts >= maxAttempts) {
                            rfidInput.value = "";
                            alert("No RFID detected within 20 seconds.");
                            clearInterval(pollRFID);
                        }
                    })
                    .catch(error => {
                        console.error('Error scanning RFID:', error);
                        rfidInput.value = "";
                        alert("Error scanning RFID.");
                        clearInterval(pollRFID);
                    });
                }, 500);
            });
        }

        const scanBarcodeBtn = document.getElementById('scan-barcode-btn');
        if (scanBarcodeBtn) {
            scanBarcodeBtn.addEventListener('click', function() {
                const barcodeInput = document.getElementById('barcode_input');
                barcodeInput.value = "Scanning...";
                let attempts = 0;
                const maxAttempts = 40;
                const pollBarcode = setInterval(() => {
                    fetch('../scan.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'barcode_scan=true'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.barcode) {
                            barcodeInput.value = data.barcode;
                            clearInterval(pollBarcode);
                        }
                        attempts++;
                        if (attempts >= maxAttempts) {
                            barcodeInput.value = "";
                            alert("No barcode detected within 20 seconds.");
                            clearInterval(pollBarcode);
                        }
                    })
                    .catch(error => {
                        console.error('Error scanning barcode:', error);
                        barcodeInput.value = "";
                        alert("Error scanning barcode.");
                        clearInterval(pollBarcode);
                    });
                }, 500);
            });
        }
    </script>
</body>
</html>