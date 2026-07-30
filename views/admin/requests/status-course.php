<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
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

$stmt = db()->prepare('UPDATE courses SET course_status = ? WHERE course_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'The course status could not be updated.']);
    exit;
}
$stmt->bind_param('ss', $courseStatus, $courseId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'The course status could not be updated.']);
    exit;
}

echo json_encode(['status' => 1, 'message' => 'Status updated successfully']);
