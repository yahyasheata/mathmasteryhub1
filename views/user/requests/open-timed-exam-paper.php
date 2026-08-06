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
$stateKey = (string) ($context['state']['key'] ?? '');
if (!in_array($stateKey, ['open', 'grace'], true)) { http_response_code(403); exit('The exam paper is available only during the exam window.'); }
$paper = mmh_timed_exam_normalize_external_paper_url((string) ($exam['paper_external_url'] ?? ''));
if (!$paper) { http_response_code(409); exit('This exam paper needs a valid Google Drive file link.'); }

$action = strtolower(trim((string) ($_GET['paper_action'] ?? '')));
if ($action === '' && !empty($_GET['download'])) $action = 'download';
if (!in_array($action, ['', 'preview', 'open', 'download'], true)) {
    http_response_code(400);
    exit('Invalid exam paper action.');
}
if ($action === 'download' && empty($exam['paper_download_allowed'])) {
    http_response_code(403);
    exit('Exam paper downloads are disabled.');
}
if ($action !== 'download' && empty($exam['paper_view_allowed'])) {
    http_response_code(403);
    exit('Exam paper viewing is disabled.');
}
if ($action === '' || $action === 'preview') {
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    $fallbackQuery = ['paper_action' => 'open'];
    if (!empty($_GET['recovery_plan']) && !empty($_GET['recovery_task'])) {
        $fallbackQuery['recovery_plan'] = (int) $_GET['recovery_plan'];
        $fallbackQuery['recovery_task'] = (int) $_GET['recovery_task'];
    }
    $fallback = rtrim(mmh_current_request_base_url(), '/') . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/paper?' . http_build_query($fallbackQuery, '', '&', PHP_QUERY_RFC3986);
    $esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $esc((string) $exam['title']); ?> · Exam Preview</title>
    <style>body{margin:0;background:#111;color:#fff;font:16px system-ui,sans-serif}main{max-width:1100px;margin:0 auto;padding:16px}header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}h1{font-size:1.1rem;margin:0}a{color:#fff;background:#e87518;border-radius:8px;padding:10px 14px;text-decoration:none;font-weight:700}.paper-frame{width:100%;height:min(82vh,900px);border:0;border-radius:8px;background:#fff}.fallback{margin:12px 0 0;color:#ddd}</style></head><body><main><header><h1><?= $esc((string) $exam['title']); ?></h1><a href="<?= $esc($fallback); ?>" target="_blank" rel="noopener noreferrer">Open Exam in New Tab</a></header><iframe class="paper-frame" src="<?= $esc($paper['preview_url']); ?>" title="<?= $esc((string) $exam['title']); ?> exam paper" allow="fullscreen"></iframe><p class="fallback">Preview unavailable? <a href="<?= $esc($fallback); ?>" target="_blank" rel="noopener noreferrer">Open Exam in New Tab</a></p></main></body></html><?php
    exit;
}
if ($action === 'open') {
    mmh_timed_exam_download($conn, $exam, false);
}
if ($action === 'download') {
    mmh_timed_exam_download($conn, $exam, true);
}
http_response_code(400);
exit('Invalid exam paper action.');
