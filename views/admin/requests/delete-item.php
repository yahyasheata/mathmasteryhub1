<?php
require_once 'connection/config.php';

header('Content-Type: application/json; charset=utf-8');

function delete_item_response($success, $message, $data = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'status' => $success ? 1 : 0,
        'message' => $message,
    ], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    delete_item_response(false, 'Invalid request method.');
}

if (!isset($_POST['_method']) || $_POST['_method'] !== 'DELETE') {
    delete_item_response(false, 'Invalid delete request.');
}

if (!isset($_POST['item_id']) || trim($_POST['item_id']) === '') {
    delete_item_response(false, 'Validation failed. Lesson ID is missing.');
}

$conn = db();
$item_id = trim($_POST['item_id']);
$course_id = isset($_POST['course_id']) ? trim($_POST['course_id']) : '';

try {
    if ($course_id !== '') {
        $stmt = $conn->prepare('DELETE FROM course_items WHERE item_id = ? AND course_id = ? LIMIT 1');
        $stmt->bind_param('ss', $item_id, $course_id);
    } else {
        $stmt = $conn->prepare('DELETE FROM course_items WHERE item_id = ? LIMIT 1');
        $stmt->bind_param('s', $item_id);
    }

    if ($stmt->execute()) {
        delete_item_response(true, 'Lesson deleted successfully.');
    }

    delete_item_response(false, 'Unexpected server error while deleting the lesson.', [
        'reason' => $stmt->error ?: $conn->error,
    ]);
} catch (Throwable $e) {
    delete_item_response(false, 'Unexpected server error while deleting the lesson.', [
        'reason' => $e->getMessage(),
    ]);
}
?>
