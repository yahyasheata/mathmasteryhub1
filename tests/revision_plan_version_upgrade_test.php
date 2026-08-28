<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$admin = file_get_contents($root . '/views/admin/revision-plans.php');
$save = file_get_contents($root . '/views/admin/requests/save-revision-plan.php');
$migration = file_get_contents($root . '/database/migrations/20260829_add_revision_requirement_lineage.php');
$workflow = file_get_contents($root . '/.github/workflows/deploy.yml');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
foreach (['source_requirement_id', 'idx_revision_requirement_lineage'] as $marker) $assert(str_contains($migration, $marker), 'Lineage migration missing ' . $marker);
$assert(str_contains($workflow, '20260829_add_revision_requirement_lineage.php'), 'Lineage migration is not in the deployment sequence.');
foreach (['mmh_revision_prepare_editable_version', 'mmh_revision_upgrade_assignments_for_version', 'mmh_revision_transfer_assignment_state', 'source_requirement_id', "status = 'archived'", 'revision_plan_requirement_progress'] as $marker) $assert(str_contains($service, $marker), 'Version continuity service missing ' . $marker);
$upgrade = substr($service, strpos($service, 'function mmh_revision_upgrade_assignments_for_version'), 7000);
$assert(!str_contains($upgrade, 'DELETE FROM revision_plan_assignments'), 'Assignment reconciliation must not delete historical rows.');
foreach (['edit_template', 'mmh_revision_prepare_editable_version'] as $marker) $assert(str_contains($save, $marker), 'Admin edit action missing ' . $marker);
foreach (['Edit Revision Plan', 'Publish Changes', 'hasPublishedVersion', 'Existing student assignments will move to the new published version automatically.'] as $marker) $assert(str_contains($admin, $marker), 'Admin version UX missing ' . $marker);
$assert(!str_contains($admin, '>New Version<'), 'Technical New Version action is still exposed in the primary editor.');
$assert(str_contains($admin, 'source_requirement_id:parseInt'), 'Builder does not preserve requirement lineage in draft payload.');
echo "revision_plan_version_upgrade=lineage=present state_transfer=present duplicate_archive=present admin_edit_publish_changes=present\n";
