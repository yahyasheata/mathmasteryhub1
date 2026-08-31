<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/RevisionPlan.php';

$db = db();
$database = 'mmh_revision_batch_edit_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$query = static function (mysqli $conn, string $sql): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error ?: 'Fixture query failed.'); };
try {
    $query($db, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($db->select_db($database), 'Unable to select isolated database.');
    $query($db, "CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(180), course_state VARCHAR(24))");
    $query($db, "CREATE TABLE course_sections (section_id VARCHAR(40) PRIMARY KEY, course_id VARCHAR(40), title VARCHAR(180), sort_order INT DEFAULT 0)");
    $query($db, "CREATE TABLE course_items (id INT AUTO_INCREMENT PRIMARY KEY, item_id VARCHAR(40), item_title VARCHAR(180), item_description TEXT, section_id VARCHAR(40), item_type VARCHAR(40), template_type VARCHAR(40), template_data TEXT, assignment_id INT NULL, duration_minutes INT NULL, sort_order INT DEFAULT 0, page_order INT DEFAULT 0, course_id VARCHAR(40))");
    $query($db, "CREATE TABLE revision_plan_templates (id BIGINT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40), title VARCHAR(180), description VARCHAR(1000), status VARCHAR(16), created_by INT, archived_at DATETIME NULL, updated_at DATETIME NULL)");
    $query($db, "CREATE TABLE revision_plan_template_versions (id BIGINT AUTO_INCREMENT PRIMARY KEY, template_id BIGINT, version_number INT, status VARCHAR(16), allow_work_ahead TINYINT DEFAULT 0, created_by INT, published_at DATETIME NULL, updated_at DATETIME NULL)");
    $query($db, "CREATE TABLE revision_plan_template_batches (id BIGINT AUTO_INCREMENT PRIMARY KEY, version_id BIGINT, title VARCHAR(180), description VARCHAR(1000), suggested_days INT, sort_order INT)");
    $query($db, "CREATE TABLE revision_plan_template_days (id BIGINT AUTO_INCREMENT PRIMARY KEY, batch_id BIGINT, version_id BIGINT, day_number INT, title VARCHAR(180), description VARCHAR(1000), sort_order INT)");
    $query($db, "CREATE TABLE revision_plan_template_activities (id BIGINT AUTO_INCREMENT PRIMARY KEY, day_id BIGINT, version_id BIGINT, title VARCHAR(180), description VARCHAR(1000), sort_order INT)");
    $query($db, "CREATE TABLE revision_plan_template_requirements (id BIGINT AUTO_INCREMENT PRIMARY KEY, version_id BIGINT, source_requirement_id BIGINT NULL, day_id BIGINT, activity_id BIGINT NULL, title VARCHAR(180), description TEXT, requirement_type VARCHAR(24), is_required TINYINT, sort_order INT, linked_course_item_id VARCHAR(40) NULL, allow_multiple_files TINYINT DEFAULT 0, accepted_file_policy VARCHAR(80) DEFAULT 'pdf')");
    $query($db, "CREATE TABLE revision_plan_template_resources (id BIGINT AUTO_INCREMENT PRIMARY KEY, version_id BIGINT, batch_id BIGINT NULL, resource_type VARCHAR(24), display_name VARCHAR(180), external_url VARCHAR(1000), storage_key VARCHAR(500), original_filename VARCHAR(255), mime_type VARCHAR(120), file_size_bytes BIGINT DEFAULT 0, linked_course_item_id VARCHAR(40) NULL, sort_order INT DEFAULT 0, created_by INT)");
    $query($db, "CREATE TABLE revision_plan_requirement_resources (id BIGINT AUTO_INCREMENT PRIMARY KEY, requirement_id BIGINT, resource_id BIGINT, sort_order INT DEFAULT 0)");
    $query($db, "INSERT INTO courses VALUES ('course-1','Test Course','private')");
    $query($db, "INSERT INTO revision_plan_templates VALUES (1,'course-1','Published Plan','Keep this description','active',7,NULL,NOW())");
    $query($db, "INSERT INTO revision_plan_template_versions VALUES (1,1,1,'published',0,7,NOW(),NOW())");
    $query($db, "INSERT INTO revision_plan_template_batches VALUES (1,1,'Batch 1','',1,0)");
    $query($db, "INSERT INTO revision_plan_template_days VALUES (1,1,1,1,'Day 1','',0)");
    $query($db, "INSERT INTO revision_plan_template_requirements VALUES (1,1,NULL,1,NULL,'Task A','', 'checklist',1,0,NULL,0,'pdf')");

    $draftId = mmh_revision_prepare_editable_version($db, 1, 1, 7);
    $assert($draftId !== 1, 'Published Version was not cloned to a Draft Version.');
    $draft = mmh_revision_version($db, $draftId);
    $assert(is_array($draft) && ($draft['status'] ?? '') === 'draft', 'Prepared Version is not a Draft.');
    $assert(count($draft['batches'] ?? []) === 1, 'Existing Batch structure was not preserved.');
    $assert(count($draft['batches'][0]['days'][0]['requirements'] ?? []) === 1, 'Lineage-bearing requirement was not cloned into the Draft.');
    $assert((int) ($draft['batches'][0]['days'][0]['requirements'][0]['source_requirement_id'] ?? 0) === 1, 'Requirement lineage was not preserved during the clone.');

    $structure = ['batches' => $draft['batches']];
    $structure['batches'][] = ['title' => 'Batch 2', 'description' => '', 'suggested_days' => 2, 'day_access_mode' => 'follow_schedule', 'days' => [
        ['title' => 'Day 1', 'description' => '', 'requirements' => [], 'activity_groups' => []],
        ['title' => 'Day 2', 'description' => '', 'requirements' => [], 'activity_groups' => []],
    ]];
    $structure['batches'][] = ['title' => 'Batch 3', 'description' => '', 'suggested_days' => 1, 'day_access_mode' => 'follow_schedule', 'days' => [
        ['title' => 'Day 1', 'description' => '', 'requirements' => [], 'activity_groups' => []],
    ]];
    mmh_revision_save_draft($db, $draftId, $structure, 'Published Plan', 'Keep this description', false);
    $saved = mmh_revision_version($db, $draftId);
    $assert(count($saved['batches'] ?? []) === 3, 'New Batches were not persisted.');
    $assert(count($saved['batches'][1]['days'] ?? []) === 2, 'Requested Batch 2 Days were not created.');
    $assert(count($saved['batches'][2]['days'] ?? []) === 1, 'Requested Batch 3 Days were not created.');
    $saved['batches'][1]['title'] = 'Algebra Revision';
    $saved['batches'][2]['title'] = 'Geometry Revision';
    mmh_revision_save_draft($db, $draftId, ['batches' => $saved['batches']], 'Published Plan', 'Keep this description', false);
    $edited = mmh_revision_version($db, $draftId);
    $assert(($edited['batches'][1]['title'] ?? '') === 'Algebra Revision', 'Batch 2 title edit was not persisted.');
    $assert(($edited['batches'][2]['title'] ?? '') === 'Geometry Revision', 'Batch 3 title edit was not persisted.');
    $published = mmh_revision_version($db, 1);
    $assert(count($published['batches'] ?? []) === 1, 'Published Version was mutated.');
    $assert(($saved['template_title'] ?? '') === 'Published Plan', 'Plan title changed unexpectedly.');
    echo "revision_plan_batch_edit_database=auto_draft=PASS add_batch=PASS days=PASS published_immutable=PASS\n";
} finally {
    $cleanup = mysqli_connect((string) ($host ?? ''), (string) ($user ?? ''), (string) ($pass ?? ''));
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_revision_batch_edit_test_[0-9]+_[a-f0-9]{8}\z/', $database)) { $cleanup->query('DROP DATABASE `' . $database . '`'); $cleanup->close(); }
}
