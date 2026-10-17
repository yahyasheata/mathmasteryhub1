<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This migration can only run from the command line.\n"); }
require_once dirname(__DIR__, 2) . '/connection/config.php';
$conn = db();

$tableExists = static function (string $table) use ($conn): bool {
    $stmt = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to inspect modular access tables.');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
};
$columnExists = static function (string $table, string $column) use ($conn): bool {
    $stmt = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to inspect modular access columns.');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
};

foreach (['course_sections', 'course_logs'] as $table) {
    if (!$tableExists($table)) throw new RuntimeException("Required table {$table} does not exist. No changes were applied.");
}

if (!$columnExists('course_sections', 'access_unit')) {
    if (!$conn->query("ALTER TABLE `course_sections` ADD COLUMN `access_unit` VARCHAR(10) NOT NULL DEFAULT 'shared'")) {
        throw new RuntimeException('Unable to add course_sections.access_unit: ' . $conn->error);
    }
}
if (!$columnExists('course_logs', 'unit_access')) {
    if (!$conn->query('ALTER TABLE `course_logs` ADD COLUMN `unit_access` VARCHAR(10) NULL DEFAULT NULL')) {
        throw new RuntimeException('Unable to add course_logs.unit_access: ' . $conn->error);
    }
}

echo "Modular section access columns are ready; existing enrollments remain unclassified.\n";
