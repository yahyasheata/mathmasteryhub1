<?php
/**
 * Additive helpers for importing historical homework into the normal
 * assignment_submissions lifecycle. This does not alter normal LMS uploads.
 */
require_once __DIR__ . '/learning_schema.php';

if (!function_exists('mmh_legacy_homework_csrf_token')) {
    function mmh_legacy_homework_csrf_token()
    {
        if (empty($_SESSION['legacy_homework_csrf'])) {
            $_SESSION['legacy_homework_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['legacy_homework_csrf'];
    }
}

if (!function_exists('mmh_legacy_homework_csrf_valid')) {
    function mmh_legacy_homework_csrf_valid($token)
    {
        $stored = (string) ($_SESSION['legacy_homework_csrf'] ?? '');
        return is_string($token) && $stored !== '' && hash_equals($stored, $token);
    }
}

if (!function_exists('mmh_legacy_homework_submission_source_label')) {
    function mmh_legacy_homework_submission_source_label($source)
    {
        return strtolower(trim((string) $source)) === 'legacy_import' ? 'Imported by Instructor' : 'LMS';
    }
}
