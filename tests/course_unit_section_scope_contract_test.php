<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");

$root = dirname(__DIR__);
require_once $root . '/inc/EnrollmentService.php';
require_once $root . '/inc/StudentCourseAccess.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$read = static function (string $path) use ($root): string {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) throw new RuntimeException('Unable to read ' . $path);
    return $source;
};
$enrollment = $read('inc/EnrollmentService.php');
$migration = $read('database/migrations/20261019_add_course_unit_section_capability.php');
$assert(str_contains($enrollment, 'SELECT supports_unit_section_access FROM courses WHERE course_id = ?'), 'Central Course capability helper does not use the Course database value.');
$assert(!str_contains($enrollment, 'MMH_UNIT_SECTION_ACCESS_COURSE_ID'), 'Central Course capability helper still uses a permanent single-ID restriction.');
$assert(str_contains($migration, "course_id IN ('3078', '7656057')"), 'Capability migration does not seed both explicitly designated Course IDs.');
$assert(str_contains($migration, 'TINYINT(1) NOT NULL DEFAULT 0'), 'Capability migration does not default all Courses to unsupported.');

// Unit enforcement stays generic: a stored restriction is still checked for
// any Course, while this feature's Admin/write capability remains Course-scoped.
$assert(student_course_access_unit_allowed('shared', null), 'Unclassified enrollment lost Shared Section access.');
$assert(!student_course_access_unit_allowed('unit1', null) && !student_course_access_unit_allowed('unit2', null), 'Unclassified enrollment bypassed a restricted Section.');
$assert(student_course_access_unit_allowed('unit1', 'unit1') && !student_course_access_unit_allowed('unit2', 'unit1'), 'Unit 1 student access matrix changed.');
$assert(student_course_access_unit_allowed('unit2', 'unit2') && !student_course_access_unit_allowed('unit1', 'unit2'), 'Unit 2 student access matrix changed.');
$assert(student_course_access_unit_allowed('unit1', 'both') && student_course_access_unit_allowed('unit2', 'both'), 'Both Units access matrix changed.');

$sectionForm = $read('views/admin/requests/form-section.php');
$sectionSave = $read('views/admin/requests/add-section.php');
$courseEdit = $read('views/admin/requests/edit-course.php');
$courseCreate = $read('views/admin/requests/add-course.php');
$coursesPage = $read('views/admin/courses.php');
$studentsPage = $read('views/admin/course-students.php');
$addStudent = $read('views/admin/requests/addUser-course.php');
$transaction = $read('inc/TransactionLog.php');
$copy = $read('inc/CourseContentCopyService.php');

$assert(str_contains($enrollment, "'candidate' => \$supported && (\$candidate || \$restricted)"), 'Unit enrollment readiness is not scoped to the supported Course.');
$assert(str_contains($enrollment, '!mmh_course_unit_access_assignment_allowed($courseId, $unitAccess, $conn)'), 'Canonical enrollment writer does not reject unsupported Course classifications.');
$assert(str_contains($enrollment, '!mmh_course_unit_access_assignment_allowed($targetCourseId, $targetUnitAccess, $conn)'), 'Enrollment movement does not reject unsupported target classifications.');
$assert(str_contains($enrollment, '!mmh_course_supports_unit_section_access($courseId, $conn)'), 'Batch classification does not enforce the Course capability boundary.');
$assert(str_contains($sectionForm, '$unitAccessSupported') && str_contains($sectionForm, '$access_unit_control = $unitAccessSupported'), 'Section Access selector is not hidden outside the supported Course.');
$assert(str_contains($sectionSave, 'Unit-restricted Sections are available only in a Course with Unit Section Access enabled.'), 'Section save endpoint does not reject unsupported Unit access writes.');
$assert(str_contains($sectionSave, '$access_unit = $storedAccessUnit;') && str_contains($sectionSave, '$unitActivationRequired'), 'Section updates do not preserve omitted access or avoid reactivating unchanged restrictions.');
$assert(str_contains($sectionSave, '$preservingExistingUnsupportedRestriction') && str_contains($sectionSave, '$access_unit !== $storedAccessUnit'), 'Ordinary edits cannot safely preserve a legacy restriction on a Course where the capability is disabled.');
$assert(str_contains($studentsPage, '$unitAccessReady = !empty($unitAccessState[\'ready\']) && $unitAccessSupported'), 'Enrollment Unit controls are not Course-scoped.');
$assert(str_contains($courseEdit, 'name=\'supports_unit_section_access\'') && str_contains($courseEdit, 'mmh_course_unit_access_disable_blocker'), 'Course edit UI or backend capability safeguards are missing.');
$assert(str_contains($coursesPage, 'name="supports_unit_section_access"') && str_contains($courseCreate, 'supports_unit_section_access'), 'New Course capability is not an explicit Admin choice.');
$assert(str_contains($courseEdit, 'SELECT my_courses_group_key, supports_unit_section_access FROM courses WHERE course_id = ? LIMIT 1 FOR UPDATE'), 'Capability changes do not lock the authoritative Course row.');
$assert(str_contains($addStudent, 'mmh_course_unit_access_assignment_allowed') && str_contains($addStudent, "!empty(\$unitState['supported'])"), 'Direct enrollment path is missing its scoped write guard or UI gate.');
$enrollFunctionStart = strpos($addStudent, 'function add_user_course_enroll_student');
$enrollFunctionEnd = strpos($addStudent, "\nfunction ", $enrollFunctionStart + 1);
$enrollFunction = substr($addStudent, $enrollFunctionStart, $enrollFunctionEnd === false ? null : $enrollFunctionEnd - $enrollFunctionStart);
$assert(strpos($enrollFunction, 'mmh_course_unit_access_assignment_allowed') < strpos($enrollFunction, 'updateBalance('), 'Unsupported classification can reach the enrollment balance update.');
$assert(str_contains($transaction, 'mmh_course_unit_access_assignment_allowed'), 'Transaction/enrollment precheck does not reject unsupported classifications.');
$assert(str_contains($copy, 'self::assertSectionAccessCopyAllowed($source, $destinationCourseId, null, $conn)'), 'Section copy does not validate destination capability.');
$assert(str_contains($copy, 'foreach ($sections as $section) self::assertSectionAccessCopyAllowed($section, $newCourseId, $supportsUnitSectionAccess);'), 'Whole-Course copy does not validate restricted Sections before inserting the destination Course.');
$copyGuard = strpos($copy, 'foreach ($sections as $section) self::assertSectionAccessCopyAllowed($section, $newCourseId, $supportsUnitSectionAccess);');
$courseInsert = strpos($copy, "self::insertRow(\$conn, 'courses', \$courseValues);");
$assert($copyGuard !== false && $courseInsert !== false && $copyGuard < $courseInsert, 'Whole-Course copy can write before validating Unit Section access.');

echo "Course-scoped Unit Section Access contract passed.\n";
