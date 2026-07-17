<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

// Ensure no output before JSON response
ob_start();

// Include database connection
$db_included = include 'includes/db.php'; // Updated path
if (!$db_included || !isset($pdo)) {
    error_log("Error: Failed to include db.php or PDO not set.");
    http_response_code(500);
    echo json_encode(['error' => 'Failed to establish database connection']);
    ob_end_flush();
    exit;
}

header('Content-Type: application/json');

try {
    // Get the last 12 months
    $months = [];
    $current_date = new DateTime();
    for ($i = 11; $i >= 0; $i--) {
        $date = (clone $current_date)->modify("-$i months");
        $months[] = [
            'year' => $date->format('Y'),
            'month' => $date->format('m'),
            'label' => $date->format('M \'y')
        ];
    }

    // Query borrow transactions
    $borrow_data = array_fill(0, 12, 0);
    $stmt = $pdo->prepare("
        SELECT YEAR(borrowed_date) AS year, MONTH(borrowed_date) AS month, COUNT(*) AS count
        FROM transactions
        WHERE action = 'BORROW' AND borrowed_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY YEAR(borrowed_date), MONTH(borrowed_date)
    ");
    $stmt->execute();
    $borrow_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($borrow_results as $row) {
        foreach ($months as $index => $month) {
            if ($row['year'] == $month['year'] && $row['month'] == $month['month']) {
                $borrow_data[$index] = (int)$row['count'];
                break;
            }
        }
    }

    // Query return transactions
    // Returns are stored as BORROW rows with returned_date filled in
    $return_data = array_fill(0, 12, 0);
    $stmt = $pdo->prepare("
        SELECT YEAR(returned_date) AS year, MONTH(returned_date) AS month, COUNT(*) AS count
        FROM transactions
        WHERE action = 'BORROW' AND returned_date IS NOT NULL AND returned_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY YEAR(returned_date), MONTH(returned_date)
    ");
    $stmt->execute();
    $return_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($return_results as $row) {
        foreach ($months as $index => $month) {
            if ($row['year'] == $month['year'] && $row['month'] == $month['month']) {
                $return_data[$index] = (int)$row['count'];
                break;
            }
        }
    }

    // Return JSON
    echo json_encode([
        'borrow_labels' => array_column($months, 'label'),
        'borrow_values' => $borrow_data,
        'return_labels' => array_column($months, 'label'),
        'return_values' => $return_data
    ]);
} catch (PDOException $e) {
    error_log("Fetch transactions error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error in fetch_book_transactions.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
} finally {
    ob_end_flush();
}
?>