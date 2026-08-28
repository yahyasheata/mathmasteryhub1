<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only be run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$columnExists = static function (string $table, string $column) use ($conn): bool {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to inspect Revision Plan schema.');
    $stmt->bind_param('ss', $table, $column); $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc(); $stmt->close(); return $exists;
};

if (!$columnExists('revision_plan_template_batches', 'day_access_mode')) {
    if (!$conn->query("ALTER TABLE revision_plan_template_batches ADD COLUMN day_access_mode VARCHAR(24) NOT NULL DEFAULT 'follow_schedule' AFTER sort_order")) throw new RuntimeException($conn->error);
}
if (!$columnExists('revision_plan_batch_releases', 'visibility')) {
    if (!$conn->query("ALTER TABLE revision_plan_batch_releases ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'released' AFTER status")) throw new RuntimeException($conn->error);
}
if (!$columnExists('revision_plan_batch_releases', 'day_access_mode')) {
    if (!$conn->query("ALTER TABLE revision_plan_batch_releases ADD COLUMN day_access_mode VARCHAR(24) NOT NULL DEFAULT 'follow_schedule' AFTER visibility")) throw new RuntimeException($conn->error);
}
if (!$columnExists('revision_plan_batch_releases', 'display_title')) {
    if (!$conn->query("ALTER TABLE revision_plan_batch_releases ADD COLUMN display_title VARCHAR(180) NULL AFTER day_access_mode")) throw new RuntimeException($conn->error);
}
// Preserve the old plan-level work-ahead behavior for already released rows.
$conn->query("UPDATE revision_plan_batch_releases r INNER JOIN revision_plan_template_versions v ON v.id = r.source_version_id SET r.day_access_mode = 'open_all' WHERE v.allow_work_ahead = 1 AND r.day_access_mode = 'follow_schedule'");
$conn->query("UPDATE revision_plan_template_batches b INNER JOIN revision_plan_template_versions v ON v.id = b.version_id SET b.day_access_mode = 'open_all' WHERE v.allow_work_ahead = 1 AND b.day_access_mode = 'follow_schedule'");
echo "Revision Plan batch controls are ready.\n";
