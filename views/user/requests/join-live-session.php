<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/LiveSessions.php';
require_once 'inc/LearningEvents.php';

$conn = db();
mmh_live_ensure_schema($conn);

$studentId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
$occurrenceId = student_course_access_identifier($occurrenceId ?? '', 40);
if (!$studentId || $occurrenceId === null) {
    http_response_code(404);
    echo 'Live session not found.';
    exit;
}

$occurrence = mmh_live_occurrence($conn, $occurrenceId);
if (!$occurrence || in_array($occurrence['status'], ['cancelled'], true)) {
    http_response_code(404);
    echo 'Live session is unavailable.';
    exit;
}

if (!student_course_access_enrolled($conn, $studentId, $occurrence['course_id'])) {
    http_response_code(403);
    echo 'You are not enrolled in this course.';
    exit;
}

$joinState = mmh_live_join_state($occurrence);
if (empty($joinState['active'])) {
    http_response_code(($joinState['state'] ?? '') === 'ended' ? 410 : 403);
    echo $joinState['label'] ?? 'Live session is unavailable.';
    exit;
}

$target = trim((string) ($occurrence['replacement_url'] ?: $occurrence['teams_url_snapshot']));
$target = mmh_live_sanitize_teams_url($target);
if ($target === null) {
    http_response_code(500);
    echo 'The meeting link is not configured correctly.';
    exit;
}

mmh_live_record_join($conn, $studentId, $occurrence, 'student_join_endpoint');
mmh_log_event($conn, $studentId, 'live_session_join_clicked', [
    'course_id' => $occurrence['course_id'],
    'meta' => [
        'occurrence_id' => $occurrence['occurrence_id'],
        'schedule_id' => $occurrence['schedule_id'],
        'status' => $occurrence['status'],
    ],
]);

header('Location: ' . $target, true, 302);
exit;
?>
