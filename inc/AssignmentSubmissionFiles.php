<?php
require_once __DIR__ . '/StudentCourseAccess.php';

if (!function_exists('mmh_assignment_submission_file_load')) {
    function mmh_assignment_submission_file_load(mysqli $conn, int $fileId): ?array
    {
        if ($fileId <= 0) return null;
        $stmt = $conn->prepare('SELECT f.id, f.submission_id, f.file_path, f.original_filename, s.student_id, s.assignment_id, a.course_id, a.item_id, a.section_id FROM assignment_submission_files f INNER JOIN assignment_submissions s ON s.id = f.submission_id INNER JOIN assignments a ON a.assignment_id = s.assignment_id WHERE f.id = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $fileId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('mmh_assignment_submission_file_serve')) {
    function mmh_assignment_submission_file_serve(mysqli $conn, int $fileId, bool $admin = false): void
    {
        $file = mmh_assignment_submission_file_load($conn, $fileId);
        if (!$file) { http_response_code(404); exit('File not found.'); }
        if (!$admin) {
            if (empty($_SESSION['username'])) { http_response_code(401); exit('Sign in required.'); }
            $studentId = student_course_access_student_id($conn, $_SESSION['username']);
            $course = student_course_access_authorized_course($conn, $studentId ?: 0, $file['course_id'] ?? '');
            $assignment = student_course_access_assignment($conn, $file['assignment_id'] ?? '');
            if (!$studentId || (int) $file['student_id'] !== (int) $studentId || !$course || !$assignment) {
                http_response_code(403); exit('This file is not available.');
            }
            $sectionId = student_course_access_normalize_section_id($file['section_id'] ?? '');
            if ($sectionId !== '') {
                $sectionState = student_course_access_section_state($conn, $course, $sectionId, $studentId);
                if (!$sectionState || !empty($sectionState['state']['locked'])) { http_response_code(403); exit('This file is not available.'); }
            }
            $itemId = trim((string) ($file['item_id'] ?? ''));
            if ($itemId !== '') {
                $item = student_course_access_item($conn, $file['course_id'], $itemId);
                if (!$item || !student_course_access_assignment_matches_item($assignment, $item)) { http_response_code(403); exit('This file is not available.'); }
            }
        } elseif (empty($_SESSION['admin'])) {
            http_response_code(403); exit('Administrator access is required.');
        }
        $relative = ltrim(str_replace('\\', '/', (string) ($file['file_path'] ?? '')), '/');
        if ($relative === '' || str_contains($relative, '..') || !str_starts_with($relative, 'uploads/static/assignments/assignment_submissions/')) {
            http_response_code(404); exit('File not found.');
        }
        $root = realpath(dirname(__DIR__));
        $path = realpath($root . '/' . $relative);
        $uploadsRoot = realpath($root . '/uploads/static/assignments/assignment_submissions');
        if ($path === false || $uploadsRoot === false || !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $uploadsRoot), '/') . '/') || !is_file($path) || !is_readable($path)) {
            http_response_code(404); exit('File not found.');
        }
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($path) : 'application/octet-stream';
        if ($mime === '') $mime = 'application/octet-stream';
        $name = trim((string) ($file['original_filename'] ?? ''));
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: basename($path);
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
        readfile($path);
        exit;
    }
}
