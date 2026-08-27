<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$sql = "CREATE TABLE IF NOT EXISTS revision_plan_batch_releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id BIGINT UNSIGNED NOT NULL,
    source_version_id BIGINT UNSIGNED NOT NULL,
    source_batch_id BIGINT UNSIGNED NOT NULL,
    batch_position INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'released',
    released_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_by INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_revision_batch_release_position (template_id, batch_position),
    KEY idx_revision_batch_release_version (source_version_id),
    KEY idx_revision_batch_release_batch (source_batch_id),
    CONSTRAINT fk_revision_batch_release_template FOREIGN KEY (template_id) REFERENCES revision_plan_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_revision_batch_release_version FOREIGN KEY (source_version_id) REFERENCES revision_plan_template_versions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_revision_batch_release_batch FOREIGN KEY (source_batch_id) REFERENCES revision_plan_template_batches(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql)) throw new RuntimeException('Unable to create Revision Plan batch release schema: ' . $conn->error);
echo "Revision Plan batch release schema is ready.\n";
