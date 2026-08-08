<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/connection/config.php';

$dbHost = (string) $host;
$dbUser = (string) $user;
$dbPass = (string) $pass;
$database = 'mmh_timed_exam_migration_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!preg_match('/\Ammh_timed_exam_migration_[0-9]+_[a-f0-9]{8}\z/', $database)) throw new RuntimeException('Unsafe migration test database name.');
$admin = db();

$runMigration = static function () use ($dbHost, $dbUser, $dbPass, $database): array {
    $environment = array_merge($_ENV, [
        'DB_HOST' => $dbHost,
        'DB_USER' => $dbUser,
        'DB_PASS' => $dbPass,
        'DB_NAME' => $database,
    ]);
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/database/migrations/20260811_finalize_timed_exam_lifecycle.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment
    );
    if (!is_resource($process)) throw new RuntimeException('Unable to start lifecycle migration test.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
};

try {
    if (!$admin->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) throw new RuntimeException($admin->error);
    if (!$admin->select_db($database)) throw new RuntimeException('Unable to select migration test database.');
    if (!$admin->query("CREATE TABLE timed_exams (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, status VARCHAR(16) NOT NULL, scheduled_start_at_utc DATETIME NULL, recovery_allowed TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB")) throw new RuntimeException($admin->error);
    if (!$admin->query("CREATE TABLE timed_exam_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, timed_exam_id BIGINT UNSIGNED NOT NULL, student_id INT NOT NULL, attempt_number INT UNSIGNED NOT NULL, state VARCHAR(24) NOT NULL, UNIQUE KEY uq_number(timed_exam_id,student_id,attempt_number)) ENGINE=InnoDB")) throw new RuntimeException($admin->error);
    $admin->query("INSERT INTO timed_exams (status,scheduled_start_at_utc,recovery_allowed) VALUES ('published','2026-08-01 10:00:00',0)");
    $admin->query("INSERT INTO timed_exam_attempts (timed_exam_id,student_id,attempt_number,state) VALUES (1,42,1,'submitted'),(1,42,2,'graded')");

    [$firstCode, $firstOut, $firstError] = $runMigration();
    [$secondCode, $secondOut, $secondError] = $runMigration();
    if ($firstCode !== 0 || $secondCode !== 0) throw new RuntimeException('Lifecycle migration failed: ' . $firstError . $secondError);
    if (!str_contains($firstOut, 'Historical attempts were preserved') || !str_contains($secondOut, 'Historical attempts were preserved')) throw new RuntimeException('Lifecycle migration did not report successful idempotent completion.');

    $columns = $admin->query("SELECT TABLE_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='timed_exam_attempts' AND COLUMN_NAME='attempt_scope') OR (TABLE_NAME='timed_exams' AND COLUMN_NAME='roster_finalized_at_utc'))");
    if (!$columns || $columns->num_rows !== 2) throw new RuntimeException('Lifecycle migration columns are missing.');
    $index = $admin->query("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='timed_exam_attempts' AND INDEX_NAME='uq_timed_exam_attempt_scope' LIMIT 1");
    if (!$index || $index->num_rows !== 1) throw new RuntimeException('Attempt-scope uniqueness index is missing.');
    $rows = $admin->query('SELECT id,state,attempt_scope FROM timed_exam_attempts ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    if (count($rows) !== 2 || ($rows[0]['state'] ?? '') !== 'submitted' || ($rows[1]['state'] ?? '') !== 'graded' || ($rows[0]['attempt_scope'] ?? '') !== 'legacy:1' || ($rows[1]['attempt_scope'] ?? '') !== 'primary') {
        throw new RuntimeException('Lifecycle migration did not preserve historical attempt states and latest-attempt compatibility.');
    }

    echo "Timed Exam lifecycle migration idempotency tests passed.\n";
} finally {
    $cleanup = mysqli_connect($dbHost, $dbUser, $dbPass);
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_timed_exam_migration_[0-9]+_[a-f0-9]{8}\z/', $database)) {
        $cleanup->query('DROP DATABASE IF EXISTS `' . $database . '`');
        $cleanup->close();
    }
}
