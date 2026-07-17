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

// Fetch notices for the user with read status
$stmt = $pdo->prepare("SELECT id, message, read_status, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$notices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread
$unread_count = 0;
foreach ($notices as $n) {
    if (!$n['read_status']) $unread_count++;
}

function timeAgo($datetime) {
    $now = time();
    $then = strtotime($datetime);
    if (!$then) return 'Just now';
    $diff = $now - $then;
    if ($diff < 0) return 'Just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $then);
}
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
        .notices-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .notices-toolbar .unread-info {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        .notices-toolbar .unread-info strong {
            color: var(--danger);
        }
        .mark-read-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all var(--transition);
        }
        .mark-read-btn:hover {
            background: var(--accent);
            color: var(--primary);
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        .mark-read-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .notices-list {
            max-width: 800px;
            margin: 0 auto;
        }

        .notice-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: var(--surface);
            border-radius: var(--radius);
            padding: 18px 20px;
            margin-bottom: 12px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            border-left: 4px solid var(--border);
            transition: all var(--transition);
            cursor: default;
        }
        .notice-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        .notice-item.unread {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-left-color: var(--accent);
        }
        .notice-item.unread .notice-icon {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-hover) 100%);
            color: var(--primary);
        }

        .notice-icon {
            flex-shrink: 0;
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--surface-alt) 0%, #e8ecf4 100%);
            color: var(--text-muted);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .notice-body {
            flex: 1;
            min-width: 0;
        }
        .notice-message {
            font-size: 0.9rem;
            color: var(--text);
            line-height: 1.6;
            margin-bottom: 6px;
        }
        .notice-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.78rem;
            color: var(--text-muted);
        }
        .notice-meta i {
            font-size: 0.7rem;
        }
        .unread-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--danger);
            flex-shrink: 0;
            margin-top: 8px;
            box-shadow: 0 0 6px rgba(229,72,77,0.5);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state i {
            font-size: 3rem;
            color: var(--border);
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 1.1rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 6px;
        }
        .empty-state p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
            <header>
                <h1><i class="fas fa-bell" style="margin-right:10px; opacity:0.8;"></i> Notices</h1>
                <p>Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>!</p>
            </header>

            <div class="notices-toolbar">
                <span class="unread-info">
                    <?php if ($unread_count > 0): ?>
                        You have <strong><?php echo $unread_count; ?></strong> unread notification<?php echo $unread_count !== 1 ? 's' : ''; ?>
                    <?php else: ?>
                        All caught up — no unread notifications
                    <?php endif; ?>
                </span>
                <button class="mark-read-btn" id="mark-all-read" <?php echo $unread_count === 0 ? 'disabled' : ''; ?>>
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>
            </div>

            <section class="notices-list" id="notices-list">
                <?php if (empty($notices)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <h3>No notifications yet</h3>
                        <p>You'll see borrow/return updates and library announcements here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notices as $notice): ?>
                        <div class="notice-item <?php echo !$notice['read_status'] ? 'unread' : ''; ?>" data-id="<?php echo $notice['id']; ?>">
                            <?php if (!$notice['read_status']): ?>
                                <div class="unread-dot"></div>
                            <?php endif; ?>
                            <div class="notice-icon">
                                <i class="fas fa-bell"></i>
                            </div>
                            <div class="notice-body">
                                <div class="notice-message"><?php echo htmlspecialchars($notice['message']); ?></div>
                                <div class="notice-meta">
                                    <span><i class="fas fa-clock"></i> <?php echo timeAgo($notice['created_at']); ?></span>
                                    <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y \a\t h:i A', strtotime($notice['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <script>
        document.getElementById('mark-all-read').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking...';

            fetch('../api/mark_notifications_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    // Remove unread styling from all items
                    document.querySelectorAll('.notice-item.unread').forEach(function(item) {
                        item.classList.remove('unread');
                        var dot = item.querySelector('.unread-dot');
                        if (dot) dot.remove();
                    });
                    // Update badge in sidebar
                    var badge = document.getElementById('notif-badge');
                    if (badge) {
                        badge.setAttribute('data-count', '0');
                        badge.textContent = '';
                    }
                    // Update toolbar text
                    var info = document.querySelector('.unread-info');
                    if (info) info.innerHTML = 'All caught up — no unread notifications';
                    btn.innerHTML = '<i class="fas fa-check-double"></i> Mark All as Read';
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-double"></i> Mark All as Read';
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-double"></i> Mark All as Read';
            });
        });

        // Dynamic notice refreshing
        function fetchNotices() {
            fetch('fetch_notices.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'user_id=<?php echo $user_id; ?>'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var list = document.getElementById('notices-list');
                if (data.length === 0) {
                    list.innerHTML = '<div class="empty-state"><i class="fas fa-bell-slash"></i><h3>No notifications yet</h3><p>You\'ll see borrow/return updates and library announcements here.</p></div>';
                } else {
                    var html = '';
                    data.forEach(function(notice) {
                        var unreadClass = notice.read_status == 0 ? ' unread' : '';
                        var dot = notice.read_status == 0 ? '<div class="unread-dot"></div>' : '';
                        var iconBg = notice.read_status == 0 ? ' style="background:linear-gradient(135deg,var(--accent) 0%,var(--accent-hover) 100%);color:var(--primary);"' : '';
                        html += '<div class="notice-item' + unreadClass + '" data-id="' + notice.id + '">' +
                            dot +
                            '<div class="notice-icon"' + iconBg + '><i class="fas fa-bell"></i></div>' +
                            '<div class="notice-body">' +
                            '<div class="notice-message">' + notice.message + '</div>' +
                            '<div class="notice-meta">' +
                            '<span><i class="fas fa-clock"></i> ' + notice.time_ago + '</span>' +
                            '<span><i class="fas fa-calendar"></i> ' + notice.created_at + '</span>' +
                            '</div></div></div>';
                    });
                    list.innerHTML = html;
                }
            })
            .catch(function() {});
        }

        fetchNotices();
        setInterval(fetchNotices, 15000);

        // Auto-mark all as read on page load
        fetch('../api/mark_notifications_read.php', { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    document.querySelectorAll('.notice-item.unread').forEach(function(item) {
                        item.classList.remove('unread');
                        var dot = item.querySelector('.unread-dot');
                        if (dot) dot.remove();
                    });
                    var badge = document.getElementById('notif-badge');
                    if (badge) { badge.setAttribute('data-count', '0'); badge.textContent = ''; }
                    var info = document.querySelector('.unread-info');
                    if (info) info.innerHTML = 'All caught up — no unread notifications';
                }
            })
            .catch(function() {});
    </script>
</body>
</html>
