<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/RevisionPlan.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/StudentResourceGateway.php';

$pageName = 'revision_plans';
$conn = db();
$userId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
$assignmentId = (int) ($assignmentId ?? 0);
$context = $userId > 0 ? mmh_revision_assignment_context($conn, $assignmentId, (int) $userId) : null;
if (!$context) { http_response_code(404); exit('Revision Plan not found.'); }
$assignment = $context['assignment'];
$days = array_values((array) ($context['days'] ?? []));
$progress = mmh_revision_assignment_progress($conn, $assignmentId, (int) $userId);
$progressSummary = mmh_revision_progress_summary($days, $progress);
$progressFlash = $_SESSION['revision_plan_progress_flash'] ?? null;
unset($_SESSION['revision_plan_progress_flash']);

$requestedDay = filter_var($_GET['day'] ?? null, FILTER_VALIDATE_INT);
$dayNumbers = [];
$todayDayNumber = 0;
$firstFutureAccessibleDay = 0;
$lastPreviousAccessibleDay = 0;
foreach ($days as $day) {
    $number = (int) ($day['absolute_day_number'] ?? 0);
    if ($number <= 0) continue;
    $dayNumbers[$number] = true;
    if (($day['availability'] ?? '') === 'today') $todayDayNumber = $number;
    if (!empty($day['accessible'])) {
        if (($day['availability'] ?? '') === 'previous') $lastPreviousAccessibleDay = $number;
        elseif ($firstFutureAccessibleDay === 0) $firstFutureAccessibleDay = $number;
    }
}
$selectedDayNumber = ($requestedDay && isset($dayNumbers[(int) $requestedDay])) ? (int) $requestedDay : ($todayDayNumber ?: ($lastPreviousAccessibleDay ?: ($firstFutureAccessibleDay ?: (int) ($days[0]['absolute_day_number'] ?? 0))));
$selectedDay = null;
foreach ($days as $day) if ((int) ($day['absolute_day_number'] ?? 0) === $selectedDayNumber) { $selectedDay = $day; break; }
if (!is_array($selectedDay) && $days) $selectedDay = $days[0];

$base = rtrim((string) $baseUrl, '/');
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$resources = [];
foreach ((array) ($context['version']['resources'] ?? []) as $resource) $resources[(int) $resource['id']] = $resource;
$resourceUrl = static function (int $resourceId, int $requirementId = 0) use ($base, $assignmentId): string { $url = $base . '/user/revision-resource/' . $assignmentId . '/' . $resourceId; return $requirementId > 0 ? $url . '?requirement=' . $requirementId : $url; };
$itemUrl = static function (string $itemId, int $requirementId) use ($base, $assignmentId, $assignment): string { return mmh_student_resource_url($base, (string) $assignment['course_id'], $itemId, ['revision_assignment_id' => $assignmentId, 'revision_requirement_id' => $requirementId]); };
$completionUrl = $base . '/user/revision-plan/' . $assignmentId . '/requirement/';
$revisionCssPath = dirname(__DIR__, 2) . '/resources/css/revision-plans.css';
$revisionCssVersion = is_file($revisionCssPath) ? (string) filemtime($revisionCssPath) : '1';
$metatags = $metatags ?? ''; $keywords = $keywords ?? ''; $openGraph = $openGraph ?? ''; $schema = $schema ?? '';

$selectedRequirements = [];
if (is_array($selectedDay)) {
    $selectedRequirements = (array) ($selectedDay['requirements'] ?? []);
    foreach ((array) ($selectedDay['activity_groups'] ?? []) as $group) $selectedRequirements = array_merge($selectedRequirements, (array) ($group['requirements'] ?? []));
}
$selectedProgress = $progressSummary['days'][$selectedDayNumber] ?? ['completed' => 0, 'total' => 0];
$selectedAvailable = is_array($selectedDay) && !empty($selectedDay['accessible']);
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $esc($assignment['title']) ?> | Your Plans</title>
    <?php include 'layouts/user/header.php'; ?>
    <link rel="stylesheet" href="<?= $esc($base . '/resources/css/revision-plans.css?v=' . $revisionCssVersion) ?>">
</head>
<body class="body ds-bg-primary revision-plan-workspace" style="margin-top:65px">
<div id="app"><div id="body-overlay"></div><?php include 'layouts/user/aside.php'; ?>
<main class="revision-student-page">
    <header class="revision-student-header">
        <div class="revision-student-header-inner">
            <a class="revision-student-back" href="<?= $esc($base . '/user/revision-plans') ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> Your Plans</a>
            <span class="revision-student-eyebrow"><?= $esc($assignment['course_title']) ?></span>
            <h1><?= $esc($assignment['title']) ?></h1>
            <p>Day <?= $selectedDayNumber ?> of <?= count($days) ?> · <?= is_array($selectedDay) ? $esc(date('l, j M', strtotime((string) $selectedDay['scheduled_date']))) : $esc(date('j M', strtotime((string) $assignment['start_date']))) ?></p>
        </div>
        <div class="container p-0"><div class="col-12 row user-menu"><nav class="navbar navbar-expand-lg navbar-light ds-surface-muted"><div class="container-fluid p-0"><?php include 'layouts/user/main-nav.php'; ?></div></nav></div></div>
    </header>
    <section class="revision-student-shell">
        <?php if (is_array($progressFlash)): ?><div class="revision-progress-flash <?= !empty($progressFlash['ok']) ? 'is-success' : 'is-error' ?>" role="status"><?= $esc($progressFlash['message'] ?? '') ?></div><?php endif; ?>
        <section class="revision-progress-summary" aria-labelledby="revision-progress-title">
            <div class="revision-progress-heading"><span class="revision-student-card-kicker">Overall progress</span><h2 id="revision-progress-title"><?= (int) $progressSummary['percentage'] ?>%</h2></div>
            <div class="revision-progress-meter-wrap"><div class="revision-progress-meter" role="progressbar" aria-label="Overall revision plan progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $progressSummary['percentage'] ?>"><span style="width: <?= (int) $progressSummary['percentage'] ?>%"></span></div><p><?= (int) $progressSummary['completed'] ?> of <?= (int) $progressSummary['total'] ?> tasks completed</p></div>
        </section>
        <nav class="revision-day-nav" aria-label="Revision plan days">
            <?php foreach ($days as $day): $number = (int) ($day['absolute_day_number'] ?? 0); $availability = (string) ($day['availability'] ?? 'upcoming'); $accessible = !empty($day['accessible']); $dayProgress = $progressSummary['days'][$number] ?? ['completed' => 0, 'total' => 0]; $completeDay = $accessible && $dayProgress['total'] > 0 && $dayProgress['completed'] >= $dayProgress['total']; $state = $availability === 'today' ? 'Today' : ($accessible ? ($availability === 'previous' ? 'Previous' : 'Available') : 'Locked'); $icon = !$accessible ? 'fa-lock' : ($completeDay ? 'fa-check' : ($availability === 'today' ? 'fa-circle' : 'fa-calendar-day')); ?>
                <a class="revision-day-tab <?= $number === $selectedDayNumber ? 'is-selected' : '' ?> <?= !$accessible ? 'is-locked' : '' ?>" href="<?= $esc($base . '/user/revision-plan/' . $assignmentId . '?day=' . $number) ?>" <?= $number === $selectedDayNumber ? 'aria-current="page"' : '' ?> title="<?= $esc($state) ?>"><span class="revision-day-tab-icon fas <?= $icon ?>" aria-hidden="true"></span><span>Day <?= $number ?></span><small><?= $esc($state) ?></small></a>
            <?php endforeach; ?>
        </nav>
        <?php if (is_array($selectedDay)): ?>
            <section class="revision-selected-day <?= $selectedAvailable ? '' : 'is-locked' ?>" aria-labelledby="selected-day-title">
                <header class="revision-selected-day-header"><div><span class="revision-student-card-kicker"><?= $esc($selectedDay['availability'] === 'today' ? 'Today' : ($selectedDay['availability'] === 'previous' ? 'Previous' : ($selectedAvailable ? 'Available' : 'Locked'))) ?></span><h2 id="selected-day-title">Day <?= $selectedDayNumber ?></h2><p><?= $esc(date('l, j M Y', strtotime((string) $selectedDay['scheduled_date']))) ?></p></div><?php if ($selectedAvailable && $selectedProgress['total'] > 0): ?><span class="revision-selected-day-progress"><?= (int) $selectedProgress['completed'] ?>/<?= (int) $selectedProgress['total'] ?> complete</span><?php endif; ?></header>
                <?php if (!$selectedAvailable): ?><div class="revision-locked-note"><span class="fas fa-lock" aria-hidden="true"></span><div><strong>Available <?= $esc(date('j M', strtotime((string) $selectedDay['scheduled_date']))) ?></strong><p>This day's tasks will unlock on <?= $esc(date('j M Y', strtotime((string) $selectedDay['scheduled_date']))) ?>.</p></div></div>
                <?php elseif (!$selectedRequirements): ?><div class="revision-student-empty small"><p>No tasks have been added to this day yet.</p></div>
                <?php else: ?><div class="revision-requirement-list">
                    <?php foreach ($selectedRequirements as $requirement): $requirementId = (int) ($requirement['id'] ?? 0); $type = strtolower(trim((string) ($requirement['requirement_type'] ?? ''))); $actionable = mmh_revision_requirement_is_actionable($requirement); $complete = $actionable && isset($progress[$requirementId]); ?>
                        <article class="revision-student-requirement <?= $complete ? 'is-complete' : '' ?>"><div class="revision-task-copy"><div class="revision-task-meta"><span class="revision-requirement-type"><?= $esc($type === 'course_item' ? 'Course content' : ucwords(str_replace('_', ' ', $type))) ?></span><?php if (!$requirement['is_required']): ?><span class="revision-task-optional">Optional</span><?php endif; ?></div><h3><?= $esc($requirement['title']) ?></h3><?php if (($requirement['description'] ?? '') !== ''): ?><p><?= nl2br($esc($requirement['description'])) ?></p><?php endif; ?></div><div class="revision-requirement-action">
                            <?php if ($type === 'course_item' && trim((string) ($requirement['linked_course_item_id'] ?? '')) !== ''): ?><div class="revision-requirement-actions"><a class="student-dashboard-btn secondary" href="<?= $esc($itemUrl((string) $requirement['linked_course_item_id'], $requirementId)) ?>"><span class="fas fa-external-link-alt" aria-hidden="true"></span> Open Course Item</a><form method="post" action="<?= $esc($completionUrl . $requirementId . '/completion') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $complete ? 'undo' : 'complete' ?>"><button type="submit" class="student-dashboard-btn <?= $complete ? 'secondary is-complete-action' : 'primary' ?>"><span class="fas fa-<?= $complete ? 'check-circle' : 'check' ?>" aria-hidden="true"></span> <?= $complete ? 'Completed · Undo' : 'Mark Done' ?></button></form></div>
                            <?php elseif ($type === 'checklist'): ?><form method="post" action="<?= $esc($completionUrl . $requirementId . '/completion') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $complete ? 'undo' : 'complete' ?>"><button type="submit" class="student-dashboard-btn <?= $complete ? 'secondary is-complete-action' : 'primary' ?>"><span class="fas fa-<?= $complete ? 'check-circle' : 'check' ?>" aria-hidden="true"></span> <?= $complete ? 'Completed · Undo' : 'Mark Done' ?></button></form>
                            <?php elseif ($type === 'resource' && !empty($requirement['resource_ids'])): ?><div class="revision-requirement-actions"><div class="revision-resource-links"><?php foreach ((array) $requirement['resource_ids'] as $resourceId): if (!isset($resources[(int) $resourceId])) continue; ?><a class="student-dashboard-btn secondary" href="<?= $esc($resourceUrl((int) $resourceId, $requirementId)) ?>"><span class="fas fa-file-alt" aria-hidden="true"></span><?= $esc($resources[(int) $resourceId]['display_name']) ?></a><?php endforeach; ?></div><form method="post" action="<?= $esc($completionUrl . $requirementId . '/completion') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $complete ? 'undo' : 'complete' ?>"><button type="submit" class="student-dashboard-btn <?= $complete ? 'secondary is-complete-action' : 'primary' ?>"><span class="fas fa-<?= $complete ? 'check-circle' : 'check' ?>" aria-hidden="true"></span> <?= $complete ? 'Completed · Undo' : 'Mark Done' ?></button></form></div>
                            <?php elseif ($type === 'upload'): ?><span class="revision-later-note">Submission will be available here in a later phase.</span><?php else: ?><span class="revision-later-note"><span class="fas fa-info-circle" aria-hidden="true"></span> This task has no completion control yet.</span><?php endif; ?>
                        </div></article>
                    <?php endforeach; ?>
                </div><?php endif; ?>
            </section>
        <?php endif; ?>
    </section>
</main></div>
</body>
</html>
