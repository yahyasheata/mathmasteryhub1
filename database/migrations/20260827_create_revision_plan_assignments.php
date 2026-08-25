<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$column = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_template_versions' AND COLUMN_NAME = 'allow_work_ahead'");
if (!$column) throw new RuntimeException('Unable to inspect Revision Plan version schema.');
$column->execute();
$hasColumn = (int) (($column->get_result()->fetch_assoc()['total'] ?? 0));
$column->close();
if ($hasColumn === 0 && !$conn->query("ALTER TABLE revision_plan_template_versions ADD COLUMN allow_work_ahead TINYINT(1) NOT NULL DEFAULT 0 AFTER status")) {
    throw new RuntimeException('Unable to add the work-ahead setting.');
}

$sql = "CREATE TABLE IF NOT EXISTS revision_plan_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id BIGINT UNSIGNED NOT NULL,
    template_version_id BIGINT UNSIGNED NOT NULL,
    course_id VARCHAR(40) NOT NULL,
    user_id INT NOT NULL,
    start_date DATE NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    assigned_by INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    ended_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_revision_assignment_user_version (user_id, template_version_id),
    KEY idx_revision_assignment_student (user_id, status, start_date),
    KEY idx_revision_assignment_version (template_version_id, status),
    KEY idx_revision_assignment_course (course_id, status),
    CONSTRAINT fk_revision_assignment_template FOREIGN KEY (template_id) REFERENCES revision_plan_templates(id) ON DELETE RESTRICT,
    CONSTRAINT fk_revision_assignment_version FOREIGN KEY (template_version_id) REFERENCES revision_plan_template_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$conn->query($sql)) throw new RuntimeException('Unable to create Revision Plan assignment schema: ' . $conn->error);
echo "Revision Plan assignment schema is ready.\n";
