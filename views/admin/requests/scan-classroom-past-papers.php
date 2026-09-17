<?php
declare(strict_types=1);

require_once 'connection/config.php';
require_once 'inc/PastPaperClassroomScanner.php';

$base = rtrim((string) ($baseUrl ?? mmh_current_request_base_url()), '/');
$courseId = trim((string) ($_POST['course_id'] ?? ''));
$syllabusId = trim((string) ($_POST['syllabus_id'] ?? ''));
[$ok, $message, $preview] = mmh_classroom_scan(db(), $courseId, $syllabusId, $base);
if ($ok && is_array($preview)) {
    $_SESSION['mmh_classroom_scan_preview'] = $preview;
}
$_SESSION['mmh_classroom_flash'] = ['type' => $ok ? 'success' : 'error', 'message' => $message];
header('Location: ' . $base . '/admin/past-papers/classroom?course_id=' . rawurlencode($courseId) . '&syllabus_id=' . rawurlencode($syllabusId));
exit;
