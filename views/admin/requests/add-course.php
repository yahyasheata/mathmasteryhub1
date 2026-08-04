<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/AcademicMetadata.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$required = ['course_title', 'course_title_en', 'course_description'];
foreach ($required as $field) {
    if (trim((string) ($_POST[$field] ?? '')) === '') {
        exit(json_encode(['status' => 0, 'message' => 'All required fields must be completed']));
    }
}

$courseId = random_int(99, 9999);
$courseTitle = trim((string) $_POST['course_title']);
$courseTitleEn = trim((string) $_POST['course_title_en']);
$courseDescription = trim((string) $_POST['course_description']);
$coursePrice = is_numeric($_POST['course_price'] ?? null) ? (float) $_POST['course_price'] : 0.0;
$preDiscountPrice = is_numeric($_POST['preDiscount_course_price'] ?? null) ? (float) $_POST['preDiscount_course_price'] : 0.0;
$category = filter_var($_POST['course_category'] ?? null, FILTER_VALIDATE_INT);
$whatsappGroup = trim((string) ($_POST['whatsapp_group'] ?? ''));
$whatsappGroup = $whatsappGroup === '' ? null : $whatsappGroup;
$sequentialLearning = (string) ($_POST['sequential_learning'] ?? '') === '1' ? 1 : 0;
$scoreMode = mmh_academic_score_mode($_POST['default_homework_score_mode'] ?? 'disabled');
$adminUsername = (string) ($_SESSION['admin'] ?? '');

$imagePath = 'uploads/static/courses/default.jpg';
if (isset($_FILES['course_image']) && (int) ($_FILES['course_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $upload = json_decode(uploadImage($_FILES['course_image'], 'uploads/static/courses', null), true);
    if (!is_array($upload) || (int) ($upload['status'] ?? 0) !== 1 || trim((string) ($upload['file_path'] ?? '')) === '') {
        exit(json_encode(['status' => 0, 'message' => 'There was an error uploading the image']));
    }
    $imagePath = (string) $upload['file_path'];
}

$conn = db();
$stmt = $conn->prepare('INSERT INTO courses (course_id, course_title, course_title_en, course_description, course_image, course_price, preDiscount_course_price, course_category, whatsapp_group, sequential_learning, default_homework_score_mode, username) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
if (!$stmt) {
    exit(json_encode(['status' => 0, 'message' => 'Course could not be prepared.']));
}
$stmt->bind_param('issssddisiss', $courseId, $courseTitle, $courseTitleEn, $courseDescription, $imagePath, $coursePrice, $preDiscountPrice, $category, $whatsappGroup, $sequentialLearning, $scoreMode, $adminUsername);
$ok = $stmt->execute();
$stmt->close();

echo json_encode($ok
    ? ['status' => 1, 'message' => 'Course added successfully']
    : ['status' => 0, 'message' => 'Database connection error']);
