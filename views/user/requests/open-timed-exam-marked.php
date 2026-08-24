<?php
require_once 'connection/config.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/TimedExam.php';
$conn = db();
$courseKey = student_course_access_identifier($courseId ?? '', 40);
$examIdValue = filter_var($examId ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$attemptIdValue = filter_var($attemptId ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$studentId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
if ($courseKey === null || $examIdValue === false || $attemptIdValue === false || !$studentId) { http_response_code(403); exit('Marked paper unavailable.'); }
$course = student_course_access_course($conn, $courseKey);
$exam = $course ? mmh_timed_exam_load($conn, (string) $course['course_id'], (int) $examIdValue, false) : null;
if (!$course || !$exam || !student_course_access_enrolled($conn, $studentId, (string) $course['course_id'])) { http_response_code(403); exit('Marked paper unavailable.'); }
// Refresh the canonical attempt state so scheduled result release follows the
// same rules as the normal student result page.
mmh_timed_exam_student_context($conn, $exam, (int) $studentId, false);
$paper = mmh_timed_exam_marked_paper_for_student($conn, (int) $attemptIdValue, (int) $examIdValue, (int) $studentId);
if (!$paper) { http_response_code(403); exit('Marked paper unavailable.'); }
mmh_timed_exam_marked_paper_serve($conn, $paper, (string) ($_GET['download'] ?? '') === '1');
