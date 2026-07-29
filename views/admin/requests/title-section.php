<?php
require_once 'connection/config.php';

header('Content-Type: application/json; charset=utf-8');

function title_section_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function title_section_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    title_section_response(false, 'Invalid request method.');
}

if (!in_array(title_section_post('_method'), ['TITLE', 'UPDATE'], true)) {
    title_section_response(false, 'Invalid title update request.');
}

$conn = db();
$course_id = title_section_post('course_id');
$section_id = title_section_post('section_id');
$title = title_section_post('title');

if ($course_id === '' || $section_id === '' || $section_id === '__general__' || $title === '') {
    title_section_response(false, 'Validation failed. Section title, section ID, or course ID is missing.');
}

if (mb_strlen($title) > 190) {
    title_section_response(false, 'Validation failed. Section title is too long.');
}

$stmt = $conn->prepare('UPDATE course_sections SET title = ? WHERE section_id = ? AND course_id = ? LIMIT 1');
if (!$stmt) {
    title_section_response(false, 'Unable to prepare section title update.', ['reason' => $conn->error]);
}
$stmt->bind_param('sss', $title, $section_id, $course_id);
if (!$stmt->execute()) {
    title_section_response(false, 'Unexpected server error while updating the section title.', ['reason' => $stmt->error ?: $conn->error]);
}

if ($stmt->affected_rows < 1) {
    title_section_response(false, 'Section title was not changed.');
}
$stmt->close();

title_section_response(true, 'Section title updated.', [
    'course_id' => $course_id,
    'section_id' => $section_id,
    'title' => $title,
]);
?>
