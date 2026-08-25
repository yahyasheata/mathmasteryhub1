<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$migration = file_get_contents($root . '/database/migrations/20260826_create_revision_plan_templates.php');
$view = file_get_contents($root . '/views/admin/revision-plans.php');
$handler = file_get_contents($root . '/views/admin/requests/save-revision-plan.php');
$security = file_get_contents($root . '/inc/AdminSecurity.php');
$aside = file_get_contents($root . '/views/admin/layouts/admin/aside.php');

foreach ([
    'revision_plan_templates',
    'revision_plan_template_versions',
    'revision_plan_template_batches',
    'revision_plan_template_days',
    'revision_plan_template_activities',
    'revision_plan_template_requirements',
    'revision_plan_template_resources',
    'revision_plan_requirement_resources',
] as $table) {
    if (!str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table)) throw new RuntimeException('Missing additive table: ' . $table);
}
foreach ([
    'mmh_revision_create_template',
    'mmh_revision_save_draft',
    'mmh_revision_publish_version',
    'mmh_revision_clone_version',
    'mmh_revision_save_resource',
] as $function) {
    if (!str_contains($service, 'function ' . $function)) throw new RuntimeException('Missing service function: ' . $function);
}
if (!str_contains($service, "status'] !== 'draft") || !str_contains($service, 'Only a Draft Version can be edited')) {
    throw new RuntimeException('Draft-only mutation guard is missing.');
}
if (!str_contains($service, 'status = \'published\'') || !str_contains($service, 'version_number')) {
    throw new RuntimeException('Immutable publication/version behavior is missing.');
}
if (!str_contains($service, 'mmh_revision_course_items') || !str_contains($service, 'linked_course_item_id')) {
    throw new RuntimeException('Same-course Course Item validation is missing.');
}
if (!str_contains($service, 'storage/private/revision-plans') || !str_contains($service, 'application/pdf')) {
    throw new RuntimeException('Private PDF storage validation is missing.');
}
if (!str_contains($view, 'Add Batch') || !str_contains($view, 'Add Day') || !str_contains($view, 'Add Group') || !str_contains($view, 'Add Requirement')) {
    throw new RuntimeException('Hierarchy builder controls are missing.');
}
if (!str_contains($view, 'Shared Materials') || !str_contains($view, 'resource_ids')) {
    throw new RuntimeException('Shared material reuse UI is missing.');
}
if (!str_contains($security, "'revision-plans'") || !str_contains($security, "'save-revision-plan.php'")) {
    throw new RuntimeException('Admin allowlists are missing Revision routes.');
}
if (!str_contains($aside, 'href="revision-plans"')) throw new RuntimeException('Revision Plans navigation link is missing.');
if (str_contains($service, 'student_learning_evidence') || str_contains($service, 'course_item_progress')) {
    throw new RuntimeException('Revision service must not write Learning Journey state.');
}
if (str_contains($handler, 'recovery_plan') || str_contains($view, 'recovery_plan')) {
    throw new RuntimeException('Revision admin flow must remain isolated from Recovery identifiers.');
}
echo "schema=additive hierarchy=present versioning=immutable resources=private recovery_isolated=yes\n";
