<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/AdminCourseService.php');
$view = file_get_contents($root . '/views/admin/course-content.php');
$renderer = file_get_contents($root . '/views/admin/requests/items-item.php');
$bulk = file_get_contents($root . '/views/admin/requests/bulk-items.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
foreach (['function mmh_admin_course_archive_item', 'archived_at', "timed_exams SET deleted_at", 'begin_transaction', 'rollback'] as $needle) $assert(str_contains($service, $needle), 'Archive service contract missing: ' . $needle);
foreach (['Delete this item?', 'Delete item', 'data-item-activity', '_token: adminCsrfToken', "action === 'delete-item'"] as $needle) $assert(str_contains($view . $renderer, $needle), 'Delete UI contract missing: ' . $needle);
$assert(str_contains($renderer, "archived_at IS NULL OR archived_at = ''"), 'Archived items are not excluded from the manager query.');
$assert(str_contains($view, "COUNT(*) AS total FROM course_items WHERE course_id = ?' . \$item_archive_filter"), 'Course summary count does not exclude archived items.');
foreach (["\$_SERVER['REQUEST_METHOD'] !== 'POST'", "bulk_items_post('_method') !== 'BULK'", "\$action === 'delete'", "UPDATE course_items SET archived_at"] as $needle) $assert(str_contains($bulk, $needle), 'Delete endpoint contract missing: ' . $needle);
foreach (['DELETE FROM assignments', 'DELETE FROM assignment_submissions', 'DELETE FROM timed_exam_attempts', 'DELETE FROM student_learning_evidence'] as $forbidden) $assert(!str_contains($service . $bulk, $forbidden), 'Deletion must not cascade historical data: ' . $forbidden);
echo "course_content_delete=archive_safe=PASS csrf_post=PASS archived_filter=PASS history_protected=PASS\n";
