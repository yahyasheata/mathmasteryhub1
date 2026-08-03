<?php
require_once 'connection/config.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/StudentCourseCsrf.php';
require_once 'inc/TimedExam.php';

header('Content-Type: application/json; charset=utf-8');
$conn = db();
$courseId = student_course_access_identifier($courseId ?? '', 40);
$examId = (int) ($examId ?? 0);
$studentId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
if (!student_course_csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(419); echo json_encode(['success' => false, 'message' => 'Your session has expired. Refresh and try again.']); exit; }
$course = $courseId !== null ? student_course_access_course($conn, $courseId) : null;
$exam = $course && $studentId ? mmh_timed_exam_load($conn, (string) $course['course_id'], $examId, false) : null;
if (!$course || !$exam || !$studentId || !student_course_access_enrolled($conn, $studentId, (string) $course['course_id'])) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Exam unavailable.']); exit; }
$recovery = mmh_timed_exam_resolve_recovery($conn, $exam, (int) $studentId, (int) ($_GET['recovery_plan'] ?? 0), (int) ($_GET['recovery_task'] ?? 0));
if (((int) ($_GET['recovery_plan'] ?? 0) > 0 || (int) ($_GET['recovery_task'] ?? 0) > 0) && !$recovery) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Recovery Plan exam window unavailable.']); exit; }
if ($recovery) $exam = $recovery['exam'];
[$success, $message, $extra] = array_pad(mmh_timed_exam_submit($conn, $exam, (int) $studentId), 3, []);
if ($success && empty($extra['already_submitted'])) {
    $title = 'Timed Exam submitted';
    $body = 'Your answer for ' . (string) $exam['title'] . ' was submitted successfully.';
    $notice = $conn->prepare('SELECT id FROM notifications WHERE user_id = ? AND title = ? AND message = ? LIMIT 1');
    $exists = false;
    if ($notice) { $notice->bind_param('iss', $studentId, $title, $body); $notice->execute(); $exists = $notice->get_result()->num_rows > 0; $notice->close(); }
    if (!$exists) { $insertNotice = $conn->prepare('INSERT INTO notifications (user_id, title, message, status) VALUES (?, ?, ?, 0)'); if ($insertNotice) { $insertNotice->bind_param('iss', $studentId, $title, $body); $insertNotice->execute(); $insertNotice->close(); } }
}
echo json_encode(array_merge(['success' => $success, 'message' => $message], is_array($extra) ? $extra : []));
