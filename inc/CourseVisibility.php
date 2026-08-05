<?php
/** Canonical course publication and visibility rules. */

if (!function_exists('mmh_course_visibility_normalize')) {
    function mmh_course_visibility_normalize($value): string
    {
        return strtolower(trim((string) $value)) === 'private' ? 'private' : 'public';
    }
}

if (!function_exists('mmh_course_is_published')) {
    function mmh_course_is_published(array $course): bool
    {
        return (string) ($course['course_status'] ?? '0') === '1';
    }
}

if (!function_exists('mmh_course_is_public')) {
    function mmh_course_is_public(array $course): bool
    {
        return mmh_course_is_published($course)
            && mmh_course_visibility_normalize($course['course_visibility'] ?? 'public') === 'public';
    }
}

if (!function_exists('mmh_course_is_student_available')) {
    function mmh_course_is_student_available(array $course): bool
    {
        // Both public and private published courses are available to students
        // who already have an enrollment. Enrollment is checked separately.
        return mmh_course_is_published($course);
    }
}
