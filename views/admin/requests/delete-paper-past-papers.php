<?php
require_once 'connection/config.php';
require_once 'inc/PastPapers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Unauthorized request.');
}

[$ok, $message] = mmh_past_delete_paper(db(), $_POST['paper_id'] ?? '');
mmh_past_flash($ok ? 'success' : 'error', $message);
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers');
exit;
?>
