<?php
require_once 'connection/config.php';
require_once 'inc/PastPapers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Unauthorized request.');
}

[$ok, $message] = mmh_past_save_resource(db(), $_POST, $_FILES);
mmh_past_flash($ok ? 'success' : 'error', $message);
$paper = mmh_past_identifier($_POST['paper_id'] ?? '', 40);
$suffix = $paper ? '?paper=' . rawurlencode($paper) . '#resources' : '';
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers' . $suffix);
exit;
?>
