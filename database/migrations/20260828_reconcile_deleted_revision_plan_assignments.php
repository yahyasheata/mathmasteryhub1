<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$check = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('revision_plan_assignments', 'revision_plan_templates')");
if (!$check) throw new RuntimeException('Unable to inspect Revision Plan schema.');
$check->execute();
$ready = (int) (($check->get_result()->fetch_assoc()['total'] ?? 0)) === 2;
$check->close();
if (!$ready) { echo "Revision Plan assignment reconciliation skipped; schema is not ready.\n"; exit(0); }

$conn->begin_transaction();
try {
    $sql = "UPDATE revision_plan_assignments a
            LEFT JOIN revision_plan_templates t ON t.id = a.template_id
            SET a.status = 'archived',
                a.archived_at = COALESCE(a.archived_at, UTC_TIMESTAMP()),
                a.ended_at = COALESCE(a.ended_at, UTC_TIMESTAMP())
            WHERE LOWER(TRIM(COALESCE(a.status, ''))) = 'active'
              AND a.archived_at IS NULL
              AND (t.id IS NULL OR t.archived_at IS NOT NULL OR LOWER(TRIM(COALESCE(t.status, ''))) IN ('archived', 'deleted'))";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare Revision Plan assignment reconciliation.');
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to reconcile Revision Plan assignments.'); }
    $repaired = $stmt->affected_rows;
    $stmt->close();
    $conn->commit();
    echo "Revision Plan assignment reconciliation complete. Repaired {$repaired} row(s).\n";
} catch (Throwable $e) {
    $conn->rollback();
    throw $e;
}
