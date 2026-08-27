<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$sql = "CREATE TABLE IF NOT EXISTS revision_plan_requirement_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_revision_progress_assignment_requirement (assignment_id, requirement_id),
    KEY idx_revision_progress_assignment (assignment_id, completed_at),
    KEY idx_revision_progress_requirement (requirement_id),
    CONSTRAINT fk_revision_progress_assignment FOREIGN KEY (assignment_id) REFERENCES revision_plan_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_revision_progress_requirement FOREIGN KEY (requirement_id) REFERENCES revision_plan_template_requirements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql)) throw new RuntimeException('Unable to create Revision Plan progress schema: ' . $conn->error);
echo "Revision Plan requirement progress schema is ready.\n";
