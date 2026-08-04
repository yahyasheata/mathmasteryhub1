<?php
require_once 'connection/config.php';
require_once '__init.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_method'] ?? '') !== 'DELETE') {
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Invalid archive request.']));
}
$itemId = trim((string) ($_POST['item_id'] ?? ''));
$courseId = trim((string) ($_POST['course_id'] ?? ''));
if ($itemId === '') {
    http_response_code(422);
    exit(json_encode(['status' => 0, 'message' => 'Lesson ID is missing.']));
}

$conn = db();
$conn->begin_transaction();
try {
    if ($courseId !== '') {
        $exam = $conn->prepare("UPDATE timed_exams SET deleted_at = COALESCE(deleted_at, UTC_TIMESTAMP()), status = 'archived' WHERE course_id = ? AND item_id = ?");
        if ($exam) { $exam->bind_param('ss', $courseId, $itemId); $exam->execute(); $exam->close(); }
        $stmt = $conn->prepare('UPDATE course_items SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE item_id = ? AND course_id = ?');
        $stmt->bind_param('ss', $itemId, $courseId);
    } else {
        $stmt = $conn->prepare('UPDATE course_items SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE item_id = ?');
        $stmt->bind_param('s', $itemId);
    }
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$changed) { throw new RuntimeException('Lesson not found.'); }
    $conn->commit();
    echo json_encode(['status' => 1, 'message' => 'Lesson archived. Progress and submissions were preserved.']);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code($e->getMessage() === 'Lesson not found.' ? 404 : 500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage() === 'Lesson not found.' ? 'Lesson not found.' : 'Lesson could not be archived.']);
}
