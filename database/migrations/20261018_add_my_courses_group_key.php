<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$table = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' LIMIT 1");
if (!$table || !$table->fetch_row()) {
    throw new RuntimeException('Required courses table does not exist. No changes were applied.');
}
$column = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'my_courses_group_key' LIMIT 1");
if (!$column) throw new RuntimeException('Unable to inspect courses.my_courses_group_key: ' . $conn->error);
if (!$column->fetch_row() && !$conn->query("ALTER TABLE `courses` ADD COLUMN `my_courses_group_key` VARCHAR(32) NULL DEFAULT NULL")) {
    throw new RuntimeException('Unable to add courses.my_courses_group_key: ' . $conn->error);
}

echo "Course My Courses group key is ready; existing Courses remain ungrouped.\n";
