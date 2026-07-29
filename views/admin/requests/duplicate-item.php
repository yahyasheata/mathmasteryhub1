<?php
require_once 'connection/config.php';

header('Content-Type: application/json; charset=utf-8');

function duplicate_item_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function duplicate_item_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function duplicate_item_generate_id(mysqli $conn, $course_id)
{
    do {
        $item_id = (string) random_int(99, 999999);
        $stmt = $conn->prepare('SELECT id FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
        $stmt->bind_param('ss', $item_id, $course_id);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($exists);

    return $item_id;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    duplicate_item_response(false, 'Invalid request method.');
}

if (duplicate_item_post('_method') !== 'DUPLICATE') {
    duplicate_item_response(false, 'Invalid duplicate request.');
}

$conn = db();
$course_id = duplicate_item_post('course_id');
$item_id = duplicate_item_post('item_id');

if ($course_id === '' || $item_id === '') {
    duplicate_item_response(false, 'Validation failed. Lesson ID or course ID is missing.');
}

$stmt = $conn->prepare('SELECT * FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
$stmt->bind_param('ss', $item_id, $course_id);
$stmt->execute();
$lesson = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lesson) {
    duplicate_item_response(false, 'Lesson not found.');
}

$new_item_id = duplicate_item_generate_id($conn, $course_id);
$section_id = $lesson['section_id'] ?? null;
$new_order = max(1, (int) ($lesson['page_order'] ?? 0) + 1);

try {
    $conn->begin_transaction();

    if ($section_id === null || $section_id === '') {
        $shift = $conn->prepare("UPDATE course_items SET page_order = page_order + 1, sort_order = sort_order + 1 WHERE course_id = ? AND (section_id IS NULL OR section_id = '') AND page_order >= ?");
        $shift->bind_param('si', $course_id, $new_order);
    } else {
        $shift = $conn->prepare('UPDATE course_items SET page_order = page_order + 1, sort_order = sort_order + 1 WHERE course_id = ? AND section_id = ? AND page_order >= ?');
        $shift->bind_param('ssi', $course_id, $section_id, $new_order);
    }
    if (!$shift || !$shift->execute()) {
        throw new RuntimeException($shift ? ($shift->error ?: $conn->error) : $conn->error);
    }
    $shift->close();

    $insert = $conn->prepare('INSERT INTO course_items (item_id, item_title, item_description, item_type, section_id, template_type, template_data, duration_minutes, assignment_id, due_date, status, sort_order, course_id, page_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$insert) {
        throw new RuntimeException($conn->error);
    }

    $item_title = $lesson['item_title'];
    $item_description = $lesson['item_description'];
    $item_type = $lesson['item_type'];
    $template_type = $lesson['template_type'];
    $template_data = $lesson['template_data'];
    $duration_minutes = $lesson['duration_minutes'];
    $assignment_id = $lesson['assignment_id'] !== null ? (int) $lesson['assignment_id'] : null;
    $due_date = $lesson['due_date'];
    $status = $lesson['status'] ?: 'published';
    $sort_order = $new_order;

    $insert->bind_param('sssssssiissisi', $new_item_id, $item_title, $item_description, $item_type, $section_id, $template_type, $template_data, $duration_minutes, $assignment_id, $due_date, $status, $sort_order, $course_id, $new_order);
    if (!$insert->execute()) {
        throw new RuntimeException($insert->error ?: $conn->error);
    }
    $insert->close();

    $conn->commit();
    duplicate_item_response(true, 'Lesson duplicated successfully.', [
        'course_id' => $course_id,
        'item_id' => $new_item_id,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    duplicate_item_response(false, 'Unexpected server error while duplicating the lesson.', ['reason' => $e->getMessage()]);
}
?>
