<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$migration = file_get_contents($root . '/database/migrations/20260827_create_revision_plan_batch_releases.php');
$student = file_get_contents($root . '/views/user/revision-plan.php');
$gateway = file_get_contents($root . '/inc/StudentResourceGateway.php');
$workflow = file_get_contents($root . '/.github/workflows/deploy.yml');

foreach (['revision_plan_batch_releases', 'uq_revision_batch_release_position', 'source_version_id', 'source_batch_id'] as $marker) {
    if (!str_contains($migration, $marker)) throw new RuntimeException('Batch release migration contract is missing: ' . $marker);
}
foreach (['mmh_revision_batch_release_schema_available', 'mmh_revision_batch_has_content', 'mmh_revision_apply_batch_releases', 'released_at <= UTC_TIMESTAMP()', 'Prepare the first Batch before publishing', 'begin_transaction', 'modify(', 'date_default_timezone_get()'] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException('Progressive release service contract is missing: ' . $marker);
}
foreach (['unreleased_batches', 'Coming soon', 'More revision content is on the way'] as $marker) {
    if (!str_contains($student, $marker)) throw new RuntimeException('Student coming-soon Batch UI is missing: ' . $marker);
}
if (!str_contains($gateway, 'mmh_revision_assignment_context')) throw new RuntimeException('Gateway does not use the released Batch context boundary.');
if (!str_contains($workflow, '20260827_create_revision_plan_batch_releases.php')) throw new RuntimeException('Deployment workflow does not run the Batch release migration.');

require_once $root . '/inc/RevisionPlan.php';
$empty = ['days' => [['requirements' => [], 'activity_groups' => []]]];
$ready = ['days' => [['requirements' => [['id' => 1]], 'activity_groups' => []]]];
if (mmh_revision_batch_has_content($empty)) throw new RuntimeException('Empty Batch must remain unreleased.');
if (!mmh_revision_batch_has_content($ready)) throw new RuntimeException('Prepared Batch must be releaseable.');

echo "revision_plan_progressive_release=migration=present immutable_source_snapshots=present empty_batch_safe=present student_coming_soon=present gateway_boundary=present\n";
