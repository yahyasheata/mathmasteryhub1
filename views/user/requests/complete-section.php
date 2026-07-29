<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/LearningEvents.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/StudentCourseCsrf.php';
require_once 'inc/AssignmentProgress.php';

header('Content-Type: application/json; charset=utf-8');

function complete_section_response($success, $message, array $data = [], $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    complete_section_response(false, 'Invalid request method.', [], 405);
}

if (empty($_SESSION['username'])) {
    complete_section_response(false, 'Please login first.', [], 401);
}

if (!student_course_csrf_valid($_POST['csrf_token'] ?? null)) {
    complete_section_response(false, 'Your session has expired. Please refresh the course and try again.', [], 403);
}

$courseId = student_course_access_identifier($_POST['course_id'] ?? '', 40);
$sectionId = student_course_access_identifier($_POST['section_id'] ?? '', 40);
$source = student_course_access_identifier($_POST['source'] ?? 'manual_completion', 50);
if ($courseId === null || $sectionId === null || $sectionId === '__general__' || $source === null) {
    complete_section_response(false, 'Validation failed. Course or section is invalid.', [], 422);
}

try {
    $conn = db();
    $userId = student_course_access_student_id($conn, $_SESSION['username']);
    if ($userId === null) {
        complete_section_response(false, 'Your account is unavailable.', [], 403);
    }

    $course = student_course_access_course($conn, $courseId);
    if (!$course) {
        complete_section_response(false, 'This course is unavailable.', [], 404);
    }
    $courseId = (string) $course['course_id'];

    if (!student_course_access_enrolled($conn, $userId, $courseId)) {
        complete_section_response(false, 'You are not enrolled in this course.', [], 403);
    }

    $sectionState = student_course_access_section_state($conn, $course, $sectionId, $userId);
    if (!$sectionState) {
        complete_section_response(false, 'This section is unavailable.', [], 404);
    }
    if (!empty($sectionState['state']['locked'])) {
        complete_section_response(false, 'This section is not available yet.', [], 403);
    }

    $section = $sectionState['section'];
    $assignmentMap = mmh_assignment_progress_load_course($conn, $userId, $courseId);
    $completionState = student_course_access_section_completion_state($conn, $section, student_course_access_progress_map($conn, $courseId, $userId), $userId, $assignmentMap);
    if (!empty($completionState['requirements']['has_requirements']) && empty($completionState['requirements']['complete'])) {
        complete_section_response(false, $completionState['requirements']['blocking_reason'] ?: 'Required assignment work must be completed first.', [
            'requirements' => $completionState['requirements'],
        ], 422);
    }
    if (!student_course_access_completion_source_allowed($conn, $section, $source, $userId)) {
        complete_section_response(false, 'This section cannot be completed from the requested action.', [], 422);
    }

    // The existing unique key (course_id, section_id, user_id) keeps this
    // idempotent even when the browser repeats a request.
    $completionRule = trim((string) ($section['completion_rule'] ?? 'manual_completion')) ?: 'manual_completion';
    $stmt = $conn->prepare('INSERT INTO course_section_progress (course_id, section_id, user_id, completion_rule, source, completed_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE completion_rule = VALUES(completion_rule), source = VALUES(source), completed_at = NOW()');
    if (!$stmt) {
        complete_section_response(false, 'Unable to save section completion.', [], 500);
    }
    $stmt->bind_param('ssiss', $courseId, $sectionId, $userId, $completionRule, $source);
    $saved = $stmt->execute();
    $stmt->close();
    if (!$saved) {
        complete_section_response(false, 'Unable to save section completion.', [], 500);
    }

    mmh_log_event($conn, $userId, 'section_completed', [
        'course_id' => $courseId,
        'section_id' => $sectionId,
        'meta' => [
            'source' => $source,
            'completion_rule' => $completionRule,
        ],
    ]);

    complete_section_response(true, 'Section marked complete.', [
        'course_id' => $courseId,
        'section_id' => $sectionId,
    ]);
} catch (Throwable $e) {
    complete_section_response(false, 'Unable to process section completion.', [], 500);
}
