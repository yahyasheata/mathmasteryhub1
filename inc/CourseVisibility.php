<?php
/** Canonical course publication and visibility rules. */

if (!function_exists('mmh_course_state_normalize')) {
    function mmh_course_state_normalize($value, $legacyStatus = '1', $legacyVisibility = 'public'): string
    {
        $state = strtolower(trim((string) $value));
        if (in_array($state, ['public', 'private', 'draft'], true)) return $state;
        if ((string) $legacyStatus !== '1') return 'draft';
        return strtolower(trim((string) $legacyVisibility)) === 'private' ? 'private' : 'public';
    }
}

if (!function_exists('mmh_course_state')) {
    function mmh_course_state(array $course): string
    {
        return mmh_course_state_normalize($course['course_state'] ?? '', $course['course_status'] ?? '1', $course['course_visibility'] ?? 'public');
    }
}

if (!function_exists('mmh_course_is_public')) {
    function mmh_course_is_public(array $course): bool
    {
        return mmh_course_state($course) === 'public';
    }
}

if (!function_exists('mmh_course_is_student_available')) {
    function mmh_course_is_student_available(array $course): bool
    {
        return in_array(mmh_course_state($course), ['public', 'private'], true);
    }
}

if (!function_exists('mmh_course_is_published')) {
    function mmh_course_is_published(array $course): bool
    {
        return mmh_course_is_student_available($course);
    }
}

if (!function_exists('mmh_course_visibility_normalize')) {
    /** Compatibility alias for older integrations; state is now canonical. */
    function mmh_course_visibility_normalize($value): string
    {
        return strtolower(trim((string) $value)) === 'private' ? 'private' : 'public';
    }
}
