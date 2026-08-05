<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$check = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'course_state'");
if (!$check) throw new RuntimeException('Unable to inspect courses schema.');
$check->execute();
$exists = (int) ($check->get_result()->fetch_assoc()['total'] ?? 0) > 0;
$check->close();

if (!$exists && !$conn->query("ALTER TABLE courses ADD COLUMN course_state VARCHAR(20) NOT NULL DEFAULT 'public' AFTER course_status")) {
    throw new RuntimeException('Unable to add course_state: ' . $conn->error);
}
$added = !$exists;

// Only fill missing/invalid states. This makes reruns safe and preserves any
// state explicitly selected after the first migration.
if (!$conn->query("UPDATE courses SET course_state = CASE
    WHEN course_status <> '1' THEN 'draft'
    WHEN COALESCE(course_visibility, 'public') = 'private' THEN 'private'
    ELSE 'public' END
    WHERE " . ($added ? '1=1' : "course_state IS NULL OR course_state = '' OR course_state NOT IN ('public', 'private', 'draft')"))) {
    throw new RuntimeException('Unable to migrate course states: ' . $conn->error);
}

$indexCheck = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND INDEX_NAME = 'idx_courses_state'");
if (!$indexCheck) throw new RuntimeException('Unable to inspect course indexes.');
$indexCheck->execute();
$hasIndex = (int) ($indexCheck->get_result()->fetch_assoc()['total'] ?? 0) > 0;
$indexCheck->close();
if (!$hasIndex && !$conn->query('ALTER TABLE courses ADD INDEX idx_courses_state (course_state)')) {
    throw new RuntimeException('Unable to add course state index: ' . $conn->error);
}

echo "course_state is ready. Existing courses were mapped from status and visibility.\n";
