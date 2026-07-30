<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/PreviousProgress.php';

$conn = db();
$redirect = rtrim((string) $baseUrl, '/') . '/admin/previous-progress';
$flash = static function (bool $ok, string $message) use ($redirect): void {
    $_SESSION['previous_progress_flash'] = ['ok' => $ok, 'message' => $message];
    header('Location: ' . $redirect);
    exit;
};

if (!mmh_auth_csrf_valid($_POST['_token'] ?? '')) {
    $flash(false, 'Your session has expired. Refresh and try again.');
}

try {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) { throw new InvalidArgumentException('Choose a valid previous progress record.'); }
    mmh_previous_progress_delete($conn, $id);
    $flash(true, 'Previous progress record deleted.');
} catch (Throwable $exception) {
    $flash(false, $exception->getMessage());
}
