<?php
require_once 'connection/config.php';
require_once 'inc/CourseContentCopyService.php';

header('Content-Type: application/json; charset=utf-8');

function copy_section_response(bool $success, string $message, array $data = []): never
{
    echo json_encode(array_merge(['success' => $success, 'status' => $success ? 1 : 0, 'message' => $message], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') copy_section_response(false, 'Copy section requires a POST request.');
$sourceCourseId = trim((string) ($_POST['source_course_id'] ?? ''));
$sourceSectionId = trim((string) ($_POST['source_section_id'] ?? ''));
$destinationCourseId = trim((string) ($_POST['destination_course_id'] ?? ''));
if ($sourceCourseId === '' || $sourceSectionId === '' || $destinationCourseId === '') copy_section_response(false, 'Choose a source section and destination course.');

try {
    $result = CourseContentCopyService::copySection(db(), $sourceCourseId, $sourceSectionId, $destinationCourseId);
    $message = 'Section copied successfully.';
    if ($result['warnings']) $message .= ' ' . implode(' ', $result['warnings']);
    copy_section_response(true, $message, $result);
} catch (Throwable $e) {
    copy_section_response(false, $e->getMessage() ?: 'The section could not be copied.');
}
