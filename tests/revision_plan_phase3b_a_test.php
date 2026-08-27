<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$student = file_get_contents($root . '/views/user/revision-plan.php');
$handler = file_get_contents($root . '/views/user/requests/revision-plan-progress.php');
$routes = file_get_contents($root . '/index.php');
$migration = file_get_contents($root . '/database/migrations/20260827_create_revision_plan_requirement_progress.php');

foreach (['mmh_revision_requirement_is_actionable', "['checklist', 'resource', 'course_item']", 'mmh_revision_assignment_progress', 'mmh_revision_progress_summary', 'mmh_revision_set_requirement_complete', 'mmh_revision_assignment_context'] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException('Revision progress service contract is missing: ' . $marker);
}
foreach (['revision_plan_requirement_progress', 'uq_revision_progress_assignment_requirement', 'fk_revision_progress_assignment', 'fk_revision_progress_requirement'] as $marker) {
    if (!str_contains($migration, $marker)) throw new RuntimeException('Revision progress migration contract is missing: ' . $marker);
}
foreach (['Mark Done', 'Completed · Undo', 'Revision Plan Progress', 'actionable requirements completed', 'Open Course Item'] as $marker) {
    if (!str_contains($student, $marker)) throw new RuntimeException('Student completion UI is missing: ' . $marker);
}
foreach (['csrf_token', "['complete', 'undo']", 'mmh_revision_set_requirement_complete', '303'] as $marker) {
    if (!str_contains($handler, $marker)) throw new RuntimeException('Protected completion handler is missing: ' . $marker);
}
if (!str_contains($routes, '/revision-plan/{assignmentId}/requirement/{requirementId}/completion')) throw new RuntimeException('Completion route is missing.');
if (str_contains($student, 'Read-only checklist') || str_contains($student, 'Read-only revision plan')) throw new RuntimeException('Obsolete read-only wording remains.');
if (str_contains($student, 'StudentLearningJourney') || str_contains($student, 'course_item_progress') || str_contains($student, 'student_learning_evidence')) throw new RuntimeException('Student Revision view must not write canonical Learning Journey state.');
echo "revision_plan_phase3b_a=checklist_course_item_progress=protected_no_lj_writes=present\n";
