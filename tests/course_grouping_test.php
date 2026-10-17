<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/CourseGroups.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(mmh_course_group_from_input('') === null, 'The None option must clear grouping.');
$assert(mmh_course_group_from_input('cambridge_a_level') === 'cambridge_a_level', 'Cambridge group key was rejected.');
$assert(mmh_course_group_from_input('edexcel_a_level') === 'edexcel_a_level', 'Edexcel group key was rejected.');
$assert(mmh_course_group_from_input('pure_1') === false, 'An invalid group key was accepted.');

$card = static fn(string $courseId, string $title, ?string $group): array => [
    'course' => ['course_id' => $courseId, 'course_title' => $title, 'my_courses_group_key' => $group],
];
$cards = [
    $card('cam-p1', 'Pure 1', 'cambridge_a_level'),
    $card('cam-m1', 'Mechanics 1', 'cambridge_a_level'),
    $card('edx-p1', 'Pure 1', 'edexcel_a_level'),
    $card('edx-p2', 'Pure 2', 'edexcel_a_level'),
    $card('edx-p3', 'Pure 3', 'edexcel_a_level'),
    $card('edx-p4', 'Pure 4', 'edexcel_a_level'),
    $card('edx-m1', 'Mechanics 1', 'edexcel_a_level'),
    $card('edx-s1', 'Statistics 1', 'edexcel_a_level'),
    $card('other', 'Other Course', null),
];
$groups = mmh_group_enrolled_course_cards($cards);
$assert(array_column($groups, 'key') === ['cambridge_a_level', 'edexcel_a_level', ''], 'Groups are not kept separate or in stable order.');
$assert(array_column($groups[0]['cards'], 'course') === array_column(array_slice($cards, 0, 2), 'course'), 'Cambridge enrolled cards changed.');
$assert(count($groups[1]['cards']) === 6 && array_column($groups[1]['cards'], 'course') === array_column(array_slice($cards, 2, 6), 'course'), 'The six enrolled Edexcel Courses were not retained together.');
$visibleIds = [];
foreach ($groups as $group) foreach ($group['cards'] as $groupCard) $visibleIds[] = (string) $groupCard['course']['course_id'];
$assert(count($visibleIds) === count(array_unique($visibleIds)) && $visibleIds === ['cam-p1', 'cam-m1', 'edx-p1', 'edx-p2', 'edx-p3', 'edx-p4', 'edx-m1', 'edx-s1', 'other'], 'A Course was duplicated, omitted, or added by grouping.');
$assert($cards[0]['course']['course_id'] !== $cards[2]['course']['course_id'], 'Cambridge and Edexcel Pure 1 identities were merged.');

$ungrouped = mmh_group_enrolled_course_cards([$card('old-1', 'Legacy 1', null), $card('old-2', 'Legacy 2', null)]);
$assert(count($ungrouped) === 1 && $ungrouped[0]['label'] === '' && count($ungrouped[0]['cards']) === 2, 'All-ungrouped Courses no longer use the existing single-list presentation.');
$assert(count(mmh_group_enrolled_course_cards([])) === 0, 'An empty authorized Course list produced groups.');
$myCoursesSource = (string) file_get_contents(dirname(__DIR__) . '/views/user/my-courses.php');
$assert(str_contains($myCoursesSource, 'INNER JOIN courses ON course_logs.course_id = courses.course_id') && str_contains($myCoursesSource, 'WHERE course_logs.user_id =') && str_contains($myCoursesSource, 'mmh_group_enrolled_course_cards($courseCards)'), 'My Courses grouping is no longer limited to the existing enrollment-filtered card list.');

echo "Course grouping presentation and validation tests passed.\n";
