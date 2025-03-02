<?php
$user_role = $_SESSION['role'] ?? 'student'; // Default to 'student' instead of 'user'
$active_page = basename($_SERVER['PHP_SELF']);
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
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
        <a href="dashboard.php?tab=books" id="books-tab" class="<?php echo ($active_page === 'dashboard.php' && ($active_tab === 'books' || $active_tab === 'add_book')) || $active_page === 'available_books.php' || $active_page === 'borrowed_books.php' ? 'active' : ''; ?>"><i class="fas fa-book"></i> <span>Books</span></a>
        <ul class="sub-menu" id="books-menu" style="display: <?php echo ($active_page === 'dashboard.php' && ($active_tab === 'books' || $active_tab === 'add_book')) || $active_page === 'available_books.php' || $active_page === 'borrowed_books.php' ? 'block' : 'none'; ?>;">
            <li><a href="available_books.php" class="<?php echo $active_page === 'available_books.php' ? 'active' : ''; ?>"><i class="fas fa-book-open"></i> <span>Available Books</span></a></li>
            <li><a href="borrowed_books.php" class="<?php echo $active_page === 'borrowed_books.php' ? 'active' : ''; ?>"><i class="fas fa-book-reader"></i> <span>Borrowed Books</span></a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="dashboard.php?tab=add_book" class="<?php echo $active_tab === 'add_book' && $active_page === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-plus"></i> <span>Add Book</span></a></li>
            <?php endif; ?>
        </ul>
        <?php if ($user_role === 'admin'): ?>
            <a href="dashboard.php?tab=students" id="students-tab" class="<?php echo $active_page === 'dashboard.php' && ($active_tab === 'students' || $active_tab === 'add_student') ? 'active' : ''; ?>"><i class="fas fa-users"></i> <span>Students</span></a>
            <ul class="sub-menu" id="students-menu" style="display: <?php echo $active_page === 'dashboard.php' && ($active_tab === 'students' || $active_tab === 'add_student') ? 'block' : 'none'; ?>;">
                <li><a href="dashboard.php?tab=add_student" class="<?php echo $active_tab === 'add_student' && $active_page === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-user-plus"></i> <span>Add Student</span></a></li>
            </ul>
        <?php endif; ?>
        <a href="notices.php" class="<?php echo $active_page === 'notices.php' ? 'active' : ''; ?>"><i class="fas fa-bell"></i> <span>Notices</span></a>
        <a href="profile.php" class="<?php echo $active_page === 'profile.php' ? 'active' : ''; ?>"><i class="fas fa-user"></i> <span>Profile</span></a>
    </nav>
    <div class="logout">
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
    </div>
</div>