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
    echo "Modular Section Access migration is additive and repeat-safe.\n";
} finally {
    $cleanup = mysqli_connect($hostValue, $userValue, $passValue);
    if ($cleanup instanceof mysqli) { $cleanup->query('DROP DATABASE `' . $database . '`'); $cleanup->close(); }
}
