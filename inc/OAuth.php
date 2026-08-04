<?php
require_once __DIR__ . '/SchemaMigration.php';
/**
 * Minimal OIDC support for Google and Sign in with Apple.
 * Secrets are read from environment variables only and never from source files.
 */

require_once __DIR__ . '/Auth.php';

if (!function_exists('mmh_oauth_env')) {
    function mmh_oauth_env(string $key): string
    {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
        }
        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('mmh_oauth_provider_config')) {
    function mmh_oauth_provider_config(string $provider): ?array
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'google') {
            return [
                'provider' => 'google',
                'client_id' => mmh_oauth_env('MMH_GOOGLE_CLIENT_ID'),
                'client_secret' => mmh_oauth_env('MMH_GOOGLE_CLIENT_SECRET'),
                'redirect_uri' => mmh_oauth_env('MMH_GOOGLE_REDIRECT_URI'),
                'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_endpoint' => 'https://oauth2.googleapis.com/token',
                'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
                'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
            ];
        }

        if ($provider === 'apple') {
            return [
                'provider' => 'apple',
                'client_id' => mmh_oauth_env('MMH_APPLE_SERVICE_ID'),
                'team_id' => mmh_oauth_env('MMH_APPLE_TEAM_ID'),
                'key_id' => mmh_oauth_env('MMH_APPLE_KEY_ID'),
                'private_key_path' => mmh_oauth_env('MMH_APPLE_PRIVATE_KEY_PATH'),
                'redirect_uri' => mmh_oauth_env('MMH_APPLE_REDIRECT_URI'),
                'authorization_endpoint' => 'https://appleid.apple.com/auth/authorize',
                'token_endpoint' => 'https://appleid.apple.com/auth/token',
                'jwks_uri' => 'https://appleid.apple.com/auth/keys',
                'issuers' => ['https://appleid.apple.com'],
            ];
        }

        return null;
    }
}

if (!function_exists('mmh_oauth_provider_available')) {
    function mmh_oauth_provider_available(string $provider): bool
    {
        $config = mmh_oauth_provider_config($provider);
        if (!$config || $config['client_id'] === '' || $config['redirect_uri'] === '') {
            return false;
        }

        if ($provider === 'google') {
            return $config['client_secret'] !== '';
        }

        if ($provider === 'apple') {
            return $config['team_id'] !== '' && $config['key_id'] !== ''
                && $config['private_key_path'] !== '' && is_file($config['private_key_path'])
                && is_readable($config['private_key_path']);
        }

        return false;
    }
}

if (!function_exists('mmh_oauth_b64url_encode')) {
    function mmh_oauth_b64url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('mmh_oauth_b64url_decode')) {
    function mmh_oauth_b64url_decode(string $value): ?string
    {
        if ($value === '' || !preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}

if (!function_exists('mmh_oauth_asn1')) {
    function mmh_oauth_asn1(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 128) {
            $lengthBytes = chr($length);
        } else {
            $bytes = ltrim(pack('N', $length), "\0");
            $lengthBytes = chr(0x80 | strlen($bytes)) . $bytes;
        }
        return chr($tag) . $lengthBytes . $value;
    }
}

if (!function_exists('mmh_oauth_asn1_integer')) {
    function mmh_oauth_asn1_integer(string $value): string
    {
        $value = ltrim($value, "\0");
        if ($value === '' || (ord($value[0]) & 0x80)) {
            $value = "\0" . $value;
        }
        return mmh_oauth_asn1(0x02, $value);
    }
}

if (!function_exists('mmh_oauth_jwk_pem')) {
    function mmh_oauth_jwk_pem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }
        $n = mmh_oauth_b64url_decode((string) $jwk['n']);
        $e = mmh_oauth_b64url_decode((string) $jwk['e']);
        if ($n === null || $e === null) {
            return null;
        }

        $rsa = mmh_oauth_asn1(0x30, mmh_oauth_asn1_integer($n) . mmh_oauth_asn1_integer($e));
        $algorithm = mmh_oauth_asn1(0x30, "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01" . "\x05\x00");
        $subjectKeyInfo = mmh_oauth_asn1(0x30, $algorithm . mmh_oauth_asn1(0x03, "\0" . $rsa));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($subjectKeyInfo), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}

if (!function_exists('mmh_oauth_http')) {
    function mmh_oauth_http(string $url, ?array $postFields = null): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return [0, '', 'Unable to initialize the provider request.'];
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        if ($postFields !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields, '', '&', PHP_QUERY_RFC3986));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return [$status, is_string($body) ? $body : '', $error];
    }
}

if (!function_exists('mmh_oauth_fetch_jwks')) {
    function mmh_oauth_fetch_jwks(string $jwksUri): ?array
    {
        [$status, $body] = mmh_oauth_http($jwksUri);
        if ($status !== 200) {
            return null;
        }
        $json = json_decode($body, true);
        return is_array($json) && isset($json['keys']) && is_array($json['keys']) ? $json['keys'] : null;
    }
}

if (!function_exists('mmh_oauth_validate_id_token')) {
    function mmh_oauth_validate_id_token(string $idToken, array $config, string $expectedNonce): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $headerRaw = mmh_oauth_b64url_decode($headerPart);
        $payloadRaw = mmh_oauth_b64url_decode($payloadPart);
        $signature = mmh_oauth_b64url_decode($signaturePart);
        if ($headerRaw === null || $payloadRaw === null || $signature === null) {
            return null;
        }
        $header = json_decode($headerRaw, true);
        $claims = json_decode($payloadRaw, true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return null;
        }

        $keys = mmh_oauth_fetch_jwks((string) $config['jwks_uri']);
        if ($keys === null) {
            return null;
        }
        $key = null;
        foreach ($keys as $candidate) {
            if (is_array($candidate) && hash_equals((string) $header['kid'], (string) ($candidate['kid'] ?? ''))) {
                $key = $candidate;
                break;
            }
        }
        $pem = $key ? mmh_oauth_jwk_pem($key) : null;
        if ($pem === null || openssl_verify($headerPart . '.' . $payloadPart, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $now = time();
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [($claims['aud'] ?? '')];
        if (!in_array($config['client_id'], $audiences, true)
            || !in_array((string) ($claims['iss'] ?? ''), $config['issuers'], true)
            || empty($claims['sub'])
            || !isset($claims['exp']) || (int) $claims['exp'] < ($now - 60)
            || (isset($claims['iat']) && (int) $claims['iat'] > ($now + 300))
            || !isset($claims['nonce']) || !hash_equals($expectedNonce, (string) $claims['nonce'])) {
            return null;
        }
        if (count($audiences) > 1 && !hash_equals($config['client_id'], (string) ($claims['azp'] ?? ''))) {
            return null;
        }

        return $claims;
    }
}

if (!function_exists('mmh_oauth_ecdsa_der_to_jose')) {
    function mmh_oauth_ecdsa_der_to_jose(string $der, int $partLength = 32): ?string
    {
        if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
            return null;
        }
        $offset = 1;
        $lengthByte = ord($der[$offset++]);
        if ($lengthByte & 0x80) {
            $count = $lengthByte & 0x7F;
            if ($count < 1 || $count > 2 || strlen($der) < $offset + $count) return null;
            $offset += $count;
        }
        if (($der[$offset++] ?? '') !== "\x02") return null;
        $rLength = ord($der[$offset++]);
        $r = substr($der, $offset, $rLength); $offset += $rLength;
        if (($der[$offset++] ?? '') !== "\x02") return null;
        $sLength = ord($der[$offset++]);
        $s = substr($der, $offset, $sLength);
        if (strlen($r) !== $rLength || strlen($s) !== $sLength) return null;
        $r = str_pad(ltrim($r, "\0"), $partLength, "\0", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\0"), $partLength, "\0", STR_PAD_LEFT);
        return strlen($r) === $partLength && strlen($s) === $partLength ? $r . $s : null;
    }
}

if (!function_exists('mmh_oauth_apple_client_secret')) {
    function mmh_oauth_apple_client_secret(array $config): ?string
    {
        $privateKey = @file_get_contents((string) $config['private_key_path']);
        if (!is_string($privateKey) || $privateKey === '') {
            return null;
        }
        $header = mmh_oauth_b64url_encode(json_encode(['alg' => 'ES256', 'kid' => $config['key_id'], 'typ' => 'JWT']));
        $now = time();
        $payload = mmh_oauth_b64url_encode(json_encode([
            'iss' => $config['team_id'], 'iat' => $now, 'exp' => $now + 300,
            'aud' => 'https://appleid.apple.com', 'sub' => $config['client_id'],
        ]));
        $input = $header . '.' . $payload;
        $der = '';
        if (!openssl_sign($input, $der, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $signature = mmh_oauth_ecdsa_der_to_jose($der);
        return $signature === null ? null : $input . '.' . mmh_oauth_b64url_encode($signature);
    }
}

if (!function_exists('mmh_oauth_ensure_schema')) {
    function mmh_oauth_ensure_schema(mysqli $conn): bool
    {
        if (!mmh_schema_mutations_allowed()) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_oauth_identities'");
            if (!$stmt) return false;
            $stmt->execute(); $row = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        }
        $sql = "CREATE TABLE IF NOT EXISTS `user_oauth_identities` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `provider` VARCHAR(32) NOT NULL,
            `provider_subject` VARCHAR(255) NOT NULL,
            `provider_email` VARCHAR(250) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_provider_subject` (`provider`, `provider_subject`),
            UNIQUE KEY `uniq_user_provider` (`user_id`, `provider`),
            KEY `idx_oauth_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        return (bool) $conn->query($sql);
    }
}

if (!function_exists('mmh_oauth_next_user_id')) {
    function mmh_oauth_next_user_id(mysqli $conn): ?int
    {
        $lookup = $conn->prepare('SELECT id FROM users WHERE user_id = ? LIMIT 1');
        if (!$lookup) return null;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = random_int(100000, 99999999);
            $lookup->bind_param('i', $candidate);
            $lookup->execute();
            if (!$lookup->get_result()->fetch_assoc()) {
                $lookup->close();
                return $candidate;
            }
        }
        $lookup->close();
        return null;
    }
}

if (!function_exists('mmh_oauth_identity_user')) {
    function mmh_oauth_identity_user(mysqli $conn, string $provider, string $subject): ?array
    {
        $stmt = $conn->prepare('SELECT u.user_id, u.username, u.role, u.status FROM user_oauth_identities oi INNER JOIN users u ON u.user_id = oi.user_id WHERE oi.provider = ? AND oi.provider_subject = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('ss', $provider, $subject);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_oauth_link_or_create_user')) {
    function mmh_oauth_link_or_create_user(mysqli $conn, string $provider, array $claims, string $displayName = ''): array
    {
        if (!mmh_oauth_ensure_schema($conn)) {
            return [false, 'Social sign-in is temporarily unavailable.', null];
        }
        $subject = trim((string) ($claims['sub'] ?? ''));
        $email = mmh_auth_normalize_username((string) ($claims['email'] ?? ''));
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($subject === '' || (!$emailVerified && $email !== '')) {
            return [false, 'Your provider account email could not be verified.', null];
        }

        $existingIdentity = mmh_oauth_identity_user($conn, $provider, $subject);
        if ($existingIdentity) {
            return (string) $existingIdentity['status'] === '1'
                ? [true, '', $existingIdentity]
                : [false, 'This account is not currently active.', null];
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Your provider did not return a verified email address. Please use password sign-in or try again.', null];
        }

        try {
            $conn->begin_transaction();
            $lookup = $conn->prepare('SELECT user_id, username, role, status FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
            if (!$lookup) throw new RuntimeException('Unable to prepare account lookup.');
            $lookup->bind_param('s', $email);
            $lookup->execute();
            $user = $lookup->get_result()->fetch_assoc();
            $lookup->close();

            if ($user && (string) $user['role'] !== 'user') {
                throw new RuntimeException('Use password sign-in for this account.');
            }
            if ($user && (string) $user['status'] !== '1') {
                throw new RuntimeException('This account is not currently active.');
            }

            if (!$user) {
                $userId = mmh_oauth_next_user_id($conn);
                if ($userId === null) throw new RuntimeException('Unable to allocate account identifier.');
                $displayName = trim($displayName);
                if ($displayName === '') $displayName = trim((string) ($claims['name'] ?? ''));
                if ($displayName === '') $displayName = 'Math Mastery Hub Student';
                $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $insert = $conn->prepare("INSERT INTO users (user_id, full_name, username, guardian_number, password, governorate, gender, role, status) VALUES (?, ?, ?, NULL, ?, NULL, NULL, 'user', '1')");
                if (!$insert) throw new RuntimeException('Unable to prepare account creation.');
                $insert->bind_param('isss', $userId, $displayName, $email, $randomPassword);
                if (!$insert->execute()) throw new RuntimeException('Unable to create account.');
                $insert->close();
                $user = ['user_id' => $userId, 'username' => $email, 'role' => 'user', 'status' => '1'];

                $title = 'Welcome to Math Mastery Hub';
                $message = 'Your account is ready. You can start learning now.';
                $notification = $conn->prepare('INSERT INTO notifications (user_id, title, message, status) VALUES (?, ?, ?, 0)');
                if ($notification) {
                    $notification->bind_param('iss', $userId, $title, $message);
                    if (!$notification->execute()) throw new RuntimeException('Unable to create welcome notification.');
                    $notification->close();
                }
            }

            $userId = (int) $user['user_id'];
            $identity = $conn->prepare('INSERT INTO user_oauth_identities (user_id, provider, provider_subject, provider_email) VALUES (?, ?, ?, ?)');
            if (!$identity) throw new RuntimeException('Unable to prepare identity link.');
            $identity->bind_param('isss', $userId, $provider, $subject, $email);
            if (!$identity->execute()) throw new RuntimeException('Unable to link provider identity.');
            $identity->close();
            $conn->commit();
            return [true, '', $user];
        } catch (Throwable $error) {
            $conn->rollback();
            $linked = mmh_oauth_identity_user($conn, $provider, $subject);
            if ($linked && (string) $linked['status'] === '1') {
                return [true, '', $linked];
            }
            $message = $error->getMessage() === 'Use password sign-in for this account.' || $error->getMessage() === 'This account is not currently active.'
                ? $error->getMessage() : 'We could not complete social sign-in. Please try again.';
            return [false, $message, null];
        }
    }
}

if (!function_exists('mmh_oauth_start')) {
    function mmh_oauth_start(string $provider, string $baseUrl = ''): void
    {
        if (!in_array($provider, ['google', 'apple'], true) || !mmh_oauth_provider_available($provider)) {
            mmh_auth_flash('error', 'That sign-in provider is not configured yet.');
            header('Location: ' . rtrim($baseUrl, '/') . '/auth/login'); exit;
        }
        $config = mmh_oauth_provider_config($provider);
        $state = mmh_oauth_b64url_encode(random_bytes(32));
        $nonce = mmh_oauth_b64url_encode(random_bytes(32));
        $_SESSION['mmh_oauth'][$provider] = [
            'state_hash' => hash('sha256', $state), 'nonce' => $nonce,
            'expires_at' => time() + 600,
        ];
        $params = [
            'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code', 'scope' => $provider === 'google' ? 'openid email profile' : 'name email',
            'state' => $state, 'nonce' => $nonce,
        ];
        if ($provider === 'google') $params['prompt'] = 'select_account';
        if ($provider === 'apple') $params['response_mode'] = 'form_post';
        header('Location: ' . $config['authorization_endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        exit;
    }
}

if (!function_exists('mmh_oauth_callback')) {
    function mmh_oauth_callback(mysqli $conn, string $provider, string $baseUrl): void
    {
        $fail = static function (string $message) use ($baseUrl): void {
            mmh_auth_flash('error', $message);
            header('Location: ' . rtrim($baseUrl, '/') . '/auth/login'); exit;
        };
        if (!in_array($provider, ['google', 'apple'], true) || !mmh_oauth_provider_available($provider)) {
            $fail('That sign-in provider is not configured yet.');
        }
        $pending = $_SESSION['mmh_oauth'][$provider] ?? null;
        unset($_SESSION['mmh_oauth'][$provider]);
        $state = (string) ($_REQUEST['state'] ?? '');
        if (!is_array($pending) || empty($pending['expires_at']) || (int) $pending['expires_at'] < time()
            || $state === '' || !hash_equals((string) $pending['state_hash'], hash('sha256', $state))) {
            $fail('This sign-in request has expired. Please try again.');
        }
        if (!empty($_REQUEST['error'])) {
            $fail('Sign-in was cancelled or denied. Please try again when you are ready.');
        }
        $code = trim((string) ($_REQUEST['code'] ?? ''));
        if ($code === '' || strlen($code) > 4096) $fail('The provider did not return a valid sign-in response.');
        $config = mmh_oauth_provider_config($provider);
        $tokenRequest = [
            'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => $config['redirect_uri'], 'client_id' => $config['client_id'],
        ];
        if ($provider === 'google') $tokenRequest['client_secret'] = $config['client_secret'];
        if ($provider === 'apple') {
            $clientSecret = mmh_oauth_apple_client_secret($config);
            if ($clientSecret === null) $fail('Apple sign-in is temporarily unavailable.');
            $tokenRequest['client_secret'] = $clientSecret;
        }
        [$status, $body] = mmh_oauth_http($config['token_endpoint'], $tokenRequest);
        $tokenData = json_decode($body, true);
        if ($status !== 200 || !is_array($tokenData) || empty($tokenData['id_token'])) $fail('The provider could not complete sign-in. Please try again.');
        $claims = mmh_oauth_validate_id_token((string) $tokenData['id_token'], $config, (string) $pending['nonce']);
        if ($claims === null) $fail('We could not verify the provider response. Please try again.');
        $displayName = '';
        if ($provider === 'apple' && !empty($_POST['user'])) {
            $appleUser = json_decode((string) $_POST['user'], true);
            if (is_array($appleUser)) {
                $displayName = trim((string) (($appleUser['name']['firstName'] ?? '') . ' ' . ($appleUser['name']['lastName'] ?? '')));
            }
        }
        [$success, $message, $user] = mmh_oauth_link_or_create_user($conn, $provider, $claims, $displayName);
        if (!$success || !$user) $fail($message ?: 'We could not complete social sign-in. Please try again.');
        mmh_auth_regenerate_session();
        unset($_SESSION['mmh_auth_csrf_token'], $_SESSION['admin']);
        $_SESSION['username'] = (string) $user['username'];
        $destination = mmh_auth_destination($conn, (string) $user['username'], 'user', $baseUrl);
        header('Location: ' . $destination); exit;
    }
}
