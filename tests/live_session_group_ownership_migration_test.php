<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
require_once $root . '/connection/config.php';
$admin = db();
$identity = $admin->query('SELECT @@hostname AS server_host, DATABASE() AS database_name')->fetch_assoc();
if (($identity['server_host'] ?? '') !== 'pcs-MacBook-Air-2.local' || ($identity['database_name'] ?? '') !== 'mathmsgv_lms') {
    throw new RuntimeException('Refusing isolated migration test setup outside the verified local development database.');
}
$hostValue = (string) $host;
$userValue = (string) $user;
$passValue = (string) $pass;
$database = 'mmh_live_group_migration_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    if (!$admin->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8 COLLATE utf8_unicode_ci')) throw new RuntimeException($admin->error);
    if (!$admin->select_db($database)) throw new RuntimeException('Unable to select isolated migration database.');
    $admin->query("CREATE TABLE course_live_schedules (schedule_id VARCHAR(40) NOT NULL PRIMARY KEY, course_id VARCHAR(40) NOT NULL, deleted_at DATETIME NULL) ENGINE=InnoDB");
    $admin->query("CREATE TABLE live_session_occurrences (occurrence_id VARCHAR(40) NOT NULL PRIMARY KEY, schedule_id VARCHAR(40) NOT NULL, course_id VARCHAR(40) NOT NULL) ENGINE=InnoDB");
    $admin->query("INSERT INTO course_live_schedules (schedule_id,course_id) VALUES ('existing','course-1')");
    $conn = $admin;
    ob_start();
    require $root . '/database/migrations/20261018_add_a_level_live_schedule_ownership.php';
    require $root . '/database/migrations/20261018_add_a_level_live_schedule_ownership.php';
    ob_end_clean();
    foreach (['course_live_schedules', 'live_session_occurrences'] as $table) {
        $stmt = $admin->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'course_id'");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $nullable = $stmt->get_result()->fetch_assoc()['IS_NULLABLE'] ?? '';
        $stmt->close();
        $assert($nullable === 'YES', "{$table}.course_id did not become nullable.");
    }
    $groupColumn = $admin->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='course_live_schedules' AND COLUMN_NAME='group_key'")->fetch_row();
    $assert((bool) $groupColumn, 'Repeat-safe migration did not add the group ownership key.');
    $assert((int) $admin->query('SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_assoc()['n'] === 2, 'Migration created an unexpected table.');
    $existing = $admin->query("SELECT course_id,group_key FROM course_live_schedules WHERE schedule_id='existing'")->fetch_assoc();
    $assert(($existing['course_id'] ?? '') === 'course-1' && $existing['group_key'] === null, 'Existing Course-owned schedule data was changed by the migration.');
    $workflow = (string) file_get_contents($root . '/.github/workflows/deploy.yml');
    $preflightMigration = strpos($workflow, 'php database/migrations/20261018_add_a_level_live_schedule_ownership.php');
$schemaPreflight = strpos($workflow, 'MMH_PROJECT_ROOT="$PWD" php "$preflight_script"');
    $codeReset = strpos($workflow, 'git reset --hard origin/main');
$backupGuard = strpos($workflow, 'if [ ! -s "$backup_dir/tables.sql" ]');
$assert($backupGuard !== false && $schemaPreflight !== false && $preflightMigration !== false && $codeReset !== false && $backupGuard < $schemaPreflight && $schemaPreflight < $preflightMigration && $preflightMigration < $codeReset, 'The deployment workflow does not require a nonempty backup, then preflight and migrate before activating application code.');
    echo "A-Level Live Session ownership migration is repeat-safe and preserves existing Course schedules.\n";
} finally {
    $cleanup = mysqli_connect($hostValue, $userValue, $passValue);
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_live_group_migration_test_[0-9]+_[a-f0-9]{8}\z/', $database)) {
        $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`');
        $cleanup->close();
    }
}
