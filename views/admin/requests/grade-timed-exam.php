<?php
require_once 'connection/config.php';
require_once 'inc/Auth.php';
require_once 'inc/AdminSecurity.php';
require_once 'inc/TimedExam.php';

// The routed admin mutation middleware performs the same checks before this
// handler; keep the guard here too so direct inclusion cannot bypass them.
mmh_admin_require_mutation();
$conn = db();
$attemptId = (int) ($_POST['attempt_id'] ?? 0);
$courseId = trim((string) ($_POST['course_id'] ?? ''));
$examId = (int) ($_POST['exam_id'] ?? 0);
$exam = $courseId !== '' ? mmh_timed_exam_load($conn, $courseId, $examId, true) : null;
if (!$exam || $attemptId <= 0) { http_response_code(404); exit('Exam attempt not found.'); }
$grade = trim((string) ($_POST['grade'] ?? ''));
$gradeValue = $grade === '' ? null : max(0, (float) $grade);
if ($gradeValue !== null && $exam['max_marks'] !== null && $gradeValue > (float) $exam['max_marks']) { http_response_code(422); exit('Grade exceeds the total marks.'); }
$feedback = mb_substr(trim((string) ($_POST['feedback'] ?? '')), 0, 5000);
$action = strtolower(trim((string) ($_POST['grade_action'] ?? 'save')));
if (!in_array($action, ['save', 'release'], true)) { http_response_code(422); exit('Invalid grading action.'); }
$removeMarked = !empty($_POST['remove_marked_pdf']);
$markedFile = isset($_FILES['marked_pdf']) && is_array($_FILES['marked_pdf']) ? $_FILES['marked_pdf'] : null;
$adminId = mmh_auth_user_id($conn, (string) ($_SESSION['admin'] ?? ''));
$saved = mmh_timed_exam_save_marking($conn, $exam, $attemptId, $gradeValue, $feedback, $markedFile, $removeMarked, $adminId);
if (empty($saved['success'])) { http_response_code(409); exit($saved['message'] ?? 'Unable to save marking.'); }
if ($action === 'release') {
    $release = mmh_timed_exam_release_result($conn, $exam, $attemptId, true);
    if (empty($release['success'])) { http_response_code(500); exit($release['message'] ?? 'Unable to release the result.'); }
}
header('Location: ' . rtrim(mmh_current_request_base_url(), '/') . '/admin/timed-exam-submissions/' . rawurlencode($courseId) . '/' . $examId, true, 303);
exit;
