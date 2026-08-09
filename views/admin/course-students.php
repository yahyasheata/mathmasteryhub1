<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';

$conn = db();
$base = rtrim((string) ($baseUrl ?? mmh_site_public_base_path()), '/');
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$courseId = trim((string) ($courseId ?? ''));
$course = null;
$courseStmt = $conn->prepare('SELECT course_id, course_title, course_state, archived_at FROM courses WHERE course_id = ? LIMIT 1');
if ($courseStmt) {
    $courseStmt->bind_param('s', $courseId);
    $courseStmt->execute();
    $course = $courseStmt->get_result()->fetch_assoc();
    $courseStmt->close();
}
if (!$course) {
    http_response_code(404);
    exit('Course not found.');
}

$students = [];
$studentsStmt = $conn->prepare('SELECT u.user_id, u.full_name, u.username, u.status, MIN(cl.purchase_date) AS enrolled_at, COUNT(cl.id) AS enrollment_rows FROM course_logs cl INNER JOIN users u ON u.user_id = cl.user_id WHERE cl.course_id = ? AND u.role = \'user\' GROUP BY u.user_id, u.full_name, u.username, u.status ORDER BY u.full_name ASC, u.username ASC');
if ($studentsStmt) {
    $studentsStmt->bind_param('s', $courseId);
    $studentsStmt->execute();
    $students = $studentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $studentsStmt->close();
}

$targetCourses = [];
$targetStmt = $conn->prepare("SELECT course_id, course_title, course_state FROM courses WHERE archived_at IS NULL AND course_state IN ('public', 'private') AND course_id <> ? ORDER BY course_title ASC, course_id ASC");
if ($targetStmt) {
    $targetStmt->bind_param('s', $courseId);
    $targetStmt->execute();
    $targetCourses = $targetStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $targetStmt->close();
}
$flashType = (string) ($_GET['enrollment'] ?? '');
$flashMessage = (string) ($_GET['message'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enrolled Students | <?= $escape($site_name ?? 'Math Mastery Hub') ?></title>
    <?php include 'layouts/admin/header.php'; ?>
    <style>
        .course-students-page{max-width:1240px;margin:70px auto 0;padding:24px}.course-students-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);padding:20px}.course-students-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}.course-students-heading h1{margin:.15rem 0 .35rem}.course-students-heading p{color:var(--text-muted);margin:0}.course-students-toolbar{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin-bottom:16px}.course-students-toolbar label{display:grid;gap:5px;min-width:280px;color:var(--text-muted);font-size:.78rem;font-weight:700}.course-students-table{width:100%;border-collapse:collapse}.course-students-table th,.course-students-table td{padding:12px 10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle}.course-students-table th{color:var(--text-muted);font-size:.74rem;letter-spacing:.04em;text-transform:uppercase}.course-students-table td strong{display:block;color:var(--text-primary)}.course-students-table td small{color:var(--text-muted)}.course-students-empty{border:1px dashed var(--border-strong);border-radius:var(--radius-md);color:var(--text-muted);padding:28px;text-align:center}.course-students-count{color:var(--text-secondary);font-size:.85rem}.course-students-note{color:var(--text-muted);font-size:.82rem;margin:.75rem 0 0}.course-students-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:16px}.course-students-actions select{min-width:280px}.course-students-actions .btn{min-height:38px}@media(max-width:760px){.course-students-page{padding:14px}.course-students-card{padding:14px}.course-students-table-wrap{overflow-x:auto}.course-students-table{min-width:680px}.course-students-toolbar label,.course-students-actions select{min-width:100%;width:100%}.course-students-heading h1{font-size:1.35rem}}
    </style>
</head>
<body class="dash ds-bg-primary">
<div class="col-12 d-flex">
    <?php include 'layouts/admin/aside.php'; ?>
    <div class="main-content in-active">
        <?php include 'layouts/admin/top-nav.php'; ?>
        <main class="course-students-page">
            <section class="course-students-card">
                <div class="course-students-heading">
                    <div>
                        <a class="course-manager-back-link" href="<?= $escape($base . '/admin/courses') ?>"><span class="fas fa-arrow-left" aria-hidden="true"></span> Back to courses</a>
                        <p class="course-manager-eyebrow">Course management</p>
                        <h1>Enrolled Students</h1>
                        <p><?= $escape($course['course_title']) ?> · Manage enrollment access without changing student history.</p>
                    </div>
                    <span class="course-students-count"><?= count($students) ?> enrolled student<?= count($students) === 1 ? '' : 's' ?></span>
                </div>
                <?php if ($flashMessage !== ''): ?><div class="alert alert-<?= $flashType === 'success' ? 'success' : 'danger' ?>" role="alert"><?= $escape($flashMessage) ?></div><?php endif; ?>
                <?php if (!$students): ?>
                    <div class="course-students-empty"><span class="fas fa-user-graduate" aria-hidden="true"></span><p class="mb-0">No enrolled students are attached to this course.</p></div>
                <?php else: ?>
                    <form method="post" action="<?= $escape($base . '/admin/courses/' . rawurlencode($courseId) . '/students') ?>" id="course-students-form">
                        <input type="hidden" name="_token" value="<?= $escape(mmh_admin_csrf_token()) ?>">
                        <input type="hidden" name="course_id" value="<?= $escape($courseId) ?>">
                        <div class="course-students-toolbar">
                            <button class="btn btn-outline-secondary btn-sm" type="button" data-select-all>Select all</button>
                            <button class="btn btn-outline-secondary btn-sm" type="button" data-clear-all>Clear selection</button>
                            <span class="course-students-count" data-selected-count>0 selected</span>
                        </div>
                        <div class="course-students-table-wrap">
                            <table class="course-students-table">
                                <thead><tr><th><span class="visually-hidden">Select</span></th><th>Student</th><th>Account</th><th>Enrollment status</th><th>Enrolled</th></tr></thead>
                                <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><input type="checkbox" name="student_ids[]" value="<?= (int) $student['user_id'] ?>" data-student-check aria-label="Select <?= $escape($student['full_name'] ?: $student['username']) ?>"></td>
                                        <td><strong><?= $escape($student['full_name'] ?: $student['username']) ?></strong><small>ID <?= (int) $student['user_id'] ?></small></td>
                                        <td><?= $escape($student['username']) ?></td>
                                        <td><?= (string) ($student['status'] ?? '') === '1' ? 'Active' : 'Inactive' ?><?php if ((int) ($student['enrollment_rows'] ?? 1) > 1): ?><small>Duplicate enrollment rows detected</small><?php endif; ?></td>
                                        <td><?= $student['enrolled_at'] ? $escape(date('j M Y', strtotime((string) $student['enrolled_at']))) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="course-students-note">Removing or moving students changes course access only. Accounts, submissions, grades, attendance, progress, and reports remain preserved.</p>
                        <div class="course-students-actions">
                            <select class="form-select" name="target_course_id" aria-label="Target course for move">
                                <option value="">Choose target course to move…</option>
                                <?php foreach ($targetCourses as $target): ?><option value="<?= $escape($target['course_id']) ?>"><?= $escape($target['course_title']) ?> · <?= ucfirst($escape($target['course_state'])) ?></option><?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-danger" type="submit" name="action" value="remove" data-enrollment-action="remove">Remove from course</button>
                            <button class="btn btn-primary" type="submit" name="action" value="move" data-enrollment-action="move">Move to selected course</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('course-students-form');
    if (!form) return;
    const checks = () => Array.from(form.querySelectorAll('[data-student-check]'));
    const updateCount = () => { const count = checks().filter(input => input.checked).length; const target = form.querySelector('[data-selected-count]'); if (target) target.textContent = count + ' selected'; };
    form.querySelector('[data-select-all]')?.addEventListener('click', () => { checks().forEach(input => { input.checked = true; }); updateCount(); });
    form.querySelector('[data-clear-all]')?.addEventListener('click', () => { checks().forEach(input => { input.checked = false; }); updateCount(); });
    checks().forEach(input => input.addEventListener('change', updateCount));
    form.addEventListener('submit', function (event) {
        const selected = checks().filter(input => input.checked).length;
        const action = event.submitter?.value || '';
        if (!selected) { event.preventDefault(); alert('Select at least one enrolled student.'); return; }
        if (action === 'move' && !form.querySelector('[name="target_course_id"]').value) { event.preventDefault(); alert('Choose a target course before moving students.'); return; }
        const title = action === 'remove' ? 'Remove ' + selected + ' student' + (selected === 1 ? '' : 's') + ' from this course?' : 'Move ' + selected + ' student' + (selected === 1 ? '' : 's') + ' to the selected course?';
        const detail = action === 'remove' ? 'Their accounts and historical submissions/progress will be preserved, but they will lose access to this course.' : 'Historical course data remains attached to the original course. Progress will not be fabricated or transferred.';
        if (!window.confirm(title + '\n\n' + detail)) event.preventDefault();
    });
});
</script>
</body>
</html>
