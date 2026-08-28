<?php
require_once 'connection/config.php';
require_once 'inc/CourseContentCopyService.php';

header('Content-Type: application/json; charset=utf-8');

function bulk_items_response($success, $message, array $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function bulk_items_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function bulk_items_bind(mysqli_stmt $statement, $types, array &$params)
{
    $references = [];
    foreach ($params as $index => &$value) {
        $references[$index] = &$value;
    }
    array_unshift($references, $types);
    call_user_func_array([$statement, 'bind_param'], $references);
}

function bulk_items_unique_ids($value)
{
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $raw_id) {
        $item_id = trim((string) $raw_id);
        if ($item_id !== '' && strlen($item_id) <= 20 && !isset($ids[$item_id])) {
            $ids[$item_id] = true;
        }
    }

    return array_keys($ids);
}

function bulk_items_section_is_valid(mysqli $conn, $course_id, $section_id)
{
    if ($section_id === '' || $section_id === '__general__') {
        return true;
    }

    $statement = $conn->prepare('SELECT id FROM course_sections WHERE section_id = ? AND course_id = ? LIMIT 1');
    if (!$statement) {
        return false;
    }
    $statement->bind_param('ss', $section_id, $course_id);
    $statement->execute();
    $valid = $statement->get_result()->num_rows === 1;
    $statement->close();
    return $valid;
}

function bulk_items_next_order(mysqli $conn, $course_id, $section_id)
{
    if ($section_id === null || $section_id === '') {
        $statement = $conn->prepare("SELECT COALESCE(MAX(page_order), 0) AS max_order FROM course_items WHERE course_id = ? AND (section_id IS NULL OR section_id = '')");
        $statement->bind_param('s', $course_id);
    } else {
        $statement = $conn->prepare('SELECT COALESCE(MAX(page_order), 0) AS max_order FROM course_items WHERE course_id = ? AND section_id = ?');
        $statement->bind_param('ss', $course_id, $section_id);
    }
    if (!$statement) {
        throw new RuntimeException($conn->error);
    }
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return max(0, (int) ($row['max_order'] ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bulk_items_response(false, 'Invalid request method.');
}

if (bulk_items_post('_method') !== 'BULK') {
    bulk_items_response(false, 'Invalid bulk lesson request.');
}

$course_id = bulk_items_post('course_id');
$action = bulk_items_post('action');
$item_ids = bulk_items_unique_ids($_POST['item_ids'] ?? []);
$allowed_actions = ['move', 'publish', 'unpublish', 'delete', 'duplicate', 'copy'];

if ($course_id === '' || !in_array($action, $allowed_actions, true)) {
    bulk_items_response(false, 'Validation failed. The course or bulk action is invalid.');
}
if (count($item_ids) === 0) {
    bulk_items_response(false, 'Select at least one lesson.');
}
if (count($item_ids) > 300) {
    bulk_items_response(false, 'A maximum of 300 lessons can be changed at once.');
}

$conn = db();
$course_statement = $conn->prepare('SELECT course_id FROM courses WHERE course_id = ? LIMIT 1');
if (!$course_statement) {
    bulk_items_response(false, 'Unable to validate the course.', ['reason' => $conn->error]);
}
$course_statement->bind_param('s', $course_id);
$course_statement->execute();
$course_exists = $course_statement->get_result()->num_rows === 1;
$course_statement->close();
if (!$course_exists) {
    bulk_items_response(false, 'Course not found.');
}

$placeholders = implode(', ', array_fill(0, count($item_ids), '?'));
$selection_sql = "SELECT * FROM course_items WHERE course_id = ? AND item_id IN ({$placeholders})";
$selection_statement = $conn->prepare($selection_sql);
if (!$selection_statement) {
    bulk_items_response(false, 'Unable to prepare the lesson selection.', ['reason' => $conn->error]);
}
$selection_params = array_merge([$course_id], $item_ids);
bulk_items_bind($selection_statement, str_repeat('s', count($selection_params)), $selection_params);
$selection_statement->execute();
$selected_by_id = [];
$result = $selection_statement->get_result();
while ($row = $result->fetch_assoc()) {
    $selected_by_id[(string) $row['item_id']] = $row;
}
$selection_statement->close();

if (count($selected_by_id) !== count($item_ids)) {
    bulk_items_response(false, 'One or more selected lessons no longer belong to this course. Refresh the page and try again.');
}

$selected = [];
foreach ($item_ids as $item_id) {
    $selected[] = $selected_by_id[$item_id];
}

$destination_section_id = bulk_items_post('destination_section_id');
if ($action === 'move' && !bulk_items_section_is_valid($conn, $course_id, $destination_section_id)) {
    bulk_items_response(false, 'Choose a valid destination section.');
}
if ($destination_section_id === '__general__') {
    $destination_section_id = '';
}

// Keep bulk duplication on the same canonical transactional copy service as
// the item/section copy workspace. Each copy is independent and never carries
// student submissions, grades, progress, or model-answer access rows.
if ($action === 'duplicate') {
    try {
        $created_ids = [];
        foreach ($selected as $lesson) {
            $copy = CourseContentCopyService::copyItem($conn, $course_id, (string) $lesson['item_id'], $course_id, trim((string) ($lesson['section_id'] ?? '')) ?: null);
            $created_ids[] = $copy['item_id'];
        }
        bulk_items_response(true, count($selected) === 1 ? 'Lesson duplicated successfully.' : count($selected) . ' lessons duplicated successfully.', [
            'course_id' => $course_id,
            'affected' => count($selected),
            'action' => $action,
            'created_item_ids' => $created_ids,
        ]);
    } catch (Throwable $exception) {
        bulk_items_response(false, $exception->getMessage() ?: 'The selected lessons could not be duplicated.');
    }
}

if ($action === 'copy') {
    $destination_course_id = bulk_items_post('destination_course_id');
    $destination_section_id = bulk_items_post('destination_section_id');
    if ($destination_course_id === '') bulk_items_response(false, 'Choose a destination course.');
    try {
        $result = CourseContentCopyService::copyItems($conn, $course_id, $item_ids, $destination_course_id, $destination_section_id !== '' ? $destination_section_id : null);
        $message = count($selected) === 1 ? 'Lesson copied successfully.' : count($selected) . ' lessons copied successfully.';
        if (!empty($result['warnings'])) $message .= ' ' . implode(' ', $result['warnings']);
        bulk_items_response(true, $message, array_merge($result, ['affected' => count($selected), 'action' => $action]));
    } catch (Throwable $exception) {
        bulk_items_response(false, $exception->getMessage() ?: 'The selected lessons could not be copied.');
    }
}

try {
    $conn->begin_transaction();

    if ($action === 'move') {
        $destination_value = $destination_section_id === '' ? null : $destination_section_id;
        $next_order = bulk_items_next_order($conn, $course_id, $destination_value);
        $update = $conn->prepare('UPDATE course_items SET section_id = ?, page_order = ?, sort_order = ? WHERE id = ? AND course_id = ?');
        if (!$update) {
            throw new RuntimeException($conn->error);
        }
        foreach ($selected as $lesson) {
            $next_order++;
            $db_id = (int) $lesson['id'];
            $update->bind_param('siiis', $destination_value, $next_order, $next_order, $db_id, $course_id);
            if (!$update->execute()) {
                throw new RuntimeException($update->error ?: $conn->error);
            }
        }
        $update->close();
        $message = count($selected) === 1 ? 'Lesson moved successfully.' : count($selected) . ' lessons moved successfully.';
    } elseif ($action === 'publish' || $action === 'unpublish') {
        $new_status = $action === 'publish' ? 'published' : 'draft';
        $update = $conn->prepare("UPDATE course_items SET status = ? WHERE course_id = ? AND item_id IN ({$placeholders})");
        if (!$update) {
            throw new RuntimeException($conn->error);
        }
        $update_params = array_merge([$new_status, $course_id], $item_ids);
        bulk_items_bind($update, str_repeat('s', count($update_params)), $update_params);
        if (!$update->execute()) {
            throw new RuntimeException($update->error ?: $conn->error);
        }
        $update->close();
        $message = $action === 'publish' ? 'Selected lessons published.' : 'Selected lessons moved to draft.';
    } elseif ($action === 'delete') {
        $archiveExam = $conn->prepare("UPDATE timed_exams SET deleted_at = COALESCE(deleted_at, UTC_TIMESTAMP()), status = 'archived' WHERE course_id = ? AND item_id IN ({$placeholders})");
        if ($archiveExam) {
            $archiveParams = array_merge([$course_id], $item_ids);
            bulk_items_bind($archiveExam, str_repeat('s', count($archiveParams)), $archiveParams);
            $archiveExam->execute();
            $archiveExam->close();
        }
        $delete = $conn->prepare("UPDATE course_items SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE course_id = ? AND item_id IN ({$placeholders})");
        if (!$delete) {
            throw new RuntimeException($conn->error);
        }
        $delete_params = array_merge([$course_id], $item_ids);
        bulk_items_bind($delete, str_repeat('s', count($delete_params)), $delete_params);
        if (!$delete->execute()) {
            throw new RuntimeException($delete->error ?: $conn->error);
        }
        $delete->close();
        $message = count($selected) === 1 ? 'Item deleted.' : count($selected) . ' items deleted.';
    }

    $conn->commit();
    bulk_items_response(true, $message, [
        'course_id' => $course_id,
        'affected' => count($selected),
        'action' => $action,
        'created_item_ids' => $created_ids ?? [],
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    bulk_items_response(false, 'Unexpected server error while updating lessons.', ['reason' => $exception->getMessage()]);
}
