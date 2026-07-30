<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/LiveSessions.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/AssignmentProgress.php';
$pageName = "mycourses";
$username = $_SESSION['username'];
$user_info = getUserInfo($username);
$user_data = is_object($user_info) ? get_object_vars($user_info) : [];
$user_id = (int) ($user_data['user_id'] ?? 0);
$user_full_name = trim((string) ($user_data['full_name'] ?? ''));
$user_first_name = student_dashboard_first_name($user_full_name);
$conn = db();

function student_dashboard_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function student_dashboard_first_name($fullName)
{
    $parts = preg_split('/\s+/u', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY);
    return !empty($parts[0]) ? (string) $parts[0] : 'there';
}

function student_dashboard_table_exists(mysqli $conn, $table)
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

function student_dashboard_relative_time($value, $fallback = 'No activity yet')
{
    $timestamp = !empty($value) ? strtotime((string) $value) : false;
    if ($timestamp === false) {
        return $fallback;
    }
    $difference = max(0, time() - $timestamp);
    if ($difference < 60) {
        return 'Just now';
    }
    if ($difference < 3600) {
        $minutes = (int) floor($difference / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
    }
    if ($difference < 86400) {
        $hours = (int) floor($difference / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    $days = (int) floor($difference / 86400);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

function student_dashboard_date_label($value, $fallback = 'No date set')
{
    $timestamp = !empty($value) ? strtotime((string) $value) : false;
    return $timestamp === false ? $fallback : date('j M Y', $timestamp);
}

$courses_query = "
    SELECT courses.id as courseId, courses.*, categories.category_title,
           COALESCE(course_item_counts.item_count, 0) AS item_count,
           COALESCE(homework_counts.homework_count, 0) AS homework_count,
           course_logs.*
    FROM course_logs
    INNER JOIN courses ON course_logs.course_id = courses.course_id
    LEFT JOIN categories ON courses.course_category = categories.id
    LEFT JOIN (
        SELECT course_id, COUNT(item_id) AS item_count
        FROM course_items
        WHERE status IS NULL OR status = '' OR status = 'published'
        GROUP BY course_id
    ) AS course_item_counts ON courses.course_id = course_item_counts.course_id
    LEFT JOIN (
        SELECT course_id, COUNT(item_id) AS homework_count
        FROM course_items
        WHERE (status IS NULL OR status = '' OR status = 'published')
          AND (template_type = 'classified_assignment' OR item_type IN ('assignment', 'homework', 'quiz'))
        GROUP BY course_id
    ) AS homework_counts ON courses.course_id = homework_counts.course_id
    WHERE course_logs.user_id = '$user_id'
    ORDER BY course_logs.purchase_date DESC, courses.id ASC";

$coures_result = mysqli_query($conn,$courses_query);
$enrolled_courses = [];
if ($coures_result && mysqli_num_rows($coures_result) > 0) {
    while ($courses_data = mysqli_fetch_assoc($coures_result)) {
        $enrolled_courses[] = $courses_data;
    }
}

$latest_activity_by_course = [];
$latest_activity = null;
if (student_dashboard_table_exists($conn, 'learning_events')) {
    $activity_stmt = $conn->prepare(
        'SELECT le.course_id, MAX(le.created_at) AS last_activity_at
         FROM learning_events AS le
         INNER JOIN course_logs AS cl ON cl.course_id = le.course_id AND cl.user_id = ?
         WHERE le.user_id = ?
         GROUP BY le.course_id'
    );
    if ($activity_stmt) {
        $activity_stmt->bind_param('ii', $user_id, $user_id);
        $activity_stmt->execute();
        $activity_result = $activity_stmt->get_result();
        while ($activity_row = $activity_result->fetch_assoc()) {
            $latest_activity_by_course[(string) $activity_row['course_id']] = $activity_row['last_activity_at'];
        }
        $activity_stmt->close();
    }

    $continue_stmt = $conn->prepare(
        'SELECT le.course_id, le.section_id, le.item_id, le.event_type, le.created_at,
                c.id AS courseId, c.course_title, c.course_image,
                ci.item_title, cs.title AS section_title
         FROM learning_events AS le
         INNER JOIN course_logs AS cl ON cl.course_id = le.course_id AND cl.user_id = ?
         INNER JOIN courses AS c ON c.course_id = le.course_id
         LEFT JOIN course_items AS ci ON ci.course_id = le.course_id AND ci.item_id = le.item_id
         LEFT JOIN course_sections AS cs ON cs.course_id = le.course_id AND cs.section_id = le.section_id
         WHERE le.user_id = ?
         ORDER BY le.created_at DESC
         LIMIT 1'
    );
    if ($continue_stmt) {
        $continue_stmt->bind_param('ii', $user_id, $user_id);
        $continue_stmt->execute();
        $latest_activity = $continue_stmt->get_result()->fetch_assoc() ?: null;
        $continue_stmt->close();
    }
}

$section_progress_by_course = [];
$assignment_progress_by_course = [];
$upcoming_items = [];
$homework_due_count = 0;
$pending_verification_count = 0;
$priority_assignment = null;
foreach ($enrolled_courses as $enrolledCourse) {
    $courseKey = (string) ($enrolledCourse['course_id'] ?? '');
    if ($courseKey === '') {
        continue;
    }
    $assignmentMap = mmh_assignment_progress_load_course($conn, $user_id, $courseKey);
    $assignment_progress_by_course[$courseKey] = $assignmentMap;
    $courseRecord = student_course_access_course($conn, $courseKey);
    $sections = $courseRecord ? student_course_access_visible_sections($conn, $courseKey) : [];
    $sectionProgress = $courseRecord ? student_course_access_progress_map($conn, $courseKey, $user_id) : [];
    $completedSections = 0;
    foreach ($sections as $section) {
        if (student_course_access_section_completed($conn, $section, $sectionProgress, $user_id, $assignmentMap)) {
            $completedSections++;
        }
    }
    $section_progress_by_course[$courseKey] = [
        'total_sections' => count($sections),
        'completed_sections' => $completedSections,
    ];
    foreach (mmh_assignment_progress_attention($assignmentMap) as $assignment) {
        $state = $assignment['_state'] ?? [];
        $assignment['courseId'] = $enrolledCourse['courseId'];
        $assignment['course_title'] = $enrolledCourse['course_title'];
        $assignment['course_image'] = $enrolledCourse['course_image'] ?? '';
        $assignment['_dashboard_status'] = $state['label'] ?? 'Not started';
        $upcoming_items[] = $assignment;
        if (($state['state'] ?? '') === 'awaiting_review') {
            $pending_verification_count++;
        }
        if (in_array(($state['state'] ?? ''), ['overdue', 'due_soon', 'needs_revision'], true)) {
            $homework_due_count++;
        }
    }
}
usort($upcoming_items, function ($a, $b) {
    return (int) ($a['_priority'] ?? 99) <=> (int) ($b['_priority'] ?? 99);
});
$upcoming_items = array_slice($upcoming_items, 0, 5);
$priority_assignment = $upcoming_items[0] ?? null;

$current_streak = null;
if (student_dashboard_table_exists($conn, 'learning_daily_activity')) {
    $streak_stmt = $conn->prepare('SELECT activity_date FROM learning_daily_activity WHERE user_id = ? ORDER BY activity_date DESC LIMIT 45');
    if ($streak_stmt) {
        $streak_stmt->bind_param('i', $user_id);
        $streak_stmt->execute();
        $streak_rows = $streak_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $streak_dates = array_flip(array_column($streak_rows, 'activity_date'));
        $cursor = strtotime(date('Y-m-d'));
        $current_streak = 0;
        while ($cursor !== false && isset($streak_dates[date('Y-m-d', $cursor)])) {
            $current_streak++;
            $cursor = strtotime('-1 day', $cursor);
        }
        $streak_stmt->close();
    }
}

$sections_completed = 0;
foreach ($section_progress_by_course as $progress_row) {
    $sections_completed += (int) ($progress_row['completed_sections'] ?? 0);
}

$continue_course = null;
if ($priority_assignment) {
    $continue_course = [
        'courseId' => $priority_assignment['courseId'],
        'course_id' => $priority_assignment['course_id'],
        'course_title' => $priority_assignment['course_title'],
        'course_image' => $priority_assignment['course_image'] ?? '',
        'section_title' => '',
        'item_title' => $priority_assignment['assignment_title'] . ' — ' . ($priority_assignment['_dashboard_status'] ?? 'Needs attention'),
        'created_at' => $priority_assignment['due_date'] ?? '',
        'item_id' => $priority_assignment['item_id'] ?? '',
    ];
} elseif ($latest_activity) {
    $continue_course = $latest_activity;
} elseif (!empty($enrolled_courses)) {
    $first_course = $enrolled_courses[0];
    $continue_course = [
        'courseId' => $first_course['courseId'],
        'course_id' => $first_course['course_id'],
        'course_title' => $first_course['course_title'],
        'course_image' => $first_course['course_image'],
        'section_title' => '',
        'item_title' => '',
        'created_at' => $first_course['purchase_date'] ?? '',
    ];
}

$live_priority = mmh_live_current_priority($conn, $user_id);
$this_week_live_sessions = array_slice(mmh_live_occurrences($conn, '', 0, 7, $user_id), 0, 5);
$live_join_base = rtrim((string) $baseUrl, '/') . '/user/live-session/join/';
?>
<!doctype html>
<html lang="en" dir="ltr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="0PjjLlpM6FmXxBStqiehsWgYBO2MSNJkr7ZTKvPk">
    <!-- <title><?=$site_name;?></title>
<meta name="title" content="<?=$site_name;?>"> -->
    <!---
Achievement is not attained by mere wishes, but by striving for it.
What is difficult for a people is only so if they lack determination.
Ahmed Shawqi
--->

    <?php include "layouts/user/header.php"; ?>

</head>

<body class='body ds-bg-primary student-dashboard-page' style="margin-top: 65px">
    <style type="text/css">
        #toast-container>div {
            opacity: 1;
        }
    </style>
    <div id="app">

        <div id="body-overlay"
            onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');">
        </div>
        <form id="logout-form" action="<?=$baseUrl?>/resources/logout" method="POST" class="d-none">
            <input type="hidden" name="_token" value="0PjjLlpM6FmXxBStqiehsWgYBO2MSNJkr7ZTKvPk">
        </form>

        <?php include "layouts/user/aside.php"; ?>

        <main class="p-0 font-2">
            <div class='student-dashboard ds-bg-primary'>
                <div class='student-dashboard-top'>
                    <div class="container">
                        <section class="student-dashboard-welcome" aria-label="Student learning welcome">
                            <div>
                                <span class="student-dashboard-eyebrow">Student learning</span>
                                <h1>Welcome back, <?=student_dashboard_html($user_first_name)?></h1>
                                <p>Continue where you left off and keep your next homework in sight.</p>
                            </div>
                            <img src="<?=$baseUrl?>/<?=student_dashboard_html($user_data['avatar'] ?? 'resources/images/default/avatar.png')?>" alt="<?=student_dashboard_html($user_full_name)?>" class="student-dashboard-avatar">
                        </section>
                    </div>

                    <div class="student-dashboard-nav-wrap">
                        <div class="container p-0">
                            <div class="col-12 row user-menu">
                                <nav class='navbar navbar-expand-lg navbar-light ds-surface-muted'>
                                    <div class="container-fluid p-0">
                                        <div class="col-12 px-0 row d-flex m-0 py-3 py-lg-0 justify-content-between align-items-center d-lg-none">
                                            <div class='navbar-brand navbar-toggler font-2 px-3 col-auto ds-text-secondary'
                                                data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent"
                                                aria-controls="navbarSupportedContent" aria-expanded="false"
                                                aria-label="Toggle navigation">Dashboard</div>
                                            <button class='navbar-toggler d-flex col-auto ds-shadow-sm'
                                                type="button" data-bs-toggle="collapse"
                                                data-bs-target="#navbarSupportedContent"
                                                aria-controls="navbarSupportedContent" aria-expanded="false"
                                                aria-label="Toggle navigation">
                                                <span class="fas fa-bars"></span>
                                            </button>
                                        </div>

                                        <?php include "layouts/user/main-nav.php"; ?>
                                    </div>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="container student-dashboard-shell">
                    <?php if ($live_priority): ?>
                        <section class="student-live-priority" aria-label="Today priority">
                            <div>
                                <span class="student-dashboard-eyebrow">Today’s live session</span>
                                <h2><?=student_dashboard_html($live_priority['course_title'])?></h2>
                                <p><?=student_dashboard_html($live_priority['schedule_title'] ?: 'Live session')?> · <?=student_dashboard_html(mmh_live_display_time($live_priority))?></p>
                                <strong><?=student_dashboard_html($live_priority['_priority_state'] ?? 'Starting soon')?></strong>
                            </div>
                            <a href="<?=student_dashboard_html($live_join_base)?><?=student_dashboard_html($live_priority['occurrence_id'])?>" class="student-dashboard-btn primary"><span class="fas fa-video" aria-hidden="true"></span> <?=student_dashboard_html($live_priority['_join_label'] ?? 'Join Session')?></a>
                        </section>
                    <?php endif; ?>

                    <section class="student-dashboard-overview" aria-label="Quick overview">
                        <article class="student-dashboard-metric">
                            <span class="fas fa-play-circle" aria-hidden="true"></span>
                            <div><strong><?=count($enrolled_courses)?></strong><small>Enrolled courses</small></div>
                        </article>
                        <article class="student-dashboard-metric">
                            <span class="fas fa-tasks" aria-hidden="true"></span>
                            <div><strong><?=$homework_due_count > 0 ? $homework_due_count : 'No data yet'?></strong><small>Homework due</small></div>
                        </article>
                        <article class="student-dashboard-metric">
                            <span class="fas fa-hourglass-half" aria-hidden="true"></span>
                            <div><strong><?=$pending_verification_count > 0 ? $pending_verification_count : 'No data yet'?></strong><small>Pending verification</small></div>
                        </article>
                        <article class="student-dashboard-metric">
                            <span class="fas fa-layer-group" aria-hidden="true"></span>
                            <div><strong><?=$sections_completed > 0 ? $sections_completed : 'No data yet'?></strong><small>Sections completed</small></div>
                        </article>
                    </section>

                    <section class="student-dashboard-grid" aria-label="Learning overview">
                        <article class="student-continue-card">
                            <div class="student-section-heading compact">
                                <span>Continue Learning</span>
                                <h2>Your next step</h2>
                            </div>
                            <?php if ($continue_course): ?>
                                <?php
                                    $continue_title = student_dashboard_html($continue_course['course_title'] ?? 'Course');
                                    $continue_section = trim((string)($continue_course['section_title'] ?? ''));
                                    $continue_lesson = trim((string)($continue_course['item_title'] ?? ''));
                                    $continue_meta = $continue_lesson !== '' ? $continue_lesson : ($continue_section !== '' ? $continue_section : 'Start learning');
                                ?>
                                <div class="student-continue-content">
                                    <img src="<?=$baseUrl?>/<?=student_dashboard_html($continue_course['course_image'] ?? '')?>" alt="<?=$continue_title?>">
                                    <div>
                                        <h3><?=$continue_title?></h3>
                                        <p><?=student_dashboard_html($continue_meta)?></p>
                                        <span class="student-dashboard-muted">Last activity: <?=student_dashboard_relative_time($continue_course['created_at'] ?? '', 'Ready to start')?></span>
                                        <?php $continueHref = 'course/' . student_dashboard_html($continue_course['courseId'] ?? ''); if (!empty($continue_course['item_id'])) { $continueHref .= '?lesson=' . rawurlencode((string) $continue_course['item_id']); } ?>
                                        <a href="<?=$continueHref?>" class="student-dashboard-btn primary"><span class="fas fa-arrow-right" aria-hidden="true"></span> Continue</a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="student-dashboard-empty compact">
                                    <span class="fas fa-info-circle" aria-hidden="true"></span>
                                    <p>You are not enrolled in any course yet.</p>
                                    <a href="courses" class="student-dashboard-btn secondary">Browse courses</a>
                                </div>
                            <?php endif; ?>
                        </article>

                        <aside class="student-upcoming-panel" aria-label="Upcoming work">
                            <div class="student-section-heading compact">
                                <span>Upcoming</span>
                                <h2>Needs attention</h2>
                            </div>
                            <?php if (!empty($upcoming_items)): ?>
                                <div class="student-upcoming-list">
                                    <?php foreach ($upcoming_items as $item): ?>
                                        <?php $assignmentHref = 'course/' . student_dashboard_html($item['courseId']); if (!empty($item['item_id'])) { $assignmentHref .= '?lesson=' . rawurlencode((string) $item['item_id']); } ?>
                                        <a href="<?=$assignmentHref?>" class="student-upcoming-item">
                                            <strong><?=student_dashboard_html($item['assignment_title'])?></strong>
                                            <small><?=student_dashboard_html($item['course_title'])?></small>
                                            <span class="student-upcoming-status <?=strtolower(str_replace(' ', '-', $item['_dashboard_status']))?>"><?=student_dashboard_html($item['_dashboard_status'])?> · <?=student_dashboard_date_label($item['due_date'], 'Awaiting review')?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="student-dashboard-empty compact">
                                    <span class="fas fa-check-circle" aria-hidden="true"></span>
                                    <p>No urgent homework or verification items right now.</p>
                                </div>
                            <?php endif; ?>
                        </aside>
                    </section>

                    <?php if (!empty($this_week_live_sessions)): ?>
                        <section class="student-courses-section mb-4" aria-label="This week live sessions">
                            <div class="student-section-heading"><span>This Week</span><h2>Upcoming live sessions</h2></div>
                            <div class="student-upcoming-list">
                                <?php foreach ($this_week_live_sessions as $session): ?>
                                    <a href="<?=$baseUrl?>/user/live-sessions" class="student-upcoming-item">
                                        <strong><?=student_dashboard_html($session['course_title'])?></strong>
                                        <small><?=student_dashboard_html($session['schedule_title'] ?: 'Live session')?> · <?=student_dashboard_html(mmh_live_display_time($session))?></small>
                                        <span class="student-upcoming-status"><?=student_dashboard_html(ucwords(str_replace('_', ' ', $session['status'])))?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="student-courses-section" aria-label="My courses">
                        <div class="student-section-heading">
                            <span>My Courses</span>
                            <h2>Continue learning from your enrolled courses.</h2>
                        </div>
                        <?php if (!empty($enrolled_courses)): ?>
                            <div class="student-course-grid">
                                <?php foreach ($enrolled_courses as $courses_data): ?>
                                    <?php
                                        $course_link = 'course/' . student_dashboard_html($courses_data['courseId']);
                                        $course_title = student_dashboard_html($courses_data['course_title']);
                                        $category = trim((string)($courses_data['category_title'] ?? ''));
                                        $last_activity = $latest_activity_by_course[(string)$courses_data['course_id']] ?? ($courses_data['purchase_date'] ?? '');
                                        $progress = $section_progress_by_course[(string)$courses_data['course_id']] ?? null;
                                        $progress_label = 'No progress data yet';
                                        $progress_style = '--student-course-progress:0%';
                                        if ($progress && (int)($progress['total_sections'] ?? 0) > 0) {
                                            $percent = round(((int)$progress['completed_sections'] / max(1, (int)$progress['total_sections'])) * 100);
                                            $progress_label = (int)$progress['completed_sections'] . '/' . (int)$progress['total_sections'] . ' sections completed';
                                            $progress_style = '--student-course-progress:' . max(0, min(100, $percent)) . '%';
                                        }
                                    ?>
                                    <article class="student-course-card">
                                        <a href="<?=$course_link?>" class="student-course-image">
                                            <img src="<?=$baseUrl?>/<?=student_dashboard_html($courses_data['course_image'])?>" alt="<?=$course_title?>">
                                        </a>
                                        <div class="student-course-card-body">
                                            <div class="student-course-meta-row">
                                                <span><?=student_dashboard_html($category !== '' ? $category : 'Course')?></span>
                                                <span><?=student_dashboard_html($courses_data['item_count'])?> lessons</span>
                                            </div>
                                            <h3><a href="<?=$course_link?>"><?=$course_title?></a></h3>
                                            <div class="student-course-facts">
                                                <span><i class="fas fa-tasks" aria-hidden="true"></i><?=student_dashboard_html($courses_data['homework_count'])?> homework</span>
                                                <span><i class="fas fa-clock" aria-hidden="true"></i><?=student_dashboard_relative_time($last_activity, 'Ready to start')?></span>
                                            </div>
                                            <div class="student-course-progress" style="<?=$progress_style?>"><span></span></div>
                                            <p class="student-dashboard-muted"><?=$progress_label?></p>
                                            <a href="<?=$course_link?>" class="student-dashboard-btn secondary">Open Course</a>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="student-dashboard-empty">
                                <span class="fas fa-info-circle" aria-hidden="true"></span>
                                <h3>No enrolled courses yet</h3>
                                <p>Browse available courses and start your learning path.</p>
                                <a href="courses" class="student-dashboard-btn primary">Browse courses</a>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </main>

        <?php include "layouts/user/footer.php"; ?>


    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js" />
    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/main-07febffb.js" />
    <script type="module" src="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js"
        data-navigate-track="reload"></script> <!-- Livewire Scripts -->
        <script src="../notification/main.js"></script>

</body>

</html>
