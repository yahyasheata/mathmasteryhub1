<?php
/** Idempotent coverage mappings for Recovery Plan tasks. */
require_once dirname(__DIR__, 2) . '/connection/config.php';

$conn = db();
$sql = "CREATE TABLE IF NOT EXISTS recovery_plan_item_coverage (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_item_id BIGINT UNSIGNED NOT NULL,
    course_id VARCHAR(40) NOT NULL,
    coverage_type VARCHAR(40) NOT NULL,
    covered_item_id VARCHAR(40) NULL,
    covered_section_id VARCHAR(40) NULL,
    topic_label VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recovery_coverage (plan_item_id, coverage_type, covered_item_id, covered_section_id, topic_label),
    KEY idx_recovery_coverage_plan_item (plan_item_id),
    KEY idx_recovery_coverage_course_item (course_id, covered_item_id),
    CONSTRAINT fk_recovery_coverage_plan_item FOREIGN KEY (plan_item_id) REFERENCES recovery_plan_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$conn->query($sql)) {
    fwrite(STDERR, "Unable to create recovery_plan_item_coverage: {$conn->error}\n");
    exit(1);
}
echo "recovery_plan_item_coverage ready\n";
