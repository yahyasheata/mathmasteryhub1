<?php
/** Canonical admin assessment reads. Legacy exams remain compatibility-only. */
if (!function_exists('mmh_admin_assignment_rows')) {
    function mmh_admin_assignment_rows(mysqli $conn): array
    {
        $result = $conn->query('SELECT * FROM assignments WHERE archived_at IS NULL ORDER BY due_date ASC, id ASC');
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('mmh_admin_assignment_submission_counts')) {
    function mmh_admin_assignment_submission_counts(mysqli $conn): array
    {
        $counts = [];
        $result = $conn->query('SELECT assignment_id, COUNT(*) AS total FROM assignment_submissions GROUP BY assignment_id');
        if ($result) {
            while ($row = $result->fetch_assoc()) $counts[(string) $row['assignment_id']] = (int) $row['total'];
        }
        return $counts;
    }
}

if (!function_exists('mmh_admin_assessment_source')) {
    function mmh_admin_assessment_source(array $item): string
    {
        $type = strtolower(trim((string) ($item['template_type'] ?? $item['item_type'] ?? '')));
        return $type === 'timed_exam' ? 'timed_exams' : 'assignments';
    }
}
