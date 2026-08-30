<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
require_once $root . '/inc/StudentCourseAccess.php';

$expected = "(i.archived_at IS NULL OR i.archived_at = '') AND (i.status IS NULL OR i.status = '' OR i.status = 'published')";
if (student_course_access_active_item_sql('i') !== $expected) {
    throw new RuntimeException('Canonical active-item predicate changed unexpectedly.');
}
foreach ([
    [['status' => 'published', 'archived_at' => null], true],
    [['status' => '', 'archived_at' => ''], true],
    [['status' => 'draft', 'archived_at' => null], false],
    [['status' => 'published', 'archived_at' => '2026-08-30 10:00:00'], false],
] as [$item, $active]) {
    if (student_course_access_item_is_active($item) !== $active) {
        throw new RuntimeException('Active-item helper misclassified an item.');
    }
}
$files = [
    'views/user/course.php' => "student_course_access_active_item_sql('course_items')",
    'views/user/my-courses.php' => '$activeCourseItemFilter = student_course_access_active_item_sql()',
    'views/user/requests/items-item.php' => 'student_course_access_active_item_sql()',
    'inc/StudentLearningJourney.php' => "student_course_access_active_item_sql('i')",
    'inc/CourseResourceNavigation.php' => 'student_course_access_ordered_items',
    'inc/StudentResourceGateway.php' => 'student_course_access_item',
];
foreach ($files as $file => $needle) {
    $source = file_get_contents($root . '/' . $file);
    if (!is_string($source) || !str_contains($source, $needle)) {
        throw new RuntimeException("Student visibility path is not wired: {$file}");
    }
}
$courseSource = file_get_contents($root . '/views/user/course.php');
if (str_contains($courseSource, "LEFT JOIN course_items ON courses.course_id = course_items.course_id\n        AND (course_items.status IS NULL")) {
    throw new RuntimeException('Main course list still has the pre-archive predicate.');
}
$deleteSource = file_get_contents($root . '/inc/AdminCourseService.php');
if (!is_string($deleteSource) || !str_contains($deleteSource, 'UPDATE course_items SET archived_at')) {
    throw new RuntimeException('Admin deletion does not use the archive lifecycle.');
}
echo "student_course_item_visibility=active_rule=PASS archived_excluded=PASS list_counts_navigation_gateway=PASS history_preserved=PASS\n";
