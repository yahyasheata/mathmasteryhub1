<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$router = file_get_contents($root . '/index.php');
$view = file_get_contents($root . '/views/admin/course-content.php');
$service = file_get_contents($root . '/inc/CourseContentCopyService.php');
$statusHandler = file_get_contents($root . '/views/admin/requests/status-item.php');
foreach ([$router, $view, $service, $statusHandler] as $source) if (!is_string($source)) throw new RuntimeException('Unable to inspect Course Content copy sources.');
foreach ([
    "'/requests/course-copy/options'",
    "'/requests/course-copy/item'",
    "'/requests/course-copy/section'",
] as $route) if (!str_contains($router, $route)) throw new RuntimeException('Missing explicit course-copy route: ' . $route);
if (!str_contains($router, 'mmh_admin_require_mutation();') || !str_contains($router, "views/admin/requests/copy-item.php") || !str_contains($router, "views/admin/requests/copy-section.php")) throw new RuntimeException('Course-copy mutation routes are not protected.');
if (!str_contains($view, "duplicate-item") || !str_contains($view, "duplicate-section") || !str_contains($view, 'copy-item') || !str_contains($view, 'copy-section') || !str_contains($view, 'data-manager-bulk="copy"') || !str_contains($view, 'requests/course-copy/options')) throw new RuntimeException('Course Content copy actions are not wired to the modal.');
foreach (['copyItem', 'copyItems', 'copySection', 'begin_transaction', 'rollback', 'Model-answer access rows are intentionally not copied', 'status'] as $needle) if (!str_contains($service, $needle)) throw new RuntimeException('Copy service missing contract: ' . $needle);
foreach (['timed_exams SET status = ?', 'begin_transaction', 'commit'] as $needle) if (!str_contains($statusHandler, $needle)) throw new RuntimeException('Timed Exam publication synchronization missing contract: ' . $needle);
echo "Course Content copy contract passed.\n";
