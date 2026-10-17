<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/inc/LiveSessions.php';

$start = strtotime('2026-10-17 12:00:00 UTC');
$now = strtotime('2026-10-17 11:00:00 UTC');
$makeOccurrence = static function (string $id, int $offsetMinutes, string $status = 'scheduled') use ($start): array {
    $scheduledStart = $start + ($offsetMinutes * 60);
    return [
        'occurrence_id' => $id,
        'course_id' => 'course-1',
        'scheduled_start_at' => gmdate('Y-m-d H:i:s', $scheduledStart),
        'scheduled_end_at' => gmdate('Y-m-d H:i:s', $scheduledStart + 90 * 60),
        'status' => $status,
        'teams_url_snapshot' => 'https://teams.microsoft.com/l/meetup-join/' . $id,
    ];
};
$selectedId = static function (?array $selection): ?string {
    return $selection['occurrence']['occurrence_id'] ?? null;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// Before the join window, retain the nearest future occurrence and show its disabled state.
$upcoming = $makeOccurrence('upcoming', 60);
$selection = mmh_live_select_course_occurrence([$upcoming], $now);
$assert($selectedId($selection) === 'upcoming', 'Upcoming occurrence was not selected before its join window.');
$assert(($selection['join_state']['state'] ?? '') === 'opens_soon' && empty($selection['join_state']['active']), 'Upcoming Join should remain disabled before the join window.');

// Join window opens exactly 30 minutes before the scheduled start.
$assert(!empty(mmh_live_select_course_occurrence([$upcoming], $start + 60 * 60 - 1800)['join_state']['active']), 'Join did not open 30 minutes before the start.');
$atStart = mmh_live_select_course_occurrence([$makeOccurrence('at-start', 0)], $start);
$assert($selectedId($atStart) === 'at-start' && !empty($atStart['join_state']['active']), 'Join was unavailable at the scheduled start.');

// An in-progress occurrence and a status explicitly marked live remain joinable.
$during = mmh_live_select_course_occurrence([$makeOccurrence('during', 0)], $start + 45 * 60);
$assert($selectedId($during) === 'during' && ($during['join_state']['state'] ?? '') === 'live', 'Join was unavailable during the session.');
$liveStatus = $makeOccurrence('live-status', 0, 'live');
$liveSelection = mmh_live_select_course_occurrence([$liveStatus], $start + 15 * 60);
$assert($selectedId($liveSelection) === 'live-status' && !empty($liveSelection['join_state']['active']), 'A live-status occurrence was not joinable in its allowed window.');

// Preserve the endpoint's inclusive two-hour post-session re-entry grace.
$completed = $makeOccurrence('completed-grace', 0, 'completed');
$graceCutoff = $start + 90 * 60 + 2 * 60 * 60;
$graceSelection = mmh_live_select_course_occurrence([$completed], $graceCutoff);
$assert($selectedId($graceSelection) === 'completed-grace' && !empty($graceSelection['join_state']['active']), 'The post-session grace period was not preserved.');
$assert(mmh_live_select_course_occurrence([$completed], $graceCutoff + 1) === null, 'An ended occurrence remained selected after its grace period.');

// A joinable current occurrence outranks a later future occurrence.
$active = $makeOccurrence('active', 0);
$later = $makeOccurrence('later', 24 * 60);
$priority = mmh_live_select_course_occurrence([$active, $later], $start + 10 * 60);
$assert($selectedId($priority) === 'active', 'A later upcoming occurrence displaced the currently joinable one.');
$soon = $makeOccurrence('soon', 12 * 60);
$nearest = mmh_live_select_course_occurrence([$later, $soon], $now);
$assert($selectedId($nearest) === 'soon', 'The nearest valid upcoming occurrence was not selected.');

// Cancelled/deleted occurrences are never selected; cancelled join is also rejected by the shared rule.
foreach (['cancelled', 'deleted'] as $status) {
    $blocked = $makeOccurrence($status, 0, $status);
    $assert(mmh_live_select_course_occurrence([$blocked], $start + 10 * 60) === null, "A {$status} occurrence was selected.");
    $assert(empty(mmh_live_join_state($blocked, $start + 10 * 60)['active']), "A {$status} occurrence remained joinable.");
}

$assert(mmh_live_select_course_occurrence([], $now) === null, 'An empty occurrence list produced a selection.');

// The page query remains tied to its actual Course and authenticated user; the existing
// occurrence query applies course_logs enrollment filtering before selection.
$courseView = file_get_contents($root . '/views/user/course.php');
$liveSessions = file_get_contents($root . '/inc/LiveSessions.php');
$joinHandler = file_get_contents($root . '/views/user/requests/join-live-session.php');
$assert(is_string($courseView) && str_contains($courseView, 'mmh_live_occurrences($conn, $course_id, -3, 45, (int) $user_id)'), 'Course page did not request a recent, Course-scoped, enrolled-user occurrence window.');
$assert(str_contains($courseView, 'mmh_live_select_course_occurrence') && str_contains($courseView, '/user/live-session/join/'), 'Course page selection or protected join route is missing.');
$assert(str_contains($courseView, '<?php if ($course_upcoming_session): ?>'), 'Course page does not safely omit the live-session block when no occurrence is eligible.');
$assert(is_string($liveSessions) && str_contains($liveSessions, "INNER JOIN (SELECT DISTINCT course_id FROM course_logs WHERE user_id = ?) AS cl ON cl.course_id = o.course_id"), "Occurrence loading no longer scopes sessions to the student's enrolled Courses.");
$assert(is_string($joinHandler) && str_contains($joinHandler, 'student_course_access_enrolled') && str_contains($joinHandler, 'mmh_live_join_state'), 'Protected join endpoint lost enrollment or canonical join-window enforcement.');
$assert(str_contains($liveSessions, "mmh_live_occurrences(\$conn, '', -1, 7, (int) \$userId)"), 'Global Live Sessions recent-window behavior changed unexpectedly.');

echo "Course live-session selection checks passed.\n";
