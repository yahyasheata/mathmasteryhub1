<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/ParentWeeklyReport.php';
require_once 'inc/PreviousProgress.php';

$username = (string) ($_SESSION['admin'] ?? '');
$pageName = 'previous_progress';
$subPageName = 'previous_progress';
$conn = db();
mmh_previous_progress_ensure_schema($conn);
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$base = rtrim((string) $baseUrl, '/');
$courses = mmh_parent_report_courses($conn);
$selectedStudentId = (int) ($_GET['student_id'] ?? 0);
$requestedCourseId = trim((string) ($_GET['course_id'] ?? ''));
$selectedCourseId = $requestedCourseId;
if ($selectedCourseId === '' && $selectedStudentId > 0) {
    $studentCourseStmt = $conn->prepare('SELECT course_id FROM course_logs WHERE user_id = ? ORDER BY purchase_date DESC, id DESC LIMIT 1');
    if ($studentCourseStmt) {
        $studentCourseStmt->bind_param('i', $selectedStudentId);
        $studentCourseStmt->execute();
        $selectedCourseId = (string) (($studentCourseStmt->get_result()->fetch_assoc()['course_id'] ?? '') ?: '');
        $studentCourseStmt->close();
    }
}
if ($selectedCourseId === '') { $selectedCourseId = (string) ($courses[0]['course_id'] ?? ''); }
$students = $selectedCourseId !== '' ? mmh_parent_report_students($conn, $selectedCourseId) : [];
$records = mmh_previous_progress_rows($conn, $selectedCourseId);
$editId = (int) ($_GET['edit'] ?? 0);
$editRecord = null;
foreach ($records as $record) {
    if ((int) ($record['id'] ?? 0) === $editId) { $editRecord = $record; break; }
}
$flash = $_SESSION['previous_progress_flash'] ?? null;
unset($_SESSION['previous_progress_flash']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Progress | <?= $escape($site_name ?? 'Math Mastery Hub') ?></title>
  <?php include 'layouts/admin/header.php'; ?>
  <style>
    .previous-progress-admin{margin-top:55px;padding:24px;max-width:1280px}.previous-progress-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);padding:20px;margin-bottom:18px}.previous-progress-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.previous-progress-grid label{display:grid;gap:6px;font-size:.78rem;font-weight:700;color:var(--text-muted)}.previous-progress-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}.previous-progress-table{width:100%;border-collapse:separate;border-spacing:0}.previous-progress-table th,.previous-progress-table td{padding:11px 10px;border-bottom:1px solid var(--border);vertical-align:top}.previous-progress-table th{font-size:.76rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em}.previous-progress-table strong{color:var(--text-primary)}.previous-progress-muted{color:var(--text-muted);font-size:.82rem}.previous-progress-import-help{font-size:.82rem;color:var(--text-muted);line-height:1.55}@media(max-width:900px){.previous-progress-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.previous-progress-table{display:block;overflow-x:auto}}@media(max-width:560px){.previous-progress-admin{padding:14px}.previous-progress-grid{grid-template-columns:1fr}}
  </style>
</head>
<body class="dash ds-bg-primary"><div class="col-12 d-flex"><?php include 'layouts/admin/aside.php'; ?><div class="main-content in-active"><?php include 'layouts/admin/top-nav.php'; ?>
<main class="previous-progress-admin">
  <section class="previous-progress-card">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
      <div><div class="course-manager-eyebrow">Student learning history</div><h1 class="h3 mb-1">Previous Progress</h1><p class="ds-text-muted mb-0">Store teacher-recorded progress from learning before Math Mastery Hub. These are summary totals only, not generated lessons or activity rows.</p></div>
      <form method="get" action="<?= $escape($base . '/admin/previous-progress') ?>" class="d-flex gap-2 align-items-end flex-wrap"><input type="hidden" name="student_id" value="<?= (int) $selectedStudentId ?>"><label class="mb-0 previous-progress-muted">Course<select class="form-control form-control-sm mt-1" name="course_id" onchange="this.form.submit()"><option value="">All courses</option><?php foreach ($courses as $course): ?><option value="<?= $escape($course['course_id']) ?>" <?= $selectedCourseId === (string) $course['course_id'] ? 'selected' : '' ?>><?= $escape($course['course_title']) ?></option><?php endforeach ?></select></label></form>
    </div>
  </section>
  <?php if (is_array($flash)): ?><div class="alert alert-<?= !empty($flash['ok']) ? 'success' : 'danger' ?>"><?= $escape($flash['message'] ?? '') ?></div><?php endif; ?>

  <section class="previous-progress-card">
    <h2 class="h5 mb-3"><?= $editRecord ? 'Edit learning history' : 'Add previous progress' ?></h2>
    <form method="post" action="<?= $escape($base . '/admin/requests/previous-progress/save') ?>">
      <input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>">
      <div class="previous-progress-grid">
        <label>Course<select name="course_id" class="form-control" required data-previous-course><option value="">Select course</option><?php foreach ($courses as $course): ?><option value="<?= $escape($course['course_id']) ?>" <?= ($editRecord ? (string) $editRecord['course_id'] : $selectedCourseId) === (string) $course['course_id'] ? 'selected' : '' ?>><?= $escape($course['course_title']) ?></option><?php endforeach ?></select></label>
        <label>Student<select name="student_id" class="form-control" required data-previous-student><option value="">Select student</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['user_id'] ?>" <?= ($editRecord && (int) $editRecord['student_id'] === (int) $student['user_id']) || (!$editRecord && $selectedStudentId === (int) $student['user_id']) ? 'selected' : '' ?>><?= $escape($student['full_name'] ?: $student['username']) ?></option><?php endforeach ?></select></label>
        <label>Homework completed<input class="form-control" type="number" min="0" step="1" name="homework_completed" value="<?= $editRecord ? (int) $editRecord['homework_completed'] : 0 ?>" required></label>
        <label>Homework total<input class="form-control" type="number" min="0" step="1" name="homework_total" value="<?= $editRecord ? (int) $editRecord['homework_total'] : 0 ?>" required></label>
        <label>Attendance completed<input class="form-control" type="number" min="0" step="1" name="attendance_completed" value="<?= $editRecord ? (int) $editRecord['attendance_completed'] : 0 ?>" required></label>
        <label>Attendance total<input class="form-control" type="number" min="0" step="1" name="attendance_total" value="<?= $editRecord ? (int) $editRecord['attendance_total'] : 0 ?>" required></label>
        <label>Quiz average <span>Optional</span><input class="form-control" type="number" min="0" max="100" step="0.01" name="quiz_average" value="<?= $editRecord && $editRecord['quiz_average'] !== null ? $escape($editRecord['quiz_average']) : '' ?>" placeholder="Optional"></label>
        <label>Source <span>Optional</span><input class="form-control" type="text" maxlength="120" name="source" value="<?= $editRecord ? $escape($editRecord['source']) : '' ?>" placeholder="WhatsApp, Previous LMS, Manual Import"></label>
      </div>
      <label class="d-block mt-3 previous-progress-muted">Teacher Comment <span>Optional</span><textarea class="form-control mt-2" maxlength="1000" rows="3" name="teacher_comment" placeholder="Optional factual note about the student’s previous learning."><?= $editRecord ? $escape($editRecord['teacher_comment']) : '' ?></textarea></label>
      <div class="previous-progress-actions"><button class="btn btn-primary" type="submit">Save previous progress</button><?php if ($editRecord): ?><a class="btn btn-outline-secondary" href="<?= $escape($base . '/admin/previous-progress?course_id=' . rawurlencode((string) $selectedCourseId)) ?>">Cancel edit</a><?php endif; ?></div>
    </form>
  </section>

  <section class="previous-progress-card">
    <h2 class="h5 mb-2">Import Previous Progress</h2>
    <p class="previous-progress-import-help">Upload CSV or XLSX with headers: <code>course_id</code>, <code>student_id</code>, <code>homework_completed</code>, <code>homework_total</code>, <code>attendance_completed</code>, <code>attendance_total</code>, optional <code>quiz_average</code>, <code>source</code>, <code>teacher_comment</code>.</p>
    <form method="post" action="<?= $escape($base . '/admin/requests/previous-progress/import') ?>" enctype="multipart/form-data" class="previous-progress-actions">
      <input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>">
      <input class="form-control" style="max-width:420px" type="file" name="progress_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      <button class="btn btn-outline-primary" type="submit">Import file</button>
    </form>
  </section>

  <section class="previous-progress-card">
    <h2 class="h5 mb-3">Saved previous progress</h2>
    <?php if (!$records): ?><div class="previous-progress-muted">No previous progress records have been saved for this filter.</div><?php else: ?>
      <table class="previous-progress-table">
        <thead><tr><th>Student</th><th>Course</th><th>Homework</th><th>Attendance</th><th>Quiz Avg.</th><th>Source / Comment</th><th></th></tr></thead>
        <tbody><?php foreach ($records as $record): ?><tr>
          <td><strong><?= $escape($record['full_name'] ?: $record['username']) ?></strong><div class="previous-progress-muted">ID <?= (int) $record['student_id'] ?></div></td>
          <td><?= $escape($record['course_title']) ?><div class="previous-progress-muted"><?= $escape($record['course_id']) ?></div></td>
          <td><?= (int) $record['homework_completed'] ?> / <?= (int) $record['homework_total'] ?></td>
          <td><?= (int) $record['attendance_completed'] ?> / <?= (int) $record['attendance_total'] ?></td>
          <td><?= $record['quiz_average'] === null ? '—' : $escape(rtrim(rtrim(number_format((float) $record['quiz_average'], 2), '0'), '.')) . '%' ?></td>
          <td><strong><?= $escape($record['source'] ?: 'Teacher Record') ?></strong><?php if ((string) ($record['teacher_comment'] ?? '') !== ''): ?><div class="previous-progress-muted"><?= $escape($record['teacher_comment']) ?></div><?php endif; ?></td>
          <td class="text-end"><a class="btn btn-outline-secondary btn-sm mb-1" href="<?= $escape($base . '/admin/previous-progress?course_id=' . rawurlencode((string) $record['course_id']) . '&edit=' . (int) $record['id']) ?>">Edit</a><form method="post" action="<?= $escape($base . '/admin/requests/previous-progress/delete') ?>" onsubmit="return confirm('Delete this previous progress record?')"><input type="hidden" name="_token" value="<?= $escape(mmh_auth_csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $record['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Delete</button></form></td>
        </tr><?php endforeach ?></tbody>
      </table>
    <?php endif; ?>
  </section>
</main></div></div>
<script>
document.querySelector('[data-previous-course]')?.addEventListener('change', function(){
  const url = new URL(window.location.href);
  url.searchParams.set('course_id', this.value);
  window.location.assign(url.toString());
});
</script>
</body></html>
