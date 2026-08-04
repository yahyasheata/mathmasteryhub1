<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/AdminCourseService.php';
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

try { mmh_admin_student_set_status(db(), (int) $userId, (int) $requestedStatus); $ok = true; }
catch (Throwable $e) { $ok = false; }

echo json_encode([
    'status' => $ok ? 1 : 0,
    'message' => $ok ? 'Status updated successfully' : 'Database connection error',
]);
