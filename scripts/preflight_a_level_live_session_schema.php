<?php
declare(strict_types=1);

/**
 * Read-only release gate for the additive A-Level Live Session ownership migration.
 * Requiring this file from a test exposes the checker without opening a connection.
 */
if (!function_exists('mmh_a_level_live_schema_preflight')) {
    function mmh_a_level_live_schema_preflight(mysqli $conn, ?string $expectedDatabase = null): array
    {
        $errors = [];
        $identityResult = $conn->query('SELECT DATABASE() AS database_name, VERSION() AS server_version, @@hostname AS server_host, @@port AS server_port');
        if (!$identityResult) {
            return ['ok' => false, 'errors' => ['Unable to read production database identity.'], 'identity' => [], 'tables' => [], 'columns' => [], 'indexes' => [], 'foreign_keys' => []];
        }
        $identity = $identityResult->fetch_assoc() ?: [];
        $identityResult->free();
        if (empty($identity['database_name'])) $errors[] = 'The application database connection has no selected database.';
        if ($expectedDatabase !== null && $expectedDatabase !== '' && (string) $identity['database_name'] !== $expectedDatabase) {
            $errors[] = 'The connected database does not match the application DB_NAME configuration.';
        }

        $tableResult = $conn->query("SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('course_live_schedules', 'live_session_occurrences')");
        if (!$tableResult) {
            $errors[] = 'Unable to inspect required Live Session table metadata.';
            $tables = [];
        } else {
            $tables = [];
            while ($row = $tableResult->fetch_assoc()) $tables[(string) $row['TABLE_NAME']] = $row;
            $tableResult->free();
        }
        foreach (['course_live_schedules', 'live_session_occurrences'] as $table) {
            if (!isset($tables[$table])) {
                $errors[] = "Required table {$table} is missing.";
                continue;
            }
            if (strtolower((string) ($tables[$table]['ENGINE'] ?? '')) !== 'innodb') {
                $errors[] = "{$table} must use InnoDB for the approved schema migration.";
            }
        }

        $columns = [];
        $columnResult = $conn->query("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME IN ('course_live_schedules', 'live_session_occurrences') AND COLUMN_NAME = 'course_id') OR (TABLE_NAME = 'course_live_schedules' AND COLUMN_NAME = 'group_key'))");
        if (!$columnResult) {
            $errors[] = 'Unable to inspect Live Session ownership columns.';
        } else {
            while ($row = $columnResult->fetch_assoc()) $columns[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = $row;
            $columnResult->free();
        }
        foreach (['course_live_schedules.course_id', 'live_session_occurrences.course_id'] as $key) {
            if (!isset($columns[$key])) {
                if (isset($tables[explode('.', $key)[0]])) $errors[] = "Required column {$key} is missing.";
                continue;
            }
            $column = $columns[$key];
            if (strtolower((string) $column['DATA_TYPE']) !== 'varchar' || !preg_match('/^varchar\(40\)$/i', (string) $column['COLUMN_TYPE'])) {
                $errors[] = "{$key} must be VARCHAR(40).";
            }
            if (strtoupper((string) $column['IS_NULLABLE']) !== 'YES'
                && (empty($column['CHARACTER_SET_NAME']) || empty($column['COLLATION_NAME']))) {
                $errors[] = "{$key} cannot be safely altered while preserving its character settings.";
            }
            if (strtoupper((string) $column['IS_NULLABLE']) !== 'YES'
                && (!empty($column['COLUMN_COMMENT']) || !empty($column['EXTRA']))) {
                $errors[] = "{$key} has column attributes the approved migration does not preserve.";
            }
        }
        $groupKey = $columns['course_live_schedules.group_key'] ?? null;
        if ($groupKey !== null && (strtolower((string) $groupKey['DATA_TYPE']) !== 'varchar'
            || !preg_match('/^varchar\(32\)$/i', (string) $groupKey['COLUMN_TYPE'])
            || strtoupper((string) $groupKey['IS_NULLABLE']) !== 'YES'
            || !empty($groupKey['EXTRA']))) {
            $errors[] = 'Existing course_live_schedules.group_key must be nullable VARCHAR(32).';
        }

        $indexes = [];
        $indexResult = $conn->query("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('course_live_schedules', 'live_session_occurrences') ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX");
        if (!$indexResult) {
            $errors[] = 'Unable to inspect Live Session indexes.';
        } else {
            while ($row = $indexResult->fetch_assoc()) $indexes[] = $row;
            $indexResult->free();
        }

        $foreignKeys = [];
        $foreignKeyResult = $conn->query("SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('course_live_schedules', 'live_session_occurrences') AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION");
        if (!$foreignKeyResult) {
            $errors[] = 'Unable to inspect Live Session foreign keys.';
        } else {
            while ($row = $foreignKeyResult->fetch_assoc()) $foreignKeys[] = $row;
            $foreignKeyResult->free();
        }
        foreach ($foreignKeys as $foreignKey) {
            $table = (string) $foreignKey['TABLE_NAME'];
            $column = (string) $foreignKey['COLUMN_NAME'];
            $courseColumnNeedsAlter = $column === 'course_id'
                && isset($columns[$table . '.course_id'])
                && strtoupper((string) $columns[$table . '.course_id']['IS_NULLABLE']) !== 'YES';
            if ($courseColumnNeedsAlter || ($table === 'course_live_schedules' && $column === 'group_key')) {
                $errors[] = "Foreign key {$foreignKey['CONSTRAINT_NAME']} on {$table}.{$column} prevents the ownership migration from being established safely.";
            }
        }
        foreach ($indexes as $index) {
            if ((string) $index['TABLE_NAME'] === 'course_live_schedules'
                && (string) $index['COLUMN_NAME'] === 'group_key'
                && (int) $index['NON_UNIQUE'] === 0
                && (int) $index['SEQ_IN_INDEX'] === 1) {
                $indexColumns = array_values(array_filter($indexes, static fn(array $candidate): bool => (string) $candidate['TABLE_NAME'] === 'course_live_schedules' && (string) $candidate['INDEX_NAME'] === (string) $index['INDEX_NAME']));
                if (count($indexColumns) === 1) $errors[] = 'A unique single-column group_key index would prevent multiple recurring schedules for one A-Level group.';
            }
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'identity' => $identity, 'tables' => $tables, 'columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys];
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $GLOBALS['mmh_a_level_preflight_finished'] = false;
    register_shutdown_function(static function (): void {
        if (empty($GLOBALS['mmh_a_level_preflight_finished'])) {
            fwrite(STDERR, "PREFLIGHT BLOCKED: Unable to establish or complete the configured application database schema check.\n");
            exit(1);
        }
    });
    $projectRoot = getenv('MMH_PROJECT_ROOT') ?: dirname(__DIR__);
    require_once rtrim($projectRoot, DIRECTORY_SEPARATOR) . '/connection/config.php';
    if (!isset($conn) || !$conn instanceof mysqli) {
        fwrite(STDERR, "PREFLIGHT BLOCKED: The application database connection is unavailable.\n");
        $GLOBALS['mmh_a_level_preflight_finished'] = true;
        exit(1);
    }
    $result = mmh_a_level_live_schema_preflight($conn instanceof mysqli ? $conn : db(), (string) ($db ?? ''));
    $identity = $result['identity'];
    if ($identity) {
        printf("Production schema target: database=%s server=%s:%s version=%s\n", $identity['database_name'] ?? 'unknown', $identity['server_host'] ?? 'unknown', $identity['server_port'] ?? 'unknown', $identity['server_version'] ?? 'unknown');
    }
    foreach ($result['tables'] as $name => $table) {
        printf("Table %s: engine=%s estimated_rows=%s data_bytes=%s index_bytes=%s\n", $name, $table['ENGINE'] ?? 'unknown', $table['TABLE_ROWS'] ?? 'unknown', $table['DATA_LENGTH'] ?? 'unknown', $table['INDEX_LENGTH'] ?? 'unknown');
    }
    foreach ($result['columns'] as $name => $column) {
        printf("Column %s: type=%s nullable=%s charset=%s collation=%s extra=%s\n", $name, $column['COLUMN_TYPE'], $column['IS_NULLABLE'], $column['CHARACTER_SET_NAME'] ?? '-', $column['COLLATION_NAME'] ?? '-', $column['EXTRA'] ?: '-');
    }
    foreach ($result['indexes'] as $index) {
        printf("Index %s.%s: column=%s position=%s unique=%s type=%s\n", $index['TABLE_NAME'], $index['INDEX_NAME'], $index['COLUMN_NAME'] ?? '(expression)', $index['SEQ_IN_INDEX'], (int) $index['NON_UNIQUE'] === 0 ? 'yes' : 'no', $index['INDEX_TYPE']);
    }
    foreach ($result['foreign_keys'] as $foreignKey) {
        printf("Foreign key %s.%s -> %s.%s (%s)\n", $foreignKey['TABLE_NAME'], $foreignKey['COLUMN_NAME'], $foreignKey['REFERENCED_TABLE_NAME'], $foreignKey['REFERENCED_COLUMN_NAME'], $foreignKey['CONSTRAINT_NAME']);
    }
    foreach ($result['errors'] as $error) fwrite(STDERR, "PREFLIGHT BLOCKED: {$error}\n");
    $GLOBALS['mmh_a_level_preflight_finished'] = true;
    if (!$result['ok']) exit(1);
    echo "Read-only A-Level Live Session schema preflight passed. DDL lock duration is not guaranteed by this check.\n";
}
