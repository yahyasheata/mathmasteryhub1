<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requestedStatus = filter_var($_POST['user_status'] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || !in_array($requestedStatus, [0, 1, 2], true)) {
    http_response_code(422);
    exit(json_encode(['status' => 0, 'message' => 'Invalid user status.']));
}
$requestedStatus = in_array($requestedStatus, [1, 2], true) ? 1 : 0;

$stmt = db()->prepare('UPDATE users SET status = ? WHERE user_id = ?');
if (!$stmt) {
    http_response_code(500);
    exit(json_encode(['status' => 0, 'message' => 'Status update could not be prepared.']));
}
$stmt->bind_param('ii', $requestedStatus, $userId);
$ok = $stmt->execute();
$stmt->close();

echo json_encode([
    'status' => $ok ? 1 : 0,
    'message' => $ok ? 'Status updated successfully' : 'Database connection error',
]);
