<?php
// The protected route includes this file from a callback scope. Bind the
// origin that the application bootstrap created at global scope.
global $baseUrl;
// This endpoint is also included directly by the protected resource route.
// Load the shared request bootstrap here so every error, redirect, and viewer
// URL has the same origin-aware base URL as the rest of the application.
require_once dirname(__DIR__, 3) . '/__init.php';
require_once 'connection/config.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/StudentCourseProgress.php';
require_once 'inc/StudentCourseCsrf.php';
require_once 'inc/RecoveryPlan.php';
require_once 'inc/StudentLearningJourney.php';
require_once 'inc/CourseResourceResolver.php';
require_once 'inc/CourseHomeworkRenderer.php';

function course_resource_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function course_resource_url($baseUrl, $courseId, $itemId)
{
    return rtrim((string) $baseUrl, '/') . '/user/course/resource/' . rawurlencode((string) $courseId) . '/' . rawurlencode((string) $itemId);
}

function course_resource_plan_url($baseUrl, $courseId, $itemId, array $planContext = [], ?int $taskId = null)
{
    if (!empty($planContext['plan']['id']) && (($taskId ?? (int) ($planContext['task']['id'] ?? 0)) > 0)) {
        return mmh_recovery_plan_resource_url($baseUrl, (string) $courseId, (string) $itemId, (int) $planContext['plan']['id'], (int) ($taskId ?? $planContext['task']['id']));
    }
    return course_resource_url($baseUrl, $courseId, $itemId);
}

/**
 * Resolve Recovery Plan context once at the protected route boundary. Every
 * downstream renderer receives a non-null, validated array; normal course
 * resources continue to use the empty array context.
 */
function course_resource_recovery_context(mysqli $conn, int $userId, array $course, int $planId, int $taskId, string $itemId): array
{
    if ($planId <= 0 || $taskId <= 0 || $itemId === '') return ['valid' => false];
    $plan = mmh_recovery_plan_load($conn, $userId, (string) $course['course_id'], $planId);
    if (!$plan || !in_array((string) ($plan['status'] ?? ''), ['active', 'completed'], true)) return ['valid' => false];
    $plan = mmh_recovery_plan_sync($conn, $plan, $userId, (string) $course['course_id']);
    $task = null;
    foreach (($plan['items'] ?? []) as $candidate) {
        if ((int) ($candidate['id'] ?? 0) === $taskId && (string) ($candidate['item_id'] ?? '') === $itemId) {
            $task = $candidate;
            break;
        }
    }
    if (!$task || (!empty($task['is_locked']) && empty($task['is_completed']))) return ['valid' => false];
    return [
        'valid' => true,
        'plan' => $plan,
        'task' => $task,
        'ordered_tasks' => array_values($plan['items'] ?? []),
        'navigation' => mmh_recovery_plan_task_context($plan, $taskId),
    ];
}

function course_resource_notice($statusCode, $title, $message, $courseId = '')
{
    global $baseUrl;
    http_response_code($statusCode);
    $back = trim((string) $courseId) !== ''
        ? rtrim((string) $baseUrl, '/') . '/user/course/' . rawurlencode((string) $courseId)
        : rtrim((string) $baseUrl, '/') . '/user/my-courses';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="' . course_resource_escape(rtrim((string) $baseUrl, '/') . '/resources/css/fontawsome5.min.css') . '">'
        . '<link rel="stylesheet" href="' . course_resource_escape(rtrim((string) $baseUrl, '/') . '/resources/css/design-system.css') . '">'
        . '<link rel="stylesheet" href="' . course_resource_escape(rtrim((string) $baseUrl, '/') . '/resources/css/course-learning.css') . '">'
        . '<title>' . course_resource_escape($title) . '</title></head><body class="course-learning-page">'
        . '<main class="course-resource-notice"><span class="fas fa-info-circle" aria-hidden="true"></span><h1>' . course_resource_escape($title) . '</h1><p>' . course_resource_escape($message) . '</p>'
        . '<a class="course-btn course-btn-secondary" href="' . course_resource_escape($back) . '"><span class="fas fa-arrow-left" aria-hidden="true"></span> Return to course</a>'
        . '</main></body></html>';
    exit;
}

function course_resource_record_open(mysqli $conn, $userId, array $course, $sectionId, $itemId, array $resource)
{
    $eventType = $resource['event_type'] ?? '';
    if ($eventType !== '') {
        mmh_log_event($conn, $userId, $eventType, [
            'course_id' => $course['course_id'],
            'section_id' => $sectionId,
            'item_id' => $itemId,
        ]);
    }
    if (student_course_progress_available($conn)) {
        student_course_progress_record_viewed($conn, $userId, $course['course_id'], $itemId);
    }
}

function course_resource_navigation(mysqli $conn, array $course, $userId, $itemId, array $planContext = [])
{
    if (!empty($planContext['plan']['items'])) {
        $items = $planContext['ordered_tasks'] ?? $planContext['plan']['items']; $current = null;
        foreach ($items as $index => $candidate) if ((string) ($candidate['item_id'] ?? '') === (string) $itemId) { $current = $index; break; }
        $previous = null; $next = null;
        if ($current !== null) { for ($i = $current - 1; $i >= 0; $i--) if (empty($items[$i]['is_locked']) || !empty($items[$i]['is_completed'])) { $previous = $items[$i]; break; } for ($i = $current + 1; $i < count($items); $i++) if (empty($items[$i]['is_locked']) || !empty($items[$i]['is_completed'])) { $next = $items[$i]; break; } }
        return ['previous' => $previous, 'next' => $next, 'position' => $current !== null ? $current + 1 : 0, 'total' => count($items), 'plan' => true];
    }
    $items = student_course_access_ordered_items($conn, $course, $userId);
    $current = null;
    foreach ($items as $index => $candidate) {
        if ((string) ($candidate['item_id'] ?? '') !== (string) $itemId) {
            continue;
        }
        $current = $index;
        break;
    }
    return [
        'previous' => $current !== null && $current > 0 ? $items[$current - 1] : null,
        'next' => $current !== null && $current < count($items) - 1 ? $items[$current + 1] : null,
        'position' => $current !== null ? $current + 1 : 0,
        'total' => count($items),
    ];
}

function course_resource_render_viewer(mysqli $conn, $baseUrl, $userId, array $course, array $selection, $itemId, array $resource)
{
    global $course_resource_plan_context;
    $item = $selection['item'];
    $section = $selection['section_state']['section'] ?? null;
    $sectionTitle = $section ? trim((string) ($section['title'] ?? '')) : 'General';
    $sectionTitle = $sectionTitle !== '' ? $sectionTitle : 'General';
    $title = trim((string) ($item['item_title'] ?? '')) ?: 'Learning resource';
    $courseUrl = student_course_access_course_url($baseUrl, $course['course_id']);
    $returnUrl = !empty($course_resource_plan_context['plan']['id']) ? mmh_recovery_plan_workspace_url($baseUrl, (string) $course['course_id'], (int) $course_resource_plan_context['plan']['id'], (int) ($course_resource_plan_context['task']['id'] ?? 0)) : student_course_access_course_url($baseUrl, $course['course_id'], $itemId, true);
    $navigation = course_resource_navigation($conn, $course, $userId, $itemId, $course_resource_plan_context);
    $previous = $navigation['previous'];
    $next = $navigation['next'];
    $previousUrl = $previous ? course_resource_plan_url($baseUrl, $course['course_id'], $previous['item_id'], $course_resource_plan_context, (int) ($previous['id'] ?? 0)) : '';
    $nextUrl = $next ? course_resource_plan_url($baseUrl, $course['course_id'], $next['item_id'], $course_resource_plan_context, (int) ($next['id'] ?? 0)) : '';
    $description = trim(strip_tags((string) ($resource['description'] ?? '')));
    $embedUrl = (string) ($resource['embed_url'] ?? '');
    $openUrl = (string) ($resource['open_url'] ?? '');
    $downloadUrl = trim((string) ($resource['download_url'] ?? ''));
    $kind = (string) ($resource['embed_kind'] ?? 'resource');
    $primaryActionLabel = 'View resource';
    $primaryActionIcon = 'fas fa-external-link-alt';
    if (in_array($kind, ['recording', 'microsoft_stream'], true)) {
        $primaryActionLabel = 'Watch recording';
        $primaryActionIcon = 'fas fa-play';
    } elseif (in_array($kind, ['youtube', 'video'], true)) {
        $primaryActionLabel = 'Watch lesson';
        $primaryActionIcon = 'fas fa-play';
    } elseif (in_array($kind, ['pdf', 'google'], true)) {
        $primaryActionLabel = 'Open document';
        $primaryActionIcon = 'fas fa-file-alt';
    }
    $viewerKey = 'math-mastery-resource-position:' . (int) $userId . ':' . (string) $course['course_id'] . ':' . (string) $itemId;
    $durationMinutes = isset($item['duration_minutes']) && is_numeric($item['duration_minutes']) ? (int) $item['duration_minutes'] : 0;
    $durationLabel = $durationMinutes > 0 ? ($durationMinutes >= 60 ? floor($durationMinutes / 60) . 'h ' . ($durationMinutes % 60 ? ($durationMinutes % 60) . 'm' : '') : $durationMinutes . ' min') : '';
    $lessonPosition = (int) ($navigation['position'] ?? 0);
    $lessonTotal = (int) ($navigation['total'] ?? 0);
    $journey = mmh_learning_journey_resolve($conn, (int) $userId, (string) $course['course_id']);
    $journeyItem = null;
    foreach ($journey['items'] as $candidate) { if ((string) ($candidate['item_id'] ?? '') === (string) $itemId) { $journeyItem = $candidate; break; } }
    $isCompleted = !empty($journeyItem['is_completed']);
    $completionLabel = $isCompleted ? 'Completed' : 'Not completed';
    $completionIcon = $isCompleted ? 'fas fa-check-circle' : 'far fa-circle';
    $manualCompletion = !$isCompleted && student_course_progress_manual_completion_eligible($selection['item']);

    course_resource_record_open($conn, $userId, $course, (string) ($selection['section_id'] ?? ''), $itemId, $resource);
    ?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= course_resource_escape($title); ?> | <?= course_resource_escape($course['course_title'] ?? 'Course'); ?></title>
    <script>
    (function () {
        var theme = 'dark';
        try { theme = localStorage.getItem('math-mastery-student-theme') || theme; } catch (error) {}
        document.documentElement.dataset.studentTheme = theme === 'light' ? 'light' : 'dark';
        document.documentElement.style.colorScheme = document.documentElement.dataset.studentTheme;
    }());
    </script>
    <link rel="stylesheet" href="<?= course_resource_escape(rtrim((string) $baseUrl, '/') . '/resources/css/fontawsome5.min.css'); ?>">
    <link rel="stylesheet" href="<?= course_resource_escape(rtrim((string) $baseUrl, '/') . '/resources/css/design-system.css'); ?>">
    <link rel="stylesheet" href="<?= course_resource_escape(rtrim((string) $baseUrl, '/') . '/resources/css/course-learning.css'); ?>">
</head>
<body class="course-learning-page course-resource-viewer-page">
<main class="course-resource-viewer" data-resource-viewer data-resource-viewer-key="<?= course_resource_escape($viewerKey); ?>" data-resource-viewer-kind="<?= course_resource_escape($kind); ?>">
    <header class="course-resource-viewer-header">
        <nav class="course-resource-viewer-breadcrumb" aria-label="Breadcrumb">
            <a href="<?= course_resource_escape($courseUrl); ?>">Course</a><span aria-hidden="true">/</span>
            <span><?= course_resource_escape($sectionTitle); ?></span><span aria-hidden="true">/</span>
            <span aria-current="page"><?= course_resource_escape($title); ?></span>
        </nav>
        <div class="course-resource-viewer-heading">
            <span class="course-resource-viewer-icon <?= course_resource_escape($resource['icon'] ?? 'fas fa-file'); ?>" aria-hidden="true"></span>
            <div class="course-resource-viewer-title-block">
                <p class="course-resource-viewer-meta"><?= course_resource_escape($resource['label'] ?? 'Resource'); ?><?php if ($lessonPosition > 0 && $lessonTotal > 0): ?> <span aria-hidden="true">•</span> Lesson <?= $lessonPosition; ?> of <?= $lessonTotal; ?><?php endif; ?><?php if ($durationLabel !== ''): ?> <span aria-hidden="true">•</span> <?= course_resource_escape($durationLabel); ?><?php endif; ?></p>
                <h1><?= course_resource_escape($title); ?></h1>
                <p class="course-resource-viewer-completion<?= $isCompleted ? ' is-complete' : ''; ?>"><span class="<?= $completionIcon; ?>" aria-hidden="true"></span> <?= course_resource_escape($completionLabel); ?></p>
                <?php if ($manualCompletion): ?><button type="button" class="course-btn course-btn-secondary" data-resource-complete data-course-id="<?= course_resource_escape($course['course_id']); ?>" data-item-id="<?= course_resource_escape($itemId); ?>" data-section-id="<?= course_resource_escape((string) ($selection['section_id'] ?? '')); ?>" data-csrf="<?= course_resource_escape(student_course_csrf_token()); ?>"><span class="fas fa-check" aria-hidden="true"></span> Mark Lesson Complete</button><?php endif; ?>
            </div>
            <a href="<?= course_resource_escape($returnUrl); ?>" class="course-resource-viewer-return"><span class="fas fa-arrow-left" aria-hidden="true"></span> Return to course</a>
        </div>
    </header>

    <?php if ($description !== '' && mmh_course_resource_has_meaningful_description($description, $title)): ?>
        <section class="course-resource-viewer-description"><h2>About this resource</h2><p><?= course_resource_escape($description); ?></p></section>
    <?php endif; ?>

    <section class="course-resource-viewer-toolbar" aria-label="Resource viewer controls">
        <div class="course-resource-viewer-tool-group course-resource-viewer-tool-group-view">
            <span class="course-resource-viewer-tool-label">Viewing</span>
            <button type="button" data-resource-open><span class="fas fa-expand-arrows-alt" aria-hidden="true"></span><span>Focus viewer</span></button>
            <a href="<?= course_resource_escape($openUrl); ?>" target="_blank" rel="noopener noreferrer"><span class="fas fa-external-link-alt" aria-hidden="true"></span><span>Open externally</span></a>
        </div>
        <div class="course-resource-viewer-tool-divider" aria-hidden="true"></div>
        <div class="course-resource-viewer-tool-group course-resource-viewer-tool-group-actions">
            <span class="course-resource-viewer-tool-label">Actions</span>
            <?php if ($downloadUrl !== ''): ?><a href="<?= course_resource_escape($downloadUrl); ?>" target="_blank" rel="noopener noreferrer"><span class="fas fa-download" aria-hidden="true"></span><span>Download</span></a><?php endif; ?>
            <button type="button" data-resource-copy><span class="fas fa-link" aria-hidden="true"></span><span>Copy link</span></button>
            <button type="button" data-resource-reload><span class="fas fa-sync-alt" aria-hidden="true"></span><span>Reload</span></button>
            <button type="button" data-resource-fullscreen aria-pressed="false"><span class="fas fa-expand" aria-hidden="true"></span><span>Fullscreen</span></button>
        </div>
        <span class="visually-hidden" data-resource-status role="status" aria-live="polite"></span>
    </section>

    <section id="resource-viewer-stage" class="course-resource-viewer-stage" data-resource-viewer-stage data-resource-kind="<?= course_resource_escape($kind); ?>" aria-label="<?= course_resource_escape($title); ?> viewer" tabindex="-1" aria-busy="true">
        <div class="course-resource-viewer-loading" data-resource-viewer-loading><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Preparing your resource…</span></div>
        <iframe data-resource-viewer-frame data-resource-viewer-src="<?= course_resource_escape($embedUrl); ?>" title="<?= course_resource_escape($title); ?>" loading="eager" referrerpolicy="no-referrer" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>
    </section>
    <p class="course-resource-viewer-provider-notice" data-resource-viewer-notice role="status" aria-live="polite" hidden></p>

    <nav class="course-resource-viewer-navigation" aria-label="Lesson navigation">
        <div><?php if ($previousUrl !== ''): ?><a class="course-resource-viewer-nav-link" href="<?= course_resource_escape($previousUrl); ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> Previous resource</a><?php endif; ?></div>
        <div><?php if (!empty($course_resource_plan_context['plan']['id']) && !empty($navigation['plan']) && empty($next) && (string) ($course_resource_plan_context['plan']['status'] ?? '') === 'completed'): ?><a class="course-resource-viewer-nav-link" href="<?= course_resource_escape(mmh_recovery_plan_workspace_url($baseUrl, (string) $course['course_id'], (int) $course_resource_plan_context['plan']['id'])); ?>">Finish Recovery Plan <span class="fas fa-check" aria-hidden="true"></span></a><?php elseif ($nextUrl !== ''): ?><a class="course-resource-viewer-nav-link" href="<?= course_resource_escape($nextUrl); ?>">Next task <span class="fas fa-arrow-right" aria-hidden="true"></span></a><?php elseif (!empty($course_resource_plan_context['plan']['id'])): ?><a class="course-resource-viewer-nav-link" href="<?= course_resource_escape(mmh_recovery_plan_workspace_url($baseUrl, (string) $course['course_id'], (int) $course_resource_plan_context['plan']['id'])); ?>">Back to Recovery Plan <span class="fas fa-arrow-right" aria-hidden="true"></span></a><?php else: ?><a class="course-resource-viewer-nav-link" href="<?= course_resource_escape($courseUrl); ?>">Continue learning <span class="fas fa-arrow-right" aria-hidden="true"></span></a><?php endif; ?></div>
    </nav>
</main>
<script src="<?= course_resource_escape(rtrim((string) $baseUrl, '/') . '/resources/js/course-resource-viewer.js'); ?>" defer></script>
<?php if ($manualCompletion): ?><script>document.querySelector('[data-resource-complete]')?.addEventListener('click',function(){var b=this;b.disabled=true;var d=new URLSearchParams({action:'complete',course_id:b.dataset.courseId,item_id:b.dataset.itemId,section_id:b.dataset.sectionId,csrf_token:b.dataset.csrf});fetch('<?= course_resource_escape(rtrim((string) $baseUrl, '/') . '/user/requests/progress/item'); ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:d}).then(function(r){return r.json();}).then(function(x){if(x&&x.success){window.location.reload();}else{b.disabled=false;}}).catch(function(){b.disabled=false;});});</script><?php endif; ?>
</body>
</html>
<?php
    exit;
}

function course_resource_open_homework_part(mysqli $conn, $baseUrl, $userId, array $course, array $selection, $itemId, array $resource, $part)
{
    $assignmentId = trim((string) ($resource['assignment_id'] ?? ''));
    $assignment = mmh_homework_assignment($conn, $assignmentId, (string) $course['course_id']);
    if (!$assignment || !student_course_access_assignment_matches_item($assignment, $selection['item'])) {
        course_resource_notice(404, 'Homework unavailable', 'This homework is no longer linked to this lesson.', $course['course_id']);
    }
    $submissions = mmh_assignment_progress_latest_submissions($conn, (int) $userId, (string) $course['course_id']);
    $submission = $submissions[$assignmentId] ?? null;
    $partResource = mmh_homework_part($conn, $course, $selection, $resource, $assignment, $submission, $part);
    if (!is_array($partResource)) {
        course_resource_notice(404, 'Resource unavailable', 'This Homework resource has not been configured yet.', $course['course_id']);
    }
    if (!empty($partResource['locked'])) {
        course_resource_notice(403, 'Model Answer locked', (string) ($partResource['message'] ?? 'This model answer is not available yet.'), $course['course_id']);
    }
    $target = mmh_course_resource_safe_url($partResource['url'] ?? '');
    if ($target === null) {
        course_resource_notice(404, 'Resource unavailable', 'This Homework resource is not available.', $course['course_id']);
    }
    $details = mmh_course_resource_embed_details($target, (string) ($partResource['resource_type'] ?? 'external_link'));
    $viewerSelection = $selection;
    $viewerSelection['item']['item_title'] = trim((string) ($selection['item']['item_title'] ?? 'Homework')) . ' — ' . (string) ($partResource['label'] ?? 'Resource');
    $viewerResource = [
        'label' => (string) ($partResource['label'] ?? 'Homework'),
        'icon' => (string) ($partResource['icon'] ?? 'fas fa-file-alt'),
        'event_type' => (string) ($resource['event_type'] ?? ''),
        'description' => '',
    ];
    if ($details !== null) {
        $viewerResource = array_merge($viewerResource, [
            'action' => 'embed',
            'url' => $target,
            'embed_url' => $details['embed_url'],
            'open_url' => $details['open_url'],
            'download_url' => $details['download_url'],
            'embed_kind' => $details['kind'],
        ]);
        course_resource_render_viewer($conn, $baseUrl, $userId, $course, $viewerSelection, $itemId, $viewerResource);
    }
    course_resource_record_open($conn, $userId, $course, (string) ($selection['section_id'] ?? ''), $itemId, $viewerResource);
    header('Location: ' . $target, true, 302);
    exit;
}

$conn = db();
$courseId = student_course_access_identifier($courseId ?? '', 40);
$itemId = student_course_access_identifier($itemId ?? '', 40);
$userId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
if ($courseId === null || $itemId === null || $userId === null) {
    course_resource_notice(404, 'Resource unavailable', 'This learning resource could not be found.');
}

$course = student_course_access_course($conn, $courseId);
if (!$course || !student_course_access_enrolled($conn, $userId, $course['course_id'])) {
    course_resource_notice(403, 'Resource unavailable', 'You do not have access to this course.');
}
$course_resource_plan_context = [];
$requestedPlanId = (int) ($_GET['recovery_plan'] ?? 0);
$requestedTaskId = (int) ($_GET['recovery_task'] ?? 0);
if (($requestedPlanId > 0) !== ($requestedTaskId > 0)) {
    course_resource_notice(403, 'Recovery Plan unavailable', 'The Recovery Plan context is incomplete.', $course['course_id']);
}

$item = student_course_access_item($conn, $course['course_id'], $itemId);
if (!$item) {
    course_resource_notice(404, 'Resource unavailable', 'This learning resource is no longer available.', $course['course_id']);
}
if ($requestedPlanId > 0 && $requestedTaskId > 0) {
    $course_resource_plan_context = course_resource_recovery_context($conn, (int) $userId, $course, $requestedPlanId, $requestedTaskId, (string) $itemId);
    if (empty($course_resource_plan_context['valid'])) {
        course_resource_notice(403, 'Recovery task unavailable', 'This Recovery Plan task is not available for your account.', $course['course_id']);
    }
}
$sectionId = student_course_access_normalize_section_id($item['section_id'] ?? '');
if ($sectionId === null) {
    course_resource_notice(404, 'Resource unavailable', 'This learning resource is unavailable.', $course['course_id']);
}
$selection = student_course_access_selected_item($conn, $course, $itemId, $sectionId, $userId);
if (!$selection) {
    course_resource_notice(403, 'Section locked', 'This resource will be available when its section is unlocked.', $course['course_id']);
}

$resource = mmh_course_resource_resolve($selection['item']);
if (($resource['action'] ?? '') === 'homework') {
    // The old homework_open flag remains a protected compatibility alias. New
    // links use a clear part name and always resolve through this endpoint.
    $part = strtolower(trim((string) ($_GET['part'] ?? '')));
    if ($part === '' && ($_GET['homework_open'] ?? '') === '1') {
        $part = 'homework';
    }
    if ($part !== '') {
        if (!in_array($part, ['homework', 'model-answer'], true)) {
            course_resource_notice(404, 'Resource unavailable', 'This Homework resource could not be found.', $course['course_id']);
        }
        course_resource_open_homework_part($conn, $baseUrl, $userId, $course, $selection, $itemId, $resource, $part);
    }
    mmh_homework_render($conn, $baseUrl, $userId, $course, $selection, $itemId, $resource, $course_resource_plan_context);
}
if (($resource['action'] ?? '') === 'embed' && !empty($resource['embed_url'])) {
    course_resource_render_viewer($conn, $baseUrl, $userId, $course, $selection, $itemId, $resource);
}
if (($resource['action'] ?? '') === 'redirect' && !empty($resource['url'])) {
    course_resource_record_open($conn, $userId, $course, $sectionId, $itemId, $resource);
    header('Location: ' . $resource['url'], true, 302);
    exit;
}
if (($resource['action'] ?? '') === 'render') {
    header('Location: ' . rtrim((string) $baseUrl, '/') . '/user/course/' . rawurlencode((string) $course['course_id']) . '?lesson=' . rawurlencode($itemId), true, 302);
    exit;
}
course_resource_notice(404, 'Resource unavailable', $resource['reason'] ?? 'This resource has not been configured yet.', $course['course_id']);
