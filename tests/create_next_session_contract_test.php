<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$router = file_get_contents($root . '/index.php');
$courseView = file_get_contents($root . '/views/admin/course-content.php');
$view = file_get_contents($root . '/views/admin/course-next-session.php');
$service = file_get_contents($root . '/inc/CourseContentCopyService.php');
foreach ([$router, $courseView, $view, $service] as $source) if (!is_string($source)) throw new RuntimeException('Unable to inspect Create Next Session sources.');
if (!str_contains($router, "'/courses/{courseId}/next-session'") || !str_contains($router, "require __DIR__ . '/views/admin/course-next-session.php';")) throw new RuntimeException('Create Next Session route is not wired.');
if (!str_contains($router, 'mmh_admin_require_admin();') || !str_contains($router, 'mmh_admin_require_mutation();')) throw new RuntimeException('Create Next Session routes are not Admin/CSRF protected.');
if (!str_contains($courseView, 'Create Next Session') || !str_contains($courseView, '/next-session')) throw new RuntimeException('Course Content does not expose Create Next Session.');
foreach (['session_title', 'course_price', 'course_state', 'mmh_csrf_token', 'Draft/Hidden', 'Replace it independently from Edit Course'] as $needle) if (!str_contains($view, $needle)) throw new RuntimeException('Create Next Session form is missing: ' . $needle);
foreach (['createNextSession', 'begin_transaction', 'rollback', 'finalizeItemReferences', 'finalizeAssignmentReferences', "'status' => \$forceDraft ? 'draft'"] as $needle) if (!str_contains($service, $needle)) throw new RuntimeException('Create Next Session clone service is missing: ' . $needle);
if (str_contains($service, 'course_live_schedules')) throw new RuntimeException('Live session schedule data must not be copied.');
if (str_contains($service, 'INSERT INTO course_logs') || str_contains($service, 'INSERT INTO assignment_submissions')) throw new RuntimeException('Create Next Session must not copy enrollment or submission records.');
echo "Create Next Session contract passed.\n";
