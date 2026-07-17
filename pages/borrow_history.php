<?php
include '../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$is_staff = in_array($user_role, ['admin', 'librarian'], true);

$stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where = "t.action = 'BORROW' AND t.returned_date IS NOT NULL";
$params = [];

if (!$is_staff) {
    $where .= " AND t.user_id = :user_id";
    $params[':user_id'] = $user_id;
}

if ($search !== '') {
    $where .= " AND (b.title LIKE :search OR b.author LIKE :search OR b.genre LIKE :search)";
    $params[':search'] = "%$search%";
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions t JOIN books b ON t.book_id = b.id WHERE $where");
$count_stmt->execute($params);
$total_books = $count_stmt->fetchColumn();
$total_pages = ceil($total_books / $per_page);

$stmt = $pdo->prepare("
    SELECT t.id, b.title, b.author, b.genre,
           t.borrowed_date, t.returned_date,
           DATEDIFF(t.returned_date, t.borrowed_date) AS days_kept,
           u.first_name, u.last_name, u.student_id
    FROM transactions t
    JOIN books b ON t.book_id = b.id
    JOIN users u ON t.user_id = u.id
    WHERE $where
    ORDER BY t.returned_date DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrow History - SHS Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        .history-stats { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .history-stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 24px;
            flex: 1;
            min-width: 160px;
            box-shadow: var(--shadow-sm);
        }
        .history-stat-card .stat-value { font-size: 1.8rem; font-weight: 800; color: var(--primary); }
        .history-stat-card .stat-label { font-size: 0.82rem; color: var(--text-muted); margin-top: 4px; font-weight: 500; }
        .history-stat-card i { font-size: 1.4rem; color: var(--accent); margin-bottom: 8px; display: block; }
        .days-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
        }
        .days-badge.good { background: #d1fae5; color: #065f46; }
        .days-badge.ok { background: #fef3c7; color: #92400e; }
        .days-badge.long { background: #e0e7ff; color: #3730a3; }
        .search-filter {
            display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;
        }
        .search-filter input {
            flex: 1; min-width: 200px; padding: 10px 14px;
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            font-size: 0.9rem; font-family: inherit;
        }
        .search-filter button {
            padding: 10px 20px; background: var(--primary); color: white;
            border: none; border-radius: var(--radius-sm); font-weight: 600;
            cursor: pointer; font-family: inherit;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        <div class="main-content">
            <header>
                <h1><i class="fas fa-history"></i> Borrow History</h1>
                <p><?php echo $is_staff ? 'All returned books across students' : 'Your complete borrowing history'; ?></p>
            </header>

            <div class="history-stats">
                <div class="history-stat-card">
                    <i class="fas fa-book-reader"></i>
                    <div class="stat-value"><?php echo number_format($total_books); ?></div>
                    <div class="stat-label">Total Books Read</div>
                </div>
                <?php
                $avg_stmt = $pdo->prepare("
                    SELECT AVG(DATEDIFF(t.returned_date, t.borrowed_date)) AS avg_days
                    FROM transactions t WHERE t.action = 'BORROW' AND t.returned_date IS NOT NULL
                    " . ($is_staff ? '' : " AND t.user_id = $user_id")
                );
                $avg_stmt->execute();
                $avg_days = round((float)$avg_stmt->fetchColumn());
                ?>
                <div class="history-stat-card">
                    <i class="fas fa-clock"></i>
                    <div class="stat-value"><?php echo $avg_days; ?>d</div>
                    <div class="stat-label">Avg. Days per Book</div>
                </div>
            </div>

            <div class="search-filter">
                <form method="GET" style="display:flex;gap:10px;width:100%;flex-wrap:wrap;">
                    <input type="text" name="search" placeholder="Search by title, author, or genre..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit"><i class="fas fa-search"></i> Search</button>
                </form>
            </div>

            <?php if (empty($history)): ?>
                <div class="verification-empty">
                    <i class="fas fa-book-open"></i>
                    <p>No borrowing history found<?php echo $search ? " for '$search'" : ''; ?>.</p>
                </div>
            <?php else: ?>
                <div class="fine-table-wrap">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Genre</th>
                                <?php if ($is_staff): ?>
                                    <th>Student</th>
                                    <th>Student ID</th>
                                <?php endif; ?>
                                <th>Borrowed</th>
                                <th>Returned</th>
                                <th>Days Kept</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($h['title']); ?></strong></td>
                                <td><?php echo htmlspecialchars($h['author']); ?></td>
                                <td><span class="genre-badge"><?php echo htmlspecialchars($h['genre']); ?></span></td>
                                <?php if ($is_staff): ?>
                                    <td><?php echo htmlspecialchars($h['first_name'] . ' ' . $h['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($h['student_id'] ?? 'N/A'); ?></td>
                                <?php endif; ?>
                                <td><?php echo date('M d, Y', strtotime($h['borrowed_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($h['returned_date'])); ?></td>
                                <td>
                                    <?php
                                    $days = (int)$h['days_kept'];
                                    if ($days <= 7) $cls = 'good';
                                    elseif ($days <= 14) $cls = 'ok';
                                    else $cls = 'long';
                                    ?>
                                    <span class="days-badge <?php echo $cls; ?>"><?php echo $days; ?> day<?php echo $days !== 1 ? 's' : ''; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>">Previous</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
