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

$user_filter = $is_staff ? '' : "AND t.user_id = $user_id";

// Monthly borrows for last 12 months
$monthly = $pdo->query("
    SELECT DATE_FORMAT(t.borrowed_date, '%Y-%m') AS month_key,
           DATE_FORMAT(t.borrowed_date, '%b %y') AS month_label,
           COUNT(*) AS borrow_count
    FROM transactions t
    WHERE t.action = 'BORROW' AND t.borrowed_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    $user_filter
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
")->fetchAll(PDO::FETCH_ASSOC);

$month_labels = [];
$month_data = [];
$all_months = [];
for ($i = 11; $i >= 0; $i--) {
    $d = new DateInterval("P{$i}M");
    $dt = (new DateTime())->sub($d);
    $key = $dt->format('Y-m');
    $all_months[$key] = $dt->format('M y');
}
foreach ($all_months as $key => $label) {
    $month_labels[] = $label;
    $month_data[] = 0;
}
foreach ($monthly as $row) {
    $idx = array_search($row['month_key'], array_keys($all_months));
    if ($idx !== false) {
        $month_data[$idx] = (int)$row['borrow_count'];
    }
}

// Genre breakdown
$genre_stmt = $pdo->query("
    SELECT b.genre, COUNT(*) AS cnt
    FROM transactions t JOIN books b ON t.book_id = b.id
    WHERE t.action = 'BORROW' $user_filter
    GROUP BY b.genre
    ORDER BY cnt DESC
");
$genre_stats = $genre_stmt->fetchAll(PDO::FETCH_ASSOC);
$total_genre_books = array_sum(array_column($genre_stats, 'cnt'));

$genre_colors = [
    'Fiction' => '#6366f1', 'Non-Fiction' => '#f59e0b', 'Science' => '#10b981',
    'History' => '#ef4444', 'Biography' => '#8b5cf6', 'Narrative' => '#ec4899',
    'Technology' => '#3b82f6', 'Education' => '#14b8a6', 'Reference' => '#f97316',
];

// Favorite genre
$fav_genre = !empty($genre_stats) ? $genre_stats[0]['genre'] : 'N/A';

// Total books read
$total_read = $total_genre_books;

// Average days per book
$avg_stmt = $pdo->prepare("
    SELECT AVG(DATEDIFF(t.returned_date, t.borrowed_date))
    FROM transactions t
    WHERE t.action = 'BORROW' AND t.returned_date IS NOT NULL $user_filter
");
$avg_stmt->execute();
$avg_days = round((float)$avg_stmt->fetchColumn());

// Active months count
$active_months = count(array_filter($month_data, fn($v) => $v > 0));

$genre_labels_json = json_encode(array_column($genre_stats, 'genre'));
$genre_values_json = json_encode(array_map('intval', array_column($genre_stats, 'cnt')));
$genre_colors_arr = array_map(function($g) use ($genre_colors) {
    return $genre_colors[$g['genre']] ?? '#6b7280';
}, $genre_stats);
$genre_colors_json = json_encode($genre_colors_arr);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reading Stats - SHS Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 22px 20px;
            box-shadow: var(--shadow-sm); text-align: center;
        }
        .stat-card i { font-size: 1.6rem; color: var(--accent); margin-bottom: 10px; display: block; }
        .stat-card .stat-value { font-size: 2rem; font-weight: 800; color: var(--primary); line-height: 1.1; }
        .stat-card .stat-label { font-size: 0.82rem; color: var(--text-muted); margin-top: 4px; font-weight: 500; }
        .chart-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 24px; margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }
        .chart-card h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        .chart-card h2 i { color: var(--accent); }
        .genre-table { width: 100%; }
        .genre-table td { padding: 10px 12px; vertical-align: middle; }
        .genre-bar-wrap { height: 8px; background: var(--surface-alt); border-radius: 4px; overflow: hidden; flex: 1; }
        .genre-bar { height: 100%; border-radius: 4px; transition: width 0.6s ease; }
        .genre-row { display: flex; align-items: center; gap: 14px; }
        .genre-pct { font-weight: 700; font-size: 0.85rem; min-width: 45px; text-align: right; }
        .genre-name { font-weight: 600; font-size: 0.9rem; min-width: 110px; }
        .genre-cnt { font-size: 0.8rem; color: var(--text-muted); min-width: 40px; }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        <div class="main-content">
            <header>
                <h1><i class="fas fa-chart-line"></i> Reading Stats</h1>
                <p><?php echo $is_staff ? 'Library-wide reading statistics' : 'Your personal reading statistics'; ?></p>
            </header>

            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-book-reader"></i>
                    <div class="stat-value"><?php echo number_format($total_read); ?></div>
                    <div class="stat-label">Total Books Read</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-clock"></i>
                    <div class="stat-value"><?php echo $avg_days; ?>d</div>
                    <div class="stat-label">Avg. Days per Book</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-heart"></i>
                    <div class="stat-value" style="font-size:1.3rem;"><?php echo htmlspecialchars($fav_genre); ?></div>
                    <div class="stat-label">Favorite Genre</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-calendar-check"></i>
                    <div class="stat-value"><?php echo $active_months; ?></div>
                    <div class="stat-label">Active Months</div>
                </div>
            </div>

            <div class="chart-card">
                <h2><i class="fas fa-chart-area"></i> Monthly Reading Activity</h2>
                <canvas id="monthlyChart" height="100"></canvas>
            </div>

            <div class="chart-card">
                <h2><i class="fas fa-palette"></i> Genre Breakdown</h2>
                <?php if (empty($genre_stats)): ?>
                    <div class="verification-empty">
                        <i class="fas fa-chart-pie"></i>
                        <p>No genre data available yet.</p>
                    </div>
                <?php else: ?>
                    <div style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start;">
                        <div style="flex:1;min-width:260px;">
                            <canvas id="genreChart" height="200"></canvas>
                        </div>
                        <div style="flex:1;min-width:260px;">
                            <table class="genre-table">
                                <?php foreach ($genre_stats as $g): ?>
                                <?php
                                    $pct = $total_genre_books > 0 ? round(($g['cnt'] / $total_genre_books) * 100) : 0;
                                    $color = $genre_colors[$g['genre']] ?? '#6b7280';
                                ?>
                                <tr>
                                    <td>
                                        <div class="genre-row">
                                            <span class="genre-name" style="color:<?php echo $color; ?>;"><?php echo htmlspecialchars($g['genre']); ?></span>
                                            <div class="genre-bar-wrap">
                                                <div class="genre-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></div>
                                            </div>
                                            <span class="genre-cnt"><?php echo $g['cnt']; ?></span>
                                            <span class="genre-pct" style="color:<?php echo $color; ?>;"><?php echo $pct; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    var monthlyCtx = document.getElementById('monthlyChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($month_labels); ?>,
                datasets: [{
                    label: 'Books Borrowed',
                    data: <?php echo json_encode($month_data); ?>,
                    borderColor: '#0a1628',
                    backgroundColor: 'rgba(10,22,40,0.08)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointBackgroundColor: '#c9a84c',
                    pointBorderColor: '#0a1628',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#e2e6ef' } },
                    x: { grid: { display: false }, ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 12 } }
                }
            }
        });
    }

    var genreCtx = document.getElementById('genreChart');
    if (genreCtx) {
        new Chart(genreCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo $genre_labels_json; ?>,
                datasets: [{
                    data: <?php echo $genre_values_json; ?>,
                    backgroundColor: <?php echo $genre_colors_json; ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 16, font: { size: 12 } } }
                }
            }
        });
    }
    </script>
</body>
</html>
