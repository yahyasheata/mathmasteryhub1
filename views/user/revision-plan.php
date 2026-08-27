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
$days = $context['days'];
$progress = mmh_revision_assignment_progress($conn, $assignmentId, (int) $userId);
$progressSummary = mmh_revision_progress_summary($days, $progress);
$progressFlash = $_SESSION['revision_plan_progress_flash'] ?? null;
unset($_SESSION['revision_plan_progress_flash']);
$base = rtrim((string) $baseUrl, '/');
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$resources = [];
foreach ((array) ($context['version']['resources'] ?? []) as $resource) $resources[(int) $resource['id']] = $resource;
$resourceUrl = static function (int $resourceId, int $requirementId = 0) use ($base, $assignmentId): string { $url = $base . '/user/revision-resource/' . $assignmentId . '/' . $resourceId; return $requirementId > 0 ? $url . '?requirement=' . $requirementId : $url; };
$itemUrl = static function (string $itemId, int $requirementId) use ($base, $assignmentId, $assignment): string { return mmh_student_resource_url($base, (string) $assignment['course_id'], $itemId, ['revision_assignment_id' => $assignmentId, 'revision_requirement_id' => $requirementId]); };
$completionUrl = $base . '/user/revision-plan/' . $assignmentId . '/requirement/';
$metatags = $metatags ?? ''; $keywords = $keywords ?? ''; $openGraph = $openGraph ?? ''; $schema = $schema ?? '';
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $esc($assignment['title']) ?> | Your Plans</title>
    <?php include 'layouts/user/header.php'; ?>
    <link rel="stylesheet" href="<?= $esc($base . '/resources/css/revision-plans.css') ?>">
</head>
<body class="body ds-bg-primary" style="margin-top:65px">
<div id="app"><div id="body-overlay"></div><?php include 'layouts/user/aside.php'; ?><main class="revision-student-page">
    <header class="revision-student-header">
        <div class="container">
            <a class="revision-student-back" href="<?= $esc($base . '/user/revision-plans') ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> Your Plans</a>
            <span class="revision-student-eyebrow"><?= $esc($assignment['course_title']) ?></span>
            <h1><?= $esc($assignment['title']) ?></h1>
            <p><?= count($days) ?>-day revision plan · Started <?= $esc(date('j M Y', strtotime((string) $assignment['start_date']))) ?>.</p>
        </div>
        <div class="container p-0"><div class="col-12 row user-menu"><nav class="navbar navbar-expand-lg navbar-light ds-surface-muted"><div class="container-fluid p-0"><?php include 'layouts/user/main-nav.php'; ?></div></nav></div></div>
    </header>
    <section class="revision-student-shell container">
        <?php if (is_array($progressFlash)): ?><div class="revision-progress-flash <?= !empty($progressFlash['ok']) ? 'is-success' : 'is-error' ?>" role="status"><?= $esc($progressFlash['message'] ?? '') ?></div><?php endif; ?>
        <section class="revision-progress-summary" aria-labelledby="revision-progress-title">
            <div><span class="revision-student-card-kicker">Progress</span><h2 id="revision-progress-title">Revision Plan Progress</h2><p><?= (int) $progressSummary['completed'] ?> of <?= (int) $progressSummary['total'] ?> actionable requirements completed</p></div>
            <strong><?= (int) $progressSummary['percentage'] ?>%</strong>
        </section>
        <div class="revision-plan-timeline">
            <aside class="revision-day-list" aria-label="Plan days"><h2>Plan timeline</h2>
                <?php foreach ($days as $day): $dayNumber = (int) ($day['absolute_day_number'] ?? 0); $dayProgress = $progressSummary['days'][$dayNumber] ?? ['completed' => 0, 'total' => 0]; ?>
                    <a class="revision-day-link <?= $day['availability'] === 'today' ? 'is-today' : '' ?> <?= !$day['accessible'] ? 'is-locked' : '' ?>" href="#day-<?= $dayNumber ?>">
                        <span>Day <?= $dayNumber ?></span><small><?= $esc($day['availability'] === 'today' ? 'Today' : ucfirst($day['availability'])) ?><?php if ($day['accessible'] && $dayProgress['total'] > 0): ?> · <?= (int) $dayProgress['completed'] ?>/<?= (int) $dayProgress['total'] ?><?php endif; ?> · <?= $esc($day['batch_title']) ?></small>
                    </a>
                <?php endforeach; ?>
            </aside>
            <div class="revision-day-content">
                <?php foreach ($days as $day):
                    $requirements = (array) ($day['requirements'] ?? []);
                    foreach ((array) ($day['activity_groups'] ?? []) as $group) $requirements = array_merge($requirements, (array) ($group['requirements'] ?? []));
                    $dayNumber = (int) ($day['absolute_day_number'] ?? 0);
                    $dayProgress = $progressSummary['days'][$dayNumber] ?? ['completed' => 0, 'total' => 0];
                ?>
                    <section class="revision-day-panel <?= $day['availability'] === 'today' ? 'is-today' : '' ?> <?= !$day['accessible'] ? 'is-locked' : '' ?>" id="day-<?= $dayNumber ?>">
                        <header><div><span class="revision-student-card-kicker"><?= $esc($day['batch_title']) ?></span><h2>Day <?= $dayNumber ?></h2><p><?= $esc(date('l, j M Y', strtotime((string) $day['scheduled_date']))) ?></p></div><span class="revision-day-status"><?= $esc($day['availability'] === 'today' ? 'Today' : ucfirst($day['availability'])) ?><?php if ($day['accessible'] && $dayProgress['total'] > 0): ?> · <?= (int) $dayProgress['completed'] ?>/<?= (int) $dayProgress['total'] ?><?php endif; ?></span></header>
                        <?php if (!$day['accessible']): ?><div class="revision-locked-note"><span class="fas fa-lock" aria-hidden="true"></span> This day becomes available on <?= $esc(date('j M Y', strtotime((string) $day['scheduled_date']))) ?>.</div>
                        <?php else: ?><div class="revision-requirement-list">
                            <?php foreach ($requirements as $requirement):
                                $requirementId = (int) ($requirement['id'] ?? 0);
                                $type = strtolower(trim((string) ($requirement['requirement_type'] ?? '')));
                                $actionable = mmh_revision_requirement_is_actionable($requirement);
                                $complete = $actionable && isset($progress[$requirementId]);
                            ?>
                                <article class="revision-student-requirement <?= $complete ? 'is-complete' : '' ?>">
                                    <div><span class="revision-requirement-type"><?= $esc($type === 'course_item' ? 'Course Content' : ucwords(str_replace('_', ' ', $type))) ?></span><h3><?= $esc($requirement['title']) ?></h3><?php if (($requirement['description'] ?? '') !== ''): ?><p><?= nl2br($esc($requirement['description'])) ?></p><?php endif; ?><?php if (!$requirement['is_required']): ?><small>Optional</small><?php endif; ?></div>
                                    <div class="revision-requirement-action">
                                        <?php if ($type === 'course_item' && trim((string) ($requirement['linked_course_item_id'] ?? '')) !== ''): ?>
                                            <div class="revision-requirement-actions"><a class="student-dashboard-btn primary" href="<?= $esc($itemUrl((string) $requirement['linked_course_item_id'], $requirementId)) ?>"><span class="fas fa-external-link-alt" aria-hidden="true"></span> Open Course Item</a><form method="post" action="<?= $esc($completionUrl . $requirementId . '/completion') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $complete ? 'undo' : 'complete' ?>"><button type="submit" class="student-dashboard-btn <?= $complete ? 'secondary' : 'primary' ?>"><span class="fas fa-<?= $complete ? 'check-circle' : 'check' ?>" aria-hidden="true"></span> <?= $complete ? 'Completed · Undo' : 'Mark Done' ?></button></form></div>
                                        <?php elseif ($type === 'checklist'): ?>
                                            <form method="post" action="<?= $esc($completionUrl . $requirementId . '/completion') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $complete ? 'undo' : 'complete' ?>"><button type="submit" class="student-dashboard-btn <?= $complete ? 'secondary' : 'primary' ?>"><span class="fas fa-<?= $complete ? 'check-circle' : 'check' ?>" aria-hidden="true"></span> <?= $complete ? 'Completed · Undo' : 'Mark Done' ?></button></form>
                                        <?php elseif ($type === 'resource' && !empty($requirement['resource_ids'])): ?><div class="revision-requirement-actions"><div class="revision-resource-links"><?php foreach ((array) $requirement['resource_ids'] as $resourceId): if (!isset($resources[(int) $resourceId])) continue; ?><a class="student-dashboard-btn secondary" href="<?= $esc($resourceUrl((int) $resourceId, $requirementId)) ?>"><span class="fas fa-file-alt" aria-hidden="true"></span><?= $esc($resources[(int) $resourceId]['display_name']) ?></a><?php endforeach; ?></div><form method="post" action="<?= $esc($completionUrl . $requirementId . '/completion') ?>"><input type="hidden" name="csrf_token" value="<?= $esc(mmh_auth_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $complete ? 'undo' : 'complete' ?>"><button type="submit" class="student-dashboard-btn <?= $complete ? 'secondary' : 'primary' ?>"><span class="fas fa-<?= $complete ? 'check-circle' : 'check' ?>" aria-hidden="true"></span> <?= $complete ? 'Completed · Undo' : 'Mark Done' ?></button></form></div>
                                        <?php elseif ($type === 'upload'): ?><span class="revision-later-note">Submission will be available here in a later phase.</span><?php else: ?><span class="revision-later-note"><span class="fas fa-info-circle" aria-hidden="true"></span> This task has no completion control yet.</span><?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                            <?php if (!$requirements): ?><div class="revision-student-empty small"><p>No requirements have been added to this day yet.</p></div><?php endif; ?>
                        </div><?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main></div>
</body>
</html>
