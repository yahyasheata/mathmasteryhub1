<?php
declare(strict_types=1);

/**
 * Password-reset primitives shared by the public forgot/reset routes.
 * Raw tokens exist only in request memory and the outbound reset URL.
 */

if (!function_exists('mmh_password_reset_no_store_headers')) {
    function mmh_password_reset_no_store_headers(): void
    {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Cookie', false);
        header('Referrer-Policy: no-referrer');
    }
}

if (!function_exists('mmh_password_reset_generic_message')) {
    function mmh_password_reset_generic_message(): string
    {
        return 'If an account exists for this email, we sent a password reset link.';
    }
}

if (!function_exists('mmh_password_reset_token')) {
    function mmh_password_reset_token(): string
    {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('mmh_password_reset_token_hash')) {
    function mmh_password_reset_token_hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

if (!function_exists('mmh_password_reset_identifier_hash')) {
    function mmh_password_reset_identifier_hash(string $identifier): string
    {
        return hash('sha256', strtolower(trim($identifier)));
    }
}

if (!function_exists('mmh_password_reset_ip_hash')) {
    function mmh_password_reset_ip_hash(string $ip): string
    {
        return hash('sha256', trim($ip));
    }
}

if (!function_exists('mmh_password_reset_valid_token')) {
    function mmh_password_reset_valid_token(string $token): bool
    {
        return (bool) preg_match('/\A[a-f0-9]{64}\z/', $token);
    }
}

if (!function_exists('mmh_password_reset_env')) {
    function mmh_password_reset_env(string $key): string
    {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('mmh_password_reset_public_url')) {
    function mmh_password_reset_public_url(string $token): ?string
    {
        $base = rtrim(mmh_password_reset_env('APP_PUBLIC_URL'), '/');
        $parts = $base !== '' ? parse_url($base) : false;
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }
        return $base . '/auth/reset-password?token=' . rawurlencode($token);
    }
}

if (!function_exists('mmh_password_reset_rate_limited')) {
    function mmh_password_reset_rate_limited(mysqli $conn, string $identifierHash, string $ipHash): bool
    {
        $emailCount = 0;
        $ipCount = 0;
        $email = $conn->prepare("SELECT COUNT(*) AS total FROM password_reset_rate_limits WHERE identifier_hash = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)");
        if ($email) {
            $email->bind_param('s', $identifierHash);
            $email->execute();
            $emailCount = (int) (($email->get_result()->fetch_assoc()['total'] ?? 0));
            $email->close();
        } else {
            return true;
        }
        $ip = $conn->prepare("SELECT COUNT(*) AS total FROM password_reset_rate_limits WHERE ip_hash = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)");
        if ($ip) {
            $ip->bind_param('s', $ipHash);
            $ip->execute();
            $ipCount = (int) (($ip->get_result()->fetch_assoc()['total'] ?? 0));
            $ip->close();
        } else {
            return true;
        }
        return $emailCount >= 5 || $ipCount >= 10;
    }
}

if (!function_exists('mmh_password_reset_record_rate_event')) {
    function mmh_password_reset_record_rate_event(mysqli $conn, string $identifierHash, string $ipHash): void
    {
        $cleanup = $conn->query("DELETE FROM password_reset_rate_limits WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)");
        unset($cleanup);
        $stmt = $conn->prepare('INSERT INTO password_reset_rate_limits (identifier_hash, ip_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())');
        if ($stmt) {
            $stmt->bind_param('ss', $identifierHash, $ipHash);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('mmh_password_reset_find_eligible_user')) {
    function mmh_password_reset_find_eligible_user(mysqli $conn, string $email): ?array
    {
        $stmt = $conn->prepare("SELECT user_id, username FROM users WHERE LOWER(username) = LOWER(?) AND status = '1' AND archived_at IS NULL LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !filter_var((string) ($row['username'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $row;
    }
}

if (!function_exists('mmh_password_reset_issue')) {
    function mmh_password_reset_issue(mysqli $conn, string $email, string $ip, string $userAgent): void
    {
        $identifierHash = mmh_password_reset_identifier_hash($email);
        $ipHash = mmh_password_reset_ip_hash($ip);
        try {
            $limited = mmh_password_reset_rate_limited($conn, $identifierHash, $ipHash);
            mmh_password_reset_record_rate_event($conn, $identifierHash, $ipHash);
            if ($limited || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $user = mmh_password_reset_find_eligible_user($conn, $email);
            if (!$user) {
                return;
            }
            $rawToken = mmh_password_reset_token();
            $tokenHash = mmh_password_reset_token_hash($rawToken);
            $userAgentHash = hash('sha256', $userAgent);

            $conn->begin_transaction();
            $invalidate = $conn->prepare("UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP(), delivery_status = 'invalidated' WHERE user_id = ? AND used_at IS NULL");
            if (!$invalidate) {
                throw new RuntimeException('Unable to prepare reset token invalidation.');
            }
            $userId = (int) $user['user_id'];
            $invalidate->bind_param('i', $userId);
            if (!$invalidate->execute()) {
                $invalidate->close();
                throw new RuntimeException('Unable to invalidate reset tokens.');
            }
            $invalidate->close();

            $insert = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, requested_ip, requested_user_agent_hash, delivery_status) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 MINUTE), ?, ?, 'pending')");
            if (!$insert) {
                throw new RuntimeException('Unable to prepare reset token creation.');
            }
            $insert->bind_param('isss', $userId, $tokenHash, $ip, $userAgentHash);
            if (!$insert->execute()) {
                $insert->close();
                throw new RuntimeException('Unable to create reset token.');
            }
            $insert->close();
            $conn->commit();

            $resetUrl = mmh_password_reset_public_url($rawToken);
            $delivery = $resetUrl !== null
                ? mmh_password_reset_send_resend((string) $user['username'], $resetUrl)
                : [false, 'missing_public_url'];
            $status = $delivery[0] ? 'sent' : 'failed';
            $errorCode = $delivery[0] ? null : (string) ($delivery[1] ?? 'delivery_failed');
            $update = $conn->prepare('UPDATE password_reset_tokens SET delivery_status = ?, delivery_error_code = ?, used_at = CASE WHEN ? = \'failed\' THEN UTC_TIMESTAMP() ELSE used_at END WHERE token_hash = ? AND used_at IS NULL LIMIT 1');
            if ($update) {
                $update->bind_param('ssss', $status, $errorCode, $status, $tokenHash);
                $update->execute();
                $update->close();
            }
        } catch (Throwable $exception) {
            if ($conn->errno || $conn->thread_id) {
                try { $conn->rollback(); } catch (Throwable $ignored) { unset($ignored); }
            }
            error_log('Password reset request could not be completed: reset_unavailable');
        }
    }
}

if (!function_exists('mmh_password_reset_send_resend')) {
    function mmh_password_reset_send_resend(string $recipient, string $resetUrl): array
    {
        $apiKey = mmh_password_reset_env('RESEND_API_KEY');
        $fromEmail = mmh_password_reset_env('MAIL_FROM_EMAIL');
        $fromName = mmh_password_reset_env('MAIL_FROM_NAME') ?: 'Math Mastery Hub';
        if ($apiKey === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return [false, 'mailer_not_configured'];
        }
        if (!function_exists('curl_init')) {
            return [false, 'curl_unavailable'];
        }
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#17202a;line-height:1.6"><h1>Math Mastery Hub</h1><h2>Reset your password</h2><p>We received a request to reset the password for your Math Mastery Hub account.</p><p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 20px;background:#f97316;color:#fff;text-decoration:none;border-radius:6px">Reset Password</a></p><p>This link expires in 60 minutes.</p><p>If you did not request this, you can safely ignore this email.</p></body></html>';
        $text = "Math Mastery Hub\n\nReset your password\n\nWe received a request to reset the password for your Math Mastery Hub account.\n\nReset Password: {$resetUrl}\n\nThis link expires in 60 minutes.\n\nIf you did not request this, you can safely ignore this email.";
        $payload = json_encode([
            'from' => $fromName . ' <' . $fromEmail . '>',
            'to' => [$recipient],
            'subject' => 'Reset your Math Mastery Hub password',
            'html' => $html,
            'text' => $text,
        ], JSON_UNESCAPED_SLASHES);
        $curl = curl_init('https://api.resend.com/emails');
        if ($curl === false) {
            return [false, 'curl_init_failed'];
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($status >= 200 && $status < 300) {
            return [true, ''];
        }
        unset($body, $error);
        return [false, $status > 0 ? 'resend_http_' . $status : 'resend_transport_error'];
    }
}

if (!function_exists('mmh_password_reset_lookup')) {
    function mmh_password_reset_lookup(mysqli $conn, string $rawToken): ?array
    {
        if (!mmh_password_reset_valid_token($rawToken)) {
            return null;
        }
        $hash = mmh_password_reset_token_hash($rawToken);
        $stmt = $conn->prepare("SELECT t.id, t.user_id, t.expires_at FROM password_reset_tokens t INNER JOIN users u ON u.user_id = t.user_id WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > UTC_TIMESTAMP() AND u.status = '1' AND u.archived_at IS NULL LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_password_reset_apply')) {
    function mmh_password_reset_apply(mysqli $conn, string $rawToken, string $newPassword): bool
    {
        if (!mmh_password_reset_valid_token($rawToken) || strlen($newPassword) < 8 || strlen($newPassword) > 190) {
            return false;
        }
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            return false;
        }
        $hash = mmh_password_reset_token_hash($rawToken);
        try {
            $conn->begin_transaction();
            $lookup = $conn->prepare("SELECT t.id, t.user_id FROM password_reset_tokens t INNER JOIN users u ON u.user_id = t.user_id WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > UTC_TIMESTAMP() AND u.status = '1' AND u.archived_at IS NULL LIMIT 1 FOR UPDATE");
            if (!$lookup) throw new RuntimeException('Reset token lookup failed.');
            $lookup->bind_param('s', $hash);
            $lookup->execute();
            $token = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if (!$token) {
                $conn->rollback();
                return false;
            }
            $userId = (int) $token['user_id'];
            $updateUser = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ? LIMIT 1');
            if (!$updateUser) throw new RuntimeException('Password update failed.');
            $updateUser->bind_param('si', $passwordHash, $userId);
            if (!$updateUser->execute()) { $updateUser->close(); throw new RuntimeException('Password update failed.'); }
            $updateUser->close();
            $used = $conn->prepare("UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP(), delivery_status = 'used' WHERE id = ? AND used_at IS NULL LIMIT 1");
            if (!$used) throw new RuntimeException('Reset token update failed.');
            $tokenId = (int) $token['id'];
            $used->bind_param('i', $tokenId);
            if (!$used->execute()) { $used->close(); throw new RuntimeException('Reset token update failed.'); }
            $used->close();
            $invalidate = $conn->prepare("UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP(), delivery_status = 'invalidated' WHERE user_id = ? AND id <> ? AND used_at IS NULL");
            if ($invalidate) {
                $invalidate->bind_param('ii', $userId, $tokenId);
                $invalidate->execute();
                $invalidate->close();
            }
            $conn->commit();
            return true;
        } catch (Throwable $exception) {
            try { $conn->rollback(); } catch (Throwable $ignored) { unset($ignored); }
            error_log('Password reset apply failed: reset_update_failed');
            return false;
        }
    }
}
