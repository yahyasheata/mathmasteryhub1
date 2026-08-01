<?php

if (!function_exists('mmh_request_host')) {
    function mmh_request_host(): string
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($host !== '' && $host[0] === '[') {
            $closingBracket = strpos($host, ']');
            return $closingBracket === false ? $host : substr($host, 0, $closingBracket + 1);
        }

        return explode(':', $host, 2)[0];
    }
}

if (!function_exists('mmh_redirect_legacy_www_host')) {
    /**
     * Keep authentication on one origin. PHP session cookies are host-only, so
     * serving both the apex and www hosts would allow two unrelated sessions.
     */
    function mmh_redirect_legacy_www_host(): void
    {
        if (mmh_request_host() !== 'www.mathmasteryhub.com') {
            return;
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($requestUri === '' || $requestUri[0] !== '/' || preg_match('/[\r\n]/', $requestUri)) {
            $requestUri = '/';
        }

        // Remove the obsolete www-only session before moving to the canonical
        // host. No identity or session value is exposed in the response.
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: https://mathmasteryhub.com' . $requestUri, true, 308);
        exit;
    }
}

if (!function_exists('mmh_send_private_response_headers')) {
    function mmh_send_private_response_headers(): void
    {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Cookie', false);
    }
}

