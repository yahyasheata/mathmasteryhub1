<?php
require_once 'connection/config.php';

header('Content-Type: application/json; charset=utf-8');

function delete_section_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function delete_section_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function delete_section_move_options(mysqli $conn, $course_id, $section_id)
{
    $options = ['__general__' => 'General'];
    $stmt = $conn->prepare('SELECT section_id, title FROM course_sections WHERE course_id = ? AND section_id <> ? ORDER BY sort_order ASC, id ASC');
    $stmt->bind_param('ss', $course_id, $section_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $options[$row['section_id']] = $row['title'];
    }
    $stmt->close();
    return $options;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    delete_section_response(false, 'Invalid request method.');
}

if (!isset($_POST['_method']) || $_POST['_method'] !== 'DELETE') {
    delete_section_response(false, 'Invalid delete request.');
}

$conn = db();
$course_id = delete_section_post('course_id');
$section_id = delete_section_post('section_id');

if ($course_id === '' || $section_id === '' || $section_id === '__general__') {
    delete_section_response(false, 'General cannot be deleted.');
}

$section_stmt = $conn->prepare('SELECT section_id FROM course_sections WHERE section_id = ? AND course_id = ? LIMIT 1');
$section_stmt->bind_param('ss', $section_id, $course_id);
$section_stmt->execute();
$section_exists = $section_stmt->get_result()->num_rows > 0;
$section_stmt->close();

if (!$section_exists) {
    delete_section_response(false, 'Section not found.');
}

$count_stmt = $conn->prepare('SELECT COUNT(*) AS total FROM course_items WHERE course_id = ? AND section_id = ?');
$count_stmt->bind_param('ss', $course_id, $section_id);
$count_stmt->execute();
$count_row = $count_stmt->get_result()->fetch_assoc();
$count_stmt->close();
$lesson_count = (int) ($count_row['total'] ?? 0);

if ($lesson_count > 0 && !isset($_POST['move_to'])) {
    delete_section_response(false, 'This section contains lessons. Choose where to move them before deleting it.', [
        'requires_move' => true,
        'lesson_count' => $lesson_count,
        'options' => delete_section_move_options($conn, $course_id, $section_id),
    ]);
}

try {
    if ($lesson_count > 0) {
        $move_to = delete_section_post('move_to');
        if ($move_to === '' || $move_to === '__general__') {
            $target_section = null;
        } else {
            $target_stmt = $conn->prepare('SELECT section_id FROM course_sections WHERE section_id = ? AND course_id = ? AND section_id <> ? LIMIT 1');
            $target_stmt->bind_param('sss', $move_to, $course_id, $section_id);
            $target_stmt->execute();
            $target_exists = $target_stmt->get_result()->num_rows > 0;
            $target_stmt->close();
            if (!$target_exists) {
                delete_section_response(false, 'Selected target section is not valid.');
            }
            $target_section = $move_to;
        }

        $move_stmt = $conn->prepare('UPDATE course_items SET section_id = ? WHERE course_id = ? AND section_id = ?');
        if (!$move_stmt) {
            delete_section_response(false, 'Unable to prepare lesson move.', ['reason' => $conn->error]);
        }
        $move_stmt->bind_param('sss', $target_section, $course_id, $section_id);
        if (!$move_stmt->execute()) {
            delete_section_response(false, 'Unexpected server error while moving lessons.', ['reason' => $move_stmt->error ?: $conn->error]);
        }
        $move_stmt->close();
    }

    $delete_stmt = $conn->prepare('DELETE FROM course_sections WHERE section_id = ? AND course_id = ? LIMIT 1');
    $delete_stmt->bind_param('ss', $section_id, $course_id);
    if (!$delete_stmt->execute()) {
        delete_section_response(false, 'Unexpected server error while deleting the section.', ['reason' => $delete_stmt->error ?: $conn->error]);
    }
    $delete_stmt->close();

    delete_section_response(true, 'Section deleted successfully.');
} catch (Throwable $e) {
    delete_section_response(false, 'Unexpected server error while deleting the section.', ['reason' => $e->getMessage()]);
}
?>
