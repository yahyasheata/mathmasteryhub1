<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$statements = [
    "CREATE TABLE IF NOT EXISTS revision_plan_requirement_submissions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        assignment_id BIGINT UNSIGNED NOT NULL,
        requirement_id BIGINT UNSIGNED NOT NULL,
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_revision_submission_assignment_requirement (assignment_id, requirement_id),
        KEY idx_revision_submission_assignment (assignment_id, submitted_at),
        CONSTRAINT fk_revision_submission_assignment FOREIGN KEY (assignment_id) REFERENCES revision_plan_assignments(id) ON DELETE CASCADE,
        CONSTRAINT fk_revision_submission_requirement FOREIGN KEY (requirement_id) REFERENCES revision_plan_template_requirements(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS revision_plan_submission_files (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        submission_id BIGINT UNSIGNED NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        original_filename VARCHAR(255) NULL,
        mime_type VARCHAR(120) NULL,
        file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_revision_submission_file (submission_id, sort_order, id),
        CONSTRAINT fk_revision_submission_file_submission FOREIGN KEY (submission_id) REFERENCES revision_plan_requirement_submissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($statements as $sql) if (!$conn->query($sql)) throw new RuntimeException('Unable to create Revision Plan submission schema: ' . $conn->error);
echo "Revision Plan requirement submission schema is ready.\n";
