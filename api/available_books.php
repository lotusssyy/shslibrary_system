<?php
header('Content-Type: application/json');
include __DIR__ . '/../includes/db.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 50;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

$where = $search ? "WHERE (title LIKE :s OR author LIKE :s) AND available > 0" : "WHERE available > 0";

$stmt = $pdo->prepare("SELECT id, title, author, genre, barcode, book_number FROM books $where ORDER BY title LIMIT :limit OFFSET :offset");
if ($search) {
    $stmt->bindValue(':s', "%$search%", PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['status' => 'ok', 'count' => count($books), 'books' => $books]);
