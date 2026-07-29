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

[$ok, $message, $data] = array_pad(mmh_past_drive_scan(db(), $_POST, (string) $_SESSION['admin'], true), 3, []);
mmh_past_flash($ok ? 'success' : 'error', $message);
$suffix = $ok && !empty($data['job_id']) ? '?drive_job=' . rawurlencode((string) $data['job_id']) . '#drive-import' : '#drive-import';
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers' . $suffix);
exit;
