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
    $file = $_FILES['progress_file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a CSV or Excel file to import.');
    }
    $filename = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xlsx'], true)) {
        throw new InvalidArgumentException('Previous progress import accepts CSV or XLSX files only.');
    }
    $adminId = mmh_auth_user_id($conn, (string) ($_SESSION['admin'] ?? ''));
    $result = mmh_previous_progress_import($conn, (string) $file['tmp_name'], $filename, $adminId);
    $message = 'Saved ' . (int) $result['imported'] . ' previous progress entr' . ((int) $result['imported'] === 1 ? 'y' : 'ies') . '.';
    if (!empty($result['skipped'])) {
        $message .= ' Skipped ' . count($result['skipped']) . ': ' . implode(' ', array_slice($result['skipped'], 0, 4));
    }
    $flash((int) $result['imported'] > 0, $message);
} catch (Throwable $exception) {
    $flash(false, $exception->getMessage());
}
