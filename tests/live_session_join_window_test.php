<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/inc/LiveSessions.php';

$occurrence = [
    'scheduled_start_at' => '2026-08-09 19:00:00',
    'scheduled_end_at' => '2026-08-09 21:00:00',
    'status' => 'scheduled',
    'teams_url_snapshot' => 'https://teams.microsoft.com/l/meetup-join/test',
];

$window = mmh_live_join_window($occurrence);
$expectedOpen = strtotime('2026-08-09 18:30:00 UTC');
$expectedClose = strtotime('2026-08-09 23:00:00 UTC');
if ($window['opens_at'] !== $expectedOpen || $window['closes_at'] !== $expectedClose) {
    throw new RuntimeException('Live-session join window was calculated from the wrong timestamps.');
}

$cases = [
    ['18:29:59', false, 'opens_soon'],
    ['18:30:00', true, 'ready'],
    ['19:00:00', true, 'live'],
    ['21:00:00', true, 'live'],
    ['22:00:00', true, 'ready'],
    ['22:59:59', true, 'ready'],
    ['23:00:00', true, 'ready'],
    ['23:00:01', false, 'ended'],
];
foreach ($cases as [$time, $active, $state]) {
    $actual = mmh_live_join_state($occurrence, strtotime('2026-08-09 ' . $time . ' UTC'));
    if ((bool) $actual['active'] !== $active || $actual['state'] !== $state) {
        throw new RuntimeException("Unexpected join state at {$time}: {$actual['state']}");
    }
}

$completedDuringGrace = $occurrence;
$completedDuringGrace['status'] = 'completed';
if (empty(mmh_live_join_state($completedDuringGrace, strtotime('2026-08-09 22:00:00 UTC'))['active'])) {
    throw new RuntimeException('A completed occurrence lost its two-hour re-entry window.');
}

foreach (['cancelled', 'deleted'] as $blockedStatus) {
    $blocked = $occurrence;
    $blocked['status'] = $blockedStatus;
    if (!empty(mmh_live_join_state($blocked, strtotime('2026-08-09 20:00:00 UTC'))['active'])) {
        throw new RuntimeException("{$blockedStatus} occurrence was joinable.");
    }
}

$invalid = $occurrence;
$invalid['teams_url_snapshot'] = 'https://example.com/not-teams';
if (!empty(mmh_live_join_state($invalid, strtotime('2026-08-09 20:00:00 UTC'))['active'])) {
    throw new RuntimeException('An occurrence with an invalid meeting URL was joinable.');
}

$joinHandler = file_get_contents($root . '/views/user/requests/join-live-session.php');
if (!is_string($joinHandler) || !str_contains($joinHandler, 'student_course_access_enrolled') || !str_contains($joinHandler, 'mmh_live_join_state')) {
    throw new RuntimeException('The protected join endpoint lost enrollment or canonical-window enforcement.');
}
$liveSessions = file_get_contents($root . '/inc/LiveSessions.php');
if (!is_string($liveSessions) || !str_contains($liveSessions, "mmh_live_occurrences(\$conn, '', -1, 7, (int) \$userId)")) {
    throw new RuntimeException('Dashboard priority does not include the recent past re-entry window.');
}
$router = file_get_contents($root . '/index.php');
if (!is_string($router) || !str_contains($router, "'/live-session/join/{occurrenceId}'") || !str_contains($router, "if (!isset(\$_SESSION['username']))")) {
    throw new RuntimeException('The live-session join route is not protected by authentication.');
}

echo "Live-session join-window checks passed.\n";
