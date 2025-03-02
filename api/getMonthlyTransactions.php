<?php
header('Content-Type: application/json');
include '../includes/db.php';

try {
    $query = $pdo->query("
        SELECT DATE_FORMAT(borrowed_date, '%Y-%m') AS month, COUNT(*) AS count 
        FROM transactions 
        WHERE action = 'BORROW' 
        GROUP BY month 
        ORDER BY month ASC
    ");
    $data = $query->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $values = [];
    foreach ($data as $row) {
        $labels[] = $row['month'];
        $values[] = (int)$row['count'];
    }

    if (empty($labels)) {
        $labels = [date('Y-m')];
        $values = [0];
    }

    error_log("Transaction Data: " . json_encode($data));
    error_log("Labels: " . json_encode($labels));
    error_log("Values: " . json_encode($values));

    echo json_encode(['labels' => $labels, 'values' => $values]);
} catch (Exception $e) {
    error_log("Error in getMonthlyTransactions: " . $e->getMessage());
    echo json_encode(['labels' => [date('Y-m')], 'values' => [0], 'error' => $e->getMessage()]);
}
?>