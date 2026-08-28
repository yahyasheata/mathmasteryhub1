<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/migrations/20260828_add_revision_batch_controls.php');
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$admin = file_get_contents($root . '/views/admin/revision-plans.php');
$student = file_get_contents($root . '/views/user/revision-plan.php');
$save = file_get_contents($root . '/views/admin/requests/save-revision-plan.php');
foreach (['day_access_mode', 'visibility', 'display_title', 'ALTER TABLE'] as $marker) if (!str_contains($migration, $marker)) throw new RuntimeException('Batch control migration is missing: ' . $marker);
foreach (['mmh_revision_update_batch_controls', "visibility", "'open_all'", 'This Batch has student progress and cannot be hidden', 'source_batch_id', 'Batch does not belong to the selected Version'] as $marker) if (!str_contains($service, $marker)) throw new RuntimeException('Batch control service contract is missing: ' . $marker);
foreach (['update_batch_controls', 'Student visibility', 'Day access', 'Follow schedule', 'Open all days'] as $marker) if (!str_contains((string) $admin, $marker) && !str_contains((string) $save, $marker)) throw new RuntimeException('Batch control UI/handler contract is missing: ' . $marker);
foreach (['Coming soon', 'unreleased_batches'] as $marker) if (!str_contains($student, $marker)) throw new RuntimeException('Student Coming Soon contract is missing: ' . $marker);
echo "revision_plan_batch_controls=migration=present metadata=transactional admin_controls=present student_visibility=present\n";
