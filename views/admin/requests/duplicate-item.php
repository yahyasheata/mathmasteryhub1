<?php
require_once 'connection/config.php';
require_once 'inc/CourseContentCopyService.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (bool $success, string $message, array $data = []): never {
    echo json_encode(array_merge(['success' => $success, 'status' => $success ? 1 : 0, 'message' => $message], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || trim((string) ($_POST['_method'] ?? '')) !== 'DUPLICATE') {
    $respond(false, 'Invalid duplicate request.');
}

$courseId = trim((string) ($_POST['course_id'] ?? ''));
$itemId = trim((string) ($_POST['item_id'] ?? ''));
if ($courseId === '' || $itemId === '') $respond(false, 'Validation failed. Item or course is missing.');

try {
    $conn = db();
    $sourceStmt = $conn->prepare('SELECT section_id FROM course_items WHERE course_id = ? AND item_id = ? LIMIT 1');
    $sourceStmt->bind_param('ss', $courseId, $itemId);
    $sourceStmt->execute();
    $source = $sourceStmt->get_result()->fetch_assoc() ?: [];
    $sourceStmt->close();
    $result = CourseContentCopyService::copyItem($conn, $courseId, $itemId, $courseId, trim((string) ($source['section_id'] ?? '')) ?: null);
    $respond(true, 'Lesson duplicated successfully.', $result);
} catch (Throwable $e) {
    $respond(false, $e->getMessage() ?: 'The lesson could not be duplicated.');
}
