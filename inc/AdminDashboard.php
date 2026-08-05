<?php
require_once __DIR__ . '/CourseVisibility.php';
/** Bounded, operational Admin Dashboard queries. No synthetic analytics. */

if (!function_exists('mmh_admin_dashboard_period')) {
    function mmh_admin_dashboard_period(string $requested): array
    {
        $map = [
            'today' => ['Today', 1],
            '7d' => ['Last 7 days', 7],
            '30d' => ['Last 30 days', 30],
        ];
        $requested = array_key_exists($requested, $map) ? $requested : '7d';
        [$label, $days] = $map[$requested];
        $now = new DateTimeImmutable('now');
        $start = $now->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
        return [
            'key' => $requested,
            'label' => $label,
            'days' => $days,
            'start' => $start,
            'end' => $now,
            'previous_start' => $start->modify('-' . $days . ' days'),
            'previous_end' => $start,
        ];
    }
}

if (!function_exists('mmh_admin_dashboard_scalar')) {
    function mmh_admin_dashboard_scalar(mysqli $conn, string $sql, string $types = '', array $values = []): int
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return 0;
        if ($types !== '') $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('mmh_admin_dashboard_delta')) {
    function mmh_admin_dashboard_delta(int $current, int $previous): ?string
    {
        if ($previous === 0) return $current > 0 ? 'New in this period' : null;
        $difference = $current - $previous;
        return ($difference >= 0 ? '+' : '') . $difference . ' vs previous period';
    }
}

if (!function_exists('mmh_admin_dashboard_activity_series')) {
    function mmh_admin_dashboard_activity_series(mysqli $conn, array $period): array
    {
        $start = $period['start']->format('Y-m-d H:i:s');
        $end = $period['end']->format('Y-m-d H:i:s');
        $sql = "SELECT DATE(e.created_at) AS activity_day, COUNT(DISTINCT e.user_id) AS total
                FROM learning_events e
                INNER JOIN users u ON u.user_id = e.user_id AND u.role = 'user'
                WHERE e.created_at >= ? AND e.created_at < ?
                  AND e.event_type NOT IN ('login', 'logout')
                GROUP BY DATE(e.created_at)";
        $stmt = $conn->prepare($sql);
        $totals = [];
        if ($stmt) {
            $stmt->bind_param('ss', $start, $end);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $totals[$row['activity_day']] = (int) $row['total'];
            $stmt->close();
        }
        $series = [];
        for ($i = 0; $i < $period['days']; $i++) {
            $day = $period['start']->modify('+' . $i . ' days')->format('Y-m-d');
            $series[] = ['label' => $period['days'] <= 7 ? date('D', strtotime($day)) : date('M j', strtotime($day)), 'value' => $totals[$day] ?? 0];
        }
        return $series;
    }
}

if (!function_exists('mmh_admin_dashboard_recent_activity')) {
    function mmh_admin_dashboard_recent_activity(mysqli $conn): array
    {
        $activity = [];
        $queries = [
            ['sql' => "SELECT u.full_name, u.username, u.created_at FROM users u WHERE u.role = 'user' ORDER BY u.created_at DESC LIMIT 4", 'kind' => 'Student registered', 'url' => 'users'],
            ['sql' => "SELECT l.course_title, l.purchase_date, u.full_name, u.username FROM course_logs l LEFT JOIN users u ON u.user_id = l.user_id ORDER BY l.purchase_date DESC LIMIT 4", 'kind' => 'Student enrolled', 'url' => 'courses'],
            ['sql' => "SELECT a.assignment_title, s.submitted_at, u.full_name, u.username FROM assignment_submissions s LEFT JOIN assignments a ON a.assignment_id = s.assignment_id LEFT JOIN users u ON u.user_id = s.student_id ORDER BY s.submitted_at DESC LIMIT 4", 'kind' => 'Assignment submitted', 'url' => 'assignment-submissions'],
            ['sql' => "SELECT job_type, status, completed_at, started_at FROM past_paper_drive_jobs ORDER BY COALESCE(completed_at, started_at) DESC LIMIT 4", 'kind' => 'Past Papers import', 'url' => 'past-papers'],
        ];
        foreach ($queries as $definition) {
            $result = $conn->query($definition['sql']);
            if (!$result) continue;
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $time = $row['created_at'] ?? $row['purchase_date'] ?? $row['submitted_at'] ?? $row['completed_at'] ?? $row['started_at'] ?? null;
                if (!$time) continue;
                $subject = trim((string) ($row['course_title'] ?? $row['assignment_title'] ?? $row['full_name'] ?? $row['username'] ?? $row['status'] ?? ''));
                if ($definition['kind'] === 'Past Papers import') $subject = ucfirst((string) ($row['status'] ?? 'updated'));
                $activity[] = ['kind' => $definition['kind'], 'subject' => $subject, 'time' => $time, 'url' => $definition['url']];
            }
        }
        usort($activity, static fn(array $a, array $b): int => strtotime($b['time']) <=> strtotime($a['time']));
        return array_slice($activity, 0, 10);
    }
}

if (!function_exists('mmh_admin_dashboard_courses')) {
    function mmh_admin_dashboard_courses(mysqli $conn, array $period): array
    {
        $start = $period['start']->format('Y-m-d H:i:s');
        $sql = "SELECT c.course_id, c.course_title, c.course_state, c.created_at,
                       COALESCE(enrollment.total, 0) AS enrolled_students,
                       COALESCE(activity.total, 0) AS active_students,
                       COALESCE(pending.total, 0) AS pending_assignments
                FROM courses c
                LEFT JOIN (SELECT course_id, COUNT(DISTINCT user_id) AS total FROM course_logs GROUP BY course_id) enrollment ON enrollment.course_id = CAST(c.course_id AS UNSIGNED)
                LEFT JOIN (SELECT course_id, COUNT(DISTINCT user_id) AS total FROM learning_events WHERE created_at >= ? AND event_type NOT IN ('login','logout') GROUP BY course_id) activity ON activity.course_id COLLATE utf8mb3_unicode_ci = c.course_id COLLATE utf8mb3_unicode_ci
                LEFT JOIN (SELECT a.course_id, COUNT(s.id) AS total FROM assignments a INNER JOIN assignment_submissions s ON s.assignment_id = a.assignment_id WHERE s.grade IS NULL GROUP BY a.course_id) pending ON pending.course_id = c.course_id
                ORDER BY FIELD(c.course_state, 'public', 'private', 'draft'), activity.total DESC, enrollment.total DESC, c.created_at DESC LIMIT 8";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('s', $start);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_admin_dashboard_data')) {
    function mmh_admin_dashboard_data(mysqli $conn, string $periodKey, string $adminUsername): array
    {
        $period = mmh_admin_dashboard_period($periodKey);
        $start = $period['start']->format('Y-m-d H:i:s'); $end = $period['end']->format('Y-m-d H:i:s');
        $previousStart = $period['previous_start']->format('Y-m-d H:i:s'); $previousEnd = $period['previous_end']->format('Y-m-d H:i:s');
        $metrics = [
            'active_students' => ['label' => 'Active students', 'icon' => 'fa-user-check', 'url' => 'users', 'current' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(DISTINCT e.user_id) AS total FROM learning_events e INNER JOIN users u ON u.user_id=e.user_id AND u.role='user' WHERE e.created_at >= ? AND e.created_at < ? AND e.event_type NOT IN ('login','logout')", 'ss', [$start, $end]), 'previous' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(DISTINCT e.user_id) AS total FROM learning_events e INNER JOIN users u ON u.user_id=e.user_id AND u.role='user' WHERE e.created_at >= ? AND e.created_at < ? AND e.event_type NOT IN ('login','logout')", 'ss', [$previousStart, $previousEnd])],
            'registrations' => ['label' => 'New registrations', 'icon' => 'fa-user-plus', 'url' => 'users', 'current' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM users WHERE role='user' AND created_at >= ? AND created_at < ?", 'ss', [$start, $end]), 'previous' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM users WHERE role='user' AND created_at >= ? AND created_at < ?", 'ss', [$previousStart, $previousEnd])],
            'enrollments' => ['label' => 'New enrollments', 'icon' => 'fa-book-reader', 'url' => 'courses', 'current' => mmh_admin_dashboard_scalar($conn, 'SELECT COUNT(*) AS total FROM course_logs WHERE purchase_date >= ? AND purchase_date < ?', 'ss', [$start, $end]), 'previous' => mmh_admin_dashboard_scalar($conn, 'SELECT COUNT(*) AS total FROM course_logs WHERE purchase_date >= ? AND purchase_date < ?', 'ss', [$previousStart, $previousEnd])],
            'submissions' => ['label' => 'Assignments submitted', 'icon' => 'fa-clipboard-check', 'url' => 'assignment-submissions', 'current' => mmh_admin_dashboard_scalar($conn, 'SELECT COUNT(*) AS total FROM assignment_submissions WHERE submitted_at >= ? AND submitted_at < ?', 'ss', [$start, $end]), 'previous' => mmh_admin_dashboard_scalar($conn, 'SELECT COUNT(*) AS total FROM assignment_submissions WHERE submitted_at >= ? AND submitted_at < ?', 'ss', [$previousStart, $previousEnd])],
        ];
        foreach ($metrics as &$metric) $metric['comparison'] = mmh_admin_dashboard_delta($metric['current'], $metric['previous']);
        unset($metric);
        $attention = [
            ['count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM courses WHERE course_state = 'draft'"), 'label' => 'draft courses', 'detail' => 'Draft courses are visible only to administrators.', 'url' => 'courses'],
            ['count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM (SELECT c.course_id FROM courses c LEFT JOIN course_items i ON i.course_id=c.course_id WHERE c.course_state IN ('public','private') GROUP BY c.course_id HAVING COUNT(i.id)=0) empty_courses", '', []), 'label' => 'available courses with no content', 'detail' => 'Add course content before sharing these courses.', 'url' => 'courses'],
            ['count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM assignment_submissions WHERE grade IS NULL"), 'label' => 'submissions awaiting review', 'detail' => 'Open assignment submissions to grade or provide feedback.', 'url' => 'assignment-submissions'],
            ['count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM past_paper_drive_jobs WHERE status='failed'"), 'label' => 'failed Drive import jobs', 'detail' => 'Review importer failures before the next sync.', 'url' => 'past-papers'],
            ['count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM past_papers WHERE status <> 'published'"), 'label' => 'draft Past Paper groups', 'detail' => 'Review or publish mapped paper groups.', 'url' => 'past-papers'],
        ];
        $attention = array_values(array_filter($attention, static fn(array $item): bool => $item['count'] > 0));
        $content = [
            ['label' => 'Public courses', 'count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM courses WHERE course_state='public'"), 'url' => 'courses'],
            ['label' => 'Private courses', 'count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM courses WHERE course_state='private'"), 'url' => 'courses'],
            ['label' => 'Draft courses', 'count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM courses WHERE course_state='draft'"), 'url' => 'courses'],
            ['label' => 'Published Free Learning resources', 'count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM free_learning_resources WHERE status='published'"), 'url' => 'free-learning'],
            ['label' => 'Published Past Paper resources', 'count' => mmh_admin_dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM past_paper_resources WHERE status='published'"), 'url' => 'past-papers'],
        ];
        $adminName = $adminUsername;
        $admin = $conn->prepare('SELECT full_name FROM users WHERE LOWER(username)=LOWER(?) LIMIT 1');
        if ($admin) { $admin->bind_param('s', $adminUsername); $admin->execute(); $row=$admin->get_result()->fetch_assoc(); if (!empty($row['full_name'])) $adminName=$row['full_name']; $admin->close(); }
        return ['period' => $period, 'admin_name' => $adminName, 'metrics' => $metrics, 'attention' => $attention, 'content' => $content, 'activity' => mmh_admin_dashboard_recent_activity($conn), 'series' => mmh_admin_dashboard_activity_series($conn, $period), 'courses' => mmh_admin_dashboard_courses($conn, $period), 'health' => ['database' => true, 'uploads' => is_writable(dirname(__DIR__) . '/uploads/static/site'), 'drive' => trim((string) getenv('MMH_GOOGLE_DRIVE_API_KEY')) !== '']];
    }
}
