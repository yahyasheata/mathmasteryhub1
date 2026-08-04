<?php
require_once 'connection/config.php';
require_once 'inc/AdminCourseService.php';

header('Content-Type: application/json; charset=utf-8');

function sorting_section_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function sorting_section_decode($key)
{
    if (!isset($_POST[$key])) {
        return [];
    }
    if (is_array($_POST[$key])) {
        return $_POST[$key];
    }
    $decoded = json_decode((string) $_POST[$key], true);
    return is_array($decoded) ? $decoded : [];
}

function sorting_section_column_exists(mysqli $conn, $table, $column)
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sorting_section_response(false, 'Invalid request method.');
}

if (!isset($_POST['_method']) || $_POST['_method'] !== 'update') {
    sorting_section_response(false, 'Invalid sorting request.');
}

if (!isset($_POST['course_id']) || trim($_POST['course_id']) === '') {
    sorting_section_response(false, 'Validation failed. Course ID is missing.');
}

$conn = db();
$course_id = trim((string) $_POST['course_id']);
$sections = sorting_section_decode('sections');
$lessons = sorting_section_decode('lessons');
if (!empty($sections) || !empty($lessons)) {
    $conn->begin_transaction();
    try {
        mmh_admin_course_reorder_sections($conn, $course_id, $sections);
        mmh_admin_course_reorder_items($conn, $course_id, $lessons);
        $conn->commit();
        sorting_section_response(true, 'Course sections updated successfully.');
    }
    catch (Throwable $e) { $conn->rollback(); sorting_section_response(false, 'Section order could not be saved.'); }
}
$has_sort_order = sorting_section_column_exists($conn, 'course_items', 'sort_order');
$has_section_id = sorting_section_column_exists($conn, 'course_items', 'section_id');

if (!$has_section_id) {
    sorting_section_response(false, 'Section sorting requires course_items.section_id.');
}

$valid_sections = [];
$section_stmt = $conn->prepare('SELECT section_id FROM course_sections WHERE course_id = ?');
$section_stmt->bind_param('s', $course_id);
$section_stmt->execute();
$section_result = $section_stmt->get_result();
while ($row = $section_result->fetch_assoc()) {
    $valid_sections[(string) $row['section_id']] = true;
}
$section_stmt->close();

try {
    $conn->begin_transaction();

    if (!empty($sections)) {
        $section_update = $conn->prepare('UPDATE course_sections SET sort_order = ? WHERE section_id = ? AND course_id = ?');
        if (!$section_update) {
            throw new RuntimeException($conn->error);
        }
        foreach (array_values($sections) as $index => $raw_section_id) {
            $section_id = trim((string) $raw_section_id);
            if ($section_id === '' || $section_id === '__general__' || !isset($valid_sections[$section_id])) {
                continue;
            }
            $sort_order = $index + 1;
            $section_update->bind_param('iss', $sort_order, $section_id, $course_id);
            if (!$section_update->execute()) {
                throw new RuntimeException($section_update->error ?: $conn->error);
            }
        }
        $section_update->close();
    }

    if (!empty($lessons)) {
        $lesson_sql = $has_sort_order
            ? 'UPDATE course_items SET section_id = ?, page_order = ?, sort_order = ? WHERE id = ? AND course_id = ?'
            : 'UPDATE course_items SET section_id = ?, page_order = ? WHERE id = ? AND course_id = ?';
        $lesson_update = $conn->prepare($lesson_sql);
        if (!$lesson_update) {
            throw new RuntimeException($conn->error);
        }

        foreach ($lessons as $lesson) {
            if (!is_array($lesson) || !isset($lesson['id']) || !is_numeric($lesson['id'])) {
                continue;
            }

            $raw_section_id = isset($lesson['section_id']) ? trim((string) $lesson['section_id']) : '';
            if ($raw_section_id === '' || $raw_section_id === '__general__') {
                $section_id = null;
            } elseif (isset($valid_sections[$raw_section_id])) {
                $section_id = $raw_section_id;
            } else {
                continue;
            }

            $page_order = isset($lesson['page_order']) && is_numeric($lesson['page_order']) ? max(1, (int) $lesson['page_order']) : 1;
            $id = (int) $lesson['id'];

            if ($has_sort_order) {
                $lesson_update->bind_param('siiis', $section_id, $page_order, $page_order, $id, $course_id);
            } else {
                $lesson_update->bind_param('siis', $section_id, $page_order, $id, $course_id);
            }

            if (!$lesson_update->execute()) {
                throw new RuntimeException($lesson_update->error ?: $conn->error);
            }
        }
        $lesson_update->close();
    }

    $conn->commit();
    sorting_section_response(true, 'Course sections updated successfully.');
} catch (Throwable $e) {
    $conn->rollback();
    sorting_section_response(false, 'Unexpected server error while sorting sections.', ['reason' => $e->getMessage()]);
}
?>
