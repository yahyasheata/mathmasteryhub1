<?php
require_once 'connection/config.php';
require_once 'inc/PastPaperDriveImport.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mmh_past_drive_admin_guard()) {
    http_response_code(403);
    exit('Unauthorized request.');
}
if (!mmh_past_drive_csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your form session expired. Reload the Past Papers page and try again.');
}

$jobId = mmh_past_identifier($_POST['job_id'] ?? '', 40);
$restart = (string) ($_POST['restart'] ?? '') === '1';
[$ok, $message] = array_pad(mmh_past_drive_reanalyze_job(db(), $jobId, (string) $_SESSION['admin'], $restart), 2, '');
mmh_past_flash($ok ? 'success' : 'error', $message);
$suffix = $jobId ? '?drive_job=' . rawurlencode($jobId) . '#drive-import' : '#drive-import';
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers' . $suffix);
exit;
