<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/views/admin/past-papers.php');
$handler = file_get_contents($root . '/views/admin/requests/save-paper-past-papers.php');
$service = file_get_contents($root . '/inc/PastPapers.php');

foreach (['quick-add', 'quick-syllabus', 'quick-paper-name', 'name="quick_resources[', 'Upload file instead', 'Save &amp; Publish', 'Advanced settings', 'Manage Papers'] as $marker) {
    if (!str_contains($page, $marker)) throw new RuntimeException("Quick-add UI marker missing: {$marker}");
}
if (!str_contains($handler, "!empty(\$_POST['quick_add'])") || !str_contains($handler, 'mmh_past_quick_add')) throw new RuntimeException('Quick-add handler wiring is missing.');
if (!str_contains($page, '$rawDriveFilter = isset($_GET[\'drive_filter\'])')) throw new RuntimeException('Drive filter default handling is missing.');
foreach (['mmh_past_parse_paper_name', 'mmh_past_quick_add', 'begin_transaction', 'mmh_past_save_resource'] as $marker) {
    if (!str_contains($service, $marker)) throw new RuntimeException("Quick-add service marker missing: {$marker}");
}

echo "Past Paper quick-add contract checks passed." . PHP_EOL;
