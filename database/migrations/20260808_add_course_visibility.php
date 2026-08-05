<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$columnCheck = $conn->prepare("SELECT COUNT(*) AS total
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'course_visibility'");
if (!$columnCheck) throw new RuntimeException('Unable to inspect courses schema.');
$columnCheck->execute();
$hasColumn = (int) ($columnCheck->get_result()->fetch_assoc()['total'] ?? 0) > 0;
$columnCheck->close();

if (!$hasColumn) {
    if (!$conn->query("ALTER TABLE courses ADD COLUMN course_visibility VARCHAR(20) NOT NULL DEFAULT 'public' AFTER course_status")) {
        throw new RuntimeException('Unable to add course_visibility: ' . $conn->error);
    }
}

if (!$conn->query("UPDATE courses
    SET course_visibility = 'public'
    WHERE course_visibility IS NULL OR course_visibility = ''
       OR course_visibility NOT IN ('public', 'private')")) {
    throw new RuntimeException('Unable to normalize course visibility: ' . $conn->error);
}

$indexCheck = $conn->prepare("SELECT COUNT(*) AS total
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND INDEX_NAME = 'idx_courses_status_visibility'");
if (!$indexCheck) throw new RuntimeException('Unable to inspect courses indexes.');
$indexCheck->execute();
$hasIndex = (int) ($indexCheck->get_result()->fetch_assoc()['total'] ?? 0) > 0;
$indexCheck->close();
if (!$hasIndex && !$conn->query('ALTER TABLE courses ADD INDEX idx_courses_status_visibility (course_status, course_visibility)')) {
    throw new RuntimeException('Unable to add course visibility index: ' . $conn->error);
}

echo "course_visibility is ready (existing courses default to public).\n";
