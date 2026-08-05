<?php
/** Shared public-course lookup and enrollment helpers. */

require_once __DIR__ . '/CourseVisibility.php';

if (!function_exists('mmh_public_course_identifier')) {
    function mmh_public_course_identifier($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' && strlen($value) <= 40 && preg_match('/\A[A-Za-z0-9_-]+\z/', $value) ? $value : null;
    }
}

if (!function_exists('mmh_public_course_find')) {
    function mmh_public_course_find(mysqli $conn, $identifier): ?array
    {
        $identifier = mmh_public_course_identifier($identifier);
        if ($identifier === null) return null;
        $stmt = $conn->prepare('SELECT * FROM courses WHERE archived_at IS NULL AND (course_id = ? OR CAST(id AS CHAR) = ?) LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $course;
    }
}

if (!function_exists('mmh_public_course_enrolled')) {
    function mmh_public_course_enrolled(mysqli $conn, $userId, string $courseId): bool
    {
        $userId = (int) $userId;
        if ($userId <= 0 || trim($courseId) === '') return false;
        $stmt = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('is', $userId, $courseId);
        $stmt->execute();
        $enrolled = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $enrolled;
    }
}

if (!function_exists('mmh_public_course_url')) {
    function mmh_public_course_url(string $baseUrl, array $course, string $suffix = ''): string
    {
        // Public course previews join course_items/course_logs with SELECT *;
        // their duplicate `id` columns can overwrite courses.id. Prefer the
        // explicit courses.id alias emitted by that query.
        $identifier = (string) ($course['cid'] ?? $course['id'] ?? $course['course_id'] ?? '');
        return rtrim($baseUrl, '/') . '/course/' . rawurlencode($identifier) . $suffix;
    }
}
