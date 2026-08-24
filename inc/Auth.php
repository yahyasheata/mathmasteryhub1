<?php
/**
 * Small shared authentication helpers. These preserve the existing session
 * keys and JSON response shape while centralizing request safety.
 */

if (!function_exists('mmh_auth_csrf_token')) {
    function mmh_auth_csrf_token(): string
    {
        if (empty($_SESSION['mmh_auth_csrf_token'])) {
            $_SESSION['mmh_auth_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['mmh_auth_csrf_token'];
    }
}

if (!function_exists('mmh_auth_csrf_valid')) {
    function mmh_auth_csrf_valid($token): bool
    {
        $storedToken = (string) ($_SESSION['mmh_auth_csrf_token'] ?? '');
        return is_string($token) && $storedToken !== '' && hash_equals($storedToken, $token);
    }
}

if (!function_exists('mmh_auth_json')) {
    function mmh_auth_json(bool $success, string $message, array $extra = [], int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'success' => $success,
            'status' => $success ? 1 : 0,
            'message' => $message,
        ], $extra));
        exit;
    }
}

if (!function_exists('mmh_auth_normalize_username')) {
    function mmh_auth_normalize_username(string $username): string
    {
        $username = trim($username);
        return filter_var($username, FILTER_VALIDATE_EMAIL) ? strtolower($username) : $username;
    }
}

if (!function_exists('mmh_auth_valid_username')) {
    function mmh_auth_valid_username(string $username): bool
    {
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        return (bool) preg_match('/^\\+?[0-9][0-9\\s()\\-]{6,24}$/', $username);
    }
}

if (!function_exists('mmh_auth_string_length')) {
    function mmh_auth_string_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}

if (!function_exists('mmh_auth_regenerate_session')) {
    function mmh_auth_regenerate_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            // A session-id rotation must never carry an old administrator
            // mutation token into the new authenticated session.
            unset($_SESSION['mmh_admin_csrf_token']);
        }
    }
}

if (!function_exists('mmh_auth_password_matches')) {
    /** Verify modern hashes and legacy plaintext values during migration. */
    function mmh_auth_password_matches(string $providedPassword, string $storedPassword): bool
    {
        if ($storedPassword === '') {
            return false;
        }
        $passwordInfo = password_get_info($storedPassword);
        if (($passwordInfo['algoName'] ?? 'unknown') !== 'unknown') {
            return password_verify($providedPassword, $storedPassword);
        }
        return hash_equals($storedPassword, $providedPassword);
    }
}

if (!function_exists('mmh_auth_logout')) {
    function mmh_auth_logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $cookie['path'] ?? '/',
                $cookie['domain'] ?? '',
                (bool) ($cookie['secure'] ?? false),
                (bool) ($cookie['httponly'] ?? true)
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

if (!function_exists('mmh_auth_flash')) {
    function mmh_auth_flash(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $_SESSION['mmh_auth_flash'][$key] = $message;
            return null;
        }

        $value = (string) ($_SESSION['mmh_auth_flash'][$key] ?? '');
        unset($_SESSION['mmh_auth_flash'][$key]);
        return $value;
    }
}

if (!function_exists('mmh_auth_first_name')) {
    function mmh_auth_first_name($fullName, string $fallback = 'there'): string
    {
        $fullName = trim((string) $fullName);
        if ($fullName === '') {
            return $fallback;
        }

        $parts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        return !empty($parts[0]) ? (string) $parts[0] : $fallback;
    }
}

if (!function_exists('mmh_auth_user_id')) {
    function mmh_auth_user_id(mysqli $conn, string $username): ?int
    {
        $stmt = $conn->prepare('SELECT user_id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null;
    }
}

if (!function_exists('mmh_auth_has_active_enrollment')) {
    function mmh_auth_has_active_enrollment(mysqli $conn, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        // course_logs is the project's existing enrollment source. It has no
        // active/expired status fields, so a matching row is the canonical rule.
        $stmt = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $enrolled = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $enrolled;
    }
}

if (!function_exists('mmh_auth_destination')) {
    function mmh_auth_destination(mysqli $conn, string $username, string $role, string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($role === 'admin') {
            return $baseUrl . '/admin/dashboard';
        }

        $userId = mmh_auth_user_id($conn, $username);
        return $userId !== null && mmh_auth_has_active_enrollment($conn, $userId)
            ? $baseUrl . '/user/my-courses'
            : ($baseUrl !== '' ? $baseUrl . '/' : '/');
    }
}
