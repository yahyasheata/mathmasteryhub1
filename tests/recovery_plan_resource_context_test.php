<?php
declare(strict_types=1);

/** Focused regression coverage for Recovery Plan resource navigation. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

function student_course_access_ordered_items(mysqli $conn, array $course, $userId): array
{
    return [
        ['item_id' => 'main-previous'],
        ['item_id' => 'main-current'],
        ['item_id' => 'main-next'],
    ];
}

require_once dirname(__DIR__) . '/inc/CourseResourceNavigation.php';
require_once dirname(__DIR__) . '/inc/RecoveryPlan.php';

$conn = mysqli_init();
$course = ['course_id' => 'math-ol'];
$planContext = [
    'valid' => true,
    'plan' => ['id' => 41, 'status' => 'active', 'items' => []],
    'task' => ['id' => 102, 'item_id' => 'workshop-video'],
    'ordered_tasks' => [
        ['id' => 101, 'item_id' => 'workshop-notes', 'is_locked' => false, 'is_completed' => true],
        ['id' => 102, 'item_id' => 'workshop-video', 'is_locked' => false, 'is_completed' => false],
        ['id' => 103, 'item_id' => 'workshop-sheet', 'is_locked' => false, 'is_completed' => false],
    ],
    'navigation' => ['position' => 2, 'total' => 3],
];
$planContext['plan']['items'] = $planContext['ordered_tasks'];

$recovery = course_resource_navigation($conn, $course, 7, 'workshop-video', $planContext);
if (($recovery['previous']['item_id'] ?? '') !== 'workshop-notes' || ($recovery['next']['item_id'] ?? '') !== 'workshop-sheet' || empty($recovery['plan'])) {
    throw new RuntimeException('Recovery navigation did not stay inside the ordered plan.');
}

$ordinary = course_resource_navigation($conn, $course, 7, 'main-current', []);
if (($ordinary['previous']['item_id'] ?? '') !== 'main-previous' || ($ordinary['next']['item_id'] ?? '') !== 'main-next' || !empty($ordinary['plan'])) {
    throw new RuntimeException('Ordinary course navigation no longer uses the course sequence.');
}

$resourceUrlSource = file_get_contents(dirname(__DIR__) . '/inc/RecoveryPlan.php');
$resourceUrl = mmh_recovery_plan_resource_url('https://example.test', 'math-ol', 'workshop-video', 41, 102);
if ($resourceUrl !== 'https://example.test/user/course/resource/math-ol/workshop-video?recovery_plan=41&recovery_task=102'
    || !is_string($resourceUrlSource)
    || !str_contains($resourceUrlSource, "'?recovery_plan=' . \$planId . '&recovery_task=' . \$taskId")) {
    throw new RuntimeException('Recovery resource URLs do not preserve both plan and task identifiers.');
}

$routeSource = file_get_contents(dirname(__DIR__) . '/views/user/requests/open-course-resource.php');
if (!is_string($routeSource)
    || !str_contains($routeSource, 'mmh_student_resource_gateway')
    || !str_contains($routeSource, "'recovery_plan_id' => \$requestedPlanId")
    || !str_contains($routeSource, "'recovery_task_id' => \$requestedTaskId")
    || !str_contains($routeSource, "'Recovery Plan unavailable'")
    || !str_contains($routeSource, 'array $planContext = []')
    || !str_contains($routeSource, 'course_resource_render_viewer($conn, $baseUrl, $userId, $course, $selection, $itemId, $resource, $course_resource_plan_context, $gateway)')) {
    throw new RuntimeException('Recovery context boundary or explicit viewer handoff is missing.');
}

echo "recovery_context=present missing_context=guarded ordinary_navigation=passed\n";
