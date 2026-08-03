<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/inc/TimedExam.php';

$exam = [
    'scheduled_start_at_utc' => '2026-08-04 12:00:00',
    'duration_minutes' => 60,
    'grace_minutes' => 10,
    'late_submission_allowed' => 1,
];
$submitted = mmh_timed_exam_state($exam, ['state' => 'submitted'], new DateTimeImmutable('2026-08-04 12:30:00', new DateTimeZone('UTC')));
$graded = mmh_timed_exam_state($exam, ['state' => 'graded'], new DateTimeImmutable('2026-08-04 12:30:00', new DateTimeZone('UTC')));
if (($submitted['key'] ?? '') !== 'submitted' || ($graded['key'] ?? '') !== 'graded') throw new RuntimeException('Submitted and graded states are not preserved.');

$view = file_get_contents(dirname(__DIR__) . '/views/user/timed-exam.php');
if (!is_string($view) || !str_contains($view, 'data-dropzone') || !str_contains($view, 'data-remove-selection') || !str_contains($view, 'data-confirm-submit') || !str_contains($view, 'data-remove-upload') || !str_contains($view, 'Time Ended') || str_contains($view, 'height:100%') || str_contains($view, 'min-height:100vh')) {
    throw new RuntimeException('Compact Timed Exam uploader/state UX contract is missing.');
}

echo "Timed Exam upload/state tests passed.\n";
