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
$unreleasedBatches = array_values((array) ($assignment['version']['unreleased_batches'] ?? []));
$unreleasedDayCount = 0;
foreach ($unreleasedBatches as $batch) $unreleasedDayCount += count((array) ($batch['days'] ?? []));
$totalPlannedDays = count($days) + $unreleasedDayCount;
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
$selectedBatchId = is_array($selectedDay) ? (int) ($selectedDay['batch_id'] ?? 0) : 0;
$batchMaterials = [];
$batchMaterialRequirementIds = [];
if ($selectedBatchId > 0) {
    foreach ($resources as $resource) {
        if ((int) ($resource['batch_id'] ?? 0) === $selectedBatchId) $batchMaterials[(int) $resource['id']] = $resource;
    }
}
foreach ($selectedRequirements as $requirement) {
    foreach ((array) ($requirement['resource_ids'] ?? []) as $resourceId) {
        $resourceId = (int) $resourceId;
        if ($resourceId > 0 && isset($resources[$resourceId])) { $batchMaterials[$resourceId] = $resources[$resourceId]; if (!isset($batchMaterialRequirementIds[$resourceId])) $batchMaterialRequirementIds[$resourceId] = (int) ($requirement['id'] ?? 0); }
    }
}
if ($selectedBatchId > 0) {
    foreach ($days as $batchDay) {
        if ((int) ($batchDay['batch_id'] ?? 0) !== $selectedBatchId) continue;
        $batchDayRequirements = (array) ($batchDay['requirements'] ?? []);
        foreach ((array) ($batchDay['activity_groups'] ?? []) as $group) $batchDayRequirements = array_merge($batchDayRequirements, (array) ($group['requirements'] ?? []));
        foreach ($batchDayRequirements as $requirement) foreach ((array) ($requirement['resource_ids'] ?? []) as $resourceId) {
            $resourceId = (int) $resourceId;
            if ($resourceId > 0 && isset($resources[$resourceId])) { $batchMaterials[$resourceId] = $resources[$resourceId]; if (!isset($batchMaterialRequirementIds[$resourceId])) $batchMaterialRequirementIds[$resourceId] = (int) ($requirement['id'] ?? 0); }
        }
    }
}
$uploadSubmissionFiles = [];
foreach ($selectedRequirements as $uploadRequirement) {
    if (strtolower(trim((string) ($uploadRequirement['requirement_type'] ?? ''))) !== 'upload') continue;
    $uploadRequirementId = (int) ($uploadRequirement['id'] ?? 0);
    if ($uploadRequirementId <= 0) continue;
    $uploadSubmission = mmh_revision_requirement_submission($conn, $assignmentId, (int) $userId, $uploadRequirementId);
    $uploadSubmissionFiles[(string) $uploadRequirementId] = [
        'submitted' => is_array($uploadSubmission),
        'files' => is_array($uploadSubmission) ? array_values(array_map(static fn(array $file): string => (string) ($file['original_filename'] ?? 'PDF file'), (array) ($uploadSubmission['files'] ?? []))) : [],
    ];
}
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
            <p>Day <?= $selectedDayNumber ?> of <?= max(count($days), $totalPlannedDays) ?> · <?= is_array($selectedDay) ? $esc(date('l, j M', strtotime((string) $selectedDay['scheduled_date']))) : $esc(date('j M', strtotime((string) $assignment['start_date']))) ?></p>
        </div>
        <div class="container p-0"><div class="col-12 row user-menu"><nav class="navbar navbar-expand-lg navbar-light ds-surface-muted"><div class="container-fluid p-0"><?php include 'layouts/user/main-nav.php'; ?></div></nav></div></div>
    </header>
    <section class="revision-student-shell">
        <?php if (is_array($progressFlash)): ?><div class="revision-progress-flash <?= !empty($progressFlash['ok']) ? 'is-success' : 'is-error' ?>" role="status"><?= $esc($progressFlash['message'] ?? '') ?></div><?php endif; ?>
        <section class="revision-progress-summary" aria-labelledby="revision-progress-title">
            <div class="revision-progress-heading"><span class="revision-student-card-kicker">Overall progress</span><h2 id="revision-progress-title"><?= (int) $progressSummary['percentage'] ?>%</h2></div>
            <div class="revision-progress-meter-wrap"><div class="revision-progress-meter" role="progressbar" aria-label="Overall revision plan progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $progressSummary['percentage'] ?>"><span style="width: <?= (int) $progressSummary['percentage'] ?>%"></span></div><p><?= (int) $progressSummary['completed'] ?> of <?= (int) $progressSummary['total'] ?> tasks completed</p></div>
        </section>
        <?php
        $releasedBatchSummaries = [];
        foreach ($days as $day) {
            $batchKey = (int) ($day['batch_id'] ?? 0);
            if (!isset($releasedBatchSummaries[$batchKey])) $releasedBatchSummaries[$batchKey] = ['title' => (string) ($day['batch_title'] ?? 'Batch'), 'days' => 0, 'completed' => 0, 'total' => 0, 'first_day' => (int) ($day['absolute_day_number'] ?? 0)];
            $releasedBatchSummaries[$batchKey]['days']++;
            $dayNumber = (int) ($day['absolute_day_number'] ?? 0);
            $batchDayProgress = $progressSummary['days'][$dayNumber] ?? ['completed' => 0, 'total' => 0];
            $releasedBatchSummaries[$batchKey]['completed'] += (int) ($batchDayProgress['completed'] ?? 0);
            $releasedBatchSummaries[$batchKey]['total'] += (int) ($batchDayProgress['total'] ?? 0);
        }
        ?>
        <?php if ($releasedBatchSummaries || $unreleasedBatches): ?><section class="revision-batch-navigation" aria-labelledby="revision-batches-title"><div class="revision-batch-navigation-heading"><span class="revision-student-card-kicker" id="revision-batches-title">Batches</span><span class="revision-help">Your plan is released in stages.</span></div><div class="revision-batch-strip"><?php $batchNumber = 0; foreach ($releasedBatchSummaries as $batchId => $batchSummary): $batchNumber++; $batchHref = $base . '/user/revision-plan/' . $assignmentId . '?day=' . (int) $batchSummary['first_day']; $batchIsSelected = $batchId === $selectedBatchId; ?><a class="revision-batch-summary <?= $batchIsSelected ? 'is-active' : '' ?>" href="<?= $esc($batchHref) ?>" <?= $batchIsSelected ? 'aria-current="page"' : '' ?>><span class="revision-student-card-kicker">Batch <?= $batchNumber ?></span><strong><?= $esc($batchSummary['title'] ?: 'Batch ' . $batchNumber) ?></strong><small>Active · <?= (int) $batchSummary['days'] ?> <?= $batchSummary['days'] === 1 ? 'day' : 'days' ?><?php if ((int) $batchSummary['total'] > 0): ?> · <?= (int) $batchSummary['completed'] ?>/<?= (int) $batchSummary['total'] ?> tasks<?php endif; ?></small></a><?php endforeach; ?><?php foreach ($unreleasedBatches as $batch): $batchNumber++; ?><div class="revision-batch-summary is-locked"><span class="revision-student-card-kicker">Batch <?= $batchNumber ?></span><strong><?= $esc($batch['title'] ?? 'Upcoming Batch') ?></strong><small>Coming soon · Content will appear when released. More revision content is on the way.</small></div><?php endforeach; ?></div></section><?php endif; ?>
        <nav class="revision-day-nav" aria-label="Revision plan days">
            <?php foreach ($days as $day): $number = (int) ($day['absolute_day_number'] ?? 0); $availability = (string) ($day['availability'] ?? 'upcoming'); $accessible = !empty($day['accessible']); $dayProgress = $progressSummary['days'][$number] ?? ['completed' => 0, 'total' => 0]; $completeDay = $accessible && $dayProgress['total'] > 0 && $dayProgress['completed'] >= $dayProgress['total']; $state = $availability === 'today' ? 'Today' : ($availability === 'previous' ? 'Previous' : ($availability === 'upcoming' ? 'Upcoming' : 'Locked')); $stateClass = !$accessible ? 'is-locked' : ($completeDay ? 'is-complete' : ($availability === 'today' ? 'is-today' : 'is-neutral')); ?>
                <a class="revision-day-tab <?= $number === $selectedDayNumber ? 'is-selected' : '' ?> <?= !$accessible ? 'is-locked' : '' ?>" href="<?= $esc($base . '/user/revision-plan/' . $assignmentId . '?day=' . $number) ?>" <?= $number === $selectedDayNumber ? 'aria-current="page"' : '' ?> title="<?= $esc($state) ?>"><span class="revision-day-state <?= $stateClass ?>" aria-hidden="true"></span><span>Day <?= $number ?></span><small><?= $esc($state) ?></small></a>
            <?php endforeach; ?>
        </nav>
        <?php if (is_array($selectedDay)): ?>
            <section class="revision-selected-day <?= $selectedAvailable ? '' : 'is-locked' ?>" aria-labelledby="selected-day-title">
                <header class="revision-selected-day-header"><div><span class="revision-student-card-kicker"><?= $esc($selectedDay['availability'] === 'today' ? 'Today' : ($selectedDay['availability'] === 'previous' ? 'Previous' : ($selectedDay['availability'] === 'upcoming' ? 'Upcoming' : 'Locked'))) ?></span><h2 id="selected-day-title">Day <?= $selectedDayNumber ?></h2><p><?= $esc(date('l, j M Y', strtotime((string) $selectedDay['scheduled_date']))) ?></p></div><?php if ($selectedAvailable && $selectedProgress['total'] > 0): ?><span class="revision-selected-day-progress"><?= (int) $selectedProgress['completed'] ?>/<?= (int) $selectedProgress['total'] ?> complete</span><?php endif; ?></header>
                <?php if (!$selectedAvailable): ?><div class="revision-locked-note"><span class="revision-day-state is-locked" aria-hidden="true"></span><div><strong>Available <?= $esc(date('j M', strtotime((string) $selectedDay['scheduled_date']))) ?></strong><p>This day's tasks will unlock on <?= $esc(date('j M Y', strtotime((string) $selectedDay['scheduled_date']))) ?>.</p></div></div>
                <?php elseif (!$selectedRequirements): ?><?php if ($batchMaterials): ?><section class="revision-batch-materials" aria-label="Batch materials"><div><span class="revision-student-card-kicker">Batch materials</span><strong><?= $esc($selectedDay['batch_title'] ?? 'This batch') ?></strong></div><div class="revision-resource-links"><?php foreach ($batchMaterials as $material): $materialId = (int) $material['id']; $materialRequirementId = (int) ($batchMaterialRequirementIds[$materialId] ?? 0); ?><a class="student-dashboard-btn secondary" href="<?= $esc($resourceUrl($materialId, $materialRequirementId)) ?>"><span class="fas fa-file-alt" aria-hidden="true"></span><?= $esc($material['display_name']) ?></a><?php endforeach; ?></div></section><?php endif; ?><div class="revision-student-empty small"><p>No tasks have been added to this day yet.</p></div>
                <?php else: ?><?php if ($batchMaterials): ?><section class="revision-batch-materials" aria-label="Batch materials"><div><span class="revision-student-card-kicker">Batch materials</span><strong><?= $esc($selectedDay['batch_title'] ?? 'This batch') ?></strong></div><div class="revision-resource-links"><?php foreach ($batchMaterials as $material): $materialId = (int) $material['id']; $materialRequirementId = (int) ($batchMaterialRequirementIds[$materialId] ?? 0); ?><a class="student-dashboard-btn secondary" href="<?= $esc($resourceUrl($materialId, $materialRequirementId)) ?>"><span class="fas fa-file-alt" aria-hidden="true"></span><?= $esc($material['display_name']) ?></a><?php endforeach; ?></div></section><?php endif; ?><div class="revision-requirement-list">
                    <?php foreach ($selectedRequirements as $requirement): $requirementId = (int) ($requirement['id'] ?? 0); $type = strtolower(trim((string) ($requirement['requirement_type'] ?? ''))); $actionable = mmh_revision_requirement_is_actionable($requirement); $complete = $actionable && isset($progress[$requirementId]); ?>
                        <article class="revision-student-requirement <?= $complete ? 'is-complete' : '' ?>"><div class="revision-task-copy"><div class="revision-task-meta"><span class="revision-requirement-type"><?= $esc($type === 'course_item' ? 'Course content' : ucwords(str_replace('_', ' ', $type))) ?></span><?php if (!$requirement['is_required']): ?><span class="revision-task-optional">Optional</span><?php endif; ?></div><h3><?= $esc($requirement['title']) ?></h3><?php if (($requirement['description'] ?? '') !== ''): ?><p><?= nl2br($esc($requirement['description'])) ?></p><?php endif; ?></div><div class="revision-requirement-action">
                            <?php if ($type === 'course_item' && trim((string) ($requirement['linked_course_item_id'] ?? '')) !== ''): ?><div class="revision-requirement-actions"><a class="student-dashboard-btn secondary" href="<?= $esc($itemUrl((string) $requirement['linked_course_item_id'], $requirementId)) ?>"><span class="fas fa-external-link-alt" aria-hidden="true"></span> Open content</a><form method="post" action="<?= $esc($completionUrl . $requirementId . '/completion') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $complete ? 'undo' : 'complete' ?>"><button type="submit" class="student-dashboard-btn <?= $complete ? 'secondary is-complete-action' : 'primary' ?>"><span class="fas fa-<?= $complete ? 'check-circle' : 'check' ?>" aria-hidden="true"></span> <?= $complete ? 'Completed · Undo' : 'Mark Done' ?></button></form></div>
                            <?php elseif ($type === 'checklist'): ?><form method="post" action="<?= $esc($completionUrl . $requirementId . '/completion') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $complete ? 'undo' : 'complete' ?>"><button type="submit" class="student-dashboard-btn <?= $complete ? 'secondary is-complete-action' : 'primary' ?>"><span class="fas fa-<?= $complete ? 'check-circle' : 'check' ?>" aria-hidden="true"></span> <?= $complete ? 'Completed · Undo' : 'Mark Done' ?></button></form>
                            <?php elseif ($type === 'resource' && !empty($requirement['resource_ids'])): ?><div class="revision-requirement-actions"><div class="revision-resource-links"><?php foreach ((array) $requirement['resource_ids'] as $resourceId): if (!isset($resources[(int) $resourceId])) continue; ?><a class="student-dashboard-btn secondary" href="<?= $esc($resourceUrl((int) $resourceId, $requirementId)) ?>"><span class="fas fa-file-alt" aria-hidden="true"></span><?= $esc($resources[(int) $resourceId]['display_name']) ?></a><?php endforeach; ?></div><form method="post" action="<?= $esc($completionUrl . $requirementId . '/completion') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $complete ? 'undo' : 'complete' ?>"><button type="submit" class="student-dashboard-btn <?= $complete ? 'secondary is-complete-action' : 'primary' ?>"><span class="fas fa-<?= $complete ? 'check-circle' : 'check' ?>" aria-hidden="true"></span> <?= $complete ? 'Completed · Undo' : 'Mark Done' ?></button></form></div>
                            <?php elseif ($type === 'upload'): ?><?php $submission = mmh_revision_requirement_submission($conn, $assignmentId, (int) $userId, $requirementId); ?><div class="revision-upload-control"><?php if (!empty($requirement['resource_ids'])): ?><div class="revision-resource-links"><?php foreach ((array) $requirement['resource_ids'] as $resourceId): if (!isset($resources[(int) $resourceId])) continue; ?><a class="student-dashboard-btn secondary" href="<?= $esc($resourceUrl((int) $resourceId, $requirementId)) ?>"><span class="fas fa-file-alt" aria-hidden="true"></span><?= $esc($resources[(int) $resourceId]['display_name']) ?></a><?php endforeach; ?></div><?php endif; ?><form method="post" enctype="multipart/form-data" action="<?= $esc($base . '/user/revision-plan/' . $assignmentId . '/requirement/' . $requirementId . '/upload') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><label class="revision-upload-label"><span><?= $submission ? 'Replace answer PDF' : 'Upload answer PDF' ?></span><input type="file" name="revision_files[]" accept="application/pdf,.pdf" multiple required></label><button type="submit" class="student-dashboard-btn <?= $submission ? 'secondary' : 'primary' ?>"><span class="fas fa-upload" aria-hidden="true"></span><?= $submission ? 'Replace upload' : 'Upload answer' ?></button><?php if ($submission): ?><small class="revision-upload-status"><?= count((array) ($submission['files'] ?? [])) ?> PDF<?= count((array) ($submission['files'] ?? [])) === 1 ? '' : 's' ?> uploaded · <?= $esc(date('j M Y', strtotime((string) $submission['submitted_at']))) ?></small><?php endif; ?></form></div><?php else: ?><span class="revision-later-note"><span class="fas fa-info-circle" aria-hidden="true"></span> This task has no completion control yet.</span><?php endif; ?>
                        </div></article>
                    <?php endforeach; ?>
                </div><?php endif; ?>
            </section>
        <?php endif; ?>
    </section>
</main></div>
<script>
(function(){
    'use strict';
    var submissionState = <?= json_encode($uploadSubmissionFiles, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    document.querySelectorAll('.revision-upload-control form[enctype="multipart/form-data"]').forEach(function(form){
        var input = form.querySelector('input[type="file"]');
        var control = form.closest('.revision-upload-control');
        if (!input || !control) return;
        var match = (form.getAttribute('action') || '').match(/\/requirement\/(\d+)\/upload/);
        var requirementId = match ? match[1] : '';
        var state = submissionState[requirementId] || {submitted:false, files:[]};
        var label = input.closest('.revision-upload-label');
        var submit = form.querySelector('button[type="submit"]');
        var zone = document.createElement('div');
        zone.className = 'revision-upload-dropzone';
        zone.setAttribute('role', 'group');
        zone.innerHTML = '<strong>Upload PDF files</strong><span>Drag files here or choose from your device</span><button type="button" class="student-dashboard-btn secondary revision-upload-choose"><span class="fas fa-folder-open" aria-hidden="true"></span>Choose PDF files</button><div class="revision-upload-selected" aria-live="polite"></div>';
        if (label) { zone.appendChild(input); label.replaceWith(zone); }
        var choose = zone.querySelector('.revision-upload-choose');
        var selected = zone.querySelector('.revision-upload-selected');
        var files = [];
        var replace = null;
        function formatSize(bytes){ if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB'; return (bytes / (1024 * 1024)).toFixed(1) + ' MB'; }
        function syncInput(){
            if (!window.DataTransfer) return;
            var transfer = new DataTransfer();
            files.forEach(function(file){ transfer.items.add(file); });
            input.files = transfer.files;
        }
        function renderFiles(){
            selected.innerHTML = '';
            if (!files.length) { selected.textContent = 'No files selected'; submit.disabled = true; submit.classList.remove('primary'); return; }
            var heading = document.createElement('strong'); heading.className = 'revision-upload-selected-count'; heading.textContent = files.length + (files.length === 1 ? ' PDF selected' : ' PDFs selected'); selected.appendChild(heading);
            files.forEach(function(file, index){
                var row = document.createElement('div'); row.className = 'revision-upload-file';
                row.innerHTML = '<span class="revision-upload-file-icon" aria-hidden="true"><span class="fas fa-file-pdf"></span></span><span class="revision-upload-file-name"></span><small></small><button type="button" class="revision-upload-remove" aria-label="Remove ' + String(file.name).replace(/"/g,'&quot;') + '">Remove</button>';
                row.querySelector('.revision-upload-file-name').textContent = file.name;
                row.querySelector('small').textContent = formatSize(file.size);
                row.querySelector('.revision-upload-remove').addEventListener('click', function(){ files.splice(index, 1); syncInput(); renderFiles(); });
                selected.appendChild(row);
            });
            submit.disabled = false; submit.classList.add('primary');
        }
        function accept(list){
            var incoming = Array.from(list || []).filter(function(file){ return file && (file.type === 'application/pdf' || /\.pdf$/i.test(file.name)); });
            incoming.forEach(function(file){ if (!files.some(function(existing){ return existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified; })) files.push(file); });
            if (files.length > 10) files = files.slice(0, 10);
            syncInput(); renderFiles();
        }
        choose.addEventListener('click', function(){ input.click(); });
        input.addEventListener('change', function(){ accept(input.files); });
        ['dragenter','dragover'].forEach(function(eventName){ zone.addEventListener(eventName, function(event){ event.preventDefault(); zone.classList.add('is-dragging'); }); });
        ['dragleave','drop'].forEach(function(eventName){ zone.addEventListener(eventName, function(event){ event.preventDefault(); zone.classList.remove('is-dragging'); if (eventName === 'drop') accept(event.dataTransfer.files); }); });
        if (state.submitted && state.files.length) {
            var submitted = document.createElement('div'); submitted.className = 'revision-upload-submitted';
            submitted.innerHTML = '<strong><span class="revision-upload-check" aria-hidden="true">✓</span> Submitted</strong><span>Submitted files</span><ul></ul><button type="button" class="student-dashboard-btn secondary revision-upload-replace">Replace submission</button>';
            var list = submitted.querySelector('ul'); state.files.forEach(function(name){ var li = document.createElement('li'); li.textContent = name; list.appendChild(li); });
            control.insertBefore(submitted, form);
            replace = submitted.querySelector('.revision-upload-replace');
            replace.addEventListener('click', function(){ submitted.hidden = true; form.hidden = false; input.focus(); });
            form.hidden = true;
        }
        form.addEventListener('submit', function(event){ if (!files.length) { event.preventDefault(); return; } submit.disabled = true; submit.classList.add('is-uploading'); submit.innerHTML = '<span class="fas fa-spinner fa-spin" aria-hidden="true"></span> Uploading…'; });
        renderFiles();
    });
})();
</script>
</body>
</html>
