<?php
/**
 * Schema changes are migration-only. Runtime requests may inspect schema
 * availability, but they must never create or alter tables.
 */
if (!function_exists('mmh_schema_mutations_allowed')) {
    function mmh_schema_mutations_allowed(): bool
    {
        return PHP_SAPI === 'cli' && defined('MMH_SCHEMA_MIGRATION_MODE') && MMH_SCHEMA_MIGRATION_MODE === true;
    }
}
