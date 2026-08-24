<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/TimedExam.php';

$dbHost = (string) $host; $dbUser = (string) $user; $dbPass = (string) $pass;
$database = 'mmh_timed_exam_reopen_' . getmypid() . '_' . bin2hex(random_bytes(4));
$admin = db();
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$query = static function (mysqli $conn, string $sql) use ($assert): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error ?: 'Database fixture query failed.'); };

try {
    $query($admin, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($database), 'Unable to select isolated reopen database.');
    $GLOBALS['conn'] = $admin;
    $query($admin, "CREATE TABLE courses (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40) NOT NULL, course_title VARCHAR(190) NOT NULL, course_description TEXT NULL, course_image VARCHAR(255) NULL, course_category VARCHAR(100) NULL, username VARCHAR(120) NULL, sequential_learning TINYINT NOT NULL DEFAULT 0, course_status VARCHAR(30) NULL, course_visibility VARCHAR(30) NULL, course_state VARCHAR(16) NOT NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE course_sections (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40) NOT NULL, section_id VARCHAR(40) NOT NULL, title VARCHAR(190) NOT NULL, sort_order INT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'published')");
    $query($admin, "CREATE TABLE course_items (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40) NOT NULL, item_id VARCHAR(40) NOT NULL, item_title VARCHAR(190) NOT NULL, item_description TEXT NULL, section_id VARCHAR(40) NULL, item_type VARCHAR(40) NOT NULL DEFAULT 'timed_exam', template_type VARCHAR(40) NULL, template_data TEXT NULL, assignment_id VARCHAR(40) NULL, duration_minutes INT NULL, status VARCHAR(16) NOT NULL DEFAULT 'published', archived_at DATETIME NULL, UNIQUE KEY uq_item(course_id,item_id))");
    $query($admin, "CREATE TABLE users (user_id INT PRIMARY KEY, username VARCHAR(120) NOT NULL, full_name VARCHAR(190) NOT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE course_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL, purchase_date DATETIME NULL)");
    $query($admin, "CREATE TABLE timed_exams (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40) NOT NULL, item_id VARCHAR(40) NOT NULL, title VARCHAR(190) NOT NULL, instructions TEXT NULL, status VARCHAR(16) NOT NULL DEFAULT 'draft', timing_mode VARCHAR(24) NOT NULL DEFAULT 'fixed_window', scheduled_start_at_utc DATETIME NULL, duration_minutes INT NOT NULL DEFAULT 60, grace_minutes INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 1, allowed_answer_types VARCHAR(255) NOT NULL DEFAULT 'pdf', max_file_size_bytes BIGINT NOT NULL DEFAULT 10485760, paper_source VARCHAR(24) NOT NULL DEFAULT 'external_link', paper_external_url VARCHAR(1000) NOT NULL, paper_external_preview_url VARCHAR(1000) NULL, paper_external_download_url VARCHAR(1000) NULL, paper_fallback_instructions TEXT NULL, paper_storage_key VARCHAR(255) NULL, paper_original_name VARCHAR(255) NULL, paper_mime VARCHAR(120) NULL, paper_size_bytes BIGINT NULL, paper_view_allowed TINYINT NOT NULL DEFAULT 1, paper_download_allowed TINYINT NOT NULL DEFAULT 1, late_submission_allowed TINYINT NOT NULL DEFAULT 0, expiry_policy VARCHAR(32) NOT NULL DEFAULT 'auto_submit_latest', max_marks DECIMAL(10,2) NULL, results_release_at_utc DATETIME NULL, recovery_window_start_at_utc DATETIME NULL, recovery_window_end_at_utc DATETIME NULL, recovery_allowed TINYINT NOT NULL DEFAULT 0, attempt_generation INT UNSIGNED NOT NULL DEFAULT 1, roster_finalized_at_utc DATETIME NULL, roster_finalized_generation INT UNSIGNED NULL, deleted_at DATETIME NULL, created_by INT NULL, updated_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $query($admin, "CREATE TABLE timed_exam_attempts (id INT AUTO_INCREMENT PRIMARY KEY, timed_exam_id INT NOT NULL, student_id INT NOT NULL, attempt_number INT NOT NULL, attempt_scope VARCHAR(80) NOT NULL, active_key VARCHAR(64) NULL, state VARCHAR(24) NOT NULL, opens_at_utc DATETIME NOT NULL, closes_at_utc DATETIME NOT NULL, grace_closes_at_utc DATETIME NOT NULL, started_at_utc DATETIME NULL, submitted_at_utc DATETIME NULL, expired_at_utc DATETIME NULL, latest_version_id INT NULL, is_late TINYINT NOT NULL DEFAULT 0, grade DECIMAL(10,2) NULL, feedback TEXT NULL, results_released_at_utc DATETIME NULL, UNIQUE KEY uq_scope(timed_exam_id,student_id,attempt_scope), UNIQUE KEY uq_number(timed_exam_id,student_id,attempt_number))");
    $query($admin, "CREATE TABLE timed_exam_submission_versions (id INT AUTO_INCREMENT PRIMARY KEY, attempt_id INT NOT NULL, version_number INT NOT NULL, original_filename VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size_bytes BIGINT NOT NULL, sha256 CHAR(64) NULL, status VARCHAR(24) NOT NULL, is_late TINYINT NOT NULL DEFAULT 0, uploaded_at_utc DATETIME NOT NULL, submitted_at_utc DATETIME NULL)");
    $query($admin, "CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(190) NOT NULL, message TEXT NOT NULL, status TINYINT NOT NULL DEFAULT 0)");

    $query($admin, "INSERT INTO courses (course_id,course_title,course_state) VALUES ('reopen-course','Reopen Course','private')");
    $query($admin, "INSERT INTO course_sections (course_id,section_id,title,sort_order) VALUES ('reopen-course','section-1','Section 1',1)");
    $query($admin, "INSERT INTO course_items (course_id,item_id,item_title,item_description,section_id,item_type,template_type,status) VALUES ('reopen-course','exam-item','Exam Item','Instructions','section-1','timed_exam','timed_exam','published')");
    for ($student = 1; $student <= 3; $student++) {
        $query($admin, "INSERT INTO users (user_id,username,full_name,role,status) VALUES ({$student},'student{$student}@example.test','Student {$student}','user','1')");
        $query($admin, "INSERT INTO course_logs (user_id,course_id) VALUES ({$student},'reopen-course')");
    }
    $oldStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-3 hours')->format('Y-m-d H:i:s');
    $insert = $admin->prepare("INSERT INTO timed_exams (course_id,item_id,title,instructions,status,timing_mode,scheduled_start_at_utc,duration_minutes,grace_minutes,max_attempts,allowed_answer_types,max_file_size_bytes,paper_source,paper_external_url,late_submission_allowed,expiry_policy,recovery_allowed,attempt_generation,roster_finalized_at_utc,roster_finalized_generation) VALUES ('reopen-course','exam-item','Reopen Exam','Instructions','published','fixed_window',?,60,0,1,'pdf',10485760,'external_link','https://drive.google.com/file/d/example/view',0,'auto_submit_latest',0,1,?,1)");
    $marker = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 hours')->format('Y-m-d H:i:s');
    $insert->bind_param('ss', $oldStart, $marker); $assert($insert->execute(), 'Unable to seed historical exam.'); $examId = (int) $insert->insert_id; $insert->close();
    $states = ['no_submission','submitted','graded'];
    foreach ($states as $index => $state) {
        $student = $index + 1; $attemptNumber = 1; $opens = $oldStart; $closes = (new DateTimeImmutable($oldStart, new DateTimeZone('UTC')))->modify('+60 minutes')->format('Y-m-d H:i:s');
        $stmt = $admin->prepare('INSERT INTO timed_exam_attempts (timed_exam_id,student_id,attempt_number,attempt_scope,state,opens_at_utc,closes_at_utc,grace_closes_at_utc,submitted_at_utc) VALUES (?,?,?,?,?,?,?,?,?)');
        $submitted = $state === 'no_submission' ? null : $closes;
        $scope = 'primary';
        $stmt->bind_param('iiissssss', $examId, $student, $attemptNumber, $scope, $state, $opens, $closes, $closes, $submitted); $assert($stmt->execute(), 'Unable to seed historical attempt.'); $stmt->close();
    }

    $newStart = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->modify('-5 minutes')->format('Y-m-d\TH:i');
    $savedId = mmh_timed_exam_save_config($admin, 'reopen-course', 'exam-item', [
        'title' => 'Reopen Exam', 'instructions' => 'Updated', 'status' => 'published', 'scheduled_start_at' => $newStart,
        'duration_minutes' => 60, 'grace_minutes' => 0, 'max_attempts' => 1, 'allowed_answer_types' => 'pdf', 'max_file_size_bytes' => 10485760,
        'paper_external_url' => 'https://drive.google.com/file/d/example/view', 'paper_view_allowed' => 1, 'paper_download_allowed' => 1,
        'late_submission_allowed' => 0, 'max_marks' => 100, 'recovery_allowed' => 0,
    ], null, 99);
    $assert($savedId === $examId, 'Schedule edit created a duplicate exam.');
    $exam = mmh_timed_exam_load($admin, 'reopen-course', $examId, true);
    $assert(($exam['attempt_generation'] ?? 0) === 2, 'Editing a window with history did not create a new attempt generation.');
    $assert(($exam['roster_finalized_generation'] ?? 0) === 1 && !empty($exam['roster_finalized_at_utc']), 'Historical roster marker was not preserved.');

    for ($student = 1; $student <= 3; $student++) {
        $context = mmh_timed_exam_student_context($admin, $exam, $student);
        $assert(($context['state']['key'] ?? '') === 'open', 'Reopened exam did not become open for student ' . $student . '.');
        $assert(($context['attempt']['attempt_scope'] ?? '') === 'primary:2', 'Reopened exam did not use the new primary scope for student ' . $student . '.');
    }
    $states = mmh_timed_exam_course_states($admin, 1, 'reopen-course');
    $currentAttemptId = (int) ($admin->query("SELECT id FROM timed_exam_attempts WHERE timed_exam_id={$examId} AND student_id=1 AND attempt_scope='primary:2'")->fetch_assoc()['id'] ?? 0);
    $assert(($states['exam-item']['state_key'] ?? '') === 'open' && (int)($states['exam-item']['attempt_id'] ?? 0) === $currentAttemptId, 'Course progress state did not resolve the active generation.');
    $oldCount = (int) ($admin->query("SELECT COUNT(*) AS total FROM timed_exam_attempts WHERE timed_exam_id={$examId} AND attempt_scope='primary'")->fetch_assoc()['total'] ?? 0);
    $newCount = (int) ($admin->query("SELECT COUNT(*) AS total FROM timed_exam_attempts WHERE timed_exam_id={$examId} AND attempt_scope='primary:2'")->fetch_assoc()['total'] ?? 0);
    $assert($oldCount === 3 && $newCount === 3, 'Historical attempts were deleted or new attempts were not isolated by generation.');
    $assert((string) ($admin->query("SELECT state FROM timed_exam_attempts WHERE timed_exam_id={$examId} AND student_id=1 AND attempt_scope='primary'")->fetch_assoc()['state'] ?? '') === 'no_submission', 'Historical no-submission state changed.');
    $assert((string) ($admin->query("SELECT state FROM timed_exam_attempts WHERE timed_exam_id={$examId} AND student_id=3 AND attempt_scope='primary'")->fetch_assoc()['state'] ?? '') === 'graded', 'Historical graded state changed.');
    echo "Timed Exam reopen generation tests passed.\n";
} finally {
    $cleanup = mysqli_connect($dbHost, $dbUser, $dbPass);
    if ($cleanup instanceof mysqli) { $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`'); $cleanup->close(); }
}
