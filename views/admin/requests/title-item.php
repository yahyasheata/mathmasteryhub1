<?php
require_once 'connection/config.php';

header('Content-Type: application/json; charset=utf-8');

function title_item_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function title_item_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    title_item_response(false, 'Invalid request method.');
}

if (!in_array(title_item_post('_method'), ['TITLE', 'UPDATE'], true)) {
    title_item_response(false, 'Invalid title update request.');
}

$conn = db();
$course_id = title_item_post('course_id');
$item_id = title_item_post('item_id');
$title = title_item_post('title');

if ($course_id === '' || $item_id === '' || $title === '') {
    title_item_response(false, 'Validation failed. Lesson title, lesson ID, or course ID is missing.');
}

if (mb_strlen($title) > 190) {
    title_item_response(false, 'Validation failed. Lesson title is too long.');
}

$stmt = $conn->prepare('UPDATE course_items SET item_title = ? WHERE item_id = ? AND course_id = ? LIMIT 1');
if (!$stmt) {
    title_item_response(false, 'Unable to prepare lesson title update.', ['reason' => $conn->error]);
}
$stmt->bind_param('sss', $title, $item_id, $course_id);
if (!$stmt->execute()) {
    title_item_response(false, 'Unexpected server error while updating the lesson title.', ['reason' => $stmt->error ?: $conn->error]);
}

if ($stmt->affected_rows < 1) {
    title_item_response(false, 'Lesson title was not changed.');
}
$stmt->close();

title_item_response(true, 'Lesson title updated.', [
    'course_id' => $course_id,
    'item_id' => $item_id,
    'title' => $title,
]);
?>
