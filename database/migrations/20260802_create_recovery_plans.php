<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();
$statements = [
    "CREATE TABLE IF NOT EXISTS recovery_plans (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        course_id VARCHAR(40) NOT NULL,
        title VARCHAR(180) NOT NULL DEFAULT 'Recovery Plan',
        status VARCHAR(16) NOT NULL DEFAULT 'draft',
        active_key VARCHAR(100) NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        completion_notified_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_recovery_active_key (active_key),
        KEY idx_recovery_student_course (user_id, course_id, status),
        KEY idx_recovery_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS recovery_plan_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        plan_id BIGINT UNSIGNED NOT NULL,
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
        UNIQUE KEY uniq_recovery_plan_item (plan_id, item_id),
        KEY idx_recovery_plan_order (plan_id, sort_order),
        KEY idx_recovery_item (course_id, item_id),
        CONSTRAINT fk_recovery_plan_items_plan FOREIGN KEY (plan_id) REFERENCES recovery_plans (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($statements as $sql) {
    if (!$conn->query($sql)) throw new RuntimeException('Unable to create Recovery Plan schema: ' . $conn->error);
}
echo "Created recovery_plans and recovery_plan_items (or they already existed).\n";
