<?php
require_once 'connection/config.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/TimedExam.php';

$conn = db();
$courseId = student_course_access_identifier($courseId ?? '', 40);
$examId = (int) ($examId ?? 0);
$studentId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
$course = $courseId !== null ? student_course_access_course($conn, $courseId) : null;
$exam = $course && $studentId ? mmh_timed_exam_load($conn, (string) $course['course_id'], $examId, false) : null;
if (!$course || !$exam || !$studentId || !student_course_access_enrolled($conn, $studentId, (string) $course['course_id'])) { http_response_code(403); exit('Exam unavailable.'); }
$recovery = mmh_timed_exam_resolve_recovery($conn, $exam, (int) $studentId, (int) ($_GET['recovery_plan'] ?? 0), (int) ($_GET['recovery_task'] ?? 0));
if (((int) ($_GET['recovery_plan'] ?? 0) > 0 || (int) ($_GET['recovery_task'] ?? 0) > 0) && !$recovery) { http_response_code(403); exit('Recovery Plan exam window unavailable.'); }
if ($recovery) $exam = $recovery['exam'];
$context = mmh_timed_exam_student_context($conn, $exam, (int) $studentId);
if (!in_array((string) ($context['state']['key'] ?? ''), ['open', 'grace', 'submitted', 'auto_submitted', 'graded'], true)) { http_response_code(403); exit('The exam paper is not available yet.'); }
mmh_timed_exam_download($conn, $exam, !empty($_GET['download']));
