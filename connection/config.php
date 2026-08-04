<?php
require_once __DIR__ . '/../inc/AdminSecurity.php';
mmh_admin_block_direct_internal_file();
/**
 * Load local environment values once, without overriding values supplied by
 * the web server/PHP-FPM process. Secrets are never emitted by this bootstrap.
 */
if (!function_exists('mmh_load_local_env')) {
    function mmh_load_local_env($path)
    {
        if (!is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $position = strpos($line, '=');
            if ($position === false) {
                continue;
            }
            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));
            if (!preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key)
                || getenv($key) !== false
                || array_key_exists($key, $_ENV)
                || array_key_exists($key, $_SERVER)) {
                continue;
            }
            if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
mmh_load_local_env(__DIR__ . '/../.env');

date_default_timezone_set('Africa/Cairo');

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db = getenv('DB_NAME') ?: 'mathmsgv_lms';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    http_response_code(500);
    error_log('Database connection failed: ' . mysqli_connect_error());
    exit('A temporary server error occurred.');
}

mysqli_set_charset($conn, 'utf8');
$GLOBALS['conn'] = $conn;

// Defence in depth for included admin pages/handlers.  The front controller
// remains the normal gate, but an admin implementation file cannot be reused
// from another request without an authenticated administrator session.
if (function_exists('mmh_admin_require_admin')) {
    $adminStack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    foreach ($adminStack as $frame) {
        $caller = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
        if (str_starts_with($caller, $projectRoot . '/views/admin/')) {
            mmh_admin_require_admin(false);
            break;
        }
    }
}

function db()
{
    return $GLOBALS['conn'];
}
?>
