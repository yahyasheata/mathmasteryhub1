<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/CourseContentCopyService.php';

$dbHost = (string) $host; $dbUser = (string) $user; $dbPass = (string) $pass;
$database = 'mmh_course_copy_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$admin = db();
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$query = static function (mysqli $conn, string $sql) use ($assert): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error); };

try {
    $query($admin, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($database), 'Unable to select isolated copy database.');
    $query($admin, "CREATE TABLE courses (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(20) NOT NULL, course_title VARCHAR(190) NOT NULL, course_state VARCHAR(16) NOT NULL)");
    $query($admin, "CREATE TABLE course_sections (id INT AUTO_INCREMENT PRIMARY KEY, section_id VARCHAR(20) NOT NULL, course_id VARCHAR(20) NOT NULL, title VARCHAR(190) NOT NULL, section_type VARCHAR(50), custom_type VARCHAR(80), icon VARCHAR(50), description TEXT, metadata TEXT, sort_order INT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'published', unlock_mode VARCHAR(50), completion_rule VARCHAR(50), unlock_at DATETIME NULL, unlock_timezone VARCHAR(64), unlock_homework_id VARCHAR(20), manual_unlocked TINYINT NOT NULL DEFAULT 0, release_mode VARCHAR(32) NOT NULL DEFAULT 'inherit', release_override VARCHAR(16) NOT NULL DEFAULT 'inherit', release_at DATETIME NULL, release_timezone VARCHAR(80), release_occurrence_id VARCHAR(64), release_delay_minutes INT NOT NULL DEFAULT 0, release_updated_at DATETIME NULL)");
    $query($admin, "CREATE TABLE course_items (id INT AUTO_INCREMENT PRIMARY KEY, item_id VARCHAR(20) NOT NULL, item_title VARCHAR(255) NOT NULL, item_description TEXT, item_type VARCHAR(20) NOT NULL, section_id VARCHAR(20), template_type VARCHAR(50), template_data TEXT, metadata TEXT, duration_minutes INT NULL, assignment_id INT NULL, due_date DATETIME NULL, status VARCHAR(16) NOT NULL DEFAULT 'published', sort_order INT NOT NULL DEFAULT 0, course_id VARCHAR(20) NOT NULL, page_order INT NOT NULL DEFAULT 0)");
    $query($admin, "CREATE TABLE assignments (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(20) NOT NULL, assignment_title VARCHAR(255) NOT NULL, assignment_description TEXT, due_date DATETIME NULL, file_path VARCHAR(255), course_id VARCHAR(20) NOT NULL, section_id VARCHAR(20), item_id VARCHAR(20), max_score DECIMAL(6,2) NULL, recommended_recording_item_id VARCHAR(40), recommended_notes_item_id VARCHAR(40), recommended_revision_item_id VARCHAR(40), archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE assignment_model_answer_access (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(20) NOT NULL, user_id INT NOT NULL)");
    $query($admin, "CREATE TABLE assignment_submissions (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(20) NOT NULL, student_id INT NOT NULL)");
    $query($admin, "CREATE TABLE timed_exams (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(20) NOT NULL, item_id VARCHAR(20) NOT NULL, title VARCHAR(190) NOT NULL, instructions TEXT, status VARCHAR(16) NOT NULL DEFAULT 'draft', timing_mode VARCHAR(24) NOT NULL DEFAULT 'fixed_window', scheduled_start_at_utc DATETIME NULL, duration_minutes INT NOT NULL DEFAULT 60, grace_minutes INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 1, allowed_answer_types VARCHAR(255) NOT NULL DEFAULT 'pdf', max_file_size_bytes BIGINT NOT NULL DEFAULT 10485760, paper_source VARCHAR(24) NOT NULL DEFAULT 'external_link', paper_external_url VARCHAR(1000), paper_external_preview_url VARCHAR(1000), paper_external_download_url VARCHAR(1000), paper_fallback_instructions TEXT, paper_storage_key VARCHAR(255), paper_original_name VARCHAR(255), paper_mime VARCHAR(120), paper_size_bytes BIGINT NULL, paper_view_allowed TINYINT NOT NULL DEFAULT 1, paper_download_allowed TINYINT NOT NULL DEFAULT 1, late_submission_allowed TINYINT NOT NULL DEFAULT 1, expiry_policy VARCHAR(32) NOT NULL DEFAULT 'auto_submit_latest', max_marks DECIMAL(10,2), results_release_at_utc DATETIME NULL, recovery_window_start_at_utc DATETIME NULL, recovery_window_end_at_utc DATETIME NULL, recovery_allowed TINYINT NOT NULL DEFAULT 0, attempt_generation INT UNSIGNED NOT NULL DEFAULT 1, deleted_at DATETIME NULL, roster_finalized_at_utc DATETIME NULL, roster_finalized_generation INT UNSIGNED NULL, created_by INT NULL, updated_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $query($admin, "INSERT INTO courses (course_id,course_title,course_state) VALUES ('source','Source','public'),('destination','Destination','draft')");
    $query($admin, "INSERT INTO course_sections (section_id,course_id,title,section_type,description,metadata,sort_order,status,unlock_mode,completion_rule,release_mode,release_override,release_delay_minutes) VALUES ('s1','source','Week 1','lecture','desc','{\"release_occurrence_id\":\"old-occurrence\"}',1,'published','always','manual_completion','inherit','inherit',0)");
    $query($admin, "INSERT INTO course_items (item_id,item_title,item_description,item_type,section_id,template_type,template_data,metadata,assignment_id,status,sort_order,course_id,page_order) VALUES ('lesson1','Lesson 1','body','file','s1','custom_lesson','{\"section_id\":\"s1\"}',NULL,NULL,'published',1,'source',1),('homework1','Homework 1','body','quiz','s1','classified_assignment','{\"assignment_id\":\"42\"}',NULL,42,'published',2,'source',2),('exam1','Exam 1','body','quiz','s1','timed_exam','{}',NULL,NULL,'published',3,'source',3)");
    $query($admin, "INSERT INTO assignments (assignment_id,assignment_title,assignment_description,due_date,course_id,section_id,item_id,max_score) VALUES ('42','Homework 1','Instructions',NULL,'source','s1','homework1',100)");
    $query($admin, "INSERT INTO assignment_model_answer_access (assignment_id,user_id) VALUES ('42',7)");
    $query($admin, "INSERT INTO assignment_submissions (assignment_id,student_id) VALUES ('42',7)");
    $query($admin, "INSERT INTO timed_exams (course_id,item_id,title,instructions,status,scheduled_start_at_utc,duration_minutes,grace_minutes,paper_external_url) VALUES ('source','exam1','Exam 1','Instructions','published','2030-01-01 10:00:00',60,5,'https://drive.google.com/file/d/example/view')");

    $item = CourseContentCopyService::copyItem($admin, 'source', 'homework1', 'destination', null);
    $assert($item['item_id'] !== 'homework1', 'Copied item reused the source item ID.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_items WHERE course_id='destination'")->fetch_assoc()['n'] === 1, 'Single item was not copied.');
    $copiedAssignment = $admin->query("SELECT assignment_id,item_id,course_id FROM assignments WHERE course_id='destination'")->fetch_assoc();
    $assert($copiedAssignment && $copiedAssignment['assignment_id'] !== '42' && $copiedAssignment['item_id'] === $item['item_id'], 'Assignment definition was not independently copied.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM assignment_submissions")->fetch_assoc()['n'] === 1, 'Submission data changed.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM assignment_model_answer_access")->fetch_assoc()['n'] === 1, 'Student model-answer access was copied or changed.');

    $section = CourseContentCopyService::copySection($admin, 'source', 's1', 'destination');
    $assert($section['section_id'] !== 's1', 'Copied section reused the source section ID.');
    $assert(count($section['item_ids']) === 3, 'Section copy did not preserve all items.');
    $orders = $admin->query("SELECT page_order FROM course_items WHERE course_id='destination' AND section_id='" . $admin->real_escape_string($section['section_id']) . "' ORDER BY page_order ASC")->fetch_all(MYSQLI_ASSOC);
    $assert(array_column($orders, 'page_order') === ['1','2','3'], 'Section item order was not preserved.');
    $exam = $admin->query("SELECT status,scheduled_start_at_utc,attempt_generation,roster_finalized_at_utc FROM timed_exams WHERE course_id='destination' AND item_id='" . $admin->real_escape_string($section['item_ids'][2]) . "'")->fetch_assoc();
    $copiedExamItem = $admin->query("SELECT status FROM course_items WHERE course_id='destination' AND item_id='" . $admin->real_escape_string($section['item_ids'][2]) . "'")->fetch_assoc();
    $assert(($exam['status'] ?? '') === 'draft' && ($copiedExamItem['status'] ?? '') === 'draft' && ($exam['scheduled_start_at_utc'] ?? null) === null, 'Copied Timed Exam and its course item were not safely drafted.');
    $assert((int) ($exam['attempt_generation'] ?? 1) === 1 && ($exam['roster_finalized_at_utc'] ?? null) === null, 'Copied Timed Exam retained historical lifecycle state.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_items WHERE course_id='source'")->fetch_assoc()['n'] === 3, 'Source items changed.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_sections WHERE course_id='source'")->fetch_assoc()['n'] === 1, 'Source section changed.');

    try { CourseContentCopyService::copyItem($admin, 'source', 'lesson1', 'destination', 'missing'); $assert(false, 'Invalid destination section was accepted.'); } catch (Throwable $expected) {}
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM course_items WHERE course_id='destination'")->fetch_assoc()['n'] === 4, 'Failed copy left partial content behind.');
    echo "Course Content copy tests passed.\n";
} finally {
    $cleanup = mysqli_connect($dbHost, $dbUser, $dbPass);
    if ($cleanup instanceof mysqli) { $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`'); $cleanup->close(); }
}
