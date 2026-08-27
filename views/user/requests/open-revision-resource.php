<?php
require_once dirname(__DIR__, 3) . '/connection/config.php';
require_once dirname(__DIR__, 3) . '/inc/StudentCourseAccess.php';
require_once dirname(__DIR__, 3) . '/inc/RevisionPlan.php';

$conn = db();
$studentId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
$assignmentId = filter_var($assignmentId ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$resourceId = filter_var($resourceId ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requirementId = filter_var($_GET['requirement'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if (!$studentId || !$assignmentId || !$resourceId) { http_response_code(404); exit('Resource unavailable.'); }
$context = mmh_revision_assignment_context($conn, (int) $assignmentId, (int) $studentId, $requirementId, (int) $resourceId);
if (!$context || empty($context['resource'])) { http_response_code(403); exit('Resource unavailable.'); }
$resource = $context['resource'];
$resourceType = (string) ($resource['resource_type'] ?? '');
if ($resourceType === 'external_link') {
    $parts = parse_url((string) ($resource['external_url'] ?? ''));
    if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) { http_response_code(404); exit('Resource unavailable.'); }
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Location: ' . $resource['external_url'], true, 302);
    exit;
}
if ($resourceType === 'course_item') {
    $itemId = trim((string) ($resource['linked_course_item_id'] ?? ''));
    $requirement = $context['requirement'];
    if ($itemId === '') { http_response_code(403); exit('Resource unavailable.'); }
    $base = rtrim((string) ($baseUrl ?? mmh_current_request_base_url()), '/');
    $options = [];
    if (is_array($requirement) && (string) ($requirement['linked_course_item_id'] ?? '') === $itemId) {
        $options = ['revision_assignment_id' => (int) $assignmentId, 'revision_requirement_id' => (int) $requirementId];
    }
    header('Location: ' . mmh_student_resource_url($base, (string) $context['assignment']['course_id'], $itemId, $options), true, 302);
    exit;
}
$storageKey = trim((string) ($resource['storage_key'] ?? ''));
$root = realpath(dirname(__DIR__, 3) . '/storage/private/revision-plans');
$path = $storageKey !== '' ? realpath(dirname(__DIR__, 3) . '/' . $storageKey) : false;
if (!$root || !$path || !is_file($path) || !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $root), '/') . '/')) { http_response_code(404); exit('Resource unavailable.'); }
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode((string) ($resource['original_filename'] ?? 'revision-resource.pdf')) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
