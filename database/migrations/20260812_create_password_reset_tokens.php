<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once dirname(__DIR__, 2) . '/connection/config.php';

$conn = db();
$tokens = "CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `requested_ip` VARCHAR(45) NOT NULL,
    `requested_user_agent_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `delivery_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
    `delivery_error_code` VARCHAR(64) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_password_reset_token_hash` (`token_hash`),
    KEY `idx_password_reset_user_active` (`user_id`, `used_at`, `expires_at`),
    KEY `idx_password_reset_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$limits = "CREATE TABLE IF NOT EXISTS `password_reset_rate_limits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `ip_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_password_reset_rate_identifier` (`identifier_hash`, `created_at`),
    KEY `idx_password_reset_rate_ip` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
if (!$conn->query($tokens)) throw new RuntimeException('Unable to create password_reset_tokens: ' . $conn->error);
if (!$conn->query($limits)) throw new RuntimeException('Unable to create password_reset_rate_limits: ' . $conn->error);
echo "Password reset schema is ready.\n";
