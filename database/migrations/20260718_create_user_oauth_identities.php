<?php
declare(strict_types=1);
/**
 * Creates stable external identities for Google and Apple sign-in.
 * Run: php database/migrations/20260718_create_user_oauth_identities.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();
$sql = "CREATE TABLE IF NOT EXISTS `user_oauth_identities` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `provider` VARCHAR(32) NOT NULL,
    `provider_subject` VARCHAR(255) NOT NULL,
    `provider_email` VARCHAR(250) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_provider_subject` (`provider`, `provider_subject`),
    UNIQUE KEY `uniq_user_provider` (`user_id`, `provider`),
    KEY `idx_oauth_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($sql)) { throw new RuntimeException('Unable to create user_oauth_identities: ' . $conn->error); }
echo "user_oauth_identities is ready.\n";
