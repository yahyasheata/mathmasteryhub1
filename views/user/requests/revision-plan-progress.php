<?php
declare(strict_types=1);

require_once 'connection/config.php';
require_once 'inc/Auth.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/RevisionPlan.php';

$conn = db();
$base = rtrim((string) ($baseUrl ?? mmh_current_request_base_url()), '/');
$assignmentId = (int) ($assignmentId ?? 0);
$requirementId = (int) ($requirementId ?? 0);
$studentId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
$redirect = $base . '/user/revision-plan/' . $assignmentId;

if (!mmh_auth_csrf_valid($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Your session has expired. Refresh and try again.');
}
if (!$studentId || $assignmentId <= 0 || $requirementId <= 0) {
    http_response_code(404);
    exit('Revision Plan requirement not found.');
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
if (!in_array($action, ['complete', 'undo'], true)) {
    http_response_code(400);
    exit('Invalid Revision Plan action.');
}

try {
    mmh_revision_set_requirement_complete($conn, $assignmentId, (int) $studentId, $requirementId, $action === 'complete');
    $_SESSION['revision_plan_progress_flash'] = ['ok' => true, 'message' => $action === 'complete' ? 'Requirement marked complete.' : 'Requirement marked incomplete.'];
} catch (InvalidArgumentException $exception) {
    $_SESSION['revision_plan_progress_flash'] = ['ok' => false, 'message' => $exception->getMessage()];
} catch (Throwable $exception) {
    error_log('Revision Plan progress update failed: ' . $exception->getMessage());
    $_SESSION['revision_plan_progress_flash'] = ['ok' => false, 'message' => 'This requirement could not be updated.'];
}

header('Location: ' . $redirect, true, 303);
exit;
