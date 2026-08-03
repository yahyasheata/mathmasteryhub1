<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/inc/TimedExam.php';

$exam = [
    'scheduled_start_at_utc' => '2026-08-03 12:00:00',
    'duration_minutes' => 90,
    'grace_minutes' => 15,
    'late_submission_allowed' => 1,
    'expiry_policy' => 'auto_submit_latest',
];
$window = mmh_timed_exam_window($exam);
if (!$window['opens_at'] || $window['opens_at']->format('Y-m-d H:i:s') !== '2026-08-03 12:00:00'
    || $window['closes_at']->format('Y-m-d H:i:s') !== '2026-08-03 13:30:00'
    || $window['grace_closes_at']->format('Y-m-d H:i:s') !== '2026-08-03 13:45:00') {
    throw new RuntimeException('Fixed Window close or grace calculation is incorrect.');
}
$before = mmh_timed_exam_state($exam, null, new DateTimeImmutable('2026-08-03 11:59:59', new DateTimeZone('UTC')));
$open = mmh_timed_exam_state($exam, null, new DateTimeImmutable('2026-08-03 13:29:59', new DateTimeZone('UTC')));
$grace = mmh_timed_exam_state($exam, null, new DateTimeImmutable('2026-08-03 13:40:00', new DateTimeZone('UTC')));
$expired = mmh_timed_exam_state($exam, null, new DateTimeImmutable('2026-08-03 13:46:00', new DateTimeZone('UTC')));
foreach ([[$before, 'before'], [$open, 'open'], [$grace, 'grace'], [$expired, 'expired']] as [$actual, $expected]) {
    if (($actual['key'] ?? '') !== $expected) throw new RuntimeException("Expected {$expected} state.");
}
$recovery = mmh_timed_exam_with_window($exam, '2026-08-04 09:00:00', '2026-08-04 10:15:00');
if (($recovery['duration_minutes'] ?? 0) !== 75 || ($recovery['grace_minutes'] ?? -1) !== 0) throw new RuntimeException('Recovery exam window was not isolated.');

$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/20260804_create_timed_exams.php');
if (!is_string($migration) || !str_contains($migration, 'UNIQUE KEY uq_timed_exam_active') || !str_contains($migration, "timing_mode VARCHAR(24) NOT NULL DEFAULT 'fixed_window'")) {
    throw new RuntimeException('Timed Exam migration is missing fixed-window concurrency safeguards.');
}

echo "Timed Exam fixed-window timing tests passed.\n";
