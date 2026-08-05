<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/ParentWeeklyReport.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/RecoveryPlan.php';
require_once 'inc/TimedExam.php';

$pageName = 'analytics';
$username = (string) ($_SESSION['username'] ?? '');
$userInfo = getUserInfo($username);
$userId = (int) ($userInfo->user_id ?? 0);
$user_id = $userId;
$conn = db();
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$base = rtrim((string) $baseUrl, '/');

$courses = [];
$courseStmt = $conn->prepare("SELECT c.course_id, c.course_title, MAX(l.purchase_date) AS enrolled_at
    FROM course_logs l INNER JOIN courses c ON c.course_id = l.course_id
    WHERE l.user_id = ? AND c.course_state IN ('public', 'private')
    GROUP BY c.id, c.course_id, c.course_title ORDER BY enrolled_at DESC, c.id ASC");
if ($courseStmt) {
    $courseStmt->bind_param('i', $userId); $courseStmt->execute(); $courses = $courseStmt->get_result()->fetch_all(MYSQLI_ASSOC); $courseStmt->close();
}
$courseMap = []; foreach ($courses as $course) { $courseMap[(string) $course['course_id']] = $course; }
$selectedCourseId = trim((string) ($_GET['course'] ?? ($courses[0]['course_id'] ?? '')));
if (!isset($courseMap[$selectedCourseId])) { $selectedCourseId = (string) ($courses[0]['course_id'] ?? ''); }
$selectedCourse = $courseMap[$selectedCourseId] ?? null;
$periodKey = strtolower(trim((string) ($_GET['period'] ?? 'current_week')));
$period = null; $report = null; $summary = null; $error = '';
if ($selectedCourse) {
    try {
        $period = mmh_report_period($conn, $selectedCourseId, $userId, $periodKey, null, null, false);
        $report = mmh_report_resolve($conn, $selectedCourseId, $userId, $period['start'], $period['end']);
        $summary = mmh_report_student_summary($report);
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
}
$courseRecordSummary = $summary;
if ($selectedCourse && !$error) {
    try {
        $courseRecordPeriod = mmh_report_period($conn, $selectedCourseId, $userId, 'course_to_date', null, null, false);
        $courseRecordReport = mmh_report_resolve($conn, $selectedCourseId, $userId, $courseRecordPeriod['start'], $courseRecordPeriod['end']);
        $courseRecordSummary = mmh_report_student_summary($courseRecordReport);
    } catch (Throwable $exception) {
        $courseRecordSummary = $summary;
    }
}
$periodOptions = mmh_report_period_options(false);
$studentRecoveryPlan = null;
if ($selectedCourse) {
    $studentRecoveryPlan = mmh_recovery_plan_resolve($conn, $userId, $selectedCourseId);
}
 $resourceUrl = static function (string $courseId, string $itemId) use ($base, $conn): string {
    $exam = mmh_timed_exam_load_for_item($conn, $courseId, $itemId, false);
    if ($exam) return $base . '/user/course/' . rawurlencode($courseId) . '/exam/' . (int) $exam['id'];
    return $base . '/user/course/resource/' . rawurlencode($courseId) . '/' . rawurlencode($itemId);
};
$statusClass = static function (string $key): string {
    return in_array($key, ['attended_live', 'opened', 'completed', 'graded'], true) ? 'is-success' : (in_array($key, ['missing', 'absent'], true) ? 'is-danger' : (in_array($key, ['awaiting_grading', 'late'], true) ? 'is-warning' : 'is-muted'));
};
$humanDate = static function ($value): string {
    $timestamp = strtotime((string) $value);
    if (!$timestamp) { return ''; }
    $today = strtotime(date('Y-m-d'));
    $date = strtotime(date('Y-m-d', $timestamp));
    $days = (int) floor(($today - $date) / 86400);
    if ($days === 0) { return 'Today'; }
    if ($days === 1) { return 'Yesterday'; }
    if ($days > 1 && $days < 7) { return $days . ' days ago'; }
    return date('j M', $timestamp);
};
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Progress | <?= $escape($site_name ?? 'Math Mastery Hub') ?></title>
    <?php include 'layouts/user/header.php'; ?>
    <style>
      .student-progress-page{padding-bottom:42px}.student-progress-shell{max-width:1120px;margin:0 auto;padding:20px 20px 44px}.student-progress-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:16px}.student-progress-eyebrow{margin:0 0 5px;color:var(--primary);font-size:.72rem;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.student-progress-header h1{margin:0;font-size:clamp(1.55rem,3vw,2rem);color:var(--text-primary)}.student-progress-header p{margin:5px 0 0;color:var(--text-muted);font-size:.9rem}.student-progress-filter{min-width:270px;display:grid;grid-template-columns:1fr 1fr;gap:7px 10px;align-items:center}.student-progress-filter label{font-size:.75rem;font-weight:700;color:var(--text-muted)}.student-progress-filter select{min-width:0}.student-progress-filter label:nth-of-type(2){grid-column:1}.student-progress-filter select:nth-of-type(2){grid-column:2;grid-row:2}.student-progress-period{margin:0 0 14px;color:var(--text-muted);font-size:.8rem}.student-progress-card{padding:16px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface);box-shadow:var(--shadow-sm)}.student-progress-card h2{margin:0;color:var(--text-primary);font-size:1rem}.student-progress-primary{display:grid;grid-template-columns:minmax(0,1.12fr) minmax(280px,.88fr);gap:12px;margin-bottom:12px}.student-progress-overall{display:grid;gap:13px}.student-progress-overall-head{display:flex;justify-content:space-between;align-items:baseline;gap:12px}.student-progress-overall-head strong{color:var(--text-primary);font-size:1.2rem}.student-progress-overall-percent{color:var(--primary);font-size:1.05rem;font-weight:800}.student-progress-bar{height:8px;overflow:hidden;border-radius:999px;background:var(--surface-hover)}.student-progress-bar span{display:block;height:100%;border-radius:inherit;background:var(--primary)}.student-progress-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.student-progress-stat{min-width:0}.student-progress-stat span{display:block;color:var(--text-muted);font-size:.72rem;line-height:1.3}.student-progress-stat strong{display:block;margin-top:3px;color:var(--text-primary);font-size:1rem}.student-progress-focus{display:flex;flex-direction:column;justify-content:space-between;gap:14px;border-left:3px solid var(--primary);background:color-mix(in srgb,var(--primary) 6%,var(--surface))}.student-progress-focus-label{margin:0 0 5px;color:var(--primary);font-size:.72rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase}.student-progress-focus h2{font-size:1.08rem}.student-progress-focus p{margin:5px 0 0;color:var(--text-secondary);font-size:.85rem;line-height:1.4}.student-progress-focus .student-progress-button{align-self:flex-start}.student-progress-button{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:35px;padding:7px 13px;border:1px solid var(--primary);border-radius:var(--radius-sm);background:var(--primary);color:#fff;text-decoration:none;font-size:.8rem;font-weight:750}.student-progress-button:hover,.student-progress-button:focus-visible{background:color-mix(in srgb,var(--primary) 86%,#000);border-color:color-mix(in srgb,var(--primary) 86%,#000);color:#fff;text-decoration:none}.student-progress-button:focus-visible,.student-progress-link:focus-visible{outline:3px solid color-mix(in srgb,var(--primary) 35%,transparent);outline-offset:2px}.student-progress-roadmap{margin-bottom:12px}.student-progress-roadmap-list{display:grid;gap:0;margin:12px 0 0;padding:0;list-style:none}.student-progress-roadmap-item{display:grid;grid-template-columns:25px minmax(0,1fr) auto;align-items:center;gap:8px;min-height:39px;border-top:1px solid var(--border)}.student-progress-roadmap-item:first-child{border-top:0}.student-progress-roadmap-icon{display:grid;place-items:center;width:20px;height:20px;border-radius:50%;background:var(--surface-hover);color:var(--text-muted);font-size:.75rem;font-weight:800}.student-progress-roadmap-item.is-complete .student-progress-roadmap-icon{background:color-mix(in srgb,var(--success) 15%,transparent);color:var(--success)}.student-progress-roadmap-item.is-current .student-progress-roadmap-icon{background:color-mix(in srgb,var(--primary) 15%,transparent);color:var(--primary)}.student-progress-roadmap-title{min-width:0;overflow-wrap:anywhere;color:var(--text-primary);font-size:.87rem;font-weight:700}.student-progress-roadmap-meta{color:var(--text-muted);font-size:.74rem;white-space:nowrap}.student-progress-columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px}.student-progress-list-card{min-width:0}.student-progress-list{display:grid;gap:0;margin:10px 0 0;padding:0;list-style:none}.student-progress-list-item{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--border)}.student-progress-list-item:first-child{border-top:0}.student-progress-list-item strong{display:block;overflow-wrap:anywhere;color:var(--text-primary);font-size:.84rem}.student-progress-list-item small{display:block;margin-top:3px;color:var(--text-muted);font-size:.75rem}.student-progress-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}.student-progress-status{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:.72rem;font-weight:750;white-space:nowrap}.student-progress-status.is-success{background:color-mix(in srgb,var(--success) 14%,transparent);color:var(--success)}.student-progress-status.is-warning{background:color-mix(in srgb,var(--warning) 16%,transparent);color:var(--warning)}.student-progress-status.is-danger{background:color-mix(in srgb,var(--danger) 14%,transparent);color:var(--danger)}.student-progress-status.is-muted{background:var(--surface-hover);color:var(--text-muted)}.student-progress-link{display:inline-flex;align-items:center;justify-content:center;min-height:31px;padding:5px 9px;border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--secondary);font-size:.73rem;font-weight:750;text-decoration:none;white-space:nowrap}.student-progress-link:hover{border-color:var(--secondary);color:var(--secondary);text-decoration:none}.student-progress-empty{padding:18px;border:1px dashed var(--border);border-radius:var(--radius-md);color:var(--text-muted);text-align:center}.student-progress-empty h2{margin:0;color:var(--text-primary);font-size:1rem}.student-progress-empty p{margin:6px 0 12px}.student-progress-warning{margin:10px 0 0;color:var(--warning);font-size:.8rem;font-weight:700}@media(max-width:900px){.student-progress-header{display:block}.student-progress-filter{margin-top:14px;max-width:440px}.student-progress-primary{grid-template-columns:1fr}.student-progress-columns{grid-template-columns:1fr}}@media(max-width:560px){.student-progress-shell{padding:18px 14px 36px}.student-progress-filter{grid-template-columns:1fr}.student-progress-filter label:nth-of-type(2),.student-progress-filter select:nth-of-type(2){grid-column:auto;grid-row:auto}.student-progress-stats{grid-template-columns:1fr 1fr}.student-progress-stat:last-child{grid-column:1/-1}.student-progress-card{padding:14px}.student-progress-button{width:100%}.student-progress-actions{justify-content:flex-start}.student-progress-list-item{grid-template-columns:1fr;align-items:start}.student-progress-link{justify-self:start}.student-progress-roadmap-item{grid-template-columns:25px minmax(0,1fr)}.student-progress-roadmap-meta{grid-column:2;white-space:normal;margin-top:-5px;margin-bottom:7px}}
    </style>
    <style>
      .student-recovery-plan{margin:0 0 12px;padding:16px;border:1px solid color-mix(in srgb,var(--primary) 30%,var(--border));border-radius:var(--radius-md);background:color-mix(in srgb,var(--primary) 6%,var(--surface))}.student-recovery-plan.is-complete{padding:28px}.student-recovery-plan-head,.student-recovery-plan-task{display:flex;align-items:center;justify-content:space-between;gap:12px}.student-recovery-plan-head strong{color:var(--primary);font-size:1rem}.student-recovery-plan-head span{color:var(--text-muted);font-size:.8rem}.student-recovery-plan-task{padding-top:10px;margin-top:10px;border-top:1px solid color-mix(in srgb,var(--primary) 20%,var(--border))}.student-recovery-plan-task div{min-width:0}.student-recovery-plan-task strong{display:block;overflow-wrap:anywhere;color:var(--text-primary);font-size:.86rem}.student-recovery-plan-task small{display:block;margin-top:3px;color:var(--text-muted);font-size:.75rem}.student-recovery-plan-list{display:grid;gap:5px;margin:11px 0 0;padding:9px 0 0;border-top:1px solid color-mix(in srgb,var(--primary) 20%,var(--border));list-style:none}.student-recovery-plan-list li{display:grid;grid-template-columns:20px minmax(0,1fr) auto;gap:6px;align-items:center;color:var(--text-secondary);font-size:.77rem}.student-recovery-plan-list li>span:first-child{color:var(--success);font-weight:800}.student-recovery-plan-list small{color:var(--text-muted);font-size:.7rem}.student-recovery-plan-complete{display:flex;align-items:center;gap:9px;color:var(--success);font-weight:800}.student-recovery-plan-complete small{display:block;color:var(--text-muted);font-size:.75rem;font-weight:500}.student-recovery-plan.is-complete .student-recovery-plan-complete{justify-content:center;text-align:center;flex-direction:column}.student-recovery-plan.is-complete .student-recovery-plan-complete>span{font-size:2rem}.student-recovery-plan.is-complete .student-recovery-plan-complete .student-progress-button{min-width:180px}@media(max-width:560px){.student-recovery-plan-task{display:block}.student-recovery-plan-task .student-progress-button{width:100%;margin-top:9px}.student-recovery-plan-complete{align-items:flex-start;flex-wrap:wrap}.student-recovery-plan-complete .student-progress-button{width:100%}}
    </style>
    <style>
      .student-progress-page.has-recovery-plan .student-recovery-plan,
      .student-progress-page.has-recovery-plan .student-progress-primary,
      .student-progress-page.has-recovery-plan .student-progress-roadmap,
      .student-progress-page.has-recovery-plan .student-progress-columns { display:none; }
      .recovery-progress-hero{display:grid;gap:16px;margin:0 0 14px;padding:22px;border:1px solid color-mix(in srgb,var(--primary) 34%,var(--border));border-radius:var(--radius-lg);background:color-mix(in srgb,var(--primary) 7%,var(--surface));box-shadow:var(--shadow-sm)}
      .recovery-progress-hero-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}.recovery-progress-hero h2{margin:0;color:var(--text-primary);font-size:clamp(1.15rem,2.6vw,1.55rem)}.recovery-progress-hero-note{margin:6px 0 0;max-width:680px;color:var(--text-secondary);line-height:1.55}.recovery-progress-hero-stat{color:var(--text-secondary);font-weight:800;font-size:.88rem;white-space:nowrap}.recovery-progress-hero-stat strong{color:var(--primary);font-size:1.2rem}.recovery-progress-track{height:10px;overflow:hidden;border-radius:999px;background:var(--surface-hover)}.recovery-progress-track span{display:block;height:100%;border-radius:inherit;background:var(--primary)}.recovery-progress-current{display:grid;gap:4px;padding:14px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface)}.recovery-progress-kicker{color:var(--primary);font-size:.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase}.recovery-progress-current strong{color:var(--text-primary);font-size:1rem}.recovery-progress-current small{color:var(--text-muted)}.recovery-progress-meta{display:flex;gap:18px;flex-wrap:wrap;color:var(--text-secondary);font-size:.82rem}.recovery-progress-hero-actions{display:flex;gap:10px;flex-wrap:wrap}.recovery-progress-hero-actions .student-progress-button{min-height:44px;padding-inline:18px;font-size:.9rem}.recovery-task-preview{display:grid;gap:5px;margin:0;padding:0;list-style:none}.recovery-task-preview li{display:grid;grid-template-columns:24px minmax(0,1fr) auto;gap:8px;align-items:center;padding:8px 0;border-top:1px solid var(--border);color:var(--text-secondary);font-size:.82rem}.recovery-task-preview li:first-child{border-top:0}.recovery-task-preview .task-state{font-weight:900;text-align:center}.recovery-task-preview .is-complete{color:var(--success)}.recovery-task-preview .is-current{color:var(--primary)}.recovery-task-preview .is-locked{color:var(--text-muted)}.recovery-task-preview small{color:var(--text-muted)}.recovery-progress-complete{text-align:center}.recovery-progress-complete .fas{font-size:2.3rem;color:var(--success)}.recovery-progress-complete h2{margin:8px 0 4px}.recovery-progress-complete p{margin:0;color:var(--text-secondary)}.recovery-progress-section{margin:0 0 14px}.recovery-progress-section h2{margin:0;font-size:1rem}.recovery-progress-section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:9px}.recovery-progress-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.recovery-progress-summary-item{padding:13px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface)}.recovery-progress-summary-item span{display:block;color:var(--text-muted);font-size:.75rem}.recovery-progress-summary-item strong{display:block;margin-top:4px;color:var(--text-primary);font-size:1.1rem}.recovery-roadmap-toggle{display:inline-flex;align-items:center;gap:6px;color:var(--primary);font-weight:800;font-size:.8rem;text-decoration:none}.recovery-roadmap-toggle:hover{color:var(--primary);text-decoration:underline}.recovery-roadmap-details{padding:14px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface)}.recovery-roadmap-details summary{cursor:pointer;color:var(--primary);font-weight:800}.recovery-roadmap-list{display:grid;gap:0;margin:12px 0 0;padding:0;list-style:none}.recovery-roadmap-list li{display:grid;grid-template-columns:24px minmax(0,1fr) auto;gap:8px;align-items:center;padding:9px 0;border-top:1px solid var(--border);color:var(--text-secondary);font-size:.82rem}.recovery-roadmap-list li:first-child{border-top:0}.recovery-roadmap-list .roadmap-icon{font-weight:900}.recovery-roadmap-list .roadmap-status{color:var(--text-muted);font-size:.74rem}.recovery-attention-list{margin:0;padding:0;list-style:none}.recovery-attention-list li{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid var(--border);color:var(--text-secondary);font-size:.82rem}.recovery-attention-list li:first-child{border-top:0}.recovery-attention-list strong{display:block;color:var(--text-primary)}.recovery-attention-list small{display:block;margin-top:2px;color:var(--text-muted)}@media(max-width:700px){.recovery-progress-hero{padding:17px}.recovery-progress-summary-grid{grid-template-columns:1fr}.recovery-progress-hero-actions{display:grid}.recovery-progress-hero-actions .student-progress-button{width:100%}.recovery-task-preview li{grid-template-columns:24px minmax(0,1fr);align-items:start}.recovery-task-preview small{grid-column:2}}
      @media(min-width:701px) and (max-width:1100px){.recovery-progress-summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
    </style>
    <style>
      .recovery-progress-summary-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.missing-coursework-list{display:grid;gap:0}.missing-coursework-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-top:1px solid var(--border)}.missing-coursework-item:first-child{border-top:0}.missing-coursework-item h3{margin:0;color:var(--text-primary);font-size:.88rem}.missing-coursework-item p{margin:4px 0 0;color:var(--text-muted);font-size:.78rem}@media(max-width:700px){.recovery-progress-summary-grid{grid-template-columns:1fr}.missing-coursework-item{display:block}.missing-coursework-item .student-progress-link{margin-top:9px}}
    </style>
</head>
<body class="body ds-bg-primary student-progress-page<?= is_array($studentRecoveryPlan) && in_array((string) ($studentRecoveryPlan['status'] ?? ''), ['active', 'completed'], true) ? ' has-recovery-plan' : '' ?>">
<div id="app"><div id="body-overlay" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></div><?php include 'layouts/user/aside.php'; ?><main class="font-2">
    <div class="student-analytics-navigation border-lg-top"><div class="container px-2"><nav class="navbar navbar-expand-lg navbar-light ds-surface-muted"><div class="container-fluid p-0"><button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"><span class="fas fa-bars"></span></button><?php include 'layouts/user/main-nav.php'; ?></div></nav></div></div>
    <section class="student-progress-shell"><header class="student-progress-header"><div><p class="student-progress-eyebrow"><span class="fas fa-chart-line" aria-hidden="true"></span> Learning progress</p><h1>My Progress</h1><p>See where you are and what to do next.</p></div><?php if ($courses): ?><form method="get" action="<?= $escape($base . '/user/analytics') ?>" class="student-progress-filter"><label for="student-progress-course">Course</label><select id="student-progress-course" name="course" class="form-select" onchange="this.form.submit()"><?php foreach ($courses as $course): ?><option value="<?= $escape($course['course_id']) ?>" <?= (string) $course['course_id'] === $selectedCourseId ? 'selected' : '' ?>><?= $escape($course['course_title']) ?></option><?php endforeach ?></select><label for="student-progress-period">Reporting period</label><select id="student-progress-period" name="period" class="form-select" onchange="this.form.submit()"><?php foreach ($periodOptions as $key => $label): ?><option value="<?= $escape($key) ?>" <?= $period && $period['key'] === $key ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach ?></select></form><?php endif; ?></header>
    <?php if (!$selectedCourse): ?><div class="student-progress-empty"><h2>No enrolled course yet</h2><p>Enroll in a course to see your learning progress.</p><a class="student-progress-button" href="<?= $escape($base . '/user/courses') ?>">Browse courses</a></div>
    <?php elseif ($error): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div>
    <?php elseif ($summary): ?>
      <?php
      $journey = $summary['learning_journey'] ?? [];
      $journeyPercent = $journey['percentage'] ?? null;
      $journeyTotal = (int) ($journey['total'] ?? 0);
      $journeyCompleted = (int) ($journey['completed'] ?? 0);
      $journeyItems = $journey['items'] ?? [];
      $lessonItems = array_values(array_filter($journeyItems, static fn(array $item): bool => ($item['item_kind'] ?? '') === 'lesson'));
      $lessonCompleted = count(array_filter($lessonItems, static fn(array $item): bool => !empty($item['is_completed'])));
      $lessonTotal = count($lessonItems);
      $counts = $summary['counts'] ?? [];
      $recordCounts = $courseRecordSummary['counts'] ?? $counts;
      $focus = null;
      foreach ($summary['sections'] ?? [] as $section) {
          foreach ($section['homework'] ?? [] as $homework) {
              if (($homework['status']['key'] ?? '') === 'missing' && ($homework['item_id'] ?? '') !== '' && !mmh_recovery_plan_covers_item($studentRecoveryPlan, (string) $homework['item_id'], (string) ($homework['section_id'] ?? ''))) { $focus = ['title' => 'Complete Homework', 'detail' => $homework['title'] ?: 'Finish your outstanding homework.', 'href' => $resourceUrl($selectedCourseId, (string) $homework['item_id']), 'icon' => 'clipboard-check']; break 2; }
          }
      }
      if ($focus === null) foreach ($summary['sections'] ?? [] as $section) {
          foreach ($section['recordings'] ?? [] as $recording) {
              if (in_array(($recording['status']['key'] ?? ''), ['not_viewed', 'opened'], true) && ($recording['item_id'] ?? '') !== '') { $focus = ['title' => 'Continue Recording', 'detail' => $recording['title'] ?: 'Continue your next recording.', 'href' => $resourceUrl($selectedCourseId, (string) $recording['item_id']), 'icon' => 'play-circle']; break 2; }
          }
      }
      if ($focus === null) foreach ($journeyItems as $journeyItem) {
          if (empty($journeyItem['is_completed']) && ($journeyItem['item_id'] ?? '') !== '') {
              $kind = (string) ($journeyItem['item_kind'] ?? 'lesson');
              $focus = ['title' => $kind === 'recording' ? 'Continue Recording' : ($kind === 'homework' ? 'Complete Homework' : 'Continue Lesson'), 'detail' => (string) ($journeyItem['item_title'] ?? 'Continue your course.'), 'href' => $resourceUrl($selectedCourseId, (string) $journeyItem['item_id']), 'icon' => $kind === 'recording' ? 'play-circle' : ($kind === 'homework' ? 'clipboard-check' : 'book-open')];
              break;
          }
      }
      $roadmapSections = [];
      $currentFound = false;
      foreach ($journey['sections'] ?? [] as $roadmapSection) {
          $items = $roadmapSection['items'] ?? []; $liveSessions = $roadmapSection['live_sessions'] ?? [];
          $total = count($items) + count($liveSessions); $completed = count(array_merge(array_filter($items, static fn(array $item): bool => !empty($item['is_completed'])), array_filter($liveSessions, static fn(array $item): bool => !empty($item['is_completed']))));
          $status = 'upcoming';
          if (!$currentFound && $total > 0 && $completed < $total) { $status = 'current'; $currentFound = true; } elseif ($total > 0 && $completed === $total) { $status = 'complete'; }
          $roadmapSections[] = ['title' => (string) ($roadmapSection['title'] ?? 'Section'), 'status' => $status, 'completed' => $completed, 'total' => $total];
      }
      if (!$currentFound && $roadmapSections) { foreach ($roadmapSections as &$roadmapSection) { if ($roadmapSection['status'] === 'upcoming') { $roadmapSection['status'] = 'current'; break; } } unset($roadmapSection); }
      $recentRows = [];
      foreach ($summary['sections'] ?? [] as $section) {
          $row = null;
          foreach ($section['homework'] ?? [] as $homework) { if (($homework['item_id'] ?? '') !== '') { $row = ['title' => $homework['title'] ?: 'Homework', 'section' => $section['title'], 'date' => $section['date'], 'status' => $homework['status'], 'href' => $resourceUrl($selectedCourseId, (string) $homework['item_id']), 'action' => 'Open Homework']; break; } }
          if ($row === null) foreach ($section['recordings'] ?? [] as $recording) { if (($recording['item_id'] ?? '') !== '') { $row = ['title' => $recording['title'] ?: 'Recording', 'section' => $section['title'], 'date' => $section['date'], 'status' => $recording['status'], 'href' => $resourceUrl($selectedCourseId, (string) $recording['item_id']), 'action' => 'Open Recording']; break; } }
          if ($row !== null) { $recentRows[] = $row; }
      }
      $recentRows = array_slice(array_reverse($recentRows), 0, 3);
      $upcomingRows = [];
      foreach ($summary['weak_points'] ?? [] as $point) { $action = $point['actions'][0] ?? null; if (!$action || ($action['item_id'] ?? '') === '' || mmh_recovery_plan_covers_item($studentRecoveryPlan, (string) $action['item_id'], (string) ($point['section_id'] ?? ''))) { continue; } $upcomingRows[] = ['title' => (string) ($point['section_title'] ?? 'Course work'), 'detail' => implode(' · ', $point['reasons'] ?? []), 'href' => $resourceUrl($selectedCourseId, (string) $action['item_id']), 'action' => (string) ($action['label'] ?? 'Open')]; if (count($upcomingRows) >= 3) { break; } }
      $hasRecoveryPlan = is_array($studentRecoveryPlan) && in_array((string) ($studentRecoveryPlan['status'] ?? ''), ['active', 'completed'], true);
      $recoveryIsComplete = $hasRecoveryPlan && (string) ($studentRecoveryPlan['status'] ?? '') === 'completed';
      $recoveryCurrent = null;
      if ($hasRecoveryPlan && !$recoveryIsComplete) {
          foreach (($studentRecoveryPlan['items'] ?? []) as $candidateTask) { if (!empty($candidateTask['is_required']) && empty($candidateTask['is_completed']) && empty($candidateTask['is_locked'])) { $recoveryCurrent = $candidateTask; break; } }
          if (!$recoveryCurrent) foreach (($studentRecoveryPlan['items'] ?? []) as $candidateTask) { if (empty($candidateTask['is_completed']) && empty($candidateTask['is_locked'])) { $recoveryCurrent = $candidateTask; break; } }
      }
      $recoveryRequiredTotal = (int) ($studentRecoveryPlan['required_total'] ?? 0);
      $recoveryRequiredCompleted = (int) ($studentRecoveryPlan['required_completed'] ?? 0);
      $recoveryRemainingMinutes = 0;
      $recoveryPreview = [];
      if ($hasRecoveryPlan) {
          foreach (($studentRecoveryPlan['items'] ?? []) as $taskIndex => $candidateTask) {
              if ($recoveryCurrent && (int) ($candidateTask['id'] ?? 0) === (int) ($recoveryCurrent['id'] ?? 0)) {
                  for ($previewIndex = $taskIndex; $previewIndex < count($studentRecoveryPlan['items']); $previewIndex++) { $recoveryPreview[] = $studentRecoveryPlan['items'][$previewIndex]; if (count($recoveryPreview) >= 3) break; }
                  break;
              }
          }
          foreach (($studentRecoveryPlan['items'] ?? []) as $candidateTask) {
              if (!empty($candidateTask['is_required']) && empty($candidateTask['is_completed']) && is_numeric($candidateTask['estimated_duration'] ?? null)) $recoveryRemainingMinutes += (int) $candidateTask['estimated_duration'];
          }
      }
      $recoveryRemainingLabel = $recoveryRemainingMinutes > 0 ? (int) floor($recoveryRemainingMinutes / 60) . ' hr' . (($recoveryRemainingMinutes % 60) ? ' ' . ($recoveryRemainingMinutes % 60) . ' min' : '') : '';
      $missingHomework = [];
      $optionalPreviousPractice = [];
      $missingHomeworkGroups = [];
      $optionalPracticeGroups = [];
      $missedLiveNeedingCatchup = 0;
      $recordingCatchupOpened = 0;
      $recordingCatchupTotal = 0;
      foreach ($courseRecordSummary['sections'] ?? [] as $sectionIndex => $section) {
          $sectionId = (string) ($section['section_id'] ?? '');
          $sectionTitle = trim((string) ($section['title'] ?? '')) ?: 'Course section';
          $topicTitle = preg_replace('/^\s*(?:lecture|lesson|section)\s*\d+\s*[-:–—]?\s*/i', '', $sectionTitle);
          $topicTitle = trim((string) $topicTitle) ?: $sectionTitle;
          $sectionNumber = '';
          if (preg_match('/\b(?:lecture|lesson|section)\s*(\d+)\b/i', $sectionTitle, $sectionMatch)) {
              $sectionNumber = (string) $sectionMatch[1];
          } else {
              $sectionNumber = (string) ((int) $sectionIndex + 1);
          }
          foreach ($section['homework'] ?? [] as $homework) {
              if (($homework['status']['key'] ?? '') !== 'missing' || ($homework['item_id'] ?? '') === '') continue;
              $homeworkRow = ['section_id' => $sectionId, 'section_number' => $sectionNumber, 'section_title' => $sectionTitle, 'topic_title' => $topicTitle, 'title' => (string) ($homework['title'] ?? 'Homework'), 'item_id' => (string) $homework['item_id']];
              if ($hasRecoveryPlan && mmh_recovery_plan_covers_item($studentRecoveryPlan, (string) $homework['item_id'], $sectionId)) {
                  $optionalPreviousPractice[] = $homeworkRow;
              } else {
                  $missingHomework[] = $homeworkRow;
              }
          }
          $attendanceKey = (string) ($section['attendance']['key'] ?? '');
          if (!$section['is_workshop'] && $attendanceKey === 'absent') {
              $sectionHasCatchup = false;
              foreach ($section['recordings'] ?? [] as $recording) {
                  if (($recording['status']['key'] ?? '') === 'not_required') continue;
                  $recordingCatchupTotal++;
                  if (in_array(($recording['status']['key'] ?? ''), ['opened', 'completed'], true)) $recordingCatchupOpened++;
                  if (($recording['status']['key'] ?? '') === 'not_viewed') $sectionHasCatchup = true;
              }
              if ($sectionHasCatchup) $missedLiveNeedingCatchup++;
          }
      }
      foreach ($missingHomework as $homeworkRow) {
          $groupKey = $homeworkRow['section_id'] !== '' ? $homeworkRow['section_id'] : $homeworkRow['section_number'];
          $missingHomeworkGroups[$groupKey] ??= ['section_number' => $homeworkRow['section_number'], 'topic_title' => $homeworkRow['topic_title'], 'items' => []];
          $missingHomeworkGroups[$groupKey]['items'][] = $homeworkRow;
      }
      foreach ($optionalPreviousPractice as $homeworkRow) {
          $groupKey = $homeworkRow['section_id'] !== '' ? $homeworkRow['section_id'] : $homeworkRow['section_number'];
          $optionalPracticeGroups[$groupKey] ??= ['section_number' => $homeworkRow['section_number'], 'topic_title' => $homeworkRow['topic_title'], 'items' => []];
          $optionalPracticeGroups[$groupKey]['items'][] = $homeworkRow;
      }
      $recoveryWorkspaceUrl = $hasRecoveryPlan ? mmh_recovery_plan_workspace_url($base, $selectedCourseId, (int) ($studentRecoveryPlan['id'] ?? 0), $recoveryCurrent ? (int) ($recoveryCurrent['id'] ?? 0) : null) : '';
      ?>
      <p class="student-progress-period"><strong><?= $escape($selectedCourse['course_title']) ?></strong> · <?= $escape($period['label']) ?> · <?= $escape($humanDate($period['start'])) ?>–<?= $escape($humanDate($period['end'])) ?></p>
      <?php if ($hasRecoveryPlan): ?>
      <section class="recovery-progress-hero" aria-labelledby="recovery-progress-title">
        <?php if ($recoveryIsComplete): ?><div class="recovery-progress-complete"><span class="fas fa-check-circle" aria-hidden="true"></span><h2 id="recovery-progress-title">Recovery Plan complete</h2><p>You completed your Recovery Plan. Your teacher considers the mapped topics covered through the study plan.</p><div class="recovery-progress-hero-actions" style="justify-content:center"><a class="student-progress-button" href="<?= $escape($base . '/user/course/' . rawurlencode($selectedCourseId)) ?>">Continue Main Course</a><a class="student-progress-link" href="<?= $escape(mmh_recovery_plan_workspace_url($base, $selectedCourseId, (int) ($studentRecoveryPlan['id'] ?? 0))) ?>">View full plan</a></div></div>
        <?php else: ?><div class="recovery-progress-hero-head"><div><p class="student-progress-eyebrow">Priority learning path</p><h2 id="recovery-progress-title"><?= $escape($studentRecoveryPlan['title'] ?? 'Recovery Plan') ?></h2><p class="recovery-progress-hero-note"><?= $escape($recoveryCurrent['teacher_note'] ?? 'Follow the teacher-selected tasks in order. Your existing course history stays unchanged.') ?></p></div><div class="recovery-progress-hero-stat"><strong><?= $recoveryRequiredCompleted ?></strong> of <?= $recoveryRequiredTotal ?> required tasks completed</div></div><div class="recovery-progress-track" role="progressbar" aria-label="Recovery Plan progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $recoveryRequiredTotal ? (int) round(($recoveryRequiredCompleted / $recoveryRequiredTotal) * 100) : 0 ?>"><span style="width:<?= $recoveryRequiredTotal ? (int) round(($recoveryRequiredCompleted / $recoveryRequiredTotal) * 100) : 0 ?>%"></span></div><?php if ($recoveryCurrent): ?><div class="recovery-progress-current"><span class="recovery-progress-kicker">Current task</span><strong><?= $escape($recoveryCurrent['item_title'] ?? 'Next recovery task') ?></strong><small><?= $escape(mmh_recovery_plan_item_label($recoveryCurrent)) ?><?php if (!empty($recoveryCurrent['section_title'])): ?> · <?= $escape($recoveryCurrent['section_title']) ?><?php endif; ?></small><?php if ($recoveryRemainingLabel !== ''): ?><div class="recovery-progress-meta"><span>Estimated remaining: <strong><?= $escape($recoveryRemainingLabel) ?></strong></span></div><?php endif; ?></div><div class="recovery-progress-hero-actions"><a class="student-progress-button" href="<?= $escape($recoveryWorkspaceUrl) ?>"><span class="fas fa-play-circle" aria-hidden="true"></span> Continue Recovery Plan</a></div><?php else: ?><div class="recovery-progress-current"><strong>All accessible tasks are complete.</strong><small>Open the plan to review the completed journey.</small></div><div class="recovery-progress-hero-actions"><a class="student-progress-button" href="<?= $escape(mmh_recovery_plan_workspace_url($base, $selectedCourseId, (int) ($studentRecoveryPlan['id'] ?? 0))) ?>">Open Recovery Plan</a></div><?php endif; ?><ul class="recovery-task-preview" aria-label="Recovery Plan task preview"><?php foreach ($recoveryPreview as $previewTask): $previewState = !empty($previewTask['is_completed']) ? 'is-complete' : (!empty($previewTask['is_locked']) ? 'is-locked' : ((int) ($previewTask['id'] ?? 0) === (int) ($recoveryCurrent['id'] ?? 0) ? 'is-current' : '')); ?><li class="<?= $escape($previewState) ?>"><span class="task-state" aria-hidden="true"><?php if (!empty($previewTask['is_completed'])): ?><span class="fas fa-check" aria-hidden="true"></span><?php elseif (!empty($previewTask['is_locked'])): ?><span class="fas fa-lock" aria-hidden="true"></span><?php elseif ((int) ($previewTask['id'] ?? 0) === (int) ($recoveryCurrent['id'] ?? 0)): ?><span class="fas fa-play" aria-hidden="true"></span><?php else: ?><span class="far fa-circle" aria-hidden="true"></span><?php endif; ?></span><span><?= $escape($previewTask['item_title'] ?? 'Recovery task') ?></span><small><?= !empty($previewTask['is_completed']) ? 'Completed' : (!empty($previewTask['is_locked']) ? 'Locked' : (!empty($previewTask['is_required']) ? 'Required' : 'Optional')) ?></small></li><?php endforeach; ?></ul><a class="student-progress-link" href="<?= $escape(mmh_recovery_plan_workspace_url($base, $selectedCourseId, (int) ($studentRecoveryPlan['id'] ?? 0), $recoveryCurrent ? (int) ($recoveryCurrent['id'] ?? 0) : null)) ?>">View full plan</a><?php endif; ?>
      </section>
      <section class="recovery-progress-section" aria-labelledby="coursework-record-title"><div class="recovery-progress-section-head"><h2 id="coursework-record-title">Coursework Record</h2><span class="student-progress-period" style="margin:0">Course to date</span></div><div class="recovery-progress-summary-grid"><div class="recovery-progress-summary-item"><span>Homework submitted</span><strong><?= (int) ($recordCounts['homework_submitted'] ?? 0) ?> / <?= (int) ($recordCounts['homework_total'] ?? 0) ?></strong></div><div class="recovery-progress-summary-item"><span>Live sessions attended</span><strong><?= (int) ($recordCounts['live_attended'] ?? 0) ?> / <?= (int) ($recordCounts['live_total'] ?? 0) ?></strong></div><div class="recovery-progress-summary-item"><span>Missed live sessions needing recording catch-up</span><strong><?= $missedLiveNeedingCatchup ?></strong></div><div class="recovery-progress-summary-item"><span>Recordings opened after missed live sessions</span><strong><?= $recordingCatchupOpened ?> / <?= $recordingCatchupTotal ?></strong></div></div></section>
      <section class="recovery-progress-section recovery-progress-card" aria-labelledby="missing-coursework-title"><div class="recovery-progress-section-head"><h2 id="missing-coursework-title">Missing Coursework</h2></div><?php if (!$missingHomeworkGroups): ?><p class="student-progress-empty">No missing homework in the course to date.</p><?php else: ?><div class="missing-coursework-list"><?php foreach ($missingHomeworkGroups as $missingGroup): ?><article class="missing-coursework-item"><div><h3><?= $escape('Lecture ' . $missingGroup['section_number'] . ' — ' . $missingGroup['topic_title']) ?></h3><?php foreach ($missingGroup['items'] as $missing): ?><p>Homework not submitted · <?= $escape($missing['title']) ?> · <a href="<?= $escape($resourceUrl($selectedCourseId, $missing['item_id'])) ?>">Open Homework</a></p><?php endforeach; ?></div></article><?php endforeach; ?></div><?php endif; ?></section>
      <?php if ($optionalPracticeGroups): ?><section class="recovery-progress-section recovery-progress-card" aria-labelledby="optional-practice-title"><div class="recovery-progress-section-head"><h2 id="optional-practice-title">Optional previous practice — covered through your Recovery Plan</h2></div><div class="missing-coursework-list"><?php foreach ($optionalPracticeGroups as $optionalGroup): ?><article class="missing-coursework-item"><div><h3><?= $escape('Lecture ' . $optionalGroup['section_number'] . ' — ' . $optionalGroup['topic_title']) ?></h3><?php foreach ($optionalGroup['items'] as $optional): ?><p>Homework not submitted · optional review · <a href="<?= $escape($resourceUrl($selectedCourseId, $optional['item_id'])) ?>">Open Homework</a></p><?php endforeach; ?></div></article><?php endforeach; ?></div></section><?php endif; ?>
      <?php if ($recentRows): ?><section class="recovery-progress-section recovery-progress-card" aria-labelledby="recent-activity-title"><div class="recovery-progress-section-head"><h2 id="recent-activity-title">Recent Activity</h2></div><ul class="student-progress-list"><?php foreach ($recentRows as $row): ?><li class="student-progress-list-item"><div><strong><?= $escape($row['title']) ?></strong><small><?= $escape($row['section']) ?><?= ($date = $humanDate($row['date'])) !== '' ? ' · ' . $escape($date) : '' ?></small></div><?php if ($row['href'] !== ''): ?><a class="student-progress-link" href="<?= $escape($row['href']) ?>"><?= $escape($row['action']) ?></a><?php endif; ?></li><?php endforeach; ?></ul></section><?php endif; ?>
      <?php else: ?>
      <section class="student-progress-primary" aria-label="Progress summary">
        <article class="student-progress-card student-progress-overall"><div class="student-progress-overall-head"><h2>Overall Progress</h2><strong class="student-progress-overall-percent"><?= $journeyPercent === null ? '—' : $escape((string) $journeyPercent . '%') ?></strong></div><div class="student-progress-bar" role="progressbar" aria-label="Overall progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $journeyPercent === null ? '0' : (int) $journeyPercent ?>"><span style="width:<?= $journeyPercent === null ? 0 : (int) $journeyPercent ?>%"></span></div><div class="student-progress-stats"><div class="student-progress-stat"><span>Lessons completed</span><strong><?= $lessonCompleted ?> / <?= $lessonTotal ?></strong></div><div class="student-progress-stat"><span>Homework completed</span><strong><?= (int) ($counts['homework_submitted'] ?? 0) ?> / <?= (int) ($counts['homework_total'] ?? 0) ?></strong></div><?php if ((int) ($counts['live_total'] ?? 0) > 0): ?><div class="student-progress-stat"><span>Live sessions attended</span><strong><?= (int) ($counts['live_attended'] ?? 0) ?> / <?= (int) ($counts['live_total'] ?? 0) ?></strong></div><?php endif; ?></div><?php if ((int) ($counts['homework_missing'] ?? 0) > 0): ?><p class="student-progress-warning"><span class="fas fa-exclamation-circle" aria-hidden="true"></span> <?= (int) $counts['homework_missing'] ?> homework item<?= (int) $counts['homework_missing'] === 1 ? '' : 's' ?> still need attention.</p><?php endif; ?></article>
        <article class="student-progress-card student-progress-focus" aria-labelledby="student-progress-focus-title"><div><p class="student-progress-focus-label">Today's Focus</p><h2 id="student-progress-focus-title"><?= $escape($focus['title'] ?? 'You are up to date') ?></h2><p><?= $escape($focus['detail'] ?? 'There is no outstanding action in this period.') ?></p></div><?php if ($focus): ?><a class="student-progress-button" href="<?= $escape($focus['href']) ?>"><span class="fas fa-<?= $escape($focus['icon']) ?>" aria-hidden="true"></span><?= $escape($focus['title']) ?></a><?php endif; ?></article>
      </section>
      <?php if ($roadmapSections): ?><section class="student-progress-card student-progress-roadmap" aria-labelledby="student-progress-roadmap-title"><h2 id="student-progress-roadmap-title">Current Section</h2><ul class="student-progress-roadmap-list"><?php foreach ($roadmapSections as $roadmapSection): ?><li class="student-progress-roadmap-item is-<?= $escape($roadmapSection['status']) ?>"><span class="student-progress-roadmap-icon" aria-hidden="true"><?php if ($roadmapSection['status'] === 'complete'): ?><span class="fas fa-check" aria-hidden="true"></span><?php elseif ($roadmapSection['status'] === 'current'): ?><span class="fas fa-play" aria-hidden="true"></span><?php else: ?><span class="far fa-circle" aria-hidden="true"></span><?php endif; ?></span><span class="student-progress-roadmap-title"><?= $escape($roadmapSection['title']) ?></span><span class="student-progress-roadmap-meta"><?= $roadmapSection['status'] === 'complete' ? 'Completed' : ($roadmapSection['status'] === 'current' ? 'You are here' : 'Upcoming') ?><?= $roadmapSection['total'] > 0 ? ' · ' . (int) $roadmapSection['completed'] . '/' . (int) $roadmapSection['total'] : '' ?></span></li><?php endforeach; ?></ul></section><?php endif; ?>
      <?php if ($recentRows || $upcomingRows): ?><div class="student-progress-columns"><?php if ($recentRows): ?><section class="student-progress-card student-progress-list-card" aria-labelledby="student-progress-recent-title"><h2 id="student-progress-recent-title">Recent Activity</h2><ul class="student-progress-list"><?php foreach ($recentRows as $row): ?><li class="student-progress-list-item"><div><strong><?= $escape($row['title']) ?></strong><small><?= $escape($row['section']) ?><?= ($date = $humanDate($row['date'])) !== '' ? ' · ' . $escape($date) : '' ?></small></div><span class="student-progress-actions"><span class="student-progress-status <?= $escape($statusClass((string) ($row['status']['key'] ?? ''))) ?>"><?= $escape($row['status']['label'] ?? 'In progress') ?></span><?php if ($row['href'] !== ''): ?><a class="student-progress-link" href="<?= $escape($row['href']) ?>"><?= $escape($row['action']) ?></a><?php endif; ?></span></li><?php endforeach; ?></ul></section><?php endif; ?><?php if ($upcomingRows): ?><section class="student-progress-card student-progress-list-card" aria-labelledby="student-progress-upcoming-title"><h2 id="student-progress-upcoming-title">Upcoming</h2><ul class="student-progress-list"><?php foreach ($upcomingRows as $row): ?><li class="student-progress-list-item"><div><strong><?= $escape($row['title']) ?></strong><small><?= $escape($row['detail']) ?></small></div><a class="student-progress-link" href="<?= $escape($row['href']) ?>"><?= $escape($row['action']) ?></a></li><?php endforeach; ?></ul></section><?php endif; ?></div><?php endif; ?>
      <?php if (!$recentRows && !$upcomingRows && !$roadmapSections): ?><div class="student-progress-empty"><h2>Your progress will appear here</h2><p>Complete a course item to start building your learning journey.</p></div><?php endif; ?>
      <?php endif; ?>
    <?php endif; ?></section>
</main><?php include 'layouts/user/footer.php'; ?></div>
</body></html>
