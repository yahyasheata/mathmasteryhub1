<?php
/**
 * Minimal CSRF protection for the student-course requests changed in B4B.
 *
 * The application has no existing shared CSRF validator. This helper keeps a
 * single per-session token and intentionally scopes adoption to the existing
 * state-changing course requests rather than refactoring unrelated forms.
 */

if (!function_exists('student_course_csrf_token')) {
    function student_course_csrf_token()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (empty($_SESSION['student_course_csrf_token'])) {
            $_SESSION['student_course_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['student_course_csrf_token'];
    }
}

if (!function_exists('student_course_csrf_valid')) {
    function student_course_csrf_valid($token)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $stored = $_SESSION['student_course_csrf_token'] ?? '';
        return is_string($token) && $stored !== '' && hash_equals($stored, $token);
    }
}
