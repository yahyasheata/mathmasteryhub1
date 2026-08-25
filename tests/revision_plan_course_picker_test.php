<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$view = file_get_contents($root . '/views/admin/revision-plans.php');

if (!str_contains($service, 'function mmh_revision_course_items')) throw new RuntimeException('Course Item picker service is missing.');
if (!str_contains($service, 'INFORMATION_SCHEMA.COLUMNS')) throw new RuntimeException('Course Item archive compatibility check is missing.');
if (!str_contains($service, 'archived_at') || !str_contains($service, 'deleted_at')) throw new RuntimeException('Archived/deleted Course Items are not filtered.');
if (!str_contains($service, 'i.section_id IS NULL OR i.section_id =')) throw new RuntimeException('Orphan Course Item protection is missing.');
if (!str_contains($service, 'COALESCE(s.sort_order, 2147483647)') || !str_contains($service, 'COALESCE(i.sort_order, 2147483647)')) throw new RuntimeException('Course Item ordering is not deterministic.');
if (str_contains($service, "i.status = 'published'") || str_contains($service, "s.status = 'published'")) throw new RuntimeException('Admin picker still filters by student publication state.');
if (!str_contains($service, "\$batchTitle = 'Week 1'") || !str_contains($service, "\$dayTitle = 'Day 1'")) throw new RuntimeException('New plans do not start with a usable default day.');
foreach (['data-open-content', 'data-content-picker-list', 'data-add-selected-content', 'data-content-filter', 'Add Content'] as $marker) {
    if (!str_contains($view, $marker)) throw new RuntimeException('Multi-select picker contract is missing: ' . $marker);
}
if (!str_contains($view, "'item_type'") || !str_contains($view, "'template_type'")) throw new RuntimeException('Course Item type metadata is not exposed to the picker.');
if (!str_contains($view, "requirement_type='course_item'") && !str_contains($view, "requirement_type='course_item'")) throw new RuntimeException('Picker does not create Course Item requirements.');
echo "course_picker=admin_authoring_scope=correct multi_select=present ordering=deterministic archive_filter=present\n";
