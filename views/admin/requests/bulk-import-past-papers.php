<?php
require_once 'connection/config.php';
require_once 'inc/PastPapers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Unauthorized request.');
}
if (!mmh_past_bulk_csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your form session expired. Reload Past Papers and try again.');
}
$indexes = $_POST['row_indexes'] ?? [];
if (!is_array($indexes)) $indexes = [];
[$ok, $message, $data] = array_pad(mmh_past_bulk_import_rows(db(), $indexes), 3, []);
if (!$ok && !empty($data['errors'])) $message .= ' ' . implode(' ', array_slice($data['errors'], 0, 3));
mmh_past_flash($ok ? 'success' : 'error', $message);
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers#bulk-links');
exit;
