<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';

$conn = db();
$tableCheck = $conn->query("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignments'");
$tableRow = $tableCheck ? $tableCheck->fetch_assoc() : null;
if ((int) ($tableRow['total'] ?? 0) !== 1) {
    throw new RuntimeException('The assignments table is required before Model Answer access can be migrated.');
}

$column = $conn->query("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignments' AND COLUMN_NAME = 'model_answer_access_mode'");
$columnRow = $column ? $column->fetch_assoc() : null;
if ((int) ($columnRow['total'] ?? 0) === 0) {
    if (!$conn->query("ALTER TABLE assignments ADD COLUMN model_answer_access_mode VARCHAR(16) NOT NULL DEFAULT 'all' AFTER archived_at")) {
        throw new RuntimeException('Unable to add assignments.model_answer_access_mode: ' . $conn->error);
    }
}

if (!$conn->query("UPDATE assignments SET model_answer_access_mode = 'all' WHERE model_answer_access_mode IS NULL OR model_answer_access_mode NOT IN ('all', 'selected', 'none')")) {
    throw new RuntimeException('Unable to normalize Model Answer access modes: ' . $conn->error);
}

$mapping = "CREATE TABLE IF NOT EXISTS assignment_model_answer_access (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id VARCHAR(40) NOT NULL,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment_model_answer_access (assignment_id, user_id),
    KEY idx_assignment_model_answer_access_assignment (assignment_id),
    KEY idx_assignment_model_answer_access_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$conn->query($mapping)) {
    throw new RuntimeException('Unable to create assignment_model_answer_access: ' . $conn->error);
}

echo "Assignment Model Answer access schema is ready.\n";
