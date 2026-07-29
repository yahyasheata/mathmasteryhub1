<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/StudentCourseCsrf.php';
require_once 'inc/StudentCourseProgress.php';

header('Content-Type: application/json; charset=utf-8');

function item_progress_response($success, $message, array $data = [], $statusCode = 200)
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
    item_progress_response(false, 'Invalid request method.', [], 405);
}
if (empty($_SESSION['username'])) {
    item_progress_response(false, 'Please login first.', [], 401);
}
if (!student_course_csrf_valid($_POST['csrf_token'] ?? null)) {
    item_progress_response(false, 'Your session has expired. Please refresh the course and try again.', [], 403);
}

$action = trim((string) ($_POST['action'] ?? ''));
if (!in_array($action, ['viewed', 'complete'], true)) {
    item_progress_response(false, 'Invalid lesson progress action.', [], 422);
}

$courseId = student_course_access_identifier($_POST['course_id'] ?? '', 40);
$itemId = student_course_access_identifier($_POST['item_id'] ?? '', 40);
$rawSectionId = trim((string) ($_POST['section_id'] ?? ''));
$sectionId = student_course_access_normalize_section_id($rawSectionId);
if ($courseId === null || $itemId === null || $rawSectionId === '' || $sectionId === null) {
    item_progress_response(false, 'Invalid lesson progress context.', [], 422);
}

try {
    $conn = db();
    if (!student_course_progress_available($conn)) {
        item_progress_response(false, 'Lesson progress is not available yet. Please contact your teacher.', [], 503);
    }

    $userId = student_course_access_student_id($conn, $_SESSION['username']);
    if ($userId === null) {
        item_progress_response(false, 'Your account is unavailable.', [], 403);
    }
    $course = student_course_access_course($conn, $courseId);
    if (!$course) {
        item_progress_response(false, 'This course is unavailable.', [], 404);
    }
    $courseId = (string) $course['course_id'];
    if (!student_course_access_enrolled($conn, $userId, $courseId)) {
        item_progress_response(false, 'You are not enrolled in this course.', [], 403);
    }

    $selection = student_course_access_selected_item($conn, $course, $itemId, $sectionId, $userId);
    if (!$selection) {
        item_progress_response(false, 'This lesson is unavailable.', [], 403);
    }

    if ($action === 'complete' && !student_course_progress_manual_completion_eligible($selection['item'])) {
        item_progress_response(false, 'This lesson is completed through its assignment or exam workflow.', [], 422);
    }

    $saved = $action === 'viewed'
        ? student_course_progress_record_viewed($conn, $userId, $courseId, $itemId)
        : student_course_progress_mark_complete($conn, $userId, $courseId, $itemId, 'manual');
    if (!$saved) {
        item_progress_response(false, 'Unable to save lesson progress. Please try again.', [], 500);
    }

    item_progress_response(true, $action === 'viewed' ? 'Lesson view saved.' : 'Lesson marked complete.', [
        'action' => $action,
        'course_id' => $courseId,
        'item_id' => $itemId,
        'completed' => $action === 'complete',
    ]);
} catch (Throwable $e) {
    item_progress_response(false, 'Unable to process lesson progress.', [], 500);
}
