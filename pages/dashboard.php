<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';

// Initialize messages
$success_message = '';
$error_message = '';

// Fetch user details
$stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
$stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch();

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

    // Debug log
    error_log("Received year_level: '$year_level'");

    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE student_id = ?");
    $check_stmt->execute([$student_id]);
    if ($check_stmt->fetchColumn() > 0) {
        $error_message = "Student ID '$student_id' already exists. Please use a unique ID.";
    } else {
        // Validate year_level
        $valid_year_levels = [11, 12];
        if ($year_level === 0 || !in_array($year_level, $valid_year_levels)) {
            $error_message = "Invalid year level selected. Please choose Grade 11 or Grade 12.";
            error_log("Validation failed: Invalid year_level: '$year_level'");
        } else {
            try {
                $query = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password, rfid_number, student_id, course, year_level, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'student')");
                $query->execute([$first_name, $last_name, $email, $password, $rfid_number, $student_id, $course, $year_level]);
                $success_message = "Student added successfully.";
            } catch (PDOException $e) {
                $error_message = "Error adding student: " . $e->getMessage();
                error_log($error_message);
            }
        }
    }
}

// Handle removing a student (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_student']) && $user_role === 'admin') {
    $student_id = trim($_POST['student_id']);
    try {
        // Start a transaction to ensure data consistency
        $pdo->beginTransaction();

        // Find the student's internal ID (users.id) based on student_id
        $query = $pdo->prepare("SELECT id FROM users WHERE student_id = ? AND role = 'student'");
        $query->execute([$student_id]);
        $student = $query->fetch();
        if (!$student) {
            throw new Exception("Student not found.");
        }
        $internal_student_id = $student['id'];

        // Delete related transactions (use user_id instead of student_id)
        $query = $pdo->prepare("DELETE FROM transactions WHERE user_id = ?");
        $query->execute([$internal_student_id]);

        // Delete the student
        $query = $pdo->prepare("DELETE FROM users WHERE student_id = ? AND role = 'student'");
        $query->execute([$student_id]);

        // Commit the transaction
        $pdo->commit();

        $success_message = "Student removed successfully.";
    } catch (Exception $e) {
        // Roll back the transaction on error
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
        $query = $pdo->prepare("INSERT INTO books (title, author, genre, barcode, book_number, available, total_quantity, added_at) VALUES (?, ?, ?, ?, ?, 1, 1, NOW())");
        $query->execute([$title, $author, $genre, $barcode, $book_number]);
        $success_message = "Book added successfully.";
    } catch (PDOException $e) {
        $error_message = "Error adding book: " . $e->getMessage();
        error_log($error_message);
    }
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - SHS Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .form-group .scan-btn {
            display: block;
            margin-top: 10px;
        }
        /* Fallback in case admin-dashboard.css doesn't load */
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

        .alert-success {
            padding: 10px;
            margin: 15px 0;
            border: 1px solid #28a745;
            border-radius: 4px;
            background-color: #d4edda;
            color: #28a745;
            font-size: 0.9rem;
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
                <h1><?php echo ucfirst($active_tab === 'dashboard' ? 'Dashboard' : ($active_tab === 'add_student' ? 'Add Student' : ($active_tab === 'add_book' ? 'Add Book' : ($active_tab === 'students' ? 'Registered Students' : 'Books')))); ?></h1>
                <p>Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</p>
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

            <?php if ($active_tab === 'dashboard'): ?>
                <section class="dashboard-cards">
                    <div class="card">
                        <h3>Total Students</h3>
                        <p><?php
                            $query = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
                            echo $query->fetchColumn();
                        ?></p>
                    </div>
                    <div class="card">
                        <h3>Total Books</h3>
                        <p><?php
                            $query = $pdo->query("SELECT COUNT(*) FROM books");
                            echo $query->fetchColumn();
                        ?></p>
                    </div>
                    <div class="card">
                        <h3>Total Transactions</h3>
                        <p><?php
                            $query = $pdo->query("SELECT COUNT(*) FROM transactions");
                            echo $query->fetchColumn();
                        ?></p>
                    </div>
                </section>
                <section class="chart">
                    <h2>Monthly Transactions</h2>
                    <canvas id="transactionsChart" style="max-height: 300px;"></canvas>
                </section>
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
                        $query = $pdo->query("SELECT id, first_name, last_name, email, student_id, course, year_level FROM users WHERE role = 'student' ORDER BY last_name, first_name");
                        $students = $query->fetchAll(PDO::FETCH_ASSOC);
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
    </script>
</body>
</html>