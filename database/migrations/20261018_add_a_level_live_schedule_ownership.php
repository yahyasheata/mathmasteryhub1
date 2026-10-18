<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
if (!isset($conn) || !$conn instanceof mysqli) $conn = db();

$tableExists = static function (mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to inspect required Live Session tables.');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
};

$column = static function (mysqli $conn, string $table, string $name): ?array {
    $stmt = $conn->prepare('SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to inspect Live Session column definitions.');
    $stmt->bind_param('ss', $table, $name);
    $stmt->execute();
    $value = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $value;
};

foreach (['course_live_schedules', 'live_session_occurrences'] as $table) {
    if (!$tableExists($conn, $table)) continue;
    $definition = $column($conn, $table, 'course_id');
    if (!$definition) throw new RuntimeException("Required {$table}.course_id column is missing.");
    if (strtolower((string) $definition['DATA_TYPE']) !== 'varchar' || !preg_match('/^varchar\(40\)$/i', (string) $definition['COLUMN_TYPE'])) {
        throw new RuntimeException("{$table}.course_id must be VARCHAR(40); no change was applied to that column.");
    }
    if (strtoupper((string) $definition['IS_NULLABLE']) !== 'YES') {
        $charset = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $definition['CHARACTER_SET_NAME']);
        $collation = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $definition['COLLATION_NAME']);
        if ($charset === '' || $collation === '') throw new RuntimeException("Unable to safely preserve {$table}.course_id character settings.");
        $sql = "ALTER TABLE `{$table}` MODIFY COLUMN `course_id` VARCHAR(40) CHARACTER SET {$charset} COLLATE {$collation} NULL DEFAULT NULL";
        if (!$conn->query($sql)) throw new RuntimeException("Unable to make {$table}.course_id nullable: " . $conn->error);
    }
}

if ($tableExists($conn, 'course_live_schedules')) {
    if (!$column($conn, 'course_live_schedules', 'group_key') && !$conn->query("ALTER TABLE `course_live_schedules` ADD COLUMN `group_key` VARCHAR(32) NULL DEFAULT NULL")) {
        throw new RuntimeException('Unable to add course_live_schedules.group_key: ' . $conn->error);
    }
}

echo "A-Level Live Session schedule ownership is ready; existing schedules remain Course-owned.\n";
