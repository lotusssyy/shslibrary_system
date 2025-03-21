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
        .notices-list {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .notice-bubble {
            background-color: #f1f8ff;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 15px;
            position: relative;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #003366;
            transition: transform 0.2s ease;
        }
        .notice-bubble:hover {
            transform: translateY(-3px);
        }
        .notice-bubble::before {
            content: '';
            position: absolute;
            top: 20px;
            left: -10px;
            width: 0;
            height: 0;
            border-top: 10px solid transparent;
            border-bottom: 10px solid transparent;
            border-right: 10px solid #003366;
        }
        .notice-message {
            font-size: 1em;
            color: #333;
            margin-bottom: 5px;
            word-wrap: break-word;
        }
        .notice-timestamp {
            font-size: 0.8em;
            color: #777;
            text-align: right;
        }
        .no-notices {
            margin-top: 20px;
            color: #666;
            font-style: italic;
            text-align: center;
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
            <section class="notices-list" id="notices-list">
                <?php if (empty($notices)): ?>
                    <p class="no-notices">No notices available.</p>
                <?php else: ?>
                    <?php foreach ($notices as $notice): ?>
                        <div class="notice-bubble">
                            <div class="notice-message">
                                <?php echo htmlspecialchars($notice['message']); ?>
                            </div>
                            <div class="notice-timestamp">
                                <?php 
                                // Convert created_at to 12-hour format if not already
                                $timestamp = strtotime($notice['created_at']);
                                echo htmlspecialchars(date('Y-m-d h:i:s A', $timestamp)); 
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <script>
        // Submenu toggle functionality
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

        // Dynamic notice refreshing
        function fetchNotices() {
            fetch('fetch_notices.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'user_id=<?php echo $user_id; ?>'
            })
            .then(response => response.json())
            .then(data => {
                const noticesList = document.getElementById('notices-list');
                noticesList.innerHTML = '';
                if (data.length === 0) {
                    noticesList.innerHTML = '<p class="no-notices">No notices available.</p>';
                } else {
                    data.forEach(notice => {
                        const bubble = document.createElement('div');
                        bubble.className = 'notice-bubble';
                        bubble.innerHTML = `
                            <div class="notice-message">${notice.message}</div>
                            <div class="notice-timestamp">${notice.created_at}</div>
                        `;
                        noticesList.appendChild(bubble);
                    });
                }
            })
            .catch(error => console.error('Error fetching notices:', error));
        }

        // Initial fetch and periodic refresh every 5 seconds
        fetchNotices();
        setInterval(fetchNotices, 5000);
    </script>
</body>
</html>