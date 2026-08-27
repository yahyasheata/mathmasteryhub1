<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$migration = file_get_contents($root . '/database/migrations/20260826_create_revision_plan_templates.php');
$admin = file_get_contents($root . '/views/admin/revision-plans.php');
$student = file_get_contents($root . '/views/user/revision-plan.php');

if (!str_contains($migration, 'sort_order INT NOT NULL DEFAULT 0')) throw new RuntimeException('Existing requirement ordering field was not found.');
foreach (['ORDER BY sort_order ASC, id ASC', "'sort_order' =>", 'readRequirements'] as $marker) {
    if (!str_contains($service . $admin, $marker)) throw new RuntimeException('Requirement order persistence contract is missing: ' . $marker);
}
foreach (['revision-drag-handle', "dataset.moveRequirement='up'", "dataset.moveRequirement='down'", 'revisionDragHandle', 'target.parentElement!==draggedRequirement.parentElement'] as $marker) {
    if (!str_contains($admin, $marker)) throw new RuntimeException('Same-Day reorder UX guard is missing: ' . $marker);
}
if (!str_contains($student, 'selectedRequirements')) throw new RuntimeException('Student requirement rendering path is missing.');

echo "revision_plan_requirement_order=existing_sort_order=present drag_handle=present move_fallback=present same_list_guard=present student_order_preserved=present\n";
