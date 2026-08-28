<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/RevisionPlan.php';

$root = dirname(__DIR__);
$service = file_get_contents($root . '/inc/RevisionPlan.php');
$migration = file_get_contents($root . '/database/migrations/20260828_reconcile_deleted_revision_plan_assignments.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
foreach (["status = 'archived'", 'ended_at = COALESCE', 'begin_transaction'] as $needle) $assert(str_contains($service, $needle), 'Archive/revocation contract missing: ' . $needle);
foreach (['revision_plan_assignments', 'Repaired', 't.archived_at IS NOT NULL', "IN ('archived', 'deleted')"] as $needle) $assert(str_contains($migration, $needle), 'Reconciliation migration contract missing: ' . $needle);

$admin = db();
$name = 'mmh_revision_deleted_visibility_' . getmypid() . '_' . bin2hex(random_bytes(3));
$query = static function (mysqli $conn, string $sql): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error ?: 'Fixture query failed.'); };
try {
    $query($admin, 'CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    if (!$admin->select_db($name)) throw new RuntimeException('Unable to select isolated database.');
    $query($admin, "CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(180), course_state VARCHAR(16), course_status VARCHAR(16) NULL, course_visibility VARCHAR(16) NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE users (user_id INT PRIMARY KEY, username VARCHAR(120), full_name VARCHAR(180), role VARCHAR(20), status VARCHAR(4), archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE course_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, course_id VARCHAR(40))");
    $query($admin, "CREATE TABLE revision_plan_templates (id BIGINT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40), title VARCHAR(180), description VARCHAR(1000), status VARCHAR(16), created_at DATETIME NULL, updated_at DATETIME NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE revision_plan_template_versions (id BIGINT AUTO_INCREMENT PRIMARY KEY, template_id BIGINT, version_number INT, status VARCHAR(16), allow_work_ahead TINYINT DEFAULT 0, published_at DATETIME NULL)");
    $query($admin, "CREATE TABLE revision_plan_assignments (id BIGINT AUTO_INCREMENT PRIMARY KEY, template_id BIGINT, template_version_id BIGINT, course_id VARCHAR(40), user_id INT, start_date DATE, status VARCHAR(16), assigned_at DATETIME NULL, archived_at DATETIME NULL, ended_at DATETIME NULL)");
    $query($admin, "INSERT INTO courses VALUES ('course-1','Visibility Course','private','1','private',NULL)");
    $query($admin, "INSERT INTO users VALUES (7,'student@example.com','Student','user','1',NULL)");
    $query($admin, "INSERT INTO course_logs (user_id,course_id) VALUES (7,'course-1')");
    $query($admin, "INSERT INTO revision_plan_templates VALUES (1,'course-1','Archived Plan','', 'active',NOW(),NOW(),NULL)");
    $query($admin, "INSERT INTO revision_plan_template_versions VALUES (1,1,1,'published',0,NOW())");
    $query($admin, "INSERT INTO revision_plan_assignments VALUES (1,1,1,'course-1',7,CURRENT_DATE,'active',NOW(),NULL,NULL)");
    $assert(count(mmh_revision_student_assignments($admin, 7)) === 1, 'Active assignment was not visible before archive.');
    mmh_revision_archive_template($admin, 1);
    $row = $admin->query('SELECT status, archived_at, ended_at FROM revision_plan_assignments WHERE id=1')->fetch_assoc();
    $assert(($row['status'] ?? '') === 'archived' && !empty($row['archived_at']) && !empty($row['ended_at']), 'Archive did not revoke the active assignment.');
    $assert(count(mmh_revision_student_assignments($admin, 7)) === 0, 'Archived Plan assignment remained student-visible.');
    $query($admin, "INSERT INTO revision_plan_templates VALUES (2,'course-1','Legacy Deleted Plan','', 'deleted',NOW(),NOW(),NOW())");
    $query($admin, "INSERT INTO revision_plan_template_versions VALUES (2,2,1,'published',0,NOW())");
    $query($admin, "INSERT INTO revision_plan_assignments VALUES (2,2,2,'course-1',7,CURRENT_DATE,'active',NOW(),NULL,NULL)");
    $assert(count(mmh_revision_student_assignments($admin, 7)) === 0, 'Deleted-status legacy assignment was returned.');
    echo "revision_plan_deleted_assignment_visibility=archive_revoke=PASS defensive_query=PASS legacy_deleted=PASS\n";
} finally {
    $cleanup = mysqli_connect((string) ($host ?? ''), (string) ($user ?? ''), (string) ($pass ?? ''));
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_revision_deleted_visibility_[0-9]+_[a-f0-9]{6}\z/', $name)) { $cleanup->query('DROP DATABASE `' . $name . '`'); $cleanup->close(); }
}
