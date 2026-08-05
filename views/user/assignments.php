<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/LiveAssignmentRepair.php';

// LiteSpeed installations may keep included PHP files in OPcache while the
// deploy checkout is updated. Invalidate the small resolver cluster before
// loading it so an authenticated request cannot run the previous six-item
// resolver bytecode after a deployment.
if (function_exists('opcache_invalidate')) {
    foreach ([
        __DIR__ . '/../../inc/AssignmentProgress.php',
        __DIR__ . '/../../inc/CourseResourceResolver.php',
        __DIR__ . '/../../inc/CourseAssignmentLinks.php',
    ] as $resolverFile) {
        @opcache_invalidate($resolverFile, true);
    }
}
require_once 'inc/AssignmentProgress.php';

// This page is user-specific and must never be served from a shared edge or
// browser cache. A stale cached response can otherwise outlive repaired
// course relationships and display an old assignment count.
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pageName = 'assignments';
$username = $_SESSION['username'] ?? '';
$userInfo = getUserInfo($username);
$userData = is_object($userInfo) ? get_object_vars($userInfo) : [];
$userId = (int) ($userData['user_id'] ?? 0);
$conn = db();
mmh_ensure_learning_schema($conn);

function student_assignments_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function student_assignments_due_label($value)
{
    $timestamp = !empty($value) ? strtotime((string) $value) : false;
    return $timestamp === false ? 'No due date' : date('j M Y, g:i A', $timestamp);
}

$courseRows = [];
if ($userId > 0) {
    $stmt = $conn->prepare("SELECT DISTINCT c.course_id, c.course_title
        FROM course_logs AS l INNER JOIN courses AS c ON c.course_id = l.course_id
        WHERE l.user_id = ? AND c.course_state IN ('public', 'private') ORDER BY c.course_title ASC");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $courseRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$assignmentRows = [];
foreach ($courseRows as $course) {
    mmh_live_assignment_repair($conn, (string) $course['course_id']);
    foreach (mmh_assignment_progress_load_course($conn, $userId, (string) $course['course_id']) as $assignment) {
        $assignment['course_title'] = $course['course_title'];
        $assignmentRows[] = $assignment;
    }
}
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assignments | <?= student_assignments_html($site_name); ?></title>
    <?php include 'layouts/user/header.php'; ?>
</head>
<body class="body ds-bg-primary student-dashboard-page unified-private-page assignments-page" style="margin-top: 65px">
    <div id="app">
        <div id="body-overlay" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></div>
        <?php include 'layouts/user/aside.php'; ?>
        <main class="font-2">
            <section class="student-dashboard-top unified-private-hero" aria-label="Assignments introduction">
                <div class="container">
                    <section class="student-dashboard-welcome">
                        <div>
                            <span class="student-dashboard-eyebrow">Learning journey</span>
                            <h1>Assignments</h1>
                            <p>See what is due, what needs revision, and what is waiting for your teacher in one place.</p>
                        </div>
                    </section>
                </div>
                <div class="student-dashboard-nav-wrap">
                    <div class="container p-0"><div class="col-12 row user-menu"><nav class="navbar navbar-expand-lg navbar-light ds-surface-muted"><div class="container-fluid p-0"><?php include 'layouts/user/main-nav.php'; ?></div></nav></div></div>
                </div>
            </section>
            <section class="container student-assignments-shell" aria-label="Assignment status">
                <?php if (empty($assignmentRows)): ?>
                    <div class="student-dashboard-empty assignment-progress-empty">
                        <span class="fas fa-clipboard-check" aria-hidden="true"></span>
                        <h2>No assignments yet</h2>
                        <p>Assignments from your enrolled courses will appear here when your teacher publishes them.</p>
                    </div>
                <?php else: ?>
                    <div class="assignment-progress-list">
                        <?php foreach ($assignmentRows as $assignment): ?>
                            <?php
                            $state = $assignment['_state'] ?? [];
                            $stateClass = str_replace('_', '-', preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string) ($state['state'] ?? 'not_started'))));
                            $itemId = trim((string) ($assignment['item_id'] ?? ''));
                            $link = rtrim((string) $baseUrl, '/') . '/user/course/' . rawurlencode((string) $assignment['course_id']);
                            if ($itemId !== '') {
                                $link .= '?lesson=' . rawurlencode($itemId);
                            }
                            ?>
                            <article class="assignment-progress-card <?= student_assignments_html($stateClass); ?>">
                                <div class="assignment-progress-card-main">
                                    <div class="assignment-progress-icon"><span class="fas <?= in_array($stateClass, ['overdue', 'needs-revision'], true) ? 'fa-exclamation-circle' : 'fa-clipboard-list'; ?>" aria-hidden="true"></span></div>
                                    <div>
                                        <div class="assignment-progress-title-row">
                                            <h2><?= student_assignments_html($assignment['assignment_title']); ?></h2>
                                            <span class="assignment-progress-status <?= student_assignments_html($stateClass); ?>"><?= student_assignments_html($state['label'] ?? 'Not started'); ?></span>
                                            <?php if ((($assignment['_submission']['submission_source'] ?? '') === 'legacy_import')): ?><span class="assignment-progress-imported">Imported by Instructor</span><?php endif; ?>
                                        </div>
                                        <p class="assignment-progress-course"><?= student_assignments_html($assignment['course_title']); ?></p>
                                        <p class="assignment-progress-section"><?= student_assignments_html($assignment['_source_section_title'] ?? 'General'); ?></p>
                                        <p class="assignment-progress-meta"><?= student_assignments_html(mmh_assignment_progress_requirement_label($assignment['completion_requirement'] ?? 'optional')); ?> · <?= student_assignments_html($state['completion_label'] ?? 'Submission required'); ?> · <?= student_assignments_due_label($assignment['due_date'] ?? ''); ?></p>
                                        <p class="assignment-progress-reason"><?= student_assignments_html($state['reason'] ?? ''); ?></p>
                                    </div>
                                </div>
                                <a class="student-dashboard-btn <?= !empty($state['blocked']) ? 'primary' : 'secondary'; ?>" href="<?= student_assignments_html($link); ?>">
                                    <?= in_array(($state['action'] ?? ''), ['submit', 'revise'], true) ? 'Open assignment' : 'View assignment'; ?>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php include 'layouts/user/footer.php'; ?>
    </div>
</body>
</html>
