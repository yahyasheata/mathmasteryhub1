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
$sectionId = trim((string) ($_POST['section_id'] ?? ''));
if ($courseId === '' || $sectionId === '' || $sectionId === '__general__') $respond(false, 'Validation failed. Section or course is missing.');

try {
    $result = CourseContentCopyService::copySection(db(), $courseId, $sectionId, $courseId);
    $respond(true, 'Section duplicated successfully.', $result);
} catch (Throwable $e) {
    $respond(false, $e->getMessage() ?: 'The section could not be duplicated.');
}
