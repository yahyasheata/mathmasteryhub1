<?php
require_once 'connection/config.php';
require_once '__init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_method'] ?? '') !== 'DELETE') {
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Invalid archive request.']));
}
$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false) {
    http_response_code(422);
    exit(json_encode(['status' => 0, 'message' => 'Invalid student.']));
}

$stmt = db()->prepare("UPDATE users SET status = 0, archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE user_id = ? AND role = 'user'");
$stmt->bind_param('i', $userId);
$ok = $stmt->execute() && $stmt->affected_rows > 0;
$stmt->close();
if (!$ok) {
    http_response_code(404);
    exit(json_encode(['status' => 0, 'message' => 'Student not found.']));
}
echo json_encode(['status' => 1, 'message' => 'Student archived. Historical records were preserved.']);
