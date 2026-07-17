<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// Initialize CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';
$is_staff = in_array($user_role, ['admin', 'librarian'], true);

// Initialize messages
$success_message = '';
$error_message = '';

// Fetch user details
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
    $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $error_message = "User not found.";
        error_log("User ID $user_id not found in users table.");
    }
} catch (PDOException $e) {
    $error_message = "Database error: Unable to fetch user details.";
    error_log("User fetch error: " . $e->getMessage());
}

// Fetch data for student dashboard
$borrowed_count = 0;
$due_soon_count = 0;
$recent_books = [];
$books_borrowed_month = 0;
$total_books_available = 0;
$due_books = [];
$total_borrowed_alltime = 0;
$total_borrowed_year = 0;
$genre_stats = [];
$recommended_books = [];
$overdue_count = 0;

if ($user_role === 'student') {
    try {
        // Count borrowed books
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND returned_date IS NULL");
        $stmt->execute([$user_id]);
        $borrowed_count = $stmt->fetchColumn();

        // Count books due soon (due_date within the next 7 days)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND returned_date IS NULL AND due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$user_id]);
        $due_soon_count = $stmt->fetchColumn();

        // Count overdue books
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND returned_date IS NULL AND due_date < NOW()");
        $stmt->execute([$user_id]);
        $overdue_count = $stmt->fetchColumn();

        // Fetch currently borrowed books with due dates
        $stmt = $pdo->prepare("SELECT t.id, t.due_date, b.title, b.author, b.genre, DATEDIFF(t.due_date, NOW()) AS days_left FROM transactions t JOIN books b ON t.book_id = b.id WHERE t.user_id = ? AND t.action = 'BORROW' AND t.returned_date IS NULL ORDER BY t.due_date ASC");
        $stmt->execute([$user_id]);
        $due_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch recent books
        $stmt = $pdo->prepare("SELECT id, title, author FROM books ORDER BY id DESC LIMIT 6");
        $stmt->execute();
        $recent_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Books borrowed this month
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND YEAR(borrowed_date) = YEAR(NOW()) AND MONTH(borrowed_date) = MONTH(NOW())");
        $stmt->execute([$user_id]);
        $books_borrowed_month = $stmt->fetchColumn();

        // Total books available
        $stmt = $pdo->query("SELECT SUM(available) FROM books");
        $total_books_available = $stmt->fetchColumn() ?: 0;

        // Reading stats — all-time borrows
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW'");
        $stmt->execute([$user_id]);
        $total_borrowed_alltime = $stmt->fetchColumn();

        // Reading stats — this year borrows
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND YEAR(borrowed_date) = YEAR(NOW())");
        $stmt->execute([$user_id]);
        $total_borrowed_year = $stmt->fetchColumn();

        // Genre breakdown for student
        $stmt = $pdo->prepare("SELECT b.genre, COUNT(*) AS cnt FROM transactions t JOIN books b ON t.book_id = b.id WHERE t.user_id = ? AND t.action = 'BORROW' GROUP BY b.genre ORDER BY cnt DESC");
        $stmt->execute([$user_id]);
        $genre_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Book recommendations: find top 2 genres, suggest books not yet borrowed
        if (!empty($genre_stats)) {
            $top_genres = array_slice($genre_stats, 0, 2);
            $genre_names = array_column($top_genres, 'genre');
            $placeholders = implode(',', array_fill(0, count($genre_names), '?'));
            $stmt = $pdo->prepare("SELECT b.id, b.title, b.author, b.genre FROM books b WHERE b.genre IN ($placeholders) AND b.id NOT IN (SELECT book_id FROM transactions WHERE user_id = ? AND action = 'BORROW') AND b.available > 0 ORDER BY RAND() LIMIT 6");
            $params = array_merge($genre_names, [$user_id]);
            $stmt->execute($params);
            $recommended_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Student fines — unreturned overdue books
        $stmt = $pdo->prepare(
            "SELECT t.id, t.due_date, b.title, b.author,
                    DATEDIFF(CURDATE(), t.due_date) AS days_overdue,
                    ROUND(DATEDIFF(CURDATE(), t.due_date) * 5.00, 2) AS fine_amount
             FROM transactions t JOIN books b ON t.book_id = b.id
             WHERE t.user_id = ? AND t.action = 'BORROW' AND t.returned_date IS NULL AND t.due_date < CURDATE()
             ORDER BY t.due_date ASC"
        );
        $stmt->execute([$user_id]);
        $student_overdue_fines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Student fines — returned late (historical)
        $stmt = $pdo->prepare(
            "SELECT t.id, t.due_date, t.returned_date, b.title,
                    DATEDIFF(t.returned_date, t.due_date) AS days_overdue,
                    ROUND(DATEDIFF(t.returned_date, t.due_date) * 5.00, 2) AS fine_amount
             FROM transactions t JOIN books b ON t.book_id = b.id
             WHERE t.user_id = ? AND t.action = 'BORROW' AND t.returned_date IS NOT NULL AND t.returned_date > t.due_date
             ORDER BY t.returned_date DESC LIMIT 5"
        );
        $stmt->execute([$user_id]);
        $student_history_fines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_outstanding_fines = array_sum(array_column($student_overdue_fines, 'fine_amount'));
        $total_paid_fines = array_sum(array_column($student_history_fines, 'fine_amount'));
    } catch (PDOException $e) {
        $error_message = "Database error: Unable to fetch student data.";
        error_log("Student data fetch error: " . $e->getMessage());
    }
}

// Dynamic greeting based on server's default timezone
$hour = (int) date('H');
$greeting = $hour < 12 ? "Good Morning" : ($hour < 18 ? "Good Afternoon" : "Good Evening");

// Librarian dashboard data
$librarian_overdue_books = [];
$librarian_active_borrowers = 0;
$librarian_popular_books = [];
$librarian_genre_dist = [];
$student_search_results = [];
$student_search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($is_staff) {
    try {
        // Overdue books
        $stmt = $pdo->prepare("SELECT t.id, t.due_date, u.first_name, u.last_name, u.student_id, b.title, b.author, DATEDIFF(NOW(), t.due_date) AS days_overdue FROM transactions t JOIN users u ON t.user_id = u.id JOIN books b ON t.book_id = b.id WHERE t.action = 'BORROW' AND t.returned_date IS NULL AND t.due_date < NOW() ORDER BY t.due_date ASC");
        $stmt->execute();
        $librarian_overdue_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Active borrowers count
        $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM transactions WHERE action = 'BORROW' AND returned_date IS NULL");
        $librarian_active_borrowers = $stmt->fetchColumn();

        // Popular books (top 10 most borrowed this year)
        $stmt = $pdo->prepare("SELECT b.title, b.author, COUNT(*) AS borrow_count FROM transactions t JOIN books b ON t.book_id = b.id WHERE t.action = 'BORROW' AND YEAR(t.borrowed_date) = YEAR(NOW()) GROUP BY b.id, b.title, b.author ORDER BY borrow_count DESC LIMIT 10");
        $stmt->execute();
        $librarian_popular_books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Genre distribution
        $stmt = $pdo->query("SELECT genre, COUNT(*) AS book_count FROM books GROUP BY genre ORDER BY book_count DESC");
        $librarian_genre_dist = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Student search
        if ($student_search_query !== '') {
            $like = '%' . $student_search_query . '%';
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, student_id, course, year_level, email FROM users WHERE role = 'student' AND (first_name LIKE ? OR last_name LIKE ? OR student_id LIKE ? OR email LIKE ?) ORDER BY last_name, first_name LIMIT 10");
            $stmt->execute([$like, $like, $like, $like]);
            $student_search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch borrowed books for each found student
            foreach ($student_search_results as &$s) {
                $stmt2 = $pdo->prepare("SELECT b.title, b.author, t.due_date, DATEDIFF(t.due_date, NOW()) AS days_left FROM transactions t JOIN books b ON t.book_id = b.id WHERE t.user_id = ? AND t.action = 'BORROW' AND t.returned_date IS NULL ORDER BY t.due_date ASC");
                $stmt2->execute([$s['id']]);
                $s['borrowed_books'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($s);
        }

        // Librarian fines summary — all students with overdue books
        $stmt = $pdo->query(
            "SELECT u.id, u.first_name, u.last_name, u.student_id,
                    COUNT(*) AS overdue_count,
                    SUM(ROUND(DATEDIFF(CURDATE(), t.due_date) * 5.00, 2)) AS total_fine
             FROM transactions t JOIN users u ON t.user_id = u.id
             WHERE t.action = 'BORROW' AND t.returned_date IS NULL AND t.due_date < CURDATE()
             GROUP BY u.id, u.first_name, u.last_name, u.student_id
             ORDER BY total_fine DESC"
        );
        $librarian_fines_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $librarian_total_fines = array_sum(array_column($librarian_fines_summary, 'total_fine'));
    } catch (PDOException $e) {
        error_log("Librarian dashboard data error: " . $e->getMessage());
    }
}

function normalizeRfidNumber(string $value): string
{
    return strtoupper(preg_replace('/[^A-F0-9]/i', '', trim($value)) ?? '');
}

function bookNumberPrefix(string $genre): string
{
    $prefixes = [
        'FICTION' => 'FIC',
        'NON-FICTION' => 'NF',
        'SCIENCE' => 'SCI',
        'HISTORY' => 'HIS',
        'BIOGRAPHY' => 'BIO',
        'NARRATIVE' => 'NAR'
    ];

    $normalized = strtoupper(trim($genre));
    if (isset($prefixes[$normalized])) {
        return $prefixes[$normalized];
    }

    $fallback = strtoupper(preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '');
    return substr($fallback ?: 'BK', 0, 3);
}

function generateBookNumbers(PDO $pdo, string $genre, int $count): array
{
    $prefix = bookNumberPrefix($genre);
    $year = date('Y');
    $pattern = $prefix . '-' . $year . '-%';
    $query = $pdo->prepare("SELECT book_number FROM books WHERE book_number LIKE ?");
    $query->execute([$pattern]);

    $max_sequence = 0;
    $regex = '/^' . preg_quote($prefix . '-' . $year . '-', '/') . '(\d+)$/';
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $book_number) {
        if (preg_match($regex, (string) $book_number, $matches)) {
            $max_sequence = max($max_sequence, (int) $matches[1]);
        }
    }

    $book_numbers = [];
    for ($i = 1; $i <= $count; $i++) {
        $book_numbers[] = sprintf('%s-%s-%04d', $prefix, $year, $max_sequence + $i);
    }

    return $book_numbers;
}

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_staff) {
    // Add student
    if (isset($_POST['add_student'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $error_message = "CSRF token validation failed.";
        } else {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = password_hash(trim($_POST['password'] ?? ''), PASSWORD_DEFAULT);
        // Keep the RFID format consistent with the Arduino reader: uppercase hexadecimal, no separators.
        $rfid_number = strtoupper(preg_replace('/[^A-F0-9]/i', '', trim($_POST['rfid_number'] ?? '')));
        $student_id = trim($_POST['student_id'] ?? '');
        $course = trim($_POST['course'] ?? '');
        $year_level = isset($_POST['year_level']) ? (int) trim($_POST['year_level']) : 0;

        try {
            if ($rfid_number === '') {
                $error_message = "Please scan a valid school ID RFID card.";
            } else {
                $check_rfid_stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE rfid_number = ? LIMIT 1");
                $check_rfid_stmt->execute([$rfid_number]);
                $rfid_owner = $check_rfid_stmt->fetch(PDO::FETCH_ASSOC);

                $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE student_id = ?");
                $check_stmt->execute([$student_id]);
                if ($rfid_owner) {
                    $error_message = "This RFID card is already assigned to {$rfid_owner['first_name']} {$rfid_owner['last_name']}.";
                } elseif ($check_stmt->fetchColumn() > 0) {
                $error_message = "Student ID '$student_id' already exists. Please use a unique ID.";
                } else {
                    $valid_year_levels = [11, 12];
                    if ($year_level === 0 || !in_array($year_level, $valid_year_levels)) {
                        $error_message = "Invalid year level selected. Please choose Grade 11 or Grade 12.";
                        error_log("Validation failed: Invalid year_level: '$year_level'");
                    } else {
                        $query = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password, rfid_number, student_id, course, year_level, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'student')");
                        $query->execute([$first_name, $last_name, $email, $password, $rfid_number, $student_id, $course, $year_level]);
                        $history_query = $pdo->prepare("INSERT INTO rfid_card_history (user_id, rfid_number, status, assigned_by) VALUES (?, ?, 'active', ?)");
                        $history_query->execute([$pdo->lastInsertId(), $rfid_number, $user_id]);
                        $success_message = "Student added successfully.";
                    }
                }
            }
        } catch (PDOException $e) {
            $error_message = "Error adding student: " . $e->getMessage();
            error_log($error_message);
        }
        }
    }

    // Revoke a student's active RFID card.
    if (isset($_POST['revoke_rfid'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $error_message = "CSRF token validation failed.";
        } else {
            $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            try {
                $pdo->beginTransaction();
                $student_query = $pdo->prepare("SELECT id, rfid_number FROM users WHERE id = ? AND role = 'student' FOR UPDATE");
                $student_query->execute([$target_user_id]);
                $student = $student_query->fetch(PDO::FETCH_ASSOC);
                if (!$student || empty($student['rfid_number'])) {
                    throw new Exception("Student does not have an active RFID card.");
                }

                $history_query = $pdo->prepare("UPDATE rfid_card_history SET status = 'revoked', revoked_at = NOW(), revoked_by = ? WHERE user_id = ? AND rfid_number = ? AND status = 'active'");
                $history_query->execute([$user_id, $student['id'], $student['rfid_number']]);
                $update_query = $pdo->prepare("UPDATE users SET rfid_number = NULL WHERE id = ?");
                $update_query->execute([$student['id']]);
                $pdo->commit();
                $success_message = "RFID card revoked successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_message = "Unable to revoke RFID card: " . $e->getMessage();
                error_log($error_message);
            }
        }
    }

    // Replace a student's RFID card while preserving the old card in history.
    if (isset($_POST['replace_rfid'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $error_message = "CSRF token validation failed.";
        } else {
            $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $new_rfid = normalizeRfidNumber($_POST['rfid_number'] ?? '');
            try {
                if ($new_rfid === '') throw new Exception("Please scan a valid replacement RFID card.");

                $pdo->beginTransaction();
                $student_query = $pdo->prepare("SELECT id, rfid_number FROM users WHERE id = ? AND role = 'student' FOR UPDATE");
                $student_query->execute([$target_user_id]);
                $student = $student_query->fetch(PDO::FETCH_ASSOC);
                if (!$student) throw new Exception("Student not found.");

                $duplicate_query = $pdo->prepare("SELECT id FROM users WHERE rfid_number = ? AND id <> ? LIMIT 1");
                $duplicate_query->execute([$new_rfid, $student['id']]);
                if ($duplicate_query->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception("This RFID card is already assigned to another student.");
                }

                if (!empty($student['rfid_number'])) {
                    $history_query = $pdo->prepare("UPDATE rfid_card_history SET status = 'revoked', revoked_at = NOW(), revoked_by = ? WHERE user_id = ? AND rfid_number = ? AND status = 'active'");
                    $history_query->execute([$user_id, $student['id'], $student['rfid_number']]);
                }

                $update_query = $pdo->prepare("UPDATE users SET rfid_number = ? WHERE id = ?");
                $update_query->execute([$new_rfid, $student['id']]);
                $history_query = $pdo->prepare("INSERT INTO rfid_card_history (user_id, rfid_number, status, assigned_by) VALUES (?, ?, 'active', ?)");
                $history_query->execute([$student['id'], $new_rfid, $user_id]);
                $pdo->commit();
                $success_message = "RFID card replaced successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_message = "Unable to replace RFID card: " . $e->getMessage();
                error_log($error_message);
            }
        }
    }

    // Remove student
    if (isset($_POST['remove_student'])) {
        $student_id = trim($_POST['student_id'] ?? '');
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare("SELECT id FROM users WHERE student_id = ? AND role = 'student'");
            $query->execute([$student_id]);
            $student = $query->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                throw new Exception("Student not found.");
            }
            $internal_student_id = $student['id'];
            $query = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND action = 'BORROW' AND returned_date IS NULL");
            $query->execute([$internal_student_id]);
            if ((int) $query->fetchColumn() > 0) {
                throw new Exception("Cannot remove student. They still have pending borrowed books.");
            }
            $query = $pdo->prepare("DELETE FROM transactions WHERE user_id = ?");
            $query->execute([$internal_student_id]);
            $query = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
            $query->execute([$internal_student_id]);
            $query = $pdo->prepare("DELETE FROM users WHERE student_id = ? AND role = 'student'");
            $query->execute([$student_id]);
            $pdo->commit();
            $success_message = "Student removed successfully.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = "Error removing student: " . $e->getMessage();
            error_log($error_message);
        }
    }

    // Add book
    if (isset($_POST['add_book'])) {
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $genre = trim($_POST['genre'] ?? '');
        $submitted_barcodes = $_POST['barcodes'] ?? [];
        if (!is_array($submitted_barcodes)) {
            $submitted_barcodes = [];
        }

        $legacy_barcode = trim($_POST['barcode'] ?? '');
        if (empty($submitted_barcodes) && $legacy_barcode !== '') {
            $submitted_barcodes[] = $legacy_barcode;
        }

        $barcodes = array_values(array_filter(array_map('trim', $submitted_barcodes), function ($barcode) {
            return $barcode !== '';
        }));

        try {
            if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                throw new Exception("CSRF token validation failed.");
            }
            if ($title === '' || $author === '' || $genre === '') {
                throw new Exception("Please complete the book title, author, and genre.");
            }
            if (empty($barcodes)) {
                throw new Exception("Please scan or enter at least one barcode.");
            }
            if (count($barcodes) !== count(array_unique($barcodes))) {
                throw new Exception("One or more scanned barcodes were repeated. Remove duplicates and try again.");
            }

            $pdo->beginTransaction();

            $placeholders = implode(',', array_fill(0, count($barcodes), '?'));
            $duplicate_query = $pdo->prepare("SELECT barcode, title, book_number FROM books WHERE barcode IN ($placeholders)");
            $duplicate_query->execute($barcodes);
            $existing_books = $duplicate_query->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($existing_books)) {
                $first_existing = $existing_books[0];
                throw new Exception("Barcode '{$first_existing['barcode']}' is already assigned to {$first_existing['title']} ({$first_existing['book_number']}).");
            }

            $book_numbers = generateBookNumbers($pdo, $genre, count($barcodes));
            $query = $pdo->prepare("INSERT INTO books (title, author, genre, barcode, book_number, available, total_quantity) VALUES (?, ?, ?, ?, ?, 1, 1)");
            foreach ($barcodes as $index => $barcode) {
                $query->execute([$title, $author, $genre, $barcode, $book_numbers[$index]]);
            }

            $pdo->commit();
            $copy_label = count($barcodes) === 1 ? 'copy' : 'copies';
            $success_message = count($barcodes) . " book $copy_label registered successfully. Book numbers: " . implode(', ', $book_numbers) . ".";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = "Error adding book: " . $e->getMessage();
            error_log($error_message);
        }
    }

    // Remove book
    if (isset($_POST['remove_book'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_SESSION['csrf_token'])) {
            $error_message = "CSRF token validation failed.";
            error_log($error_message);
        } else {
            $book_id = trim($_POST['book_id'] ?? '');
            try {
                $pdo->beginTransaction();
                $query = $pdo->prepare("SELECT available FROM books WHERE id = ?");
                $query->execute([$book_id]);
                $book = $query->fetch(PDO::FETCH_ASSOC);
                if (!$book) {
                    throw new Exception("Book not found.");
                }
                if ($book['available'] == 0) {
                    throw new Exception("Cannot remove book: It is currently borrowed.");
                }
                $query = $pdo->prepare("DELETE FROM transactions WHERE book_id = ?");
                $query->execute([$book_id]);
                $query = $pdo->prepare("DELETE FROM books WHERE id = ?");
                $query->execute([$book_id]);
                $pdo->commit();
                $success_message = "Book removed successfully.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                error_log("Admin removed book ID $book_id");
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_message = "Error removing book: " . $e->getMessage();
                error_log($error_message);
            }
        }
    }

    // Reset transactions
    if (isset($_POST['reset_transactions'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_SESSION['csrf_token'])) {
            $error_message = "CSRF token validation failed.";
            error_log($error_message);
        } else {
            try {
                $pdo->beginTransaction();
                $query = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE action = 'BORROW' AND returned_date IS NULL");
                $query->execute();
                if ((int) $query->fetchColumn() > 0) {
                    throw new Exception("Cannot reset transactions. There are still pending borrowed books.");
                }
                $query = $pdo->prepare("DELETE FROM transactions");
                $query->execute();
                $pdo->commit();
                $success_message = "All transactions have been reset successfully.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                error_log("Admin reset all transactions");
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_message = "Error resetting transactions: " . $e->getMessage();
                error_log($error_message);
            }
        }
    }

    // Reset books
    if (isset($_POST['reset_books'])) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_SESSION['csrf_token'])) {
            $error_message = "CSRF token validation failed.";
            error_log($error_message);
        } else {
            try {
                $pdo->beginTransaction();
                $query = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE action = 'BORROW' AND returned_date IS NULL");
                $query->execute();
                if ((int) $query->fetchColumn() > 0) {
                    throw new Exception("Cannot reset books. There are still pending borrowed books.");
                }
                $query = $pdo->prepare("DELETE FROM transactions");
                $query->execute();
                $query = $pdo->prepare("DELETE FROM books");
                $query->execute();
                $pdo->commit();
                $success_message = "All books have been removed successfully.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                error_log("Admin reset all books");
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_message = "Error resetting books: " . $e->getMessage();
                error_log($error_message);
            }
        }
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
    <link rel="stylesheet" href="../css/styles.css?v=20260715-rfid-actions">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <?php include '../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <?php if ($success_message): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <?php if ($active_tab === 'dashboard'): ?>
                <?php if ($user_role === 'student'): ?>
                    <header>
                        <div class="welcome-widget">
                            <div class="avatar-initials">
                                <?php
                                $initials = strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? '', 0, 1));
                                echo htmlspecialchars($initials);
                                ?>
                            </div>
                            <h1><?php echo htmlspecialchars($greeting . ', ' . ($user['first_name'] ?? 'User') . '!'); ?></h1>
                            <p>Your Library at a Glance<?php echo $due_soon_count ? " - <strong>$due_soon_count book(s) due soon</strong>" : ''; ?></p>
                            <div class="quick-actions">
                                <a href="borrowed_books.php" class="action-btn"><i class="fas fa-book-reader"></i> My Books (<?php echo $borrowed_count; ?>)</a>
                                <form action="available_books.php" method="GET" class="mini-search">
                                    <input type="text" name="search" placeholder="Find a book..." required>
                                    <button type="submit"><i class="fas fa-search"></i></button>
                                </form>
                            </div>
                        </div>
                    </header>

                    <?php if (!empty($due_books)): ?>
                    <section class="countdown-section">
                        <h2><i class="fas fa-clock"></i> Your Borrowed Books</h2>
                        <div class="countdown-grid">
                            <?php foreach ($due_books as $db): ?>
                                <?php
                                $days = (int) $db['days_left'];
                                if ($days < 0) {
                                    $status_class = 'overdue';
                                    $status_text = abs($days) . ' day' . (abs($days) !== 1 ? 's' : '') . ' overdue';
                                    $status_icon = 'fa-exclamation-triangle';
                                } elseif ($days <= 3) {
                                    $status_class = 'urgent';
                                    $status_text = $days . ' day' . ($days !== 1 ? 's' : '') . ' left';
                                    $status_icon = 'fa-exclamation-circle';
                                } elseif ($days <= 7) {
                                    $status_class = 'warning';
                                    $status_text = $days . ' days left';
                                    $status_icon = 'fa-clock';
                                } else {
                                    $status_class = 'safe';
                                    $status_text = $days . ' days left';
                                    $status_icon = 'fa-check-circle';
                                }
                                ?>
                                <div class="countdown-card <?php echo $status_class; ?>">
                                    <div class="countdown-icon"><i class="fas <?php echo $status_icon; ?>"></i></div>
                                    <div class="countdown-info">
                                        <h3><?php echo htmlspecialchars($db['title']); ?></h3>
                                        <p class="countdown-author"><?php echo htmlspecialchars($db['author']); ?></p>
                                        <p class="countdown-due">Due: <?php echo date('M d, Y', strtotime($db['due_date'])); ?></p>
                                    </div>
                                    <div class="countdown-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if ($overdue_count > 0): ?>
                    <div class="alert-error" style="margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        You have <strong><?php echo $overdue_count; ?></strong> overdue book<?php echo $overdue_count !== 1 ? 's' : '' ?>. Please return them as soon as possible.
                    </div>
                    <?php endif; ?>

                    <section class="quick-stats">
                        <div class="stat-card">
                            <i class="fas fa-book"></i>
                            <h3>Borrowed This Month</h3>
                            <p><?php echo $books_borrowed_month; ?></p>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-book-open"></i>
                            <h3>Books Available</h3>
                            <p><?php echo $total_books_available; ?></p>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-history"></i>
                            <h3>All-Time Borrows</h3>
                            <p><?php echo $total_borrowed_alltime; ?></p>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-calendar-year"></i>
                            <h3>This Year</h3>
                            <p><?php echo $total_borrowed_year; ?></p>
                        </div>
                    </section>

                    <?php if (!empty($genre_stats)): ?>
                    <section class="genre-stats-section">
                        <h2><i class="fas fa-chart-bar"></i> Your Reading Genres</h2>
                        <div class="genre-bars">
                            <?php
                            $max_genre = $genre_stats[0]['cnt'] ?? 1;
                            foreach ($genre_stats as $gs):
                                $pct = round(($gs['cnt'] / $max_genre) * 100);
                                $genre_colors = ['FICTION' => '#3b82f6', 'NON-FICTION' => '#30a46c', 'SCIENCE' => '#8b5cf6', 'HISTORY' => '#f59e0b', 'BIOGRAPHY' => '#ec4899', 'NARRATIVE' => '#06b6d4'];
                                $color = $genre_colors[strtoupper($gs['genre'])] ?? '#697386';
                            ?>
                                <div class="genre-bar-row">
                                    <span class="genre-label"><?php echo htmlspecialchars($gs['genre']); ?></span>
                                    <div class="genre-bar-track">
                                        <div class="genre-bar-fill" style="width: <?php echo $pct; ?>%; background: <?php echo $color; ?>;"></div>
                                    </div>
                                    <span class="genre-count"><?php echo $gs['cnt']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <section class="recent-books">
                        <h2>Recently Added Books</h2>
                        <div class="books-grid">
                            <?php if (empty($recent_books)): ?>
                                <p>No recent books available.</p>
                            <?php else: ?>
                                <?php foreach ($recent_books as $book): ?>
                                    <div class="book-card">
                                        <i class="fas fa-book book-icon"></i>
                                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                        <p><?php echo htmlspecialchars($book['author']); ?></p>
                                        <a href="available_books.php" class="view-btn">View Details</a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <?php if (!empty($recommended_books)): ?>
                    <section class="recent-books">
                        <h2><i class="fas fa-magic" style="color: var(--accent);"></i> Recommended For You</h2>
                        <div class="books-grid">
                            <?php foreach ($recommended_books as $book): ?>
                                <div class="book-card">
                                    <i class="fas fa-book book-icon"></i>
                                    <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                    <p><?php echo htmlspecialchars($book['author']); ?></p>
                                    <span class="genre-badge"><?php echo htmlspecialchars($book['genre']); ?></span>
                                    <a href="available_books.php" class="view-btn">View Details</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if ($total_outstanding_fines > 0 || !empty($student_history_fines)): ?>
                    <section class="fines-section">
                        <h2><i class="fas fa-money-bill-wave"></i> Fines &amp; Penalties</h2>
                        <?php if ($total_outstanding_fines > 0): ?>
                        <div class="fine-alert-banner">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Outstanding Fines: <strong>₱<?php echo number_format($total_outstanding_fines, 2); ?></strong></span>
                            <small>Please return overdue books to avoid additional charges (₱5.00/day).</small>
                        </div>
                        <div class="fine-table-wrap">
                            <table class="styled-table">
                                <thead>
                                    <tr>
                                        <th>Book Title</th>
                                        <th>Due Date</th>
                                        <th>Days Overdue</th>
                                        <th>Fine</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($student_overdue_fines as $f): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($f['title']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($f['due_date'])); ?></td>
                                        <td><strong style="color:var(--danger);"><?php echo $f['days_overdue']; ?> day<?php echo $f['days_overdue'] !== 1 ? 's' : ''; ?></strong></td>
                                        <td><strong style="color:var(--danger);">₱<?php echo number_format($f['fine_amount'], 2); ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student_history_fines)): ?>
                        <h3 style="margin-top:20px;font-size:0.95rem;color:var(--text-muted);"><i class="fas fa-history"></i> Recent Late Returns</h3>
                        <div class="fine-table-wrap">
                            <table class="styled-table">
                                <thead>
                                    <tr>
                                        <th>Book Title</th>
                                        <th>Returned</th>
                                        <th>Was Due</th>
                                        <th>Late By</th>
                                        <th>Fine Paid</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($student_history_fines as $hf): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($hf['title']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($hf['returned_date'])); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($hf['due_date'])); ?></td>
                                        <td><?php echo $hf['days_overdue']; ?> day<?php echo $hf['days_overdue'] !== 1 ? 's' : ''; ?></td>
                                        <td>₱<?php echo number_format($hf['fine_amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                <?php else: ?>
                    <header>
                        <h1>Dashboard</h1>
                        <p>Welcome back, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] ?? 'Admin'); ?>!</p>
                    </header>

                    <section class="student-search-section">
                        <h2><i class="fas fa-search"></i> Quick Student Lookup</h2>
                        <form method="GET" class="student-search-form">
                            <input type="hidden" name="tab" value="dashboard">
                            <div class="student-search-bar">
                                <i class="fas fa-user-search"></i>
                                <input type="text" name="search" placeholder="Search by name, student ID, or email..." value="<?php echo htmlspecialchars($student_search_query); ?>">
                                <button type="submit"><i class="fas fa-search"></i> Search</button>
                            </div>
                        </form>
                        <?php if ($student_search_query !== '' && !empty($student_search_results)): ?>
                            <div class="student-search-results">
                                <?php foreach ($student_search_results as $s): ?>
                                    <div class="student-result-card">
                                        <div class="student-result-header">
                                            <div class="student-avatar"><?php echo strtoupper(substr($s['first_name'],0,1) . substr($s['last_name'],0,1)); ?></div>
                                            <div class="student-result-info">
                                                <h3><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></h3>
                                                <p><strong>ID:</strong> <?php echo htmlspecialchars($s['student_id']); ?> | <strong>Course:</strong> <?php echo htmlspecialchars($s['course']); ?> | <strong>Year:</strong> Grade <?php echo htmlspecialchars($s['year_level']); ?></p>
                                            </div>
                                        </div>
                                        <?php if (!empty($s['borrowed_books'])): ?>
                                            <div class="student-borrowed-list">
                                                <p class="borrowed-label"><i class="fas fa-book-reader"></i> Currently Borrowed (<?php echo count($s['borrowed_books']); ?>)</p>
                                                <table class="styled-table" style="margin:8px 0 0;">
                                                    <thead><tr><th>Title</th><th>Due Date</th><th>Status</th></tr></thead>
                                                    <tbody>
                                                    <?php foreach ($s['borrowed_books'] as $bb):
                                                        $dl = (int)$bb['days_left'];
                                                        if ($dl < 0) { $st = 'Overdue'; $sc = 'color:var(--danger);font-weight:600;'; }
                                                        elseif ($dl <= 3) { $st = 'Due Soon'; $sc = 'color:var(--danger);font-weight:600;'; }
                                                        elseif ($dl <= 7) { $st = $dl . ' days'; $sc = 'color:#f59e0b;font-weight:600;'; }
                                                        else { $st = $dl . ' days'; $sc = 'color:var(--success);font-weight:600;'; }
                                                    ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($bb['title']); ?></td>
                                                            <td><?php echo date('M d, Y', strtotime($bb['due_date'])); ?></td>
                                                            <td style="<?php echo $sc; ?>"><?php echo $st; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <p class="no-borrowed-msg">No books currently borrowed.</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($student_search_query !== ''): ?>
                            <div class="no-results-msg"><i class="fas fa-user-slash"></i> No students found matching "<strong><?php echo htmlspecialchars($student_search_query); ?></strong>"</div>
                        <?php endif; ?>
                    </section>

                    <section class="dashboard-cards">
                        <div class="card">
                            <h3>Total Students</h3>
                            <p><?php
                                try {
                                    $query = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
                                    echo $query->fetchColumn();
                                } catch (PDOException $e) {
                                    echo "N/A";
                                }
                            ?></p>
                        </div>
                        <div class="card">
                            <h3>Total Books</h3>
                            <p><?php
                                try {
                                    $query = $pdo->query("SELECT COUNT(*) FROM books");
                                    echo $query->fetchColumn();
                                } catch (PDOException $e) {
                                    echo "N/A";
                                }
                            ?></p>
                        </div>
                        <div class="card">
                            <h3>Total Transactions</h3>
                            <p><?php
                                try {
                                    $query = $pdo->query("SELECT COUNT(*) FROM transactions");
                                    echo $query->fetchColumn();
                                } catch (PDOException $e) {
                                    echo "N/A";
                                }
                            ?></p>
                        </div>
                        <div class="card">
                            <h3>Active Borrowers</h3>
                            <p><?php echo $librarian_active_borrowers; ?></p>
                        </div>
                    </section>

                    <?php if (!empty($librarian_overdue_books)): ?>
                    <section class="overdue-panel">
                        <h2><i class="fas fa-exclamation-triangle"></i> Overdue Books <span class="overdue-count"><?php echo count($librarian_overdue_books); ?></span></h2>
                        <table class="styled-table">
                            <thead>
                                <tr><th>Student</th><th>Student ID</th><th>Book</th><th>Due Date</th><th>Days Overdue</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($librarian_overdue_books as $ob): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ob['first_name'] . ' ' . $ob['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($ob['student_id']); ?></td>
                                    <td><?php echo htmlspecialchars($ob['title']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($ob['due_date'])); ?></td>
                                    <td><strong style="color:var(--danger);"><?php echo $ob['days_overdue']; ?> day<?php echo $ob['days_overdue'] !== 1 ? 's' : ''; ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($librarian_fines_summary)): ?>
                    <section class="fines-panel">
                        <h2><i class="fas fa-money-bill-wave" style="color:var(--danger);"></i> Outstanding Fines
                            <span class="fine-total-badge">₱<?php echo number_format($librarian_total_fines, 2); ?></span>
                        </h2>
                        <div class="fine-table-wrap">
                            <table class="styled-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Student ID</th>
                                        <th>Overdue Books</th>
                                        <th>Total Fine</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($librarian_fines_summary as $fs): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($fs['first_name'] . ' ' . $fs['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($fs['student_id']); ?></td>
                                        <td><strong><?php echo $fs['overdue_count']; ?></strong></td>
                                        <td><strong style="color:var(--danger);">₱<?php echo number_format($fs['total_fine'], 2); ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <?php endif; ?>

                    <section class="charts">
                        <div class="chart-container">
                            <h2>Borrowed Books</h2>
                            <canvas id="borrowedBooksChart" style="max-height: 300px;"></canvas>
                        </div>
                        <div class="chart-container">
                            <h2>Returned Books</h2>
                            <canvas id="returnedBooksChart" style="max-height: 300px;"></canvas>
                        </div>
                        <div class="chart-container">
                            <h2><i class="fas fa-fire" style="color:var(--danger);"></i> Popular Books (<?php echo date('Y'); ?>)</h2>
                            <?php if (!empty($librarian_popular_books)): ?>
                                <canvas id="popularBooksChart" style="max-height: 300px;"></canvas>
                            <?php else: ?>
                                <p class="no-chart-data">No borrowing data this year yet.</p>
                            <?php endif; ?>
                        </div>
                        <div class="chart-container">
                            <h2><i class="fas fa-chart-pie" style="color:var(--accent);"></i> Genre Distribution</h2>
                            <?php if (!empty($librarian_genre_dist)): ?>
                                <canvas id="genreDistChart" style="max-height: 300px;"></canvas>
                            <?php else: ?>
                                <p class="no-chart-data">No books in inventory yet.</p>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($is_staff): ?>
                <?php if ($active_tab === 'add_student'): ?>
                    <section class="admin-section">
                        <h2>Add Student</h2>
                        <form method="POST" id="add-student-form">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
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
                                <button type="button" id="scan-rfid-btn" class="scan-btn">Scan with Phone NFC</button>
                                <button type="button" id="scan-rfid-reader-btn" class="scan-btn reader-scan-btn">Scan with RFID Reader</button>
                                <div id="rfid-scan-status" class="rfid-scan-status" aria-live="polite">Choose a scanning method.</div>
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
                                    <option value="STEM Maritime">STEM Maritime</option>
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

                <?php if ($active_tab === 'replace_rfid'): ?>
                    <?php
                    $replace_user_id = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);
                    $replace_query = $pdo->prepare("SELECT id, first_name, last_name, student_id, rfid_number FROM users WHERE id = ? AND role = 'student'");
                    $replace_query->execute([$replace_user_id]);
                    $replace_student = $replace_query->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <section class="admin-section">
                        <h2>Replace RFID Card</h2>
                        <?php if (!$replace_student): ?>
                            <p class="alert-error">Student not found.</p>
                        <?php else: ?>
                            <p>Student: <strong><?php echo htmlspecialchars($replace_student['first_name'] . ' ' . $replace_student['last_name']); ?></strong> (<?php echo htmlspecialchars($replace_student['student_id']); ?>)</p>
                            <p>Current RFID: <strong><?php echo htmlspecialchars($replace_student['rfid_number'] ?: 'None'); ?></strong></p>
                            <form method="POST" id="replace-rfid-form">
                                <input type="hidden" name="user_id" value="<?php echo (int) $replace_student['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <div class="form-group">
                                    <label>New RFID Number:</label>
                                    <input type="text" name="rfid_number" id="rfid_input" required>
                                    <button type="button" id="scan-rfid-btn" class="scan-btn">Scan with Phone NFC</button>
                                    <button type="button" id="scan-rfid-reader-btn" class="scan-btn reader-scan-btn">Scan with RFID Reader</button>
                                    <div id="rfid-scan-status" class="rfid-scan-status" aria-live="polite">Choose a scanning method.</div>
                                </div>
                                <button type="submit" name="replace_rfid">Replace RFID Card</button>
                            </form>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'students'): ?>
                    <section class="admin-section">
                        <h2>Registered Students</h2>
                        <?php
                        try {
                            $query = $pdo->query("SELECT id, first_name, last_name, email, student_id, course, year_level, rfid_number FROM users WHERE role = 'student' ORDER BY last_name, first_name");
                            $students = $query->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $students = [];
                            $error_message = "Error loading students: " . $e->getMessage();
                            error_log($error_message);
                        }
                        if (empty($students)) {
                            echo "<p>No students registered in the database.</p>";
                        } else {
                        ?>
                        <table class="styled-table" id="student-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>ID Number</th>
                                    <th>Course</th>
                                    <th>Year Level</th>
                                    <th>RFID Number</th>
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
                                        <td><?php echo htmlspecialchars($student['rfid_number'] ?: 'None'); ?></td>
                                        <td>
                                            <div class="student-actions">
                                            <?php if (!empty($student['rfid_number'])): ?>
                                                <a href="dashboard.php?tab=replace_rfid&student_id=<?php echo (int) $student['id']; ?>" class="edit-btn">Replace RFID</a>
                                                <form method="POST" class="action-form" style="display:inline;" onsubmit="return confirm('Revoke this student\'s RFID card?');">
                                                    <input type="hidden" name="user_id" value="<?php echo (int) $student['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <button type="submit" name="revoke_rfid" class="remove-btn">Revoke</button>
                                                </form>
                                            <?php else: ?>
                                                <a href="dashboard.php?tab=replace_rfid&student_id=<?php echo (int) $student['id']; ?>" class="edit-btn">Assign RFID</a>
                                            <?php endif; ?>
                                            <form method="POST" class="action-form">
                                                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                                <button type="submit" name="remove_student" class="remove-btn"><i class="fas fa-trash"></i> Remove</button>
                                            </form>
                                            </div>
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
                        <h2>Fast Book Registration</h2>
                        <form method="POST" id="add-book-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
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
                                    <option value="Narrative">Narrative</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Barcode:</label>
                                <input type="text" name="barcode" id="barcode_input">
                                <button type="button" id="scan-barcode-btn" class="scan-btn">Scan Barcode</button>
                                <button type="button" id="add-barcode-btn" class="scan-btn reader-scan-btn">Add to Copy List</button>
                                <div id="barcode-scan-status" class="rfid-scan-status" aria-live="polite">Scan or type a barcode, then add it to the copy list.</div>
                            </div>
                            <div class="form-group">
                                <label>Book Number:</label>
                                <input type="text" id="book_number_preview" value="Auto-generated after saving" readonly>
                            </div>
                            <div class="form-group">
                                <label>Scanned Copies:</label>
                                <div class="barcode-copy-list">
                                    <table class="styled-table" id="barcode-copy-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Barcode</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="barcode-copy-body">
                                            <tr id="empty-copy-row">
                                                <td colspan="3">No barcodes added yet.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <button type="submit" name="add_book">Register Book Copies</button>
                        </form>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'transactions'): ?>
                    <section class="admin-section">
                        <h2>Transactions</h2>
                        <?php
                        $transactions = [];
                        $total_pages = 1;
                        try {
                            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
                            $per_page = 20;
                            $offset = ($page - 1) * $per_page;

                            $count_query = $pdo->query("SELECT COUNT(*) FROM transactions WHERE action IN ('BORROW', 'RETURN')");
                            $total_transactions = $count_query->fetchColumn();
                            $total_pages = ceil($total_transactions / $per_page);

                            $query = $pdo->prepare("
                                SELECT t.id, t.action,
                                       CASE 
                                           WHEN t.action = 'BORROW' THEN t.borrowed_date
                                           WHEN t.action = 'RETURN' THEN t.returned_date
                                           ELSE '1970-01-01 00:00:00' -- Fallback for unexpected cases
                                       END AS transaction_date,
                                       u.first_name, u.last_name, u.email, u.student_id
                                FROM transactions t
                                JOIN users u ON t.user_id = u.id
                                WHERE t.action IN ('BORROW', 'RETURN')
                                ORDER BY CASE 
                                             WHEN t.action = 'BORROW' THEN t.borrowed_date
                                             WHEN t.action = 'RETURN' THEN t.returned_date
                                             ELSE '1970-01-01 00:00:00'
                                         END DESC
                                LIMIT :limit OFFSET :offset
                            ");
                            $query->bindValue(':limit', $per_page, PDO::PARAM_INT);
                            $query->bindValue(':offset', $offset, PDO::PARAM_INT);
                            $query->execute();
                            $transactions = $query->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $error_message = "Error loading transactions: " . $e->getMessage();
                            error_log($error_message);
                        }
                        if (empty($transactions)) {
                            echo "<p class='no-transactions'>No transactions recorded.</p>";
                        } else {
                        ?>
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Email</th>
                                    <th>Student ID</th>
                                    <th>Action</th>
                                    <th>Transaction Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['email']); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['student_id'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['action']); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['transaction_date'] ? date('Y-m-d H:i:s', strtotime($transaction['transaction_date'])) : 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?tab=transactions&page=<?= $page - 1 ?>">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?tab=transactions&page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?tab=transactions&page=<?= $page + 1 ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php } ?>
                        <div class="reset-button-container">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" name="reset_transactions" class="reset-btn" onclick="return confirm('Are you sure you want to reset all transactions? This action cannot be undone.');">
                                    <i class="fas fa-undo"></i> Reset Transactions
                                </button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($active_tab === 'inventory'): ?>
                    <section class="admin-section">
                        <h2>Inventory</h2>
                        <form method="GET" class="filter-form">
                            <input type="hidden" name="tab" value="inventory">
                            <input type="hidden" name="page" value="<?= isset($_GET['page']) ? (int)$_GET['page'] : 1 ?>">
                            <div class="form-group">
                                <label>Search:</label>
                                <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search by title or author">
                            </div>
                            <div class="form-group">
                                <label>Genre:</label>
                                <select name="genre_filter">
                                    <option value="">All Genres</option>
                                    <?php
                                    try {
                                        $genre_query = $pdo->query("SELECT DISTINCT genre FROM books ORDER BY genre");
                                        $genres = $genre_query->fetchAll(PDO::FETCH_COLUMN);
                                    } catch (PDOException $e) {
                                        $genres = [];
                                        error_log("Genre query error: " . $e->getMessage());
                                    }
                                    foreach ($genres as $genre):
                                    ?>
                                        <option value="<?= htmlspecialchars($genre) ?>" <?= ($_GET['genre_filter'] ?? '') === $genre ? 'selected' : '' ?>><?= htmlspecialchars($genre) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit">Filter</button>
                        </form>
                        <?php
                        $books = [];
                        $total_pages = 1;
                        try {
                            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
                            $per_page = 20;
                            $offset = ($page - 1) * $per_page;

                            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                            $genre_filter = isset($_GET['genre_filter']) ? trim($_GET['genre_filter']) : '';
                            $where_clause = [];
                            $params = [];
                            if ($search) {
                                $where_clause[] = "(title LIKE :search OR author LIKE :search)";
                                $params[':search'] = "%$search%";
                            }
                            if ($genre_filter) {
                                $where_clause[] = "genre = :genre";
                                $params[':genre'] = $genre_filter;
                            }
                            $where_sql = $where_clause ? 'WHERE ' . implode(' AND ', $where_clause) : '';

                            $count_query = $pdo->prepare("SELECT COUNT(*) FROM books $where_sql");
                            $count_query->execute($params);
                            $total_books = $count_query->fetchColumn();
                            $total_pages = ceil($total_books / $per_page);

                            $query = $pdo->prepare("
                                SELECT id, title, author, genre, barcode, book_number, available, total_quantity
                                FROM books
                                $where_sql
                                ORDER BY title
                                LIMIT :limit OFFSET :offset
                            ");
                            foreach ($params as $key => $value) {
                                $query->bindValue($key, $value);
                            }
                            $query->bindValue(':limit', $per_page, PDO::PARAM_INT);
                            $query->bindValue(':offset', $offset, PDO::PARAM_INT);
                            $query->execute();
                            $books = $query->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $error_message = "Error loading inventory: " . $e->getMessage();
                            error_log($error_message);
                        }
                        if (empty($books)) {
                            echo "<p class='no-records'>No books in the inventory.</p>";
                        } else {
                        ?>
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Author</th>
                                    <th>Genre</th>
                                    <th>Barcode</th>
                                    <th>Book Number</th>
                                    <th>Availability</th>
                                    <th>Total Quantity</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($books as $book): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($book['title']); ?></td>
                                        <td><?php echo htmlspecialchars($book['author']); ?></td>
                                        <td><?php echo htmlspecialchars($book['genre']); ?></td>
                                        <td><?php echo htmlspecialchars($book['barcode']); ?></td>
                                        <td><?php echo htmlspecialchars($book['book_number']); ?></td>
                                        <td><?php echo $book['available'] > 0 ? 'Available' : 'Borrowed'; ?></td>
                                        <td><?php echo htmlspecialchars($book['total_quantity']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <form method="GET" action="edit_book.php" class="action-form">
                                                    <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                    <button type="submit" class="edit-btn"><i class="fas fa-edit"></i> Edit</button>
                                                </form>
                                                <form method="POST" class="action-form">
                                                    <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <button type="submit" name="remove_book" class="remove-btn" onclick="return confirm('Are you sure you want to remove this book?');">
                                                        <i class="fas fa-trash"></i> Remove
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?tab=inventory&page=<?= $page - 1 ?>">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?tab=inventory&page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?tab=inventory&page=<?= $page + 1 ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php } ?>
                        <div class="reset-button-container">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" name="reset_books" class="reset-btn" onclick="return confirm('Are you sure you want to reset all books? This action cannot be undone.');">
                                    <i class="fas fa-undo"></i> Reset Books
                                </button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($active_tab === 'phone_scanner'): ?>
                    <?php
                    $server_ip = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
                    $scanner_url = "https://{$server_ip}/shslibrary_system-main/pages/barcode_scanner.php";
                    ?>
                    <section class="admin-section">
                        <h2>Phone Barcode Scanner</h2>
                        <p style="margin-bottom:15px;">Open this page on your phone to scan book barcodes directly into the Add Book form.</p>
                        <div style="text-align:center; margin:20px 0;">
                            <div id="qr-code-container" style="display:inline-block; padding:15px; background:white; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);"></div>
                            <p style="margin-top:15px; font-size:0.9rem; color:#666;">Or open this URL on your phone:</p>
                            <div style="margin-top:8px; padding:10px 15px; background:#f0f2f5; border-radius:6px; font-family:monospace; font-size:0.95rem; word-break:break-all; display:inline-block;"><?php echo htmlspecialchars($scanner_url); ?></div>
                            <p style="margin-top:15px; font-size:0.85rem; color:#888;">Make sure your phone is on the same Wi-Fi network as this computer.</p>
                        </div>
                        <div style="margin-top:20px; padding:15px; background:#fff3cd; border-left:4px solid #ffc107; border-radius:4px;">
                            <strong>Camera not working?</strong>
                            <p style="margin-top:6px; font-size:0.85rem; color:#333;">Chrome requires HTTPS for camera access over LAN. To fix this on your phone:</p>
                            <ol style="margin-top:6px; padding-left:20px; font-size:0.85rem; color:#333;">
                                <li>Open <strong>chrome://flags</strong> on your phone's Chrome browser</li>
                                <li>Search for <strong>"Insecure origins treated as secure"</strong></li>
                                <li>Set it to <strong>Enabled</strong></li>
                                <li>Add this URL: <code><?php echo htmlspecialchars($scanner_url); ?></code></li>
                                <li>Tap <strong>Relaunch</strong></li>
                            </ol>
                            <p style="margin-top:8px; font-size:0.85rem; color:#555;">Alternatively, you can type barcodes manually on the phone page.</p>
                        </div>
                        <div style="margin-top:20px; padding:15px; background:#e9f7ff; border-left:4px solid #003366; border-radius:4px;">
                            <strong>How to use:</strong>
                            <ol style="margin-top:8px; padding-left:20px; font-size:0.9rem; color:#333;">
                                <li>Scan the QR code above with your phone camera</li>
                                <li>On your phone, tap <strong>Start Scanner</strong> or type a barcode manually</li>
                                <li>On this desktop, go to <a href="dashboard.php?tab=add_book">Add Book</a> and click <strong>Scan Barcode</strong></li>
                                <li>The barcode from your phone will appear automatically</li>
                                <li>Click <strong>Add to Copy List</strong> and repeat for each copy</li>
                            </ol>
                        </div>
                    </section>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
                    <script>
                        (function() {
                            var baseUrl = <?php echo json_encode($scanner_url); ?>;
                            var qrContainer = document.getElementById('qr-code-container');
                            if (qrContainer && typeof QRCode !== 'undefined') {
                                new QRCode(qrContainer, { text: baseUrl, width: 200, height: 200 });
                            }
                        })();
                    </script>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Borrowed and Returned Books Charts (Admin Dashboard)
        const borrowedChartCanvas = document.getElementById('borrowedBooksChart');
        const returnedChartCanvas = document.getElementById('returnedBooksChart');
        if (borrowedChartCanvas && returnedChartCanvas) {
            fetch('../fetch_book_transactions.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        console.error('Error fetching chart data:', data.error);
                        document.querySelector('.charts').innerHTML = `<p>Error: ${data.error}</p>`;
                        return;
                    }

                    // Check if there's any non-zero data
                    const hasBorrowData = data.borrow_values.some(value => value > 0);
                    const hasReturnData = data.return_values.some(value => value > 0);

                    if (!hasBorrowData && !hasReturnData) {
                       

 document.querySelector('.charts').innerHTML = '<p>No transactions in the last 12 months to display.</p>';
                        return;
                    }

                    // Borrowed Books Chart
                    if (hasBorrowData) {
                        new Chart(borrowedChartCanvas, {
                            type: 'line',
                            data: {
                                labels: data.borrow_labels,
                                datasets: [{
                                    label: 'Borrowed Books',
                                    data: data.borrow_values,
                                    borderColor: '#0a1628',
                                    backgroundColor: 'rgba(10, 22, 40, 0.1)',
                                    fill: true,
                                    tension: 0.3,
                                    pointRadius: 4,
                                    pointBackgroundColor: '#c9a84c'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                plugins: {
                                    legend: { display: false }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: { stepSize: 1, precision: 0 },
                                        title: { display: true, text: 'Books' }
                                    },
                                    x: {
                                        ticks: {
                                            maxRotation: 45,
                                            minRotation: 0,
                                            autoSkip: true,
                                            maxTicksLimit: 12
                                        },
                                        title: { display: true, text: 'Month' }
                                    }
                                }
                            }
                        });
                    } else {
                        borrowedChartCanvas.parentElement.innerHTML = '<h2>Borrowed Books</h2><p>No borrow transactions in the last 12 months.</p>';
                    }

                    // Returned Books Chart
                    if (hasReturnData) {
                        new Chart(returnedChartCanvas, {
                            type: 'line',
                            data: {
                                labels: data.return_labels,
                                datasets: [{
                                    label: 'Returned Books',
                                    data: data.return_values,
                                    borderColor: '#162d50',
                                    backgroundColor: 'rgba(22, 45, 80, 0.1)',
                                    fill: true,
                                    tension: 0.3,
                                    pointRadius: 4,
                                    pointBackgroundColor: '#c9a84c'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                plugins: {
                                    legend: { display: false }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: { stepSize: 1, precision: 0 },
                                        title: { display: true, text: 'Books' }
                                    },
                                    x: {
                                        ticks: {
                                            maxRotation: 45,
                                            minRotation: 0,
                                            autoSkip: true,
                                            maxTicksLimit: 12
                                        },
                                        title: { display: true, text: 'Month' }
                                    }
                                }
                            }
                        });
                    } else {
                        returnedChartCanvas.parentElement.innerHTML = '<h2>Returned Books</h2><p>No return transactions in the last 12 months.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading chart data:', error);
                    document.querySelector('.charts').innerHTML = `<p>Error loading transaction data for charts: ${error.message}</p>`;
                });
        }

        // Popular Books Bar Chart
        const popularCanvas = document.getElementById('popularBooksChart');
        if (popularCanvas) {
            <?php if (!empty($librarian_popular_books)): ?>
            new Chart(popularCanvas, {
                type: 'bar',
                data: {
                    labels: [<?php echo implode(',', array_map(function($b) { return "'" . addslashes(substr($b['title'], 0, 25)) . "'"; }, $librarian_popular_books)); ?>],
                    datasets: [{
                        label: 'Times Borrowed',
                        data: [<?php echo implode(',', array_column($librarian_popular_books, 'borrow_count')); ?>],
                        backgroundColor: [
                            '#0a1628','#162d50','#c9a84c','#3b82f6','#30a46c',
                            '#8b5cf6','#f59e0b','#ec4899','#06b6d4','#64748b'
                        ],
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, title: { display: true, text: 'Borrow Count' } }
                    }
                }
            });
            <?php endif; ?>
        }

        // Genre Distribution Doughnut Chart
        const genreCanvas = document.getElementById('genreDistChart');
        if (genreCanvas) {
            <?php if (!empty($librarian_genre_dist)): ?>
            new Chart(genreCanvas, {
                type: 'doughnut',
                data: {
                    labels: [<?php echo implode(',', array_map(function($g) { return "'" . addslashes($g['genre']) . "'"; }, $librarian_genre_dist)); ?>],
                    datasets: [{
                        data: [<?php echo implode(',', array_column($librarian_genre_dist, 'book_count')); ?>],
                        backgroundColor: [
                            '#0a1628','#c9a84c','#3b82f6','#30a46c',
                            '#8b5cf6','#f59e0b','#ec4899','#06b6d4','#64748b'
                        ],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true } }
                    }
                }
            });
            <?php endif; ?>
        }

        // Web NFC RFID scanning for the Add Student form.
        const scanRfidBtn = document.getElementById('scan-rfid-btn');
        const rfidInput = document.getElementById('rfid_input');
        const rfidScanStatus = document.getElementById('rfid-scan-status');

        function normalizeRfid(value) {
            return String(value || '').replace(/[^a-f0-9]/gi, '').toUpperCase();
        }

        function setRfidScanStatus(message, isError = false) {
            if (!rfidScanStatus) return;
            rfidScanStatus.textContent = message;
            rfidScanStatus.style.color = isError ? '#b42318' : '#155724';
        }

        if (scanRfidBtn && rfidInput) {
            scanRfidBtn.addEventListener('click', async function() {
                const originalButtonText = 'Scan with Phone NFC';
                scanRfidBtn.textContent = 'Checking NFC...';

                if (!window.isSecureContext) {
                    scanRfidBtn.textContent = originalButtonText;
                    setRfidScanStatus('Web NFC requires HTTPS. Open the secure version of this site.', true);
                    return;
                }

                if (!('NDEFReader' in window)) {
                    scanRfidBtn.textContent = originalButtonText;
                    setRfidScanStatus('Web NFC is not supported by this browser. Use the RFID reader instead.', true);
                    return;
                }

                scanRfidBtn.disabled = true;
                rfidInput.value = '';
                setRfidScanStatus('NFC is ready. Tap the student school ID card on the phone.');

                try {
                    const ndef = new NDEFReader();
                    await ndef.scan();
                    scanRfidBtn.textContent = 'Waiting for card...';

                    ndef.onreadingerror = function() {
                        scanRfidBtn.disabled = false;
                        scanRfidBtn.textContent = originalButtonText;
                        setRfidScanStatus('The card could not be read. Try again or use the RFID reader.', true);
                    };

                    ndef.onreading = function(event) {
                        const rfid = normalizeRfid(event.serialNumber);

                        if (!rfid) {
                            scanRfidBtn.disabled = false;
                            scanRfidBtn.textContent = originalButtonText;
                            setRfidScanStatus('Card detected, but no usable RFID number was provided.', true);
                            return;
                        }

                        rfidInput.value = rfid;
                        scanRfidBtn.disabled = false;
                        scanRfidBtn.textContent = originalButtonText;
                        setRfidScanStatus('RFID detected. Review the number, then submit the form.');
                    };
                } catch (error) {
                    console.error('Web NFC scan error:', error);
                    scanRfidBtn.disabled = false;
                    scanRfidBtn.textContent = originalButtonText;
                    setRfidScanStatus('Unable to start NFC scanning: ' + error.message, true);
                }
            });
        }

        // RFID reader fallback for devices without NFC.
        const scanRfidReaderBtn = document.getElementById('scan-rfid-reader-btn');
        if (scanRfidReaderBtn && rfidInput) {
            scanRfidReaderBtn.addEventListener('click', async function() {
                const originalButtonText = 'Scan with RFID Reader';
                scanRfidReaderBtn.disabled = true;
                if (scanRfidBtn) scanRfidBtn.disabled = true;
                rfidInput.value = '';
                scanRfidReaderBtn.textContent = 'Waiting for reader...';
                setRfidScanStatus('Ready. Tap the school ID card on the connected RFID reader.');

                try {
                    const clearResponse = await fetch('../scan.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'clear_rfid=true'
                    });
                    if (!clearResponse.ok) throw new Error('Unable to clear the previous RFID scan.');
                } catch (error) {
                    scanRfidReaderBtn.disabled = false;
                    if (scanRfidBtn) scanRfidBtn.disabled = false;
                    scanRfidReaderBtn.textContent = originalButtonText;
                    setRfidScanStatus(error.message, true);
                    return;
                }

                let attempts = 0;
                const maxAttempts = 40;
                const pollReader = setInterval(() => {
                    fetch('../scan.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'registration_scan=true'
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.rfid_number) {
                                const rfid = normalizeRfid(data.rfid_number);
                                rfidInput.value = rfid;
                                clearInterval(pollReader);
                                scanRfidReaderBtn.disabled = false;
                                if (scanRfidBtn) scanRfidBtn.disabled = false;
                                scanRfidReaderBtn.textContent = originalButtonText;
                                setRfidScanStatus('RFID detected from the reader. Review the number, then submit the form.');
                                return;
                            }

                            attempts++;
                            if (attempts >= maxAttempts) {
                                clearInterval(pollReader);
                                scanRfidReaderBtn.disabled = false;
                                if (scanRfidBtn) scanRfidBtn.disabled = false;
                                scanRfidReaderBtn.textContent = originalButtonText;
                                setRfidScanStatus('No RFID card was detected within 20 seconds.', true);
                            }
                        })
                        .catch(error => {
                            clearInterval(pollReader);
                            scanRfidReaderBtn.disabled = false;
                            if (scanRfidBtn) scanRfidBtn.disabled = false;
                            scanRfidReaderBtn.textContent = originalButtonText;
                            setRfidScanStatus('Error reading from the RFID reader: ' + error.message, true);
                        });
                }, 500);
            });
        }

        // --- Barcode Registration Queue ---
        const barcodeQueue = [];
        const barcodeInput = document.getElementById('barcode_input');
        const barcodeCopyBody = document.getElementById('barcode-copy-body');
        const barcodeScanStatus = document.getElementById('barcode-scan-status');
        const addBarcodeBtn = document.getElementById('add-barcode-btn');
        const scanBarcodeBtn = document.getElementById('scan-barcode-btn');
        const addBookForm = document.getElementById('add-book-form');

        function setBarcodeStatus(message, isError) {
            if (!barcodeScanStatus) return;
            barcodeScanStatus.textContent = message;
            barcodeScanStatus.style.color = isError ? '#b42318' : '#155724';
        }

        function renderBarcodeTable() {
            if (!barcodeCopyBody) return;
            barcodeCopyBody.innerHTML = '';
            if (barcodeQueue.length === 0) {
                var emptyRow = document.createElement('tr');
                emptyRow.id = 'empty-copy-row';
                emptyRow.innerHTML = '<td colspan="3">No barcodes added yet.</td>';
                barcodeCopyBody.appendChild(emptyRow);
                return;
            }
            barcodeQueue.forEach(function(barcode, index) {
                var row = document.createElement('tr');
                row.innerHTML = '<td>' + (index + 1) + '</td>' +
                    '<td>' + barcode + '</td>' +
                    '<td><button type="button" class="remove-barcode-btn" data-index="' + index + '" ' +
                    'style="background:#dc3545;color:white;border:none;padding:4px 10px;border-radius:4px;cursor:pointer;">Remove</button></td>';
                barcodeCopyBody.appendChild(row);
            });
            document.querySelectorAll('.remove-barcode-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    removeBarcode(parseInt(this.getAttribute('data-index'), 10));
                });
            });
        }

        function addBarcodeToQueue(barcode) {
            if (!barcode || barcode === 'Scanning...') return false;
            barcode = barcode.trim();
            if (barcode === '') return false;
            if (barcodeQueue.indexOf(barcode) !== -1) {
                setBarcodeStatus('Barcode ' + barcode + ' is already in the list.', true);
                return false;
            }
            barcodeQueue.push(barcode);
            renderBarcodeTable();
            setBarcodeStatus('Added ' + barcode + '. (' + barcodeQueue.length + ' total)');
            barcodeInput.value = '';
            return true;
        }

        function removeBarcode(index) {
            var removed = barcodeQueue.splice(index, 1)[0];
            renderBarcodeTable();
            setBarcodeStatus('Removed ' + removed + '. (' + barcodeQueue.length + ' total)');
        }

        // Scan Barcode Button — uses book_registration_scan mode
        if (scanBarcodeBtn) {
            scanBarcodeBtn.addEventListener('click', function() {
                var originalText = 'Scan Barcode';
                scanBarcodeBtn.disabled = true;
                scanBarcodeBtn.textContent = 'Scanning...';
                barcodeInput.value = 'Scanning...';
                setBarcodeStatus('Waiting for barcode... Scan with the phone or USB scanner.');

                var attempts = 0;
                var maxAttempts = 40;
                var pollBarcode = setInterval(function() {
                    fetch('../scan.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'book_registration_scan=true'
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                            if (data.barcode) {
                            barcodeInput.value = data.barcode;
                            clearInterval(pollBarcode);
                            scanBarcodeBtn.disabled = false;
                            scanBarcodeBtn.textContent = originalText;
                            var queueMsg = '';
                            if (data.queue_remaining !== undefined && data.queue_remaining > 0) {
                                queueMsg = ' (' + data.queue_remaining + ' more in queue)';
                            }
                            if (data.duplicate) {
                                setBarcodeStatus('Barcode ' + data.barcode + ' is already registered to "' + data.book.title + '" (' + data.book.book_number + ').' + queueMsg, true);
                            } else {
                                setBarcodeStatus('Scanned: ' + data.barcode + '. Click "Add to Copy List".' + queueMsg);
                            }
                        } else {
                            attempts++;
                            if (attempts >= maxAttempts) {
                                clearInterval(pollBarcode);
                                scanBarcodeBtn.disabled = false;
                                scanBarcodeBtn.textContent = originalText;
                                barcodeInput.value = '';
                                setBarcodeStatus('No barcode detected within 20 seconds.', true);
                            }
                        }
                    })
                    .catch(function(error) {
                        clearInterval(pollBarcode);
                        scanBarcodeBtn.disabled = false;
                        scanBarcodeBtn.textContent = originalText;
                        barcodeInput.value = '';
                        setBarcodeStatus('Error scanning barcode: ' + error.message, true);
                    });
                }, 500);
            });
        }

        // Add to Copy List Button
        if (addBarcodeBtn) {
            addBarcodeBtn.addEventListener('click', function() {
                var value = barcodeInput.value.trim();
                if (!value || value === 'Scanning...') {
                    setBarcodeStatus('Type or scan a barcode first.', true);
                    return;
                }
                addBarcodeToQueue(value);
            });
        }

        // Allow Enter key in barcode input to add to the list
        if (barcodeInput) {
            barcodeInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var value = barcodeInput.value.trim();
                    if (!value || value === 'Scanning...') {
                        setBarcodeStatus('Type or scan a barcode first.', true);
                        return;
                    }
                    addBarcodeToQueue(value);
                }
            });
        }

        // Form submission — inject barcodes[] hidden inputs
        if (addBookForm) {
            addBookForm.addEventListener('submit', function(e) {
                if (barcodeQueue.length === 0) {
                    e.preventDefault();
                    setBarcodeStatus('Please scan or enter at least one barcode before submitting.', true);
                    return;
                }
                addBookForm.querySelectorAll('.barcode-hidden-input').forEach(function(el) { el.remove(); });
                barcodeQueue.forEach(function(barcode) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'barcodes[]';
                    input.value = barcode;
                    input.className = 'barcode-hidden-input';
                    addBookForm.appendChild(input);
                });
            });
        }
    </script>
</body>
</html>
