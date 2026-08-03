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
[$success, $message] = mmh_timed_exam_remove_latest_upload($conn, $exam, (int) $studentId);
echo json_encode(['success' => $success, 'message' => $message]);
