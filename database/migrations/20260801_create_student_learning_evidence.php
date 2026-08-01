<?php
declare(strict_types=1);

/**
 * Stores item-level historical learning evidence without fabricating LMS
 * submissions, attendance events, grades, or timestamps.
 *
 * Run from the project root:
 * php database/migrations/20260801_create_student_learning_evidence.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';

$conn = db();
$sql = "CREATE TABLE IF NOT EXISTS `student_learning_evidence` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `course_id` VARCHAR(40) NOT NULL,
    `section_id` VARCHAR(40) NULL,
    `item_id` VARCHAR(40) NULL,
    `assignment_id` VARCHAR(40) NULL,
    `occurrence_id` VARCHAR(40) NULL,
    `entity_key` VARCHAR(120) NOT NULL,
    `item_kind` VARCHAR(32) NOT NULL,
    `state` VARCHAR(32) NOT NULL,
    `source` VARCHAR(16) NOT NULL,
    `recorded_by` INT NOT NULL,
    `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `activity_at` DATETIME NULL,
    `note` VARCHAR(1000) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_student_learning_entity` (`user_id`, `course_id`, `entity_key`),
    KEY `idx_student_learning_course` (`user_id`, `course_id`),
    KEY `idx_student_learning_item` (`course_id`, `item_id`),
    KEY `idx_student_learning_assignment` (`course_id`, `assignment_id`),
    KEY `idx_student_learning_occurrence` (`course_id`, `occurrence_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql)) {
    throw new RuntimeException('Unable to create student_learning_evidence: ' . $conn->error);
}

echo "Created student_learning_evidence (or it already existed).\n";
