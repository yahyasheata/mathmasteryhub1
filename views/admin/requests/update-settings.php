<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/SiteSettings.php';
require_once 'inc/LandingPage.php';

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

function mmh_settings_landing_file(string $section, string $index, string $field): array
{
    $files = $_FILES['landing_items'] ?? null;
    if (!is_array($files)) return ['error' => UPLOAD_ERR_NO_FILE];
    return [
        'name' => $files['name'][$section][$index][$field] ?? '',
        'type' => $files['type'][$section][$index][$field] ?? '',
        'tmp_name' => $files['tmp_name'][$section][$index][$field] ?? '',
        'error' => $files['error'][$section][$index][$field] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$section][$index][$field] ?? 0,
    ];
}

function mmh_settings_upload_landing_photo(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [true, null, ''];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return [false, null, 'The selected photo could not be uploaded.'];
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) return [false, null, 'The uploaded photo could not be verified.'];
    if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 5 * 1024 * 1024) return [false, null, 'Photos must be smaller than 5 MB.'];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) return [false, null, 'Use a JPG, PNG, or WebP photo.'];

    $directory = dirname(__DIR__, 3) . '/uploads/static/landing';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) return [false, null, 'The landing photo folder is not writable.'];
    if (!is_writable($directory)) return [false, null, 'The landing photo folder is not writable.'];

    $filename = 'testimonial-' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) return [false, null, 'The testimonial photo could not be saved.'];
    return [true, 'uploads/static/landing/' . $filename, ''];
}

function mmh_settings_landing_trim($value, int $max): string
{
    $value = trim((string) (is_scalar($value) ? $value : ''));
    if ($max > 0 && (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > $max) {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
    return $value;
}

function mmh_settings_save_landing_items(mysqli $conn, array $postedRows, array &$uploadedPaths): void
{
    mmh_landing_ensure_schema($conn);
    $types = mmh_landing_item_types();

    foreach ($types as $section => $type) {
        $rows = is_array($postedRows[$section] ?? null) ? $postedRows[$section] : [];
        foreach ($rows as $index => $rawRow) {
            if (!is_array($rawRow)) continue;
            $index = (string) $index;
            $id = max(0, (int) ($rawRow['id'] ?? 0));
            $delete = !empty($rawRow['_delete']);

            if ($delete && $id > 0) {
                $stmt = $conn->prepare('DELETE FROM landing_page_items WHERE id = ? AND section_key = ?');
                if (!$stmt) throw new RuntimeException('Landing item delete could not be prepared.');
                $stmt->bind_param('is', $id, $section);
                if (!$stmt->execute()) throw new RuntimeException('Landing item delete failed.');
                $stmt->close();
                continue;
            }

            $data = [
                'icon' => mmh_landing_icon_class(mmh_settings_landing_trim($rawRow['icon'] ?? '', 80), ''),
                'title' => mmh_settings_landing_trim($rawRow['title'] ?? '', 190),
                'description' => mmh_settings_landing_trim($rawRow['description'] ?? '', 1200),
                'value' => mmh_settings_landing_trim($rawRow['value'] ?? '', 80),
                'label' => mmh_settings_landing_trim($rawRow['label'] ?? '', 190),
                'question' => mmh_settings_landing_trim($rawRow['question'] ?? '', 255),
                'answer' => mmh_settings_landing_trim($rawRow['answer'] ?? '', 1200),
                'student_name' => mmh_settings_landing_trim($rawRow['student_name'] ?? '', 190),
                'grade' => mmh_settings_landing_trim($rawRow['grade'] ?? '', 80),
                'exam_board' => mmh_settings_landing_trim($rawRow['exam_board'] ?? '', 120),
                'quote' => mmh_settings_landing_trim($rawRow['quote'] ?? '', 1200),
                'photo_path' => mmh_settings_landing_trim($rawRow['photo_path'] ?? '', 255),
                'sort_order' => max(0, min(999, (int) ($rawRow['sort_order'] ?? 0))),
                'status' => !empty($rawRow['published']) ? 'published' : 'draft',
            ];

            if ($section === 'testimonials') {
                [$validPhoto, $photoPath, $photoMessage] = mmh_settings_upload_landing_photo(mmh_settings_landing_file($section, $index, 'photo'));
                if (!$validPhoto) throw new RuntimeException($photoMessage ?: 'A testimonial photo could not be saved.');
                if ($photoPath !== null) {
                    $data['photo_path'] = $photoPath;
                    $uploadedPaths[] = $photoPath;
                }
            }

            if (!mmh_landing_item_has_content(array_merge($data, ['section_key' => $section])) && $id === 0) {
                continue;
            }
            if (!mmh_landing_item_has_content(array_merge($data, ['section_key' => $section])) && $id > 0) {
                $stmt = $conn->prepare('DELETE FROM landing_page_items WHERE id = ? AND section_key = ?');
                if (!$stmt) throw new RuntimeException('Empty landing item delete could not be prepared.');
                $stmt->bind_param('is', $id, $section);
                if (!$stmt->execute()) throw new RuntimeException('Empty landing item delete failed.');
                $stmt->close();
                continue;
            }

            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE landing_page_items SET item_type = ?, status = ?, icon = ?, title = ?, description = ?, value = ?, label = ?, question = ?, answer = ?, student_name = ?, grade = ?, exam_board = ?, quote = ?, photo_path = ?, sort_order = ?, updated_at = NOW() WHERE id = ? AND section_key = ?');
                if (!$stmt) throw new RuntimeException('Landing item update could not be prepared.');
                $stmt->bind_param('ssssssssssssssiis', $type, $data['status'], $data['icon'], $data['title'], $data['description'], $data['value'], $data['label'], $data['question'], $data['answer'], $data['student_name'], $data['grade'], $data['exam_board'], $data['quote'], $data['photo_path'], $data['sort_order'], $id, $section);
                if (!$stmt->execute()) throw new RuntimeException('Landing item update failed.');
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO landing_page_items (section_key, item_type, status, icon, title, description, value, label, question, answer, student_name, grade, exam_board, quote, photo_path, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                if (!$stmt) throw new RuntimeException('Landing item insert could not be prepared.');
                $stmt->bind_param('sssssssssssssssi', $section, $type, $data['status'], $data['icon'], $data['title'], $data['description'], $data['value'], $data['label'], $data['question'], $data['answer'], $data['student_name'], $data['grade'], $data['exam_board'], $data['quote'], $data['photo_path'], $data['sort_order']);
                if (!$stmt->execute()) throw new RuntimeException('Landing item insert failed.');
                $stmt->close();
            }
        }
    }
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
mmh_landing_ensure_schema($conn);
$definition = mmh_site_settings_definition();
$posted = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
$landingPosted = is_array($_POST['landing_items'] ?? null) ? $_POST['landing_items'] : [];
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
$landingUploads = [];
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
    mmh_settings_save_landing_items($conn, $landingPosted, $landingUploads);
    $conn->commit();
    mmh_settings_flash('success', 'Website settings saved successfully.');
} catch (Throwable $error) {
    $conn->rollback();
    foreach ($uploads as $path) @unlink(dirname(__DIR__, 3) . '/' . $path);
    foreach ($landingUploads as $path) @unlink(dirname(__DIR__, 3) . '/' . $path);
    mmh_settings_flash('error', 'Settings could not be saved. No existing site assets were removed.');
}

mmh_settings_redirect($baseUrl);
