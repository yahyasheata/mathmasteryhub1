<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$header = file_get_contents($root . '/views/admin/layouts/admin/header.php');
$feedbackJs = file_get_contents($root . '/resources/js/admin-feedback.js');
$shellCss = file_get_contents($root . '/resources/css/admin-shell.css');
$courseContent = file_get_contents($root . '/views/admin/course-content.php');
$courses = file_get_contents($root . '/views/admin/courses.php');

if (!str_contains((string) $header, 'resources/js/admin-feedback.js')) throw new RuntimeException('Shared admin feedback script is not loaded.');
foreach (['textContent', 'role', 'aria-live', 'setTimeout', 'mmhAdminFeedback'] as $marker) {
    if (!str_contains((string) $feedbackJs, $marker)) throw new RuntimeException("Feedback accessibility/safety marker missing: {$marker}.");
}
if (!str_contains((string) $feedbackJs, 'window.Swal')) throw new RuntimeException('Legacy SweetAlert compatibility bridge is missing.');
foreach (['position: fixed', 'max-width: min(28rem', 'pointer-events: none', 'mmh-admin-feedback--success', 'mmh-admin-feedback--error'] as $marker) {
    if (!str_contains((string) $shellCss, $marker)) throw new RuntimeException("Compact feedback CSS marker missing: {$marker}.");
}
if (!str_contains((string) $courseContent, 'window.mmhAdminFeedback.show(type, message)')) throw new RuntimeException('Course Content does not use the canonical feedback mechanism.');
if (!str_contains((string) $courses, 'window.mmhAdminFeedback.error(message)')) throw new RuntimeException('Legacy Course Content errors still use a blocking alert.');
if (preg_match('/var Toast\s*=\s*Swal\.mixin|toast:\s*true/', (string) $courseContent . (string) $courses)) throw new RuntimeException('Course Content still defines a SweetAlert toast implementation.');
if (!str_contains((string) $courseContent, "Discard unsaved changes?")) throw new RuntimeException('Intentional confirmation dialog was removed.');
if (preg_match('/mmh-admin-feedback__message[^\n]*innerHTML/', (string) $feedbackJs)) throw new RuntimeException('Feedback message is rendered as unsanitized HTML.');

echo "Admin feedback regression checks passed.\n";
