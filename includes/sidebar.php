<?php
$user_role = $_SESSION['role'] ?? 'student';
$is_staff = in_array($user_role, ['admin', 'librarian'], true);
$active_page = basename($_SERVER['PHP_SELF']);
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';

// Fetch unread notification count
$unread_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_status = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $unread_count = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    // silently fail
}

// Fetch unread message count
$unread_messages = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $unread_messages = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    // silently fail
}
?>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>
            <img src="../images/logo.png" alt="School Logo" class="school-logo">
            SHS Library
        </h2>
    </div>
    <nav>
        <a href="dashboard.php?tab=dashboard" class="<?php echo $active_page === 'dashboard.php' && $active_tab === 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-home"></i> <span>Dashboard</span></a>
        <?php $books_open = ($active_page === 'dashboard.php' && ($active_tab === 'books' || $active_tab === 'add_book' || $active_tab === 'phone_scanner')) || $active_page === 'available_books.php' || $active_page === 'borrowed_books.php' || $active_page === 'test_transactions.php' || $active_page === 'borrow_history.php' || $active_page === 'reading_stats.php'; ?>
        <button type="button" id="books-tab" class="sidebar-toggle <?php echo $books_open ? 'active' : ''; ?>" aria-expanded="<?php echo $books_open ? 'true' : 'false'; ?>" aria-controls="books-menu">
            <i class="fas fa-book"></i> <span>Books</span><i class="fas fa-chevron-down toggle-icon" aria-hidden="true"></i>
        </button>
        <ul class="sub-menu" id="books-menu" <?php echo $books_open ? '' : 'hidden'; ?>>
            <li><a href="available_books.php" class="<?php echo $active_page === 'available_books.php' ? 'active' : ''; ?>"><i class="fas fa-book-open"></i> <span>Available Books</span></a></li>
            <li><a href="borrowed_books.php" class="<?php echo $active_page === 'borrowed_books.php' ? 'active' : ''; ?>"><i class="fas fa-book-reader"></i> <span>Borrowed Books</span></a></li>
            <li><a href="borrow_history.php" class="<?php echo $active_page === 'borrow_history.php' ? 'active' : ''; ?>"><i class="fas fa-history"></i> <span>Borrow History</span></a></li>
            <li><a href="reading_stats.php" class="<?php echo $active_page === 'reading_stats.php' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> <span>Reading Stats</span></a></li>
            <?php if ($is_staff): ?>
                <li><a href="dashboard.php?tab=add_book" class="<?php echo $active_tab === 'add_book' && $active_page === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-plus"></i> <span>Add Book</span></a></li>
                <li><a href="dashboard.php?tab=phone_scanner" class="<?php echo $active_tab === 'phone_scanner' && $active_page === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-mobile-alt"></i> <span>Phone Scanner</span></a></li>
                <li><a href="test_transactions.php" class="<?php echo $active_page === 'test_transactions.php' ? 'active' : ''; ?>"><i class="fas fa-flask"></i> <span>Test Borrow/Return</span></a></li>
            <?php endif; ?>
        </ul>
        <?php if ($is_staff): ?>
            <?php $students_open = $active_page === 'dashboard.php' && ($active_tab === 'students' || $active_tab === 'add_student'); ?>
            <button type="button" id="students-tab" class="sidebar-toggle <?php echo $students_open ? 'active' : ''; ?>" aria-expanded="<?php echo $students_open ? 'true' : 'false'; ?>" aria-controls="students-menu">
                <i class="fas fa-users"></i> <span>Students</span><i class="fas fa-chevron-down toggle-icon" aria-hidden="true"></i>
            </button>
            <ul class="sub-menu" id="students-menu" <?php echo $students_open ? '' : 'hidden'; ?>>
                <li><a href="dashboard.php?tab=add_student" class="<?php echo $active_tab === 'add_student' && $active_page === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-user-plus"></i> <span>Add Student</span></a></li>
                <li><a href="dashboard.php?tab=students" class="<?php echo $active_tab === 'students' && $active_page === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-list"></i> <span>Registered Students</span></a></li>
            </ul>
        <?php endif; ?>
        <?php if ($is_staff): ?>
            <a href="exit_verification.php" class="<?php echo $active_page === 'exit_verification.php' ? 'active' : ''; ?>"><i class="fas fa-door-open"></i> <span>Exit Verification</span></a>
        <?php endif; ?>
        <a href="messages.php" class="<?php echo $active_page === 'messages.php' ? 'active' : ''; ?>" id="messages-link">
            <i class="fas fa-envelope"></i> <span>Messages</span>
            <span class="nav-badge" id="msg-badge" data-count="<?php echo $unread_messages; ?>"><?php echo $unread_messages > 0 ? $unread_messages : ''; ?></span>
        </a>
        <a href="notices.php" class="<?php echo $active_page === 'notices.php' ? 'active' : ''; ?>" id="notices-link">
            <i class="fas fa-bell"></i> <span>Notices</span>
            <span class="nav-badge" id="notif-badge" data-count="<?php echo $unread_count; ?>"><?php echo $unread_count > 0 ? $unread_count : ''; ?></span>
        </a>
        <a href="profile.php" class="<?php echo $active_page === 'profile.php' ? 'active' : ''; ?>"><i class="fas fa-user"></i> <span>Profile</span></a>
    </nav>
    <div class="logout">
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
    </div>
</div>

<script>
    document.querySelectorAll('.sidebar-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            const menu = document.getElementById(toggle.getAttribute('aria-controls'));
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!isOpen));
            menu.hidden = isOpen;
        });
    });

    // Poll unread notification count every 30 seconds
    (function() {
        function updateBadge() {
            fetch('../api/notifications_count.php', { method: 'GET' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var badge = document.getElementById('notif-badge');
                    if (!badge) return;
                    var count = parseInt(data.count) || 0;
                    badge.setAttribute('data-count', count);
                    badge.textContent = count > 0 ? count : '';
                })
                .catch(function() {});
        }
        setInterval(updateBadge, 5000);

        // Poll unread message count every 5 seconds
        function updateMsgBadge() {
            fetch('../api/unread_messages_count.php', { method: 'GET' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var badge = document.getElementById('msg-badge');
                    if (!badge) return;
                    var count = parseInt(data.count) || 0;
                    badge.setAttribute('data-count', count);
                    badge.textContent = count > 0 ? count : '';
                })
                .catch(function() {});
        }
        setInterval(updateMsgBadge, 5000);
    })();
</script>
