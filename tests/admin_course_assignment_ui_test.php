<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$renderer = file_get_contents($root . '/views/admin/requests/items-item.php');
$courseContent = file_get_contents($root . '/views/admin/course-content.php');
$css = file_get_contents($root . '/resources/css/course-manager.css');

foreach (['course-manager-assignment-row', 'course-manager-assignment-title-row', 'course-manager-assignment-surface', 'course-manager-assignment-icon', 'course-manager-assignment-detail-row', 'course-manager-assignment-stat', 'course-manager-assignment-stat-copy', 'course-manager-assignment-submissions', 'course-manager-assignment-edit', 'course-manager-assignment-link'] as $marker) {
    if (!str_contains((string) $renderer, $marker)) throw new RuntimeException("Assignment card marker missing: {$marker}.");
}
foreach (['course-manager-assignment-title-row .course-manager-edit-link', 'course-manager-assignment-surface', 'course-manager-assignment-detail-row', 'course-manager-assignment-stat-copy', 'course-manager-assignment-stat + .course-manager-assignment-stat', 'course-manager-assignment-title-row .course-manager-row-actions > .btn'] as $marker) {
    if (!str_contains((string) $css, $marker)) throw new RuntimeException("Assignment card CSS marker missing: {$marker}.");
}
if (!str_contains((string) $renderer, "data-manager-action='edit-item'")) throw new RuntimeException('Assignment edit action is missing.');
if (!str_contains((string) $renderer, 'assignment-submissions?assignment_id=')) throw new RuntimeException('Assignment submissions link is missing.');
if (!str_contains((string) $renderer, 'course-manager-drag course-builder-sort-handle')) throw new RuntimeException('Assignment drag handle is missing.');
if (!str_contains((string) $renderer, 'course-manager-select')) throw new RuntimeException('Assignment bulk-selection control is missing.');
if (!str_contains((string) $courseContent, 'course-manager.css?v=assignment-card-compact-20260809-r2')) throw new RuntimeException('Assignment card stylesheet cache-bust is missing.');

echo "Admin Course Content assignment UI regression checks passed.\n";
