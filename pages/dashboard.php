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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .form-group .scan-btn {
            display: block;
            margin-top: 10px;
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
            transition: background-color 0.3s ease;
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .reset-btn:hover {
            background-color: #ffd700;
        }
        .reset-btn i {
            margin-right: 0;
        }
        @media (min-width: 768px) {
            .reset-btn {
                font-size: 1rem;
            }
        }
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
        .transaction-table, .inventory-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .transaction-table th, .transaction-table td,
        .inventory-table th, .inventory-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        .transaction-table th, .inventory-table th {
            background-color: #003366;
            color: white;
        }
        .transaction-table tr:nth-child(even),
        .inventory-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .inventory-table th:nth-child(9), .inventory-table td:nth-child(9) {
            text-align: center;
        }
        /* Welcome Widget */
        .welcome-widget {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }
        .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin-bottom: 10px;
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
            background: #003366;
            color: white;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .action-btn:hover {
            background: #ffd700;
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
            background: #003366;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        /* Recent Books Carousel */
        .recent-books {
            margin: 20px 0;
        }
        .carousel {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .carousel-track {
            display: flex;
            overflow-x: auto;
            scroll-behavior: smooth;
            gap: 15px;
            padding: 10px 0;
            scrollbar-width: none;
        }
        .carousel-track::-webkit-scrollbar {
            display: none;
        }
        .book-card {
            flex: 0 0 200px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            transition: transform 0.2s;
        }
        .book-card:hover {
            transform: scale(1.05);
        }
        .book-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 4px;
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
        }
        .carousel-prev, .carousel-next {
            background: #003366;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            position: absolute;
            z-index: 1;
        }
        .carousel-prev {
            left: -30px;
        }
        .carousel-next {
            right: -30px;
        }
        .carousel-prev:hover, .carousel-next:hover {
            background: #ffd700;
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
                            <img src="../images/default-avatar.png" alt="Profile" class="avatar">
                            <h1>Hello, <?php echo htmlspecialchars($user['first_name'] ?? 'User'); ?>!</h1>
                            <p>Your Library at a Glance</p>
                            <div class="quick-actions">
                                <a href="borrowed_books.php" class="action-btn">My Books (<?php echo $borrowed_count; ?>)</a>
                                <form action="available_books.php" method="GET" class="mini-search">
                                    <input type="text" name="search" placeholder="Find a book..." required>
                                    <button type="submit"><i class="fas fa-search"></i></button>
                                </form>
                            </div>
                        </div>
                    </header>
                    <section class="recent-books">
                        <h2>Recently Added Books</h2>
                        <div class="carousel">
                            <button class="carousel-prev"><i class="fas fa-chevron-left"></i></button>
                            <div class="carousel-track">
                                <?php if (empty($recent_books)): ?>
                                    <p>No recent books available.</p>
                                <?php else: ?>
                                    <?php foreach ($recent_books as $book): ?>
                                        <div class="book-card">
                                            <img src="../images/default-book.png" alt="Cover">
                                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                            <p><?php echo htmlspecialchars($book['author']); ?></p>
                                            <a href="available_books.php" class="view-btn">View Details</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <button class="carousel-next"><i class="fas fa-chevron-right"></i></button>
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
                                <input type="email" name="email" required>
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
                                            <form method="POST" style="display:inline;">
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
                                        <a href="?tab=transactions&page=<?= $page + 1 ?>">Next</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="reset-button-container">
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
                                                <div class="action-buttons">
                                                    <form method="GET" action="edit_book.php" class="action-form">
                                                        <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                        <button type="submit" class="edit-btn"><i class="fas fa-edit"></i> Edit</button>
                                                    </form>
                                                    <form method="POST" class="action-form">
                                                        <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
                        <?php endif; ?>
                        <div class="reset-button-container">
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
                            datasets: [{
                                label: 'Borrow Transactions',
                                data: data.values,
                                borderColor: 'rgba(52, 152, 219, 1)',
                                backgroundColor: 'rgba(52, 152, 219, 0.2)',
                                borderWidth: 2,
                                fill: true,
                                pointRadius: 5,
                                pointBackgroundColor: 'rgba(52, 152, 219, 1)'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: { title: { display: true, text: 'Month' } },
                                y: { title: { display: true, text: 'Number of Transactions' }, beginAtZero: true, ticks: { stepSize: 1 } }
                            },
                            plugins: { legend: { display: true } }
                        }
                    });
                });
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
                    });
                }, 500);
            });
        }

        // Carousel navigation
        const carousel = document.querySelector('.carousel-track');
        const prevBtn = document.querySelector('.carousel-prev');
        const nextBtn = document.querySelector('.carousel-next');
        if (carousel && prevBtn && nextBtn) {
            prevBtn.addEventListener('click', () => {
                carousel.scrollBy({ left: -220, behavior: 'smooth' });
            });
            nextBtn.addEventListener('click', () => {
                carousel.scrollBy({ left: 220, behavior: 'smooth' });
            });
        }
    </script>
</body>
</html>