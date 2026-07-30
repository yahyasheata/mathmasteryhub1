<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/PreviousProgress.php';

$conn = db();
$redirect = rtrim((string) $baseUrl, '/') . '/admin/previous-progress';
$flash = static function (bool $ok, string $message, string $courseId = '') use ($redirect): void {
    $_SESSION['previous_progress_flash'] = ['ok' => $ok, 'message' => $message];
    $target = $redirect . ($courseId !== '' ? '?course_id=' . rawurlencode($courseId) : '');
    header('Location: ' . $target);
    exit;
};

if (!mmh_auth_csrf_valid($_POST['_token'] ?? '')) {
    $flash(false, 'Your session has expired. Refresh and try again.');
}

try {
    $payload = mmh_previous_progress_validate_payload($_POST);
    $adminId = mmh_auth_user_id($conn, (string) ($_SESSION['admin'] ?? ''));
    mmh_previous_progress_save($conn, $payload, $adminId);
    $flash(true, 'Previous progress saved.', $payload['course_id']);
} catch (Throwable $exception) {
    $flash(false, $exception->getMessage(), trim((string) ($_POST['course_id'] ?? '')));
}
