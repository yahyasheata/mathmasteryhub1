<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';

$admin = db();
$hostValue = (string) $host; $userValue = (string) $user; $passValue = (string) $pass;
$database = 'mmh_course_group_migration_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$query = static function (mysqli $conn, string $sql): mysqli_result|bool {
    $result = $conn->query($sql);
    if ($result === false) throw new RuntimeException($conn->error ?: 'Migration test query failed.');
    return $result;
};
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

try {
    $query($admin, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($database), 'Unable to select isolated migration database.');
    $query($admin, 'CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(190) NOT NULL, course_category INT NOT NULL)');
    $query($admin, "INSERT INTO courses (course_id, course_title, course_category) VALUES ('cam-p1','Cambridge Pure 1',7)");

    require dirname(__DIR__) . '/database/migrations/20261018_add_my_courses_group_key.php';
    require dirname(__DIR__) . '/database/migrations/20261018_add_my_courses_group_key.php';

    $column = $admin->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'my_courses_group_key'")->fetch_assoc();
    $row = $admin->query("SELECT my_courses_group_key, course_category FROM courses WHERE course_id='cam-p1'")->fetch_assoc();
    $assert(($column['COLUMN_TYPE'] ?? '') === 'varchar(32)' && ($column['IS_NULLABLE'] ?? '') === 'YES' && ($column['COLUMN_DEFAULT'] ?? null) === null, 'Migration did not create the nullable VARCHAR(32) default-null column.');
    $assert(($row['my_courses_group_key'] ?? null) === null && (int) ($row['course_category'] ?? 0) === 7, 'Migration classified an existing Course or changed its Category.');
    $assert((int) $admin->query('SELECT COUNT(*) AS n FROM courses')->fetch_assoc()['n'] === 1, 'Migration changed the existing Course count.');
    $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy.yml');
    $preflightMigration = strpos($workflow, 'php database/migrations/20261018_add_my_courses_group_key.php');
    $codeReset = strpos($workflow, 'git reset --hard origin/main');
    $assert($preflightMigration !== false && $codeReset !== false && $preflightMigration < $codeReset, 'Deployment does not apply the new column migration before activating the new application code.');
    echo "Course Grouping migration is additive, repeat-safe, and leaves existing Courses ungrouped.\n";
} finally {
    $cleanup = mysqli_connect($hostValue, $userValue, $passValue);
    if ($cleanup instanceof mysqli) { $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`'); $cleanup->close(); }
}
