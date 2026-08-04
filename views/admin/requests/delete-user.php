<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/AdminCourseService.php';

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

try { mmh_admin_student_archive(db(), (int) $userId); }
catch (Throwable $e) { http_response_code($e instanceof InvalidArgumentException ? 422 : 404); exit(json_encode(['status' => 0, 'message' => 'Student not found.'])); }
echo json_encode(['status' => 1, 'message' => 'Student archived. Historical records were preserved.']);
