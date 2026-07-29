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

$action = (string) ($_POST['action'] ?? 'process_batch');
if ($action === 'save_correction') {
    [$ok, $message, $data] = array_pad(mmh_past_drive_save_candidate_correction(db(), $_POST['job_id'] ?? '', $_POST['candidate_id'] ?? '', $_POST, (string) $_SESSION['admin']), 3, []);
} elseif ($action === 'process_batch') {
    // Automatic batches accept only CSRF, job ID and action. Candidate
    // selection and progress live in the database, avoiding max_input_vars.
    [$ok, $message, $data] = array_pad(mmh_past_drive_import_batch(db(), $_POST['job_id'] ?? '', (string) $_SESSION['admin'], 50), 3, []);
} elseif ($action === 'publish_created') {
    // Recovery action for older completed jobs that used the prior draft
    // default. It only touches this job's successfully-created records.
    [$ok, $message, $data] = array_pad(mmh_past_drive_publish_created_records(db(), $_POST['job_id'] ?? ''), 3, []);
} else {
    http_response_code(400);
    exit('Unsupported import action.');
}
mmh_past_flash($ok ? 'success' : 'error', $message);
$job = mmh_past_identifier($_POST['job_id'] ?? '', 40);
$suffix = $job ? '?drive_job=' . rawurlencode($job) . '#drive-import' : '#drive-import';
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers' . $suffix);
exit;
