<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$statements = [
    "CREATE TABLE IF NOT EXISTS revision_plan_templates (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        course_id VARCHAR(40) NOT NULL,
        title VARCHAR(180) NOT NULL,
        description VARCHAR(1000) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        archived_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_revision_template_course (course_id, status),
        KEY idx_revision_template_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS revision_plan_template_versions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_id BIGINT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'draft',
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        published_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_revision_template_version (template_id, version_number),
        KEY idx_revision_version_status (template_id, status),
        CONSTRAINT fk_revision_version_template FOREIGN KEY (template_id) REFERENCES revision_plan_templates(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS revision_plan_template_batches (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        version_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        description VARCHAR(1000) NULL,
        suggested_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_revision_batch_order (version_id, sort_order, id),
        CONSTRAINT fk_revision_batch_version FOREIGN KEY (version_id) REFERENCES revision_plan_template_versions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS revision_plan_template_days (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        day_number INT UNSIGNED NOT NULL DEFAULT 1,
        title VARCHAR(180) NOT NULL,
        description VARCHAR(1000) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_revision_day_order (batch_id, sort_order, id),
        KEY idx_revision_day_version (version_id),
        CONSTRAINT fk_revision_day_batch FOREIGN KEY (batch_id) REFERENCES revision_plan_template_batches(id) ON DELETE CASCADE,
        CONSTRAINT fk_revision_day_version FOREIGN KEY (version_id) REFERENCES revision_plan_template_versions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS revision_plan_template_activities (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        day_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        description VARCHAR(1000) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_revision_activity_order (day_id, sort_order, id),
        KEY idx_revision_activity_version (version_id),
        CONSTRAINT fk_revision_activity_day FOREIGN KEY (day_id) REFERENCES revision_plan_template_days(id) ON DELETE CASCADE,
        CONSTRAINT fk_revision_activity_version FOREIGN KEY (version_id) REFERENCES revision_plan_template_versions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS revision_plan_template_requirements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        version_id BIGINT UNSIGNED NOT NULL,
        day_id BIGINT UNSIGNED NOT NULL,
        activity_id BIGINT UNSIGNED NULL,
        title VARCHAR(180) NOT NULL,
        description VARCHAR(2000) NULL,
        requirement_type VARCHAR(24) NOT NULL DEFAULT 'checklist',
        is_required TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        linked_course_item_id VARCHAR(40) NULL,
        allow_multiple_files TINYINT(1) NOT NULL DEFAULT 0,
        accepted_file_policy VARCHAR(80) NOT NULL DEFAULT 'pdf',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_revision_requirement_day (day_id, sort_order, id),
        KEY idx_revision_requirement_activity (activity_id, sort_order, id),
        KEY idx_revision_requirement_version (version_id),
        CONSTRAINT fk_revision_requirement_version FOREIGN KEY (version_id) REFERENCES revision_plan_template_versions(id) ON DELETE CASCADE,
        CONSTRAINT fk_revision_requirement_day FOREIGN KEY (day_id) REFERENCES revision_plan_template_days(id) ON DELETE CASCADE,
        CONSTRAINT fk_revision_requirement_activity FOREIGN KEY (activity_id) REFERENCES revision_plan_template_activities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS revision_plan_template_resources (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        version_id BIGINT UNSIGNED NOT NULL,
        batch_id BIGINT UNSIGNED NULL,
        resource_type VARCHAR(24) NOT NULL,
        display_name VARCHAR(180) NOT NULL,
        external_url VARCHAR(1000) NULL,
        storage_key VARCHAR(500) NULL,
        original_filename VARCHAR(255) NULL,
        mime_type VARCHAR(120) NULL,
        file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        linked_course_item_id VARCHAR(40) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_revision_resource_version (version_id, sort_order, id),
        KEY idx_revision_resource_batch (batch_id),
        CONSTRAINT fk_revision_resource_version FOREIGN KEY (version_id) REFERENCES revision_plan_template_versions(id) ON DELETE CASCADE,
        CONSTRAINT fk_revision_resource_batch FOREIGN KEY (batch_id) REFERENCES revision_plan_template_batches(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS revision_plan_requirement_resources (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        requirement_id BIGINT UNSIGNED NOT NULL,
        resource_id BIGINT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_revision_requirement_resource (requirement_id, resource_id),
        KEY idx_revision_requirement_resource_resource (resource_id),
        CONSTRAINT fk_revision_requirement_resource_requirement FOREIGN KEY (requirement_id) REFERENCES revision_plan_template_requirements(id) ON DELETE CASCADE,
        CONSTRAINT fk_revision_requirement_resource_resource FOREIGN KEY (resource_id) REFERENCES revision_plan_template_resources(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($statements as $sql) {
    if (!$conn->query($sql)) throw new RuntimeException('Unable to create Revision Plan schema: ' . $conn->error);
}
echo "Revision Plan template schema is ready.\n";
