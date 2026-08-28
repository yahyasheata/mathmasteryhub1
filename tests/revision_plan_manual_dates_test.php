<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/inc/RevisionPlan.php';
$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/migrations/20260828_add_revision_manual_day_dates.php');
$adminView = file_get_contents($root . '/views/admin/revision-plans.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
foreach (['schedule_mode', 'schedule_start_date', 'scheduled_date', 'ALTER TABLE', "DEFAULT 'automatic'"] as $needle) $assert(str_contains($migration, $needle), 'Manual date migration contract missing: ' . $needle);
foreach (['Consecutive dates', 'Custom dates', 'First Day date', 'Study date', 'schedule_start_date', 'scheduled_date', 'schedule_mode', 'data-batch-title-field', 'syncBatchTitleFields'] as $needle) $assert(str_contains($adminView, $needle), 'Admin date UX contract missing: ' . $needle);
$automatic = [
    'start_date' => '2026-08-30',
    'version' => ['allow_work_ahead' => 0, 'batches' => [['title' => 'Batch 1', 'day_access_mode' => 'follow_schedule', 'schedule_mode' => 'automatic', 'days' => [['title' => 'Day 1'], ['title' => 'Day 2']]]]],
];
$days = mmh_revision_assignment_days($automatic);
$assert(array_column($days, 'scheduled_date') === ['2026-08-30', '2026-08-31'], 'Automatic schedule changed.');
$consecutive = [
    'start_date' => '2026-12-01',
    'version' => ['allow_work_ahead' => 0, 'batches' => [
        ['title' => 'Batch 1', 'day_access_mode' => 'follow_schedule', 'schedule_mode' => 'automatic', 'schedule_start_date' => '2026-08-30', 'days' => [['title' => 'Day 1'], ['title' => 'Day 2'], ['title' => 'Day 3']]],
        ['title' => 'Batch 2', 'day_access_mode' => 'follow_schedule', 'schedule_mode' => 'automatic', 'schedule_start_date' => '2026-09-05', 'days' => [['title' => 'Day 1'], ['title' => 'Day 2']]],
    ]],
];
$days = mmh_revision_assignment_days($consecutive);
$assert(array_column($days, 'scheduled_date') === ['2026-08-30', '2026-08-31', '2026-09-01', '2026-09-05', '2026-09-06'], 'Consecutive Batch dates did not use independent Batch start dates.');
$manual = [
    'start_date' => '2026-08-30',
    'version' => ['allow_work_ahead' => 0, 'batches' => [
        ['title' => 'Workshop 1', 'day_access_mode' => 'follow_schedule', 'schedule_mode' => 'manual', 'days' => [['title' => 'Day 1', 'scheduled_date' => '2026-08-30'], ['title' => 'Day 2', 'scheduled_date' => '2026-09-01'], ['title' => 'Day 3', 'scheduled_date' => '2026-09-04']]],
        ['title' => 'Workshop 2', 'day_access_mode' => 'follow_schedule', 'schedule_mode' => 'manual', 'days' => [['title' => 'Day 1', 'scheduled_date' => '2026-09-08'], ['title' => 'Day 2', 'scheduled_date' => '2026-09-10']]],
    ]],
];
$days = mmh_revision_assignment_days($manual);
$assert(array_column($days, 'scheduled_date') === ['2026-08-30', '2026-09-01', '2026-09-04', '2026-09-08', '2026-09-10'], 'Manual dates did not remain authored across Batches.');
$assert($days[1]['accessible'] === false && $days[3]['accessible'] === false, 'Manual future dates unlocked early.');
$openAll = $manual;
$openAll['version']['batches'][0]['day_access_mode'] = 'open_all';
$openDays = mmh_revision_assignment_days($openAll);
$assert($openDays[2]['accessible'] === true, 'Open All Days did not override a future manual date.');
$assert(mmh_revision_normalize_study_date('2026-09-01', true) === '2026-09-01', 'Valid date normalization failed.');
$invalid = false;
try { mmh_revision_normalize_study_date('2026-02-30', true); } catch (InvalidArgumentException $e) { $invalid = true; }
$assert($invalid, 'Invalid Study date was accepted.');
echo "revision_plan_manual_dates=automatic_compatibility=PASS consecutive_batch_starts=PASS manual_dates=PASS batch_boundary=PASS open_all=PASS locking=PASS validation=PASS\n";
