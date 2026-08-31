<?php
require_once 'connection/config.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/TimedExam.php';

$conn = db();
$timedExamPreview = !empty($timedExamPreview);
$courseId = student_course_access_identifier($courseId ?? '', 40);
$examId = (int) ($examId ?? 0);
$studentId = $timedExamPreview ? 0 : student_course_access_student_id($conn, $_SESSION['username'] ?? '');
$course = null;
if ($courseId !== null && $timedExamPreview) {
    $previewCourseStmt = $conn->prepare('SELECT id, course_id, course_title, course_description, course_image, course_category, username, sequential_learning, course_status, course_visibility, course_state FROM courses WHERE archived_at IS NULL AND (course_id = ? OR CAST(id AS CHAR) = ?) LIMIT 1');
    if ($previewCourseStmt) {
        $previewCourseStmt->bind_param('ss', $courseId, $courseId);
        $previewCourseStmt->execute();
        $course = $previewCourseStmt->get_result()->fetch_assoc() ?: null;
        $previewCourseStmt->close();
    }
} elseif ($courseId !== null) {
    $course = student_course_access_course($conn, $courseId);
}
$exam = $course && ($timedExamPreview || $studentId) ? mmh_timed_exam_load($conn, (string) $course['course_id'], $examId, $timedExamPreview) : null;
if (!$course || !$exam || (!$timedExamPreview && (!$studentId || !student_course_access_enrolled($conn, $studentId, (string) $course['course_id'])))) { http_response_code(403); exit('Exam unavailable.'); }
$recovery = !$timedExamPreview ? mmh_timed_exam_resolve_recovery($conn, $exam, (int) $studentId, (int) ($_GET['recovery_plan'] ?? 0), (int) ($_GET['recovery_task'] ?? 0)) : null;
if (((int) ($_GET['recovery_plan'] ?? 0) > 0 || (int) ($_GET['recovery_task'] ?? 0) > 0) && !$recovery) { http_response_code(403); exit('Recovery Plan exam window unavailable.'); }
if ($recovery) $exam = $recovery['exam'];
$context = mmh_timed_exam_student_context($conn, $exam, (int) $studentId, $timedExamPreview);
$stateKey = (string) ($context['state']['key'] ?? '');
$activeState = in_array($stateKey, ['open', 'grace'], true);
if (!$timedExamPreview && !in_array($stateKey, ['open', 'grace'], true)) { http_response_code(403); exit('The exam paper is available only during the exam window.'); }
$paper = mmh_timed_exam_normalize_external_paper_url((string) ($exam['paper_external_url'] ?? ''));
if (!$paper) { http_response_code(409); exit('This exam paper needs a valid Google Drive file link.'); }

$action = strtolower(trim((string) ($_GET['paper_action'] ?? '')));
if ($action === '' && !empty($_GET['download'])) $action = 'download';
if (!in_array($action, ['', 'preview', 'open', 'download'], true)) {
    http_response_code(400);
    exit('Invalid exam paper action.');
}
if ($action === 'download' && empty($exam['paper_download_allowed'])) {
    http_response_code(403);
    exit('Exam paper downloads are disabled.');
}
if ($action !== 'download' && empty($exam['paper_view_allowed'])) {
    http_response_code(403);
    exit('Exam paper viewing is disabled.');
}
if ($action === '' || $action === 'preview') {
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    $paperQuery = static function (string $paperAction) use ($recoveryParams): string {
        return '?' . http_build_query($recoveryParams + ['paper_action' => $paperAction], '', '&', PHP_QUERY_RFC3986);
    };
    $openUrl = rtrim(mmh_current_request_base_url(), '/') . ($timedExamPreview
        ? '/admin/courses/' . rawurlencode((string) $course['course_id']) . '/timed-exam/item/' . rawurlencode((string) ($exam['item_id'] ?? '')) . '/paper'
        : '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/paper') . $paperQuery('open');
    $downloadUrl = rtrim(mmh_current_request_base_url(), '/') . ($timedExamPreview
        ? '/admin/courses/' . rawurlencode((string) $course['course_id']) . '/timed-exam/item/' . rawurlencode((string) ($exam['item_id'] ?? '')) . '/paper'
        : '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/paper') . $paperQuery('download');
    $returnQuery = $recoveryParams ? '?' . http_build_query($recoveryParams, '', '&', PHP_QUERY_RFC3986) : '';
    $courseUrl = $timedExamPreview
        ? $base . '/admin/courses/' . rawurlencode((string) $course['course_id']) . '/content'
        : $base . '/user/course/' . rawurlencode((string) $course['course_id']);
    $returnUrl = $timedExamPreview
        ? $previewExitUrl
        : $base . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . $returnQuery;
    $closeTimestamp = $state['window']['closes_at'] instanceof DateTimeImmutable ? $state['window']['closes_at']->getTimestamp() : 0;
    if (($state['key'] ?? '') === 'grace' && $state['window']['grace_closes_at'] instanceof DateTimeImmutable) {
        $closeTimestamp = $state['window']['grace_closes_at']->getTimestamp();
    }
    $timerSeconds = max(0, (int) ($state['remaining_seconds'] ?? 0));
    $timerLabel = $timedExamPreview ? 'Preview' : ($activeState
        ? sprintf('%02d:%02d', intdiv($timerSeconds, 60), $timerSeconds % 60)
        : (string) ($state['label'] ?? 'Timed Exam'));
    $sectionTitle = trim((string) ($exam['section_title'] ?? '')) ?: 'Exam Simulation';
    $metaLabel = $timedExamPreview ? 'Timed Exam · Preview' : 'Timed Exam · Fixed Window';
    $viewerKey = 'math-mastery-exam-paper:' . ($timedExamPreview ? 'preview' : (int) $studentId) . ':' . (string) $course['course_id'] . ':' . (int) $exam['id'];
    $esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ?><!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $esc((string) $exam['title']); ?> | <?= $esc($course['course_title'] ?? 'Course'); ?></title>
    <script>
    (function () {
        var theme = 'dark';
        try { theme = localStorage.getItem('math-mastery-student-theme') || theme; } catch (error) {}
        document.documentElement.dataset.studentTheme = theme === 'light' ? 'light' : 'dark';
        document.documentElement.style.colorScheme = document.documentElement.dataset.studentTheme;
    }());
    </script>
    <link rel="stylesheet" href="<?= $esc(rtrim((string) $base, '/') . '/resources/css/fontawsome5.min.css'); ?>">
    <link rel="stylesheet" href="<?= $esc(rtrim((string) $base, '/') . '/resources/css/design-system.css'); ?>">
    <link rel="stylesheet" href="<?= $esc(rtrim((string) $base, '/') . '/resources/css/course-learning.css'); ?>">
</head>
<body class="course-learning-page course-resource-viewer-page">
<main class="course-resource-viewer" data-resource-viewer data-resource-viewer-key="<?= $esc($viewerKey); ?>" data-resource-viewer-kind="pdf">
    <header class="course-resource-viewer-header">
        <nav class="course-resource-viewer-breadcrumb" aria-label="Breadcrumb">
            <a href="<?= $esc($courseUrl); ?>"><?= $esc($timedExamPreview ? 'Course Content' : 'Course'); ?></a>
            <span aria-hidden="true">/</span>
            <span><?= $esc($sectionTitle); ?></span>
            <span aria-hidden="true">/</span>
            <span aria-current="page"><?= $esc((string) $exam['title']); ?></span>
        </nav>
        <div class="course-resource-viewer-heading">
            <span class="course-resource-viewer-icon fas fa-file-pdf" aria-hidden="true"></span>
            <div class="course-resource-viewer-title-block">
                <p class="course-resource-viewer-meta">
                    <?= $esc($metaLabel); ?>
                    <?php if ($activeState || $timedExamPreview): ?><span aria-hidden="true">•</span><span data-countdown<?= $activeState ? ' data-close="' . (int) $closeTimestamp . '"' : ''; ?>><?= $esc($timerLabel); ?></span><?php endif; ?>
                    <?php if (!$activeState && !$timedExamPreview): ?><span aria-hidden="true">•</span><?= $esc((string) ($state['label'] ?? 'Unavailable')); ?><?php endif; ?>
                </p>
                <h1><?= $esc((string) $exam['title']); ?></h1>
                <p class="course-resource-viewer-completion"><span class="fas fa-stopwatch" aria-hidden="true"></span> <?= $esc($activeState ? 'Exam window active' : ($timedExamPreview ? 'Preview only' : (string) ($state['label'] ?? 'Exam unavailable'))); ?></p>
            </div>
            <a href="<?= $esc($returnUrl); ?>" class="course-resource-viewer-return"><span class="fas fa-arrow-left" aria-hidden="true"></span> <?= $esc($timedExamPreview ? 'Exit Preview' : 'Return to Exam'); ?></a>
        </div>
    </header>

    <section class="course-resource-viewer-toolbar" aria-label="Exam paper viewer controls">
        <div class="course-resource-viewer-tool-group course-resource-viewer-tool-group-view">
            <span class="course-resource-viewer-tool-label">Viewing</span>
            <button type="button" data-resource-open><span class="fas fa-expand-arrows-alt" aria-hidden="true"></span><span>Focus viewer</span></button>
            <?php if (!empty($exam['paper_view_allowed'])): ?><a href="<?= $esc($openUrl); ?>" target="_blank" rel="noopener noreferrer"><span class="fas fa-external-link-alt" aria-hidden="true"></span><span>Open externally</span></a><?php endif; ?>
        </div>
        <div class="course-resource-viewer-tool-divider" aria-hidden="true"></div>
        <div class="course-resource-viewer-tool-group course-resource-viewer-tool-group-actions">
            <span class="course-resource-viewer-tool-label">Actions</span>
            <?php if (!empty($exam['paper_download_allowed'])): ?><a href="<?= $esc($downloadUrl); ?>" target="_blank" rel="noopener noreferrer"><span class="fas fa-download" aria-hidden="true"></span><span>Download</span></a><?php endif; ?>
            <button type="button" data-resource-copy><span class="fas fa-link" aria-hidden="true"></span><span>Copy link</span></button>
            <button type="button" data-resource-reload><span class="fas fa-sync-alt" aria-hidden="true"></span><span>Reload</span></button>
            <button type="button" data-resource-fullscreen aria-pressed="false"><span class="fas fa-expand" aria-hidden="true"></span><span>Fullscreen</span></button>
        </div>
        <span class="visually-hidden" data-resource-status role="status" aria-live="polite"></span>
    </section>

    <section id="resource-viewer-stage" class="course-resource-viewer-stage" data-resource-viewer-stage data-resource-kind="pdf" aria-label="<?= $esc((string) $exam['title']); ?> exam paper" tabindex="-1" aria-busy="true">
        <div class="course-resource-viewer-loading" data-resource-viewer-loading><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Preparing exam paper…</span></div>
        <iframe data-resource-viewer-frame data-resource-viewer-src="<?= $esc((string) $paper['preview_url']); ?>" title="<?= $esc((string) $exam['title']); ?> exam paper" loading="eager" referrerpolicy="no-referrer" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>
    </section>
    <p class="course-resource-viewer-provider-notice" data-resource-viewer-notice role="status" aria-live="polite" hidden></p>

    <nav class="course-resource-viewer-navigation" aria-label="Exam navigation">
        <div></div>
        <div><a class="course-resource-viewer-nav-link" href="<?= $esc($returnUrl); ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> <?= $esc($timedExamPreview ? 'Exit Preview' : 'Return to Exam'); ?></a></div>
    </nav>
</main>
<script src="<?= $esc(rtrim((string) $base, '/') . '/resources/js/course-resource-viewer.js'); ?>" defer></script>
<?php if ($activeState): ?><script>
(function () {
    var countdown = document.querySelector('[data-countdown]');
    if (!countdown) return;
    var title = <?= json_encode((string) $exam['title'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    function format(seconds) { seconds = Math.max(0, seconds); return String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0'); }
    function tick() {
        var seconds = Math.max(0, parseInt(countdown.dataset.close || '0', 10) - Math.floor(Date.now() / 1000));
        countdown.textContent = format(seconds);
        document.title = (seconds > 0 ? format(seconds) : 'Time Ended') + ' — ' + title;
        if (seconds <= 0) window.location.reload();
    }
    tick();
    window.setInterval(tick, 1000);
}());
</script><?php endif; ?>
</body>
</html><?php
    exit;
}
if ($action === 'open') {
    mmh_timed_exam_download($conn, $exam, false);
}
if ($action === 'download') {
    mmh_timed_exam_download($conn, $exam, true);
}
http_response_code(400);
exit('Invalid exam paper action.');
