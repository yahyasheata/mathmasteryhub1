<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
require_once $root . '/connection/config.php';
require_once $root . '/scripts/preflight_a_level_live_session_schema.php';
$admin = db();
$identity = $admin->query('SELECT @@hostname AS server_host, DATABASE() AS database_name')->fetch_assoc();
if (($identity['server_host'] ?? '') !== 'pcs-MacBook-Air-2.local' || ($identity['database_name'] ?? '') !== 'mathmsgv_lms') {
    throw new RuntimeException('Refusing schema preflight fixtures outside the verified local development database.');
}
$hostValue = (string) $host;
$userValue = (string) $user;
$passValue = (string) $pass;
$database = 'mmh_live_schema_preflight_' . getmypid() . '_' . bin2hex(random_bytes(4));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$run = static function (mysqli $conn, string $expectedDatabase): array {
    return mmh_a_level_live_schema_preflight($conn, $expectedDatabase);
};

try {
    if (!$admin->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8 COLLATE utf8_unicode_ci')) throw new RuntimeException($admin->error);
    if (!$admin->select_db($database)) throw new RuntimeException('Unable to select isolated preflight fixture database.');
    $assert(!$run($admin, $database)['ok'], 'Missing Live Session tables unexpectedly passed.');

    $admin->query("CREATE TABLE course_live_schedules (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, schedule_id VARCHAR(40) NOT NULL, course_id VARCHAR(40) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uniq_schedule_id (schedule_id), KEY idx_course_effective (course_id, enabled)) ENGINE=InnoDB");
    $admin->query("CREATE TABLE live_session_occurrences (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, occurrence_id VARCHAR(40) NOT NULL, schedule_id VARCHAR(40) NOT NULL, course_id VARCHAR(40) NOT NULL, scheduled_start_at DATETIME NOT NULL, UNIQUE KEY uniq_occurrence_id (occurrence_id), UNIQUE KEY uniq_schedule_start (schedule_id, scheduled_start_at), KEY idx_occurrence_course (course_id)) ENGINE=InnoDB");
    $preMigration = $run($admin, $database);
    $assert($preMigration['ok'] && !isset($preMigration['columns']['course_live_schedules.group_key']), 'Expected pre-migration schema did not pass.');
    $admin->query('ALTER TABLE course_live_schedules ADD COLUMN group_key VARCHAR(32) NULL DEFAULT NULL, ADD KEY idx_group_effective (group_key, enabled)');
    $compatible = $run($admin, $database);
    $assert($compatible['ok'], 'Already-migrated schema did not pass: ' . implode('; ', $compatible['errors']));
    $assert(($compatible['identity']['database_name'] ?? '') === $database, 'Preflight did not report the selected fixture database.');
    $wrongIdentity = $run($admin, 'wrong_database');
    $assert(!$wrongIdentity['ok'], 'Wrong application database identity unexpectedly passed.');

    $admin->query('ALTER TABLE live_session_occurrences MODIFY course_id INT NOT NULL');
    $assert(!$run($admin, $database)['ok'], 'Incompatible course_id type unexpectedly passed.');
    $admin->query('ALTER TABLE live_session_occurrences MODIFY course_id VARCHAR(40) NOT NULL');

    $admin->query('ALTER TABLE course_live_schedules MODIFY group_key VARCHAR(24) NULL');
    $assert(!$run($admin, $database)['ok'], 'Incompatible existing group_key unexpectedly passed.');
    $admin->query('ALTER TABLE course_live_schedules MODIFY group_key VARCHAR(32) NULL DEFAULT NULL');

    $admin->query('CREATE TABLE courses (course_id VARCHAR(40) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $admin->query('ALTER TABLE live_session_occurrences ADD CONSTRAINT fk_preflight_course FOREIGN KEY (course_id) REFERENCES courses(course_id)');
    $assert(!$run($admin, $database)['ok'], 'Unexpected foreign key on a column requiring nullability change unexpectedly passed.');
    $admin->query('ALTER TABLE live_session_occurrences DROP FOREIGN KEY fk_preflight_course');

    $admin->query('ALTER TABLE course_live_schedules DROP INDEX idx_group_effective, ADD UNIQUE KEY uniq_group_key (group_key)');
    $assert(!$run($admin, $database)['ok'], 'Unique group_key constraint unexpectedly passed.');
    $admin->query('ALTER TABLE course_live_schedules DROP INDEX uniq_group_key, ADD KEY idx_group_effective (group_key, enabled)');

    $admin->query('DROP TABLE live_session_occurrences');
    $assert(!$run($admin, $database)['ok'], 'Missing occurrence table unexpectedly passed.');

    $workflow = (string) file_get_contents($root . '/.github/workflows/deploy.yml');
    $preflightCall = strpos($workflow, 'MMH_PROJECT_ROOT="$PWD" php "$preflight_script"');
    $firstMigration = strpos($workflow, 'php database/migrations/');
    $codeReset = strpos($workflow, 'git reset --hard origin/main');
    $preflightSource = (string) file_get_contents($root . '/scripts/preflight_a_level_live_session_schema.php');
    $assert(strpos($workflow, 'set -e') !== false && strpos($workflow, 'git show origin/main:scripts/preflight_a_level_live_session_schema.php') !== false && $preflightCall !== false && $firstMigration !== false && $codeReset !== false && $preflightCall < $firstMigration && $firstMigration < $codeReset, 'A failed schema preflight could proceed to migration or application code reset.');
    $assert(strpos($preflightSource, 'DB_PASS') === false && strpos($workflow, 'set -x') === false, 'A database credential could be printed by the schema preflight workflow.');
    echo "Live Session schema preflight passes compatible fixtures and rejects missing tables, wrong identity, incompatible columns, and conflicting constraints.\n";
} finally {
    $cleanup = mysqli_connect($hostValue, $userValue, $passValue);
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_live_schema_preflight_[0-9]+_[a-f0-9]{8}\z/', $database)) {
        $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`');
        $cleanup->close();
    }
}
