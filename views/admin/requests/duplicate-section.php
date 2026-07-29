<?php
require_once 'connection/config.php';
require_once 'inc/learning_schema.php';

header('Content-Type: application/json; charset=utf-8');

function duplicate_section_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function duplicate_section_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function duplicate_section_generate_id(mysqli $conn, $table, $column, $course_id)
{
    do {
        $value = (string) random_int(99, 999999);
        $stmt = $conn->prepare("SELECT id FROM {$table} WHERE {$column} = ? AND course_id = ? LIMIT 1");
        $stmt->bind_param('ss', $value, $course_id);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($exists);

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    duplicate_section_response(false, 'Invalid request method.');
}

if (duplicate_section_post('_method') !== 'DUPLICATE') {
    duplicate_section_response(false, 'Invalid duplicate request.');
}

$conn = db();
mmh_ensure_learning_schema($conn);
$course_id = duplicate_section_post('course_id');
$section_id = duplicate_section_post('section_id');

if ($course_id === '' || $section_id === '' || $section_id === '__general__') {
    duplicate_section_response(false, 'Validation failed. Section ID or course ID is missing.');
}

$stmt = $conn->prepare('SELECT * FROM course_sections WHERE section_id = ? AND course_id = ? LIMIT 1');
$stmt->bind_param('ss', $section_id, $course_id);
$stmt->execute();
$section = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$section) {
    duplicate_section_response(false, 'Section not found.');
}

$new_section_id = duplicate_section_generate_id($conn, 'course_sections', 'section_id', $course_id);
$new_title = rtrim((string) $section['title']) . ' (Copy)';
$new_sort_order = max(1, (int) ($section['sort_order'] ?? 0) + 1);

try {
    $conn->begin_transaction();

    $shift_sections = $conn->prepare('UPDATE course_sections SET sort_order = sort_order + 1 WHERE course_id = ? AND sort_order >= ?');
    $shift_sections->bind_param('si', $course_id, $new_sort_order);
    if (!$shift_sections->execute()) {
        throw new RuntimeException($shift_sections->error ?: $conn->error);
    }
    $shift_sections->close();

    $insert_section = $conn->prepare("INSERT INTO course_sections (section_id, course_id, title, section_type, custom_type, icon, description, metadata, sort_order, status, unlock_mode, completion_rule, unlock_at, unlock_timezone, unlock_homework_id, manual_unlocked, release_mode, release_override, release_at, release_timezone, release_occurrence_id, release_delay_minutes, release_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inherit', 'inherit', NULL, NULL, NULL, 0, NULL)");
    if (!$insert_section) {
        throw new RuntimeException($conn->error);
    }
    $section_type = $section['section_type'];
    $custom_type = $section['custom_type'];
    $icon = $section['icon'];
    $description = $section['description'];
    $metadata = (string) ($section['metadata'] ?? '');
    $status = $section['status'] ?: 'published';
    $unlock_mode = $section['unlock_mode'] ?: 'always';
    $completion_rule = $section['completion_rule'] ?: 'manual_completion';
    $unlock_at = $section['unlock_at'];
    $unlock_timezone = $section['unlock_timezone'] ?: 'Africa/Cairo';
    $unlock_homework_id = $section['unlock_homework_id'];
    $manual_unlocked = (int) ($section['manual_unlocked'] ?? 0);
    $insert_section->bind_param('ssssssssissssssi', $new_section_id, $course_id, $new_title, $section_type, $custom_type, $icon, $description, $metadata, $new_sort_order, $status, $unlock_mode, $completion_rule, $unlock_at, $unlock_timezone, $unlock_homework_id, $manual_unlocked);
    if (!$insert_section->execute()) {
        throw new RuntimeException($insert_section->error ?: $conn->error);
    }
    $insert_section->close();

    $lessons_stmt = $conn->prepare('SELECT * FROM course_items WHERE course_id = ? AND section_id = ? ORDER BY page_order ASC, id ASC');
    $lessons_stmt->bind_param('ss', $course_id, $section_id);
    $lessons_stmt->execute();
    $lessons = $lessons_stmt->get_result();

    $insert_lesson = $conn->prepare('INSERT INTO course_items (item_id, item_title, item_description, item_type, section_id, template_type, template_data, metadata, duration_minutes, assignment_id, due_date, status, sort_order, course_id, page_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$insert_lesson) {
        throw new RuntimeException($conn->error);
    }

    $lesson_order = 1;
    while ($lesson = $lessons->fetch_assoc()) {
        $new_item_id = duplicate_section_generate_id($conn, 'course_items', 'item_id', $course_id);
        $item_title = $lesson['item_title'];
        $item_description = $lesson['item_description'];
        $item_type = $lesson['item_type'];
        $template_type = $lesson['template_type'];
        $template_data = $lesson['template_data'];
        // Keep explicit lesson-level metadata overrides. Shared values remain on
        // the duplicated section and are resolved at read time.
        $lesson_metadata = $lesson['metadata'] ?? null;
        $duration_minutes = $lesson['duration_minutes'];
        $assignment_id = $lesson['assignment_id'] !== null ? (int) $lesson['assignment_id'] : null;
        $due_date = $lesson['due_date'];
        $lesson_status = $lesson['status'] ?: 'published';
        $insert_lesson->bind_param('ssssssssiissisi', $new_item_id, $item_title, $item_description, $item_type, $new_section_id, $template_type, $template_data, $lesson_metadata, $duration_minutes, $assignment_id, $due_date, $lesson_status, $lesson_order, $course_id, $lesson_order);
        if (!$insert_lesson->execute()) {
            throw new RuntimeException($insert_lesson->error ?: $conn->error);
        }
        $lesson_order++;
    }
    $insert_lesson->close();
    $lessons_stmt->close();

    $conn->commit();
    duplicate_section_response(true, 'Section duplicated successfully.', [
        'course_id' => $course_id,
        'section_id' => $new_section_id,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    duplicate_section_response(false, 'Unexpected server error while duplicating the section.', ['reason' => $e->getMessage()]);
}
?>
