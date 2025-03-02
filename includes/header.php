<div class="sidebar">
    <h2>SHS Library</h2>
    <nav>
        <a href="dashboard.php" <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : '' ?>>Dashboard</a>
        <a href="books.php" <?= basename($_SERVER['PHP_SELF']) == 'books.php' ? 'class="active"' : '' ?>>Available Books</a>
        <a href="borrowed.php" <?= basename($_SERVER['PHP_SELF']) == 'borrowed.php' ? 'class="active"' : '' ?>>Borrowed Books</a>
        <a href="notices.php" <?= basename($_SERVER['PHP_SELF']) == 'notices.php' ? 'class="active"' : '' ?>>Notice</a>
        <a href="profile.php" <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'class="active"' : '' ?>>Profile</a>
    </nav>
</div>
