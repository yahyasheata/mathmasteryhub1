<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$form = file_get_contents($root . '/views/admin/requests/form-item.php');
$save = file_get_contents($root . '/views/admin/requests/add-item.php');
$resolver = file_get_contents($root . '/inc/CourseResourceResolver.php');
$renderer = file_get_contents($root . '/inc/CourseHomeworkRenderer.php');
foreach ([$form, $save, $resolver, $renderer] as $source) if (!is_string($source)) throw new RuntimeException('Unable to inspect Homework PDF 2 sources.');
foreach ([
    "name='assignment_drive_url'",
    "name='assignment_drive_url_2'",
    "homework_resource_2",
] as $needle) {
    if (!str_contains($form . $save . $resolver, $needle)) throw new RuntimeException('Missing Homework PDF 2 field/storage contract: ' . $needle);
}
foreach (['homework-2', 'PDF 2', 'data-homework-submission'] as $needle) {
    if (!str_contains($renderer, $needle)) throw new RuntimeException('Missing protected second Homework resource contract: ' . $needle);
}
if (str_contains($renderer, 'assignment_id.*homework-2')) throw new RuntimeException('Homework PDF 2 must not create a second submission path.');
echo "Two Homework PDF contract passed.\n";
