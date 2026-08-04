<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$title = trim((string) ($_POST['category_title'] ?? ''));
$link = trim((string) ($_POST['category_link'] ?? ''));
$description = trim((string) ($_POST['category_description'] ?? ''));
if ($title === '' || $link === '') {
    exit(json_encode(['status' => 0, 'message' => 'All required fields must be completed']));
}

$imagePath = 'uploads/static/courses/categories/default.jpg';
if (isset($_FILES['category_image']) && (int) ($_FILES['category_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $upload = json_decode(uploadImage($_FILES['category_image'], 'uploads/static/courses/categories'), true);
    if (!is_array($upload) || (int) ($upload['status'] ?? 0) !== 1 || trim((string) ($upload['file_path'] ?? '')) === '') {
        exit(json_encode(['status' => 0, 'message' => 'There was an error uploading the image']));
    }
    $imagePath = (string) $upload['file_path'];
}

$categoryId = random_int(99, 9999);
$adminUsername = (string) ($_SESSION['admin'] ?? '');
$stmt = db()->prepare('INSERT INTO categories (category_id, category_title, category_link, category_description, category_image, username) VALUES (?, ?, ?, ?, ?, ?)');
if (!$stmt) {
    exit(json_encode(['status' => 0, 'message' => 'Category could not be prepared.']));
}
$stmt->bind_param('isssss', $categoryId, $title, $link, $description, $imagePath, $adminUsername);
$ok = $stmt->execute();
$stmt->close();

echo json_encode($ok
    ? ['status' => 1, 'message' => 'Category added successfully']
    : ['status' => 0, 'message' => 'Database connection error']);
