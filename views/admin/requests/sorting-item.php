<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';

header('Content-Type: application/json; charset=utf-8');

function sorting_item_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sorting_item_response(false, 'Invalid request method.');
}

if (!isset($_POST['_method']) || $_POST['_method'] !== 'update') {
    sorting_item_response(false, 'Invalid sorting request.');
}

if (!isset($_POST['page_id_array']) || !is_array($_POST['page_id_array']) || count($_POST['page_id_array']) === 0) {
    sorting_item_response(false, 'No lessons were provided for sorting.');
}

$conn = db();
$has_sort_order = false;
$column_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_items' AND COLUMN_NAME = 'sort_order'");
if ($column_stmt) {
    $column_stmt->execute();
    $column_row = $column_stmt->get_result()->fetch_assoc();
    $has_sort_order = (int) ($column_row['total'] ?? 0) > 0;
    $column_stmt->close();
}

$sql = $has_sort_order
    ? 'UPDATE course_items SET page_order = ?, sort_order = ? WHERE id = ?'
    : 'UPDATE course_items SET page_order = ? WHERE id = ?';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    sorting_item_response(false, 'Unable to prepare sorting update.', ['reason' => $conn->error]);
}

try {
    $updated = 0;
    foreach (array_values($_POST['page_id_array']) as $index => $raw_id) {
        if (!is_numeric($raw_id)) {
            continue;
        }

        $page_order = $index + 1;
        $id = (int) $raw_id;
        if ($has_sort_order) {
            $stmt->bind_param('iii', $page_order, $page_order, $id);
        } else {
            $stmt->bind_param('ii', $page_order, $id);
        }
        if ($stmt->execute()) {
            $updated += $stmt->affected_rows >= 0 ? 1 : 0;
        }
    }

    sorting_item_response(true, 'Lesson order updated successfully.', ['updated' => $updated]);
} catch (Throwable $e) {
    sorting_item_response(false, 'Unexpected server error while sorting lessons.', [
        'reason' => $e->getMessage(),
    ]);
}
?>
