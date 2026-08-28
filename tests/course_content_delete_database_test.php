<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/AdminCourseService.php';

$admin = db();
$hostName = (string) ($host ?? ''); $userName = (string) ($user ?? ''); $password = (string) ($pass ?? '');
$database = 'mmh_course_delete_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$query = static function (mysqli $conn, string $sql) use ($assert): void { if (!$conn->query($sql)) throw new RuntimeException($conn->error ?: 'Database query failed.'); };
try {
    $query($admin, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($database), 'Unable to select isolated deletion database.');
    $query($admin, "CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(180) NOT NULL)");
    $query($admin, "CREATE TABLE course_items (id INT AUTO_INCREMENT PRIMARY KEY, item_id VARCHAR(40) NOT NULL, course_id VARCHAR(40) NOT NULL, item_title VARCHAR(180) NOT NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE assignments (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(40) NOT NULL, course_id VARCHAR(40) NOT NULL, item_id VARCHAR(40) NOT NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE assignment_submissions (id INT AUTO_INCREMENT PRIMARY KEY, assignment_id VARCHAR(40) NOT NULL, student_id INT NOT NULL)");
    $query($admin, "CREATE TABLE timed_exams (id INT AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(40) NOT NULL, item_id VARCHAR(40) NOT NULL, status VARCHAR(16) NOT NULL, deleted_at DATETIME NULL)");
    $query($admin, "CREATE TABLE timed_exam_attempts (id INT AUTO_INCREMENT PRIMARY KEY, timed_exam_id INT NOT NULL, student_id INT NOT NULL)");
    $query($admin, "INSERT INTO courses VALUES ('course-a','Course A'),('course-b','Course B')");
    $query($admin, "INSERT INTO course_items (item_id,course_id,item_title) VALUES ('unused','course-a','Unused item'),('homework','course-a','Homework item'),('foreign','course-b','Foreign item')");
    $query($admin, "INSERT INTO assignments (assignment_id,course_id,item_id) VALUES ('assignment-1','course-a','homework')");
    $query($admin, "INSERT INTO assignment_submissions (assignment_id,student_id) VALUES ('assignment-1',17)");
    $query($admin, "INSERT INTO timed_exams (course_id,item_id,status) VALUES ('course-a','homework','published')");
    $examId = (int) $admin->insert_id;
    $query($admin, "INSERT INTO timed_exam_attempts (timed_exam_id,student_id) VALUES ($examId,17)");

    mmh_admin_course_archive_item($admin, 'unused', 'course-a');
    $unused = $admin->query("SELECT archived_at FROM course_items WHERE item_id='unused'")->fetch_assoc();
    $assert(!empty($unused['archived_at']), 'Unused item was not removed from active content.');

    mmh_admin_course_archive_item($admin, 'homework', 'course-a');
    $homework = $admin->query("SELECT archived_at FROM course_items WHERE item_id='homework'")->fetch_assoc();
    $exam = $admin->query("SELECT status,deleted_at FROM timed_exams WHERE item_id='homework'")->fetch_assoc();
    $assert(!empty($homework['archived_at']), 'Historical item was not archived.');
    $assert(($exam['status'] ?? '') === 'archived' && !empty($exam['deleted_at']), 'Timed Exam was not archived with the item.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM assignment_submissions")->fetch_assoc()['n'] === 1, 'Homework submission was deleted.');
    $assert((int) $admin->query("SELECT COUNT(*) AS n FROM timed_exam_attempts")->fetch_assoc()['n'] === 1, 'Timed Exam attempt was deleted.');

    $foreignBlocked = false;
    try { mmh_admin_course_archive_item($admin, 'foreign', 'course-a'); } catch (Throwable $e) { $foreignBlocked = true; }
    $assert($foreignBlocked, 'Wrong-course item was accepted.');
    $foreign = $admin->query("SELECT archived_at FROM course_items WHERE item_id='foreign'")->fetch_assoc();
    $assert(empty($foreign['archived_at']), 'Wrong-course item was changed.');

    echo "course_content_delete_database=unused_archive=PASS history_preserved=PASS timed_exam_preserved=PASS wrong_course_denied=PASS\n";
} finally {
    $cleanup = mysqli_connect($hostName, $userName, $password);
    if ($cleanup instanceof mysqli && preg_match('/\\Ammh_course_delete_test_[0-9]+_[a-f0-9]{8}\\z/', $database)) { $cleanup->query('DROP DATABASE `' . $database . '`'); $cleanup->close(); }
}
