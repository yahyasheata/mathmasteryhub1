<?php
/**
 * Live Teaching Phase 1: recurring course schedules, lazy occurrences,
 * protected join tracking, and manual attendance.
 *
 * This module is intentionally separate from Course Builder lessons.
 */

require_once __DIR__ . '/learning_schema.php';

if (!function_exists('mmh_live_response')) {
    function mmh_live_response($success, $message, array $data = [], $statusCode = 200)
    {
        if (!headers_sent()) {
            http_response_code((int) $statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array_merge([
            'success' => (bool) $success,
            'status' => $success ? 1 : 0,
            'message' => $message,
        ], $data));
        exit;
    }
}

if (!function_exists('mmh_live_ensure_schema')) {
    function mmh_live_ensure_schema(mysqli $conn)
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        if (!mmh_table_exists($conn, 'course_live_schedules')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `course_live_schedules` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `schedule_id` VARCHAR(40) NOT NULL,
                `course_id` VARCHAR(40) NOT NULL,
                `title` VARCHAR(190) NULL,
                `day_of_week` TINYINT UNSIGNED NOT NULL,
                `start_time` TIME NOT NULL,
                `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 90,
                `timezone` VARCHAR(80) NOT NULL DEFAULT 'Asia/Riyadh',
                `teams_url` TEXT NOT NULL,
                `teams_meeting_ref` VARCHAR(190) NULL,
                `academic_period` VARCHAR(120) NULL,
                `effective_start_date` DATE NOT NULL,
                `effective_end_date` DATE NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `admin_notes` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_schedule_id` (`schedule_id`),
                KEY `idx_course_effective` (`course_id`, `enabled`, `effective_start_date`, `effective_end_date`),
                KEY `idx_course_day_sort` (`course_id`, `day_of_week`, `start_time`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'live_session_occurrences')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `live_session_occurrences` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `occurrence_id` VARCHAR(40) NOT NULL,
                `schedule_id` VARCHAR(40) NOT NULL,
                `course_id` VARCHAR(40) NOT NULL,
                `scheduled_start_at` DATETIME NOT NULL,
                `scheduled_end_at` DATETIME NOT NULL,
                `timezone` VARCHAR(80) NOT NULL DEFAULT 'Asia/Riyadh',
                `status` VARCHAR(20) NOT NULL DEFAULT 'scheduled',
                `teams_url_snapshot` TEXT NOT NULL,
                `replacement_url` TEXT NULL,
                `change_note` TEXT NULL,
                `actual_start_at` DATETIME NULL,
                `actual_end_at` DATETIME NULL,
                `teams_attendance_report_id` VARCHAR(190) NULL,
                `teams_imported_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_occurrence_id` (`occurrence_id`),
                UNIQUE KEY `uniq_schedule_start` (`schedule_id`, `scheduled_start_at`),
                KEY `idx_course_start` (`course_id`, `scheduled_start_at`),
                KEY `idx_status_start` (`status`, `scheduled_start_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'live_session_join_events')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `live_session_join_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `course_id` VARCHAR(40) NOT NULL,
                `occurrence_id` VARCHAR(40) NOT NULL,
                `clicked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `minutes_from_start` INT NULL,
                `source` VARCHAR(40) NOT NULL DEFAULT 'student_dashboard',
                `user_agent_category` VARCHAR(40) NULL,
                PRIMARY KEY (`id`),
                KEY `idx_user_occurrence` (`user_id`, `occurrence_id`, `clicked_at`),
                KEY `idx_occurrence` (`occurrence_id`, `clicked_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'live_session_attendance')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `live_session_attendance` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `course_id` VARCHAR(40) NOT NULL,
                `occurrence_id` VARCHAR(40) NOT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'unknown',
                `first_join_clicked_at` DATETIME NULL,
                `last_join_clicked_at` DATETIME NULL,
                `confirmed_source` VARCHAR(32) NOT NULL DEFAULT 'none',
                `teacher_note` TEXT NULL,
                `external_attendee_ref` VARCHAR(190) NULL,
                `confirmed_by` INT NULL,
                `confirmed_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_student_occurrence` (`user_id`, `occurrence_id`),
                KEY `idx_course_occurrence` (`course_id`, `occurrence_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        $conn->query("ALTER TABLE `course_live_schedules` CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci");
        $conn->query("ALTER TABLE `live_session_occurrences` CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci");
        $conn->query("ALTER TABLE `live_session_join_events` CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci");
        $conn->query("ALTER TABLE `live_session_attendance` CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci");
    }
}

if (!function_exists('mmh_live_id')) {
    function mmh_live_id($prefix)
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('mmh_live_timezone')) {
    function mmh_live_timezone($timezone = '')
    {
        $timezone = trim((string) $timezone);
        try {
            return new DateTimeZone($timezone !== '' ? $timezone : 'Asia/Riyadh');
        } catch (Throwable $e) {
            return new DateTimeZone('Asia/Riyadh');
        }
    }
}

if (!function_exists('mmh_live_sanitize_teams_url')) {
    function mmh_live_sanitize_teams_url($url)
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2000) {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return null;
        }
        $allowed = $host === 'teams.microsoft.com' || str_ends_with($host, '.teams.microsoft.com');
        if (!$allowed) {
            return null;
        }
        return $url;
    }
}

if (!function_exists('mmh_live_admin_id')) {
    function mmh_live_admin_id(mysqli $conn, $username)
    {
        $stmt = $conn->prepare('SELECT user_id FROM users WHERE username = ? OR CAST(user_id AS CHAR) = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $username = (string) $username;
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['user_id'] : null;
    }
}

if (!function_exists('mmh_live_course_options')) {
    function mmh_live_course_options(mysqli $conn)
    {
        mmh_live_ensure_schema($conn);
        $rows = [];
        $result = $conn->query('SELECT course_id, course_title FROM courses ORDER BY course_title ASC, id ASC');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('mmh_live_schedule_rows')) {
    function mmh_live_schedule_rows(mysqli $conn, $courseId = '')
    {
        mmh_live_ensure_schema($conn);
        if ($courseId !== '') {
            $stmt = $conn->prepare('SELECT s.*, c.course_title FROM course_live_schedules AS s INNER JOIN courses AS c ON c.course_id = s.course_id WHERE s.course_id = ? ORDER BY s.day_of_week ASC, s.start_time ASC, s.sort_order ASC');
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('s', $courseId);
        } else {
            $stmt = $conn->prepare('SELECT s.*, c.course_title FROM course_live_schedules AS s INNER JOIN courses AS c ON c.course_id = s.course_id ORDER BY c.course_title ASC, s.day_of_week ASC, s.start_time ASC');
            if (!$stmt) {
                return [];
            }
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_live_save_schedule')) {
    function mmh_live_save_schedule(mysqli $conn, array $data)
    {
        mmh_live_ensure_schema($conn);
        $courseId = trim((string) ($data['course_id'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        $day = (int) ($data['day_of_week'] ?? -1);
        $startTime = trim((string) ($data['start_time'] ?? ''));
        $duration = max(1, (int) ($data['duration_minutes'] ?? 90));
        $timezone = mmh_live_timezone($data['timezone'] ?? 'Asia/Riyadh')->getName();
        $teamsUrl = mmh_live_sanitize_teams_url($data['teams_url'] ?? '');
        $meetingRef = trim((string) ($data['teams_meeting_ref'] ?? ''));
        $period = trim((string) ($data['academic_period'] ?? ''));
        $startDate = trim((string) ($data['effective_start_date'] ?? ''));
        $endDate = trim((string) ($data['effective_end_date'] ?? ''));
        $enabled = isset($data['enabled']) && (string) $data['enabled'] === '0' ? 0 : 1;
        $sortOrder = max(0, (int) ($data['sort_order'] ?? 0));
        $notes = trim((string) ($data['admin_notes'] ?? ''));

        if ($courseId === '' || $day < 0 || $day > 6 || !preg_match('/^\d{2}:\d{2}$/', $startTime) || $teamsUrl === null || strtotime($startDate) === false) {
            return [false, 'Please provide a valid course, weekday, time, effective date, and Microsoft Teams HTTPS URL.'];
        }
        if ($endDate !== '' && strtotime($endDate) === false) {
            return [false, 'Effective end date is invalid.'];
        }

        $scheduleId = trim((string) ($data['schedule_id'] ?? ''));
        if ($scheduleId !== '') {
            $stmt = $conn->prepare('UPDATE course_live_schedules SET course_id=?, title=?, day_of_week=?, start_time=?, duration_minutes=?, timezone=?, teams_url=?, teams_meeting_ref=?, academic_period=?, effective_start_date=?, effective_end_date=?, enabled=?, sort_order=?, admin_notes=? WHERE schedule_id=?');
            if (!$stmt) {
                return [false, 'Unable to prepare schedule update: ' . $conn->error];
            }
            $endDateParam = $endDate !== '' ? $endDate : null;
            $stmt->bind_param('ssisissssssiiss', $courseId, $title, $day, $startTime, $duration, $timezone, $teamsUrl, $meetingRef, $period, $startDate, $endDateParam, $enabled, $sortOrder, $notes, $scheduleId);
            $ok = $stmt->execute();
            $stmt->close();
            return [$ok, $ok ? 'Schedule updated successfully.' : 'Schedule update failed.'];
        }

        $scheduleId = mmh_live_id('sched');
        $stmt = $conn->prepare('INSERT INTO course_live_schedules (schedule_id, course_id, title, day_of_week, start_time, duration_minutes, timezone, teams_url, teams_meeting_ref, academic_period, effective_start_date, effective_end_date, enabled, sort_order, admin_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            return [false, 'Unable to prepare schedule insert: ' . $conn->error];
        }
        $endDateParam = $endDate !== '' ? $endDate : null;
        $stmt->bind_param('sssisissssssiis', $scheduleId, $courseId, $title, $day, $startTime, $duration, $timezone, $teamsUrl, $meetingRef, $period, $startDate, $endDateParam, $enabled, $sortOrder, $notes);
        $ok = $stmt->execute();
        $stmt->close();
        return [$ok, $ok ? 'Schedule added successfully.' : 'Schedule insert failed.', ['schedule_id' => $scheduleId]];
    }
}

if (!function_exists('mmh_live_generate_occurrences')) {
    function mmh_live_generate_occurrences(mysqli $conn, $fromDays = -3, $toDays = 45, $courseId = '')
    {
        mmh_live_ensure_schema($conn);
        $from = new DateTime('today ' . ((int) $fromDays) . ' days', new DateTimeZone('UTC'));
        $to = new DateTime('today +' . ((int) $toDays) . ' days', new DateTimeZone('UTC'));
        $schedules = mmh_live_schedule_rows($conn, $courseId);
        $inserted = 0;

        foreach ($schedules as $schedule) {
            if ((int) $schedule['enabled'] !== 1) {
                continue;
            }
            $tz = mmh_live_timezone($schedule['timezone'] ?? 'Asia/Riyadh');
            $cursor = new DateTime($from->format('Y-m-d 00:00:00'), $tz);
            $end = new DateTime($to->format('Y-m-d 23:59:59'), $tz);
            $effectiveStart = new DateTime((string) $schedule['effective_start_date'] . ' 00:00:00', $tz);
            $effectiveEnd = !empty($schedule['effective_end_date']) ? new DateTime((string) $schedule['effective_end_date'] . ' 23:59:59', $tz) : null;

            while ($cursor <= $end) {
                if ((int) $cursor->format('w') === (int) $schedule['day_of_week']) {
                    $localStart = new DateTime($cursor->format('Y-m-d') . ' ' . substr((string) $schedule['start_time'], 0, 5) . ':00', $tz);
                    if ($localStart >= $effectiveStart && ($effectiveEnd === null || $localStart <= $effectiveEnd)) {
                        $localEnd = clone $localStart;
                        $localEnd->modify('+' . max(1, (int) $schedule['duration_minutes']) . ' minutes');
                        $utcStart = clone $localStart;
                        $utcStart->setTimezone(new DateTimeZone('UTC'));
                        $utcEnd = clone $localEnd;
                        $utcEnd->setTimezone(new DateTimeZone('UTC'));
                        $occurrenceId = 'occ_' . substr(sha1($schedule['schedule_id'] . '|' . $utcStart->format('Y-m-d H:i:s')), 0, 16);
                        $stmt = $conn->prepare("INSERT IGNORE INTO live_session_occurrences (occurrence_id, schedule_id, course_id, scheduled_start_at, scheduled_end_at, timezone, status, teams_url_snapshot) VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?)");
                        if ($stmt) {
                            $start = $utcStart->format('Y-m-d H:i:s');
                            $endAt = $utcEnd->format('Y-m-d H:i:s');
                            $stmt->bind_param('sssssss', $occurrenceId, $schedule['schedule_id'], $schedule['course_id'], $start, $endAt, $schedule['timezone'], $schedule['teams_url']);
                            $stmt->execute();
                            $inserted += $stmt->affected_rows > 0 ? 1 : 0;
                            $stmt->close();
                        }
                    }
                }
                $cursor->modify('+1 day');
            }
        }

        return $inserted;
    }
}

if (!function_exists('mmh_live_occurrences')) {
    function mmh_live_occurrences(mysqli $conn, $courseId = '', $fromDays = -3, $toDays = 45, $onlyEnrolledUserId = null)
    {
        mmh_live_generate_occurrences($conn, $fromDays, $toDays, $courseId);
        $from = gmdate('Y-m-d H:i:s', strtotime((int) $fromDays . ' days'));
        $to = gmdate('Y-m-d H:i:s', strtotime('+' . (int) $toDays . ' days'));
        $params = [];
        $types = '';
        $sql = 'SELECT o.*, s.title AS schedule_title, s.academic_period, c.course_title';
        if ($onlyEnrolledUserId !== null) {
            $sql .= ', a.status AS attendance_status, a.first_join_clicked_at, a.last_join_clicked_at, a.confirmed_source';
        }
        $sql .= ' FROM live_session_occurrences AS o INNER JOIN course_live_schedules AS s ON s.schedule_id = o.schedule_id INNER JOIN courses AS c ON c.course_id = o.course_id';
        if ($onlyEnrolledUserId !== null) {
            $sql .= ' INNER JOIN course_logs AS cl ON cl.course_id = o.course_id AND cl.user_id = ?';
            $types .= 'i';
            $params[] = (int) $onlyEnrolledUserId;
            $sql .= ' LEFT JOIN live_session_attendance AS a ON a.occurrence_id = o.occurrence_id AND a.user_id = ?';
            $types .= 'i';
            $params[] = (int) $onlyEnrolledUserId;
        }
        $sql .= ' WHERE o.scheduled_start_at BETWEEN ? AND ?';
        $types .= 'ss';
        $params[] = $from;
        $params[] = $to;
        if ($courseId !== '') {
            $sql .= ' AND o.course_id = ?';
            $types .= 's';
            $params[] = $courseId;
        }
        $sql .= ' ORDER BY o.scheduled_start_at ASC, s.sort_order ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_live_occurrence')) {
    function mmh_live_occurrence(mysqli $conn, $occurrenceId)
    {
        mmh_live_ensure_schema($conn);
        $stmt = $conn->prepare('SELECT o.*, s.title AS schedule_title, c.course_title FROM live_session_occurrences AS o INNER JOIN course_live_schedules AS s ON s.schedule_id = o.schedule_id INNER JOIN courses AS c ON c.course_id = o.course_id WHERE o.occurrence_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $occurrenceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_live_display_time')) {
    function mmh_live_display_time(array $occurrence, $format = 'D, j M g:i A')
    {
        $tz = mmh_live_timezone($occurrence['timezone'] ?? 'Asia/Riyadh');
        $dt = new DateTime((string) $occurrence['scheduled_start_at'], new DateTimeZone('UTC'));
        $dt->setTimezone($tz);
        return $dt->format($format);
    }
}

if (!function_exists('mmh_live_occurrence_timestamp')) {
    function mmh_live_occurrence_timestamp(array $occurrence, $field)
    {
        $value = trim((string) ($occurrence[$field] ?? ''));
        if ($value === '') {
            return false;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable $exception) {
            return false;
        }
    }
}

if (!function_exists('mmh_live_join_state')) {
    function mmh_live_join_state(array $occurrence, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $status = strtolower(trim((string) ($occurrence['status'] ?? 'scheduled')));
        $start = mmh_live_occurrence_timestamp($occurrence, 'scheduled_start_at');
        $end = mmh_live_occurrence_timestamp($occurrence, 'scheduled_end_at');
        $target = mmh_live_sanitize_teams_url((string) (($occurrence['replacement_url'] ?? '') ?: ($occurrence['teams_url_snapshot'] ?? '')));

        if ($status === 'cancelled') {
            return ['active' => false, 'state' => 'cancelled', 'label' => 'Session Cancelled'];
        }
        if ($target === null) {
            return ['active' => false, 'state' => 'missing_link', 'label' => 'Meeting link not available'];
        }
        if ($start === false || $end === false) {
            return ['active' => false, 'state' => 'invalid_time', 'label' => 'Session time unavailable'];
        }
        if ($status === 'completed' || $now > $end) {
            return ['active' => false, 'state' => 'ended', 'label' => 'Session Ended'];
        }
        if ($now < ($start - 1800)) {
            return ['active' => false, 'state' => 'opens_soon', 'label' => 'Join opens 30 minutes before'];
        }
        if ($now >= $start && $now <= $end) {
            return ['active' => true, 'state' => 'live', 'label' => 'Join Live Session'];
        }
        return ['active' => true, 'state' => 'ready', 'label' => 'Join Session'];
    }
}

if (!function_exists('mmh_live_current_priority')) {
    function mmh_live_current_priority(mysqli $conn, $userId)
    {
        $sessions = mmh_live_occurrences($conn, '', 0, 7, (int) $userId);
        foreach ($sessions as $session) {
            $joinState = mmh_live_join_state($session);
            if (!empty($joinState['active'])) {
                $session['_priority_state'] = $joinState['state'] === 'live' ? 'Live now' : 'Starting soon';
                $session['_join_label'] = $joinState['label'];
                return $session;
            }
        }
        return null;
    }
}

if (!function_exists('mmh_live_record_join')) {
    function mmh_live_record_join(mysqli $conn, $userId, array $occurrence, $source = 'student_dashboard')
    {
        mmh_live_ensure_schema($conn);
        $userId = (int) $userId;
        $occurrenceId = (string) $occurrence['occurrence_id'];
        $courseId = (string) $occurrence['course_id'];
        $stmt = $conn->prepare('SELECT clicked_at FROM live_session_join_events WHERE user_id = ? AND occurrence_id = ? AND clicked_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) ORDER BY clicked_at DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('is', $userId, $occurrenceId);
            $stmt->execute();
            $recent = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($recent) {
                return true;
            }
        }
        $minutes = null;
        $start = mmh_live_occurrence_timestamp($occurrence, 'scheduled_start_at');
        if ($start !== false) {
            $minutes = (int) floor((time() - $start) / 60);
        }
        $agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $ua = str_contains($agent, 'mobile') ? 'mobile' : 'desktop';
        $stmt = $conn->prepare('INSERT INTO live_session_join_events (user_id, course_id, occurrence_id, minutes_from_start, source, user_agent_category) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ississ', $userId, $courseId, $occurrenceId, $minutes, $source, $ua);
        $ok = $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO live_session_attendance (user_id, course_id, occurrence_id, status, first_join_clicked_at, last_join_clicked_at, confirmed_source) VALUES (?, ?, ?, 'unknown', NOW(), NOW(), 'none') ON DUPLICATE KEY UPDATE first_join_clicked_at = COALESCE(first_join_clicked_at, NOW()), last_join_clicked_at = NOW()");
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $courseId, $occurrenceId);
            $stmt->execute();
            $stmt->close();
        }
        return (bool) $ok;
    }
}

if (!function_exists('mmh_live_attendance_statuses')) {
    function mmh_live_attendance_statuses()
    {
        return [
            'unknown' => 'Unknown',
            'present_live' => 'Present',
            'late' => 'Late',
            'absent' => 'Absent',
            'excused' => 'Excused',
        ];
    }
}

if (!function_exists('mmh_live_attendance_label')) {
    function mmh_live_attendance_label($status)
    {
        $status = trim((string) $status);
        $labels = mmh_live_attendance_statuses();
        return $labels[$status] ?? 'Unknown';
    }
}

if (!function_exists('mmh_live_student_attendance_label')) {
    function mmh_live_student_attendance_label(array $session)
    {
        $status = trim((string) ($session['attendance_status'] ?? 'unknown')) ?: 'unknown';
        if (!empty($session['first_join_clicked_at']) && $status === 'unknown') {
            return 'Waiting for teacher confirmation';
        }
        if ($status === 'present_live') {
            return 'Confirmed present';
        }
        if ($status === 'late') {
            return 'Confirmed late';
        }
        if ($status === 'absent') {
            return 'Marked absent';
        }
        if ($status === 'excused') {
            return 'Excused';
        }
        return 'Attendance not confirmed yet';
    }
}

if (!function_exists('mmh_live_format_local_datetime')) {
    function mmh_live_format_local_datetime($value, $timezone = 'Asia/Riyadh', $format = 'g:i A')
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        try {
            $tz = mmh_live_timezone($timezone);
            return (new DateTimeImmutable($value, $tz))->format($format);
        } catch (Throwable $exception) {
            return $value;
        }
    }
}

if (!function_exists('mmh_live_join_evidence')) {
    function mmh_live_join_evidence(array $row, array $occurrence)
    {
        if (empty($row['first_join_clicked_at'])) {
            return ['has_join' => false, 'label' => 'No Join click recorded', 'detail' => 'Student has not clicked the protected Join button.'];
        }

        $timezone = $occurrence['timezone'] ?? 'Asia/Riyadh';
        $joinedAt = mmh_live_format_local_datetime($row['first_join_clicked_at'], $timezone);
        $lastAt = !empty($row['last_join_clicked_at']) && $row['last_join_clicked_at'] !== $row['first_join_clicked_at']
            ? mmh_live_format_local_datetime($row['last_join_clicked_at'], $timezone)
            : '';

        $detail = 'Join click recorded';
        try {
            $tz = mmh_live_timezone($timezone);
            $start = new DateTimeImmutable((string) $occurrence['scheduled_start_at'], new DateTimeZone('UTC'));
            $start = $start->setTimezone($tz);
            $clicked = new DateTimeImmutable((string) $row['first_join_clicked_at'], $tz);
            if ($clicked > $start) {
                $detail = 'Join clicked late';
            }
        } catch (Throwable $exception) {
            $detail = 'Join click recorded';
        }

        if ($lastAt !== '') {
            $detail .= ' · Last click ' . $lastAt;
        }

        return ['has_join' => true, 'label' => 'Joined at ' . $joinedAt, 'detail' => $detail];
    }
}

if (!function_exists('mmh_live_students_for_occurrence')) {
    function mmh_live_students_for_occurrence(mysqli $conn, array $occurrence)
    {
        $stmt = $conn->prepare("SELECT u.user_id, u.full_name, u.username,
                COALESCE(a.status, 'unknown') AS status,
                COALESCE(a.first_join_clicked_at, je.first_join_clicked_at) AS first_join_clicked_at,
                COALESCE(a.last_join_clicked_at, je.last_join_clicked_at) AS last_join_clicked_at,
                a.teacher_note,
                COALESCE(a.confirmed_source, 'none') AS confirmed_source,
                COALESCE(je.join_clicks, 0) AS join_clicks
            FROM course_logs AS cl
            INNER JOIN users AS u ON u.user_id = cl.user_id
            LEFT JOIN live_session_attendance AS a ON a.user_id = u.user_id AND a.occurrence_id = ?
            LEFT JOIN (
                SELECT user_id, occurrence_id, MIN(clicked_at) AS first_join_clicked_at, MAX(clicked_at) AS last_join_clicked_at, COUNT(*) AS join_clicks
                FROM live_session_join_events
                WHERE occurrence_id = ?
                GROUP BY user_id, occurrence_id
            ) AS je ON je.user_id = u.user_id AND je.occurrence_id = ?
            WHERE cl.course_id = ?
            ORDER BY u.full_name ASC, u.username ASC");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ssss', $occurrence['occurrence_id'], $occurrence['occurrence_id'], $occurrence['occurrence_id'], $occurrence['course_id']);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_live_save_attendance')) {
    function mmh_live_save_attendance(mysqli $conn, array $occurrence, $userId, $status, $note, $adminId)
    {
        $allowed = ['unknown', 'present_live', 'late', 'absent', 'excused', 'recording_completed', 'manually_confirmed'];
        $status = in_array($status, $allowed, true) ? $status : 'unknown';
        $source = $status === 'unknown' ? 'none' : 'manual';
        $stmt = $conn->prepare('INSERT INTO live_session_attendance (user_id, course_id, occurrence_id, status, confirmed_source, teacher_note, confirmed_by, confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), confirmed_source = VALUES(confirmed_source), teacher_note = VALUES(teacher_note), confirmed_by = VALUES(confirmed_by), confirmed_at = NOW()');
        if (!$stmt) {
            return false;
        }
        $courseId = (string) $occurrence['course_id'];
        $occurrenceId = (string) $occurrence['occurrence_id'];
        $userId = (int) $userId;
        $adminId = $adminId !== null ? (int) $adminId : null;
        $note = trim((string) $note);
        $stmt->bind_param('isssssi', $userId, $courseId, $occurrenceId, $status, $source, $note, $adminId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}
?>
