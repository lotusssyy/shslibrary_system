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

$success_msg = '';
$error_msg = '';

// Handle send message POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($subject === '' || $body === '') {
        $error_msg = 'Subject and message are required.';
    } elseif ($receiver_id <= 0) {
        $error_msg = 'Please select a recipient.';
    } else {
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
        $stmt->execute([$receiver_id]);
        $receiver = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$receiver) {
            $error_msg = 'Recipient not found.';
        } elseif ($is_staff || $receiver['role'] === 'librarian') {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, subject, body) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $receiver_id, $subject, $body]);
            $success_msg = 'Message sent successfully.';
        } else {
            $error_msg = 'Students can only message librarians.';
        }
    }
}

// Fetch librarians for student compose
$librarians = [];
if (!$is_staff) {
    $librarians = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'librarian' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch students for librarian compose
$students = [];
if ($is_staff) {
    $students = $pdo->query("SELECT id, first_name, last_name, student_id FROM users WHERE role = 'student' ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get current view state
$view_user = isset($_GET['view_user']) ? (int)$_GET['view_user'] : 0;
$view_subject = isset($_GET['view_subject']) ? trim($_GET['view_subject']) : '';
$compose = isset($_GET['compose']) ? (int)$_GET['compose'] : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - SHS Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>
        <div class="main-content">
            <?php if ($success_msg): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <header>
                <h1><i class="fas fa-envelope"></i> Messages</h1>
                <p><?php echo $is_staff ? 'Communicate with students' : 'Contact the librarian for any concerns'; ?></p>
            </header>

            <div class="msg-layout">
                <!-- Left: Conversation List -->
                <div class="msg-sidebar">
                    <div class="msg-sidebar-header">
                        <h3>Conversations</h3>
                        <button type="button" class="msg-compose-btn" id="new-msg-btn">
                            <i class="fas fa-pen"></i> New
                        </button>
                    </div>
                    <div class="msg-list" id="msg-list">
                        <div class="msg-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                    </div>
                </div>

                <!-- Right: Thread / Compose -->
                <div class="msg-main" id="msg-main">
                    <?php if ($compose): ?>
                        <!-- New Message Form -->
                        <div class="msg-thread-header" id="compose-header">
                            <h3><i class="fas fa-pen"></i> New Message</h3>
                        </div>
                        <div class="msg-compose-area" id="compose-area" style="flex:1;padding:20px;">
                            <form method="POST" id="compose-form">
                                <input type="hidden" name="send_message" value="1">
                                <div class="form-group">
                                    <label>To:</label>
                                    <?php if ($is_staff): ?>
                                        <select name="receiver_id" required style="width:100%;padding:10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;font-size:0.9rem;">
                                            <option value="">Select a student...</option>
                                            <?php foreach ($students as $s): ?>
                                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name'] . ' (' . $s['student_id'] . ')'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <select name="receiver_id" required style="width:100%;padding:10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;font-size:0.9rem;">
                                            <?php foreach ($librarians as $l): ?>
                                                <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['first_name'] . ' ' . $l['last_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                <div class="form-group">
                                    <label>Subject:</label>
                                    <input type="text" name="subject" required placeholder="e.g., Book Return Question" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;font-size:0.9rem;">
                                </div>
                                <div class="form-group">
                                    <label>Message:</label>
                                    <textarea name="body" required rows="5" placeholder="Type your message..." style="width:100%;padding:10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;font-size:0.9rem;resize:vertical;"></textarea>
                                </div>
                                <div style="display:flex;gap:10px;margin-top:16px;">
                                    <button type="submit" class="verify-btn verify-btn-success"><i class="fas fa-paper-plane"></i> Send Message</button>
                                    <a href="messages.php" class="verify-btn" style="background:var(--surface-alt);color:var(--text);text-decoration:none;display:inline-flex;align-items:center;gap:6px;">Cancel</a>
                                </div>
                            </form>
                        </div>
                    <?php elseif ($view_user > 0 && $view_subject !== ''): ?>
                        <!-- Thread View (loaded via JS) -->
                        <div class="msg-thread-header" id="thread-header">
                            <button type="button" class="msg-back-btn" id="back-btn"><i class="fas fa-arrow-left"></i></button>
                            <div id="thread-title">Loading...</div>
                        </div>
                        <div class="msg-thread" id="msg-thread"></div>
                        <div class="msg-reply-bar" id="reply-bar">
                            <input type="text" id="reply-input" placeholder="Type a reply..." autocomplete="off">
                            <button type="button" id="reply-send-btn" class="msg-send-btn"><i class="fas fa-paper-plane"></i></button>
                        </div>
                    <?php else: ?>
                        <!-- Empty state -->
                        <div class="msg-empty" id="msg-empty">
                            <i class="fas fa-envelope-open"></i>
                            <h3>No conversation selected</h3>
                            <p>Select a conversation from the left or start a new message.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var userId = <?php echo $user_id; ?>;
        var isStaff = <?php echo $is_staff ? 'true' : 'false'; ?>;
        var viewUser = <?php echo $view_user; ?>;
        var viewSubject = <?php echo json_encode($view_subject); ?>;
        var msgList = document.getElementById('msg-list');
        var msgMain = document.getElementById('msg-main');
        var lastMsgCount = 0;
        var lastConvosHash = '';

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str || ''));
            return div.innerHTML;
        }

        function timeAgo(dateStr) {
            var now = new Date();
            var then = new Date(dateStr);
            var diff = Math.floor((now - then) / 1000);
            if (diff < 60) return diff + 's ago';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
            return then.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }

        function updateMsgBadge() {
            fetch('../api/unread_messages_count.php')
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

        function playNotifSound() {
            try {
                var ctx = new (window.AudioContext || window.webkitAudioContext)();
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = 800;
                osc.type = 'sine';
                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.3);
            } catch (e) {}
        }

        function isAtBottom(el) {
            return el.scrollHeight - el.scrollTop - el.clientHeight < 60;
        }

        function loadConversations(callback) {
            fetch('../api/messages.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var convos = data.conversations || [];
                    var hash = JSON.stringify(convos.map(function(c) { return c.subject + '|' + c.other_user_id + '|' + c.unread_count + '|' + c.last_message; }));
                    var changed = hash !== lastConvosHash;
                    lastConvosHash = hash;

                    if (convos.length === 0) {
                        msgList.innerHTML = '<div class="msg-empty-small"><i class="fas fa-inbox"></i><p>No messages yet.</p></div>';
                        if (callback) callback(false);
                        return;
                    }
                    var html = '';
                    for (var i = 0; i < convos.length; i++) {
                        var c = convos[i];
                        var hasUnread = parseInt(c.unread_count) > 0;
                        var activeClass = (parseInt(c.other_user_id) === viewUser && c.subject === viewSubject) ? ' active' : '';
                        html += '<a href="messages.php?view_user=' + c.other_user_id + '&view_subject=' + encodeURIComponent(c.subject) + '" class="msg-conversation' + activeClass + '">';
                        html += '<div class="msg-conv-avatar">' + escapeHtml(c.first_name.charAt(0) + c.last_name.charAt(0)) + '</div>';
                        html += '<div class="msg-conv-info">';
                        html += '<div class="msg-conv-name">' + escapeHtml(c.first_name + ' ' + c.last_name) + (c.other_role === 'librarian' ? ' <span class="msg-role-badge">Staff</span>' : '') + '</div>';
                        html += '<div class="msg-conv-subject">' + escapeHtml(c.subject) + '</div>';
                        html += '<div class="msg-conv-preview">' + escapeHtml(c.last_message).substring(0, 50) + '</div>';
                        html += '</div>';
                        html += '<div class="msg-conv-meta">';
                        html += '<div class="msg-conv-time">' + timeAgo(c.last_time) + '</div>';
                        if (hasUnread) html += '<div class="msg-conv-unread">' + c.unread_count + '</div>';
                        html += '</div>';
                        html += '</a>';
                    }
                    msgList.innerHTML = html;
                    if (callback) callback(changed);
                })
                .catch(function() {
                    msgList.innerHTML = '<div class="msg-empty-small"><i class="fas fa-exclamation-triangle"></i><p>Failed to load messages.</p></div>';
                });
        }

        function loadThread(otherId, subject) {
            var thread = document.getElementById('msg-thread');
            var title = document.getElementById('thread-title');
            if (!thread) return;

            var wasAtBottom = isAtBottom(thread);
            var isInitialLoad = lastMsgCount === 0;

            title.textContent = subject;

            if (isInitialLoad) {
                thread.innerHTML = '<div class="msg-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            }

            fetch('../api/messages.php?user_id=' + otherId + '&subject=' + encodeURIComponent(subject))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var msgs = data.messages || [];
                    var newMsgCount = msgs.length;
                    var hasNew = newMsgCount > lastMsgCount && lastMsgCount > 0;

                    if (msgs.length === 0) {
                        thread.innerHTML = '<div class="msg-empty-small"><p>No messages in this conversation.</p></div>';
                        lastMsgCount = 0;
                        return;
                    }

                    var html = '';
                    for (var i = 0; i < msgs.length; i++) {
                        var m = msgs[i];
                        var isMine = (parseInt(m.sender_id) === userId);
                        var cls = isMine ? ' msg-bubble-sent' : ' msg-bubble-received';
                        var isNew = hasNew && i >= lastMsgCount;
                        if (isNew) cls += ' msg-bubble-new';
                        html += '<div class="msg-bubble' + cls + '">';
                        html += '<div class="msg-bubble-sender">' + escapeHtml(m.sender_first + ' ' + m.sender_last) + ' <span class="msg-role-tag">' + escapeHtml(m.sender_role) + '</span></div>';
                        html += '<div class="msg-bubble-body">' + escapeHtml(m.body).replace(/\n/g, '<br>') + '</div>';
                        html += '<div class="msg-bubble-time">' + timeAgo(m.created_at) + '</div>';
                        html += '</div>';
                    }
                    thread.innerHTML = html;

                    if (isInitialLoad || wasAtBottom || isMine) {
                        thread.scrollTop = thread.scrollHeight;
                    }

                    if (hasNew && !isMine) {
                        playNotifSound();
                    }

                    lastMsgCount = newMsgCount;
                    updateMsgBadge();
                });
        }

        // Initial load
        if (viewUser > 0 && viewSubject) {
            loadThread(viewUser, viewSubject);
        }
        loadConversations();
        updateMsgBadge();

        // New message button
        var newBtn = document.getElementById('new-msg-btn');
        if (newBtn) {
            newBtn.addEventListener('click', function() {
                window.location.href = 'messages.php?compose=1';
            });
        }

        // Back button
        var backBtn = document.getElementById('back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                window.location.href = 'messages.php';
            });
        }

        // Reply send
        var replyInput = document.getElementById('reply-input');
        var replySendBtn = document.getElementById('reply-send-btn');
        if (replySendBtn && replyInput) {
            function sendReply() {
                var body = replyInput.value.trim();
                if (!body || !viewUser || !viewSubject) return;
                replySendBtn.disabled = true;
                fetch('../api/send_message.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ receiver_id: viewUser, subject: viewSubject, body: body })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === 'ok') {
                        replyInput.value = '';
                        loadThread(viewUser, viewSubject);
                        loadConversations();
                        updateMsgBadge();
                    } else {
                        alert(data.error || 'Failed to send message.');
                    }
                })
                .catch(function() { alert('Network error. Please try again.'); })
                .finally(function() { replySendBtn.disabled = false; });
            }
            replySendBtn.addEventListener('click', sendReply);
            replyInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendReply(); }
            });
        }

        // REAL-TIME POLLING: Thread every 3s, conversations every 5s, badge every 5s
        setInterval(function() {
            if (viewUser > 0 && viewSubject) {
                loadThread(viewUser, viewSubject);
            }
        }, 3000);

        setInterval(function() {
            loadConversations();
            updateMsgBadge();
        }, 5000);
    })();
    </script>
</body>
</html>
