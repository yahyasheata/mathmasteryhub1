#!/usr/bin/env php
<?php
/** Safe Google Drive authentication diagnostic. Never prints secrets. */
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../inc/PastPaperDriveImport.php';

$status = mmh_google_drive_credential_status();
[$pathOk] = mmh_google_drive_service_account_path();
echo 'SERVICE ACCOUNT FILE: ' . ($pathOk ? 'FOUND' : 'NOT FOUND') . PHP_EOL;
echo 'AUTH MODE: ' . ($status['label'] ?? 'Not configured') . PHP_EOL;

$credential = mmh_google_drive_access_credential();
if (!empty($credential['available']) && in_array($credential['mode'] ?? '', ['service_account', 'explicit_access_token'], true)) {
    echo 'AUTH PASS' . PHP_EOL;
} else {
    echo 'AUTH FAIL' . PHP_EOL;
    echo 'AUTH MESSAGE: ' . ($credential['message'] ?? $status['message'] ?? 'Authentication is unavailable.') . PHP_EOL;
}

[$ok, $data, $message, $httpStatus] = mmh_past_drive_api('files', [
    'pageSize' => 1,
    'fields' => 'files(id,name,mimeType),nextPageToken',
    'includeItemsFromAllDrives' => 'true',
]);
echo ($ok ? 'DRIVE API PASS' : 'DRIVE API FAIL') . PHP_EOL;
echo 'HTTP STATUS: ' . (int) $httpStatus . PHP_EOL;
if (!$ok) {
    echo 'ERROR: ' . $message . PHP_EOL;
}
exit($ok ? 0 : 1);
