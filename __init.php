<?php
if (!function_exists('mmh_current_request_origin')) {
    /** Build a safe absolute origin from the current request, retaining a valid port. */
    function mmh_current_request_origin(): string
    {
        $httpsEnabled = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $forwardedSsl = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
        $scheme = ($httpsEnabled || $forwardedProto === 'https' || $forwardedSsl === 'on') ? 'https' : 'http';

        $candidate = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        // HTTP_HOST may include an IPv4/IPv6 host and a port. Reject control
        // characters or path content before reflecting it into absolute URLs.
        $host = preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $candidate) ? $candidate : '127.0.0.1:8091';
        return $scheme . '://' . $host;
    }
}

$script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$scriptDirectory = dirname($script);
$basePath = ($scriptDirectory === '/' || $scriptDirectory === '.' || $scriptDirectory === '\\') ? '' : rtrim($scriptDirectory, '/');
$baseUrl = mmh_current_request_origin() . $basePath;
