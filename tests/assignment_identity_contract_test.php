<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/inc/CourseResourceResolver.php';
require_once $root . '/inc/AssignmentIdentity.php';

$item = [
    'course_id' => 'course-1',
    'item_id' => 'item-1',
    'assignment_id' => '12345',
    'template_type' => 'classified_assignment',
    'template_data' => json_encode(['assignment_id' => 'legacy-source']),
    'item_description' => '<span data-assignment-id="another-source"></span>',
];
if (mmh_course_assignment_id($item) !== '12345') {
    throw new RuntimeException('Canonical Course Item assignment_id was not preferred.');
}
if (mmh_assignment_identity_normalize_id('bad id') !== '') {
    throw new RuntimeException('Assignment identity accepted an invalid identifier.');
}
if (!mmh_assignment_identity_is_homework_item($item)) {
    throw new RuntimeException('Homework classification is missing.');
}
if (mmh_assignment_identity_is_homework_item(['template_type' => 'timed_exam', 'item_type' => 'quiz'])) {
    throw new RuntimeException('Timed Exam was incorrectly classified as Homework.');
}

$assignmentsPage = file_get_contents($root . '/views/user/assignments.php');
$progress = file_get_contents($root . '/inc/AssignmentProgress.php');
$studentProgress = file_get_contents($root . '/inc/StudentCourseProgress.php');
$gateway = file_get_contents($root . '/inc/StudentResourceGateway.php');
$copyService = file_get_contents($root . '/inc/CourseContentCopyService.php');
$reconciliation = file_get_contents($root . '/scripts/reconcile-assignment-identities.php');
$standardizer = file_get_contents($root . '/scripts/standardize-homework.php');
$adminPreview = file_get_contents($root . '/views/admin/course-content-preview.php');
$adminAssessment = file_get_contents($root . '/inc/AdminAssessmentService.php');
$sectionForm = file_get_contents($root . '/views/admin/requests/form-section.php');

if (str_contains($assignmentsPage, 'LiveAssignmentRepair.php') || str_contains($assignmentsPage, 'mmh_live_assignment_repair(')) {
    throw new RuntimeException('Student assignment page still performs read-time repair.');
}
if (!str_contains($standardizer, "require_once __DIR__ . '/../inc/AssignmentIdentity.php'") || !str_contains($standardizer, 'mmh_assignment_identity_for_item($conn, $row, true)')) {
    throw new RuntimeException('Homework standardizer bypasses the centralized Assignment identity resolver.');
}
if (!str_contains($adminPreview, "require_once 'inc/AssignmentIdentity.php'") || !str_contains($adminPreview, 'mmh_assignment_identity_for_item($conn, $item, true)')) {
    throw new RuntimeException('Admin Course Content preview bypasses the centralized Assignment identity resolver.');
}
if (!str_contains($adminAssessment, 'mmh_assignment_identity_column_exists($conn, \'course_items\', \'archived_at\')') || !str_contains($sectionForm, 'mmh_assignment_identity_column_exists($conn, \'course_items\', \'archived_at\')')) {
    throw new RuntimeException('Assignment Admin readers assume course_items.archived_at exists.');
}
if (str_contains($progress, 'mmh_assignment_progress_repair_duplicate_sources(')) {
    throw new RuntimeException('Assignment progress still performs read-time duplicate repair.');
}
if (str_contains($progress, 'mmh_assignment_progress_legacy_assignment_ids(') || str_contains($progress, 'mmh_assignment_progress_decode_template_data(')) {
    throw new RuntimeException('Assignment progress still contains scattered legacy identity parsing.');
}
foreach (['mmh_assignment_identity_for_item'] as $marker) {
    if (!str_contains($progress, $marker)) throw new RuntimeException("Progress identity marker missing: {$marker}");
}
if (!str_contains($studentProgress, 'mmh_assignment_identity_id')) throw new RuntimeException('Student Course progress does not use the centralized Assignment resolver.');
if (!str_contains($gateway, 'mmh_assignment_identity_for_item')) {
    throw new RuntimeException('Student gateway does not normalize Assignment identity.');
}
if (!str_contains($copyService, 'mmh_assignment_identity_for_item')) {
    throw new RuntimeException('Course copy does not use the central Assignment identity resolver.');
}
if (!str_contains($reconciliation, "array_key_exists('apply', \$options)")) {
    throw new RuntimeException('Reconciliation is not explicitly dry-run by default.');
}

echo "Assignment identity contract checks passed." . PHP_EOL;
