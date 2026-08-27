<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/RevisionPlan.php';
require_once 'inc/StudentCourseAccess.php';

$pageName = 'revision_plans';
$conn = db();
$userId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
$assignmentError = null;
$assignments = $userId ? mmh_revision_student_assignments($conn, (int) $userId, $assignmentError) : [];
$base = rtrim((string) $baseUrl, '/');
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$metatags = $metatags ?? ''; $keywords = $keywords ?? ''; $openGraph = $openGraph ?? ''; $schema = $schema ?? '';
?>
<!doctype html><html lang="en" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Your Plans | <?= $esc($site_name ?? 'Math Mastery Hub') ?></title><?php include 'layouts/user/header.php'; ?><link rel="stylesheet" href="<?= $esc($base . '/resources/css/revision-plans.css') ?>"></head>
<body class="body ds-bg-primary revision-plan-list-page" style="margin-top:65px"><div id="app"><div id="body-overlay"></div><?php include 'layouts/user/aside.php'; ?><main class="revision-student-page"><header class="revision-student-header"><div class="container"><span class="revision-student-eyebrow">Personal study paths</span><h1>Your Plans</h1><p>Your assigned revision plans and study schedules.</p></div><div class="container p-0"><div class="col-12 row user-menu"><nav class="navbar navbar-expand-lg navbar-light ds-surface-muted"><div class="container-fluid p-0"><?php include 'layouts/user/main-nav.php'; ?></div></nav></div></div></header><section class="revision-student-shell container"><div class="revision-student-grid"><?php if ($assignmentError): ?><div class="revision-student-empty" role="alert"><span class="fas fa-exclamation-circle" aria-hidden="true"></span><h2>Plans are temporarily unavailable</h2><p>Please try again in a moment.</p></div><?php elseif (!$assignments): ?><div class="revision-student-empty"><span class="fas fa-route" aria-hidden="true"></span><h2>No plans assigned yet</h2><p>Your teacher will add a revision plan here when one is ready.</p><a class="student-dashboard-btn secondary" href="<?= $esc($base . '/user/my-courses') ?>">Return to My Courses</a></div><?php else: ?><?php foreach ($assignments as $assignment): $scheduleState = (string) ($assignment['schedule_state'] ?? 'active'); $planUrl = $base . '/user/revision-plan/' . (int) $assignment['id']; ?><a class="revision-student-card revision-student-card-link" href="<?= $esc($planUrl) ?>" aria-label="<?= $esc('Open ' . $assignment['title']) ?>"><div><span class="revision-student-card-kicker"><?= $esc($assignment['course_title']) ?></span><h2><?= $esc($assignment['title']) ?></h2><p><span class="revision-day-status"><?= $esc(ucfirst($scheduleState)) ?></span> · <?= $scheduleState === 'upcoming' ? 'Starts' : 'Started' ?> <?= $esc(date('j M Y', strtotime((string) $assignment['start_date']))) ?></p></div><span class="student-dashboard-btn primary" aria-hidden="true"><?= $scheduleState === 'upcoming' ? 'View Plan' : 'Open Plan' ?> <span aria-hidden="true">→</span></span></a><?php endforeach; ?><?php endif; ?></div></section></main></div></body></html>
