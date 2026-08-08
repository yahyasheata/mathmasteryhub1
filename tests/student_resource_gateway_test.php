<?php
declare(strict_types=1);

/** Focused contract coverage for the canonical authenticated course-item gateway. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/inc/StudentResourceGateway.php';

$normal = mmh_student_resource_url('https://example.test/', 'math-ol', 'lesson-1');
if ($normal !== 'https://example.test/user/course/resource/math-ol/lesson-1') {
    throw new RuntimeException('Normal resource URL does not use the canonical route.');
}

$recovery = mmh_student_resource_url('https://example.test', 'math-ol', 'lesson-1', [
    'recovery_plan_id' => 41,
    'recovery_task_id' => 102,
    'homework_part' => 'model-answer',
]);
if ($recovery !== 'https://example.test/user/course/resource/math-ol/lesson-1?recovery_plan=41&recovery_task=102&part=model-answer') {
    throw new RuntimeException('Recovery resource URL lost plan, task, or part context.');
}

if (mmh_student_resource_adapter(['action' => 'embed']) !== 'course_resource_viewer'
    || mmh_student_resource_adapter(['action' => 'recording_external']) !== 'course_resource_viewer'
    || mmh_student_resource_adapter(['action' => 'homework']) !== 'homework'
    || mmh_student_resource_adapter(['action' => 'timed_exam']) !== 'timed_exam'
    || mmh_student_resource_adapter(['action' => 'redirect']) !== 'external_redirect'
    || mmh_student_resource_adapter(['action' => 'unknown']) !== 'unavailable') {
    throw new RuntimeException('Viewer adapter mapping is incomplete.');
}

$gatewaySource = file_get_contents(dirname(__DIR__) . '/inc/StudentResourceGateway.php');
$routeSource = file_get_contents(dirname(__DIR__) . '/views/user/requests/open-course-resource.php');
if (!is_string($gatewaySource) || !is_string($routeSource)
    || !str_contains($gatewaySource, 'function mmh_student_resource_gateway')
    || !str_contains($gatewaySource, "role = 'user'")
    || !str_contains($gatewaySource, 'student_course_access_enrolled')
    || !str_contains($gatewaySource, 'student_course_access_selected_item')
    || !str_contains($gatewaySource, 'mmh_student_resource_recovery_context')
    || !str_contains($gatewaySource, 'course_resource_navigation')
    || !str_contains($gatewaySource, "'course_state' => \$courseState")
    || !str_contains($routeSource, 'mmh_student_resource_gateway')
    || !str_contains($routeSource, "'recovery_plan_id'")
    || !str_contains($routeSource, "'recovery_task_id'")) {
    throw new RuntimeException('Gateway authorization/context handoff is incomplete.');
}

$entryPoints = [
    'views/user/course.php',
    'views/user/my-courses.php',
    'views/user/assignments.php',
    'views/user/live-sessions.php',
    'views/user/recovery-plan.php',
    'views/user/analytics.php',
];
foreach ($entryPoints as $entryPoint) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $entryPoint);
    if (!is_string($source) || !str_contains($source, 'StudentResourceGateway.php')) {
        throw new RuntimeException('Authenticated entry point is not wired to the gateway: ' . $entryPoint);
    }
}

echo "gateway_url=passed adapters=passed authorization_contract=present entry_points=passed\n";
