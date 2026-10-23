<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
require_once $root . '/connection/config.php';
require_once $root . '/inc/StudentCourseAccess.php';

$admin = db();
$identity = $admin->query('SELECT @@hostname AS host, DATABASE() AS database_name')->fetch_assoc();
if (($identity['host'] ?? '') !== 'pcs-MacBook-Air-2.local' || ($identity['database_name'] ?? '') !== 'mathmsgv_lms') {
    throw new RuntimeException('Refusing database-writing test outside the verified local development database.');
}

$testDatabase = 'mmh_student_active_item_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!preg_match('/\Ammh_student_active_item_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) {
    throw new RuntimeException('Unsafe isolated test database name.');
}
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$query = static function (mysqli $conn, string $sql): mysqli_result|bool {
    $result = $conn->query($sql);
    if ($result === false) throw new RuntimeException($conn->error ?: 'Test database query failed.');
    return $result;
};
$test = null;
$databaseCreated = false;

try {
    $query($admin, "CREATE DATABASE `{$testDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $databaseCreated = true;
    $test = mysqli_connect((string) $host, (string) $user, (string) $pass, $testDatabase);
    if (!$test) throw new RuntimeException('Unable to connect to isolated test database.');
    mysqli_set_charset($test, 'utf8mb4');
    $testIdentity = $test->query('SELECT @@hostname AS host, DATABASE() AS database_name')->fetch_assoc();
    $assert(($testIdentity['host'] ?? '') === 'pcs-MacBook-Air-2.local' && ($testIdentity['database_name'] ?? '') === $testDatabase, 'Isolated database identity verification failed.');

    $query($test, "CREATE TABLE users (user_id INT PRIMARY KEY, username VARCHAR(120) NOT NULL, role VARCHAR(16) NOT NULL, status VARCHAR(4) NOT NULL, archived_at DATETIME NULL) ENGINE=InnoDB");
    $query($test, "CREATE TABLE courses (id INT PRIMARY KEY, course_id VARCHAR(40) NOT NULL, course_title VARCHAR(190) NOT NULL, course_description TEXT NOT NULL, course_image TEXT NOT NULL, course_category INT NOT NULL, username VARCHAR(120) NOT NULL, sequential_learning TINYINT NOT NULL DEFAULT 0, course_status VARCHAR(8) NOT NULL DEFAULT '1', course_visibility VARCHAR(16) NOT NULL DEFAULT 'public', course_state VARCHAR(16) NOT NULL DEFAULT 'public', archived_at DATETIME NULL) ENGINE=InnoDB");
    $query($test, "CREATE TABLE categories (id INT PRIMARY KEY, category_title VARCHAR(190) NOT NULL) ENGINE=InnoDB");
    $query($test, "CREATE TABLE course_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id VARCHAR(50) NOT NULL, course_id INT NOT NULL, course_title VARCHAR(190) NOT NULL, purchase_date DATETIME NULL, unit_access VARCHAR(16) NULL) ENGINE=InnoDB");
    $query($test, "CREATE TABLE course_items (id INT AUTO_INCREMENT PRIMARY KEY, item_id VARCHAR(40) NOT NULL, item_title VARCHAR(190) NOT NULL, item_description TEXT NOT NULL, item_type VARCHAR(40) NOT NULL, section_id VARCHAR(40) NULL, template_type VARCHAR(40) NULL, template_data JSON NULL, assignment_id INT NULL, duration_minutes INT NULL, status VARCHAR(16) NULL, archived_at DATETIME NULL, course_id VARCHAR(40) NOT NULL, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB");

    $query($test, "INSERT INTO users (user_id, username, role, status) VALUES (981001, 'active-item.student@example.test', 'user', '1'), (981002, 'active-item.other@example.test', 'user', '1')");
    $query($test, "INSERT INTO categories (id, category_title) VALUES (981, 'Test Category')");
    $query($test, "INSERT INTO courses (id, course_id, course_title, course_description, course_image, course_category, username) VALUES (981, '981001', 'Active Item Test Course', '', '', 981, 'fixture')");
    $query($test, "INSERT INTO course_logs (user_id, course_id, course_title) VALUES ('981001', 981001, 'Active Item Test Course')");
    $query($test, "INSERT INTO course_items (item_id, item_title, item_description, item_type, template_type, template_data, status, archived_at, course_id, sort_order) VALUES ('981001', 'Published Recording', '', 'video', 'recording', '{\"url\":\"https://drive.google.com/file/d/ACTIVE_ITEM_TEST/view\"}', 'published', NULL, '981001', 1), ('981002', 'Archived Recording', '', 'video', 'recording', '{\"url\":\"https://drive.google.com/file/d/ARCHIVED_ITEM_TEST/view\"}', 'published', UTC_TIMESTAMP(), '981001', 2)");

    $activeSql = student_course_access_active_item_sql();
    $items = $test->query('SELECT item_id FROM course_items WHERE course_id = \'981001\' AND ' . $activeSql . ' ORDER BY sort_order');
    $assert($items instanceof mysqli_result && $items->num_rows === 1 && (string) $items->fetch_assoc()['item_id'] === '981001', 'DATETIME-safe active-item filter did not keep only the unarchived published item.');
    $assert(student_course_access_item($test, '981001', '981001') !== null, 'Authorized active Course Item retrieval failed.');
    $assert(student_course_access_item($test, '981001', '981002') === null, 'Archived Course Item remained available.');

    $studentId = student_course_access_student_id($test, 'active-item.student@example.test');
    $assert($studentId === 981001, 'Synthetic Student identity did not resolve.');
    $assert(student_course_access_authorized_course($test, $studentId, '981001') !== null, 'Authorized Student Course lookup failed.');
    $assert(!student_course_access_enrolled($test, 981002, '981001'), 'Unenrolled Student gained Course access.');
    $assert(student_course_access_unit_allowed('shared', null) && !student_course_access_unit_allowed('unit1', 'unit2'), 'Existing Section access restrictions changed.');

    $filter = student_course_access_active_item_sql();
    $coursesSql = "SELECT courses.id AS courseId, courses.course_id, courses.course_title, categories.category_title,
               COALESCE(course_item_counts.item_count, 0) AS item_count,
               COALESCE(homework_counts.homework_count, 0) AS homework_count, course_logs.*
        FROM course_logs
        INNER JOIN courses ON course_logs.course_id = courses.course_id
        LEFT JOIN categories ON courses.course_category = categories.id
        LEFT JOIN (SELECT course_id, COUNT(item_id) AS item_count FROM course_items WHERE {$filter} GROUP BY course_id) AS course_item_counts
            ON courses.course_id = course_item_counts.course_id
        LEFT JOIN (SELECT course_id, COUNT(item_id) AS homework_count FROM course_items WHERE ({$filter}) AND (template_type = 'classified_assignment' OR item_type IN ('assignment', 'homework', 'quiz')) GROUP BY course_id) AS homework_counts
            ON courses.course_id = homework_counts.course_id
        WHERE course_logs.user_id = '981001' AND courses.course_state IN ('public', 'private') AND courses.archived_at IS NULL
        ORDER BY course_logs.purchase_date DESC, courses.id ASC";
    $courses = $test->query($coursesSql);
    $assert($courses instanceof mysqli_result && $courses->num_rows === 1, 'My Courses query failed or omitted the enrolled public Course.');
    $course = $courses->fetch_assoc();
    $assert((int) ($course['item_count'] ?? 0) === 1, 'My Courses count included an archived item or omitted the active item.');

    echo "student_active_item_sql_database_test=PASS datetime_filter=PASS archive_exclusion=PASS my_courses_query=PASS student_authorization=PASS section_access=PASS\n";
} finally {
    if ($test instanceof mysqli) $test->close();
    $admin->select_db((string) ($identity['database_name'] ?? 'mathmsgv_lms'));
    if ($databaseCreated) $admin->query("DROP DATABASE `{$testDatabase}`");
}
