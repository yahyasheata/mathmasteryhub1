<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/AdminCourseService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (isset($_POST['update-visibility']) && (string) $_POST['update-visibility'] === '1') {
    $courseId = trim((string) ($_POST['course_id'] ?? ''));
    $visibility = strtolower(trim((string) ($_POST['course_visibility'] ?? '')));
    if ($courseId === '' || !in_array($visibility, ['public', 'private'], true)) {
        http_response_code(422);
        echo json_encode(['status' => 0, 'message' => 'Invalid course visibility.']);
        exit;
    }
    try { mmh_admin_course_set_visibility(db(), $courseId, $visibility); }
    catch (Throwable $e) { http_response_code($e instanceof InvalidArgumentException ? 422 : ($e->getMessage() === 'Course not found.' ? 404 : 500)); echo json_encode(['status' => 0, 'message' => 'The course visibility could not be updated.']); exit; }
    echo json_encode(['status' => 1, 'message' => 'Visibility updated successfully.']);
    exit;
}

if (!isset($_POST['update-status']) || (string) $_POST['update-status'] !== '1') {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => 'Invalid status update request.']);
    exit;
}

$courseId = trim((string) ($_POST['course_id'] ?? ''));
if ($courseId === '') {
    http_response_code(422);
    echo json_encode(['status' => 0, 'message' => 'A valid course is required.']);
    exit;
}

// An unchecked checkbox is omitted from FormData. In this form that omission
// represents Draft -> Published; an explicit zero represents Published -> Draft.
$courseStatus = (string) ($_POST['course_status'] ?? '1');
if (!in_array($courseStatus, ['0', '1'], true)) {
    http_response_code(422);
    echo json_encode(['status' => 0, 'message' => 'Invalid course status.']);
    exit;
}

try { mmh_admin_course_set_status(db(), $courseId, $courseStatus); }
catch (Throwable $e) { http_response_code($e instanceof InvalidArgumentException ? 422 : ($e->getMessage() === 'Course not found.' ? 404 : 500)); echo json_encode(['status' => 0, 'message' => 'The course status could not be updated.']); exit; }

echo json_encode(['status' => 1, 'message' => 'Status updated successfully']);
