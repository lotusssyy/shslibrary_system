<?php
include '../includes/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_test_transaction'])) {
    try {
        $user_id = $_POST['user_id'];
        $book_id = $_POST['book_id'];
        $action = $_POST['action'];
        $transaction_date = date('Y-m-d H:i:s'); // Current date/time
        $due_date = date('Y-m-d H:i:s', strtotime('+7 days')); // 7 days from now

        $query = $pdo->prepare("
            INSERT INTO transactions (user_id, book_id, action, transaction_type, borrowed_date, due_date, returned_date)
            VALUES (:user_id, :book_id, :action, :action, :borrowed_date, :due_date, :returned_date)
        ");
        $query->execute([
            ':user_id' => $user_id,
            ':book_id' => $book_id,
            ':action' => $action,
            ':borrowed_date' => $action === 'BORROW' ? $transaction_date : null,
            ':due_date' => $action === 'BORROW' ? $due_date : null,
            ':returned_date' => $action === 'RETURN' ? $transaction_date : null
        ]);
        $success_message = "Test transaction added successfully.";
    } catch (PDOException $e) {
        $error_message = "Error adding test transaction: " . $e->getMessage();
        error_log($error_message);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Transactions</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
</head>
<body>
    <div class="container">
        <div class="main-content">
            <header>
                <h1>Test Transactions</h1>
            </header>
            <?php if ($success_message): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <section class="admin-section">
                <h2>Add Test Transaction</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>User ID:</label>
                        <input type="number" name="user_id" required>
                    </div>
                    <div class="form-group">
                        <label>Book ID:</label>
                        <input type="number" name="book_id" required>
                    </div>
                    <div class="form-group">
                        <label>Action:</label>
                        <select name="action" required>
                            <option value="BORROW">Borrow</option>
                            <option value="RETURN">Return</option>
                        </select>
                    </div>
                    <button type="submit" name="add_test_transaction">Add Transaction</button>
                </form>
            </section>
        </div>
    </div>
</body>
</html>