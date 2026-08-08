<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$courseContent = (string) file_get_contents($root . '/views/admin/course-content.php');
$security = (string) file_get_contents($root . '/inc/AdminSecurity.php');
$sectionForm = (string) file_get_contents($root . '/views/admin/requests/form-section.php');
$sectionSave = (string) file_get_contents($root . '/views/admin/requests/add-section.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(str_contains($courseContent, "url: 'requests/section/form'"), 'Section editor endpoint is missing.');
$assert(str_contains($courseContent, "data = $.extend({}, data, { _token: adminCsrfToken });"), 'Section editor request does not carry an explicit CSRF token.');
$assert(str_contains($courseContent, "_method: 'GET', _token: adminCsrfToken"), 'Section integrity request does not carry an explicit CSRF token.');
$assert(str_contains($security, "'form-section.php'"), 'Section editor handler is not allowlisted.');
$assert(str_contains($security, "'integrity-section.php'"), 'Section integrity handler is not allowlisted.');
$assert(str_contains($sectionForm, '$section_id = \'\';'), 'Create mode does not initialize the optional section ID.');
$assert(str_contains($sectionForm, '$is_edit = isset($_POST[\'section_id\'])'), 'Create/edit mode detection is missing.');
$assert(str_contains($sectionForm, "'section_id' => ''"), 'Create mode does not start with blank section values.');
$assert(!str_contains($sectionForm, 'INSERT INTO course_sections'), 'Opening the section form must not create a section.');
$assert(str_contains($sectionForm, 'section_id = ? AND course_id = ?'), 'Section editor does not enforce course ownership during lookup.');
$assert(str_contains($sectionSave, 'section_id = ? AND course_id = ? LIMIT 1'), 'Section update does not enforce course ownership.');
$assert(str_contains($security, "['mmh_csrf_token', 'csrf_token', '_token']"), 'Shared admin middleware does not accept the explicit form token.');

echo "Admin section editor regression checks passed.\n";
