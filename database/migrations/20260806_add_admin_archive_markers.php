<?php
/**
 * Add non-destructive archive markers used by Stage 1 admin actions.
 * Run: php database/migrations/20260806_add_admin_archive_markers.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only run from the command line.\n");
}

require_once __DIR__ . '/../../connection/config.php';
$conn = db();

function mmh_archive_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

foreach (['courses', 'users', 'assignments', 'course_items', 'categories'] as $table) {
    if (!mmh_archive_column_exists($conn, $table, 'archived_at')) {
        $safeTable = '`' . $table . '`';
        if (!$conn->query("ALTER TABLE {$safeTable} ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL")) {
            throw new RuntimeException("Unable to add archived_at to {$table}: {$conn->error}");
        }
    }
}

echo "Admin archive marker migration complete.\n";
