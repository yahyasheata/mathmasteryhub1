<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sidebar = file_get_contents($root . '/views/admin/layouts/admin/aside.php');
$courseContent = file_get_contents($root . '/views/admin/course-content.php');
$items = file_get_contents($root . '/views/admin/requests/items-item.php');
$legacyAdd = file_get_contents($root . '/views/admin/requests/add-assignment.php');
$legacyEdit = file_get_contents($root . '/views/admin/requests/edit-assignment.php');
$legacyDelete = file_get_contents($root . '/views/admin/requests/delete-assignment.php');
$legacyOverview = file_get_contents($root . '/views/admin/assignments.php');

if (preg_match('/href="assignments"/', $sidebar) || preg_match('/href="assignment-submissions"/', $sidebar)) {
    throw new RuntimeException('Standalone assignment navigation is still exposed.');
}
if (!str_contains($courseContent, 'data-template="classified_assignment"') || !str_contains($courseContent, '<span>Assignment</span>')) {
    throw new RuntimeException('Course Content no longer exposes the Assignment element.');
}
if (!str_contains($items, 'View submissions') || !str_contains($items, 'mmh_admin_assignment_operational_stats')) {
    throw new RuntimeException('Course Content assignment operations are missing.');
}
if (!str_contains($legacyAdd, 'Create assignments from the Assignment element inside Course Content.')) {
    throw new RuntimeException('Legacy assignment creation is not blocked.');
}
if (!str_contains($legacyEdit, 'Open this assignment from Course Content to edit it.')) {
    throw new RuntimeException('Legacy assignment editing is not compatibility-only.');
}
if (preg_match('/INSERT\s+INTO\s+assignments|UPDATE\s+assignments|DELETE\s+FROM\s+assignments/i', $legacyAdd . $legacyEdit . $legacyDelete . $legacyOverview)) {
    throw new RuntimeException('Legacy assignment mutation SQL remains.');
}
if (str_contains($legacyOverview, 'requests/assignment/')) {
    throw new RuntimeException('Legacy assignment AJAX mutation remains in the overview.');
}

echo "Admin assignment consolidation checks passed.\n";
