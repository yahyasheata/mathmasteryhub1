<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/RevisionPlan.php';

$admin = db();
$testDatabase = 'mmh_revision_delete_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!preg_match('/\Ammh_revision_delete_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) throw new RuntimeException('Unsafe test database name.');
$query = static function (mysqli $conn, string $sql): mysqli_result|bool { $result = $conn->query($sql); if ($result === false) throw new RuntimeException($conn->error ?: 'Database test query failed.'); return $result; };
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

try {
    $query($admin, 'CREATE DATABASE ' . chr(96) . $testDatabase . chr(96) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($testDatabase), 'Unable to select isolated Revision Plan database.');
    $query($admin, 'CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(180) NOT NULL)');
    $query($admin, 'CREATE TABLE revision_plan_templates (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, course_id VARCHAR(40) NOT NULL, title VARCHAR(180) NOT NULL, status VARCHAR(16) NOT NULL, archived_at DATETIME NULL)');
    $query($admin, 'CREATE TABLE revision_plan_template_versions (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, template_id BIGINT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL)');
    $query($admin, 'CREATE TABLE revision_plan_template_batches (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, version_id BIGINT UNSIGNED NOT NULL)');
    $query($admin, 'CREATE TABLE revision_plan_template_days (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, batch_id BIGINT UNSIGNED NOT NULL, version_id BIGINT UNSIGNED NOT NULL)');
    $query($admin, 'CREATE TABLE revision_plan_template_activities (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, day_id BIGINT UNSIGNED NOT NULL, version_id BIGINT UNSIGNED NOT NULL)');
    $query($admin, 'CREATE TABLE revision_plan_template_requirements (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, version_id BIGINT UNSIGNED NOT NULL, day_id BIGINT UNSIGNED NOT NULL, activity_id BIGINT UNSIGNED NULL)');
    $query($admin, 'CREATE TABLE revision_plan_template_resources (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, version_id BIGINT UNSIGNED NOT NULL, storage_key VARCHAR(500) NULL)');
    $query($admin, 'CREATE TABLE revision_plan_requirement_resources (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, requirement_id BIGINT UNSIGNED NOT NULL, resource_id BIGINT UNSIGNED NOT NULL)');
    $query($admin, 'CREATE TABLE revision_plan_assignments (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, template_id BIGINT UNSIGNED NOT NULL, template_version_id BIGINT UNSIGNED NOT NULL)');
    $query($admin, 'CREATE TABLE revision_plan_requirement_progress (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, assignment_id BIGINT UNSIGNED NOT NULL, requirement_id BIGINT UNSIGNED NOT NULL)');
    $query($admin, 'CREATE TABLE revision_plan_batch_releases (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, template_id BIGINT UNSIGNED NOT NULL, source_version_id BIGINT UNSIGNED NOT NULL, source_batch_id BIGINT UNSIGNED NOT NULL)');
    $query($admin, "INSERT INTO courses (course_id, course_title) VALUES ('source-course', 'Untouched Course')");

    $seed = static function (mysqli $conn, int $templateId, bool $withActivity = true): void {
        $insert = static function (mysqli $db, string $sql): void { if (!$db->query($sql)) throw new RuntimeException($db->error ?: 'Fixture insert failed.'); };
        $insert($conn, "INSERT INTO revision_plan_templates (id, course_id, title, status) VALUES ($templateId, 'source-course', 'Delete test', 'active')");
        $insert($conn, "INSERT INTO revision_plan_template_versions (id, template_id, status) VALUES ($templateId, $templateId, 'published')");
        $insert($conn, "INSERT INTO revision_plan_template_batches (id, version_id) VALUES ($templateId, $templateId)");
        $insert($conn, "INSERT INTO revision_plan_template_days (id, batch_id, version_id) VALUES ($templateId, $templateId, $templateId)");
        $insert($conn, "INSERT INTO revision_plan_template_activities (id, day_id, version_id) VALUES ($templateId, $templateId, $templateId)");
        $insert($conn, "INSERT INTO revision_plan_template_requirements (id, version_id, day_id, activity_id) VALUES ($templateId, $templateId, $templateId, $templateId)");
        $insert($conn, "INSERT INTO revision_plan_template_resources (id, version_id, storage_key) VALUES ($templateId, $templateId, NULL)");
        $insert($conn, "INSERT INTO revision_plan_requirement_resources (id, requirement_id, resource_id) VALUES ($templateId, $templateId, $templateId)");
        if ($withActivity) {
            $insert($conn, "INSERT INTO revision_plan_assignments (id, template_id, template_version_id) VALUES ($templateId, $templateId, $templateId)");
            $insert($conn, "INSERT INTO revision_plan_requirement_progress (id, assignment_id, requirement_id) VALUES ($templateId, $templateId, $templateId)");
            $insert($conn, "INSERT INTO revision_plan_batch_releases (id, template_id, source_version_id, source_batch_id) VALUES ($templateId, $templateId, $templateId, $templateId)");
        }
    };

    $seed($admin, 1, true);
    $blocked = false;
    try { mmh_revision_delete_template($admin, 1, false); } catch (InvalidArgumentException $e) { $blocked = str_contains($e->getMessage(), 'Type DELETE'); }
    $assert($blocked, 'Student activity must require DELETE confirmation.');
    $assert((int) $admin->query('SELECT COUNT(*) AS total FROM revision_plan_templates WHERE id=1')->fetch_assoc()['total'] === 1, 'Unconfirmed delete changed data.');
    mmh_revision_delete_template($admin, 1, true);
    foreach (['revision_plan_requirement_progress', 'revision_plan_requirement_resources', 'revision_plan_template_requirements', 'revision_plan_template_activities', 'revision_plan_template_days', 'revision_plan_template_resources', 'revision_plan_batch_releases', 'revision_plan_assignments', 'revision_plan_template_batches', 'revision_plan_template_versions', 'revision_plan_templates'] as $table) {
        $assert((int) $admin->query("SELECT COUNT(*) AS total FROM $table")->fetch_assoc()['total'] === 0, 'Rows remain in ' . $table);
    }
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM courses WHERE course_id='source-course'")->fetch_assoc()['total'] === 1, 'Source course was changed.');

    $seed($admin, 2, false);
    mmh_revision_delete_template($admin, 2, false);
    $assert((int) $admin->query('SELECT COUNT(*) AS total FROM revision_plan_templates WHERE id=2')->fetch_assoc()['total'] === 0, 'Empty Draft was not deleted.');

    $seed($admin, 3, true);
    $query($admin, "CREATE TRIGGER revision_delete_forced_failure BEFORE DELETE ON revision_plan_template_resources FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced rollback'");
    $rolledBack = false;
    try { mmh_revision_delete_template($admin, 3, true); } catch (Throwable $e) { $rolledBack = true; }
    $assert($rolledBack, 'Forced failure did not reach rollback path.');
    $assert((int) $admin->query('SELECT COUNT(*) AS total FROM revision_plan_templates WHERE id=3')->fetch_assoc()['total'] === 1, 'Rollback did not preserve the template.');
    $query($admin, 'DROP TRIGGER revision_delete_forced_failure');

    echo "revision_plan_delete_database=activity_confirmation=PASS empty_draft=PASS dependency_cleanup=PASS source_data_untouched=PASS rollback=PASS\n";
} finally {
    $cleanup = mysqli_connect((string) ($host ?? ''), (string) ($user ?? ''), (string) ($pass ?? ''));
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_revision_delete_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) { $cleanup->query('DROP DATABASE ' . chr(96) . $testDatabase . chr(96)); $cleanup->close(); }
}
