<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$columnExists = static function (string $table, string $column) use ($conn): bool {
    $stmt = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to inspect Revision Plan schema.');
    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to inspect Revision Plan schema.'); }
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
};

if (!$columnExists('revision_plan_template_batches', 'schedule_mode')) {
    if (!$conn->query("ALTER TABLE revision_plan_template_batches ADD COLUMN schedule_mode VARCHAR(16) NOT NULL DEFAULT 'automatic' AFTER day_access_mode")) throw new RuntimeException($conn->error);
}
if (!$columnExists('revision_plan_template_days', 'scheduled_date')) {
    if (!$conn->query("ALTER TABLE revision_plan_template_days ADD COLUMN scheduled_date DATE NULL AFTER description")) throw new RuntimeException($conn->error);
}
if (!$columnExists('revision_plan_template_batches', 'schedule_start_date')) {
    if (!$conn->query("ALTER TABLE revision_plan_template_batches ADD COLUMN schedule_start_date DATE NULL AFTER schedule_mode")) throw new RuntimeException($conn->error);
}

echo "Revision Plan manual day dates are ready.\n";
