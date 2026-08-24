<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This test can only run from the command line.\n"); }

require_once dirname(__DIR__) . '/connection/config.php';
require_once dirname(__DIR__) . '/inc/Auth.php';
require_once dirname(__DIR__) . '/inc/AdminAccountManagement.php';
require_once dirname(__DIR__) . '/inc/AdminSecurity.php';

$admin = db();
$testDatabase = 'mmh_admin_accounts_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!preg_match('/\Ammh_admin_accounts_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) throw new RuntimeException('Unsafe test database name.');
$query = static function (mysqli $conn, string $sql): mysqli_result|bool { $result = $conn->query($sql); if ($result === false) throw new RuntimeException($conn->error ?: 'Database test query failed.'); return $result; };
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

try {
    $query($admin, 'CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert($admin->select_db($testDatabase), 'Unable to select isolated admin database.');
    $query($admin, "CREATE TABLE users (user_id INT PRIMARY KEY, username VARCHAR(190) NOT NULL, full_name VARCHAR(190) NULL, password VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(1) NOT NULL, archived_at DATETIME NULL)");
    $query($admin, "CREATE TABLE admin_security_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_user_id INT NOT NULL, target_user_id INT NOT NULL, action VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL)");
    $hashA = password_hash('admin-a-password', PASSWORD_DEFAULT);
    $hashB = password_hash('admin-b-password', PASSWORD_DEFAULT);
    $legacy = 'legacy-user-password';
    $insert = $admin->prepare('INSERT INTO users (user_id, username, full_name, password, role, status, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $rows = [
        [1, 'a@example.test', 'Admin A', $hashA, 'admin', '1', null],
        [2, 'b@example.test', 'Admin B', $hashB, 'user', '1', null],
        [3, 'inactive@example.test', 'Inactive', $legacy, 'user', '0', null],
        [4, 'archived@example.test', 'Archived', $legacy, 'user', '1', '2026-01-01 00:00:00'],
    ];
    foreach ($rows as [$id, $username, $name, $password, $role, $status, $archived]) { $insert->bind_param('issssss', $id, $username, $name, $password, $role, $status, $archived); $insert->execute(); }
    $insert->close();

    $promote = mmh_admin_management_change_role($admin, 'a@example.test', 2, 'admin', 'admin-a-password');
    $assert(!empty($promote['ok']), 'Active user promotion failed.');
    $assert((string) $admin->query('SELECT role FROM users WHERE user_id=2')->fetch_assoc()['role'] === 'admin', 'Promotion did not update only the role.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM admin_security_audit WHERE action='ADMIN_PROMOTED' AND actor_user_id=1 AND target_user_id=2")->fetch_assoc()['total'] === 1, 'Promotion audit row is missing.');

    $demote = mmh_admin_management_change_role($admin, 'b@example.test', 1, 'user', 'admin-b-password');
    $assert(!empty($demote['ok']), 'Second admin could not demote the original admin.');
    $assert((string) $admin->query('SELECT role FROM users WHERE user_id=1')->fetch_assoc()['role'] === 'user', 'Demotion did not update the target role.');
    $assert((int) $admin->query("SELECT COUNT(*) AS total FROM admin_security_audit WHERE action='ADMIN_DEMOTED' AND actor_user_id=2 AND target_user_id=1")->fetch_assoc()['total'] === 1, 'Demotion audit row is missing.');

    $lastAdmin = mmh_admin_management_change_role($admin, 'b@example.test', 2, 'user', 'admin-b-password');
    $assert(empty($lastAdmin['ok']) && str_contains((string) $lastAdmin['message'], 'last active administrator'), 'Last-admin demotion was not blocked.');
    $assert((string) $admin->query('SELECT role FROM users WHERE user_id=2')->fetch_assoc()['role'] === 'admin', 'Last-admin block changed the role.');

    $inactive = mmh_admin_management_change_role($admin, 'b@example.test', 3, 'admin', 'admin-b-password');
    $archived = mmh_admin_management_change_role($admin, 'b@example.test', 4, 'admin', 'admin-b-password');
    $assert(empty($inactive['ok']) && empty($archived['ok']), 'Inactive or archived target was accepted.');
    $wrongPassword = mmh_admin_management_change_role($admin, 'b@example.test', 3, 'admin', 'wrong');
    $assert(empty($wrongPassword['ok']), 'Incorrect current password was accepted.');

    // Restore two admins only for the self-demotion assertion, then verify the
    // service marks it so the handler can revoke the session immediately.
    $query($admin, "UPDATE users SET role='admin' WHERE user_id=1");
    $self = mmh_admin_management_change_role($admin, 'b@example.test', 2, 'user', 'admin-b-password');
    $assert(!empty($self['ok']) && !empty($self['self_demotion']), 'Self-demotion was not identified.');
    $assert((string) $admin->query('SELECT role FROM users WHERE user_id=2')->fetch_assoc()['role'] === 'user', 'Self-demotion did not update the role.');

    $GLOBALS['conn'] = $admin;
    $_SESSION = ['admin' => 'b@example.test'];
    $assert(mmh_admin_fresh_session_user() === null, 'A demoted admin session still appears current.');
    mmh_admin_revoke_stale_session();
    $assert(empty($_SESSION['admin']), 'Stale admin session was not revoked.');
    $_SESSION = ['admin' => 'a@example.test'];
    $assert(mmh_admin_fresh_session_user() !== null, 'An active admin session was rejected.');

    echo "Admin account management database checks passed.\n";
} finally {
    $cleanup = mysqli_connect((string) ($host ?? ''), (string) ($user ?? ''), (string) ($pass ?? ''));
    if ($cleanup instanceof mysqli && preg_match('/\Ammh_admin_accounts_test_[0-9]+_[a-f0-9]{8}\z/', $testDatabase)) { $cleanup->query('DROP DATABASE `' . $testDatabase . '`'); $cleanup->close(); }
}
