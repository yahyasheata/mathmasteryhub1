<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
require_once dirname(__DIR__) . '/connection/config.php';

$admin = db();
$hostValue = (string) $host; $userValue = (string) $user; $passValue = (string) $pass;
$database = 'mmh_modular_migration_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!preg_match('/\Ammh_modular_migration_test_[0-9]+_[a-f0-9]{8}\z/', $database)) throw new RuntimeException('Unsafe test database name.');
$query = static function (mysqli $conn, string $sql): mysqli_result|bool { $result = $conn->query($sql); if ($result === false) throw new RuntimeException($conn->error ?: 'Migration test query failed.'); return $result; };
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
try {
    $query($admin, 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($database), 'Unable to select isolated migration database.');
    $query($admin, "CREATE TABLE courses (course_id VARCHAR(40) PRIMARY KEY, course_title VARCHAR(190) NOT NULL)");
    $query($admin, "INSERT INTO courses (course_id,course_title) VALUES ('3078','Math OL Full Course'),('7656057','Math OL May/June 2027'),('4359','Math OL Edexcel Course'),('ordinary','Ordinary Course')");
    $query($admin, "CREATE TABLE course_sections (id INT AUTO_INCREMENT PRIMARY KEY, section_id VARCHAR(40) NOT NULL, course_id VARCHAR(40) NOT NULL, title VARCHAR(190) NOT NULL)");
    $query($admin, "CREATE TABLE course_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, course_id VARCHAR(40) NOT NULL)");
    $query($admin, "INSERT INTO course_sections (section_id,course_id,title) VALUES ('s1','course','Existing Section')");
    $query($admin, "INSERT INTO course_logs (user_id,course_id) VALUES (17,'course')");

    require dirname(__DIR__) . '/database/migrations/20261017_add_modular_section_access.php';
    require dirname(__DIR__) . '/database/migrations/20261017_add_modular_section_access.php';

    $section = $admin->query("SELECT access_unit FROM course_sections WHERE section_id='s1'")->fetch_assoc();
    $enrollment = $admin->query("SELECT unit_access FROM course_logs WHERE user_id=17 AND course_id='course'")->fetch_assoc();
    $assert(($section['access_unit'] ?? null) === 'shared', 'Existing Sections did not receive the safe Shared default.');
    $assert(is_array($enrollment) && array_key_exists('unit_access', $enrollment) && $enrollment['unit_access'] === null, 'Existing enrollment was automatically classified.');
    $assert((int) $admin->query('SELECT COUNT(*) AS n FROM course_sections')->fetch_assoc()['n'] === 1, 'Migration changed existing Section rows.');
    $assert((int) $admin->query('SELECT COUNT(*) AS n FROM course_logs')->fetch_assoc()['n'] === 1, 'Migration changed existing enrollment rows.');

    $query($admin, "UPDATE course_sections SET access_unit='unit2' WHERE section_id='s1'");
    $query($admin, "UPDATE course_logs SET unit_access='unit1' WHERE user_id=17");
    require dirname(__DIR__) . '/database/migrations/20261019_add_course_unit_section_capability.php';
    $capabilities = $admin->query('SELECT course_id,supports_unit_section_access FROM courses ORDER BY course_id')->fetch_all(MYSQLI_ASSOC);
    $capabilities = array_column($capabilities, 'supports_unit_section_access', 'course_id');
    $assert((int) $capabilities['3078'] === 1 && (int) $capabilities['7656057'] === 1, 'Capability migration did not enable both designated Courses.');
    $assert((int) $capabilities['4359'] === 0 && (int) $capabilities['ordinary'] === 0, 'Capability migration enabled an unsupported Course.');
    $query($admin, "UPDATE courses SET supports_unit_section_access=0 WHERE course_id='7656057'");
    require dirname(__DIR__) . '/database/migrations/20261019_add_course_unit_section_capability.php';
    $assert((int) $admin->query("SELECT supports_unit_section_access FROM courses WHERE course_id='7656057'")->fetch_assoc()['supports_unit_section_access'] === 0, 'Repeat migration overwrote a later Admin capability choice.');
    $query($admin, "INSERT INTO courses (course_id,course_title) VALUES ('later','Later Course')");
    $assert((int) $admin->query("SELECT supports_unit_section_access FROM courses WHERE course_id='later'")->fetch_assoc()['supports_unit_section_access'] === 0, 'New Courses did not default to unsupported.');
    $assert((string) $admin->query("SELECT access_unit FROM course_sections WHERE section_id='s1'")->fetch_assoc()['access_unit'] === 'unit2', 'Capability migration changed a stored Section restriction.');
    $assert((string) $admin->query("SELECT unit_access FROM course_logs WHERE user_id=17")->fetch_assoc()['unit_access'] === 'unit1', 'Capability migration changed an existing enrollment classification.');
    echo "Modular Section Access migration is additive and repeat-safe.\n";
} finally {
    $cleanup = mysqli_connect($hostValue, $userValue, $passValue);
    if ($cleanup instanceof mysqli) { $cleanup->query('DROP DATABASE `' . $database . '`'); $cleanup->close(); }
}
