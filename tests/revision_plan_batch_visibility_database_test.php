<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/RevisionPlan.php';
$db = db();
$database = 'mmh_revision_batch_visibility_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$query = static function (mysqli $conn, string $sql): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error ?: 'Fixture query failed.'); };
try {
    $query($db, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($db->select_db($database), 'Unable to select isolated database.');
    $query($db, "CREATE TABLE revision_plan_template_versions (id BIGINT AUTO_INCREMENT PRIMARY KEY, template_id BIGINT, status VARCHAR(16))");
    $query($db, "CREATE TABLE revision_plan_batch_releases (id BIGINT AUTO_INCREMENT PRIMARY KEY, template_id BIGINT, source_version_id BIGINT, source_batch_id BIGINT, batch_position INT, status VARCHAR(16), visibility VARCHAR(16), day_access_mode VARCHAR(24), display_title VARCHAR(180), released_at DATETIME NULL)");
    $query($db, "CREATE TABLE revision_plan_requirement_progress (id BIGINT AUTO_INCREMENT PRIMARY KEY, requirement_id BIGINT, completed_at DATETIME NULL)");
    $query($db, "INSERT INTO revision_plan_template_versions VALUES (1, 1, 'published')");
    $query($db, "INSERT INTO revision_plan_batch_releases VALUES (1, 1, 1, 10, 1, 'released', 'released', 'follow_schedule', 'Batch 2', NOW())");
    $query($db, "INSERT INTO revision_plan_requirement_progress VALUES (1, 99, NOW())");
    mmh_revision_update_batch_controls($db, 1, 1, 'Batch 2', 'coming_soon', 'follow_schedule', 1);
    $row = $db->query("SELECT visibility, day_access_mode, display_title FROM revision_plan_batch_releases WHERE id = 1")->fetch_assoc();
    $assert(($row['visibility'] ?? '') === 'coming_soon', 'Batch was not hidden.');
    $assert(($row['display_title'] ?? '') === 'Batch 2', 'Batch title was not preserved.');
    $progress = (int) (($db->query("SELECT COUNT(*) AS total FROM revision_plan_requirement_progress")->fetch_assoc()['total'] ?? 0));
    $assert($progress === 1, 'Existing progress was changed.');
    mmh_revision_update_batch_controls($db, 1, 1, 'Batch 2', 'released', 'open_all', 1);
    $row = $db->query("SELECT visibility, day_access_mode FROM revision_plan_batch_releases WHERE id = 1")->fetch_assoc();
    $assert(($row['visibility'] ?? '') === 'released' && ($row['day_access_mode'] ?? '') === 'open_all', 'Batch was not restored.');
    echo "revision_plan_batch_visibility_database=hide_with_progress=PASS restore=PASS\n";
} finally {
    $cleanup = mysqli_connect((string) ($host ?? ''), (string) ($user ?? ''), (string) ($pass ?? ''));
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_revision_batch_visibility_test_[0-9]+_[a-f0-9]{8}\z/', $database)) { $cleanup->query('DROP DATABASE `' . $database . '`'); $cleanup->close(); }
}
