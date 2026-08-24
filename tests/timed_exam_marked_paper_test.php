<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/inc/TimedExam.php';
$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = file_get_contents($root . '/database/migrations/20260824_create_timed_exam_marked_papers.php');
$assert(is_string($migration) && str_contains($migration, 'timed_exam_marked_papers') && str_contains($migration, 'uq_timed_exam_marked_attempt'), 'Marked-paper migration is incomplete.');
$handler = file_get_contents($root . '/views/admin/requests/grade-timed-exam.php');
$assert(is_string($handler) && str_contains($handler, 'mmh_admin_require_mutation') && str_contains($handler, 'mmh_timed_exam_save_marking'), 'Admin marking handler is not using the canonical guarded service.');
$studentRoute = file_get_contents($root . '/views/user/requests/open-timed-exam-marked.php');
$assert(is_string($studentRoute) && str_contains($studentRoute, 'mmh_timed_exam_marked_paper_for_student') && str_contains($studentRoute, 'student_course_access_enrolled'), 'Student marked-paper route is missing ownership/enrollment checks.');
$studentView = file_get_contents($root . '/views/user/timed-exam.php');
$assert(is_string($studentView) && str_contains($studentView, 'results_released_at_utc') && str_contains($studentView, 'marked-paper'), 'Student result view does not gate the marked paper behind release.');
$index = file_get_contents($root . '/index.php');
$assert(is_string($index) && str_contains($index, '/timed-exam-marked/{attemptId}') && str_contains($index, '/marked-paper/{attemptId}'), 'Protected marked-paper routes are not registered.');
$assert(mmh_timed_exam_marked_paper_store_path('../storage/private/timed-exams/marked/x.pdf') === null, 'Traversal path was accepted.');

require_once $root . '/connection/config.php';
$dbHost = (string) $host;
$dbUser = (string) $user;
$dbPass = (string) $pass;
$admin = db();
$dbName = 'mmh_marked_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$assert((bool) preg_match('/\Ammh_marked_test_[0-9]+_[a-f0-9]{8}\z/', $dbName), 'Unsafe test database name.');
$query = static function (mysqli $conn, string $sql): void {
    if (!$conn->query($sql)) throw new RuntimeException($conn->error ?: 'Database query failed.');
};
try {
    $query($admin, 'CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($dbName), 'Could not select isolated database.');
    $query($admin, "CREATE TABLE timed_exams (id INT PRIMARY KEY, max_marks DECIMAL(10,2) NULL)");
    $query($admin, "CREATE TABLE timed_exam_attempts (id INT PRIMARY KEY, timed_exam_id INT NOT NULL, student_id INT NOT NULL, state VARCHAR(24) NOT NULL, grade DECIMAL(10,2) NULL, feedback TEXT NULL, results_released_at_utc DATETIME NULL)");
    $query($admin, "CREATE TABLE timed_exam_marked_papers (id INT AUTO_INCREMENT PRIMARY KEY, attempt_id INT NOT NULL UNIQUE, original_filename VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size_bytes BIGINT UNSIGNED NOT NULL, uploaded_by INT NULL, uploaded_at_utc DATETIME NOT NULL, updated_at_utc DATETIME NOT NULL)");
    $query($admin, "INSERT INTO timed_exams (id,max_marks) VALUES (7,70)");
    $query($admin, "INSERT INTO timed_exam_attempts (id,timed_exam_id,student_id,state) VALUES (11,7,101,'submitted')");
    $saved = mmh_timed_exam_save_marking($admin, ['id' => 7, 'max_marks' => 70], 11, 54.0, 'Revise vectors.');
    $assert(!empty($saved['success']), 'Canonical marking save failed.');
    $row = $admin->query('SELECT state,grade,feedback FROM timed_exam_attempts WHERE id=11')->fetch_assoc() ?: [];
    $assert(($row['state'] ?? '') === 'graded' && (float) ($row['grade'] ?? 0) === 54.0 && ($row['feedback'] ?? '') === 'Revise vectors.', 'Score/report were not persisted on the existing attempt.');
    $query($admin, "INSERT INTO timed_exam_marked_papers (attempt_id,original_filename,storage_key,mime_type,file_size_bytes,uploaded_at_utc,updated_at_utc) VALUES (11,'marked.pdf','storage/private/timed-exams/marked/test.pdf','application/pdf',12,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $assert(mmh_timed_exam_marked_paper_for_student($admin, 11, 7, 101) === null, 'Unreleased marked paper was accessible.');
    $query($admin, "UPDATE timed_exam_attempts SET results_released_at_utc=UTC_TIMESTAMP() WHERE id=11");
    $assert(mmh_timed_exam_marked_paper_for_student($admin, 11, 7, 101) !== null, 'Released marked paper was not accessible to its owner.');
    $assert(mmh_timed_exam_marked_paper_for_student($admin, 11, 7, 102) === null, 'Marked paper IDOR protection failed for another student.');
    echo "Timed Exam marked-paper tests passed.\n";
} finally {
    $admin->select_db((string) $dbName);
    $admin->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
}
