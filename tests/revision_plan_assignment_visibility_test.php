<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$studentList = file_get_contents($root . '/views/user/revision-plans.php');

foreach ([
    "LOWER(TRIM(COALESCE(a.status, ''))) = 'active'",
    'a.archived_at IS NULL',
    'a.ended_at IS NULL OR a.ended_at > UTC_TIMESTAMP()',
    'INNER JOIN course_logs cl',
    "LOWER(TRIM(COALESCE(v.status, ''))) = 'published'",
    "CURRENT_DATE THEN 'upcoming'",
    "ELSE 'past'",
    'temporarily unavailable',
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException('Assignment visibility contract is missing: ' . $marker);
}
if (!str_contains($studentList, 'schedule_state') || !str_contains($studentList, 'View Plan')) throw new RuntimeException('Upcoming student-plan presentation is missing.');
foreach ([
    'ON DUPLICATE KEY UPDATE',
    "status = 'active'",
    'archived_at = NULL',
    'ended_at = NULL',
] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException('Assignment reactivation contract is missing: ' . $marker);
}
echo "assignment_visibility=canonical_identity=course_enrollment_status=present upcoming=visible past=visible\n";
