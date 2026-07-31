<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/LiveSessions.php';
require_once 'inc/LearningEvents.php';
require_once 'inc/CourseResourceResolver.php';

$pageName = 'live_sessions';
$username = $_SESSION['username'];
$conn = db();
$user_id = student_course_access_student_id($conn, $username);
$sessions = $user_id ? mmh_live_occurrences($conn, '', -30, 45, $user_id) : [];
if ($user_id) {
    mmh_log_event($conn, $user_id, 'live_session_viewed');
}

function live_user_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function live_user_session_local_datetime(array $session, $field = 'scheduled_start_at')
{
    $value = trim((string) ($session[$field] ?? ''));
    if ($value === '') {
        return null;
    }
    try {
        $timezone = mmh_live_timezone($session['timezone'] ?? 'Asia/Riyadh');
        $datetime = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $datetime->setTimezone($timezone);
    } catch (Throwable $exception) {
        return null;
    }
}

function live_user_week_end(DateTimeImmutable $now)
{
    return $now->modify('sunday this week')->setTime(23, 59, 59);
}

function live_user_group(array $sessions, array $recordingsByOccurrence)
{
    $now = time();
    $groups = ['Previous Recordings' => [], 'Today' => [], 'Upcoming This Week' => []];
    $previousRecordings = [];

    foreach ($sessions as $session) {
        $start = mmh_live_occurrence_timestamp($session, 'scheduled_start_at');
        if ($start === false) {
            continue;
        }

        $joinState = mmh_live_join_state($session, $now);
        $state = (string) ($joinState['state'] ?? '');
        $isEnded = $state === 'ended' || in_array(strtolower((string) ($session['status'] ?? '')), ['completed'], true);
        $isCancelled = $state === 'cancelled' || strtolower((string) ($session['status'] ?? '')) === 'cancelled';
        $occurrenceId = (string) ($session['occurrence_id'] ?? '');

        if ($isEnded && !$isCancelled && isset($recordingsByOccurrence[$occurrenceId])) {
            $previousRecordings[] = $session;
            continue;
        }

        if ($isEnded || $isCancelled) {
            continue;
        }

        $localStart = live_user_session_local_datetime($session, 'scheduled_start_at');
        if (!$localStart) {
            continue;
        }
        $localNow = (new DateTimeImmutable('now', $localStart->getTimezone()));
        $today = $localNow->format('Y-m-d');
        $sessionDay = $localStart->format('Y-m-d');
        $weekEnd = live_user_week_end($localNow);

        if ($sessionDay === $today) {
            $groups['Today'][] = $session;
        } elseif ($start > $now && $localStart <= $weekEnd) {
            $groups['Upcoming This Week'][] = $session;
        }
    }

    usort($previousRecordings, static function ($a, $b) {
        return (mmh_live_occurrence_timestamp($b, 'scheduled_start_at') ?: 0) <=> (mmh_live_occurrence_timestamp($a, 'scheduled_start_at') ?: 0);
    });
    $groups['Previous Recordings'] = array_slice($previousRecordings, 0, 2);

    return $groups;
}

function live_user_minutes_until_open(array $session)
{
    $start = mmh_live_occurrence_timestamp($session, 'scheduled_start_at');
    if ($start === false) {
        return null;
    }
    return max(1, (int) ceil((($start - 1800) - time()) / 60));
}

function live_user_attendance_text(array $session)
{
    $status = trim((string) ($session['attendance_status'] ?? 'unknown')) ?: 'unknown';
    $hasJoin = !empty($session['first_join_clicked_at']);
    if ($hasJoin) {
        return 'Join recorded at ' . mmh_live_format_local_datetime($session['first_join_clicked_at'], $session['timezone'] ?? 'Asia/Riyadh')
            . ' · ' . mmh_live_student_attendance_label($session);
    }
    if ($status !== 'unknown') {
        return mmh_live_student_attendance_label($session);
    }
    return '';
}

function live_user_recording_map(mysqli $conn, array $sessions)
{
    $occurrenceIds = array_values(array_unique(array_filter(array_map(static fn($session) => (string) ($session['occurrence_id'] ?? ''), $sessions))));
    if (!$occurrenceIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($occurrenceIds), '?'));
    $types = str_repeat('s', count($occurrenceIds));
    $sql = "SELECT s.release_occurrence_id, i.course_id, i.item_id, i.item_title, i.template_type, i.template_data
            FROM course_sections AS s
            INNER JOIN course_items AS i ON i.course_id = s.course_id AND i.section_id = s.section_id
            WHERE s.release_occurrence_id IN ({$placeholders})
              AND (i.status IS NULL OR i.status = '' OR i.status = 'published')
            ORDER BY s.sort_order ASC, i.page_order ASC, i.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$occurrenceIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $recordings = [];
    foreach ($rows as $row) {
        $templateType = strtolower(trim((string) ($row['template_type'] ?? '')));
        $isRecording = in_array($templateType, ['recording', 'video'], true);
        if (!$isRecording && $templateType === 'resource') {
            $data = mmh_course_resource_template_data($row['template_data'] ?? '');
            $resourceType = strtolower(trim((string) ($data['resource_type'] ?? ($data['resource']['type'] ?? ''))));
            $provider = strtolower(trim((string) ($data['resource_provider'] ?? ($data['resource']['provider'] ?? ''))));
            $isRecording = in_array($resourceType, ['recording', 'video'], true)
                || in_array($provider, ['microsoft_stream', 'sharepoint', 'youtube', 'vimeo'], true);
        }
        if (!$isRecording) {
            continue;
        }
        $occurrenceId = (string) ($row['release_occurrence_id'] ?? '');
        if ($occurrenceId !== '' && !isset($recordings[$occurrenceId])) {
            $recordings[$occurrenceId] = $row;
        }
    }
    return $recordings;
}

$liveJoinBase = rtrim((string) $baseUrl, '/') . '/user/live-session/join/';
$recordingsByOccurrence = live_user_recording_map($conn, $sessions);
$groups = live_user_group($sessions, $recordingsByOccurrence);
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include "layouts/user/header.php"; ?>
</head>
<body class='body ds-bg-primary student-dashboard-page student-live-sessions-page'>
<div id="app">
    <div id="body-overlay" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></div>
    <?php include "layouts/user/aside.php"; ?>
    <main class="p-0 font-2">
        <div class="student-dashboard ds-bg-primary">
            <div class="student-dashboard-top">
                <div class="container">
                    <section class="student-dashboard-welcome">
                        <div><span class="student-dashboard-eyebrow">Live sessions</span><h1>Your live teaching schedule</h1><p>Join through Math Mastery Hub so your teacher can see join-click evidence.</p></div>
                    </section>
                </div>
            </div>
            <div class="container student-dashboard-shell">
                <?php foreach ($groups as $label => $items): ?>
                    <?php if (empty($items)) { continue; } ?>
                    <?php $isRecordingGroup = $label === 'Previous Recordings'; ?>
                    <section class="student-courses-section mb-4">
                        <div class="student-section-heading"><span><?=live_user_html($label)?></span><h2><?=count($items)?> session<?=count($items) === 1 ? '' : 's'?></h2></div>
                        <div class="student-upcoming-list">
                            <?php foreach ($items as $session): ?>
                                <?php
                                    $joinState = mmh_live_join_state($session);
                                    $statusLabel = ucwords(str_replace('_', ' ', (string) $session['status']));
                                    $isEnded = ($joinState['state'] ?? '') === 'ended';
                                    $recording = $recordingsByOccurrence[(string) ($session['occurrence_id'] ?? '')] ?? null;
                                    $attendanceText = live_user_attendance_text($session);
                                    if (!$attendanceText && !$isEnded) {
                                        $attendanceText = 'Not recorded yet';
                                    }
                                    $actionLabel = '';
                                    if (($joinState['state'] ?? '') === 'opens_soon') {
                                        $minutes = live_user_minutes_until_open($session);
                                        $actionLabel = $minutes === null ? 'Opens soon' : 'Opens in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's');
                                    }
                                ?>
                                <article class="student-upcoming-item live-session-card">
                                    <div class="live-session-card-copy">
                                        <span class="live-session-meta-label">Course</span>
                                        <strong><?=live_user_html($session['course_title'])?></strong>
                                        <div class="live-session-card-grid">
                                            <span><em>Lesson</em><?=live_user_html($session['schedule_title'] ?: 'Live session')?></span>
                                            <span><em>Date &amp; Time</em><?=live_user_html(mmh_live_display_time($session))?></span>
                                            <?php if ($attendanceText !== ''): ?><span><em>Attendance</em><?=live_user_html($attendanceText)?></span><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="live-session-card-actions">
                                        <?php if (!$isEnded): ?><span class="student-upcoming-status <?=live_user_html($session['status'])?>"><?=live_user_html($statusLabel)?></span><?php endif; ?>
                                        <?php if ($isRecordingGroup && $recording): ?>
                                            <a class="student-dashboard-btn secondary live-session-join-button" href="<?=live_user_html(rtrim((string)$baseUrl, '/'))?>/user/course/resource/<?=live_user_html($recording['course_id'])?>/<?=live_user_html($recording['item_id'])?>"><span class="fas fa-play-circle" aria-hidden="true"></span> Watch Recording</a>
                                        <?php elseif (!empty($joinState['active'])): ?>
                                            <a class="student-dashboard-btn primary live-session-join-button" href="<?=live_user_html($liveJoinBase)?><?=live_user_html($session['occurrence_id'])?>"><span class="fas fa-video" aria-hidden="true"></span> Join Live</a>
                                        <?php elseif (!$isEnded): ?>
                                            <button class="student-dashboard-btn secondary live-session-join-button is-disabled" type="button" disabled><?=live_user_html($actionLabel ?: $joinState['label'])?></button>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>
<link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js" />
<link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/main-07febffb.js" />
<script type="module" src="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js" data-navigate-track="reload"></script>
<script src="../notification/main.js"></script>
</body>
</html>
