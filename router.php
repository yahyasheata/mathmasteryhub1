<?php
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$staticPath = rawurldecode((string) $requestPath);
$filePath = __DIR__ . $staticPath;

// Static filenames may safely contain spaces or Unicode characters.  Test the
// decoded filesystem path while retaining the original request URI for routing.
if ($requestPath !== '/' && is_file($filePath)) {
    return false;
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '1');

if (!isset($_SERVER['REQUEST_SCHEME'])) {
    $_SERVER['REQUEST_SCHEME'] = 'http';
}

chdir(__DIR__);

// The built-in server may set SCRIPT_NAME to a nested asset-directory URL
// (for example /resources/open/...), which makes Bramus Router strip the
// wrong base path. Pin local preview requests to the real front controller.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/index.php';
