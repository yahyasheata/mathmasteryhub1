<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();
$sql = "CREATE TABLE IF NOT EXISTS `timed_exam_marked_papers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attempt_id` BIGINT UNSIGNED NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `storage_key` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(120) NOT NULL,
    `file_size_bytes` BIGINT UNSIGNED NOT NULL,
    `uploaded_by` INT NULL,
    `uploaded_at_utc` DATETIME NOT NULL,
    `updated_at_utc` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_timed_exam_marked_attempt` (`attempt_id`),
    KEY `idx_timed_exam_marked_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$conn->query($sql)) throw new RuntimeException('Unable to create timed_exam_marked_papers: ' . $conn->error);
echo "Timed Exam marked-paper schema is ready.\n";
