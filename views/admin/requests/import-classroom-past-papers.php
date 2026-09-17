<?php
declare(strict_types=1);

require_once 'connection/config.php';
require_once 'inc/PastPaperClassroomScanner.php';

$base = rtrim((string) ($baseUrl ?? mmh_current_request_base_url()), '/');
$preview = is_array($_SESSION['mmh_classroom_scan_preview'] ?? null) ? $_SESSION['mmh_classroom_scan_preview'] : null;
$selected = is_array($_POST['candidate_keys'] ?? null) ? $_POST['candidate_keys'] : [];
if (!$preview) {
    $_SESSION['mmh_classroom_flash'] = ['type' => 'error', 'message' => 'The Classroom scan preview is no longer available. Run the scan again before importing.'];
    header('Location: ' . $base . '/admin/past-papers/classroom');
    exit;
}
$result = mmh_classroom_import_candidates(db(), $preview, $selected, (string) ($_SESSION['admin'] ?? ''));
$message = sprintf('%d selected · %d created · %d existing skipped · %d blocked · %d failed · %d resources created.', $result['selected'], $result['created'], $result['skipped_existing'], $result['blocked'], $result['failed'], $result['resources_created']);
if ($result['missing_optional']) $message .= ' Optional source resources missing: ' . $result['missing_optional'] . '.';
if ($result['failures']) $message .= ' ' . implode(' ', array_map(static fn(array $failure): string => $failure['identity'] . ': ' . $failure['message'], $result['failures']));
$_SESSION['mmh_classroom_flash'] = ['type' => $result['failed'] > 0 ? 'error' : 'success', 'message' => $message];
if ($result['imported_keys']) {
    $imported = array_fill_keys($result['imported_keys'], true);
    foreach ($preview['candidates'] as &$candidate) {
        if (isset($imported[(string) ($candidate['candidate_key'] ?? '')])) {
            $candidate['existing_paper'] = ['paper_id' => 'imported', 'status' => 'published', 'short_title' => $candidate['paper_number']];
        }
    }
    unset($candidate);
    $_SESSION['mmh_classroom_scan_preview'] = $preview;
}
header('Location: ' . $base . '/admin/past-papers/classroom?course_id=' . rawurlencode((string) ($preview['course']['id'] ?? '')) . '#import');
exit;
