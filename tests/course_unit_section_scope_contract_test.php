<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");

$root = dirname(__DIR__);
require_once $root . '/inc/EnrollmentService.php';
require_once $root . '/inc/StudentCourseAccess.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(MMH_UNIT_SECTION_ACCESS_COURSE_ID === '3078', 'Canonical Math OL Full Course ID changed.');
$assert(mmh_course_supports_unit_section_access('3078'), 'Math OL Full Course does not support Unit Section Access.');
foreach (['4359', '23', 'cambridge-pure-1', 'edexcel-pure-1', 'other-course'] as $courseId) {
    $assert(!mmh_course_supports_unit_section_access($courseId), 'Unsupported Course gained Unit Section capability: ' . $courseId);
}
$assert(mmh_course_unit_access_assignment_allowed('3078', null), 'Unclassified Full Course enrollment was rejected.');
$assert(mmh_course_unit_access_assignment_allowed('3078', 'unit1') && mmh_course_unit_access_assignment_allowed('3078', 'unit2') && mmh_course_unit_access_assignment_allowed('3078', 'both'), 'Valid Full Course Unit classifications were rejected.');
$assert(!mmh_course_unit_access_assignment_allowed('4359', 'unit1') && !mmh_course_unit_access_assignment_allowed('other-course', 'both'), 'Unsupported Course accepted a Unit classification.');

// Unit enforcement stays generic: a stored restriction is still checked for
// any Course, while this feature's Admin/write capability remains Course-scoped.
$assert(student_course_access_unit_allowed('shared', null), 'Unclassified enrollment lost Shared Section access.');
$assert(!student_course_access_unit_allowed('unit1', null) && !student_course_access_unit_allowed('unit2', null), 'Unclassified enrollment bypassed a restricted Section.');
$assert(student_course_access_unit_allowed('unit1', 'unit1') && !student_course_access_unit_allowed('unit2', 'unit1'), 'Unit 1 student access matrix changed.');
$assert(student_course_access_unit_allowed('unit2', 'unit2') && !student_course_access_unit_allowed('unit1', 'unit2'), 'Unit 2 student access matrix changed.');
$assert(student_course_access_unit_allowed('unit1', 'both') && student_course_access_unit_allowed('unit2', 'both'), 'Both Units access matrix changed.');

$read = static function (string $path) use ($root): string {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) throw new RuntimeException('Unable to read ' . $path);
    return $source;
};
$enrollment = $read('inc/EnrollmentService.php');
$sectionForm = $read('views/admin/requests/form-section.php');
$sectionSave = $read('views/admin/requests/add-section.php');
$studentsPage = $read('views/admin/course-students.php');
$addStudent = $read('views/admin/requests/addUser-course.php');
$transaction = $read('inc/TransactionLog.php');
$copy = $read('inc/CourseContentCopyService.php');

$assert(str_contains($enrollment, "'candidate' => \$supported && (\$candidate || \$restricted)"), 'Unit enrollment readiness is not scoped to the supported Course.');
$assert(str_contains($enrollment, '!mmh_course_unit_access_assignment_allowed($courseId, $unitAccess)'), 'Canonical enrollment writer does not reject unsupported Course classifications.');
$assert(str_contains($enrollment, '!mmh_course_unit_access_assignment_allowed($targetCourseId, $targetUnitAccess)'), 'Enrollment movement does not reject unsupported target classifications.');
$assert(str_contains($enrollment, '!mmh_course_supports_unit_section_access($courseId)'), 'Batch classification does not enforce the Course capability boundary.');
$assert(str_contains($sectionForm, '$unitAccessSupported') && str_contains($sectionForm, '$access_unit_control = $unitAccessSupported'), 'Section Access selector is not hidden outside the supported Course.');
$assert(str_contains($sectionSave, 'Unit-restricted Sections are available only in Math OL Full Course.'), 'Section save endpoint does not reject unsupported Unit access writes.');
$assert(str_contains($sectionSave, '$access_unit = $storedAccessUnit;') && str_contains($sectionSave, '$unitActivationRequired'), 'Section updates do not preserve omitted access or avoid reactivating unchanged restrictions.');
$assert(str_contains($studentsPage, '$unitAccessReady = !empty($unitAccessState[\'ready\']) && $unitAccessSupported'), 'Enrollment Unit controls are not Course-scoped.');
$assert(str_contains($addStudent, 'mmh_course_unit_access_assignment_allowed') && str_contains($addStudent, 'mmh_course_supports_unit_section_access'), 'Direct enrollment path is missing its scoped write guard or UI gate.');
$enrollFunctionStart = strpos($addStudent, 'function add_user_course_enroll_student');
$enrollFunctionEnd = strpos($addStudent, "\nfunction ", $enrollFunctionStart + 1);
$enrollFunction = substr($addStudent, $enrollFunctionStart, $enrollFunctionEnd === false ? null : $enrollFunctionEnd - $enrollFunctionStart);
$assert(strpos($enrollFunction, 'mmh_course_unit_access_assignment_allowed') < strpos($enrollFunction, 'updateBalance('), 'Unsupported classification can reach the enrollment balance update.');
$assert(str_contains($transaction, 'mmh_course_unit_access_assignment_allowed'), 'Transaction/enrollment precheck does not reject unsupported classifications.');
$assert(str_contains($copy, 'self::assertSectionAccessCopyAllowed($source, $destinationCourseId)'), 'Section copy does not validate destination capability.');
$assert(str_contains($copy, 'foreach ($sections as $section) self::assertSectionAccessCopyAllowed($section, $newCourseId);'), 'Whole-Course copy does not validate restricted Sections before inserting the destination Course.');
$copyGuard = strpos($copy, 'foreach ($sections as $section) self::assertSectionAccessCopyAllowed($section, $newCourseId);');
$courseInsert = strpos($copy, "self::insertRow(\$conn, 'courses', \$courseValues);");
$assert($copyGuard !== false && $courseInsert !== false && $copyGuard < $courseInsert, 'Whole-Course copy can write before validating Unit Section access.');

echo "Course-scoped Unit Section Access contract passed.\n";
