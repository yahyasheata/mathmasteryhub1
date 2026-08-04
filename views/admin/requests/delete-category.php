<?php
require_once 'connection/config.php';
require_once '__init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_method'] ?? '') !== 'DELETE') {
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Invalid request.']));
}
$categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($categoryId === false) { http_response_code(422); exit(json_encode(['status' => 0, 'message' => 'Invalid category.'])); }

$conn = db();
$stmt = $conn->prepare('UPDATE categories SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE category_id = ?');
$stmt->bind_param('i', $categoryId);
$ok = $stmt->execute() && $stmt->affected_rows > 0;
$stmt->close();
echo json_encode(['status' => $ok ? 1 : 0, 'message' => $ok ? 'Category archived.' : 'Category not found or already archived.']);
