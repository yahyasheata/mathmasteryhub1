<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/LearningEvents.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/StudentCourseCsrf.php';
require_once 'inc/StudentCourseProgress.php';

header('Content-Type: application/json; charset=utf-8');

function learning_event_response($success, $message, array $data = [], $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function learning_event_value($key)
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    learning_event_response(false, 'Invalid request method.', [], 405);
}
if (empty($_SESSION['username'])) {
    learning_event_response(false, 'Please login first.', [], 401);
}
if (!student_course_csrf_valid($_POST['csrf_token'] ?? null)) {
    learning_event_response(false, 'Your session has expired. Please refresh the course and try again.', [], 403);
}

$eventType = learning_event_value('event_type');
// This endpoint is only for the existing student-course browser events.
// Server-originated events continue to use mmh_log_event() directly.
$sectionEvents = ['section_opened'];
$lessonEvents = [
    'recording_started',
    'notes_opened',
    'notes_downloaded',
    'homework_opened',
    'model_answer_viewed',
    'custom_lesson_opened',
    'past_paper_opened',
];
if (!in_array($eventType, MMH_LEARNING_EVENT_TYPES, true)
    || (!in_array($eventType, $sectionEvents, true) && !in_array($eventType, $lessonEvents, true))) {
    learning_event_response(false, 'Invalid learning event.', [], 422);
}

$courseId = student_course_access_identifier(learning_event_value('course_id'), 40);
$sectionId = student_course_access_normalize_section_id(learning_event_value('section_id'));
$itemId = learning_event_value('item_id');
$assignmentId = learning_event_value('assignment_id');
$examId = learning_event_value('exam_id');
if ($courseId === null || $sectionId === null) {
    learning_event_response(false, 'Invalid learning event context.', [], 422);
}
if ($itemId !== '' && student_course_access_identifier($itemId, 40) === null) {
    learning_event_response(false, 'Invalid lesson reference.', [], 422);
}
if ($assignmentId !== '' && student_course_access_identifier($assignmentId, 40) === null) {
    learning_event_response(false, 'Invalid assignment reference.', [], 422);
}
if ($examId !== '' && student_course_access_identifier($examId, 40) === null) {
    learning_event_response(false, 'Invalid exam reference.', [], 422);
}

try {
    $conn = db();
    $userId = student_course_access_student_id($conn, $_SESSION['username']);
    if ($userId === null) {
        learning_event_response(false, 'Your account is unavailable.', [], 403);
    }
    $course = student_course_access_course($conn, $courseId);
    if (!$course) {
        learning_event_response(false, 'This course is unavailable.', [], 404);
    }
    $courseId = (string) $course['course_id'];
    if (!student_course_access_enrolled($conn, $userId, $courseId)) {
        learning_event_response(false, 'You are not enrolled in this course.', [], 403);
    }

    if (in_array($eventType, $sectionEvents, true)) {
        if ($itemId !== '' || $assignmentId !== '' || $examId !== '') {
            learning_event_response(false, 'Invalid section event context.', [], 422);
        }
        if ($sectionId === '') {
            if (!student_course_access_has_visible_general_item($conn, $courseId)) {
                learning_event_response(false, 'This section is unavailable.', [], 404);
            }
        } else {
            $sectionState = student_course_access_section_state($conn, $course, $sectionId, $userId);
            if (!$sectionState) {
                learning_event_response(false, 'This section is unavailable.', [], 404);
            }
            if (!empty($sectionState['state']['locked'])) {
                learning_event_response(false, 'This section is not available yet.', [], 403);
            }
        }
    } else {
        if ($itemId === '') {
            learning_event_response(false, 'A lesson is required for this event.', [], 422);
        }

        $selection = student_course_access_selected_item($conn, $course, $itemId, $sectionId, $userId);
        if (!$selection) {
            learning_event_response(false, 'This lesson is unavailable.', [], 403);
        }
        $item = $selection['item'];
        $expectedEvent = mmh_lesson_open_event($item['template_type'] ?: $item['item_type']);
        if ($eventType === 'notes_downloaded') {
            if ($expectedEvent !== 'notes_opened') {
                learning_event_response(false, 'This lesson does not support that event.', [], 422);
            }
        } elseif ($eventType !== $expectedEvent) {
            learning_event_response(false, 'This lesson does not support that event.', [], 422);
        }

        if ($assignmentId !== '') {
            $assignment = student_course_access_assignment($conn, $assignmentId);
            if (!$assignment || (string) $assignment['course_id'] !== $courseId
                || !student_course_access_assignment_matches_item($assignment, $item)) {
                learning_event_response(false, 'The assignment context is unavailable.', [], 403);
            }
        }

        if ($examId !== '') {
            $exam = student_course_access_exam($conn, $examId, $courseId);
            if (!$exam) {
                learning_event_response(false, 'The exam context is unavailable.', [], 403);
            }
            $examSection = student_course_access_normalize_section_id($exam['section_id'] ?? '');
            if ($examSection === null || ($examSection !== '' && $examSection !== $sectionId)) {
                learning_event_response(false, 'The exam section is unavailable.', [], 403);
            }
            if (!empty($exam['item_id']) && (string) $exam['item_id'] !== (string) $item['item_id']) {
                learning_event_response(false, 'The exam lesson is unavailable.', [], 403);
            }
        }
    }

    $recorded = mmh_log_event($conn, $userId, $eventType, [
        'course_id' => $courseId,
        'section_id' => $sectionId,
        'item_id' => $itemId,
        'assignment_id' => $assignmentId,
        'exam_id' => $examId,
    ]);
    // Opening an external Recording is a real viewed/opened action, not a
    // completion claim. Keep the same viewed-progress semantics as the old
    // protected viewer without inventing watch duration or completion.
    if ($recorded && $eventType === 'recording_started' && student_course_progress_available($conn)) {
        student_course_progress_record_viewed($conn, $userId, $courseId, $itemId);
    }

    learning_event_response($recorded, $recorded ? 'Learning event recorded.' : 'Learning event was not recorded.', [], $recorded ? 200 : 500);
} catch (Throwable $e) {
    learning_event_response(false, 'Unable to record this learning event.', [], 500);
}
