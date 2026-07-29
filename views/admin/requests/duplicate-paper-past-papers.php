<?php
require_once 'connection/config.php';
require_once 'inc/PastPapers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Unauthorized request.');
}

[$ok, $message, $data] = array_pad(mmh_past_duplicate_paper(db(), $_POST['paper_id'] ?? ''), 3, []);
mmh_past_flash($ok ? 'success' : 'error', $message);
$paperId = is_array($data) && !empty($data['paper_id']) ? '?paper=' . rawurlencode($data['paper_id']) . '#paper-form' : '';
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers' . $paperId);
exit;
?>
