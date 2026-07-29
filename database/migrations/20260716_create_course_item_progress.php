<?php
declare(strict_types=1);

/**
 * Creates the per-student lesson progress store used by B4C.
 *
 * Run from the project root:
 * php database/migrations/20260716_create_course_item_progress.php
 *
 * The migration is CLI-only and idempotent. It creates one new table and does
 * not alter existing course, lesson, assignment, or analytics tables.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';

$conn = db();
$table = 'course_item_progress';
$tableStmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
if (!$tableStmt) {
    throw new RuntimeException('Unable to inspect the course_item_progress table: ' . $conn->error);
}
$tableStmt->bind_param('s', $table);
$tableStmt->execute();
$exists = (int) (($tableStmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
$tableStmt->close();

if ($exists) {
    echo "course_item_progress already exists; no changes made.\n";
    exit(0);
}

$sql = "CREATE TABLE IF NOT EXISTS `course_item_progress` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `course_id` VARCHAR(40) NOT NULL,
    `item_id` VARCHAR(40) NOT NULL,
    `last_viewed_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `completion_source` VARCHAR(50) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_course_item` (`user_id`, `course_id`, `item_id`),
    KEY `idx_user_course` (`user_id`, `course_id`),
    KEY `idx_course_item` (`course_id`, `item_id`),
    KEY `idx_completed_at` (`completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$conn->query($sql)) {
    throw new RuntimeException('Unable to create course_item_progress: ' . $conn->error);
}

echo "Created course_item_progress.\n";
