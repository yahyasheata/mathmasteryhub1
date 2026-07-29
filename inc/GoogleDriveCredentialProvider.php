<?php
/**
 * Google Drive credentials for server-side integrations.
 *
 * Service-account JSON stays outside the web root and is never serialized,
 * logged, or sent to the browser. The provider intentionally keeps the
 * current process token in memory only; GoogleClient refreshes it on demand.
 */

if (!function_exists('mmh_google_drive_env')) {
    function mmh_google_drive_env($key)
    {
        $value = getenv($key);
        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('mmh_google_drive_service_account_path')) {
    function mmh_google_drive_service_account_path()
    {
        $path = mmh_google_drive_env('MMH_GOOGLE_SERVICE_ACCOUNT_JSON');
        if ($path === '') {
            return [false, '', 'Service account JSON is not configured.'];
        }
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return [false, '', 'Service account JSON must use an absolute path.'];
        }
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            return [false, '', 'Service account JSON was not found or is not readable.'];
        }
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== false && str_starts_with($realPath, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return [false, '', 'Service account JSON must be stored outside the public web root.'];
        }
        return [true, $realPath, ''];
    }
}

if (!function_exists('mmh_google_drive_credential_status')) {
    function mmh_google_drive_credential_status()
    {
        if (mmh_google_drive_env('MMH_GOOGLE_DRIVE_ACCESS_TOKEN') !== '') {
            return ['available' => true, 'configured' => true, 'mode' => 'explicit_access_token', 'label' => 'Explicit access token configured', 'message' => 'Explicit server-side Google Drive access token is configured.'];
        }
        [$pathOk, $path, $pathMessage] = mmh_google_drive_service_account_path();
        if ($pathOk) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (!is_file($autoload)) {
                return ['available' => false, 'configured' => true, 'mode' => 'service_account', 'label' => 'Service account configured', 'message' => 'The Google PHP API client is not installed.'];
            }
            return ['available' => true, 'configured' => true, 'mode' => 'service_account', 'label' => 'Service account configured', 'message' => 'Google Drive service account is configured.'];
        }
        if (mmh_google_drive_env('MMH_GOOGLE_SERVICE_ACCOUNT_JSON') !== '') {
            return ['available' => false, 'configured' => true, 'mode' => 'service_account', 'label' => 'Service account configuration error', 'message' => $pathMessage];
        }
        if (mmh_google_drive_env('MMH_GOOGLE_DRIVE_API_KEY') !== '') {
            return ['available' => true, 'configured' => true, 'mode' => 'api_key', 'label' => 'API key only', 'message' => 'Google Drive API key is configured. Some Drive methods require OAuth authentication.'];
        }
        return ['available' => false, 'configured' => false, 'mode' => 'unconfigured', 'label' => 'Not configured', 'message' => 'Google Drive scanning is not configured.'];
    }
}

if (!function_exists('mmh_google_drive_token_cache_key')) {
    function mmh_google_drive_token_cache_key($path)
    {
        return 'mmh_google_drive_token_' . hash('sha256', (string) $path);
    }
}

if (!function_exists('mmh_google_drive_cache_read')) {
    function mmh_google_drive_cache_read($key)
    {
        static $memory = [];
        if (isset($memory[$key]) && (int) ($memory[$key]['expires_at'] ?? 0) > time() + 60) {
            return $memory[$key];
        }
        if (function_exists('apcu_fetch') && (bool) ini_get('apc.enabled')) {
            $success = false;
            $value = apcu_fetch($key, $success);
            if ($success && is_array($value) && !empty($value['access_token']) && (int) ($value['expires_at'] ?? 0) > time() + 60) {
                return $memory[$key] = $value;
            }
        }
        return null;
    }
}

if (!function_exists('mmh_google_drive_cache_write')) {
    function mmh_google_drive_cache_write($key, array $credential)
    {
        static $memory = [];
        $memory[$key] = $credential;
        if (function_exists('apcu_store') && (bool) ini_get('apc.enabled')) {
            $ttl = max(1, (int) ($credential['expires_at'] ?? time()) - time() - 60);
            apcu_store($key, $credential, $ttl);
        }
        return $credential;
    }
}

if (!function_exists('mmh_google_drive_access_credential')) {
    function mmh_google_drive_access_credential()
    {
        static $cached = null;
        if (is_array($cached) && !empty($cached['access_token']) && (int) ($cached['expires_at'] ?? 0) > time() + 60) {
            return $cached;
        }

        $status = mmh_google_drive_credential_status();
        if ($status['mode'] === 'explicit_access_token') {
            return $cached = [
                'available' => true,
                'mode' => 'explicit_access_token',
                'access_token' => mmh_google_drive_env('MMH_GOOGLE_DRIVE_ACCESS_TOKEN'),
                'expires_at' => time() + 300,
                'message' => '',
            ];
        }
        if ($status['mode'] !== 'service_account') {
            return ['available' => false, 'mode' => $status['mode'], 'access_token' => '', 'expires_at' => 0, 'message' => $status['message']];
        }
        if (!$status['available']) {
            return ['available' => false, 'mode' => 'service_account', 'access_token' => '', 'expires_at' => 0, 'message' => $status['message']];
        }

        [$pathOk, $path, $pathMessage] = mmh_google_drive_service_account_path();
        if (!$pathOk) {
            return ['available' => false, 'mode' => 'service_account', 'access_token' => '', 'expires_at' => 0, 'message' => $pathMessage];
        }
        $cacheKey = mmh_google_drive_token_cache_key($path);
        $cachedCredential = mmh_google_drive_cache_read($cacheKey);
        if ($cachedCredential) {
            return $cached = $cachedCredential;
        }
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        require_once $autoload;
        if (!class_exists('Google\\Client')) {
            return ['available' => false, 'mode' => 'service_account', 'access_token' => '', 'expires_at' => 0, 'message' => 'The Google PHP API client is unavailable.'];
        }

        try {
            $client = new Google\Client();
            $client->setAuthConfig($path);
            $client->setScopes(['https://www.googleapis.com/auth/drive.readonly']);
            $token = $client->fetchAccessTokenWithAssertion();
            $accessToken = is_array($token) ? (string) ($token['access_token'] ?? '') : '';
            if ($accessToken === '') {
                return ['available' => false, 'mode' => 'service_account', 'access_token' => '', 'expires_at' => 0, 'message' => 'Service-account token generation failed.'];
            }
            $expiresIn = max(60, (int) ($token['expires_in'] ?? 3600));
            return $cached = mmh_google_drive_cache_write($cacheKey, [
                'available' => true,
                'mode' => 'service_account',
                'access_token' => $accessToken,
                'expires_at' => time() + $expiresIn,
                'message' => '',
            ]);
        } catch (Throwable $exception) {
            return ['available' => false, 'mode' => 'service_account', 'access_token' => '', 'expires_at' => 0, 'message' => 'Service-account token generation failed.'];
        }
    }
}
