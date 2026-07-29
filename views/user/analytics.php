<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/StudentAnalytics.php';

$pageName = 'analytics';
$username = $_SESSION['username'];
$userId = (int) getUserInfo($username)->user_id;
$user_id = $userId; // Existing shared student layout expects this variable name.
$conn = db();

function student_analytics_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function student_analytics_number($value, $suffix = '', $unavailable = 'Not available')
{
    if ($value === null || $value === '') {
        return $unavailable;
    }
    $number = round((float) $value, 2);
    $formatted = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    return $formatted . $suffix;
}

function student_analytics_progress_style($value)
{
    if ($value === null || $value === '') {
        return '';
    }
    $percent = max(0, min(100, (float) $value));
    return ' style="--student-analytics-progress:' . $percent . '%"';
}

function student_analytics_datetime($value)
{
    if (empty($value) || strtotime((string) $value) === false) {
        return 'No activity yet';
    }
    return date('j M Y, g:i A', strtotime((string) $value));
}

function student_analytics_relative_datetime($value, $unavailable = 'No scored activity yet')
{
    $timestamp = !empty($value) ? strtotime((string) $value) : false;
    if ($timestamp === false) {
        return $unavailable;
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

function student_analytics_topic_badge_class($classification)
{
    $classes = [
        'strong' => 'text-bg-success',
        'developing' => 'text-bg-secondary',
        'weak' => 'text-bg-warning',
        'insufficient_data' => 'text-bg-light',
    ];
    return $classes[$classification] ?? 'text-bg-light';
}

function student_analytics_progress_attributes($value, $label)
{
    $label = student_analytics_html($label);
    if ($value === null || $value === '') {
        return "role=\"progressbar\" aria-label=\"{$label}\" aria-valuetext=\"Insufficient data\"";
    }
    $percent = max(0, min(100, (float) $value));
    return "role=\"progressbar\" aria-label=\"{$label}\" aria-valuemin=\"0\" aria-valuemax=\"100\" aria-valuenow=\"{$percent}\"";
}

function student_analytics_readable_rule($rule)
{
    $labels = [
        'manual_completion' => 'Mark section complete',
        'watching_recordings' => 'Watch all recordings',
        'viewing_notes' => 'View all notes',
        'homework_submitted' => 'Submit homework',
        'homework_approved' => 'Homework approval',
        'all_lessons_completed' => 'Complete all lessons',
    ];
    return $labels[$rule] ?? ucwords(str_replace('_', ' ', (string) $rule));
}

// This selector query establishes enrollment only. All analytics values below
// come exclusively from getStudentCourseOverview(), the B3A source of truth.
$enrolledCourses = [];
$courseStmt = $conn->prepare(
    'SELECT c.course_id, c.course_title, MAX(l.purchase_date) AS enrolled_at
     FROM course_logs AS l
     INNER JOIN courses AS c ON c.course_id = l.course_id
     WHERE l.user_id = ?
     GROUP BY c.id, c.course_id, c.course_title
     ORDER BY enrolled_at DESC, c.id ASC'
);
if ($courseStmt) {
    $courseStmt->bind_param('i', $userId);
    $courseStmt->execute();
    $enrolledCourses = $courseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $courseStmt->close();
}

$enrolledById = [];
foreach ($enrolledCourses as $course) {
    $enrolledById[(string) $course['course_id']] = $course;
}

$requestedCourseId = isset($_GET['course']) ? trim((string) $_GET['course']) : '';
$selectedCourseId = isset($enrolledById[$requestedCourseId])
    ? $requestedCourseId
    : (string) ($enrolledCourses[0]['course_id'] ?? '');
$selectedCourse = $selectedCourseId !== '' ? $enrolledById[$selectedCourseId] : null;
$overview = $selectedCourse ? getStudentCourseOverview($conn, $userId, $selectedCourseId, ['include_recommendation_candidates' => false]) : null;
$assignmentProgress = $selectedCourse ? mmh_assignment_progress_load_course($conn, $userId, $selectedCourseId) : [];
$assignmentAttention = mmh_assignment_progress_attention($assignmentProgress);

$homework = $overview['homework'] ?? [];
$activity = $overview['activity'] ?? [];
$topics = $overview['topics']['primary_topics'] ?? [];
$sectionProgress = $overview['section_progress'] ?? [];
$progressMetrics = $overview['progress_metrics'] ?? [];
$strongTopics = array_values(array_filter($topics, function ($topic) {
    return ($topic['classification'] ?? '') === 'strong';
}));
$weakTopics = array_values(array_filter($topics, function ($topic) {
    return ($topic['classification'] ?? '') === 'weak';
}));
$hasSufficientTopicEvidence = !empty($strongTopics) || !empty($weakTopics) || !empty(array_filter($topics, function ($topic) {
    return ($topic['classification'] ?? '') === 'developing';
}));
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Progress | <?= student_analytics_html($site_name); ?></title>
    <?php include 'layouts/user/header.php'; ?>
</head>
<body class="body ds-bg-primary student-analytics-page" style="margin-top: 65px">
    <div id="app">
        <div id="body-overlay" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></div>
        <?php include 'layouts/user/aside.php'; ?>

        <main class="font-2">
            <div class="student-analytics-navigation border-lg-top">
                <div class="container px-2">
                    <nav class="navbar navbar-expand-lg navbar-light ds-surface-muted">
                        <div class="container-fluid p-0">
                            <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                                <span class="fas fa-bars"></span>
                            </button>
                            <?php include 'layouts/user/main-nav.php'; ?>
                        </div>
                    </nav>
                </div>
            </div>

            <section class="student-analytics-shell">
                <div class="container px-3 px-md-4">
                    <header class="student-analytics-header">
                        <div>
                            <p class="student-analytics-eyebrow"><span class="fas fa-chart-line" aria-hidden="true"></span> Learning analytics</p>
                            <h1>My Progress</h1>
                            <p>Review your verified progress, homework habits, and course activity.</p>
                        </div>

                        <?php if ($enrolledCourses): ?>
                            <form method="get" action="<?= student_analytics_html($baseUrl); ?>/user/analytics" class="student-analytics-course-selector">
                                <label for="analytics-course">Course</label>
                                <select id="analytics-course" name="course" class="form-select" aria-describedby="analytics-course-help" onchange="this.form.submit()">
                                    <?php foreach ($enrolledCourses as $course): ?>
                                        <option value="<?= student_analytics_html($course['course_id']); ?>" <?= (string) $course['course_id'] === $selectedCourseId ? 'selected' : ''; ?>>
                                            <?= student_analytics_html($course['course_title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span id="analytics-course-help" class="visually-hidden">Choose an enrolled course to view its progress.</span>
                                <noscript><button class="btn btn-primary btn-sm mt-2" type="submit">View progress</button></noscript>
                            </form>
                        <?php endif; ?>
                    </header>

                    <?php if (!$overview): ?>
                        <section class="student-analytics-empty student-analytics-empty-card card" aria-live="polite">
                            <span class="fas fa-chart-line" aria-hidden="true"></span>
                            <h2>No course analytics yet</h2>
                            <p>Enroll in a course to begin tracking your learning progress.</p>
                            <a class="btn btn-primary" href="<?= student_analytics_html($baseUrl); ?>/user/courses">Browse courses</a>
                        </section>
                    <?php else: ?>
                        <section class="student-analytics-overview" aria-label="<?= student_analytics_html($selectedCourse['course_title']); ?> overview">
                            <div class="student-analytics-course-name">
                                <span class="fas fa-book-open" aria-hidden="true"></span>
                                <span><?= student_analytics_html($selectedCourse['course_title']); ?></span>
                            </div>
                            <div class="student-analytics-metric-grid">
                                <article class="student-analytics-metric-card">
                                    <i class="student-analytics-metric-icon secondary student-fa fas fa-layer-group" aria-hidden="true"></i>
                                    <span class="student-analytics-metric-label">Section Progress</span>
                                    <strong><?= student_analytics_number($progressMetrics['section_completion']['percent'] ?? null, '%'); ?></strong>
                                    <small><?= (int) ($progressMetrics['section_completion']['completed'] ?? 0); ?> of <?= (int) ($progressMetrics['section_completion']['total'] ?? 0); ?> sections complete</small>
                                </article>
                                <article class="student-analytics-metric-card">
                                    <i class="student-analytics-metric-icon primary student-fa fas fa-tasks" aria-hidden="true"></i>
                                    <span class="student-analytics-metric-label">Homework Submitted</span>
                                    <strong><?= student_analytics_number($homework['submission_rate'] ?? null, '%'); ?></strong>
                                    <small><?= (int) ($homework['total_submitted'] ?? 0); ?> of <?= (int) ($homework['total_assigned'] ?? 0); ?> assigned</small>
                                </article>
                                <article class="student-analytics-metric-card">
                                    <i class="student-analytics-metric-icon accent student-fa fas fa-star" aria-hidden="true"></i>
                                    <span class="student-analytics-metric-label">Average Valid Score</span>
                                    <strong><?= student_analytics_number($homework['average_normalized_score'] ?? null, '%', 'Insufficient data'); ?></strong>
                                    <small><?= (int) ($homework['valid_scored_homework_count'] ?? 0); ?> valid scored homework</small>
                                </article>
                                <article class="student-analytics-metric-card">
                                    <i class="student-analytics-metric-icon secondary student-fa fas fa-clock" aria-hidden="true"></i>
                                    <span class="student-analytics-metric-label">On-Time Submission</span>
                                    <strong><?= student_analytics_number($homework['on_time_rate'] ?? null, '%'); ?></strong>
                                    <small><?= (int) ($homework['total_on_time'] ?? 0); ?> on time</small>
                                </article>
                                <article class="student-analytics-metric-card">
                                    <i class="student-analytics-metric-icon primary student-fa fas fa-calendar-check" aria-hidden="true"></i>
                                    <span class="student-analytics-metric-label">Active Days</span>
                                    <strong><?= (int) ($activity['active_course_days'] ?? 0); ?></strong>
                                    <small>Recorded in this course</small>
                                </article>
                                <article class="student-analytics-metric-card">
                                    <i class="student-analytics-metric-icon accent student-fa fas fa-fire" aria-hidden="true"></i>
                                    <span class="student-analytics-metric-label">Current Streak</span>
                                    <strong><?= (int) ($activity['current_activity_streak_days'] ?? 0); ?> days</strong>
                                    <small>Longest: <?= (int) ($activity['longest_activity_streak_days'] ?? 0); ?> days</small>
                                </article>
                            </div>
                        </section>

                        <section class="student-analytics-panel student-analytics-section-panel" aria-label="Required assignment work">
                            <div class="student-analytics-panel-header">
                                <div><p class="student-analytics-eyebrow">Learning journey</p><h2>Required Work</h2></div>
                                <a class="student-analytics-last-activity" href="<?= student_analytics_html($baseUrl); ?>/user/assignments">View assignments</a>
                            </div>
                            <?php if (empty($assignmentAttention)): ?>
                                <div class="student-analytics-inline-empty card border-0" role="status">
                                    <span class="fas fa-clipboard-check" aria-hidden="true"></span>
                                    <div><strong>No required assignment is blocking your progress.</strong><p>Optional work remains available in Assignments.</p></div>
                                </div>
                            <?php else: ?>
                                <div class="student-analytics-section-list">
                                    <?php foreach ($assignmentAttention as $assignment): ?>
                                        <?php
                                        $assignmentState = $assignment['_state'] ?? [];
                                        $assignmentItemId = trim((string) ($assignment['item_id'] ?? ''));
                                        $assignmentLink = rtrim((string) $baseUrl, '/') . '/user/course/' . rawurlencode((string) $assignment['course_id']);
                                        if ($assignmentItemId !== '') {
                                            $assignmentLink .= '?lesson=' . rawurlencode($assignmentItemId);
                                        }
                                        ?>
                                        <article class="student-analytics-section-row">
                                            <span class="student-analytics-section-icon <?= student_analytics_html(($assignmentState['state'] ?? '') === 'approved' ? 'completed' : 'available'); ?>"><span class="fas <?= in_array(($assignmentState['state'] ?? ''), ['overdue', 'needs_revision'], true) ? 'fa-exclamation-circle' : 'fa-clipboard-list'; ?>" aria-hidden="true"></span></span>
                                            <div>
                                                <strong><?= student_analytics_html($assignment['assignment_title']); ?></strong>
                                                <p><?= student_analytics_html(mmh_assignment_progress_requirement_label($assignment['completion_requirement'] ?? 'optional')); ?> · <?= student_analytics_html($assignmentState['completion_label'] ?? 'Submission required'); ?></p>
                                                <small><?= student_analytics_html($assignmentState['reason'] ?? ''); ?><?= !empty($assignment['due_date']) ? ' Due: ' . student_analytics_html(student_analytics_datetime($assignment['due_date'])) : ''; ?></small>
                                            </div>
                                            <a class="badge student-analytics-section-state available text-bg-warning" href="<?= student_analytics_html($assignmentLink); ?>"><?= student_analytics_html($assignmentState['label'] ?? 'Not started'); ?></a>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="student-analytics-grid student-analytics-grid-two">
                            <article class="student-analytics-panel">
                                <div class="student-analytics-panel-header">
                                    <div>
                                        <p class="student-analytics-eyebrow">Homework</p>
                                        <h2>Homework Summary</h2>
                                    </div>
                                    <span class="student-analytics-confidence <?= student_analytics_html($homework['confidence']['level'] ?? 'none'); ?>">
                                        <?= student_analytics_html(ucfirst($homework['confidence']['level'] ?? 'none')); ?> confidence
                                    </span>
                                </div>
                                <?php if ((int) ($homework['total_assigned'] ?? 0) === 0): ?>
                                    <div class="student-analytics-inline-empty card border-0" role="status">
                                        <span class="fas fa-clipboard-check" aria-hidden="true"></span>
                                        <div><strong>No completed homework yet.</strong><p>Homework statistics will appear after work is assigned and submitted.</p></div>
                                    </div>
                                <?php else: ?>
                                    <div class="row g-2 student-analytics-homework-cards" aria-label="Homework status summary">
                                        <div class="col-6 col-lg-3"><article class="card student-analytics-homework-card submitted"><span class="fas fa-check-circle" aria-hidden="true"></span><strong><?= (int) $homework['total_submitted']; ?></strong><span>Submitted</span></article></div>
                                        <div class="col-6 col-lg-3"><article class="card student-analytics-homework-card pending"><span class="fas fa-hourglass-half" aria-hidden="true"></span><strong><?= (int) $homework['pending_verification_count']; ?></strong><span>Pending</span></article></div>
                                        <div class="col-6 col-lg-3"><article class="card student-analytics-homework-card overdue"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><strong><?= (int) $homework['overdue_homework_count']; ?></strong><span>Overdue</span></article></div>
                                        <div class="col-6 col-lg-3"><article class="card student-analytics-homework-card rejected"><span class="fas fa-times-circle" aria-hidden="true"></span><strong><?= (int) $homework['rejected_self_score_count']; ?></strong><span>Rejected</span></article></div>
                                    </div>
                                    <dl class="student-analytics-summary-list">
                                        <div><dt>Assigned</dt><dd><?= (int) $homework['total_assigned']; ?></dd></div>
                                        <div><dt>Missing</dt><dd><?= (int) $homework['total_missing']; ?></dd></div>
                                        <div><dt>Late</dt><dd><?= (int) $homework['total_late']; ?></dd></div>
                                        <div><dt>On time</dt><dd><?= (int) $homework['total_on_time']; ?></dd></div>
                                        <div><dt>Upcoming</dt><dd><?= (int) $homework['upcoming_homework_count']; ?></dd></div>
                                        <div><dt>Passing rate</dt><dd><?= student_analytics_number($homework['passing_rate'] ?? null, '%'); ?></dd></div>
                                        <div><dt>Weighted average</dt><dd><?= student_analytics_number($homework['weighted_average_score'] ?? null, '%', 'Insufficient data'); ?></dd></div>
                                    </dl>
                                <?php endif; ?>
                            </article>

                            <article class="student-analytics-panel">
                                <div class="student-analytics-panel-header">
                                    <div>
                                        <p class="student-analytics-eyebrow">Activity</p>
                                        <h2>Learning Activity</h2>
                                    </div>
                                    <span class="student-analytics-last-activity">Last activity: <?= student_analytics_html(student_analytics_datetime($activity['last_activity_at'] ?? null)); ?></span>
                                </div>
                                <?php $events = $activity['event_counts'] ?? []; ?>
                                <div class="row g-2 student-analytics-availability-grid" aria-label="Available learning activity metrics">
                                    <div class="col-6 col-lg"><article class="card student-analytics-availability-card"><span>Active days</span><strong><?= (int) ($activity['active_course_days'] ?? 0); ?></strong></article></div>
                                    <div class="col-6 col-lg"><article class="card student-analytics-availability-card unavailable"><span>Study sessions</span><strong>Insufficient data</strong></article></div>
                                    <div class="col-6 col-lg"><article class="card student-analytics-availability-card unavailable"><span>Study time</span><strong>Not available</strong></article></div>
                                    <div class="col-6 col-lg"><article class="card student-analytics-availability-card unavailable"><span>Questions answered</span><strong>Not available</strong></article></div>
                                    <div class="col-12 col-lg"><article class="card student-analytics-availability-card unavailable"><span>Average questions / session</span><strong>Not available</strong></article></div>
                                </div>
                                <dl class="student-analytics-summary-list student-analytics-activity-list">
                                    <div><dt>Course opens</dt><dd><?= (int) ($events['course_opened'] ?? 0); ?></dd></div>
                                    <div><dt>Section opens</dt><dd><?= (int) ($events['section_opened'] ?? 0); ?></dd></div>
                                    <div><dt>Sections completed</dt><dd><?= (int) ($events['section_completed'] ?? 0); ?></dd></div>
                                    <div><dt>Notes opened</dt><dd><?= (int) ($events['notes_opened'] ?? 0); ?></dd></div>
                                    <div><dt>Notes downloaded</dt><dd><?= (int) ($events['notes_downloaded'] ?? 0); ?></dd></div>
                                    <div><dt>Recordings started</dt><dd><?= (int) ($events['recording_started'] ?? 0); ?></dd></div>
                                    <div><dt>Homework opened</dt><dd><?= (int) ($events['homework_opened'] ?? 0); ?></dd></div>
                                    <div><dt>Model answers viewed</dt><dd><?= (int) ($events['model_answer_viewed'] ?? 0); ?></dd></div>
                                </dl>
                                <p class="student-analytics-data-note"><span class="fas fa-info-circle"></span> Study sessions, duration, and question totals are intentionally unavailable until the LMS records reliable data for them.</p>
                            </article>
                        </section>

                        <section class="student-analytics-panel student-analytics-topic-panel">
                            <div class="student-analytics-panel-header">
                                <div>
                                    <p class="student-analytics-eyebrow">Classified homework</p>
                                    <h2>Topic Performance</h2>
                                </div>
                                <p class="student-analytics-helper">Analytics improve as you complete more classified homework and verified scores become available.</p>
                            </div>
                            <?php if (!$topics): ?>
                                <div class="student-analytics-inline-empty card border-0" role="status">
                                    <span class="fas fa-chart-bar" aria-hidden="true"></span>
                                    <div><strong>Topic insights are not ready yet.</strong><p>Complete more classified homework to unlock analytics for each topic.</p></div>
                                </div>
                            <?php else: ?>
                                <div class="student-analytics-topic-list">
                                    <?php foreach ($topics as $topic): ?>
                                        <?php
                                        $classification = $topic['classification'] ?? 'insufficient_data';
                                        $topicScore = $topic['average_normalized_score'] ?? null;
                                        $topicLastActivity = $topic['most_recent_score_at'] ?? null;
                                        ?>
                                        <article class="student-analytics-topic-row">
                                            <div class="student-analytics-topic-heading">
                                                <div><strong><?= student_analytics_html($topic['title']); ?></strong><small>Last scored activity: <?= student_analytics_html(student_analytics_relative_datetime($topicLastActivity)); ?></small></div>
                                                <span class="badge student-analytics-topic-badge <?= student_analytics_html($classification); ?> <?= student_analytics_html(student_analytics_topic_badge_class($classification)); ?>"><?= student_analytics_html(ucwords(str_replace('_', ' ', $classification))); ?></span>
                                            </div>
                                            <div class="student-analytics-topic-score">
                                                <div class="progress student-analytics-progress-track" <?= student_analytics_progress_attributes($topicScore, $topic['title'] . ' score'); ?><?= student_analytics_progress_style($topicScore); ?>><div class="progress-bar"><span class="visually-hidden"><?= student_analytics_number($topicScore, '%', 'Insufficient data'); ?></span></div></div>
                                                <strong><?= student_analytics_number($topicScore, '%', 'Insufficient data'); ?></strong>
                                            </div>
                                            <div class="student-analytics-topic-meta">
                                                <span><?= (int) ($topic['submitted_count'] ?? 0); ?> submitted / <?= (int) ($topic['assigned_count'] ?? 0); ?> assigned</span>
                                                <span><?= (int) ($topic['valid_scored_count'] ?? 0); ?> scored attempt<?= (int) ($topic['valid_scored_count'] ?? 0) === 1 ? '' : 's'; ?></span>
                                                <span><?= student_analytics_html(ucfirst($topic['confidence']['level'] ?? 'none')); ?> confidence</span>
                                                <span><?= student_analytics_html(ucfirst($topic['trend'] ?? 'insufficient_data')); ?><?= ($topic['trend_change'] ?? null) !== null ? ' (' . student_analytics_number($topic['trend_change'], '%') . ')' : ''; ?></span>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="student-analytics-grid student-analytics-grid-two">
                            <article class="student-analytics-panel student-analytics-focus-panel strong">
                                <div class="student-analytics-panel-header"><div><p class="student-analytics-eyebrow">Verified evidence</p><h2>Strong Topics</h2></div><span class="fas fa-star"></span></div>
                                <?php if ($strongTopics): ?>
                                    <ul class="student-analytics-topic-focus-list">
                                        <?php foreach ($strongTopics as $topic): ?><li><span class="badge text-bg-success"><span class="fas fa-check" aria-hidden="true"></span> <?= student_analytics_html($topic['title']); ?></span><span class="student-analytics-focus-score"><?= student_analytics_number($topic['average_normalized_score'], '%'); ?></span></li><?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="student-analytics-empty-copy">Complete more classified homework to unlock Topic insights.</p>
                                <?php endif; ?>
                            </article>
                            <article class="student-analytics-panel student-analytics-focus-panel attention">
                                <div class="student-analytics-panel-header"><div><p class="student-analytics-eyebrow">Verified evidence</p><h2>Needs Attention</h2></div><span class="fas fa-lightbulb"></span></div>
                                <?php if ($weakTopics): ?>
                                    <ul class="student-analytics-topic-focus-list">
                                        <?php foreach ($weakTopics as $topic): ?><li><span class="badge text-bg-warning"><span class="fas fa-lightbulb" aria-hidden="true"></span> <?= student_analytics_html($topic['title']); ?></span><span class="student-analytics-focus-score"><?= student_analytics_number($topic['average_normalized_score'], '%'); ?></span></li><?php endforeach; ?>
                                    </ul>
                                <?php elseif (!$hasSufficientTopicEvidence): ?>
                                    <p class="student-analytics-empty-copy">Complete more classified homework to unlock Topic insights.</p>
                                <?php else: ?>
                                    <p class="student-analytics-empty-copy">No topics currently need attention based on verified evidence.</p>
                                <?php endif; ?>
                            </article>
                        </section>

                        <section class="student-analytics-panel student-analytics-section-panel">
                            <div class="student-analytics-panel-header">
                                <div><p class="student-analytics-eyebrow">Learning path</p><h2>Section Progress</h2></div>
                                <span class="student-analytics-last-activity"><?= !empty($sectionProgress['learning_rules_enabled']) ? 'Learning rules active' : 'Learning rules not required'; ?></span>
                            </div>
                            <?php if (empty($sectionProgress['sections'])): ?>
                                <div class="student-analytics-inline-empty card border-0" role="status">
                                    <span class="fas fa-layer-group" aria-hidden="true"></span>
                                    <div><strong>This course does not yet contain enough activity to generate section statistics.</strong><p>Published sections will appear here as the course is organized.</p></div>
                                </div>
                            <?php else: ?>
                                <div class="student-analytics-section-list">
                                    <?php foreach ($sectionProgress['sections'] as $section): ?>
                                        <?php
                                        $sectionCompleted = !empty($section['completed']);
                                        $sectionLocked = !empty($section['locked']);
                                        $sectionPercent = $sectionCompleted ? 100 : 0;
                                        $sectionState = $sectionLocked ? 'locked' : ($sectionCompleted ? 'completed' : 'available');
                                        ?>
                                        <article class="student-analytics-section-row <?= $sectionLocked ? 'is-locked' : ''; ?>">
                                            <span class="student-analytics-section-icon <?= $sectionState; ?>"><span class="fas <?= $sectionLocked ? 'fa-lock' : ($sectionCompleted ? 'fa-check' : 'fa-play'); ?>" aria-hidden="true"></span></span>
                                            <div>
                                                <strong><?= student_analytics_html($section['title']); ?></strong>
                                                <p><?= student_analytics_html(ucwords(str_replace('_', ' ', $section['section_type'] ?: 'lecture'))); ?> · <?= (int) $section['lesson_count']; ?> lessons · <?= student_analytics_html(student_analytics_readable_rule($section['completion_rule'] ?? 'manual_completion')); ?></p>
                                                <div class="student-analytics-section-progress-wrap">
                                                    <div class="progress student-analytics-section-progress" <?= student_analytics_progress_attributes($sectionPercent, $section['title'] . ' completion'); ?><?= student_analytics_progress_style($sectionPercent); ?>><div class="progress-bar"><span class="visually-hidden"><?= $sectionPercent; ?>% complete</span></div></div>
                                                    <small>Completion: <?= $sectionPercent; ?>%<?= !$sectionCompleted ? ' · completion is recorded when this section is marked complete' : ''; ?></small>
                                                </div>
                                                <?php if (!empty($section['assignment_requirements']['has_requirements'])): ?>
                                                    <small class="student-analytics-section-reason"><?= (int) ($section['assignment_requirements']['completed_count'] ?? 0); ?> of <?= (int) ($section['assignment_requirements']['required_count'] ?? 0); ?> required assignments complete<?= !empty($section['assignment_requirements']['blocking_reason']) ? ' · ' . student_analytics_html($section['assignment_requirements']['blocking_reason']) : ''; ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($section['lock_reason'])): ?><small class="student-analytics-section-reason"><?= student_analytics_html($section['lock_reason']); ?></small><?php endif; ?>
                                            </div>
                                            <span class="badge student-analytics-section-state <?= $sectionState; ?> <?= $sectionLocked ? 'text-bg-secondary' : ($sectionCompleted ? 'text-bg-success' : 'text-bg-warning'); ?>">
                                                <?= $sectionLocked ? 'Locked' : ($sectionCompleted ? 'Completed' : 'Available'); ?>
                                            </span>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($sectionProgress['general_section']['legacy_unsectioned_lessons'])): ?>
                                <p class="student-analytics-data-note"><span class="fas fa-folder"></span> General includes <?= (int) $sectionProgress['general_section']['lesson_count']; ?> legacy lesson<?= (int) $sectionProgress['general_section']['lesson_count'] === 1 ? '' : 's'; ?> not assigned to a section.</p>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </div>
            </section>
        </main>
        <?php include 'layouts/user/footer.php'; ?>
    </div>
</body>
</html>
