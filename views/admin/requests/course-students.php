<?php
require_once 'connection/config.php';
require_once 'inc/EnrollmentService.php';

$courseId = trim((string) ($courseId ?? $_POST['course_id'] ?? ''));
$action = trim((string) ($_POST['action'] ?? ''));
$studentIds = is_array($_POST['student_ids'] ?? null) ? $_POST['student_ids'] : [];
$studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn($id) => $id > 0)));
$conn = db();

$courseStmt = $conn->prepare('SELECT course_id, course_title FROM courses WHERE course_id = ? LIMIT 1');
$course = null;
if ($courseStmt) {
    $courseStmt->bind_param('s', $courseId);
    $courseStmt->execute();
    $course = $courseStmt->get_result()->fetch_assoc();
    $courseStmt->close();
}
if (!$course || !$studentIds || !in_array($action, ['remove', 'move'], true)) {
    http_response_code(422);
    exit('Invalid enrollment request.');
}

$targetCourseId = trim((string) ($_POST['target_course_id'] ?? ''));
$ok = false;
if ($action === 'remove') {
    $ok = mmh_enrollment_remove_batch($conn, $studentIds, (string) $course['course_id']);
} else {
    $ok = mmh_enrollment_move_batch($conn, $studentIds, (string) $course['course_id'], $targetCourseId);
}

$count = count($studentIds);
$message = $ok
    ? ($action === 'remove'
        ? "Removed {$count} student" . ($count === 1 ? '' : 's') . ' from the course. Accounts and historical records were preserved.'
        : "Moved {$count} student" . ($count === 1 ? '' : 's') . ' to the selected course. Historical records remain attached to the source course.')
    : 'No enrollment changes were made. Check the selected students and target course, then try again.';
$base = rtrim((string) mmh_current_request_base_url(), '/');
header('Location: ' . $base . '/admin/courses/' . rawurlencode((string) $course['course_id']) . '/students?enrollment=' . ($ok ? 'success' : 'error') . '&message=' . rawurlencode($message));
exit;
