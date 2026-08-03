<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/StudentCourseCsrf.php';
require_once 'inc/TimedExam.php';
require_once 'inc/RecoveryPlan.php';

$conn = db();
$courseId = student_course_access_identifier($courseId ?? '', 40);
$examId = (int) ($examId ?? 0);
$studentId = student_course_access_student_id($conn, $_SESSION['username'] ?? '');
$course = $courseId !== null ? student_course_access_course($conn, $courseId) : null;
$exam = $course && $studentId ? mmh_timed_exam_load($conn, (string) $course['course_id'], $examId, false) : null;
if (!$course || !$exam || !$studentId || !student_course_access_enrolled($conn, $studentId, (string) $course['course_id'])) {
    http_response_code(403);
    exit('Exam unavailable.');
}
$recoveryPlanId = (int) ($_GET['recovery_plan'] ?? 0);
$recoveryTaskId = (int) ($_GET['recovery_task'] ?? 0);
if ($recoveryPlanId > 0 || $recoveryTaskId > 0) {
    $plan = ($recoveryPlanId > 0) ? mmh_recovery_plan_load($conn, (int) $studentId, (string) $course['course_id'], $recoveryPlanId) : null;
    $task = $plan && $recoveryTaskId > 0 ? mmh_recovery_plan_task_context($plan, $recoveryTaskId)['current'] : null;
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $recoveryStart = !empty($exam['recovery_window_start_at_utc']) ? new DateTimeImmutable((string) $exam['recovery_window_start_at_utc'], new DateTimeZone('UTC')) : null;
    $recoveryEnd = !empty($exam['recovery_window_end_at_utc']) ? new DateTimeImmutable((string) $exam['recovery_window_end_at_utc'], new DateTimeZone('UTC')) : null;
    if (!$plan || !$task || (string) ($task['item_id'] ?? '') !== (string) ($exam['item_id'] ?? '') || empty($exam['recovery_allowed']) || !$recoveryStart || !$recoveryEnd || $nowUtc < $recoveryStart || $nowUtc > $recoveryEnd) { http_response_code(403); exit('This Timed Exam is not available through the Recovery Plan window.'); }
    $exam = mmh_timed_exam_with_window($exam, $recoveryStart->format('Y-m-d H:i:s'), $recoveryEnd->format('Y-m-d H:i:s'));
}
$context = mmh_timed_exam_student_context($conn, $exam, (int) $studentId);
$state = $context['state'];
$attempt = $context['attempt'];
$version = $context['latest_version'];
$base = rtrim((string) $baseUrl, '/');
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$localDate = static function (?string $value): string {
    if (!$value) return 'Not scheduled';
    try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('j M Y, g:i A'); }
    catch (Throwable $e) { return 'Not scheduled'; }
};
$paperBase = $base . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/paper';
$uploadUrl = $base . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/upload';
$submitUrl = $base . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/submit';
$recoveryQuery = ($recoveryPlanId > 0 && $recoveryTaskId > 0) ? '?recovery_plan=' . $recoveryPlanId . '&recovery_task=' . $recoveryTaskId : '';
$paperBase .= $recoveryQuery; $uploadUrl .= $recoveryQuery; $submitUrl .= $recoveryQuery;
$closeTimestamp = $state['window']['closes_at'] instanceof DateTimeImmutable ? $state['window']['closes_at']->getTimestamp() : 0;
if (($state['key'] ?? '') === 'grace' && $state['window']['grace_closes_at'] instanceof DateTimeImmutable) $closeTimestamp = $state['window']['grace_closes_at']->getTimestamp();
$metatags = $metatags ?? ''; $keywords = $keywords ?? ''; $openGraph = $openGraph ?? ''; $schema = $schema ?? '';
include 'views/user/layouts/user/header.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $esc($exam['title']); ?> | Timed Exam</title>
  <link rel="stylesheet" href="<?= $esc($base . '/resources/css/design-system.css'); ?>">
  <link rel="stylesheet" href="<?= $esc($base . '/resources/css/course-learning.css'); ?>">
  <link rel="stylesheet" href="<?= $esc($base . '/resources/css/fontawsome5.min.css'); ?>">
  <style>
    .timed-exam-shell{max-width:900px;margin:0 auto;padding:30px 18px 64px}.timed-exam-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap}.timed-exam-eyebrow{color:var(--text-muted);font-size:.78rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.timed-exam-head h1{margin:.3rem 0}.timed-exam-meta{color:var(--text-secondary);line-height:1.6}.timed-exam-card{margin-top:18px;padding:20px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface);box-shadow:var(--shadow-sm)}.timed-exam-status{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-top:18px;padding:14px;border-radius:var(--radius-sm);background:color-mix(in srgb,var(--primary) 8%,var(--surface))}.timed-exam-countdown{font-size:1.35rem;font-weight:850;color:var(--primary);font-variant-numeric:tabular-nums}.timed-exam-instructions{white-space:pre-wrap;color:var(--text-secondary);line-height:1.7}.timed-exam-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.timed-exam-actions a,.timed-exam-actions button{display:inline-flex;align-items:center;justify-content:center;gap:7px}.timed-exam-file{margin-top:14px;color:var(--text-secondary);font-size:.85rem}.timed-exam-message{margin-top:12px;color:var(--text-secondary)}.timed-exam-error{color:var(--danger)}@media(max-width:600px){.timed-exam-shell{padding:20px 14px 44px}.timed-exam-card{padding:16px}.timed-exam-actions{display:grid}.timed-exam-actions a,.timed-exam-actions button{width:100%}}
  </style>
</head>
<body class="course-learning-page">
<main class="timed-exam-shell">
  <header class="timed-exam-head">
    <div><div class="timed-exam-eyebrow"><?= $esc($course['course_title']); ?> · <?= $esc($exam['section_title'] ?: 'Course exam'); ?></div><h1><?= $esc($exam['title']); ?></h1><p class="timed-exam-meta">Fixed Window · Opens <?= $esc($localDate($exam['scheduled_start_at_utc'])); ?> · Duration <?= (int) $exam['duration_minutes']; ?> minutes</p></div>
    <a class="course-btn course-btn-secondary" href="<?= $esc($base . '/user/course/' . rawurlencode((string) $course['course_id'])); ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> Return to course</a>
  </header>
  <section class="timed-exam-card">
    <h2 class="h5">Instructions</h2>
    <div class="timed-exam-instructions"><?= $esc($exam['instructions'] ?? 'Read the paper carefully and upload your answer before the window closes.'); ?></div>
    <div class="timed-exam-status" role="status">
      <div><strong><?= $esc($state['label']); ?></strong><div class="timed-exam-meta">Closes <?= $esc($localDate($state['window']['closes_at']?->format('Y-m-d H:i:s'))); ?><?= (int) $exam['grace_minutes'] > 0 ? ' · Grace until ' . $esc($localDate($state['window']['grace_closes_at']?->format('Y-m-d H:i:s'))) : ''; ?></div></div>
      <?php if (in_array((string) ($state['key'] ?? ''), ['open', 'grace'], true)): ?><div class="timed-exam-countdown" data-countdown data-close="<?= (int) $closeTimestamp; ?>">--:--</div><?php endif; ?>
    </div>
    <?php if (($state['key'] ?? '') === 'before'): ?>
      <p class="timed-exam-message">The exam paper and answer controls will unlock when the scheduled window opens.</p>
    <?php elseif (in_array((string) ($state['key'] ?? ''), ['open', 'grace'], true)): ?>
      <div class="timed-exam-actions"><a class="course-btn course-btn-secondary" href="<?= $esc($paperBase); ?>" target="_blank" rel="noopener"><span class="fas fa-eye" aria-hidden="true"></span> View Exam</a><?php if (!empty($exam['paper_download_allowed'])): ?><a class="course-btn course-btn-secondary" href="<?= $esc($paperBase . (str_contains($paperBase, '?') ? '&' : '?') . 'download=1'); ?>"><span class="fas fa-download" aria-hidden="true"></span> Download Exam</a><?php endif; ?></div>
      <form id="timed-exam-upload" class="timed-exam-card" style="margin-top:16px;padding:14px" method="post" enctype="multipart/form-data" action="<?= $esc($uploadUrl); ?>"><input type="hidden" name="csrf_token" value="<?= $esc(student_course_csrf_token()); ?>"><label for="answer-file"><strong>Upload answer</strong></label><input id="answer-file" class="form-control mt-2" type="file" name="answer_file" accept="<?= $esc(implode(',', array_map(static fn($type): string => '.' . $type, $exam['allowed_answer_types_list'] ?? ['pdf']))); ?>" required><div class="timed-exam-actions"><button class="course-btn course-btn-secondary" type="submit"><span class="fas fa-upload" aria-hidden="true"></span> Upload Answer</button></div><p class="timed-exam-message" data-upload-message role="status"></p></form>
      <?php if ($version): ?><div class="timed-exam-file">Current upload: <strong><?= $esc($version['original_filename']); ?></strong> · <?= $esc($version['status']); ?><?= !empty($version['is_late']) ? ' · Grace-period upload' : ''; ?></div><?php endif; ?>
      <form id="timed-exam-submit" method="post" action="<?= $esc($submitUrl); ?>"><input type="hidden" name="csrf_token" value="<?= $esc(student_course_csrf_token()); ?>"><div class="timed-exam-actions"><button class="course-btn course-btn-primary" type="submit"<?= $version && ($version['status'] ?? '') === 'uploaded' ? '' : ' disabled'; ?>><span class="fas fa-paper-plane" aria-hidden="true"></span> Submit Exam</button></div><p class="timed-exam-message" data-submit-message role="status"></p></form>
    <?php elseif (in_array((string) ($state['key'] ?? ''), ['submitted', 'auto_submitted', 'graded'], true)): ?>
      <p class="timed-exam-message"><strong><?= $state['key'] === 'auto_submitted' ? 'Submitted automatically at the end of the window.' : 'Submitted'; ?></strong><?php if (!empty($attempt['submitted_at_utc'])): ?> <?= $esc($localDate($attempt['submitted_at_utc'])); ?><?php endif; ?><?= !empty($attempt['is_late']) ? ' · Grace-period submission' : ''; ?></p>
      <?php if ($version): ?><div class="timed-exam-file">Submitted file: <strong><?= $esc($version['original_filename']); ?></strong></div><?php endif; ?>
      <?php if ($state['key'] === 'graded' && (empty($exam['results_release_at_utc']) || strtotime((string) $exam['results_release_at_utc']) <= time())): ?><div class="timed-exam-status"><strong>Grade: <?= $esc($attempt['grade'] ?? 'Not entered'); ?><?= $exam['max_marks'] !== null ? ' / ' . $esc($exam['max_marks']) : ''; ?></strong><?php if (($attempt['feedback'] ?? '') !== ''): ?><span><?= $esc($attempt['feedback']); ?></span><?php endif; ?></div><?php endif; ?>
    <?php else: ?>
      <p class="timed-exam-message">No submission was recorded.</p>
    <?php endif; ?>
  </section>
</main>
<?php if (in_array((string) ($state['key'] ?? ''), ['open', 'grace'], true)): ?>
<script>
(function () {
  var countdown = document.querySelector('[data-countdown]');
  var title = <?= json_encode((string) $exam['title'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  function tick() {
    if (!countdown) return;
    var seconds = Math.max(0, parseInt(countdown.dataset.close || '0', 10) - Math.floor(Date.now() / 1000));
    var minutes = Math.floor(seconds / 60), rest = seconds % 60;
    countdown.textContent = String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
    document.title = countdown.textContent + ' — ' + title;
    if (seconds <= 0) window.location.reload();
  }
  tick(); window.setInterval(tick, 1000);
  function bind(form, message) {
    if (!form) return;
    form.addEventListener('submit', function (event) {
      event.preventDefault(); var button = form.querySelector('button[type=submit]'); if (button) button.disabled = true;
      fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin'}).then(function (response) { return response.json(); }).then(function (data) { message.textContent = data.message || ''; if (!data.success && button) button.disabled = false; if (data.success) window.location.reload(); }).catch(function () { message.textContent = 'The request could not be completed.'; if (button) button.disabled = false; });
    });
  }
  bind(document.getElementById('timed-exam-upload'), document.querySelector('[data-upload-message]'));
  bind(document.getElementById('timed-exam-submit'), document.querySelector('[data-submit-message]'));
}());
</script>
<?php endif; ?>
</body></html>
