<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$column = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'supports_unit_section_access' LIMIT 1");
if (!$column) throw new RuntimeException('Unable to inspect Course Unit Section Access capability.');
$columnExists = (bool) $column->fetch_row();
$column->free();

if (!$columnExists) {
    if (!$conn->query('ALTER TABLE `courses` ADD COLUMN `supports_unit_section_access` TINYINT(1) NOT NULL DEFAULT 0')) {
        throw new RuntimeException('Unable to add Course Unit Section Access capability: ' . $conn->error);
    }
    // Seed only on first installation. Re-running the migration must preserve
    // later Admin choices rather than silently re-enabling a Course.
    if (!$conn->query("UPDATE courses SET supports_unit_section_access = 1 WHERE course_id IN ('3078', '7656057')")) {
        throw new RuntimeException('Unable to enable Unit Section Access for the designated Math OL Courses: ' . $conn->error);
    }
}

echo "Course Unit Section Access capability is ready; existing Sections and enrollments were not changed.\n";
