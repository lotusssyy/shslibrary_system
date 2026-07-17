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

if (!$is_staff) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$librarian = $stmt->fetch();

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$found_student = null;
$student_borrows = [];
$success_msg = '';
$error_msg = '';

// Handle verification POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['student_id'])) {
    $action = $_POST['action'];
    $sid = (int)$_POST['student_id'];
    if (in_array($action, ['verified', 'flagged']) && $sid > 0) {
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO exit_verifications (student_id, verified_by, status, notes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$sid, $user_id, $action, $notes]);
        $success_msg = $action === 'verified' ? 'Student exit verified successfully.' : 'Issue flagged for this student.';
    }
}

// Handle RFID scan lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rfid_lookup'])) {
    $rfid = trim($_POST['rfid_lookup']);
    if ($rfid !== '') {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, student_id, course, year_level, email FROM users WHERE rfid_number = ? AND role = 'student'");
        $stmt->execute([$rfid]);
        $found_student = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found_student) {
            $search_query = $found_student['student_id'];
        }
    }
}

// Text search
if ($search_query !== '' && !$found_student) {
    $like = '%' . $search_query . '%';
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, student_id, course, year_level, email FROM users WHERE role = 'student' AND (first_name LIKE ? OR last_name LIKE ? OR student_id LIKE ? OR email LIKE ?) LIMIT 1");
    $stmt->execute([$like, $like, $like, $like]);
    $found_student = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch unreturned books if student found
if ($found_student) {
    $stmt = $pdo->prepare(
        "SELECT b.id, b.barcode, b.title, b.author, b.genre,
                t.id AS transaction_id, t.due_date, t.borrowed_date,
                DATEDIFF(t.due_date, NOW()) AS days_left
         FROM transactions t JOIN books b ON t.book_id = b.id
         WHERE t.user_id = ? AND t.action = 'BORROW' AND t.returned_date IS NULL
         ORDER BY t.due_date ASC"
    );
    $stmt->execute([$found_student['id']]);
    $student_borrows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Recent exit log
$stmt = $pdo->query(
    "SELECT ev.*, u.first_name AS student_first, u.last_name AS student_last, u.student_id AS sid,
            l.first_name AS verifier_first, l.last_name AS verifier_last
     FROM exit_verifications ev
     JOIN users u ON ev.student_id = u.id
     JOIN users l ON ev.verified_by = l.id
     ORDER BY ev.verified_at DESC LIMIT 20"
);
$exit_log = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exit Verification - SHS Library System</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
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
                <h1><i class="fas fa-door-open"></i> Exit Verification</h1>
                <p>Verify student books before they leave the library</p>
            </header>

            <!-- Search Section -->
            <section class="verification-search-section">
                <form method="GET" class="student-search-form">
                    <div class="student-search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search by name, student ID, or email..." value="<?php echo htmlspecialchars($search_query); ?>">
                        <button type="submit"><i class="fas fa-search"></i> Search</button>
                    </div>
                </form>

                <div class="verification-divider"><span>or</span></div>

                <form method="POST" id="rfid-verify-form">
                    <input type="hidden" name="rfid_lookup" id="rfid-verify-input">
                    <button type="button" id="scan-nfc-verify-btn" class="scan-btn verification-rfid-btn verification-nfc-btn">
                        <i class="fas fa-mobile-alt"></i> Scan Student RFID with Phone
                    </button>
                    <div id="nfc-verify-status" class="nfc-scan-status"></div>
                </form>
            </section>

            <?php if ($found_student): ?>
            <!-- Student Info Card -->
            <section class="verification-student-card">
                <div class="verification-student-header">
                    <div class="student-avatar">
                        <?php echo strtoupper(substr($found_student['first_name'], 0, 1) . substr($found_student['last_name'], 0, 1)); ?>
                    </div>
                    <div class="verification-student-info">
                        <h2><?php echo htmlspecialchars($found_student['first_name'] . ' ' . $found_student['last_name']); ?></h2>
                        <p>Student ID: <strong><?php echo htmlspecialchars($found_student['student_id']); ?></strong></p>
                        <p><?php echo htmlspecialchars($found_student['course'] ?? 'N/A'); ?> &middot; Year <?php echo htmlspecialchars($found_student['year_level'] ?? 'N/A'); ?></p>
                    </div>
                </div>
            </section>

            <?php if (!empty($student_borrows)): ?>
            <!-- Book Barcode Scanner -->
            <section class="book-scanner-section">
                <h2><i class="fas fa-camera"></i> Scan Book Barcodes</h2>
                <p class="scanner-instruction">Point the phone camera at each book's barcode to verify it belongs to this student.</p>
                <div class="scanner-controls">
                    <button type="button" id="start-scan-btn" class="scan-book-btn">
                        <i class="fas fa-camera"></i> Start Scanning
                    </button>
                    <button type="button" id="stop-scan-btn" class="scan-book-btn scan-book-btn-stop" style="display:none;">
                        <i class="fas fa-stop"></i> Stop Camera
                    </button>
                </div>
                <div id="book-scanner-region" class="book-scanner-region"></div>
                <div id="scan-progress" class="scan-progress" style="display:none;">
                    <span id="scan-progress-text">0 / <?php echo count($student_borrows); ?> books verified</span>
                </div>
                <div id="scanned-results" class="scanned-results"></div>
            </section>
            <?php endif; ?>

            <!-- Currently Borrowed Books -->
            <section class="verification-borrow-section">
                <h2><i class="fas fa-book-reader"></i> Currently Borrowed Books
                    <span class="borrow-count-badge"><?php echo count($student_borrows); ?></span>
                </h2>

                <?php if (empty($student_borrows)): ?>
                    <div class="verification-empty">
                        <i class="fas fa-check-circle"></i>
                        <p>No unregistered books. Student is clear to exit.</p>
                    </div>
                <?php else: ?>
                    <div class="fine-table-wrap">
                        <table class="styled-table" id="borrowed-books-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Book Title</th>
                                    <th>Author</th>
                                    <th>Genre</th>
                                    <th>Borrowed</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($student_borrows as $b): ?>
                                <tr id="book-row-<?php echo htmlspecialchars($b['barcode']); ?>" data-barcode="<?php echo htmlspecialchars($b['barcode']); ?>">
                                    <td class="book-verify-icon"><i class="fas fa-circle"></i></td>
                                    <td><strong><?php echo htmlspecialchars($b['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($b['author']); ?></td>
                                    <td><?php echo htmlspecialchars($b['genre']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($b['borrowed_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($b['due_date'])); ?></td>
                                    <td>
                                        <?php
                                        $days = (int)$b['days_left'];
                                        if ($days < 0) {
                                            echo '<span style="color:var(--danger);font-weight:600;">' . abs($days) . ' day(s) overdue</span>';
                                        } elseif ($days <= 3) {
                                            echo '<span style="color:#d97706;font-weight:600;">' . $days . ' day(s) left</span>';
                                        } else {
                                            echo '<span style="color:var(--success);">' . $days . ' day(s) left</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Verification Actions -->
                    <form method="POST" class="verification-actions">
                        <input type="hidden" name="student_id" value="<?php echo $found_student['id']; ?>">
                        <div class="verification-notes-row">
                            <input type="text" name="notes" id="verification-notes" placeholder="Optional notes (e.g., book condition, missing items)..." class="verification-notes-input">
                        </div>
                        <div class="verification-buttons">
                            <button type="submit" name="action" value="verified" class="verify-btn verify-btn-success">
                                <i class="fas fa-check-circle"></i> All Verified
                            </button>
                            <button type="submit" name="action" value="flagged" class="verify-btn verify-btn-danger">
                                <i class="fas fa-flag"></i> Flag Issue
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <script>
            var borrowedBooks = <?php echo json_encode($student_borrows); ?>;
            </script>
            <?php elseif ($search_query !== ''): ?>
            <div class="verification-empty">
                <i class="fas fa-user-slash"></i>
                <p>No student found matching "<strong><?php echo htmlspecialchars($search_query); ?></strong>".</p>
            </div>
            <?php endif; ?>

            <!-- Exit Log -->
            <section class="exit-log-section">
                <h2><i class="fas fa-clipboard-list"></i> Recent Exit Log</h2>
                <?php if (empty($exit_log)): ?>
                    <div class="verification-empty">
                        <i class="fas fa-inbox"></i>
                        <p>No exit verifications recorded yet.</p>
                    </div>
                <?php else: ?>
                    <div class="fine-table-wrap">
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Student ID</th>
                                    <th>Status</th>
                                    <th>Verified By</th>
                                    <th>Notes</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($exit_log as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['student_first'] . ' ' . $log['student_last']); ?></td>
                                    <td><?php echo htmlspecialchars($log['sid']); ?></td>
                                    <td>
                                        <?php if ($log['status'] === 'verified'): ?>
                                            <span class="status-badge status-verified"><i class="fas fa-check"></i> Verified</span>
                                        <?php else: ?>
                                            <span class="status-badge status-flagged"><i class="fas fa-flag"></i> Flagged</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['verifier_first'] . ' ' . $log['verifier_last']); ?></td>
                                    <td><?php echo htmlspecialchars($log['notes'] ?? '—'); ?></td>
                                    <td><?php echo date('M d, Y g:i A', strtotime($log['verified_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <script>
    (function() {
        // --- Phone NFC scanning (student card) ---
        var nfcBtn = document.getElementById('scan-nfc-verify-btn');
        var rfidInput = document.getElementById('rfid-verify-input');
        var nfcStatus = document.getElementById('nfc-verify-status');

        function normalizeRfid(value) {
            return String(value || '').replace(/[^a-f0-9]/gi, '').toUpperCase();
        }

        function setNfcStatus(message, isError) {
            if (!nfcStatus) return;
            nfcStatus.textContent = message;
            nfcStatus.style.color = isError ? '#b42318' : '#155724';
            nfcStatus.style.display = message ? 'block' : 'none';
        }

        if (nfcBtn && rfidInput) {
            nfcBtn.addEventListener('click', async function() {
                var origText = '<i class="fas fa-mobile-alt"></i> Scan Student RFID with Phone';
                nfcBtn.textContent = 'Checking NFC...';
                setNfcStatus('', false);

                if (!window.isSecureContext) {
                    nfcBtn.innerHTML = origText;
                    setNfcStatus('Web NFC requires HTTPS. Open the secure version of this site.', true);
                    return;
                }

                if (!('NDEFReader' in window)) {
                    nfcBtn.innerHTML = origText;
                    setNfcStatus('Web NFC is not supported by this browser. Use a supported device.', true);
                    return;
                }

                nfcBtn.disabled = true;
                rfidInput.value = '';
                setNfcStatus('NFC is ready. Tap the student school ID card on the phone.', false);

                try {
                    var ndef = new NDEFReader();
                    await ndef.scan();
                    nfcBtn.textContent = 'Waiting for card...';

                    ndef.onreadingerror = function() {
                        nfcBtn.disabled = false;
                        nfcBtn.innerHTML = origText;
                        setNfcStatus('The card could not be read. Try again.', true);
                    };

                    ndef.onreading = function(event) {
                        var rfid = normalizeRfid(event.serialNumber);
                        if (!rfid) {
                            nfcBtn.disabled = false;
                            nfcBtn.innerHTML = origText;
                            setNfcStatus('Card detected, but no usable RFID number was provided.', true);
                            return;
                        }
                        rfidInput.value = rfid;
                        setNfcStatus('RFID detected: ' + rfid + '. Submitting...', false);
                        document.getElementById('rfid-verify-form').submit();
                    };
                } catch (error) {
                    console.error('Web NFC error:', error);
                    nfcBtn.disabled = false;
                    nfcBtn.innerHTML = origText;
                    setNfcStatus('Unable to start NFC: ' + error.message, true);
                }
            });
        }

        // --- Phone camera barcode scanning (book verification) ---
        var startScanBtn = document.getElementById('start-scan-btn');
        var stopScanBtn = document.getElementById('stop-scan-btn');
        var scanProgress = document.getElementById('scan-progress');
        var scanProgressText = document.getElementById('scan-progress-text');
        var scannedResults = document.getElementById('scanned-results');
        var bookScanner = null;
        var verifiedBarcodes = {};
        var totalBooks = (typeof borrowedBooks !== 'undefined') ? borrowedBooks.length : 0;
        var lastScanTime = 0;
        var SCAN_COOLDOWN = 1500;

        function updateProgress() {
            var count = Object.keys(verifiedBarcodes).length;
            if (scanProgressText) {
                scanProgressText.textContent = count + ' / ' + totalBooks + ' books verified';
            }
            if (count >= totalBooks && totalBooks > 0 && scanProgress) {
                scanProgress.classList.add('scan-complete');
                scanProgressText.textContent = 'All ' + totalBooks + ' books verified!';
                stopCamera();
            }
        }

        function addScannedResult(barcode, book, isVerified) {
            if (!scannedResults) return;
            var card = document.createElement('div');
            card.className = 'scanned-book-card ' + (isVerified ? 'verified' : 'unauthorized');
            if (isVerified) {
                card.innerHTML = '<i class="fas fa-check-circle"></i><div><strong>' + escapeHtml(book.title) + '</strong><br><small>' + escapeHtml(book.author) + '</small></div>';
            } else {
                card.innerHTML = '<i class="fas fa-times-circle"></i><div><strong>Not borrowed by this student</strong><br><small>Barcode: ' + escapeHtml(barcode) + '</small></div>';
            }
            scannedResults.prepend(card);
        }

        function highlightRow(barcode, verified) {
            var row = document.getElementById('book-row-' + barcode);
            if (!row) return;
            row.classList.add(verified ? 'row-verified' : 'row-unauthorized');
            var icon = row.querySelector('.book-verify-icon i');
            if (icon) {
                icon.className = verified ? 'fas fa-check-circle' : 'fas fa-times-circle';
                icon.style.color = verified ? 'var(--success)' : 'var(--danger)';
            }
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str || ''));
            return div.innerHTML;
        }

        function handleScannedBarcode(decodedText) {
            var barcode = decodedText.trim();
            var now = Date.now();
            if (now - lastScanTime < SCAN_COOLDOWN) return;
            if (verifiedBarcodes[barcode]) return;
            lastScanTime = now;

            var match = null;
            for (var i = 0; i < borrowedBooks.length; i++) {
                if (borrowedBooks[i].barcode === barcode) {
                    match = borrowedBooks[i];
                    break;
                }
            }

            if (match) {
                verifiedBarcodes[barcode] = true;
                highlightRow(barcode, true);
                addScannedResult(barcode, match, true);
                updateProgress();
            } else {
                verifiedBarcodes[barcode] = false;
                addScannedResult(barcode, { title: 'Unknown Book', author: 'Barcode: ' + barcode }, false);
            }
        }

        function stopCamera() {
            if (bookScanner && bookScanner.isScanning) {
                bookScanner.stop().then(function() {
                    bookScanner.clear();
                    bookScanner = null;
                }).catch(function() {});
            }
            var region = document.getElementById('book-scanner-region');
            if (region) region.style.display = 'none';
            if (startScanBtn) startScanBtn.style.display = '';
            if (stopScanBtn) stopScanBtn.style.display = 'none';
        }

        if (startScanBtn && typeof Html5Qrcode !== 'undefined') {
            startScanBtn.addEventListener('click', function() {
                if (totalBooks === 0) return;

                bookScanner = new Html5Qrcode('book-scanner-region');
                var region = document.getElementById('book-scanner-region');
                region.style.display = 'block';
                startScanBtn.style.display = 'none';
                stopScanBtn.style.display = '';
                if (scanProgress) scanProgress.style.display = '';

                bookScanner.start(
                    { facingMode: 'environment' },
                    { fps: 5, qrbox: { width: 250, height: 100 }, aspectRatio: 1.5 },
                    function(decodedText) {
                        handleScannedBarcode(decodedText);
                    },
                    function() {}
                ).catch(function(err) {
                    console.error('Camera error:', err);
                    startScanBtn.style.display = '';
                    stopScanBtn.style.display = 'none';
                    alert('Could not start camera: ' + err);
                });
            });
        }

        if (stopScanBtn) {
            stopScanBtn.addEventListener('click', function() {
                stopCamera();
            });
        }
    })();
    </script>
</body>
</html>
