<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$column = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_template_requirements' AND COLUMN_NAME = 'source_requirement_id'");
if (!$column) throw new RuntimeException('Unable to inspect Revision Plan requirement lineage schema.');
$column->execute();
$exists = (int) (($column->get_result()->fetch_assoc()['total'] ?? 0));
$column->close();
if ($exists === 0 && !$conn->query("ALTER TABLE revision_plan_template_requirements ADD COLUMN source_requirement_id BIGINT UNSIGNED NULL AFTER version_id")) {
    throw new RuntimeException('Unable to add Revision Plan requirement lineage.');
}
$index = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'revision_plan_template_requirements' AND INDEX_NAME = 'idx_revision_requirement_lineage'");
if (!$index) throw new RuntimeException('Unable to inspect Revision Plan requirement lineage index.');
$index->execute();
$indexExists = (int) (($index->get_result()->fetch_assoc()['total'] ?? 0));
$index->close();
if ($indexExists === 0 && !$conn->query('ALTER TABLE revision_plan_template_requirements ADD KEY idx_revision_requirement_lineage (source_requirement_id)')) {
    throw new RuntimeException('Unable to add Revision Plan requirement lineage index.');
}
echo "Revision Plan requirement lineage is ready.\n";
