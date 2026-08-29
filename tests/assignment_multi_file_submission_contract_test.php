<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$form = file_get_contents($root . '/views/admin/requests/form-item.php');
$save = file_get_contents($root . '/views/admin/requests/add-item.php');
$resolver = file_get_contents($root . '/inc/CourseResourceResolver.php');
$renderer = file_get_contents($root . '/inc/CourseHomeworkRenderer.php');
$handler = file_get_contents($root . '/views/user/requests/submission-assignment.php');
$js = file_get_contents($root . '/resources/js/course-homework.js');
foreach ([$form, $save, $resolver, $renderer, $handler, $js] as $source) if (!is_string($source)) throw new RuntimeException('Unable to inspect multi-file Homework sources.');
$combined = $form . $save . $resolver . $renderer;
foreach (["assignment_drive_url_2", 'homework_resource_2', 'homework-2', 'Homework PDF 2'] as $needle) {
    if (str_contains($combined, $needle)) throw new RuntimeException('Mistaken Homework PDF 2 path remains: ' . $needle);
}
foreach (['name=\'assignment_drive_url\'', 'submission_files[]', 'multiple', 'assignment_submission_files', 'mime_type', 'mmh_assignment_submission_max_files'] as $needle) {
    if (!str_contains($form . $renderer . $handler . $js . file_get_contents($root . '/inc/AssignmentProgress.php'), $needle)) throw new RuntimeException('Missing multi-file contract: ' . $needle);
}
if (!str_contains($renderer, 'data-homework-file-list') || !str_contains($js, 'Remove')) throw new RuntimeException('Selected-file UX is missing.');
if (str_contains($js, 'input.required =')) throw new RuntimeException('Custom Homework uploader still mutates native required validation.');
if (str_contains($renderer, 'name="submission_files[]" type="file" accept=".pdf,.doc,.docx" multiple required')) throw new RuntimeException('Custom Homework input still relies on native required validation.');
if (!str_contains($renderer, 'course-homework.js?v=')) throw new RuntimeException('Homework JavaScript asset is not cache-busted.');
foreach (["payload.delete('submission_files[]')", "payload.append('submission_files[]', file, file.name)", 'submissionFormData()'] as $needle) {
    if (!str_contains($js, $needle)) throw new RuntimeException('Canonical selected-file FormData contract is missing: ' . $needle);
}
if (!str_contains($handler, 'fileEntries') || !str_contains($handler, 'INSERT INTO assignment_submission_files')) throw new RuntimeException('Upload handler does not persist normalized child files.');
if (!str_contains(file_get_contents($root . '/inc/AssignmentSubmissionFiles.php'), 'student_course_access_authorized_course')) throw new RuntimeException('Protected submission-file route is missing enrollment authorization.');
echo "Multi-file Homework contract passed.\n";
