<?php
require_once 'connection/config.php';
require_once 'inc/PastPapers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Unauthorized request.');
}

if (!empty($_POST['quick_add'])) {
    [$ok, $message, $data] = array_pad(mmh_past_quick_add(db(), $_POST, $_FILES, $_SESSION['admin'] ?? ''), 3, []);
    if (!$ok) {
        // Preserve scalar/nested form values so a validation error never wipes the teacher's work.
        $preserved = $_POST;
        unset($preserved['mmh_csrf_token'], $preserved['csrf_token']);
        $_SESSION['past_quick_form'] = $preserved;
    } else {
        unset($_SESSION['past_quick_form']);
    }
    mmh_past_flash($ok ? 'success' : 'error', $message);
    $paperId = $ok && is_array($data) && !empty($data['paper_id']) ? '?paper=' . rawurlencode($data['paper_id']) . '#paper-form' : '?quick_add=1#quick-add';
    header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers' . $paperId);
    exit;
}

[$ok, $message, $data] = array_pad(mmh_past_save_paper(db(), $_POST), 3, []);
mmh_past_flash($ok ? 'success' : 'error', $message);
$paperId = is_array($data) && !empty($data['paper_id']) ? '?paper=' . rawurlencode($data['paper_id']) . '#paper-form' : '';
header('Location: ' . rtrim((string) $baseUrl, '/') . '/admin/past-papers' . $paperId);
exit;
?>
