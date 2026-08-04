<?php
require_once 'connection/config.php';
require_once '__init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_method'] ?? '') !== 'DELETE') {
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Invalid archive request.']));
}
$courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($courseId === false) {
    http_response_code(422);
    exit(json_encode(['status' => 0, 'message' => 'Invalid course.']));
}

$conn = db();
$conn->begin_transaction();
try {
    $stmt = $conn->prepare('UPDATE courses SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE course_id = ?');
    $stmt->bind_param('i', $courseId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$changed) {
        throw new RuntimeException('Course not found.');
    }
    $conn->commit();
    echo json_encode(['status' => 1, 'message' => 'Course archived. Related records were preserved.']);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code($e->getMessage() === 'Course not found.' ? 404 : 500);
    echo json_encode(['status' => 0, 'message' => $e->getMessage() === 'Course not found.' ? 'Course not found.' : 'Course could not be archived.']);
}
