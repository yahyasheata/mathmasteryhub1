<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
$view = file_get_contents($root . '/views/user/course.php');
$css = file_get_contents($root . '/resources/css/course-learning.css');
if (!is_string($view) || !is_string($css)) {
    throw new RuntimeException('Unable to inspect the student Course page sources.');
}

foreach ([
    '<aside class="course-learning-sidebar"' => 'The duplicate sidebar is still rendered.',
    'course-sidebar-title' => 'The sidebar Course title is still rendered.',
    'course-sidebar-description' => 'The sidebar Course description is still rendered.',
    'Browse Course Content' => 'The duplicate sidebar content action is still rendered.',
    'Course Level' => 'The unavailable Course Level placeholder is still rendered.',
    'Last Updated' => 'The unavailable Last Updated placeholder is still rendered.',
    'Progress not available' => 'The unavailable progress placeholder is still rendered.',
    'Remaining duration not available' => 'The unavailable duration placeholder is still rendered.',
    'course-navigation-disabled' => 'Disabled navigation placeholders are still rendered.',
] as $needle => $message) {
    if (str_contains($view, $needle)) {
        throw new RuntimeException($message);
    }
}

if (substr_count($view, '<h2><?=$course_title_html;?></h2>') !== 1) {
    throw new RuntimeException('The Course identity heading must appear exactly once.');
}
if (substr_count($view, '<?=$course_description_html;?>') !== 1) {
    throw new RuntimeException('The Course description must be rendered only once.');
}
if (!str_contains($view, 'if ($course_access_allowed && $course_item_count === 0)')
    || substr_count($view, 'No lessons have been released yet.') !== 1) {
    throw new RuntimeException('The enrolled empty-Course state is missing or duplicated.');
}
foreach ([
    'if ($has_continue_lesson)',
    'if ($previous_lesson_url !== \'\')',
    'if ($next_lesson_url !== \'\')',
    'if ($course_progress_available)',
    'student_course_access_active_item_sql(\'course_items\')',
    'mmh_course_resource_resolve(',
    'mmh_learning_journey_resolve(',
] as $required) {
    if (!str_contains($view, $required)) {
        throw new RuntimeException('Missing conditional UI or preserved learning behavior: ' . $required);
    }
}

if (!preg_match('/\.course-learning-shell\s*\{\s*display:\s*block;/', $css)
    || str_contains($css, '.course-overview-card')) {
    throw new RuntimeException('The student Course layout still reserves sidebar styling.');
}

echo "Student Course page simplification contract passed.\n";
