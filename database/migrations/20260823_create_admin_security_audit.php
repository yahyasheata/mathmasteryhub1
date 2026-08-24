<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';

$conn = db();
$sql = "CREATE TABLE IF NOT EXISTS `admin_security_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_user_id` INT NOT NULL,
    `target_user_id` INT NOT NULL,
    `action` VARCHAR(32) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_admin_security_audit_actor` (`actor_user_id`),
    KEY `idx_admin_security_audit_target` (`target_user_id`),
    KEY `idx_admin_security_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql)) {
    throw new RuntimeException('Unable to create admin_security_audit: ' . $conn->error);
}

echo "Admin security audit schema is ready.\n";
