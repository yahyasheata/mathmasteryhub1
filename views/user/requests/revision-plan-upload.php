<?php
declare(strict_types=1);

require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/Auth.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/RevisionPlan.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Method Not Allowed'); }
if (!mmh_auth_csrf_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Your session has expired. Refresh and try again.'); }
$conn = db();
$studentId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
$assignmentId = filter_var($assignmentId ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requirementId = filter_var($requirementId ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$studentId || !$assignmentId || !$requirementId) { http_response_code(404); exit('Revision upload unavailable.'); }
try {
    mmh_revision_upload_requirement($conn, (int) $assignmentId, (int) $studentId, (int) $requirementId, $_FILES['revision_files'] ?? []);
    $_SESSION['revision_plan_progress_flash'] = ['ok' => true, 'message' => 'Revision work uploaded and requirement marked complete.'];
} catch (InvalidArgumentException $e) {
    $_SESSION['revision_plan_progress_flash'] = ['ok' => false, 'message' => $e->getMessage()];
} catch (Throwable $e) {
    error_log('Revision Plan upload failed: ' . $e->getMessage());
    $_SESSION['revision_plan_progress_flash'] = ['ok' => false, 'message' => 'This Revision upload could not be saved.'];
}
header('Location: ' . rtrim((string) ($baseUrl ?? mmh_current_request_base_url()), '/') . '/user/revision-plan/' . (int) $assignmentId, true, 303);
exit;
