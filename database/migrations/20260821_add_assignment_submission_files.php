<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

define('MMH_SCHEMA_MIGRATION_MODE', true);
require_once dirname(__DIR__, 2) . '/connection/config.php';
require_once dirname(__DIR__, 2) . '/inc/learning_schema.php';

$conn = db();
if (!mmh_table_exists($conn, 'assignment_submissions')) {
    echo "assignment_submissions is not present; nothing to migrate.\n";
    exit(0);
}

$conn->query("CREATE TABLE IF NOT EXISTS assignment_submission_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NULL,
    mime_type VARCHAR(127) NULL,
    file_size BIGINT UNSIGNED NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_submission_file (submission_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($conn->errno) {
    throw new RuntimeException('Unable to create assignment submission file table: ' . $conn->error);
}
foreach ([
    ['mime_type', 'VARCHAR(127) NULL'],
    ['file_size', 'BIGINT UNSIGNED NULL'],
    ['sort_order', 'INT UNSIGNED NOT NULL DEFAULT 0'],
] as [$column, $definition]) {
    if (!mmh_add_column_if_missing($conn, 'assignment_submission_files', $column, $definition)) {
        throw new RuntimeException("Unable to add {$column} to assignment_submission_files: {$conn->error}");
    }
}

$backfill = $conn->query("INSERT INTO assignment_submission_files (submission_id, file_path, original_filename, sort_order)
    SELECT s.id, s.file_path, NULL, 0
    FROM assignment_submissions s
    WHERE s.file_path IS NOT NULL AND TRIM(s.file_path) <> ''
      AND NOT EXISTS (SELECT 1 FROM assignment_submission_files f WHERE f.submission_id = s.id)");
if ($backfill === false) {
    throw new RuntimeException('Unable to backfill legacy submission files: ' . $conn->error);
}
$backfilled = $conn->affected_rows;

if (!mmh_add_index_if_missing($conn, 'assignment_submission_files', 'idx_submission_file_order', '`submission_id`, `sort_order`, `id`')) {
    throw new RuntimeException('Unable to add submission file ordering index: ' . $conn->error);
}
echo "assignment_submission_files is ready; backfilled {$backfilled} legacy file reference(s).\n";
