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
[$ok, $message] = array_pad(mmh_past_bulk_parse_csv(db(), $_POST['bulk_csv'] ?? ''), 2, '');
mmh_past_flash($ok ? 'success' : 'error', $message);
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers#bulk-links');
exit;
