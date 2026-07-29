<?php
declare(strict_types=1);

/**
 * Adds the optional, admin-entered expected duration for each course item.
 *
 * Run from the project root:
 * php database/migrations/20260716_add_course_item_duration_minutes.php
 *
 * This migration is intentionally CLI-only and idempotent. It does not alter
 * lesson HTML, template data, or any existing course-item value.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';

$conn = db();

$tableStmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
if (!$tableStmt) {
    throw new RuntimeException('Unable to inspect the course_items table: ' . $conn->error);
}

$table = 'course_items';
$tableStmt->bind_param('s', $table);
$tableStmt->execute();
$tableExists = (int) (($tableStmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
$tableStmt->close();

if (!$tableExists) {
    throw new RuntimeException('The course_items table does not exist. No migration was applied.');
}

$columnStmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
if (!$columnStmt) {
    throw new RuntimeException('Unable to inspect the duration_minutes column: ' . $conn->error);
}

$column = 'duration_minutes';
$columnStmt->bind_param('ss', $table, $column);
$columnStmt->execute();
$columnExists = (int) (($columnStmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
$columnStmt->close();

if ($columnExists) {
    echo "duration_minutes already exists on course_items; no changes made.\n";
    exit(0);
}

if (!$conn->query('ALTER TABLE `course_items` ADD COLUMN `duration_minutes` INT UNSIGNED NULL AFTER `template_data`')) {
    throw new RuntimeException('Unable to add duration_minutes: ' . $conn->error);
}

echo "Added nullable course_items.duration_minutes.\n";
