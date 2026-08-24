<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/TimedExam.php';

header('Content-Type: application/json; charset=utf-8');

function status_item_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

function status_item_column_exists(mysqli $conn)
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_items' AND COLUMN_NAME = 'status'");
    if (!$stmt) {
        return false;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

function status_item_supports_hidden(mysqli $conn)
{
    $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_items' AND COLUMN_NAME = 'status' LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return isset($row['COLUMN_TYPE']) && strpos((string) $row['COLUMN_TYPE'], "'hidden'") !== false;
}

function status_item_normalize($value)
{
    $value = strtolower(trim((string) $value));
    if ($value === 'hidden' || $value === '0') {
        return 'hidden';
    }
    if ($value === 'draft') {
        return 'draft';
    }
    return 'published';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    status_item_response(false, 'Invalid request method.');
}

$conn = db();
if (!status_item_column_exists($conn)) {
    status_item_response(false, 'Lesson visibility is unavailable because the status column does not exist. No schema changes were made.');
}

if (!isset($_POST['item_id'], $_POST['course_id']) || trim($_POST['item_id']) === '' || trim($_POST['course_id']) === '') {
    status_item_response(false, 'Validation failed. Lesson ID or Course ID is missing.');
}

$item_id = trim($_POST['item_id']);
$course_id = trim($_POST['course_id']);

try {
    if (!$conn->begin_transaction()) status_item_response(false, 'Unable to begin the visibility update.');
    $select_stmt = $conn->prepare('SELECT status, template_type, item_type FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
    $select_stmt->bind_param('ss', $item_id, $course_id);
    $select_stmt->execute();
    $item = $select_stmt->get_result()->fetch_assoc();
    $select_stmt->close();

    if (!$item) {
        status_item_response(false, 'Lesson not found.');
    }

    $supports_hidden_status = status_item_supports_hidden($conn);
    $current_status = status_item_normalize($item['status'] ?? 'published');
    $new_status = $current_status === 'published' ? ($supports_hidden_status ? 'hidden' : 'draft') : 'published';
    $label = $new_status === 'hidden' ? 'Hidden' : ($new_status === 'draft' ? 'Draft' : 'Published');

    $update_stmt = $conn->prepare('UPDATE course_items SET status = ? WHERE item_id = ? AND course_id = ? LIMIT 1');
    $update_stmt->bind_param('sss', $new_status, $item_id, $course_id);

    if (!$update_stmt->execute()) {
        throw new RuntimeException($update_stmt->error ?: $conn->error ?: 'Unable to update lesson visibility.');
    }
    $update_stmt->close();
    $template = strtolower(trim((string) ($item['template_type'] ?? $item['item_type'] ?? '')));
    if ($template === 'timed_exam') {
        $examStatus = $new_status === 'published' ? 'published' : 'draft';
        $examUpdate = $conn->prepare('UPDATE timed_exams SET status = ? WHERE course_id = ? AND item_id = ? AND deleted_at IS NULL LIMIT 1');
        if (!$examUpdate) throw new RuntimeException('Unable to synchronize the Timed Exam publication state.');
        $examUpdate->bind_param('sss', $examStatus, $course_id, $item_id);
        if (!$examUpdate->execute()) { $error = $examUpdate->error; $examUpdate->close(); throw new RuntimeException($error ?: 'Unable to synchronize the Timed Exam publication state.'); }
        $examUpdate->close();
    }
    if (!$conn->commit()) throw new RuntimeException('Unable to commit the visibility update.');
    status_item_response(true, "Lesson visibility updated to {$label}.", [
        'item_id' => $item_id,
        'course_id' => $course_id,
        'new_status' => $new_status,
        'label' => $label,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    status_item_response(false, 'Unexpected server error while updating visibility.', ['reason' => $e->getMessage()]);
}
?>
