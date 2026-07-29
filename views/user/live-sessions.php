<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/LiveSessions.php';
require_once 'inc/LearningEvents.php';

$pageName = 'live_sessions';
$username = $_SESSION['username'];
$conn = db();
$user_id = student_course_access_student_id($conn, $username);
$sessions = $user_id ? mmh_live_occurrences($conn, '', -7, 45, $user_id) : [];
if ($user_id) {
    mmh_log_event($conn, $user_id, 'live_session_viewed');
}

function live_user_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function live_user_group(array $sessions)
{
    $now = time();
    $endToday = strtotime('today 23:59:59');
    $endWeek = strtotime('today +7 days 23:59:59');
    $groups = ['Today' => [], 'Upcoming this week' => [], 'Later' => [], 'Recently completed' => []];
    foreach ($sessions as $session) {
        $start = mmh_live_occurrence_timestamp($session, 'scheduled_start_at');
        if ($start === false) {
            continue;
        }
        if ($start < $now && in_array($session['status'], ['completed', 'cancelled'], true)) {
            $groups['Recently completed'][] = $session;
        } elseif ($start <= $endToday) {
            $groups['Today'][] = $session;
        } elseif ($start <= $endWeek) {
            $groups['Upcoming this week'][] = $session;
        } else {
            $groups['Later'][] = $session;
        }
    }
    return $groups;
}

$groups = live_user_group($sessions);
$liveJoinBase = rtrim((string) $baseUrl, '/') . '/user/live-session/join/';
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include "layouts/user/header.php"; ?>
</head>
<body class='body ds-bg-primary student-dashboard-page' style="margin-top: 65px">
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
                <div class="student-dashboard-nav-wrap"><div class="container p-0"><div class="col-12 row user-menu"><nav class='navbar navbar-expand-lg navbar-light ds-surface-muted'><div class="container-fluid p-0"><div class="col-12 px-0 row d-flex m-0 py-3 py-lg-0 justify-content-between align-items-center d-lg-none"><div class='navbar-brand navbar-toggler font-2 px-3 col-auto ds-text-secondary' data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent">Live Sessions</div><button class='navbar-toggler d-flex col-auto ds-shadow-sm' type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent"><span class="fas fa-bars"></span></button></div><?php include "layouts/user/main-nav.php"; ?></div></nav></div></div></div>
            </div>
            <div class="container student-dashboard-shell">
                <?php foreach ($groups as $label => $items): ?>
                    <?php if ($label === 'Recently completed') { $items = array_slice($items, 0, 5); } ?>
                    <section class="student-courses-section mb-4">
                        <div class="student-section-heading"><span><?=live_user_html($label)?></span><h2><?=count($items)?> session<?=count($items) === 1 ? '' : 's'?></h2></div>
                        <?php if ($items): ?>
                            <div class="student-upcoming-list">
                                <?php foreach ($items as $session): ?>
                                    <?php
                                        $joinState = mmh_live_join_state($session);
                                        $statusLabel = ucwords(str_replace('_', ' ', (string) $session['status']));
                                    ?>
                                    <article class="student-upcoming-item live-session-card">
                                        <div class="live-session-card-copy">
                                            <strong><?=live_user_html($session['course_title'])?></strong>
                                            <small><?=live_user_html($session['schedule_title'] ?: 'Live session')?> · <?=live_user_html(mmh_live_display_time($session))?></small>
                                            <span class="live-session-attendance-summary">
                                                <?php if (!empty($session['first_join_clicked_at'])): ?>
                                                    Join recorded at <?=live_user_html(mmh_live_format_local_datetime($session['first_join_clicked_at'], $session['timezone'] ?? 'Asia/Riyadh'))?> · <?=live_user_html(mmh_live_student_attendance_label($session))?>
                                                <?php else: ?>
                                                    <?=live_user_html(mmh_live_student_attendance_label($session))?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="live-session-card-actions">
                                            <span class="student-upcoming-status <?=live_user_html($session['status'])?>"><?=live_user_html($statusLabel)?></span>
                                            <?php if (!empty($joinState['active'])): ?>
                                                <a class="student-dashboard-btn primary live-session-join-button" href="<?=live_user_html($liveJoinBase)?><?=live_user_html($session['occurrence_id'])?>"><span class="fas fa-video" aria-hidden="true"></span> <?=live_user_html($joinState['label'])?></a>
                                            <?php else: ?>
                                                <button class="student-dashboard-btn secondary live-session-join-button is-disabled" type="button" disabled><?=live_user_html($joinState['label'])?></button>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="student-dashboard-empty compact"><span class="fas fa-calendar" aria-hidden="true"></span><p>No sessions in this group.</p></div>
                        <?php endif; ?>
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
