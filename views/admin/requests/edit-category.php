<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Method Not Allowed']));
}
$conn = db();

if ((string) ($_POST['_method'] ?? '') === 'GET') {
    $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($categoryId === false) { exit(json_encode(['status' => 0, 'message' => 'Invalid category.'])); }
    $stmt = $conn->prepare('SELECT category_id, category_title, category_link, category_description, category_image FROM categories WHERE category_id = ? LIMIT 1');
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $category = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$category) { http_response_code(404); exit(json_encode(['status' => 0, 'message' => 'Category not found.'])); }
    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $html = '<div class="modal fade show" id="response-html-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Edit Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><form action="requests/category/edit" method="POST" id="updateCategory" enctype="multipart/form-data"><input type="hidden" name="category_id" value="'.$e($category['category_id']).'"><input type="hidden" name="_method" value="UPDATE"><input type="hidden" name="mmh_csrf_token" value="'.htmlspecialchars(mmh_admin_csrf_token(), ENT_QUOTES, 'UTF-8').'"><label>Title<input type="text" name="category_title" required maxlength="190" class="form-control" value="'.$e($category['category_title']).'"></label><label>Link<input type="text" name="category_link" required maxlength="190" class="form-control" value="'.$e($category['category_link']).'"></label><label>Description<textarea class="form-control" name="category_description" rows="3" required>'.$e($category['category_description']).'</textarea></label><label>Category image<input type="file" name="category_image" class="form-control" accept="image/*"></label><div class="modal-footer"><button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save</button></div></form></div></div></div></div>';
    exit(json_encode(['status' => 1, 'html' => $html]));
}

if ((string) ($_POST['_method'] ?? '') !== 'UPDATE') {
    exit(json_encode(['status' => 0, 'message' => 'Invalid update request.']));
}
$categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$title = trim((string) ($_POST['category_title'] ?? ''));
$link = trim((string) ($_POST['category_link'] ?? ''));
$description = trim((string) ($_POST['category_description'] ?? ''));
if ($categoryId === false || $title === '' || $link === '') { exit(json_encode(['status' => 0, 'message' => 'All required fields must be completed'])); }

$imagePath = null;
if (isset($_FILES['category_image']) && (int) ($_FILES['category_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $upload = json_decode(uploadImage($_FILES['category_image'], 'uploads/static/courses/categories'), true);
    if (!is_array($upload) || (int) ($upload['status'] ?? 0) !== 1) { exit(json_encode(['status' => 0, 'message' => 'There was an error uploading the image'])); }
    $imagePath = (string) ($upload['file_path'] ?? '');
}

if ($imagePath !== null && $imagePath !== '') {
    $stmt = $conn->prepare('UPDATE categories SET category_title = ?, category_link = ?, category_description = ?, category_image = ? WHERE category_id = ?');
    $stmt->bind_param('ssssi', $title, $link, $description, $imagePath, $categoryId);
} else {
    $stmt = $conn->prepare('UPDATE categories SET category_title = ?, category_link = ?, category_description = ? WHERE category_id = ?');
    $stmt->bind_param('sssi', $title, $link, $description, $categoryId);
}
$ok = $stmt && $stmt->execute();
if ($stmt) { $stmt->close(); }
echo json_encode(['status' => $ok ? 1 : 0, 'message' => $ok ? 'Category updated successfully' : 'Database connection error']);
