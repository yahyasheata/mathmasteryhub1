<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$table = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_live_schedules' LIMIT 1");
if (!$table) throw new RuntimeException('Unable to inspect course_live_schedules: ' . $conn->error);
if (!$table->fetch_row()) {
    echo "course_live_schedules is not present yet; deleted_at will be included when the table is created.\n";
    exit(0);
}

$column = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_live_schedules' AND COLUMN_NAME = 'deleted_at' LIMIT 1");
if (!$column) throw new RuntimeException('Unable to inspect course_live_schedules.deleted_at: ' . $conn->error);
if (!$column->fetch_row() && !$conn->query("ALTER TABLE `course_live_schedules` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL")) {
    throw new RuntimeException('Unable to add course_live_schedules.deleted_at: ' . $conn->error);
}

echo "Recurring Live Session soft-delete marker is ready.\n";
