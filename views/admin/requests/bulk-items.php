<?php
require_once 'connection/config.php';
require_once 'inc/CourseAssignmentLinks.php';

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

function bulk_items_generate_id(mysqli $conn, $course_id)
{
    do {
        $item_id = (string) random_int(99, 999999);
        $statement = $conn->prepare('SELECT id FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
        if (!$statement) {
            throw new RuntimeException($conn->error);
        }
        $statement->bind_param('ss', $item_id, $course_id);
        $statement->execute();
        $exists = $statement->get_result()->num_rows > 0;
        $statement->close();
    } while ($exists);

    return $item_id;
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
$allowed_actions = ['move', 'publish', 'unpublish', 'delete', 'duplicate'];

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
        $delete = $conn->prepare("DELETE FROM course_items WHERE course_id = ? AND item_id IN ({$placeholders})");
        if (!$delete) {
            throw new RuntimeException($conn->error);
        }
        $delete_params = array_merge([$course_id], $item_ids);
        bulk_items_bind($delete, str_repeat('s', count($delete_params)), $delete_params);
        if (!$delete->execute()) {
            throw new RuntimeException($delete->error ?: $conn->error);
        }
        $delete->close();
        $message = count($selected) === 1 ? 'Lesson deleted successfully.' : count($selected) . ' lessons deleted successfully.';
    } else {
        $orders = [];
        $insert = $conn->prepare('INSERT INTO course_items (item_id, item_title, item_description, item_type, section_id, template_type, template_data, duration_minutes, metadata, assignment_id, due_date, status, sort_order, course_id, page_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$insert) {
            throw new RuntimeException($conn->error);
        }
        $created_ids = [];
        foreach ($selected as $lesson) {
            $section_id = (string) ($lesson['section_id'] ?? '');
            $order_key = $section_id === '' ? '__general__' : $section_id;
            if (!array_key_exists($order_key, $orders)) {
                $orders[$order_key] = bulk_items_next_order($conn, $course_id, $section_id === '' ? null : $section_id);
            }
            $orders[$order_key]++;
            $new_item_id = bulk_items_generate_id($conn, $course_id);
            $item_title = (string) $lesson['item_title'];
            $item_description = (string) $lesson['item_description'];
            $item_type = (string) $lesson['item_type'];
            $template_type = (string) ($lesson['template_type'] ?? '');
            $template_data = (string) ($lesson['template_data'] ?? '');
            $duration_minutes = $lesson['duration_minutes'] !== null ? (int) $lesson['duration_minutes'] : null;
            $metadata = (string) ($lesson['metadata'] ?? '');
            $assignment_id = $lesson['assignment_id'] !== null ? (int) $lesson['assignment_id'] : null;
            $due_date = $lesson['due_date'];
            $status = (string) ($lesson['status'] ?: 'published');
            $sort_order = $orders[$order_key];
            $page_order = $orders[$order_key];
            $insert->bind_param('sssssssisissisi', $new_item_id, $item_title, $item_description, $item_type, $section_id, $template_type, $template_data, $duration_minutes, $metadata, $assignment_id, $due_date, $status, $sort_order, $course_id, $page_order);
            if (!$insert->execute()) {
                throw new RuntimeException($insert->error ?: $conn->error);
            }
            if ($template_type === 'timed_exam') {
                $copyExam = $conn->prepare("INSERT INTO timed_exams (course_id, item_id, title, instructions, status, timing_mode, scheduled_start_at_utc, duration_minutes, grace_minutes, max_attempts, allowed_answer_types, max_file_size_bytes, paper_storage_key, paper_original_name, paper_mime, paper_size_bytes, paper_view_allowed, paper_download_allowed, late_submission_allowed, expiry_policy, max_marks, results_release_at_utc, recovery_window_start_at_utc, recovery_window_end_at_utc, recovery_allowed, created_by, updated_by) SELECT course_id, ?, CONCAT(title, ' (Copy)'), instructions, 'draft', timing_mode, scheduled_start_at_utc, duration_minutes, grace_minutes, max_attempts, allowed_answer_types, max_file_size_bytes, paper_storage_key, paper_original_name, paper_mime, paper_size_bytes, paper_view_allowed, paper_download_allowed, late_submission_allowed, expiry_policy, max_marks, results_release_at_utc, recovery_window_start_at_utc, recovery_window_end_at_utc, recovery_allowed, created_by, updated_by FROM timed_exams WHERE course_id = ? AND item_id = ? AND deleted_at IS NULL LIMIT 1");
                if ($copyExam) { $copyExam->bind_param('sss', $new_item_id, $course_id, $lesson['item_id']); $copyExam->execute(); $copyExam->close(); }
            }
            $source_assignment_id = mmh_course_assignment_id($lesson);
            if ($source_assignment_id !== '') {
                $new_assignment_id = mmh_course_assignment_clone_for_item($conn, $course_id, $source_assignment_id, $new_item_id, $section_id);
                if ($new_assignment_id !== null) {
                    mmh_course_assignment_relink_item($conn, $course_id, $new_item_id, $source_assignment_id, $new_assignment_id);
                }
            }
            $created_ids[] = $new_item_id;
        }
        $insert->close();
        $message = count($selected) === 1 ? 'Lesson duplicated successfully.' : count($selected) . ' lessons duplicated successfully.';
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
