<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/AdminCourseService.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_method'] ?? '') !== 'DELETE') {
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Invalid delete request.']));
}
$itemId = trim((string) ($_POST['item_id'] ?? ''));
$courseId = trim((string) ($_POST['course_id'] ?? ''));
if ($itemId === '') {
    http_response_code(422);
    exit(json_encode(['status' => 0, 'message' => 'Item ID is missing.']));
}

$conn = db();
try {
    mmh_admin_course_archive_item($conn, $itemId, $courseId !== '' ? $courseId : null);
    echo json_encode(['status' => 1, 'message' => 'Item deleted. Progress and submissions were preserved.']);
} catch (Throwable $e) {
    http_response_code($e->getMessage() === 'Lesson not found.' ? 404 : 500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage() === 'Lesson not found.' ? 'Lesson not found.' : 'Lesson could not be archived.']);
}
