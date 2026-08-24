<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/TimedExam.php';

$conn = db();
$courseId = trim((string) ($courseId ?? ''));
$examId = (int) ($examId ?? 0);
$exam = $courseId !== '' ? mmh_timed_exam_load($conn, $courseId, $examId, true) : null;
if (!$exam) { http_response_code(404); exit('Timed Exam not found.'); }
$rows = mmh_timed_exam_admin_attempts($conn, $examId);
$base = rtrim((string) $baseUrl, '/');
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$gradeUrl = $base . '/admin/requests/timed-exam/grade';
$markedUrl = static fn(int $attemptId): string => $base . '/admin/timed-exam-marked/' . $attemptId;
$answerUrl = static fn(int $versionId): string => $base . '/admin/timed-exam-answer/' . $versionId;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $esc($exam['title']); ?> submissions</title>
  <?php include 'layouts/admin/header.php'; ?>
  <style>
    .timed-admin-marked{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px;font-size:.78rem}.timed-admin-marked label{font-size:.75rem}.timed-admin-upload{display:grid;gap:3px;margin-top:6px;font-size:.75rem;white-space:normal}.timed-admin-upload input{max-width:220px!important}
    .timed-admin-shell{max-width:1180px;margin:0 auto;padding:28px}.timed-admin-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);padding:18px}.timed-admin-table{width:100%;border-collapse:collapse}.timed-admin-table th,.timed-admin-table td{padding:10px;border-bottom:1px solid var(--border);vertical-align:top}.timed-admin-table th{text-align:left;color:var(--text-muted);font-size:.8rem}.timed-admin-table input,.timed-admin-table textarea{max-width:140px}.timed-admin-actions{display:flex;gap:6px;flex-wrap:wrap}.timed-admin-release{display:block;margin-top:4px;color:var(--text-muted);font-size:.75rem}@media(max-width:760px){.timed-admin-shell{padding:16px}.timed-admin-table{display:block;overflow:auto;white-space:nowrap}}
  </style>
</head>
<body class="dash ds-bg-primary">
<div class="col-12 d-flex">
  <?php include 'layouts/admin/aside.php'; ?>
  <div class="main-content in-active">
    <main class="timed-admin-shell">
      <div class="timed-admin-card">
        <p class="text-muted mb-1"><?= $esc($exam['course_id']); ?> · <?= $esc($exam['section_title'] ?? 'Course exam'); ?></p>
        <h1><?= $esc($exam['title']); ?> submissions</h1>
        <p>Fixed Window · <?= (int) $exam['duration_minutes']; ?> minutes · <?= count($rows); ?> canonical outcome records</p>
        <a class="btn btn-outline-secondary" href="<?= $esc($base . '/admin/courses/' . rawurlencode($courseId) . '/content'); ?>">Back to course content</a>
      </div>
      <div class="timed-admin-card mt-3">
        <div class="table-responsive">
          <table class="timed-admin-table">
            <thead><tr><th>Student</th><th>Start</th><th>Status</th><th>Answer</th><th>Grade / feedback</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6">No canonical exam outcomes are available yet.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $gradeable = in_array((string) ($row['state'] ?? ''), ['submitted', 'auto_submitted', 'graded'], true);
                $formId = 'timed-exam-grade-' . (int) $row['id'];
              ?>
              <tr>
                <td><strong><?= $esc($row['full_name'] ?: $row['username']); ?></strong><br><small><?= $esc($row['username']); ?></small></td>
                <td><?= $esc($row['started_at_utc'] ?? '—'); ?></td>
                <td>
                  <?= $esc(ucwords(str_replace('_', ' ', (string) ($row['state'] ?? '')))); ?><?= !empty($row['is_late']) ? ' · Grace' : ''; ?>
                  <span class="timed-admin-release"><?= !empty($row['results_released_at_utc']) ? 'Result released' : ((string) ($row['state'] ?? '') === 'graded' ? 'Result not released' : ''); ?></span>
                </td>
                <td><?php if (!empty($row['version_id'])): ?><a href="<?= $esc($answerUrl((int) $row['version_id'])); ?>">Download <?= $esc($row['original_filename']); ?></a><?php else: ?>—<?php endif; ?></td>
                <?php if ($gradeable): ?>
                  <td>
                    <form id="<?= $esc($formId); ?>" method="post" action="<?= $esc($gradeUrl); ?>" enctype="multipart/form-data">
                      <input type="hidden" name="_token" value="<?= $esc(mmh_admin_csrf_token()); ?>">
                      <input type="hidden" name="attempt_id" value="<?= (int) $row['id']; ?>">
                      <input type="hidden" name="course_id" value="<?= $esc($courseId); ?>">
                      <input type="hidden" name="exam_id" value="<?= $examId; ?>">
                      <input type="number" name="grade" step="0.01" min="0" value="<?= $esc($row['grade'] ?? ''); ?>" placeholder="Score">
                      <textarea name="feedback" rows="2" placeholder="Report / feedback"><?= $esc($row['feedback'] ?? ''); ?></textarea>
                      <?php if (!empty($row['marked_paper_id'])): ?><div class="timed-admin-marked"><span class="badge bg-success">Marked PDF</span> <a href="<?= $esc($markedUrl((int) $row['id'])); ?>" target="_blank" rel="noopener">View</a><label><input type="checkbox" name="remove_marked_pdf" value="1"> Remove</label></div><label class="timed-admin-upload">Replace marked PDF<input type="file" name="marked_pdf" accept="application/pdf,.pdf"></label><?php else: ?><label class="timed-admin-upload">Marked PDF (optional)<input type="file" name="marked_pdf" accept="application/pdf,.pdf"></label><?php endif; ?>
                    </form>
                  </td>
                  <td>
                    <div class="timed-admin-actions">
                      <button class="btn btn-sm btn-primary" type="submit" form="<?= $esc($formId); ?>" name="grade_action" value="save">Save marking</button>
                      <?php if (empty($row['results_released_at_utc'])): ?><button class="btn btn-sm btn-outline-success" type="submit" form="<?= $esc($formId); ?>" name="grade_action" value="release">Save &amp; release</button><?php else: ?><span class="badge bg-success">Released</span><?php endif; ?>
                    </div>
                  </td>
                <?php else: ?><td>—</td><td>—</td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
</div>
</body>
</html>
