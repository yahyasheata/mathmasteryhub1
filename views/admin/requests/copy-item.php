<?php
require_once 'connection/config.php';
require_once 'inc/CourseContentCopyService.php';

header('Content-Type: application/json; charset=utf-8');

function copy_item_response(bool $success, string $message, array $data = []): never
{
    echo json_encode(array_merge(['success' => $success, 'status' => $success ? 1 : 0, 'message' => $message], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') copy_item_response(false, 'Copy item requires a POST request.');
$sourceCourseId = trim((string) ($_POST['source_course_id'] ?? ''));
$sourceItemId = trim((string) ($_POST['source_item_id'] ?? ''));
$destinationCourseId = trim((string) ($_POST['destination_course_id'] ?? ''));
$destinationSectionId = trim((string) ($_POST['destination_section_id'] ?? ''));
if ($sourceCourseId === '' || $sourceItemId === '' || $destinationCourseId === '') copy_item_response(false, 'Choose a source item and destination course.');

try {
    $result = CourseContentCopyService::copyItem(db(), $sourceCourseId, $sourceItemId, $destinationCourseId, $destinationSectionId !== '' ? $destinationSectionId : null);
    $message = 'Item copied successfully.';
    if ($result['warnings']) $message .= ' ' . implode(' ', $result['warnings']);
    copy_item_response(true, $message, $result);
} catch (Throwable $e) {
    copy_item_response(false, $e->getMessage() ?: 'The item could not be copied.');
}
