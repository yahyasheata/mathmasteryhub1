<?php
/**
 * Course-section release rules shared by admin management and student access.
 * This layer deliberately resolves only a section's release schedule; student
 * enrollment, publication, and prerequisite checks remain in StudentCourseAccess.
 */
require_once __DIR__ . '/LiveSessions.php';

if (!function_exists('mmh_section_release_modes')) {
    function mmh_section_release_modes()
    {
        return [
            'inherit' => 'Use existing learning rules only',
            'immediate' => 'Immediately available',
            'manual' => 'Manual lock / unlock',
            'scheduled' => 'Scheduled date and time',
            'live_session' => 'After linked live session',
            'live_session_delay' => 'After linked live session plus delay',
        ];
    }
}

if (!function_exists('mmh_section_release_normalize_mode')) {
    function mmh_section_release_normalize_mode($value)
    {
        $value = strtolower(trim((string) $value));
        return array_key_exists($value, mmh_section_release_modes()) ? $value : 'inherit';
    }
}

if (!function_exists('mmh_section_release_normalize_override')) {
    function mmh_section_release_normalize_override($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['inherit', 'locked', 'unlocked'], true) ? $value : 'inherit';
    }
}

if (!function_exists('mmh_section_release_timezone')) {
    function mmh_section_release_timezone($value)
    {
        $value = trim((string) $value) ?: 'Asia/Riyadh';
        try {
            return new DateTimeZone($value);
        } catch (Throwable $exception) {
            return new DateTimeZone('Asia/Riyadh');
        }
    }
}

if (!function_exists('mmh_section_release_timezone_name')) {
    function mmh_section_release_timezone_name($value)
    {
        return mmh_section_release_timezone($value)->getName();
    }
}

if (!function_exists('mmh_section_release_utc_from_local')) {
    function mmh_section_release_utc_from_local($value, $timezone)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable(str_replace('T', ' ', $value), mmh_section_release_timezone($timezone));
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $exception) {
            return null;
        }
    }
}

if (!function_exists('mmh_section_release_local_input')) {
    function mmh_section_release_local_input($utcValue, $timezone)
    {
        $utcValue = trim((string) $utcValue);
        if ($utcValue === '') {
            return '';
        }
        try {
            $date = new DateTimeImmutable($utcValue, new DateTimeZone('UTC'));
            return $date->setTimezone(mmh_section_release_timezone($timezone))->format('Y-m-d\\TH:i');
        } catch (Throwable $exception) {
            return '';
        }
    }
}

if (!function_exists('mmh_section_release_timestamp')) {
    function mmh_section_release_timestamp($utcValue)
    {
        $utcValue = trim((string) $utcValue);
        if ($utcValue === '') {
            return false;
        }
        try {
            return (new DateTimeImmutable($utcValue, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable $exception) {
            return false;
        }
    }
}

if (!function_exists('mmh_section_release_format_timestamp')) {
    function mmh_section_release_format_timestamp($timestamp, $timezone)
    {
        if (!is_numeric($timestamp)) {
            return '';
        }
        try {
            return (new DateTimeImmutable('@' . (int) $timestamp))
                ->setTimezone(mmh_section_release_timezone($timezone))
                ->format('l, j M \a\t g:i A');
        } catch (Throwable $exception) {
            return '';
        }
    }
}

if (!function_exists('mmh_section_release_occurrence')) {
    function mmh_section_release_occurrence(mysqli $conn, $courseId, $occurrenceId)
    {
        $courseId = trim((string) $courseId);
        $occurrenceId = trim((string) $occurrenceId);
        if ($courseId === '' || $occurrenceId === '') {
            return null;
        }
        mmh_live_ensure_schema($conn);
        $stmt = $conn->prepare('SELECT o.occurrence_id, o.course_id, o.scheduled_start_at, o.scheduled_end_at, o.timezone, o.status, o.change_note, s.title AS schedule_title FROM live_session_occurrences AS o LEFT JOIN course_live_schedules AS s ON s.schedule_id = o.schedule_id WHERE o.occurrence_id = ? AND o.course_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $occurrenceId, $courseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_section_release_state')) {
    function mmh_section_release_state(mysqli $conn, array $section, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $sectionId = trim((string) ($section['section_id'] ?? $section['key'] ?? ''));
        if ($sectionId === '' || $sectionId === '__general__') {
            return [
                'accessible' => true, 'locked' => false, 'reason' => '', 'badge' => 'Available',
                'mode' => 'immediate', 'override' => 'inherit', 'release_timestamp' => null,
                'release_label' => '', 'linked_session' => null, 'warning' => '',
            ];
        }

        $mode = mmh_section_release_normalize_mode($section['release_mode'] ?? 'inherit');
        $override = mmh_section_release_normalize_override($section['release_override'] ?? 'inherit');
        $timezone = mmh_section_release_timezone_name($section['release_timezone'] ?? $section['unlock_timezone'] ?? 'Asia/Riyadh');
        $base = [
            'accessible' => true,
            'locked' => false,
            'reason' => '',
            'badge' => 'Available',
            'mode' => $mode,
            'override' => $override,
            'release_timestamp' => null,
            'release_label' => '',
            'linked_session' => null,
            'warning' => '',
        ];

        // An explicit teacher lock always wins. An explicit unlock bypasses
        // only this release rule; StudentCourseAccess still applies enrollment,
        // publication, and any existing sequential prerequisite.
        if ($override === 'locked') {
            return array_merge($base, ['accessible' => false, 'locked' => true, 'reason' => 'Locked by your teacher.', 'badge' => 'Locked']);
        }
        if ($override === 'unlocked') {
            return array_merge($base, ['badge' => 'Unlocked by teacher']);
        }

        if ($mode === 'inherit' || $mode === 'immediate') {
            return $base;
        }
        if ($mode === 'manual') {
            return array_merge($base, ['accessible' => false, 'locked' => true, 'reason' => 'This section is not available yet.', 'badge' => 'Locked']);
        }
        if ($mode === 'scheduled') {
            $timestamp = mmh_section_release_timestamp($section['release_at'] ?? '');
            if ($timestamp === false) {
                return array_merge($base, ['accessible' => false, 'locked' => true, 'reason' => 'This section is scheduled for release.', 'badge' => 'Scheduled', 'warning' => 'Scheduled release time is missing or invalid.']);
            }
            $label = mmh_section_release_format_timestamp($timestamp, $timezone);
            if ($now < $timestamp) {
                return array_merge($base, ['accessible' => false, 'locked' => true, 'reason' => 'Available ' . $label . '.', 'badge' => 'Available Later', 'release_timestamp' => $timestamp, 'release_label' => $label]);
            }
            return array_merge($base, ['release_timestamp' => $timestamp, 'release_label' => $label]);
        }

        $occurrence = mmh_section_release_occurrence($conn, $section['course_id'] ?? '', $section['release_occurrence_id'] ?? '');
        if (!$occurrence) {
            return array_merge($base, ['accessible' => false, 'locked' => true, 'reason' => 'This section will be available after its linked live session.', 'badge' => 'Live session', 'warning' => 'The linked live-session occurrence is missing or no longer belongs to this course.']);
        }
        if (strtolower(trim((string) ($occurrence['status'] ?? ''))) === 'cancelled') {
            return array_merge($base, ['accessible' => false, 'locked' => true, 'reason' => 'This section is waiting for a replacement live session.', 'badge' => 'Live session', 'linked_session' => $occurrence, 'warning' => 'The linked live-session occurrence is cancelled.']);
        }
        $endTimestamp = mmh_section_release_timestamp($occurrence['scheduled_end_at'] ?? '');
        if ($endTimestamp === false) {
            return array_merge($base, ['accessible' => false, 'locked' => true, 'reason' => 'This section will be available after its linked live session.', 'badge' => 'Live session', 'linked_session' => $occurrence, 'warning' => 'The linked live-session occurrence has no valid end time.']);
        }
        $delay = max(0, min(10080, (int) ($section['release_delay_minutes'] ?? 0)));
        $releaseTimestamp = $endTimestamp + ($delay * 60);
        $label = mmh_section_release_format_timestamp($releaseTimestamp, $occurrence['timezone'] ?? $timezone);
        $title = trim((string) ($occurrence['schedule_title'] ?? 'Live session')) ?: 'Live session';
        if ($now < $releaseTimestamp) {
            return array_merge($base, [
                'accessible' => false, 'locked' => true,
                'reason' => 'Available after ' . $title . ' on ' . $label . '.',
                'badge' => 'After Live Session', 'release_timestamp' => $releaseTimestamp,
                'release_label' => $label, 'linked_session' => $occurrence,
            ]);
        }
        return array_merge($base, ['release_timestamp' => $releaseTimestamp, 'release_label' => $label, 'linked_session' => $occurrence]);
    }
}
