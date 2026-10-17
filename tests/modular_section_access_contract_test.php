<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/inc/StudentCourseAccess.php';

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$assert(student_course_access_unit_allowed('shared', null), 'Unclassified students lost Shared Section access.');
$assert(student_course_access_unit_allowed('unit1', 'unit1') && !student_course_access_unit_allowed('unit2', 'unit1'), 'Unit 1 access matrix is incorrect.');
$assert(student_course_access_unit_allowed('unit2', 'unit2') && !student_course_access_unit_allowed('unit1', 'unit2'), 'Unit 2 access matrix is incorrect.');
$assert(student_course_access_unit_allowed('unit1', 'both') && student_course_access_unit_allowed('unit2', 'both'), 'Both Units access matrix is incorrect.');
$assert(!student_course_access_unit_allowed('unit1', null) && !student_course_access_unit_allowed('unit2', null), 'Unclassified students accessed a restricted Section.');
$assert(!student_course_access_unit_allowed('unit1', 'unlock_all') && !student_course_access_unit_allowed('unit2', 'unlock_all'), 'Unlock All bypassed Unit access.');

$root = dirname(__DIR__);
$sources = [
    'access' => file_get_contents($root . '/inc/StudentCourseAccess.php'),
    'gateway' => file_get_contents($root . '/inc/StudentResourceGateway.php'),
    'submission' => file_get_contents($root . '/views/user/requests/submission-assignment.php'),
    'submission_file' => file_get_contents($root . '/inc/AssignmentSubmissionFiles.php'),
    'timed_exam' => file_get_contents($root . '/inc/TimedExam.php'),
    'journey' => file_get_contents($root . '/inc/StudentLearningJourney.php'),
    'public_preview' => file_get_contents($root . '/views/public/course.php'),
    'student_course' => file_get_contents($root . '/views/user/course.php'),
    'admin_add_item' => file_get_contents($root . '/views/admin/requests/add-item.php'),
    'admin_reorder' => file_get_contents($root . '/inc/AdminCourseService.php'),
    'copy' => file_get_contents($root . '/inc/CourseContentCopyService.php'),
];
foreach ($sources as $name => $source) $assert(is_string($source), 'Unable to inspect ' . $name . ' access path.');
$sectionStateSource = substr($sources['access'], strpos($sources['access'], 'function student_course_access_section_state'));
$assert(str_contains($sectionStateSource, "student_course_access_unit_allowed(\$section['access_unit']") && strpos($sectionStateSource, 'student_course_access_unit_allowed($section') < strpos($sectionStateSource, 'student_course_access_section_unlock_state('), 'Section authorization is not checked before release/sequential rules.');
$assert(str_contains($sources['gateway'], 'student_course_access_selected_item('), 'Direct resource gateway does not use canonical selected-item authorization.');
$assert(str_contains($sources['submission'], 'student_course_access_section_state($conn, $course, $assignmentSectionId, $studentId)'), 'Homework submission does not check the canonical Course Item Section.');
$assert(strpos($sources['submission_file'], 'student_course_access_item($conn,') < strpos($sources['submission_file'], 'student_course_access_section_state($conn, $course, $sectionId'), 'Submission-file download does not authorize the canonical Course Item Section.');
$assert(strpos($sources['timed_exam'], 'student_course_access_unit_allowed($exam[') < strpos($sources['timed_exam'], 'mmh_timed_exam_create_attempt($conn, $exam'), 'Timed Exam Unit check occurs after attempt creation.');
$assert(str_contains($sources['timed_exam'], "student_course_access_unit_allowed(\$row['section_access_unit'"), 'Timed Exam summaries do not filter inaccessible Units.');
$assert(str_contains($sources['journey'], 'student_course_access_unit_entitlement($conn, $userId, $courseId)'), 'Learning Journey does not filter by Unit entitlement.');
$assert(str_contains($sources['public_preview'], 'student_course_access_unit_allowed(') && str_contains($sources['student_course'], 'student_course_access_unit_allowed('), 'Public or enrolled Course listing exposes all Unit Sections.');
$assert(str_contains($sources['admin_add_item'], "Unit-restricted Sections cannot use direct /uploads/ links") && str_contains($sources['admin_reorder'], 'cannot be moved into a Unit-restricted Section'), 'Admin can attach directly addressable uploads to restricted Sections.');
$assert(str_contains($sources['copy'], "'access_unit' => in_array("), 'Course copy does not preserve Section classification.');
echo "Modular Section Access policy and protected-route contract checks passed.\n";
