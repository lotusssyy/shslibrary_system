<?php
include '../includes/db.php';
session_start();
date_default_timezone_set('Asia/Manila');
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
    $user = $stmt->fetch();
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
$recent_books = [];
if ($user_role === 'student') {
    try {
        // Count borrowed books
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND returned_date IS NULL");
        $stmt->execute([$user_id]);
        $borrowed_count = $stmt->fetchColumn();

        // Fetch recent books
        $stmt = $pdo->prepare("SELECT id, title, author FROM books ORDER BY id DESC LIMIT 6");
        $stmt->execute();
        $recent_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = "Database error: Unable to fetch student data.";
        error_log("Student data fetch error: " . $e->getMessage());
    }
}

// Handle adding a student (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student']) && $user_role === 'admin') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    $rfid_number = trim($_POST['rfid_number']);
    $student_id = trim($_POST['student_id']);
    $course = trim($_POST['course']);
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

// Handle removing a student (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_student']) && $user_role === 'admin') {
    $student_id = trim($_POST['student_id']);
    try {
        $pdo->beginTransaction();
        $query = $pdo->prepare("SELECT id FROM users WHERE student_id = ? AND role = 'student'");
        $query->execute([$student_id]);
        $student = $query->fetch();
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

// Handle adding a book (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_book']) && $user_role === 'admin') {
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $genre = trim($_POST['genre']);
    $barcode = trim($_POST['barcode']);
    $book_number = trim($_POST['book_number']);

    try {
        $query = $pdo->prepare("INSERT INTO books (title, author, genre, barcode, book_number, available, total_quantity) VALUES (?, ?, ?, ?, ?, 1, 1)");
        $query->execute([$title, $author, $genre, $barcode, $book_number]);
        $success_message = "Book added successfully.";
    } catch (PDOException $e) {
        $error_message = "Error adding book: " . $e->getMessage();
        error_log($error_message);
    }
}

// Handle removing a book (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_book']) && $user_role === 'admin') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_SESSION['csrf_token'])) {
        $error_message = "CSRF token validation failed.";
        error_log($error_message);
    } else {
        $book_id = trim($_POST['book_id']);
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare("SELECT available FROM books WHERE id = ?");
            $query->execute([$book_id]);
            $book = $query->fetch();
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
            error_log("Admin removed book ID $book_id on " . date('Y-m-d H:i:s'));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = "Error removing book: " . $e->getMessage();
            error_log($error_message);
        }
    }
}

// Handle resetting transactions (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_transactions']) && $user_role === 'admin') {
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
            error_log("Admin reset all transactions on " . date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = "Error resetting transactions: " . $e->getMessage();
            error_log($error_message);
        }
    }
}

// Handle resetting books (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_books']) && $user_role === 'admin') {
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
            error_log("Admin reset all books on " . date('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = "Error resetting books: " . $e->getMessage();
            error_log($error_message);
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
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Inter', sans-serif;
        }
        .container {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            background: #003366;
            color: white;
            width: 250px;
            padding: 20px;
            transition: width 0.3s;
        }
        .sidebar a {
            color: white;
            display: flex;
            align-items: center;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 5px;
        }
        .sidebar a:hover, .sidebar a.active {
            background: #ffd700;
            color: #003366;
        }
        .main-content {
            flex: 1;
            padding: 20px;
            background: white;
            border-radius: 10px;
            margin: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .form-group .scan-btn {
            background: #003366;
            color: white;
            padding: 8px;
            border-radius: 5px;
            margin-top: 10px;
        }
        .reset-btn {
            background: #003366;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .reset-btn:hover {
            background: #ffd700;
        }
        .alert-success, .alert-error {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-size: 1rem;
        }
        .alert-success {
            background: #d4edda;
            color: #28a745;
            border: 1px solid #28a745;
        }
        .alert-error {
            background: #f8d7da;
            color: #dc3545;
            border: 1px solid #dc3545;
        }
        .transaction-table, .inventory-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .transaction-table th, .transaction-table td,
        .inventory-table th, .inventory-table td {
            padding: 12px;
            border: 1px solid #e5e7eb;
            text-align: left;
        }
        .transaction-table th, .inventory-table th {
            background: #003366;
            color: white;
        }
        .transaction-table tr:nth-child(even),
        .inventory-table tr:nth-child(even) {
            background: #f9fafb;
        }
        .welcome-widget {
            background: linear-gradient(135deg, #003366 0%, #004080 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 3px solid #ffd700;
            object-fit: cover;
        }
        .quick-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .action-btn {
            background: #ffd700;
            color: #003366;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .action-btn:hover {
            transform: scale(1.05);
        }
        .mini-search {
            display: flex;
            gap: 10px;
        }
        .mini-search input {
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 5px;
            width: 250px;
        }
        .mini-search button {
            background: #ffd700;
            color: #003366;
            padding: 10px;
            border-radius: 5px;
        }
        .recent-books {
            margin: 30px 0;
        }
        .carousel {
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .carousel-track {
            display: flex;
            overflow-x: auto;
            scroll-behavior: smooth;
            gap: 20px;
            padding: 15px 0;
            scrollbar-width: none;
        }
        .carousel-track::-webkit-scrollbar {
            display: none;
        }
        .book-card {
            flex: 0 0 220px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
        }
        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        .book-card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 8px;
            background: #f3f4f6;
        }
        .book-card .fallback-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 2rem;
            color: #9ca3af;
            display: none;
        }
        .book-card img.error + .fallback-icon {
            display: block;
        }
        .book-card h3 {
            font-size: 1.1rem;
            margin: 15px 0 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #1f2937;
        }
        .book-card p {
            font-size: 0.9rem;
            color: #6b7280;
        }
        .view-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #003366;
            color: white;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.3s;
        }
        .view-btn:hover {
            background: #ffd700;
        }
        .carousel-prev, .carousel-next {
            background: #003366;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 50%;
            cursor: pointer;
            position: absolute;
            z-index: 1;
            transition: background 0.3s;
        }
        .carousel-prev {
            left: -40px;
        }
        .carousel-next {
            right: -40px;
        }
        .carousel-prev:hover, .carousel-next:hover {
            background: #ffd700;
        }
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card h3 {
            font-size: 1.2rem;
            color: #1f2937;
        }
        .card p {
            font-size: 1.5rem;
            font-weight: 700;
            color: #003366;
        }
        .pagination {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        .pagination a {
            padding: 8px 16px;
            background: #e5e7eb;
            color: #1f2937;
            border-radius: 5px;
            text-decoration: none;
        }
        .pagination a.active, .pagination a:hover {
            background: #003366;
            color: white;
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
            }
            .carousel-prev, .carousel-next {
                padding: 8px;
                left: -30px;
                right: -30px;
            }
            .book-card {
                flex: 0 0 180px;
            }
            .book-card img {
                height: 150px;
            }
            .mini-search input {
                width: 200px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2 class="flex items-center gap-2">
                    <img src="../images/logo.png" alt="School Logo" class="w-10 h-10">
                    SHS LIBRARY
                </h2>
            </div>
            <nav>
                <a href="dashboard.php?tab=dashboard" class="<?= $active_tab === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-home"></i> <span>Dashboard</span></a>
                <a href="#" id="books-tab" class="<?= in_array($active_tab, ['add_book']) ? 'active' : '' ?>"><i class="fas fa-book"></i> <span>Books</span></a>
                <ul class="sub-menu" id="books-menu" style="display: <?= in_array($active_tab, ['add_book']) ? 'block' : 'none' ?>;">
                    <li><a href="available_books.php"><i class="fas fa-book-open"></i> <span>Available Books</span></a></li>
                    <li><a href="borrowed_books.php"><i class="fas fa-book-reader"></i> <span>Borrowed Books</span></a></li>
                    <?php if ($user_role === 'admin'): ?>
                        <li><a href="dashboard.php?tab=add_book" class="<?= $active_tab === 'add_book' ? 'active' : '' ?>"><i class="fas fa-plus"></i> <span>Add Book</span></a></li>
                    <?php endif; ?>
                </ul>
                <?php if ($user_role === 'admin'): ?>
                    <a href="#" id="students-tab" class="<?= in_array($active_tab, ['add_student', 'students']) ? 'active' : '' ?>"><i class="fas fa-users"></i> <span>Students</span></a>
                    <ul class="sub-menu" id="students-menu" style="display: <?= in_array($active_tab, ['add_student', 'students']) ? 'block' : 'none' ?>;">
                        <li><a href="dashboard.php?tab=add_student" class="<?= $active_tab === 'add_student' ? 'active' : '' ?>"><i class="fas fa-user-plus"></i> <span>Add Student</span></a></li>
                        <li><a href="dashboard.php?tab=students" class="<?= $active_tab === 'students' ? 'active' : '' ?>"><i class="fas fa-list"></i> <span>Registered Students</span></a></li>
                    </ul>
                    <a href="dashboard.php?tab=transactions" id="transactions-tab" class="<?= $active_tab === 'transactions' ? 'active' : '' ?>"><i class="fas fa-exchange-alt"></i> <span>Transactions</span></a>
                    <a href="dashboard.php?tab=inventory" id="inventory-tab" class="<?= $active_tab === 'inventory' ? 'active' : '' ?>"><i class="fas fa-boxes"></i> <span>Inventory</span></a>
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
            <?php if ($active_tab === 'dashboard'): ?>
                <?php if ($user_role === 'student'): ?>
                    <header>
                        <div class="welcome-widget">
                            <img src="https://picsum.photos/100/100?random=1" alt="Profile" class="avatar" onerror="this class='error'> 
                                <i class='fas fa-user-circle fallback-icon'></i> 
                            </img>
                            <h1 class="text-2xl font-bold">Hello, <?php echo htmlspecialchars($user['first_name'] ?? 'User'); ?>!</h1>
                            <p class="text-lg">Your Library at a Glance</p>
                            <div class="quick-actions">
                                <a href="borrowed_books.php" class="action-btn">My Books (<?php echo $borrowed_count; ?>)</a>
                                <form action="available_books.php" method="GET" class="mini-search">
                                    <input type="text" name="search" placeholder="Search books..." required>
                                    <button type="submit"><i class="fas fa-search"></i></button>
                                </form>
                            </div>
                        </div>
                    </header>
                    <section class="recent-books">
                        <h2 class="text-xl font-semibold mb-4">Recently Added Books</h2>
                        <div class="carousel">
                            <button class="carousel-prev"><i class="fas fa-chevron-left"></i></button>
                            <div class="carousel-track">
                                <?php if (empty($recent_books)): ?>
                                    <p>No recent books available.</p>
                                <?php else: ?>
                                    <?php foreach ($recent_books as $book): ?>
                                        <div class="book-card">
                                            <img src="https://picsum.photos/200/300?random=<?php echo $book['id']; ?>" alt="Book Cover" onerror="this.classList.add('error');">
                                            <i class="fas fa-book-open fallback-icon"></i>
                                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                            <p><?php echo htmlspecialchars($book['author']); ?></p>
                                            <a href="available_books.php?id=<?php echo $book['id']; ?>" class="view-btn">View Details</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <button class="carousel-next"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </section>
                <?php else: ?>
                    <header>
                        <h1 class="text-3xl font-bold">Admin Dashboard</h1>
                        <p class="text-lg">Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] ?? 'Admin'); ?>!</p>
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
                        <h2 class="text-xl font-semibold mb-4">Monthly Transactions</h2>
                        <canvas id="transactionsChart" style="max-height: 400px;"></canvas>
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

            <?php if ($user_role === 'admin'): ?>
                <?php if ($active_tab === 'add_student'): ?>
                    <section class="admin-section">
                        <h2 class="text-xl font-semibold mb-4">Add Student</h2>
                        <form method="POST" id="add-student-form" class="space-y-4">
                            <div class="form-group">
                                <label class="block font-medium">First Name:</label>
                                <input type="text" name="first_name" required class="w-full p-2 border rounded">
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">Last Name:</label>
                                <input type="text" name="last_name" required class="w-full p-2 border rounded">
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">Email:</label>
                                <input type="email" name="email" required class="w-full p-2 border rounded">
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">Password:</label>
                                <input type="password" name="password" required class="w-full p-2 border rounded">
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">RFID Number:</label>
                                <input type="text" name="rfid_number" id="rfid_input" required class="w-full p-2 border rounded">
                                <button type="button" id="scan-rfid-btn" class="scan-btn">Scan RFID</button>
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">Student ID:</label>
                                <input type="text" name="student_id" required class="w-full p-2 border rounded">
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">Course/Strand:</label>
                                <select name="course" required class="w-full p-2 border rounded">
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
                                <label class="block font-medium">Year Level:</label>
                                <select name="year_level" required class="w-full p-2 border rounded">
                                    <option value="">Select Year Level</option>
                                    <option value="11">Grade 11</option>
                                    <option value="12">Grade 12</option>
                                </select>
                            </div>
                            <button type="submit" name="add_student" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-yellow-500">Add Student</button>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'students'): ?>
                    <section class="admin-section">
                        <h2 class="text-xl font-semibold mb-4">Registered Students</h2>
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
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                                <button type="submit" name="remove_student" class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700"><i class="fas fa-trash"></i> Remove</button>
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
                        <h2 class="text-xl font-semibold mb-4">Add Book</h2>
                        <form method="POST" id="add-book-form" class="space-y-4">
                            <div class="form-group">
                                <label class="block font-medium">Title:</label>
                                <input type="text" name="title" required class="w-full p-2 border rounded">
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">Author:</label>
                                <input type="text" name="author" required class="w-full p-2 border rounded">
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">Genre:</label>
                                <select name="genre" required class="w-full p-2 border rounded">
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
                                <label class="block font-medium">Barcode:</label>
                                <input type="text" name="barcode" id="barcode_input" required class="w-full p-2 border rounded">
                                <button type="button" id="scan-barcode-btn" class="scan-btn">Scan Barcode</button>
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">Book Number:</label>
                                <input type="text" name="book_number" required class="w-full p-2 border rounded">
                            </div>
                            <button type="submit" name="add_book" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-yellow-500">Add Book</button>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'transactions'): ?>
                    <section class="admin-section">
                        <h2 class="text-xl font-semibold mb-4">Transactions</h2>
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
                        ?>
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
                        <?php if (empty($transactions)): ?>
                            <p class='no-transactions'>No transactions recorded.</p>
                        <?php else: ?>
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
                                        <a href="?tab=transactions&page=<?= $page + _ef1 ?>">Next</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="reset-button-container mt-4">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" name="reset_transactions" class="reset-btn" onclick="return confirm('Are you sure you want to reset all transactions? This action cannot be undone.');">
                                    <i class="fas fa-undo"></i> Reset Transactions
                                </button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'inventory'): ?>
                    <section class="admin-section">
                        <h2 class="text-xl font-semibold mb-4">Inventory</h2>
                        <form method="GET" class="filter-form flex space-x-4 mb-4">
                            <input type="hidden" name="tab" value="inventory">
                            <input type="hidden" name="page" value="<?= isset($_GET['page']) ? (int)$_GET['page'] : 1 ?>">
                            <div class="form-group">
                                <label class="block font-medium">Search:</label>
                                <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search by title or author" class="p-2 border rounded">
                            </div>
                            <div class="form-group">
                                <label class="block font-medium">Genre:</label>
                                <select name="genre_filter" class="p-2 border rounded">
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
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-yellow-500 self-end">Filter</button>
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
                        ?>
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
                        <?php if (empty($books)): ?>
                            <p class='no-records'>No books in the inventory.</p>
                        <?php else: ?>
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
                                                <div class="action-buttons flex space-x-2">
                                                    <form method="GET" action="edit_book.php" class="action-form">
                                                        <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                        <button type="submit" class="bg-green-600 text-white px-3 py-1 rounded hover:bg-green-700"><i class="fas fa-edit"></i> Edit</button>
                                                    </form>
                                                    <form method="POST" class="action-form">
                                                        <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <button type="submit" name="remove_book" class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700" onclick="return confirm('Are you sure you want to remove this book?');">
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
                        <?php endif; ?>
                        <div class="reset-button-container mt-4">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" name="reset_books" class="reset-btn" onclick="return confirm('Are you sure you want to remove all books? This action cannot be undone.');">
                                    <i class="fas fa-undo"></i> Reset Books
                                </button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Sidebar menu toggle
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

        // Enhanced Transactions Chart
        const transactionsChart = document.getElementById('transactionsChart');
        if (transactionsChart) {
            fetch('../api/getMonthlyTransactions.php')
                .then(response => response.json())
                .then(data => {
                    const ctx = transactionsChart.getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.labels,
                            datasets: [
                                {
                                    label: 'Borrow Transactions',
                                    data: data.borrow_values,
                                    borderColor: '#003366',
                                    backgroundColor: 'rgba(0, 51, 102, 0.2)',
                                    borderWidth: 2,
                                    fill: true,
                                    pointRadius: 5,
                                    pointBackgroundColor: '#003366'
                                },
                                {
                                    label: 'Return Transactions',
                                    data: data.return_values,
                                    borderColor: '#ffd700',
                                    backgroundColor: 'rgba(255, 215, 0, 0.2)',
                                    borderWidth: 2,
                                    fill: true,
                                    pointRadius: 5,
                                    pointBackgroundColor: '#ffd700'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: { title: { display: true, text: 'Month', font: { size: 14 } } },
                                y: { 
                                    title: { display: true, text: 'Number of Transactions', font: { size: 14 } },
                                    beginAtZero: true,
                                    ticks: { stepSize: 1 }
                                }
                            },
                            plugins: {
                                legend: { display: true, position: 'top' },
                                tooltip: {
                                    enabled: true,
                                    mode: 'index',
                                    intersect: false,
                                    backgroundColor: 'rgba(0, 51, 102, 0.8)',
                                    titleFont: { size: 14 },
                                    bodyFont: { size: 12 }
                                }
                            }
                        }
                    });
                });
        }

        // RFID Scan
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
                    });
                }, 500);
            });
        }

        // Barcode Scan
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
                    });
                }, 500);
            });
        }

        // Carousel with Auto-Scroll
        const carousel = document.querySelector('.carousel-track');
        const prevBtn = document.querySelector('.carousel-prev');
        const nextBtn = document.querySelector('.carousel-next');
        if (carousel && prevBtn && nextBtn) {
            let autoScroll;
            const scrollAmount = 240;

            prevBtn.addEventListener('click', () => {
                carousel.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
                clearInterval(autoScroll);
            });

            nextBtn.addEventListener('click', () => {
                carousel.scrollBy({ left: scrollAmount, behavior: 'smooth' });
                clearInterval(autoScroll);
            });

            // Auto-scroll every 5 seconds
            autoScroll = setInterval(() => {
                if (carousel.scrollLeft + carousel.clientWidth >= carousel.scrollWidth) {
                    carousel.scrollTo({ left: 0, behavior: 'smooth' });
                } else {
                    carousel.scrollBy({ left: scrollAmount, behavior: 'smooth' });
                }
            }, 5000);

            // Pause auto-scroll on hover
            carousel.addEventListener('mouseenter', () => clearInterval(autoScroll));
            carousel.addEventListener('mouseleave', () => {
                autoScroll = setInterval(() => {
                    if (carousel.scrollLeft + carousel.clientWidth >= carousel.scrollWidth) {
                        carousel.scrollTo({ left: 0, behavior: 'smooth' });
                    } else {
                        carousel.scrollBy({ left: scrollAmount, behavior: 'smooth' });
                    }
                }, 5000);
            });
        }
    </script>
</body>
</html>