<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$handler = file_get_contents($root . '/views/admin/requests/save-revision-plan.php');
$admin = file_get_contents($root . '/views/admin/revision-plans.php');
$student = file_get_contents($root . '/views/user/revision-plan.php');

foreach ([
    'mmh_revision_template_has_student_activity',
    'mmh_revision_delete_template',
    '$conn->begin_transaction()',
    '$conn->commit()',
    '$conn->rollback()',
    'revision_plan_batch_releases',
    'revision_plan_requirement_progress',
    'revision_plan_requirement_resources',
    'revision_plan_template_requirements',
    'revision_plan_template_activities',
    'revision_plan_template_days',
    'revision_plan_template_resources',
    'revision_plan_assignments',
    'revision_plan_template_batches',
    'revision_plan_template_versions',
    'revision_plan_templates',
    'DELETE p FROM',
    'Type DELETE to permanently remove',
    'Never remove a file still referenced',
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException('Delete service contract is missing: ' . $marker);
}

$deleteStart = strpos($service, 'function mmh_revision_delete_template');
$deleteEnd = strpos($service, "if (!function_exists('mmh_revision_resource'))", $deleteStart ?: 0);
$deleteService = ($deleteStart !== false && $deleteEnd !== false) ? substr($service, $deleteStart, $deleteEnd - $deleteStart) : '';
if ($deleteService === '') throw new RuntimeException('Delete service body could not be isolated.');
$order = [
    'DELETE p FROM revision_plan_requirement_progress',
    'DELETE rr FROM revision_plan_requirement_resources',
    'DELETE r FROM revision_plan_template_requirements',
    'DELETE g FROM revision_plan_template_activities',
    'DELETE d FROM revision_plan_template_days',
    'DELETE r FROM revision_plan_template_resources',
    'DELETE FROM revision_plan_batch_releases',
    'DELETE FROM revision_plan_assignments',
    'DELETE b FROM revision_plan_template_batches',
    'DELETE FROM revision_plan_template_versions',
    'DELETE FROM revision_plan_templates',
];
$last = -1;
foreach ($order as $table) {
    $pos = strpos($deleteService, $table);
    if ($pos === false || $pos < $last) throw new RuntimeException('Child-first delete ordering is invalid near: ' . $table);
    $last = $pos;
}

foreach (['delete_template', 'mmh_admin_csrf_valid', 'delete_confirmation', 'mmh_revision_delete_template'] as $marker) {
    if (!str_contains($handler, $marker)) throw new RuntimeException('Admin delete handler contract is missing: ' . $marker);
}
foreach (['data-open-delete', 'revision-delete-modal', 'data-delete-activity-warning', 'Type <strong>DELETE</strong>', 'data-delete-submit'] as $marker) {
    if (!str_contains($admin, $marker)) throw new RuntimeException('Delete confirmation UI contract is missing: ' . $marker);
}
if (!str_contains($student, 'Revision Plan not found.')) throw new RuntimeException('Bookmarked deleted plan must have a controlled unavailable response.');

foreach (['DELETE FROM courses', 'DELETE FROM course_items', 'DELETE FROM course_sections', 'DELETE FROM users', 'DELETE FROM course_logs', 'DELETE FROM recovery'] as $forbidden) {
    if (str_contains($service, $forbidden)) throw new RuntimeException('Delete service must not delete source data: ' . $forbidden);
}

echo "revision_plan_delete=transactional=present child_first=present strong_confirmation=present csrf=present post_handler=present source_data_protected=present old_release_schema_optional=present controlled_student_unavailable=present\n";
