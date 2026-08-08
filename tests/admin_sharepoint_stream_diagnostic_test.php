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
foreach (['alexuuni-my.sharepoint.com/personal/es_yehia_shehata2024_alexu_edu_eg/_layouts/15/stream.aspx?id=%2Fpersonal%2Fes%5Fyehia%5Fshehata2024%5Falexu_edu_eg%2FDocuments%2FRecordings%2FMath%20OL%20Cambridge%20Lecture%2D20260711%5F190016%2DMeeting%20Recording%2Emp4', 'nav=eyJwbGF5YmFja09wdGlvbnMiOnsic3RhcnRUaW1lSW5TZWNvbmRzIjoxNTQ3LjE1MjMyNzQ3NTk4Mzh9fQ%3D%3D', '<iframe', 'allowfullscreen', 'Open exact URL in new tab'] as $needle) {
    if (strpos($view, $needle) === false) {
        throw new RuntimeException('Missing diagnostic view requirement: ' . $needle);
    }
}
if (stripos($view, 'sandbox=') !== false || stripos($view, 'token') !== false || stripos($view, 'password') !== false) {
    throw new RuntimeException('Diagnostic view contains a forbidden sandbox or credential marker.');
}

echo "admin_sharepoint_stream_diagnostic_test: ok\n";
