<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');

function tiny_image_error($message, $code = 400)
{
    http_response_code($code);
    echo json_encode(['success' => false, 'status' => 0, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tiny_image_error('Invalid request method.', 405);
}

if (empty($_FILES)) {
    tiny_image_error('Upload failed. No image file was received.');
}

$file = null;
foreach ($_FILES as $candidate) {
    if (isset($candidate['tmp_name'])) {
        $file = $candidate;
        break;
    }
}

if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    tiny_image_error('Upload failed. Please choose a valid image file.');
}

$max_size = 10 * 1024 * 1024;
if ((int) $file['size'] > $max_size) {
    tiny_image_error('Upload failed. Images must be 10MB or smaller.');
}

$image_info = @getimagesize($file['tmp_name']);
if ($image_info === false) {
    tiny_image_error('Upload failed. The selected file is not a valid image.');
}

$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, $allowed_extensions, true)) {
    tiny_image_error('Upload failed. Supported image types are JPG, PNG, GIF, and WEBP.');
}

$upload_dir = 'uploads/static/images/course-builder/';
if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true)) {
    tiny_image_error('Upload failed. The image upload directory could not be created.');
}

try {
    $safe_name = 'lesson_image_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
} catch (Throwable $e) {
    $safe_name = 'lesson_image_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $extension;
}

$target = $upload_dir . $safe_name;
if (!move_uploaded_file($file['tmp_name'], $target)) {
    tiny_image_error('Upload failed. The image could not be saved.');
}

$location = rtrim($baseUrl, '/') . '/' . ltrim($target, '/');
echo json_encode([
    'success' => true,
    'status' => 1,
    'message' => 'Image uploaded successfully.',
    'location' => $location,
]);
?>
