<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

// Runtime modules retain their idempotent definitions, but this explicit
// migration mode is the only path permitted to execute CREATE/ALTER SQL.
define('MMH_SCHEMA_MIGRATION_MODE', true);
require_once dirname(__DIR__, 2) . '/connection/config.php';
require_once dirname(__DIR__, 2) . '/inc/learning_schema.php';
require_once dirname(__DIR__, 2) . '/inc/LandingPage.php';
require_once dirname(__DIR__, 2) . '/inc/ParentWeeklyReport.php';
require_once dirname(__DIR__, 2) . '/inc/LiveSessions.php';
require_once dirname(__DIR__, 2) . '/inc/FreeResources.php';
require_once dirname(__DIR__, 2) . '/inc/PastPapers.php';
require_once dirname(__DIR__, 2) . '/inc/PastPaperDriveImport.php';
require_once dirname(__DIR__, 2) . '/inc/OAuth.php';

$conn = db();
mmh_ensure_learning_schema($conn);
mmh_landing_ensure_schema($conn);
mmh_parent_report_ensure_schema($conn);
mmh_live_ensure_schema($conn);
mmh_free_ensure_schema($conn);
mmh_past_ensure_schema($conn);
mmh_past_drive_ensure_schema($conn);
if (!mmh_oauth_ensure_schema($conn)) {
    throw new RuntimeException('Unable to create OAuth schema: ' . $conn->error);
}

echo "Runtime module schema is ready.\n";
