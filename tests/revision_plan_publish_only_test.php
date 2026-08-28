<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/RevisionPlan.php';

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/views/admin/revision-plans.php');
$save = file_get_contents($root . '/views/admin/requests/save-revision-plan.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(str_contains($admin, 'function submitPublishOnly()'), 'Publish Only does not have a dedicated submit helper.');
$assert(str_contains($admin, "action:'publish_version'"), 'Publish Only action is not explicit.');
$assert(!str_contains($admin, 'querySelector(\'input[name="action"]\')'), 'Publish Only still mutates a shared hidden action.');
$assert(str_contains($save, "if (\$action === 'publish_version')"), 'Publish handler branch is missing.');

$db = db();
$name = 'mmh_revision_publish_only_' . getmypid() . '_' . bin2hex(random_bytes(3));
$query = static function (mysqli $conn, string $sql): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error ?: 'Fixture query failed.'); };
try {
    $query($db, 'CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($db->select_db($name), 'Unable to select isolated database.');
    $query($db, "CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(180), course_state VARCHAR(24))");
    $query($db, "CREATE TABLE revision_plan_templates (id BIGINT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40), title VARCHAR(180), description VARCHAR(1000), status VARCHAR(16), created_by INT, archived_at DATETIME NULL, updated_at DATETIME NULL)");
    $query($db, "CREATE TABLE revision_plan_template_versions (id BIGINT AUTO_INCREMENT PRIMARY KEY, template_id BIGINT, version_number INT, status VARCHAR(16), allow_work_ahead TINYINT DEFAULT 0, created_by INT, published_at DATETIME NULL, updated_at DATETIME NULL)");
    $query($db, "CREATE TABLE revision_plan_template_batches (id BIGINT AUTO_INCREMENT PRIMARY KEY, version_id BIGINT, title VARCHAR(180), description VARCHAR(1000), suggested_days INT, sort_order INT)");
    $query($db, "CREATE TABLE revision_plan_template_days (id BIGINT AUTO_INCREMENT PRIMARY KEY, batch_id BIGINT, version_id BIGINT, day_number INT, title VARCHAR(180), description VARCHAR(1000), sort_order INT)");
    $query($db, "CREATE TABLE revision_plan_template_activities (id BIGINT AUTO_INCREMENT PRIMARY KEY, day_id BIGINT, version_id BIGINT, title VARCHAR(180), description VARCHAR(1000), sort_order INT)");
    $query($db, "CREATE TABLE revision_plan_template_requirements (id BIGINT AUTO_INCREMENT PRIMARY KEY, version_id BIGINT, day_id BIGINT, activity_id BIGINT NULL, title VARCHAR(180), description TEXT, requirement_type VARCHAR(24), is_required TINYINT, sort_order INT, linked_course_item_id VARCHAR(40) NULL, allow_multiple_files TINYINT DEFAULT 0, accepted_file_policy VARCHAR(80) DEFAULT 'pdf')");
    $query($db, "CREATE TABLE revision_plan_template_resources (id BIGINT AUTO_INCREMENT PRIMARY KEY, version_id BIGINT, batch_id BIGINT NULL, resource_type VARCHAR(24), display_name VARCHAR(180), external_url VARCHAR(1000), storage_key VARCHAR(500), original_filename VARCHAR(255), mime_type VARCHAR(120), file_size_bytes BIGINT DEFAULT 0, linked_course_item_id VARCHAR(40) NULL, sort_order INT DEFAULT 0, created_by INT)");
    $query($db, "CREATE TABLE revision_plan_requirement_resources (id BIGINT AUTO_INCREMENT PRIMARY KEY, requirement_id BIGINT, resource_id BIGINT, sort_order INT DEFAULT 0)");
    $query($db, "INSERT INTO courses VALUES ('course-1','Test Course','private')");
    $query($db, "INSERT INTO revision_plan_templates VALUES (1,'course-1','Publish Only Plan','', 'active',7,NULL,NOW())");
    $query($db, "INSERT INTO revision_plan_template_versions VALUES (1,1,1,'draft',0,7,NULL,NOW())");
    $query($db, "INSERT INTO revision_plan_template_batches VALUES (1,1,'Batch 1','',1,0)");
    $query($db, "INSERT INTO revision_plan_template_days VALUES (1,1,1,1,'Day 1','',0)");
    $query($db, "INSERT INTO revision_plan_template_requirements VALUES (1,1,1,NULL,'Review','', 'checklist',1,0,NULL,0,'pdf')");
    mmh_revision_publish_version($db, 1, 7);
    $row = $db->query('SELECT status FROM revision_plan_template_versions WHERE id = 1')->fetch_assoc();
    $assert(($row['status'] ?? '') === 'published', 'Canonical Publish Only service did not publish the same Version.');
    $assert($db->query("SHOW TABLES LIKE 'revision_plan_assignments'")->num_rows === 0, 'Publish Only fixture unexpectedly created assignments.');
    echo "revision_plan_publish_only=explicit_action=PASS canonical_publish=PASS no_assignment=PASS\n";
} finally {
    $cleanup = mysqli_connect((string) ($host ?? ''), (string) ($user ?? ''), (string) ($pass ?? ''));
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_revision_publish_only_[0-9]+_[a-f0-9]{6}\z/', $name)) { $cleanup->query('DROP DATABASE `' . $name . '`'); $cleanup->close(); }
}
