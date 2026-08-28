<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/RevisionPlan.php';
$db = db();
$name = 'mmh_revision_upgrade_' . getmypid() . '_' . bin2hex(random_bytes(3));
$exec = static function (mysqli $conn, string $sql): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error ?: 'Fixture query failed.'); };
try {
    $exec($db, 'CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    if (!$db->select_db($name)) throw new RuntimeException('Unable to select isolated database.');
    $exec($db, "CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(180), course_state VARCHAR(24))");
    $exec($db, "CREATE TABLE revision_plan_templates (id BIGINT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40), title VARCHAR(180), description TEXT, status VARCHAR(16), created_by INT, archived_at DATETIME NULL, updated_at DATETIME NULL)");
    $exec($db, "CREATE TABLE revision_plan_template_versions (id BIGINT AUTO_INCREMENT PRIMARY KEY, template_id BIGINT, version_number INT, status VARCHAR(16), allow_work_ahead TINYINT DEFAULT 0, created_by INT, published_at DATETIME NULL, updated_at DATETIME NULL)");
    $exec($db, "CREATE TABLE revision_plan_template_batches (id BIGINT AUTO_INCREMENT PRIMARY KEY, version_id BIGINT, title VARCHAR(180), description TEXT, suggested_days INT, sort_order INT)");
    $exec($db, "CREATE TABLE revision_plan_template_days (id BIGINT AUTO_INCREMENT PRIMARY KEY, batch_id BIGINT, version_id BIGINT, day_number INT, title VARCHAR(180), description TEXT, sort_order INT)");
    $exec($db, "CREATE TABLE revision_plan_template_activities (id BIGINT AUTO_INCREMENT PRIMARY KEY, day_id BIGINT, version_id BIGINT, title VARCHAR(180), description TEXT, sort_order INT)");
    $exec($db, "CREATE TABLE revision_plan_template_requirements (id BIGINT AUTO_INCREMENT PRIMARY KEY, version_id BIGINT, source_requirement_id BIGINT UNSIGNED NULL, day_id BIGINT, activity_id BIGINT NULL, title VARCHAR(180), description TEXT, requirement_type VARCHAR(24), is_required TINYINT, sort_order INT, linked_course_item_id VARCHAR(40) NULL, allow_multiple_files TINYINT DEFAULT 0, accepted_file_policy VARCHAR(80) DEFAULT 'pdf')");
    $exec($db, "CREATE TABLE revision_plan_template_resources (id BIGINT AUTO_INCREMENT PRIMARY KEY, version_id BIGINT, batch_id BIGINT NULL, resource_type VARCHAR(24), display_name VARCHAR(180), external_url TEXT, storage_key TEXT, original_filename VARCHAR(255), mime_type VARCHAR(120), file_size_bytes BIGINT DEFAULT 0, linked_course_item_id VARCHAR(40) NULL, sort_order INT DEFAULT 0, created_by INT)");
    $exec($db, "CREATE TABLE revision_plan_requirement_resources (id BIGINT AUTO_INCREMENT PRIMARY KEY, requirement_id BIGINT, resource_id BIGINT, sort_order INT DEFAULT 0)");
    $exec($db, "CREATE TABLE revision_plan_assignments (id BIGINT AUTO_INCREMENT PRIMARY KEY, template_id BIGINT, template_version_id BIGINT, course_id VARCHAR(40), user_id INT, start_date DATE, status VARCHAR(16), assigned_at DATETIME NULL, archived_at DATETIME NULL, ended_at DATETIME NULL)");
    $exec($db, "CREATE TABLE revision_plan_requirement_progress (id BIGINT AUTO_INCREMENT PRIMARY KEY, assignment_id BIGINT, requirement_id BIGINT, completed_at DATETIME NOT NULL, UNIQUE KEY uq_progress (assignment_id, requirement_id))");
    $exec($db, "CREATE TABLE revision_plan_requirement_submissions (id BIGINT AUTO_INCREMENT PRIMARY KEY, assignment_id BIGINT, requirement_id BIGINT, submitted_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_submission (assignment_id, requirement_id))");
    $exec($db, "CREATE TABLE revision_plan_submission_files (id BIGINT AUTO_INCREMENT PRIMARY KEY, submission_id BIGINT, file_path VARCHAR(500), original_filename VARCHAR(255), mime_type VARCHAR(120), file_size_bytes BIGINT, sort_order INT, uploaded_at DATETIME)");
    $exec($db, "INSERT INTO courses VALUES ('c1','Course 1','private')");
    $exec($db, "INSERT INTO revision_plan_templates VALUES (1,'c1','Continuity Plan','', 'active',7,NULL,NOW())");
    $exec($db, "INSERT INTO revision_plan_template_versions VALUES (1,1,1,'published',0,7,NOW(),NOW()),(2,1,2,'draft',0,7,NULL,NOW())");
    $exec($db, "INSERT INTO revision_plan_template_batches VALUES (1,1,'Batch 1','',1,0),(2,2,'Batch 1','',1,0)");
    $exec($db, "INSERT INTO revision_plan_template_days VALUES (1,1,1,1,'Day 1','',0),(2,2,2,1,'Day 1','',0)");
    $exec($db, "INSERT INTO revision_plan_template_requirements VALUES (1,1,NULL,1,NULL,'Task A','', 'checklist',1,0,NULL,0,'pdf'),(2,2,1,2,NULL,'Task A updated','', 'checklist',1,0,NULL,0,'pdf'),(3,2,NULL,2,NULL,'Task B new','', 'checklist',1,1,NULL,0,'pdf')");
    $exec($db, "INSERT INTO revision_plan_assignments VALUES (1,1,1,'c1',10,'2026-08-27','active','2026-08-27 08:00:00',NULL,NULL),(2,1,1,'c1',10,'2026-08-27','active','2026-08-28 08:00:00',NULL,NULL)");
    $exec($db, "INSERT INTO revision_plan_requirement_progress (assignment_id,requirement_id,completed_at) VALUES (1,1,'2026-08-27 09:00:00'),(2,1,'2026-08-28 09:00:00')");
    $exec($db, "INSERT INTO revision_plan_requirement_submissions (id,assignment_id,requirement_id,submitted_at,updated_at) VALUES (1,1,1,'2026-08-27 09:00:00','2026-08-27 09:00:00')");
    $exec($db, "INSERT INTO revision_plan_submission_files VALUES (1,1,'storage/private/revision-plans/1/a.pdf','a.pdf','application/pdf',100,0,'2026-08-27 09:00:00')");
    mmh_revision_publish_version($db, 2, 7);
    $row = $db->query('SELECT template_version_id, start_date, assigned_at FROM revision_plan_assignments WHERE id = 1')->fetch_assoc();
    if ((int) $row['template_version_id'] !== 2 || $row['start_date'] !== '2026-08-27' || $row['assigned_at'] !== '2026-08-27 08:00:00') throw new RuntimeException('Canonical assignment was not upgraded while preserving schedule metadata.');
    $dup = $db->query("SELECT status FROM revision_plan_assignments WHERE id = 2")->fetch_assoc();
    if (($dup['status'] ?? '') !== 'archived') throw new RuntimeException('Superseded duplicate assignment was not archived.');
    if ((int) $db->query("SELECT COUNT(*) AS total FROM revision_plan_assignments WHERE template_id = 1 AND user_id = 10 AND status = 'active'")->fetch_assoc()['total'] !== 1) throw new RuntimeException('Logical plan still has more than one active student assignment.');
    if ($db->query('SELECT 1 FROM revision_plan_requirement_progress WHERE assignment_id = 1 AND requirement_id = 2')->num_rows !== 1) throw new RuntimeException('Checklist progress did not follow requirement lineage.');
    if ($db->query('SELECT 1 FROM revision_plan_submission_files WHERE submission_id = 2')->num_rows !== 1) throw new RuntimeException('Revision upload evidence did not follow requirement lineage.');
    if ($db->query('SELECT 1 FROM revision_plan_requirement_progress WHERE assignment_id = 1 AND requirement_id = 3')->num_rows !== 0) throw new RuntimeException('New requirement incorrectly inherited completion.');
    echo "revision_plan_version_upgrade_db=publish=PASS assignment_upgrade=PASS_start_date_preserved=PASS_duplicate_archived=PASS_progress_transfer=PASS_upload_transfer=PASS_new_task_uncompleted=PASS\n";
} finally {
    $cleanup = mysqli_connect((string) ($host ?? ''), (string) ($user ?? ''), (string) ($pass ?? ''));
    if ($cleanup instanceof mysqli && preg_match('/\\Ammh_revision_upgrade_[0-9]+_[a-f0-9]{6}\\z/', $name)) { $cleanup->query('DROP DATABASE `' . $name . '`'); $cleanup->close(); }
}
