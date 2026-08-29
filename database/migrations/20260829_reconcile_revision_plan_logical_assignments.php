<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
require_once dirname(__DIR__, 2) . '/inc/RevisionPlan.php';
$conn = db();

$scope = trim((string) (getenv('MMH_REVISION_RECONCILE_TEMPLATE_IDS') ?: ''));
if ($scope === '') { echo "Revision Plan logical-assignment reconciliation skipped; set MMH_REVISION_RECONCILE_TEMPLATE_IDS to an explicit comma-separated template ID list.\n"; exit(0); }
$templateIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/\\s*,\\s*/', $scope)), static fn(int $id): bool => $id > 0)));
if (!$templateIds) throw new InvalidArgumentException('MMH_REVISION_RECONCILE_TEMPLATE_IDS must contain positive template IDs.');
$idList = implode(',', $templateIds);
$scalar = static function (mysqli $conn, string $sql): int { $result = $conn->query($sql); return $result ? (int) (($result->fetch_assoc()['total'] ?? 0)) : 0; };
$duplicateGroups = $scalar($conn, "SELECT COUNT(*) AS total FROM (SELECT template_id, user_id FROM revision_plan_assignments WHERE template_id IN ({$idList}) AND status = 'active' AND archived_at IS NULL GROUP BY template_id, user_id HAVING COUNT(*) > 1) duplicate_groups");
$duplicateAssignments = $scalar($conn, "SELECT COALESCE(SUM(total - 1), 0) AS total FROM (SELECT template_id, user_id, COUNT(*) AS total FROM revision_plan_assignments WHERE template_id IN ({$idList}) AND status = 'active' AND archived_at IS NULL GROUP BY template_id, user_id HAVING COUNT(*) > 1) duplicate_assignments");
$deletedRevoked = 0;
$revoke = $conn->query("UPDATE revision_plan_assignments a LEFT JOIN revision_plan_templates t ON t.id = a.template_id SET a.status = 'archived', a.archived_at = COALESCE(a.archived_at, UTC_TIMESTAMP()), a.ended_at = COALESCE(a.ended_at, UTC_TIMESTAMP()) WHERE a.template_id IN ({$idList}) AND a.status = 'active' AND a.archived_at IS NULL AND (t.id IS NULL OR t.archived_at IS NOT NULL OR LOWER(TRIM(COALESCE(t.status, ''))) IN ('archived', 'deleted'))");
if ($revoke === false) throw new RuntimeException('Unable to revoke deleted Revision Plan assignments: ' . $conn->error);
$deletedRevoked = $conn->affected_rows;
$upgraded = 0; $progressBefore = 0; $progressAfter = 0; $uploadsBefore = 0; $uploadsAfter = 0;
$hasProgress = mmh_revision_progress_schema_available($conn); $hasSubmissions = mmh_revision_submission_schema_available($conn);
$templates = $conn->query("SELECT t.id, (SELECT v.id FROM revision_plan_template_versions v WHERE v.template_id = t.id AND LOWER(TRIM(COALESCE(v.status, ''))) = 'published' ORDER BY v.version_number DESC, v.id DESC LIMIT 1) AS latest_version_id FROM revision_plan_templates t WHERE t.id IN ({$idList}) AND t.archived_at IS NULL AND LOWER(TRIM(COALESCE(t.status, ''))) NOT IN ('archived', 'deleted')");
if (!$templates) throw new RuntimeException('Unable to inspect scoped Revision Plans: ' . $conn->error);
foreach ($templates->fetch_all(MYSQLI_ASSOC) as $row) {
    $templateId = (int) ($row['id'] ?? 0); $versionId = (int) ($row['latest_version_id'] ?? 0); if ($templateId <= 0 || $versionId <= 0) continue;
    if ($hasProgress) $progressBefore += $scalar($conn, "SELECT COUNT(*) AS total FROM revision_plan_requirement_progress p INNER JOIN revision_plan_assignments a ON a.id = p.assignment_id WHERE a.template_id = {$templateId} AND a.status = 'active' AND a.archived_at IS NULL");
    if ($hasSubmissions) $uploadsBefore += $scalar($conn, "SELECT COUNT(*) AS total FROM revision_plan_requirement_submissions s INNER JOIN revision_plan_assignments a ON a.id = s.assignment_id WHERE a.template_id = {$templateId} AND a.status = 'active' AND a.archived_at IS NULL");
    $conn->begin_transaction();
    try { $upgraded += mmh_revision_upgrade_assignments_for_version($conn, $templateId, $versionId); $conn->commit(); }
    catch (Throwable $e) { $conn->rollback(); throw $e; }
    if ($hasProgress) $progressAfter += $scalar($conn, "SELECT COUNT(*) AS total FROM revision_plan_requirement_progress p INNER JOIN revision_plan_assignments a ON a.id = p.assignment_id WHERE a.template_id = {$templateId} AND a.status = 'active' AND a.archived_at IS NULL");
    if ($hasSubmissions) $uploadsAfter += $scalar($conn, "SELECT COUNT(*) AS total FROM revision_plan_requirement_submissions s INNER JOIN revision_plan_assignments a ON a.id = s.assignment_id WHERE a.template_id = {$templateId} AND a.status = 'active' AND a.archived_at IS NULL");
}
$archived = max(0, $duplicateAssignments + $deletedRevoked); $progressTransferred = max(0, $progressAfter - $progressBefore); $uploadsTransferred = max(0, $uploadsAfter - $uploadsBefore);
echo "Revision Plan logical-assignment reconciliation complete for {$scope}. duplicate logical-plan groups found: {$duplicateGroups}; superseded assignments archived: {$archived}; deleted-plan assignments revoked: {$deletedRevoked}; canonical assignments upgraded: {$upgraded}; progress records preserved/transferred: {$progressTransferred}; upload submissions preserved/transferred: {$uploadsTransferred}.\n";
