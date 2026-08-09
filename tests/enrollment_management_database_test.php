<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/EnrollmentService.php';

$admin = db();
$testDatabase = 'mmh_enrollment_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!preg_match('/\Ammh_enrollment_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) throw new RuntimeException('Unsafe test database name.');
$query = static function (mysqli $conn, string $sql): mysqli_result|bool { $result = $conn->query($sql); if ($result === false) throw new RuntimeException($conn->error ?: 'Database test query failed.'); return $result; };
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

try {
    $query($admin, 'CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($testDatabase), 'Unable to select isolated enrollment database.');
    $query($admin, "CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(190) NOT NULL, course_state VARCHAR(16) NOT NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE course_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL, course_title VARCHAR(190) NULL, purchase_date DATETIME NULL, KEY idx_enrollment(course_id,user_id))");
    $query($admin, "CREATE TABLE learning_events (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL)");
    $query($admin, "INSERT INTO courses VALUES ('source','Source Course','public',NULL),('target','Target Course','public',NULL),('private','Private Course','private',NULL),('draft','Draft Course','draft',NULL),('archived','Archived Course','private',NOW())");

    $insert = $admin->prepare("INSERT INTO course_logs (user_id,course_id,course_title,purchase_date) VALUES (?, ?, ?, NOW())");
    foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $userId) { $course = 'Source Course'; $source = 'source'; $insert->bind_param('iss', $userId, $source, $course); $insert->execute(); }
    $target = 'target'; $targetTitle = 'Target Course'; $targetUser = 2; $insert->bind_param('iss', $targetUser, $target, $targetTitle); $insert->execute();
    $insert->bind_param('iss', $targetUser, $target, $targetTitle); $insert->execute();
    $target = 'private'; $targetTitle = 'Private Course'; $targetUser = 8; $insert->bind_param('iss', $targetUser, $target, $targetTitle); $insert->execute();
    $insert->close();
    $query($admin, "INSERT INTO learning_events (user_id,course_id) VALUES (1,'source'),(4,'source')");

    $assert(mmh_enrollment_remove($admin, 1, 'source'), 'Single enrollment removal failed.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=1 AND course_id='source'")->fetch_assoc()['total'] === 0, 'Removed enrollment still exists.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM learning_events WHERE user_id=1 AND course_id='source'")->fetch_assoc()['total'] === 1, 'Historical learning event was deleted.');

    $assert(!mmh_enrollment_remove_batch($admin, [3, 999], 'source'), 'Partial removal batch should roll back.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=3 AND course_id='source'")->fetch_assoc()['total'] === 1, 'Failed removal batch changed data.');
    $assert(mmh_enrollment_remove_batch($admin, [3, 4], 'source'), 'Multi-student removal failed.');

    $assert(!mmh_enrollment_move($admin, 2, 'source', 'target'), 'Duplicate target enrollment was not rejected.');
    $assert(mmh_enrollment_move($admin, 5, 'source', 'private'), 'Move to private course failed.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=5 AND course_id='source'")->fetch_assoc()['total'] === 0, 'Source enrollment remained after move.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=5 AND course_id='private'")->fetch_assoc()['total'] === 1, 'Target enrollment was not created.');
    $assert(!mmh_enrollment_move($admin, 6, 'source', 'source'), 'Same-course move was not rejected.');
    $assert(!mmh_enrollment_move($admin, 6, 'source', 'draft'), 'Draft target course was accepted.');
    $assert(!mmh_enrollment_move($admin, 6, 'source', 'archived'), 'Archived target course was accepted.');

    $assert(!mmh_enrollment_move_batch($admin, [7, 8], 'source', 'private'), 'Failed move batch should roll back.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM course_logs WHERE user_id=7 AND course_id='source'")->fetch_assoc()['total'] === 1, 'Failed move batch changed the first student.');
    $assert(mmh_enrollment_move_batch($admin, [6, 7], 'source', 'private'), 'Successful move batch failed.');

    $handler = file_get_contents(dirname(__DIR__) . '/views/admin/requests/course-students.php');
    $index = file_get_contents(dirname(__DIR__) . '/index.php');
    $page = file_get_contents(dirname(__DIR__) . '/views/admin/course-students.php');
    $assert(is_string($handler) && str_contains($handler, 'mmh_enrollment_remove_batch') && str_contains($handler, 'mmh_enrollment_move_batch'), 'Admin handler does not use canonical enrollment services.');
    $assert(is_string($index) && str_contains($index, "mmh_admin_require_mutation()"), 'Admin enrollment route is not mutation-protected.');
    $assert(is_string($page) && str_contains($page, 'mmh_admin_csrf_token()'), 'Enrollment management form is missing admin CSRF.');
    echo "Enrollment management database checks passed.\n";
} finally {
    $cleanup = mysqli_connect((string) $host, (string) $user, (string) $pass);
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_enrollment_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) {
        $cleanup->query('DROP DATABASE `' . $testDatabase . '`');
        $cleanup->close();
    }
}
