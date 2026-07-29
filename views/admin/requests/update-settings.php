<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/SiteSettings.php';

function mmh_settings_redirect(string $baseUrl): void
{
    header('Location: ' . rtrim(mmh_site_public_base_path(), '/') . '/admin/settings');
    exit;
}

function mmh_settings_flash(string $type, string $message, array $old = [], array $errors = []): void
{
    $_SESSION['mmh_site_settings_flash'] = [
        'type' => $type,
        'message' => $message,
        'old' => $old,
        'errors' => $errors,
    ];
}

function mmh_settings_upload_asset(array $file, string $key): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [true, null, ''];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return [false, null, 'The selected file could not be uploaded.'];
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) return [false, null, 'The uploaded file could not be verified.'];
    if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 5 * 1024 * 1024) return [false, null, 'Images must be smaller than 5 MB.'];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];
    if (!isset($allowed[$mime])) return [false, null, 'Use a JPG, PNG, WebP, GIF, or ICO image. SVG files are not accepted.'];
    if ($key === 'website_icon' && !in_array($allowed[$mime], ['png', 'ico', 'webp'], true)) return [false, null, 'Use PNG, WebP, or ICO for the favicon.'];

    $directory = dirname(__DIR__, 3) . '/uploads/static/site';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) return [false, null, 'The site asset folder is not writable.'];
    if (!is_writable($directory)) return [false, null, 'The site asset folder is not writable.'];

    $filename = $key . '-' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) return [false, null, 'The image could not be saved.'];
    return [true, 'uploads/static/site/' . $filename, ''];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    exit('Administrator access is required.');
}
if (!mmh_auth_csrf_valid($_POST['csrf_token'] ?? null)) {
    mmh_settings_flash('error', 'Your session expired. Refresh the page and try again.');
    mmh_settings_redirect($baseUrl);
}

$conn = db();
$definition = mmh_site_settings_definition();
$posted = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
$clean = [];
$errors = [];

foreach ($definition as $key => $meta) {
    $raw = $posted[$key] ?? ($meta['type'] === 'toggle' ? '0' : '');
    [$valid, $value, $message] = mmh_site_settings_validate($key, $raw);
    if (!$valid) {
        $errors[$key] = $message;
        continue;
    }
    $clean[$key] = $value;
}

$uploads = [];
foreach (['website_logo', 'website_wide_logo', 'website_icon', 'website_cover'] as $key) {
    $file = $_FILES['settings'] ?? null;
    $candidate = is_array($file) ? [
        'name' => $file['name'][$key] ?? '',
        'type' => $file['type'][$key] ?? '',
        'tmp_name' => $file['tmp_name'][$key] ?? '',
        'error' => $file['error'][$key] ?? UPLOAD_ERR_NO_FILE,
        'size' => $file['size'][$key] ?? 0,
    ] : ['error' => UPLOAD_ERR_NO_FILE];
    [$valid, $path, $message] = mmh_settings_upload_asset($candidate, $key);
    if (!$valid) $errors[$key] = $message;
    if ($path !== null) $uploads[$key] = $path;
}

if (!empty($errors)) {
    foreach ($uploads as $path) @unlink(dirname(__DIR__, 3) . '/' . $path);
    mmh_settings_flash('error', 'Please correct the highlighted settings and try again.', $posted, $errors);
    mmh_settings_redirect($baseUrl);
}

$conn->begin_transaction();
try {
    foreach ($clean as $key => $value) {
        $category = (string) ($definition[$key]['category'] ?? 'General');
        if (!mmh_site_settings_upsert($conn, $key, $value, $category)) throw new RuntimeException('A setting could not be saved.');
    }
    foreach ($uploads as $key => $path) {
        if (!mmh_site_settings_upsert($conn, $key, $path, 'Branding')) throw new RuntimeException('A site asset reference could not be saved.');
    }
    $conn->commit();
    mmh_settings_flash('success', 'Website settings saved successfully.');
} catch (Throwable $error) {
    $conn->rollback();
    foreach ($uploads as $path) @unlink(dirname(__DIR__, 3) . '/' . $path);
    mmh_settings_flash('error', 'Settings could not be saved. No existing site assets were removed.');
}

mmh_settings_redirect($baseUrl);
