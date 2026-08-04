<?php
require_once 'connection/config.php';
require_once '__init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_method'] ?? '') !== 'DELETE') {
    header('Allow: POST');
    http_response_code(405);
    exit(json_encode(['status' => 0, 'message' => 'Invalid archive request.']));
}
$assignmentId = trim((string) ($_POST['assignment_id'] ?? ''));
if ($assignmentId === '') {
    http_response_code(422);
    exit(json_encode(['status' => 0, 'message' => 'Invalid assignment.']));
}

$stmt = db()->prepare('UPDATE assignments SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE assignment_id = ?');
$stmt->bind_param('s', $assignmentId);
$ok = $stmt->execute() && $stmt->affected_rows > 0;
$stmt->close();
if (!$ok) {
    http_response_code(404);
    exit(json_encode(['status' => 0, 'message' => 'Assignment not found.']));
}
echo json_encode(['status' => 1, 'message' => 'Assignment archived. Submissions and grades were preserved.']);
