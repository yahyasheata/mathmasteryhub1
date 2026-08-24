<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/StudentCourseAccess.php';
require_once 'inc/StudentCourseCsrf.php';
require_once 'inc/TimedExam.php';
require_once 'inc/RecoveryPlan.php';

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
if (!$course || !$exam || (!$timedExamPreview && (!$studentId || !student_course_access_enrolled($conn, $studentId, (string) $course['course_id'])))) {
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
    $exam['_attempt_scope'] = 'recovery:' . $recoveryPlanId . ':' . $recoveryTaskId;
}
$context = mmh_timed_exam_student_context($conn, $exam, (int) $studentId, $timedExamPreview);
$state = $context['state'];
$attempt = $context['attempt'];
$version = $context['latest_version'];
$base = rtrim(mmh_current_request_base_url(), '/');
$previewExitUrl = $timedExamPreview
    ? $base . '/admin/courses/' . rawurlencode((string) $course['course_id']) . '/content#course-item-' . rawurlencode((string) ($exam['item_id'] ?? ''))
    : '';
$returnCourseUrl = $timedExamPreview ? $previewExitUrl : $base . '/user/course/' . rawurlencode((string) $course['course_id']);
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$localDate = static function (?string $value): string {
    if (!$value) return 'Not scheduled';
    try { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('j M Y, g:i A'); }
    catch (Throwable $e) { return 'Not scheduled'; }
};
$paperBase = $timedExamPreview
    ? $base . '/admin/courses/' . rawurlencode((string) $course['course_id']) . '/timed-exam/item/' . rawurlencode((string) ($exam['item_id'] ?? '')) . '/paper'
    : $base . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/paper';
$uploadUrl = $timedExamPreview ? '' : $base . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/upload';
$removeUploadUrl = $base . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/remove-upload';
$submitUrl = $timedExamPreview ? '' : $base . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/submit';
$recoveryParams = ($recoveryPlanId > 0 && $recoveryTaskId > 0) ? ['recovery_plan' => $recoveryPlanId, 'recovery_task' => $recoveryTaskId] : [];
$paperUrl = static fn(string $action): string => $paperBase . '?' . http_build_query($recoveryParams + ['paper_action' => $action], '', '&', PHP_QUERY_RFC3986);
$recoveryQuery = $recoveryParams ? '?' . http_build_query($recoveryParams, '', '&', PHP_QUERY_RFC3986) : '';
$uploadUrl .= $recoveryQuery; $removeUploadUrl .= $recoveryQuery; $submitUrl .= $recoveryQuery;
$closeTimestamp = $state['window']['closes_at'] instanceof DateTimeImmutable ? $state['window']['closes_at']->getTimestamp() : 0;
if (($state['key'] ?? '') === 'grace' && $state['window']['grace_closes_at'] instanceof DateTimeImmutable) $closeTimestamp = $state['window']['grace_closes_at']->getTimestamp();
$stateKey = (string) ($state['key'] ?? 'unavailable');
$activeState = in_array($stateKey, ['open', 'grace'], true);
$paperResolved = mmh_timed_exam_normalize_external_paper_url((string) ($exam['paper_external_url'] ?? ''));
$paperHasDownload = $paperResolved !== null;
$acceptedTypes = implode(', ', array_map(static fn($type): string => strtoupper((string) $type), $exam['allowed_answer_types_list'] ?? ['pdf']));
$acceptedExtensions = implode(',', array_map(static fn($type): string => '.' . $type, $exam['allowed_answer_types_list'] ?? ['pdf']));
$maxFileSizeMb = max(1, (int) ceil(((int) ($exam['max_file_size_bytes'] ?? 10485760)) / 1048576));
$uploadLimit = max(1, (int) ($context['upload_version_limit'] ?? $exam['max_attempts'] ?? 1));
$uploadCount = max(0, (int) ($context['upload_version_count'] ?? 0));
$uploadsRemaining = max(0, (int) ($context['upload_versions_remaining'] ?? ($uploadLimit - $uploadCount)));
$attemptSummary = $uploadsRemaining . ' of ' . $uploadLimit . ' answer upload' . ($uploadLimit === 1 ? '' : 's') . ' remaining';
$resultReleased = !empty($attempt['results_released_at_utc']);
$markedPaper = (!$timedExamPreview && $resultReleased && $attempt)
    ? mmh_timed_exam_marked_paper_for_student($conn, (int) $attempt['id'], (int) $exam['id'], (int) $studentId)
    : null;
$markedPaperUrl = $markedPaper
    ? $base . '/user/course/' . rawurlencode((string) $course['course_id']) . '/exam/' . (int) $exam['id'] . '/marked-paper/' . (int) $attempt['id']
    : '';
$titlePrefix = match ($stateKey) {
    'submitted', 'auto_submitted', 'graded' => 'Submitted',
    'expired', 'no_submission' => 'Time Ended',
    'before' => 'Upcoming',
    default => $activeState ? sprintf('%02d:%02d', intdiv(max(0, (int) ($state['remaining_seconds'] ?? 0)), 60), max(0, (int) ($state['remaining_seconds'] ?? 0)) % 60) : 'Timed Exam',
};
$tabTitle = $titlePrefix . ' — ' . (string) $exam['title'];
$metatags = $metatags ?? ''; $keywords = $keywords ?? ''; $openGraph = $openGraph ?? ''; $schema = $schema ?? '';
include 'views/user/layouts/user/header.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $esc($tabTitle); ?></title>
  <link rel="stylesheet" href="<?= $esc($base . '/resources/css/design-system.css'); ?>">
  <link rel="stylesheet" href="<?= $esc($base . '/resources/css/course-learning.css'); ?>">
  <link rel="stylesheet" href="<?= $esc($base . '/resources/css/fontawsome5.min.css'); ?>">
  <style>
    .timed-exam-shell{max-width:900px;margin:0 auto;padding:24px 18px 64px;color:var(--text-primary)}
    .timed-exam-preview-banner{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:9px 18px;background:color-mix(in srgb,var(--primary) 14%,var(--surface));border-bottom:1px solid color-mix(in srgb,var(--primary) 40%,var(--border));color:var(--text-primary);font-size:.84rem}.timed-exam-preview-banner>div{display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-width:0}.timed-exam-preview-banner strong{color:var(--primary);white-space:nowrap}.timed-exam-preview-banner a{color:var(--primary);font-weight:800;white-space:nowrap}.timed-exam-preview-controls{margin-top:12px;padding:12px;border:1px dashed color-mix(in srgb,var(--primary) 50%,var(--border));border-radius:var(--radius-sm);background:color-mix(in srgb,var(--primary) 5%,var(--surface))}.timed-exam-preview-controls .timed-exam-actions{margin-top:0}
    .timed-exam-sticky-head{position:sticky;top:var(--public-header-offset,0px);z-index:20;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 -18px 18px;padding:14px 18px;border-bottom:1px solid var(--border);background:color-mix(in srgb,var(--surface) 94%,transparent);backdrop-filter:blur(12px)}
    .timed-exam-head-main{min-width:0}.timed-exam-eyebrow{color:var(--text-muted);font-size:.75rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;overflow-wrap:anywhere}.timed-exam-head h1{margin:.25rem 0;font-size:clamp(1.35rem,3vw,2rem);overflow-wrap:anywhere}.timed-exam-head-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:var(--text-secondary);font-size:.85rem}.timed-exam-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;background:color-mix(in srgb,var(--primary) 12%,var(--surface));color:var(--primary);font-weight:800}.timed-exam-timer{display:flex;align-items:center;gap:10px;flex:0 0 auto}.timed-exam-countdown{font-size:1.3rem;font-weight:850;color:var(--primary);font-variant-numeric:tabular-nums;white-space:nowrap}.timed-exam-card{margin-top:16px;padding:18px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface);box-shadow:var(--shadow-sm)}.timed-exam-card h2{margin-top:0}.timed-exam-meta{color:var(--text-secondary);line-height:1.55}.timed-exam-instructions{white-space:pre-wrap;color:var(--text-secondary);line-height:1.7}.timed-exam-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.timed-exam-actions a,.timed-exam-actions button{display:inline-flex;align-items:center;justify-content:center;gap:7px}.timed-exam-status{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-top:14px;padding:12px;border-radius:var(--radius-sm);background:color-mix(in srgb,var(--primary) 8%,var(--surface))}.timed-exam-upload-card{margin-top:16px;padding:16px;border:1px solid var(--border);border-radius:var(--radius-sm);background:color-mix(in srgb,var(--surface) 94%,var(--primary))}.timed-exam-upload-heading{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.timed-exam-upload-heading h3{margin:0;font-size:1rem}.timed-exam-dropzone{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:12px;padding:14px;border:1px dashed color-mix(in srgb,var(--primary) 52%,var(--border));border-radius:var(--radius-sm);background:var(--surface);cursor:pointer}.timed-exam-dropzone:focus-visible,.timed-exam-dropzone.is-dragging{outline:3px solid color-mix(in srgb,var(--primary) 30%,transparent);outline-offset:2px;background:color-mix(in srgb,var(--primary) 6%,var(--surface))}.timed-exam-drop-copy{display:grid;gap:3px;min-width:0;flex:1 1 210px}.timed-exam-drop-copy strong,.timed-exam-drop-copy span{overflow-wrap:anywhere}.timed-exam-drop-copy span{color:var(--text-secondary);font-size:.82rem}.timed-exam-file-input{position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}.timed-exam-choose{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 13px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text-primary);font-weight:750;cursor:pointer;white-space:nowrap}.timed-exam-choose:hover{border-color:var(--primary);color:var(--primary)}.timed-exam-selected{display:none;flex:1 1 100%;padding-top:8px;border-top:1px solid var(--border);font-size:.85rem;color:var(--text-secondary);overflow-wrap:anywhere}.timed-exam-selected.has-file{display:block}.timed-exam-file{margin-top:12px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text-secondary);font-size:.85rem;overflow-wrap:anywhere}.timed-exam-file strong{color:var(--text-primary)}.timed-exam-inline-button{margin-left:8px;padding:5px 9px!important}.timed-exam-message{margin:10px 0 0;color:var(--text-secondary)}.timed-exam-error{color:var(--danger)}.timed-exam-confirm{margin-top:12px;padding:12px;border:1px solid color-mix(in srgb,var(--primary) 35%,var(--border));border-radius:var(--radius-sm);background:color-mix(in srgb,var(--primary) 7%,var(--surface))}.timed-exam-confirm p{margin:0;color:var(--text-secondary)}.timed-exam-confirm .timed-exam-actions{margin-top:10px}.timed-exam-visually-hidden{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}@media(max-width:600px){.timed-exam-shell{padding:16px 14px 44px}.timed-exam-sticky-head{margin:0 -14px 16px;padding:12px 14px}.timed-exam-card{padding:15px}.timed-exam-upload-card{padding:13px}.timed-exam-actions{display:grid}.timed-exam-actions a,.timed-exam-actions button{width:100%}.timed-exam-timer{width:100%;justify-content:space-between}.timed-exam-dropzone{align-items:flex-start}.timed-exam-choose{width:100%}}
    .timed-exam-file-help{flex:1 1 100%;padding-top:8px;border-top:1px solid var(--border);font-size:.82rem;color:var(--text-secondary);overflow-wrap:anywhere}.timed-exam-inline-button{margin-left:8px;padding:5px 9px!important}.timed-exam-marked-paper{margin-top:14px;padding:13px;border:1px solid color-mix(in srgb,var(--primary) 34%,var(--border));border-radius:var(--radius-sm);background:color-mix(in srgb,var(--primary) 6%,var(--surface))}.timed-exam-marked-paper p{margin:.35rem 0;color:var(--text-secondary)}
  </style>
</head>
<body class="course-learning-page">
<?php if ($timedExamPreview): ?>
  <div class="timed-exam-preview-banner" role="status"><div><strong><span class="fas fa-eye" aria-hidden="true"></span> Admin Preview</strong><span>You are viewing this Timed Exam as a student. No answers, attempts, submissions, or grades will be saved.</span></div><a href="<?= $esc($previewExitUrl); ?>">Exit Preview</a></div>
<?php endif; ?>
<main class="timed-exam-shell">
  <header class="timed-exam-sticky-head timed-exam-head">
    <div class="timed-exam-head-main"><div class="timed-exam-eyebrow"><?= $esc($course['course_title']); ?> · <?= $esc($exam['section_title'] ?: 'Course exam'); ?></div><h1><?= $esc($exam['title']); ?></h1><div class="timed-exam-head-meta"><span class="timed-exam-badge"><span class="fas fa-stopwatch" aria-hidden="true"></span> Fixed Window</span><span>Closes <?= $esc($localDate($state['window']['closes_at']?->format('Y-m-d H:i:s'))); ?></span></div></div>
    <div class="timed-exam-timer"><span class="timed-exam-countdown"<?= $activeState ? ' data-countdown data-close="' . (int) $closeTimestamp . '"' : ''; ?>><?= $activeState ? '--:--' : $esc($state['label']); ?></span><a class="course-btn course-btn-secondary" href="<?= $esc($returnCourseUrl); ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> <?= $timedExamPreview ? 'Exit Preview' : 'Return to course'; ?></a></div>
  </header>
  <section class="timed-exam-card" aria-labelledby="timed-exam-instructions-title">
    <h2 id="timed-exam-instructions-title" class="h5">Instructions</h2>
    <div class="timed-exam-instructions"><?= $esc($exam['instructions'] ?? 'Read the paper carefully and upload your answer before the window closes.'); ?></div>
    <?php if ($stateKey === 'before'): ?>
      <p class="timed-exam-message">The exam paper and answer controls will unlock when the scheduled window opens.</p>
    <?php elseif ($activeState): ?>
      <section class="timed-exam-card" aria-labelledby="timed-exam-paper-title">
        <h2 id="timed-exam-paper-title" class="h5">Exam paper</h2>
        <?php if ($paperResolved): ?>
          <div class="timed-exam-actions"><a class="course-btn course-btn-primary" href="<?= $esc($paperUrl('preview')); ?>"><span class="fas fa-eye" aria-hidden="true"></span> View Exam</a><a class="course-btn course-btn-secondary" href="<?= $esc($paperUrl('open')); ?>" target="_blank" rel="noopener noreferrer"><span class="fas fa-external-link-alt" aria-hidden="true"></span> Open Exam in New Tab</a><?php if (!empty($exam['paper_download_allowed']) && $paperHasDownload): ?><a class="course-btn course-btn-secondary" href="<?= $esc($paperUrl('download')); ?>"><span class="fas fa-download" aria-hidden="true"></span> Download Exam</a><?php endif; ?></div>
        <?php else: ?>
          <p class="timed-exam-message timed-exam-error">The exam paper is not available yet. Your teacher must add a Google Drive link.</p>
        <?php endif; ?>
        <?php if (!empty($exam['paper_fallback_instructions'])): ?><p class="timed-exam-message"><?= $esc($exam['paper_fallback_instructions']); ?></p><?php endif; ?>
      </section>
      <section class="timed-exam-upload-card" aria-labelledby="timed-exam-answer-title">
        <div class="timed-exam-upload-heading"><div><h2 id="timed-exam-answer-title" class="h5">Answer submission</h2><p class="timed-exam-meta">Choose a file, upload it, then submit it as your final response.</p></div><span class="timed-exam-meta"><?= $esc($attemptSummary); ?></span></div>
        <?php if ($timedExamPreview): ?><div class="timed-exam-preview-controls"><p class="timed-exam-message">Preview only — choose a file to inspect the upload state. The file stays in your browser and is never uploaded.</p><div class="timed-exam-actions"><label class="timed-exam-choose" for="answer-file"><span class="fas fa-folder-open" aria-hidden="true"></span> Choose File</label><input id="answer-file" class="timed-exam-file-input" type="file" accept="<?= $esc($acceptedExtensions); ?>" aria-describedby="answer-file-help"><button class="course-btn course-btn-primary" type="button" data-preview-submit disabled><span class="fas fa-paper-plane" aria-hidden="true"></span> Submit Exam</button></div><div id="answer-file-help" class="timed-exam-file-help">Accepted: <?= $esc($acceptedTypes); ?> · Maximum <?= (int) $maxFileSizeMb; ?> MB</div><p class="timed-exam-message" data-preview-file role="status"></p><div class="timed-exam-confirm" data-preview-confirm hidden><p>Submit this answer as your final exam response?</p><div class="timed-exam-actions"><button class="course-btn course-btn-primary" type="button" data-preview-confirm-submit>Confirm submission</button><button class="course-btn course-btn-secondary" type="button" data-preview-cancel>Keep working</button></div></div><p class="timed-exam-message" data-preview-message role="status"></p></div>
        <?php elseif ($uploadsRemaining > 0): ?><form id="timed-exam-upload" method="post" enctype="multipart/form-data" action="<?= $esc($uploadUrl); ?>"><input type="hidden" name="csrf_token" value="<?= $esc(student_course_csrf_token()); ?>"><div class="timed-exam-dropzone" data-dropzone role="button" tabindex="0" aria-describedby="answer-file-help"><span class="fas fa-cloud-upload-alt" aria-hidden="true"></span><div class="timed-exam-drop-copy"><strong data-drop-title><?= $version && ($version['status'] ?? '') === 'uploaded' ? 'Replace your uploaded answer' : 'Drop your answer here'; ?></strong><span data-file-name><?= $version && ($version['status'] ?? '') === 'uploaded' ? 'Choose a new file to replace the current upload.' : 'or choose a file from your device'; ?></span></div><label class="timed-exam-choose" for="answer-file"><span class="fas fa-folder-open" aria-hidden="true"></span> <?= $version && ($version['status'] ?? '') === 'uploaded' ? 'Replace File' : 'Choose File'; ?></label><input id="answer-file" class="timed-exam-file-input" type="file" name="answer_file" accept="<?= $esc($acceptedExtensions); ?>" aria-describedby="answer-file-help" required><div id="answer-file-help" class="timed-exam-file-help">Accepted: <?= $esc($acceptedTypes); ?> · Maximum <?= (int) $maxFileSizeMb; ?> MB</div><div class="timed-exam-selected" data-selected-file aria-live="polite"></div></div><div class="timed-exam-actions"><button class="course-btn course-btn-secondary" type="submit" data-upload-submit disabled><span class="fas fa-upload" aria-hidden="true"></span> Upload Answer</button><button class="course-btn course-btn-secondary" type="button" data-remove-selection hidden>Remove File</button></div><p class="timed-exam-message" data-upload-message role="status"></p></form><?php else: ?><p class="timed-exam-message">You have used all available answer uploads. You can still submit the latest uploaded answer.</p><?php endif; ?>
        <?php if ($version && ($version['status'] ?? '') === 'uploaded'): ?><div class="timed-exam-file"><strong>Uploaded:</strong> <?= $esc($version['original_filename']); ?><?= !empty($version['is_late']) ? ' · Grace-period upload' : ''; ?><button class="course-btn course-btn-secondary timed-exam-inline-button" type="button" data-remove-upload>Remove File</button></div><?php endif; ?>
        <p class="timed-exam-message">If time ends after a valid file has uploaded, the latest uploaded file will be submitted automatically.</p>
      </section>
      <?php if (!$timedExamPreview): ?><form id="timed-exam-submit" method="post" action="<?= $esc($submitUrl); ?>"><input type="hidden" name="csrf_token" value="<?= $esc(student_course_csrf_token()); ?>"><div class="timed-exam-actions"><button class="course-btn course-btn-primary" type="submit"<?= $version && ($version['status'] ?? '') === 'uploaded' ? '' : ' disabled'; ?>><span class="fas fa-paper-plane" aria-hidden="true"></span> Submit Exam</button></div><div class="timed-exam-confirm" data-submit-confirm hidden><p>Submit this answer as your final exam response?</p><div class="timed-exam-actions"><button class="course-btn course-btn-primary" type="button" data-confirm-submit>Confirm submission</button><button class="course-btn course-btn-secondary" type="button" data-cancel-submit>Keep working</button></div></div><p class="timed-exam-message" data-submit-message role="status"></p></form><?php endif; ?>
    <?php elseif (in_array($stateKey, ['submitted', 'auto_submitted', 'graded'], true)): ?>
      <p class="timed-exam-message"><strong><?= $stateKey === 'auto_submitted' ? 'Submitted automatically at the end of the window.' : 'Submitted'; ?></strong><?php if (!empty($attempt['submitted_at_utc'])): ?> <?= $esc($localDate($attempt['submitted_at_utc'])); ?><?php endif; ?><?= !empty($attempt['is_late']) ? ' · Grace-period submission' : ''; ?></p>
      <?php if ($version): ?><div class="timed-exam-file">Submitted file: <strong><?= $esc($version['original_filename']); ?></strong></div><?php endif; ?>
      <?php if ($stateKey === 'graded' && $resultReleased): ?>
        <div class="timed-exam-status"><strong>Grade: <?= $esc($attempt['grade'] ?? 'Not entered'); ?><?= $exam['max_marks'] !== null ? ' / ' . $esc($exam['max_marks']) : ''; ?></strong><?php if (($attempt['feedback'] ?? '') !== ''): ?><span><?= $esc($attempt['feedback']); ?></span><?php endif; ?></div>
        <?php if ($markedPaper): ?><div class="timed-exam-marked-paper"><strong><span class="fas fa-file-pdf" aria-hidden="true"></span> Marked Paper</strong><p>Your teacher has returned a marked copy of your exam.</p><div class="timed-exam-actions"><a class="course-btn course-btn-primary" href="<?= $esc($markedPaperUrl); ?>" target="_blank" rel="noopener noreferrer"><span class="fas fa-eye" aria-hidden="true"></span> View Marked Paper</a><a class="course-btn course-btn-secondary" href="<?= $esc($markedPaperUrl . '?download=1'); ?>"><span class="fas fa-download" aria-hidden="true"></span> Download Marked PDF</a></div></div><?php endif; ?>
      <?php elseif ($stateKey === 'graded'): ?><p class="timed-exam-message">Your answer has been graded. The result has not been released yet.</p><?php endif; ?>
    <?php elseif ($stateKey === 'finalization_error'): ?>
      <p class="timed-exam-message timed-exam-error">Your exam outcome is being finalized. Your uploaded answer has not been discarded. Please check again shortly.</p>
    <?php else: ?>
      <p class="timed-exam-message">No submission was recorded.</p>
    <?php endif; ?>
  </section>
</main>
<?php if ($activeState): ?>
<script>
(function () {
  var countdown = document.querySelector('[data-countdown]');
  var title = <?= json_encode((string) $exam['title'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var csrf = <?= json_encode(student_course_csrf_token()); ?>;
  var uploadForm = document.getElementById('timed-exam-upload');
  var submitForm = document.getElementById('timed-exam-submit');
  var previewMode = <?= $timedExamPreview ? 'true' : 'false'; ?>;
  var fileInput = document.getElementById('answer-file');
  var dropzone = document.querySelector('[data-dropzone]');
  var fileName = document.querySelector('[data-file-name]');
  var selected = document.querySelector('.timed-exam-selected');
  var uploadButton = document.querySelector('[data-upload-submit]');
  var removeSelection = document.querySelector('[data-remove-selection]');
  var uploadMessage = document.querySelector('[data-upload-message]');
  var submitMessage = document.querySelector('[data-submit-message]');
  var confirmPanel = document.querySelector('[data-submit-confirm]');
  var removeUpload = document.querySelector('[data-remove-upload]');
  function format(seconds) { seconds = Math.max(0, seconds); return String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0'); }
  function updateTitle(prefix) { document.title = prefix + ' — ' + title; }
  function tick() {
    if (!countdown) return;
    var seconds = Math.max(0, parseInt(countdown.dataset.close || '0', 10) - Math.floor(Date.now() / 1000));
    countdown.textContent = format(seconds); updateTitle(format(seconds));
    if (seconds <= 0) { updateTitle('Time Ended'); window.location.reload(); }
  }
  tick(); window.setInterval(tick, 1000);
  if (previewMode) {
    var previewFile = document.querySelector('[data-preview-file]');
    var previewSubmit = document.querySelector('[data-preview-submit]');
    var previewMessage = document.querySelector('[data-preview-message]');
    var previewConfirm = document.querySelector('[data-preview-confirm]');
    var previewConfirmSubmit = document.querySelector('[data-preview-confirm-submit]');
    var previewCancel = document.querySelector('[data-preview-cancel]');
    if (fileInput) fileInput.addEventListener('change', function () {
      var file = fileInput.files && fileInput.files[0];
      if (previewFile) previewFile.textContent = file ? 'Selected: ' + file.name + ' — ready for local preview only.' : '';
      if (previewSubmit) previewSubmit.disabled = !file;
    });
    if (previewSubmit) previewSubmit.addEventListener('click', function () { if (previewConfirm) { previewConfirm.hidden = false; if (previewConfirmSubmit) previewConfirmSubmit.focus(); } });
    if (previewConfirmSubmit) previewConfirmSubmit.addEventListener('click', function () { if (previewConfirm) previewConfirm.hidden = true; if (previewMessage) previewMessage.textContent = 'Preview mode — no submission was created.'; });
    if (previewCancel) previewCancel.addEventListener('click', function () { if (previewConfirm) previewConfirm.hidden = true; });
    return;
  }
  function showSelected(file) {
    var hasFile = !!file;
    if (fileName) fileName.textContent = hasFile ? file.name : 'or choose a file from your device';
    if (selected) { selected.textContent = hasFile ? 'Selected: ' + file.name : 'Accepted: <?= $esc($acceptedTypes); ?> · Maximum <?= (int) $maxFileSizeMb; ?> MB'; selected.classList.toggle('has-file', hasFile); }
    if (uploadButton) uploadButton.disabled = !hasFile;
    if (removeSelection) removeSelection.hidden = !hasFile;
  }
  function chooseFiles(files) { if (files && files.length) { fileInput.files = files; showSelected(files[0]); } }
  if (fileInput) fileInput.addEventListener('change', function () { showSelected(fileInput.files[0]); });
  if (dropzone) {
    dropzone.addEventListener('click', function (event) { if (event.target.closest('label')) return; fileInput.click(); });
    dropzone.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fileInput.click(); } });
    dropzone.addEventListener('dragover', function (event) { event.preventDefault(); dropzone.classList.add('is-dragging'); });
    dropzone.addEventListener('dragleave', function () { dropzone.classList.remove('is-dragging'); });
    dropzone.addEventListener('drop', function (event) { event.preventDefault(); dropzone.classList.remove('is-dragging'); chooseFiles(event.dataTransfer.files); });
  }
  if (removeSelection) removeSelection.addEventListener('click', function () { fileInput.value = ''; showSelected(null); });
  function request(form, message, done) {
    var button = form.querySelector('button[type=submit]'); if (button) button.disabled = true;
    fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin'}).then(function (response) { return response.json(); }).then(function (data) { message.textContent = data.message || ''; if (!data.success && button) button.disabled = false; if (data.success) done(); }).catch(function () { message.textContent = 'The request could not be completed.'; if (button) button.disabled = false; });
  }
  if (uploadForm) uploadForm.addEventListener('submit', function (event) { event.preventDefault(); request(uploadForm, uploadMessage, function () { window.location.reload(); }); });
  if (removeUpload) removeUpload.addEventListener('click', function () {
    if (!window.confirm('Remove this uploaded answer?')) return;
    var body = new FormData(); body.append('csrf_token', csrf); removeUpload.disabled = true;
    fetch(<?= json_encode($removeUploadUrl, JSON_UNESCAPED_SLASHES); ?>, {method:'POST', body:body, credentials:'same-origin'}).then(function (response) { return response.json(); }).then(function (data) { if (data.success) window.location.reload(); else { uploadMessage.textContent = data.message || 'The uploaded answer could not be removed.'; removeUpload.disabled = false; } }).catch(function () { uploadMessage.textContent = 'The request could not be completed.'; removeUpload.disabled = false; });
  });
  function submitFinal() { confirmPanel.hidden = true; request(submitForm, submitMessage, function () { updateTitle('Submitted'); window.location.reload(); }); }
  if (submitForm) submitForm.addEventListener('submit', function (event) { event.preventDefault(); if (confirmPanel) { confirmPanel.hidden = false; var confirmButton = confirmPanel.querySelector('[data-confirm-submit]'); if (confirmButton) confirmButton.focus(); } });
  var confirmButton = document.querySelector('[data-confirm-submit]'); if (confirmButton) confirmButton.addEventListener('click', submitFinal);
  var cancelButton = document.querySelector('[data-cancel-submit]'); if (cancelButton) cancelButton.addEventListener('click', function () { confirmPanel.hidden = true; });
  if (removeSelection) showSelected(null);
}());
</script>
<?php endif; ?>
</body></html>
