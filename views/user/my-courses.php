<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/LiveSessions.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/AssignmentProgress.php';
require_once 'inc/StudentLearningJourney.php';
require_once 'inc/RecoveryPlan.php';

$pageName = 'mycourses';
$username = (string) ($_SESSION['username'] ?? '');
$userInfo = $username !== '' ? getUserInfo($username) : false;
$userData = is_object($userInfo) ? get_object_vars($userInfo) : [];
$userId = (int) ($userData['user_id'] ?? 0);
$conn = db();

function student_courses_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function student_courses_relative_time($value)
{
    $timestamp = !empty($value) ? strtotime((string) $value) : false;
    if ($timestamp === false) {
        return 'Ready to start';
    }
    $difference = max(0, time() - $timestamp);
    if ($difference < 60) return 'Just now';
    if ($difference < 3600) return floor($difference / 60) . ' min ago';
    if ($difference < 86400) return floor($difference / 3600) . ' hr ago';
    if ($difference < 604800) return floor($difference / 86400) . ' days ago';
    return date('j M Y', $timestamp);
}

function student_courses_table_exists(mysqli $conn, $table)
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

function student_courses_item_link($baseUrl, $courseRouteId, $itemId = '')
{
    $url = rtrim((string) $baseUrl, '/') . '/user/course/' . rawurlencode((string) $courseRouteId);
    if (trim((string) $itemId) !== '') $url .= '?lesson=' . rawurlencode((string) $itemId);
    return $url;
}

function student_courses_local_date(array $session)
{
    $value = trim((string) ($session['scheduled_start_at'] ?? ''));
    if ($value === '') return '';
    try {
        $timezone = mmh_live_timezone($session['timezone'] ?? 'Asia/Riyadh');
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
    } catch (Throwable $exception) {
        return '';
    }
}

$coursesQuery = "
    SELECT courses.id AS courseId, courses.*, categories.category_title,
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
    WHERE course_logs.user_id = '" . (int) $userId . "'
    ORDER BY course_logs.purchase_date DESC, courses.id ASC";

$enrolledCourses = [];
$coursesResult = mysqli_query($conn, $coursesQuery);
if ($coursesResult) {
    while ($course = mysqli_fetch_assoc($coursesResult)) {
        $courseKey = trim((string) ($course['course_id'] ?? ''));
        if ($courseKey !== '' && !isset($enrolledCourses[$courseKey])) {
            $enrolledCourses[$courseKey] = $course;
        }
    }
}
$enrolledCourses = array_values($enrolledCourses);

$latestActivityByCourse = [];
if ($userId > 0 && student_courses_table_exists($conn, 'learning_events')) {
    $activityStmt = $conn->prepare(
        'SELECT le.course_id, MAX(le.created_at) AS last_activity_at
         FROM learning_events AS le
         INNER JOIN course_logs AS cl ON cl.course_id = le.course_id AND cl.user_id = ?
         WHERE le.user_id = ?
         GROUP BY le.course_id'
    );
    if ($activityStmt) {
        $activityStmt->bind_param('ii', $userId, $userId);
        $activityStmt->execute();
        $activityRows = $activityStmt->get_result();
        while ($row = $activityRows->fetch_assoc()) {
            $latestActivityByCourse[(string) $row['course_id']] = $row['last_activity_at'];
        }
        $activityStmt->close();
    }
}

$liveByCourse = [];
if ($userId > 0) {
    foreach (mmh_live_occurrences($conn, '', 0, 45, $userId) as $session) {
        $liveByCourse[(string) ($session['course_id'] ?? '')][] = $session;
    }
}

$courseCards = [];
foreach ($enrolledCourses as $course) {
    $courseId = trim((string) ($course['course_id'] ?? ''));
    if ($courseId === '') continue;

    $journey = mmh_learning_journey_resolve($conn, $userId, $courseId);
    $recoveryPlan = mmh_recovery_plan_resolve($conn, $userId, $courseId);
    $assignmentMap = mmh_assignment_progress_load_course($conn, $userId, $courseId);
    $recoveredItemIds = array_map('strval', is_array($recoveryPlan) ? ($recoveryPlan['covered_item_ids'] ?? []) : []);
    $journeyItems = $journey['items'] ?? [];

    $lessonItems = array_values(array_filter($journeyItems, static fn($item) => ($item['item_kind'] ?? '') === 'lesson'));
    $recordingItems = array_values(array_filter($journeyItems, static fn($item) => ($item['item_kind'] ?? '') === 'recording'));
    $homeworkItems = array_values(array_filter($journeyItems, static fn($item) => ($item['item_kind'] ?? '') === 'homework'));

    $completedLessons = count(array_filter($lessonItems, static fn($item) => !empty($item['is_completed'])));
    $completedHomework = 0;
    $unfinishedHomework = null;
    $overdueHomework = [];
    foreach ($assignmentMap as $assignment) {
        $state = $assignment['_state'] ?? [];
        if (!empty($state['complete'])) $completedHomework++;
        $assignmentItemId = (string) ($assignment['item_id'] ?? ($assignment['_source_item_id'] ?? ''));
        $coveredByPlan = in_array($assignmentItemId, $recoveredItemIds, true);
        if ($unfinishedHomework === null && empty($state['complete']) && !$coveredByPlan) $unfinishedHomework = $assignment;
        if (($state['state'] ?? '') === 'overdue' || !empty($state['overdue'])) if (!$coveredByPlan) $overdueHomework[] = $assignment;
    }
    if (!$assignmentMap && $homeworkItems) {
        $completedHomework = count(array_filter($homeworkItems, static fn($item) => !empty($item['is_completed'])));
        foreach ($homeworkItems as $homework) {
            if (empty($homework['is_completed']) && !mmh_recovery_plan_covers_item($recoveryPlan, (string) ($homework['item_id'] ?? ''), (string) ($homework['section_id'] ?? ''))) {
                $unfinishedHomework = $homework;
                break;
            }
        }
    }

    $nextLearning = mmh_learning_journey_resume($journey);
    $nextLesson = null;
    foreach ($journeyItems as $item) {
        if (in_array(($item['item_kind'] ?? ''), ['lesson', 'recording'], true) && empty($item['is_completed'])) {
            $nextLesson = $item;
            break;
        }
    }
    $nextSession = ($liveByCourse[$courseId] ?? [])[0] ?? null;
    $lastActivity = $latestActivityByCourse[$courseId] ?? ($course['purchase_date'] ?? '');
    $courseImage = trim((string) ($course['course_image'] ?? ''));
    $courseImage = $courseImage !== '' ? $courseImage : 'resources/images/default/wide-logo.png';
    $courseRouteId = (string) ($course['courseId'] ?? $courseId);
    $currentSection = trim((string) (($nextLearning['section_title'] ?? '') ?: ($nextLesson['section_title'] ?? '')));
    if ($currentSection === '') $currentSection = !empty($journey['total']) && (int) ($journey['completed'] ?? 0) >= (int) $journey['total'] ? 'Course complete' : 'Not started';
    $progressPercent = $journey['percentage'] ?? null;
    $unfinishedHomeworkId = $unfinishedHomework['item_id'] ?? ($unfinishedHomework['_source_item_id'] ?? '');
    $liveToday = $nextSession && student_courses_local_date($nextSession) === (new DateTimeImmutable('now', mmh_live_timezone($nextSession['timezone'] ?? 'Asia/Riyadh')))->format('Y-m-d');
    $recordingAvailable = (bool) array_filter($recordingItems, static fn($item) => empty($item['is_completed']) && !mmh_recovery_plan_covers_item($recoveryPlan, (string) ($item['item_id'] ?? ''), (string) ($item['section_id'] ?? '')));
    $courseCards[] = [
        'course' => $course,
        'course_id' => $courseId,
        'course_route_id' => $courseRouteId,
        'course_image' => $courseImage,
        'journey' => $journey,
        'recovery_plan' => $recoveryPlan,
        'assignment_map' => $assignmentMap,
        'lesson_total' => count($lessonItems),
        'lesson_completed' => $completedLessons,
        'homework_total' => $assignmentMap ? count($assignmentMap) : count($homeworkItems),
        'homework_completed' => $completedHomework,
        'next_learning' => $nextLearning,
        'next_lesson' => $nextLesson,
        'next_session' => $nextSession,
        'last_activity' => $lastActivity,
        'current_section' => $currentSection,
        'unfinished_homework' => $unfinishedHomework,
        'unfinished_homework_id' => $unfinishedHomeworkId,
        'overdue_homework' => $overdueHomework,
        'live_today' => $liveToday,
        'recording_available' => $recordingAvailable,
        'progress_percent' => $progressPercent === null ? null : max(0, min(100, (int) $progressPercent)),
    ];
}
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Courses | <?= student_courses_html($site_name); ?></title>
    <?php include 'layouts/user/header.php'; ?>
</head>
<body class="body ds-bg-primary student-dashboard-page student-my-courses-page" style="margin-top: 65px">
<div id="app">
    <div id="body-overlay" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></div>
    <?php include 'layouts/user/aside.php'; ?>
    <main class="student-my-courses-main font-2">
        <header class="student-courses-page-header">
            <div class="container">
                <span class="student-dashboard-eyebrow">Study dashboard</span>
                <h1>My Courses</h1>
            </div>
            <div class="student-dashboard-nav-wrap">
                <div class="container p-0"><div class="col-12 row user-menu"><nav class="navbar navbar-expand-lg navbar-light ds-surface-muted"><div class="container-fluid p-0"><?php include 'layouts/user/main-nav.php'; ?></div></nav></div></div>
            </div>
        </header>
        <section class="container student-courses-shell" aria-label="Enrolled courses">
            <?php if (!$courseCards): ?>
                <div class="student-dashboard-empty student-courses-empty">
                    <span class="fas fa-book-open" aria-hidden="true"></span>
                    <h2>No enrolled courses yet</h2>
                    <p>Once you enroll in a course, it will appear here.</p>
                    <a href="<?= student_courses_html(rtrim((string) $baseUrl, '/') . '/user/courses'); ?>" class="student-dashboard-btn primary">Browse courses</a>
                </div>
            <?php else: ?>
                <div class="student-course-grid student-course-dashboard-grid">
                    <?php foreach ($courseCards as $card): ?>
                        <?php
                        $course = $card['course'];
                        $courseTitle = trim((string) ($course['course_title'] ?? 'Course'));
                        $courseLink = student_courses_item_link($baseUrl, $card['course_route_id']);
                        $nextLearning = $card['next_learning'];
                        $continueLink = student_courses_item_link($baseUrl, $card['course_route_id'], $nextLearning['item_id'] ?? '');
                        $homeworkLink = student_courses_item_link($baseUrl, $card['course_route_id'], $card['unfinished_homework_id']);
                        $recoveryPlan = $card['recovery_plan'];
                        $recoveryTask = null;
                        foreach (($recoveryPlan['items'] ?? []) as $candidateTask) {
                            if (empty($candidateTask['is_completed']) && empty($candidateTask['is_locked'])) { $recoveryTask = $candidateTask; break; }
                        }
                        $progressText = $card['progress_percent'] === null ? 'No progress data yet' : $card['progress_percent'] . '% complete';
                        $nextLessonLabel = $card['next_lesson']['item_title'] ?? ($card['lesson_total'] > 0 ? 'All available lessons complete' : 'No lessons available');
                        ?>
                        <article class="student-course-card student-course-dashboard-card">
                            <a href="<?= student_courses_html($courseLink); ?>" class="student-course-image">
                                <img src="<?= student_courses_html(rtrim((string) $baseUrl, '/') . '/' . ltrim($card['course_image'], '/')); ?>" alt="<?= student_courses_html($courseTitle); ?>">
                            </a>
                            <div class="student-course-card-body">
                                <div class="student-course-card-heading">
                                    <div>
                                        <?php if (trim((string) ($course['category_title'] ?? '')) !== ''): ?><span class="student-course-category"><?= student_courses_html($course['category_title']); ?></span><?php endif; ?>
                                        <h2><a href="<?= student_courses_html($courseLink); ?>"><?= student_courses_html($courseTitle); ?></a></h2>
                                    </div>
                                </div>
                                <?php if ($card['overdue_homework'] || $card['live_today'] || $card['recording_available']): ?>
                                    <div class="student-course-statuses" aria-label="Course alerts">
                                        <?php if ($card['overdue_homework']): ?><span class="student-course-badge is-warning"><span class="fas fa-exclamation-circle" aria-hidden="true"></span> Overdue homework</span><?php endif; ?>
                                        <?php if ($card['live_today']): ?><span class="student-course-badge is-live"><span class="fas fa-video" aria-hidden="true"></span> Live Today</span><?php endif; ?>
                                        <?php if ($card['recording_available']): ?><span class="student-course-badge is-recording"><span class="fas fa-play-circle" aria-hidden="true"></span> Recording Available</span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($recoveryPlan): ?>
                                    <section class="student-recovery-plan-card" aria-label="Recovery Plan">
                                        <?php if (($recoveryPlan['status'] ?? '') === 'completed'): ?>
                                            <div class="student-recovery-plan-complete"><span class="fas fa-check-circle" aria-hidden="true"></span><div><strong>Recovery Plan complete</strong><small>Recovered through Study Plan</small></div></div>
                                        <?php else: ?>
                                            <div class="student-recovery-plan-heading"><div><span class="fas fa-route" aria-hidden="true"></span><strong>Recovery Plan</strong></div><span><?= (int) ($recoveryPlan['completed'] ?? 0) ?> / <?= (int) ($recoveryPlan['total'] ?? 0) ?> tasks</span></div>
                                            <?php if ($recoveryTask): ?><div class="student-recovery-plan-next"><div><strong><?= student_courses_html($recoveryTask['item_title'] ?? 'Next recovery task'); ?></strong><small><?= student_courses_html($recoveryTask['teacher_note'] ?? 'Priority task') ?></small></div><a class="student-dashboard-btn primary" href="<?= student_courses_html(mmh_recovery_plan_workspace_url(rtrim((string) $baseUrl, '/'), (string) $card['course_id'], (int) ($recoveryPlan['id'] ?? 0), (int) ($recoveryTask['id'] ?? 0))); ?>">Continue</a></div><?php endif; ?>
                                        <?php endif; ?>
                                    </section>
                                <?php endif; ?>
                                <div class="student-course-progress-heading"><span>Overall progress</span><strong><?= student_courses_html($progressText); ?></strong></div>
                                <div class="student-course-progress" role="progressbar" aria-label="Overall course progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) ($card['progress_percent'] ?? 0); ?>" style="--student-course-progress:<?= (int) ($card['progress_percent'] ?? 0); ?>%"><span></span></div>
                                <div class="student-course-stat-grid">
                                    <div><span>Lessons</span><strong><?= (int) $card['lesson_completed']; ?> / <?= (int) $card['lesson_total']; ?></strong></div>
                                    <div><span>Homework</span><strong><?= (int) $card['homework_completed']; ?> / <?= (int) $card['homework_total']; ?></strong></div>
                                </div>
                                <dl class="student-course-context">
                                    <div><dt>Next lesson</dt><dd><?= student_courses_html($nextLessonLabel); ?></dd></div>
                                    <div><dt>Next live session</dt><dd><?= $card['next_session'] ? student_courses_html(mmh_live_display_time($card['next_session'])) : 'No upcoming session'; ?></dd></div>
                                    <div><dt>Current section</dt><dd><?= student_courses_html($card['current_section']); ?></dd></div>
                                    <div><dt>Last activity</dt><dd><?= student_courses_html(student_courses_relative_time($card['last_activity'])); ?></dd></div>
                                </dl>
                                <?php if ($card['overdue_homework']): ?><div class="student-course-warning"><span class="fas fa-exclamation-triangle" aria-hidden="true"></span> <?= count($card['overdue_homework']) === 1 ? '1 homework task is overdue' : count($card['overdue_homework']) . ' homework tasks are overdue'; ?></div><?php endif; ?>
                                <div class="student-course-actions">
                                    <a href="<?= student_courses_html($continueLink); ?>" class="student-dashboard-btn primary"><span class="fas fa-play" aria-hidden="true"></span> Continue Learning</a>
                                    <?php if ($card['unfinished_homework']): ?><a href="<?= student_courses_html($homeworkLink); ?>" class="student-dashboard-btn homework-action"><span class="fas fa-tasks" aria-hidden="true"></span> Continue Homework</a><?php endif; ?>
                                </div>
                                <nav class="student-course-secondary-actions" aria-label="Course shortcuts">
                                    <a href="<?= student_courses_html(rtrim((string) $baseUrl, '/') . '/user/assignments'); ?>"><span class="fas fa-tasks" aria-hidden="true"></span> Assignments</a>
                                    <a href="<?= student_courses_html(rtrim((string) $baseUrl, '/') . '/user/live-sessions'); ?>"><span class="fas fa-video" aria-hidden="true"></span> Live Sessions</a>
                                    <a href="<?= student_courses_html(rtrim((string) $baseUrl, '/') . '/user/analytics'); ?>"><span class="fas fa-chart-line" aria-hidden="true"></span> My Progress</a>
                                </nav>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
