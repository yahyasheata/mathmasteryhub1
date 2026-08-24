<?php
declare(strict_types=1);

/**
 * Secure admin-account management services. Role changes are deliberately
 * limited to existing active users and are kept separate from profile data.
 */

if (!function_exists('mmh_admin_management_connection')) {
    function mmh_admin_management_connection(): ?mysqli
    {
        if (($GLOBALS['conn'] ?? null) instanceof mysqli) {
            return $GLOBALS['conn'];
        }
        static $loading = false;
        if ($loading) {
            return null;
        }
        $config = dirname(__DIR__) . '/connection/config.php';
        if (!is_file($config)) {
            return null;
        }
        $loading = true;
        require_once $config;
        $loading = false;
        return (($GLOBALS['conn'] ?? null) instanceof mysqli) ? $GLOBALS['conn'] : null;
    }
}

if (!function_exists('mmh_admin_management_has_archive_marker')) {
    function mmh_admin_management_has_archive_marker(mysqli $conn): bool
    {
        static $cached = [];
        $key = spl_object_id($conn);
        if (array_key_exists($key, $cached)) {
            return $cached[$key];
        }
        $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'archived_at' LIMIT 1");
        if (!$stmt) {
            return $cached[$key] = false;
        }
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $cached[$key] = $exists;
    }
}

if (!function_exists('mmh_admin_management_archive_clause')) {
    function mmh_admin_management_archive_clause(mysqli $conn, string $prefix = ''): string
    {
        $column = ($prefix !== '' ? $prefix . '.' : '') . 'archived_at';
        return mmh_admin_management_has_archive_marker($conn) ? $column . ' IS NULL' : '1=1';
    }
}

if (!function_exists('mmh_admin_management_user_columns')) {
    function mmh_admin_management_user_columns(mysqli $conn, string $prefix = ''): string
    {
        $p = $prefix !== '' ? $prefix . '.' : '';
        return $p . 'user_id, ' . $p . 'username, ' . $p . 'full_name, ' . $p . 'password, ' . $p . 'role, ' . $p . 'status, ' . (mmh_admin_management_has_archive_marker($conn) ? $p . 'archived_at' : 'NULL AS archived_at');
    }
}

if (!function_exists('mmh_admin_management_is_active_user')) {
    function mmh_admin_management_is_active_user(array $row): bool
    {
        return (string) ($row['status'] ?? '') === '1' && trim((string) ($row['archived_at'] ?? '')) === '';
    }
}

if (!function_exists('mmh_admin_management_is_active_admin')) {
    function mmh_admin_management_is_active_admin(array $row): bool
    {
        return strtolower((string) ($row['role'] ?? '')) === 'admin' && mmh_admin_management_is_active_user($row);
    }
}

if (!function_exists('mmh_admin_management_current_admin')) {
    function mmh_admin_management_current_admin(mysqli $conn, string $username): ?array
    {
        $sql = 'SELECT ' . mmh_admin_management_user_columns($conn) . ' FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('mmh_admin_management_list_admins')) {
    function mmh_admin_management_list_admins(mysqli $conn): array
    {
        $archive = mmh_admin_management_archive_clause($conn);
        $sql = 'SELECT ' . mmh_admin_management_user_columns($conn) . " FROM users WHERE role = 'admin' ORDER BY status DESC, full_name ASC, user_id ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['active_admin'] = mmh_admin_management_is_active_admin($row);
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_admin_management_search_users')) {
    function mmh_admin_management_search_users(mysqli $conn, string $search, int $limit = 25): array
    {
        $limit = max(1, min(50, $limit));
        $archive = mmh_admin_management_archive_clause($conn, 'u');
        $search = trim($search);
        $sql = 'SELECT u.user_id, u.username, u.full_name, u.status, ' . (mmh_admin_management_has_archive_marker($conn) ? 'u.archived_at' : 'NULL AS archived_at') . " FROM users u WHERE u.role = 'user' AND u.status = '1' AND {$archive}";
        if ($search !== '') {
            $sql .= ' AND (u.full_name LIKE CONCAT("%", ?, "%") OR u.username LIKE CONCAT("%", ?, "%"))';
        }
        $sql .= ' ORDER BY u.full_name ASC, u.username ASC, u.user_id ASC LIMIT ' . $limit;
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($search !== '') {
            $stmt->bind_param('ss', $search, $search);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_admin_management_verify_password')) {
    function mmh_admin_management_verify_password(string $provided, string $stored): bool
    {
        if (function_exists('mmh_auth_password_matches')) {
            return mmh_auth_password_matches($provided, $stored);
        }
        $info = password_get_info($stored);
        return (($info['algoName'] ?? 'unknown') !== 'unknown')
            ? password_verify($provided, $stored)
            : ($stored !== '' && hash_equals($stored, $provided));
    }
}

if (!function_exists('mmh_admin_management_change_role')) {
    /**
     * Promote or demote one existing user. Returns {ok, message, self_demotion}.
     */
    function mmh_admin_management_change_role(mysqli $conn, string $actorUsername, int $targetUserId, string $newRole, string $currentPassword): array
    {
        $newRole = strtolower(trim($newRole));
        if (!in_array($newRole, ['admin', 'user'], true) || $targetUserId <= 0 || $currentPassword === '') {
            return ['ok' => false, 'message' => 'The administrator change request is invalid.', 'self_demotion' => false];
        }

        $archive = mmh_admin_management_archive_clause($conn);
        $actor = null;
        $target = null;
        $selfDemotion = false;
        try {
            if (!$conn->begin_transaction()) {
                return ['ok' => false, 'message' => 'The administrator change could not be started.', 'self_demotion' => false];
            }

            $actorSql = 'SELECT ' . mmh_admin_management_user_columns($conn) . ' FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1 FOR UPDATE';
            $actorStmt = $conn->prepare($actorSql);
            if (!$actorStmt) {
                throw new RuntimeException('actor lookup failed');
            }
            $actorStmt->bind_param('s', $actorUsername);
            $actorStmt->execute();
            $actor = $actorStmt->get_result()->fetch_assoc() ?: null;
            $actorStmt->close();
            if (!is_array($actor) || !mmh_admin_management_is_active_admin($actor) || !mmh_admin_management_verify_password($currentPassword, (string) $actor['password'])) {
                throw new InvalidArgumentException('Your current administrator password is incorrect.');
            }

            $targetSql = 'SELECT ' . mmh_admin_management_user_columns($conn) . ' FROM users WHERE user_id = ? LIMIT 1 FOR UPDATE';
            $targetStmt = $conn->prepare($targetSql);
            if (!$targetStmt) {
                throw new RuntimeException('target lookup failed');
            }
            $targetStmt->bind_param('i', $targetUserId);
            $targetStmt->execute();
            $target = $targetStmt->get_result()->fetch_assoc() ?: null;
            $targetStmt->close();
            if (!is_array($target) || !mmh_admin_management_is_active_user($target)) {
                throw new InvalidArgumentException('Only active, non-archived users can be granted or removed administrator access.');
            }

            if ($newRole === 'admin') {
                $targetRole = strtolower((string) ($target['role'] ?? ''));
                if ($targetRole === 'admin') {
                    throw new InvalidArgumentException('This account is already an administrator.');
                }
                if ($targetRole !== 'user') {
                    throw new InvalidArgumentException('Only normal user accounts can be promoted to administrator.');
                }
            } else {
                if (strtolower((string) ($target['role'] ?? '')) !== 'admin') {
                    throw new InvalidArgumentException('This account is not an administrator.');
                }
                // Lock every eligible admin row before counting. This prevents
                // two concurrent demotions from removing the final admin.
                $admins = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND status = '1' AND {$archive} FOR UPDATE");
                if (!$admins) {
                    throw new RuntimeException('active admin check failed');
                }
                $adminCount = $admins->num_rows;
                if ($adminCount <= 1) {
                    throw new InvalidArgumentException('You cannot remove Admin access from the last active administrator.');
                }
                $selfDemotion = (int) $actor['user_id'] === (int) $target['user_id'];
            }

            $update = $conn->prepare('UPDATE users SET role = ? WHERE user_id = ? LIMIT 1');
            if (!$update) {
                throw new RuntimeException('role update failed');
            }
            $update->bind_param('si', $newRole, $targetUserId);
            if (!$update->execute() || $update->affected_rows !== 1) {
                $update->close();
                throw new RuntimeException('role update failed');
            }
            $update->close();

            $audit = $conn->prepare("INSERT INTO admin_security_audit (actor_user_id, target_user_id, action, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())");
            if (!$audit) {
                throw new RuntimeException('audit insert failed');
            }
            $action = $newRole === 'admin' ? 'ADMIN_PROMOTED' : 'ADMIN_DEMOTED';
            $actorId = (int) $actor['user_id'];
            $audit->bind_param('iis', $actorId, $targetUserId, $action);
            if (!$audit->execute()) {
                $audit->close();
                throw new RuntimeException('audit insert failed');
            }
            $audit->close();
            $conn->commit();

            return ['ok' => true, 'message' => $newRole === 'admin' ? 'Administrator access granted.' : 'Administrator access removed.', 'self_demotion' => $selfDemotion, 'target_username' => (string) ($target['username'] ?? '')];
        } catch (InvalidArgumentException $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => $e->getMessage(), 'self_demotion' => false];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Admin account change failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The administrator change could not be completed. No account data was changed.', 'self_demotion' => false];
        }
    }
}

if (!function_exists('mmh_admin_management_revoke_self_session')) {
    function mmh_admin_management_revoke_self_session(string $username): void
    {
        unset($_SESSION['admin'], $_SESSION['mmh_admin_csrf_token']);
        $_SESSION['username'] = $username;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
