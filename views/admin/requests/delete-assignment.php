<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/AdminCourseService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_method'] ?? '') !== 'DELETE') {
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Invalid archive request.']));
}
$assignmentId = trim((string) ($_POST['assignment_id'] ?? ''));
if ($assignmentId === '') {
    http_response_code(422);
    exit(json_encode(['status' => 0, 'message' => 'Invalid assignment.']));
}

try { mmh_admin_assignment_archive(db(), $assignmentId); }
catch (Throwable $e) { http_response_code($e instanceof InvalidArgumentException ? 422 : 404); exit(json_encode(['status' => 0, 'message' => $e->getMessage() === 'Assignment not found.' ? 'Assignment not found.' : 'Assignment could not be archived.'])); }
echo json_encode(['status' => 1, 'message' => 'Assignment archived. Submissions and grades were preserved.']);
