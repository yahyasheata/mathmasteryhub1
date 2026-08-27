<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$assignmentMigration = file_get_contents($root . '/database/migrations/20260827_create_revision_plan_assignments.php');
$adminView = file_get_contents($root . '/views/admin/revision-plans.php');
$adminHandler = file_get_contents($root . '/views/admin/requests/save-revision-plan.php');
$gateway = file_get_contents($root . '/inc/StudentResourceGateway.php');
$courseViewer = file_get_contents($root . '/views/user/requests/open-course-resource.php');
$studentList = file_get_contents($root . '/views/user/revision-plans.php');
$studentPlan = file_get_contents($root . '/views/user/revision-plan.php');
$privateResource = file_get_contents($root . '/views/user/requests/open-revision-resource.php');
$routes = file_get_contents($root . '/index.php');

foreach (['revision_plan_assignments', 'uq_revision_assignment_user_version', 'allow_work_ahead'] as $marker) {
    if (!str_contains($assignmentMigration, $marker)) throw new RuntimeException('Assignment migration is missing: ' . $marker);
}
foreach (['mmh_revision_assign_students', 'mmh_revision_assignment_context', 'mmh_revision_assignment_days', 'ON DUPLICATE KEY UPDATE'] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException('Assignment service contract is missing: ' . $marker);
}
foreach (['data-add-days', 'data-duplicate="day"', 'data-duplicate="requirement"', 'data-duplicate="batch"', 'Assign selected students', 'allow_work_ahead'] as $marker) {
    if (!str_contains($adminView, $marker)) throw new RuntimeException('Builder UX contract is missing: ' . $marker);
}
if (!str_contains($adminHandler, "action === 'assign_students'") || !str_contains($adminHandler, "action === 'publish_and_assign'")) throw new RuntimeException('Assignment handler is missing.');
foreach (['revision_assignment', 'revision_requirement', 'mmh_revision_assignment_context'] as $marker) {
    if (!str_contains($gateway, $marker)) throw new RuntimeException('Gateway Revision context is missing: ' . $marker);
}
if (!str_contains($courseViewer, "revision_assignment_id")) throw new RuntimeException('Canonical resource route does not pass Revision context.');
foreach (['mmh_revision_student_assignments', 'Your Plans'] as $marker) {
    if (!str_contains($studentList, $marker)) throw new RuntimeException('Student plan list is missing: ' . $marker);
}
foreach (['Today', 'Submission will be available here', 'Open content', 'mmh_revision_assignment_context'] as $marker) {
    if (!str_contains($studentPlan, $marker)) throw new RuntimeException('Read-only student workspace is missing: ' . $marker);
}
foreach (['storage/private/revision-plans', 'Cache-Control', 'mmh_revision_assignment_context'] as $marker) {
    if (!str_contains($privateResource, $marker)) throw new RuntimeException('Protected Revision resource delivery is missing: ' . $marker);
}
foreach (['/revision-plans', '/revision-plan/{assignmentId}', '/revision-resource/{assignmentId}/{resourceId}'] as $marker) {
    if (!str_contains($routes, $marker)) throw new RuntimeException('Student Revision route is missing: ' . $marker);
}
if (str_contains($studentList . $studentPlan, 'student_learning_evidence') || str_contains($studentList . $studentPlan, 'course_item_progress')) {
    throw new RuntimeException('Phase 3A student views must not write progress.');
}
echo "phase3a=assignment_builder=present student_read_only=present gateway_context=validated progress_writes=none\n";
