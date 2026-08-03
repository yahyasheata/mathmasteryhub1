<?php
require_once 'connection/config.php';
require_once 'inc/Auth.php';
require_once 'inc/TimedExam.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mmh_auth_csrf_valid($_POST['_token'] ?? '')) { http_response_code(419); exit('Your session has expired.'); }
$conn = db();
$attemptId = (int) ($_POST['attempt_id'] ?? 0);
$courseId = trim((string) ($_POST['course_id'] ?? ''));
$examId = (int) ($_POST['exam_id'] ?? 0);
$exam = mmh_timed_exam_load($conn, $courseId, $examId, true);
if (!$exam || $attemptId <= 0) { http_response_code(404); exit('Exam attempt not found.'); }
$grade = trim((string) ($_POST['grade'] ?? ''));
$gradeValue = $grade === '' ? null : max(0, (float) $grade);
if ($gradeValue !== null && $exam['max_marks'] !== null && $gradeValue > (float) $exam['max_marks']) { http_response_code(422); exit('Grade exceeds the total marks.'); }
$feedback = mb_substr(trim((string) ($_POST['feedback'] ?? '')), 0, 5000);
$release = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$stmt = $conn->prepare("UPDATE timed_exam_attempts SET state = 'graded', grade = ?, feedback = ?, results_released_at_utc = ? WHERE id = ? AND timed_exam_id = ? AND state IN ('submitted','auto_submitted','graded')");
if (!$stmt) { http_response_code(500); exit('Unable to save grade.'); }
$releaseAt = $release->format('Y-m-d H:i:s');
$stmt->bind_param('dssii', $gradeValue, $feedback, $releaseAt, $attemptId, $examId);
if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); http_response_code(500); exit($error ?: 'Unable to save grade.'); }
$stmt->close();
$studentStmt = $conn->prepare('SELECT student_id FROM timed_exam_attempts WHERE id = ? LIMIT 1');
$studentId = 0;
if ($studentStmt) { $studentStmt->bind_param('i', $attemptId); $studentStmt->execute(); $studentId = (int) (($studentStmt->get_result()->fetch_assoc()['student_id'] ?? 0)); $studentStmt->close(); }
if ($studentId > 0) {
    $title = 'Timed Exam result available';
    $body = 'Your result for ' . (string) $exam['title'] . ' is now available.';
    $notice = $conn->prepare('SELECT id FROM notifications WHERE user_id = ? AND title = ? AND message = ? LIMIT 1');
    $exists = false;
    if ($notice) { $notice->bind_param('iss', $studentId, $title, $body); $notice->execute(); $exists = $notice->get_result()->num_rows > 0; $notice->close(); }
    if (!$exists) { $insertNotice = $conn->prepare('INSERT INTO notifications (user_id, title, message, status) VALUES (?, ?, ?, 0)'); if ($insertNotice) { $insertNotice->bind_param('iss', $studentId, $title, $body); $insertNotice->execute(); $insertNotice->close(); } }
}
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/timed-exam-submissions/' . rawurlencode($courseId) . '/' . $examId, true, 303);
exit;
