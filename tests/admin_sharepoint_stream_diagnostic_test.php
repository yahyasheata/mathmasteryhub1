<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$route = file_get_contents($root . '/index.php');
$view = file_get_contents($root . '/views/admin/diagnostics/sharepoint-stream-test.php');

$requiredRoute = "'/diagnostics/sharepoint-stream-test'";

foreach ([$requiredRoute, 'mmh_admin_require_admin();'] as $needle) {
    if (strpos($route, $needle) === false) {
        throw new RuntimeException('Missing diagnostic route guard: ' . $needle);
    }
}
foreach (['https://alexuuni-my.sharepoint.com/:v:/g/personal/es_yehia_shehata2024_alexu_edu_eg/IQAiQHosEWtMT53dhX-s-ysCARMXHL-GKGNtrptp1_qQAKU', '<iframe', 'allowfullscreen', 'Test A — Anonymous Share Link Embedded', 'Test B — Anonymous Share Link External', 'Open exact anonymous URL in new tab'] as $needle) {
    if (strpos($view, $needle) === false) {
        throw new RuntimeException('Missing diagnostic view requirement: ' . $needle);
    }
}
if (stripos($view, 'sandbox=') !== false || stripos($view, 'token') !== false || stripos($view, 'password') !== false) {
    throw new RuntimeException('Diagnostic view contains a forbidden sandbox or credential marker.');
}

echo "admin_sharepoint_stream_diagnostic_test: ok\n";
