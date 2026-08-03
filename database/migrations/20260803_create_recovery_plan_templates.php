<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$statements = [
    "CREATE TABLE IF NOT EXISTS recovery_plan_templates (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        course_id VARCHAR(40) NOT NULL,
        title VARCHAR(180) NOT NULL DEFAULT 'Recovery Plan Template',
        description VARCHAR(1000) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        archived_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_recovery_template_course (course_id, status),
        KEY idx_recovery_template_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS recovery_plan_template_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_id BIGINT UNSIGNED NOT NULL,
        course_id VARCHAR(40) NOT NULL,
        item_id VARCHAR(40) NOT NULL,
        assignment_id VARCHAR(40) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_required TINYINT(1) NOT NULL DEFAULT 1,
        teacher_note VARCHAR(1000) NULL,
        estimated_duration INT NULL,
        weight DECIMAL(8,2) NULL,
        locked_until_previous TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_recovery_template_item (template_id, item_id),
        KEY idx_recovery_template_order (template_id, sort_order),
        KEY idx_recovery_template_item (course_id, item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS recovery_plan_template_coverage (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_item_id BIGINT UNSIGNED NOT NULL,
        course_id VARCHAR(40) NOT NULL,
        coverage_type VARCHAR(40) NOT NULL,
        covered_item_id VARCHAR(40) NULL,
        covered_section_id VARCHAR(40) NULL,
        topic_label VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_recovery_template_coverage (template_item_id, coverage_type, covered_item_id, covered_section_id, topic_label),
        KEY idx_recovery_template_coverage_item (template_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS recovery_plan_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_id BIGINT UNSIGNED NOT NULL,
        plan_id BIGINT UNSIGNED NOT NULL,
        student_id INT NOT NULL,
        course_id VARCHAR(40) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'assigned',
        template_version INT NOT NULL DEFAULT 1,
        assigned_by INT NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_recovery_plan_assignment_plan (plan_id),
        KEY idx_recovery_assignment_template (template_id, status),
        KEY idx_recovery_assignment_student (student_id, course_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($statements as $sql) {
    if (!$conn->query($sql)) {
        throw new RuntimeException('Unable to create Recovery Plan template schema: ' . $conn->error);
    }
}

function mmh_migration_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
    $stmt->close();
    return $exists;
}

$legacyColumns = [
    'template_id' => 'BIGINT UNSIGNED NULL',
    'assignment_id' => 'BIGINT UNSIGNED NULL',
    'template_version' => 'INT NOT NULL DEFAULT 1',
];
foreach ($legacyColumns as $column => $definition) {
    if (!mmh_migration_column_exists($conn, 'recovery_plans', $column)) {
        if (!$conn->query("ALTER TABLE recovery_plans ADD COLUMN `{$column}` {$definition}")) {
            throw new RuntimeException('Unable to extend recovery_plans: ' . $conn->error);
        }
    }
}

echo "Recovery Plan template and assignment schema is ready.\n";
