<?php
/**
 * Parent Weekly Reports read only existing learning data. The optional teacher
 * comment is the sole report-owned record.
 */
require_once __DIR__ . '/CourseResourceResolver.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/StudentLearningJourney.php';

function mmh_parent_report_ensure_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS parent_weekly_report_comments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        course_id VARCHAR(40) NOT NULL,
        student_id INT NOT NULL,
        week_start DATE NOT NULL,
        week_end DATE NOT NULL,
        teacher_comment VARCHAR(1000) NOT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_parent_report_comment (course_id, student_id, week_start, week_end),
        KEY idx_parent_report_course_week (course_id, week_start, week_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS parent_weekly_report_overrides (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        course_id VARCHAR(40) NOT NULL,
        student_id INT NOT NULL,
        week_start DATE NOT NULL,
        week_end DATE NOT NULL,
        section_id VARCHAR(40) NOT NULL,
        attendance_override VARCHAR(24) NULL,
        recording_override VARCHAR(24) NULL,
        homework_override VARCHAR(24) NULL,
        homework_score_override DECIMAL(10,2) NULL,
        homework_max_score_override DECIMAL(10,2) NULL,
        revision_override VARCHAR(24) NULL,
        exam_override VARCHAR(24) NULL,
        exam_score_override DECIMAL(10,2) NULL,
        exam_max_score_override DECIMAL(10,2) NULL,
        updated_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_parent_report_override (course_id, student_id, week_start, week_end, section_id),
        KEY idx_parent_report_override_lookup (course_id, student_id, week_start, week_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function mmh_parent_report_overrides(mysqli $conn, string $courseId, int $studentId, string $start, string $end): array
{
    $stmt = $conn->prepare('SELECT * FROM parent_weekly_report_overrides WHERE course_id = ? AND student_id = ? AND week_start = ? AND week_end = ?');
    if (!$stmt) { return []; }
    $stmt->bind_param('siss', $courseId, $studentId, $start, $end);
    $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $overrides = []; foreach ($rows as $row) { $overrides[(string) $row['section_id']] = $row; }
    return $overrides;
}

function mmh_parent_report_override_number($value, string $label): ?string
{
    $value = trim((string) $value);
    if ($value === '') { return null; }
    if (!is_numeric($value) || (float) $value < 0) { throw new InvalidArgumentException($label . ' must be a non-negative number.'); }
    return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
}

function mmh_parent_report_override_input(array $input, array $sections): array
{
    $sectionMap = []; foreach ($sections as $section) { $sectionMap[(string) $section['section_id']] = $section; }
    $result = [];
    $normalAttendance = ['present', 'late', 'absent', 'excused', 'not_recorded'];
    $recording = ['viewed', 'not_viewed', 'not_required', 'no_recording'];
    $homework = ['submitted', 'missing', 'waiting_for_grade', 'no_homework'];
    $revision = ['viewed', 'not_viewed', 'no_video'];
    $exam = ['completed', 'not_completed', 'waiting_for_grade', 'no_exam'];
    foreach ($input as $sectionId => $values) {
        $sectionId = trim((string) $sectionId);
        if ($sectionId === '' || !isset($sectionMap[$sectionId]) || !is_array($values)) { throw new InvalidArgumentException('An invalid report section was submitted.'); }
        $isWorkshop = !empty($sectionMap[$sectionId]['is_workshop']);
        $clean = [];
        foreach (['attendance' => $normalAttendance, 'recording' => $recording, 'homework' => $homework, 'revision' => $revision, 'exam' => $exam] as $field => $allowed) {
            if (!array_key_exists($field, $values)) { continue; }
            $value = trim((string) $values[$field]);
            if ($value === '') { continue; }
            if (($field === 'attendance' || $field === 'recording') && $isWorkshop) { continue; }
            if (($field === 'revision' || $field === 'exam') && !$isWorkshop) { continue; }
            if (!in_array($value, $allowed, true)) { throw new InvalidArgumentException('An invalid ' . $field . ' value was submitted.'); }
            $clean[$field . '_override'] = $value;
        }
        foreach (['homework_score' => 'Homework score', 'homework_max_score' => 'Homework maximum score', 'exam_score' => 'Exam score', 'exam_max_score' => 'Exam maximum score'] as $field => $label) {
            if (($field === 'exam_score' || $field === 'exam_max_score') && !$isWorkshop) { continue; }
            $clean[$field . '_override'] = mmh_parent_report_override_number($values[$field] ?? '', $label);
        }
        foreach ([['homework_score_override', 'homework_max_score_override', 'Homework score'], ['exam_score_override', 'exam_max_score_override', 'Exam score']] as [$scoreField, $maxField, $label]) {
            if (($clean[$scoreField] ?? null) !== null && ($clean[$maxField] ?? null) !== null && (float) $clean[$scoreField] > (float) $clean[$maxField]) { throw new InvalidArgumentException($label . ' cannot be greater than its maximum score.'); }
        }
        if (array_filter($clean, static fn($value) => $value !== null && $value !== '')) { $result[$sectionId] = $clean; }
    }
    return $result;
}

function mmh_parent_report_save_overrides(mysqli $conn, string $courseId, int $studentId, string $start, string $end, array $overrides, ?int $adminId): void
{
    $conn->begin_transaction();
    try {
        $delete = $conn->prepare('DELETE FROM parent_weekly_report_overrides WHERE course_id = ? AND student_id = ? AND week_start = ? AND week_end = ?');
        $delete->bind_param('siss', $courseId, $studentId, $start, $end); $delete->execute(); $delete->close();
        if ($overrides) {
            $stmt = $conn->prepare('INSERT INTO parent_weekly_report_overrides (course_id, student_id, week_start, week_end, section_id, attendance_override, recording_override, homework_override, homework_score_override, homework_max_score_override, revision_override, exam_override, exam_score_override, exam_max_score_override, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($overrides as $sectionId => $override) {
                $attendance = $override['attendance_override'] ?? null; $recording = $override['recording_override'] ?? null; $homework = $override['homework_override'] ?? null;
                $homeworkScore = $override['homework_score_override'] ?? null; $homeworkMax = $override['homework_max_score_override'] ?? null; $revision = $override['revision_override'] ?? null;
                $exam = $override['exam_override'] ?? null; $examScore = $override['exam_score_override'] ?? null; $examMax = $override['exam_max_score_override'] ?? null;
                $stmt->bind_param('sissssssddssddi', $courseId, $studentId, $start, $end, $sectionId, $attendance, $recording, $homework, $homeworkScore, $homeworkMax, $revision, $exam, $examScore, $examMax, $adminId);
                $stmt->execute();
            }
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback(); throw $exception;
    }
}

function mmh_parent_report_reset_override(mysqli $conn, string $courseId, int $studentId, string $start, string $end, ?string $sectionId = null): void
{
    $sql = 'DELETE FROM parent_weekly_report_overrides WHERE course_id = ? AND student_id = ? AND week_start = ? AND week_end = ?';
    if ($sectionId !== null) { $sql .= ' AND section_id = ?'; }
    $stmt = $conn->prepare($sql);
    if ($sectionId === null) { $stmt->bind_param('siss', $courseId, $studentId, $start, $end); }
    else { $stmt->bind_param('sisss', $courseId, $studentId, $start, $end, $sectionId); }
    $stmt->execute(); $stmt->close();
}

function mmh_parent_report_courses(mysqli $conn): array
{
    $result = $conn->query('SELECT course_id, course_title, course_status FROM courses ORDER BY course_status DESC, course_title ASC, course_id ASC');
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function mmh_parent_report_course(mysqli $conn, string $courseId): ?array
{
    $id = trim($courseId);
    if ($id === '') { return null; }
    $stmt = $conn->prepare('SELECT course_id, course_title FROM courses WHERE course_id = ? LIMIT 1');
    if (!$stmt) { return null; }
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $course = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $course;
}

function mmh_parent_report_current_week(): array
{
    $now = new DateTimeImmutable('now');
    $offset = ((int) $now->format('w') + 2) % 7; // Friday is the first day.
    $start = $now->setTime(0, 0)->modify('-' . $offset . ' days');
    return ['start' => $start->format('Y-m-d'), 'end' => $start->modify('+6 days')->format('Y-m-d')];
}

function mmh_parent_report_date($value): ?string
{
    $value = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

/** Shared reporting-period choices used by Parent Reports and student My Progress. */
function mmh_report_period_options(bool $allowCustom = false): array
{
    $options = [
        'current_week' => 'Current teaching week',
        'last_30_days' => 'Last 30 days',
        'course_to_date' => 'Course to date',
    ];
    if ($allowCustom) { $options['custom'] = 'Custom date range'; }
    return $options;
}

/** Resolve a period to the same inclusive date range for every report consumer. */
function mmh_report_period(mysqli $conn, string $courseId, int $studentId, string $period = 'current_week', ?string $customStart = null, ?string $customEnd = null, bool $allowCustom = false): array
{
    $period = strtolower(trim($period));
    if (!array_key_exists($period, mmh_report_period_options($allowCustom))) { $period = 'current_week'; }
    $today = new DateTimeImmutable('today');
    $range = match ($period) {
        'last_30_days' => ['start' => $today->modify('-29 days')->format('Y-m-d'), 'end' => $today->format('Y-m-d')],
        'course_to_date' => null,
        'custom' => ['start' => mmh_parent_report_date($customStart), 'end' => mmh_parent_report_date($customEnd)],
        default => mmh_parent_report_current_week(),
    };
    if ($period === 'course_to_date') {
        $start = null;
        $stmt = $conn->prepare('SELECT MIN(purchase_date) AS enrolled_at FROM course_logs WHERE course_id = ? AND user_id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $courseId, $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $start = mmh_parent_report_date(substr((string) ($row['enrolled_at'] ?? ''), 0, 10));
        }
        $range = ['start' => $start ?: $today->format('Y-m-d'), 'end' => $today->format('Y-m-d')];
    }
    if (!$range || empty($range['start']) || empty($range['end'])) { throw new InvalidArgumentException('Choose a valid report date range.'); }
    if ($range['start'] > $range['end']) { throw new InvalidArgumentException('The report start date must be before the end date.'); }
    return ['key' => $period, 'label' => mmh_report_period_options($allowCustom)[$period] ?? 'Current teaching week', 'start' => $range['start'], 'end' => $range['end']];
}

function mmh_parent_report_students(mysqli $conn, string $courseId): array
{
    $course = trim($courseId);
    if ($course === '') { return []; }
    $stmt = $conn->prepare('SELECT DISTINCT u.user_id, u.full_name, u.username FROM course_logs cl INNER JOIN users u ON u.user_id = cl.user_id WHERE cl.course_id = ? AND u.role = ? ORDER BY u.full_name ASC, u.username ASC');
    if (!$stmt) { return []; }
    $role = 'user';
    $stmt->bind_param('ss', $course, $role);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function mmh_parent_report_recording(array $item): bool
{
    $type = strtolower(trim((string) ($item['template_type'] ?? '')));
    if ($type === '') { $type = strtolower(trim((string) ($item['item_type'] ?? ''))); }
    $data = mmh_course_resource_template_data($item['template_data'] ?? '');
    $resourceType = strtolower(trim((string) ($data['resource_type'] ?? $data['resource']['type'] ?? '')));
    $provider = strtolower(trim((string) ($data['resource_provider'] ?? $data['resource']['provider'] ?? '')));
    $url = mmh_course_resource_safe_url($data['resource_url'] ?? $data['resource']['url'] ?? $data['url'] ?? '');
    return in_array($type, ['recording', 'video'], true)
        || in_array($resourceType, ['recording', 'video'], true)
        || in_array($provider, ['microsoft_stream', 'sharepoint'], true)
        || ($url !== null && mmh_course_resource_is_microsoft_stream_embed_url($url));
}

/** Prefer an existing explicit type; title matching is a narrow legacy fallback. */
function mmh_parent_report_workshop_section(array $section): bool
{
    $metadata = [];
    $rawMetadata = $section['metadata'] ?? '';
    if (is_string($rawMetadata) && trim($rawMetadata) !== '') {
        $decoded = json_decode($rawMetadata, true);
        if (is_array($decoded)) { $metadata = $decoded; }
    } elseif (is_array($rawMetadata)) {
        $metadata = $rawMetadata;
    }
    foreach ([$section['section_type'] ?? '', $section['custom_type'] ?? '', $metadata['section_type'] ?? '', $metadata['type'] ?? '', $metadata['section_kind'] ?? ''] as $value) {
        if (strtolower(trim((string) $value)) === 'workshop') { return true; }
    }
    return preg_match('/\bworkshop\b/i', (string) ($section['title'] ?? '')) === 1;
}

/** An unlinked Workshop has no occurrence date; retain the section's existing date precedence. */
function mmh_parent_report_workshop_date(array $section): string
{
    foreach (['release_at', 'unlock_at', 'created_at'] as $field) {
        $value = trim((string) ($section[$field] ?? ''));
        if ($value !== '') { return $value; }
    }
    return '';
}

function mmh_parent_report_exam_item(array $item): bool
{
    $type = strtolower(trim((string) ($item['template_type'] ?? '')));
    if ($type === '') { $type = strtolower(trim((string) ($item['item_type'] ?? ''))); }
    $data = mmh_course_resource_template_data($item['template_data'] ?? '');
    $resourceType = strtolower(trim((string) ($data['resource_type'] ?? $data['resource']['type'] ?? '')));
    return in_array($type, ['exam', 'classified_exam'], true) || $resourceType === 'exam';
}

function mmh_parent_report_exam_id(array $item): string
{
    $data = mmh_course_resource_template_data($item['template_data'] ?? '');
    return trim((string) ($data['exam_id'] ?? $data['exam']['id'] ?? ''));
}

function mmh_parent_report_exam_state(?array $submission): array
{
    if (!$submission) { return ['label' => 'Not Attempted', 'tone' => 'muted', 'submitted' => false, 'grade' => 'Grade not entered', 'submitted_at' => '', 'feedback' => '']; }
    $grade = trim((string) ($submission['grade'] ?? ''));
    $feedback = trim((string) ($submission['feedback'] ?? ''));
    if (mb_strlen($feedback) > 350) { $feedback = ''; }
    return ['label' => $grade === '' ? 'Submitted' : 'Graded', 'tone' => $grade === '' ? 'warning' : 'success', 'submitted' => true, 'grade' => $grade === '' ? 'Grade not entered' : $grade, 'submitted_at' => trim((string) ($submission['submitted_at'] ?? '')), 'feedback' => $feedback];
}

function mmh_parent_report_final_status(string $group, string $key): array
{
    $groups = [
        'attendance' => [
            'present' => ['Present', 'success'], 'late' => ['Late', 'warning'], 'absent' => ['Absent', 'danger'], 'excused' => ['Excused', 'info'], 'not_recorded' => ['Not Recorded', 'muted'],
        ],
        'recording' => [
            'viewed' => ['Viewed', 'success'], 'not_viewed' => ['Not Viewed', 'muted'], 'not_required' => ['Not Required', 'muted'], 'no_recording' => ['No Recording', 'muted'],
        ],
        'revision' => [
            'viewed' => ['Viewed', 'success'], 'not_viewed' => ['Not Viewed', 'muted'], 'no_video' => ['No Video', 'muted'],
        ],
        'homework' => [
            'submitted' => ['Submitted', 'success'], 'missing' => ['Missing', 'danger'], 'waiting_for_grade' => ['Waiting for Grade', 'warning'], 'no_homework' => ['No Homework', 'muted'],
        ],
        'exam' => [
            'completed' => ['Completed', 'success'], 'not_completed' => ['Not Completed', 'danger'], 'waiting_for_grade' => ['Waiting for Grade', 'warning'], 'no_exam' => ['No Exam', 'muted'],
        ],
    ];
    [$label, $tone] = $groups[$group][$key] ?? ['Not Available', 'muted'];
    return ['key' => $key, 'label' => $label, 'tone' => $tone];
}

function mmh_parent_report_grade_value(array $rows, string $kind): array
{
    foreach ($rows as $row) {
        $state = $row['state'] ?? [];
        $grade = trim((string) ($state['grade'] ?? ''));
        if ($grade === '' || $grade === 'Grade not entered') { continue; }
        if (preg_match('/^\s*(\d+(?:\.\d+)?)(?:\s*\/\s*(\d+(?:\.\d+)?))?\s*$/', $grade, $match)) {
            $max = $match[2] ?? '';
            if ($max === '' && $kind === 'homework') { $max = trim((string) ($row['assignment']['max_score'] ?? '')); }
            return ['score' => $match[1], 'max' => $max];
        }
    }
    return ['score' => '', 'max' => ''];
}

function mmh_parent_report_grade_label(array $grade): string
{
    $score = trim((string) ($grade['score'] ?? ''));
    $max = trim((string) ($grade['max'] ?? ''));
    if ($score === '') { return '—'; }
    $format = static fn(string $value): string => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : $value;
    return $max === '' ? $format($score) : $format($score) . ' / ' . $format($max);
}

/** Merges report-only teacher decisions after factual LMS collection; LMS rows are never updated. */
function mmh_parent_report_apply_overrides(array $report, array $overrides): array
{
    foreach ($report['sections'] as &$section) {
        $sectionId = (string) ($section['section_id'] ?? '');
        $override = $overrides[$sectionId] ?? [];
        $hasOverride = static fn(string $field): bool => isset($override[$field]) && $override[$field] !== '' && $override[$field] !== null;
        $workshop = !empty($section['is_workshop']);
        $manual = false;

        if (!$workshop) {
            $attendanceKey = (string) ($section['attendance']['key'] ?? 'not_recorded');
            if ($hasOverride('attendance_override')) { $attendanceKey = $override['attendance_override']; $manual = true; }
            $attendance = mmh_parent_report_final_status('attendance', $attendanceKey);
            $attendance['source'] = $hasOverride('attendance_override') ? 'override' : 'lms';

            if (!$section['recordings']) { $recordingKey = 'no_recording'; }
            elseif (in_array($attendanceKey, ['present', 'late'], true)) { $recordingKey = 'not_required'; }
            else { $recordingKey = array_reduce($section['recordings'], static fn(bool $opened, array $recording): bool => $opened || !empty($recording['opened']) || in_array(($recording['status_key'] ?? ''), ['opened', 'completed'], true) || $recording['label'] === 'Opened', false) ? 'viewed' : 'not_viewed'; }
            if ($hasOverride('recording_override')) { $recordingKey = $override['recording_override']; $manual = true; }
            $recording = mmh_parent_report_final_status('recording', $recordingKey);
            $recording['source'] = $hasOverride('recording_override') ? 'override' : 'lms';
            $revision = null;
        } else {
            $revisionKey = !$section['recordings'] ? 'no_video' : (array_reduce($section['recordings'], static fn(bool $opened, array $recording): bool => $opened || !empty($recording['opened']) || in_array(($recording['status_key'] ?? ''), ['opened', 'completed'], true) || $recording['label'] === 'Opened', false) ? 'viewed' : 'not_viewed');
            if ($hasOverride('revision_override')) { $revisionKey = $override['revision_override']; $manual = true; }
            $revision = mmh_parent_report_final_status('revision', $revisionKey);
            $revision['source'] = $hasOverride('revision_override') ? 'override' : 'lms';
            $attendance = $recording = null;
        }

        if (!$section['homework']) { $homeworkKey = 'no_homework'; }
        elseif (array_reduce($section['homework'], static fn(bool $missing, array $homework): bool => $missing || !$homework['state']['submitted'], false)) { $homeworkKey = 'missing'; }
        elseif (array_reduce($section['homework'], static fn(bool $graded, array $homework): bool => $graded || $homework['state']['grade'] !== 'Grade not entered', false)) { $homeworkKey = 'submitted'; }
        else { $homeworkKey = 'waiting_for_grade'; }
        if ($hasOverride('homework_override')) { $homeworkKey = $override['homework_override']; $manual = true; }
        $homework = mmh_parent_report_final_status('homework', $homeworkKey);
        $homework['source'] = $hasOverride('homework_override') ? 'override' : 'lms';
        $homeworkGrade = mmh_parent_report_grade_value($section['homework'], 'homework');
        if ($hasOverride('homework_score_override')) { $homeworkGrade['score'] = (string) $override['homework_score_override']; $manual = true; }
        if ($hasOverride('homework_max_score_override')) { $homeworkGrade['max'] = (string) $override['homework_max_score_override']; $manual = true; }
        $homeworkGrade['source'] = ($hasOverride('homework_score_override') || $hasOverride('homework_max_score_override')) ? 'override' : 'lms';

        $exam = null; $examGrade = ['score' => '', 'max' => ''];
        if ($workshop) {
            if (!$section['exams']) { $examKey = 'no_exam'; }
            elseif (array_reduce($section['exams'], static fn(bool $submitted, array $exam): bool => $submitted || !empty($exam['state']['submitted']), false)) { $examKey = array_reduce($section['exams'], static fn(bool $graded, array $exam): bool => $graded || $exam['state']['grade'] !== 'Grade not entered', false) ? 'completed' : 'waiting_for_grade'; }
            else { $examKey = 'not_completed'; }
            if ($hasOverride('exam_override')) { $examKey = $override['exam_override']; $manual = true; }
            $exam = mmh_parent_report_final_status('exam', $examKey);
            $exam['source'] = $hasOverride('exam_override') ? 'override' : 'lms';
            $examGrade = mmh_parent_report_grade_value($section['exams'], 'exam');
            if ($hasOverride('exam_score_override')) { $examGrade['score'] = (string) $override['exam_score_override']; $manual = true; }
            if ($hasOverride('exam_max_score_override')) { $examGrade['max'] = (string) $override['exam_max_score_override']; $manual = true; }
            $examGrade['source'] = ($hasOverride('exam_score_override') || $hasOverride('exam_max_score_override')) ? 'override' : 'lms';
        }
        $section['final'] = ['attendance' => $attendance, 'recording' => $recording, 'revision' => $revision, 'homework' => $homework, 'homework_grade' => $homeworkGrade, 'exam' => $exam, 'exam_grade' => $examGrade, 'manual' => $manual];
    }
    unset($section);
    return $report;
}

function mmh_parent_report_attendance($status): array
{
    return match (strtolower(trim((string) $status))) {
        'present_live' => ['label' => 'Present', 'tone' => 'success', 'key' => 'present'],
        'late' => ['label' => 'Late', 'tone' => 'warning', 'key' => 'late'],
        'absent' => ['label' => 'Absent', 'tone' => 'danger', 'key' => 'absent'],
        'excused' => ['label' => 'Excused', 'tone' => 'info', 'key' => 'excused'],
        default => ['label' => 'Not Recorded', 'tone' => 'muted', 'key' => 'not_recorded'],
    };
}

function mmh_parent_report_comment(mysqli $conn, string $courseId, int $studentId, string $start, string $end): string
{
    $course = trim($courseId);
    $stmt = $conn->prepare('SELECT teacher_comment FROM parent_weekly_report_comments WHERE course_id = ? AND student_id = ? AND week_start = ? AND week_end = ? LIMIT 1');
    if (!$stmt) { return ''; }
    $stmt->bind_param('siss', $course, $studentId, $start, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim((string) ($row['teacher_comment'] ?? ''));
}

function mmh_parent_report_save_comment(mysqli $conn, string $courseId, int $studentId, string $start, string $end, string $comment, ?int $adminId): bool
{
    mmh_parent_report_ensure_schema($conn);
    $course = trim($courseId);
    if ($course === '') { return false; }
    $comment = trim(mb_substr($comment, 0, 1000));
    $stmt = $conn->prepare('INSERT INTO parent_weekly_report_comments (course_id, student_id, week_start, week_end, teacher_comment, created_by) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE teacher_comment = VALUES(teacher_comment), created_by = VALUES(created_by), updated_at = NOW()');
    if (!$stmt) { return false; }
    $stmt->bind_param('sisssi', $course, $studentId, $start, $end, $comment, $adminId);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool) $ok;
}

function mmh_parent_report_submission_state(?array $submission, array $assignment): array
{
    if (!$submission) {
        return ['label' => 'Missing', 'tone' => 'danger', 'submitted' => false, 'late' => false, 'grade' => 'Grade not entered', 'imported' => false, 'submitted_at' => '', 'original_submitted_at' => '', 'feedback' => ''];
    }
    $source = strtolower(trim((string) ($submission['submission_source'] ?? 'lms')));
    $original = trim((string) ($submission['original_submitted_at'] ?? ''));
    $submittedAt = trim((string) ($submission['submitted_at'] ?? ''));
    $comparisonDate = $original !== '' ? $original : $submittedAt;
    $due = trim((string) ($assignment['due_date'] ?? ''));
    $late = $due !== '' && $comparisonDate !== '' && strtotime($comparisonDate) > strtotime($due);
    $grade = trim((string) ($submission['grade'] ?? ''));
    $max = trim((string) ($assignment['max_score'] ?? ''));
    $gradeLabel = $grade !== '' ? $grade . ($max !== '' ? ' / ' . $max : '') : 'Grade not entered';
    $feedback = trim((string) ($submission['feedback'] ?? ''));
    if (mb_strlen($feedback) > 350) { $feedback = ''; }
    if (!empty($submission['historical'])) { $label = 'Completed'; $tone = 'success'; }
    elseif ($grade !== '') { $label = 'Graded'; $tone = 'success'; }
    elseif ($late) { $label = 'Submitted Late'; $tone = 'warning'; }
    else { $label = 'Awaiting Grading'; $tone = 'warning'; }
    return ['label' => $label, 'tone' => $tone, 'submitted' => true, 'late' => $late, 'grade' => $gradeLabel, 'imported' => $source === 'legacy_import', 'submitted_at' => $submittedAt, 'original_submitted_at' => $original, 'feedback' => $feedback];
}

function mmh_parent_report_suggested_comment(array $report): string
{
    $sections = $report['sections'];
    if (!$sections) { return ''; }
    $present = $missing = $absentNoOpen = $liveSections = 0;
    foreach ($sections as $section) {
        if (empty($section['is_workshop'])) {
            $liveSections++;
            if (in_array($section['attendance']['key'], ['present', 'late'], true)) { $present++; }
        }
        foreach ($section['homework'] as $homework) { if (!$homework['state']['submitted']) { $missing++; } }
        if (empty($section['is_workshop']) && $section['attendance']['key'] === 'absent') {
            foreach ($section['recordings'] as $recording) { if ($recording['label'] === 'Not Opened') { $absentNoOpen++; } }
        }
    }
    if ($liveSections > 0 && $present === $liveSections && $missing === 0) { return 'Attended all recorded sessions and submitted all assigned homework.'; }
    if ($absentNoOpen > 0) { return 'Missed a live session and has not opened its recording yet.'; }
    if ($missing > 0) { return $missing . ' homework task' . ($missing === 1 ? ' is' : 's are') . ' still outstanding.'; }
    return '';
}

/** Shared section-progress report service. Parent Reports and My Progress both consume this. */
function mmh_report_resolve(mysqli $conn, string $courseId, int $studentId, string $start, string $end, ?string $comment = null): array
{
    mmh_parent_report_ensure_schema($conn);
    $course = mmh_parent_report_course($conn, $courseId);
    if (!$course) { throw new RuntimeException('The selected course is unavailable.'); }
    $start = mmh_parent_report_date($start) ?? throw new InvalidArgumentException('Choose a valid report start date.');
    $end = mmh_parent_report_date($end) ?? throw new InvalidArgumentException('Choose a valid report end date.');
    if ($start > $end) { throw new InvalidArgumentException('Choose a valid report date range.'); }

    $courseId = (string) $course['course_id'];
    $studentStmt = $conn->prepare('SELECT u.user_id, u.full_name FROM users u INNER JOIN course_logs cl ON cl.user_id = u.user_id WHERE u.user_id = ? AND cl.course_id = ? LIMIT 1');
    $studentStmt->bind_param('is', $studentId, $courseId);
    $studentStmt->execute(); $student = $studentStmt->get_result()->fetch_assoc(); $studentStmt->close();
    if (!$student) { throw new RuntimeException('The selected student is not enrolled in the selected course.'); }

    $from = $start . ' 00:00:00'; $to = date('Y-m-d H:i:s', strtotime($end . ' +1 day'));
    $sectionStmt = $conn->prepare("SELECT s.section_id, s.title, s.sort_order, s.release_occurrence_id, o.scheduled_start_at, o.scheduled_end_at, COALESCE(a.status, 'unknown') AS attendance_status FROM course_sections s INNER JOIN live_session_occurrences o ON o.occurrence_id = s.release_occurrence_id AND o.course_id = s.course_id LEFT JOIN live_session_attendance a ON a.occurrence_id = o.occurrence_id AND a.user_id = ? WHERE s.course_id = ? AND s.status = 'published' AND o.scheduled_start_at >= ? AND o.scheduled_start_at < ? AND o.scheduled_start_at <= NOW() ORDER BY o.scheduled_start_at ASC, s.sort_order ASC");
    $sectionStmt->bind_param('isss', $studentId, $courseId, $from, $to); $sectionStmt->execute(); $sections = $sectionStmt->get_result()->fetch_all(MYSQLI_ASSOC); $sectionStmt->close();

    // Workshops deliberately have no live occurrence. Include only explicit/narrowly-matched
    // Workshops whose existing release, unlock, or creation date lies in the report window.
    $workshopStmt = $conn->prepare('SELECT section_id, title, sort_order, section_type, custom_type, metadata, release_at, unlock_at, created_at FROM course_sections WHERE course_id = ? AND status = \'published\' AND (release_occurrence_id IS NULL OR release_occurrence_id = \'\')');
    $workshopStmt->bind_param('s', $courseId); $workshopStmt->execute(); $workshopCandidates = $workshopStmt->get_result()->fetch_all(MYSQLI_ASSOC); $workshopStmt->close();
    foreach ($workshopCandidates as $workshop) {
        $workshopDate = mmh_parent_report_workshop_date($workshop);
        if (!mmh_parent_report_workshop_section($workshop) || $workshopDate === '' || $workshopDate < $from || $workshopDate >= $to || strtotime($workshopDate) > time()) { continue; }
        $workshop['scheduled_start_at'] = $workshopDate;
        $workshop['scheduled_end_at'] = '';
        $workshop['attendance_status'] = 'unknown';
        $workshop['is_workshop'] = true;
        $sections[] = $workshop;
    }
    foreach ($sections as &$section) { $section['is_workshop'] = !empty($section['is_workshop']); }
    unset($section);
    usort($sections, static fn(array $a, array $b): int => [strtotime((string) $a['scheduled_start_at']), (int) $a['sort_order']] <=> [strtotime((string) $b['scheduled_start_at']), (int) $b['sort_order']]);
    $sectionIds = array_column($sections, 'section_id'); $sectionSet = array_fill_keys($sectionIds, true);
    $sectionsById = []; foreach ($sections as $section) { $sectionsById[(string) $section['section_id']] = $section; }

    $journeyEvidence = mmh_learning_journey_load_evidence($conn, $studentId, $courseId);
    $journeyItemsById = [];
    foreach (mmh_learning_journey_item_records($conn, $studentId, $courseId) as $journeyItem) { $journeyItemsById[(string) ($journeyItem['item_id'] ?? '')] = $journeyItem; }
    foreach ($sections as &$resolvedSection) {
        $occurrenceId = trim((string) ($resolvedSection['release_occurrence_id'] ?? ''));
        $historicalAttendance = $occurrenceId !== '' ? ($journeyEvidence[mmh_learning_journey_entity_key('live_session', '', '', $occurrenceId)] ?? null) : null;
        if ($historicalAttendance && in_array((string) ($historicalAttendance['state'] ?? ''), ['present', 'attended', 'completed'], true) && strtolower((string) ($resolvedSection['attendance_status'] ?? 'unknown')) === 'unknown') {
            $resolvedSection['attendance_status'] = 'present_live';
        }
    }
    unset($resolvedSection);
    $itemStmt = $conn->prepare("SELECT item_id, item_title, section_id, item_type, template_type, template_data FROM course_items WHERE course_id = ? AND status = 'published'");
    $itemStmt->bind_param('s', $courseId); $itemStmt->execute(); $allItems = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC); $itemStmt->close();
    $recordingsBySection = [];
    foreach ($allItems as $item) {
        $itemSection = trim((string) ($item['section_id'] ?? ''));
        $workshopVideo = !empty($sectionsById[$itemSection]['is_workshop']) && in_array(strtolower(trim((string) ($item['item_type'] ?? ''))), ['recording', 'video'], true);
        if ($itemSection !== '' && isset($sectionSet[$itemSection]) && (mmh_parent_report_recording($item) || $workshopVideo)) {
            $recordingsBySection[$itemSection][] = $item;
        }
    }
    $examsBySection = [];
    foreach ($allItems as $item) {
        $itemSection = trim((string) ($item['section_id'] ?? ''));
        if ($itemSection !== '' && isset($sectionSet[$itemSection]) && mmh_parent_report_exam_item($item)) {
            $examsBySection[$itemSection][] = $item;
        }
    }

    $eventStmt = $conn->prepare('SELECT item_id, event_type, MAX(created_at) AS event_at FROM learning_events WHERE user_id = ? AND course_id = ? AND event_type IN (?, ?) AND created_at >= ? AND created_at < ? GROUP BY item_id, event_type');
    $opened = []; $completed = [];
    if ($eventStmt) {
        $startedType = 'recording_started'; $completedType = 'recording_completed';
        $eventStmt->bind_param('isssss', $studentId, $courseId, $startedType, $completedType, $from, $to); $eventStmt->execute();
        foreach ($eventStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $itemKey = (string) ($row['item_id'] ?? '');
            if ($itemKey === '') { continue; }
            if ((string) $row['event_type'] === $completedType) { $completed[$itemKey] = (string) $row['event_at']; }
            else { $opened[$itemKey] = (string) $row['event_at']; }
        }
        $eventStmt->close();
    }
    $progressStmt = $conn->prepare('SELECT item_id, MAX(last_viewed_at) AS viewed_at FROM course_item_progress WHERE user_id = ? AND course_id = ? AND last_viewed_at >= ? AND last_viewed_at < ? GROUP BY item_id');
    if ($progressStmt) {
        $progressStmt->bind_param('isss', $studentId, $courseId, $from, $to); $progressStmt->execute();
        foreach ($progressStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $itemKey = (string) ($row['item_id'] ?? '');
            if ($itemKey !== '' && !isset($opened[$itemKey])) { $opened[$itemKey] = (string) $row['viewed_at']; }
        }
        $progressStmt->close();
    }
    foreach ($journeyEvidence as $historical) {
        if (($historical['item_kind'] ?? '') !== 'recording' || ($historical['state'] ?? '') !== 'watched') { continue; }
        $itemKey = trim((string) ($historical['item_id'] ?? ''));
        if ($itemKey !== '' && !isset($opened[$itemKey])) { $opened[$itemKey] = ''; }
    }

    $assignmentStmt = $conn->prepare("SELECT a.*, i.section_id AS item_section_id FROM assignments a LEFT JOIN course_items i ON i.course_id = a.course_id AND i.item_id = a.item_id WHERE a.course_id = ? AND (a.item_id IS NULL OR a.item_id = '' OR i.status = 'published') ORDER BY a.due_date ASC, a.id ASC");
    $assignmentStmt->bind_param('s', $courseId); $assignmentStmt->execute(); $allAssignments = $assignmentStmt->get_result()->fetch_all(MYSQLI_ASSOC); $assignmentStmt->close();
    $submissionStmt = $conn->prepare('SELECT s.* FROM assignment_submissions s INNER JOIN assignments a ON a.assignment_id = s.assignment_id WHERE s.student_id = ? AND a.course_id = ? ORDER BY s.submitted_at DESC, s.id DESC');
    $submissionStmt->bind_param('is', $studentId, $courseId); $submissionStmt->execute(); $submissionRows = $submissionStmt->get_result()->fetch_all(MYSQLI_ASSOC); $submissionStmt->close();
    $submissions = []; foreach ($submissionRows as $row) { $submissions[(string) $row['assignment_id']] ??= $row; }

    $examSubmissionStmt = $conn->prepare('SELECT es.* FROM exam_submissions es INNER JOIN exams e ON e.exam_id = es.exam_id WHERE es.student_id = ? AND e.course_id = ? ORDER BY es.submitted_at DESC, es.id DESC');
    $examSubmissionStmt->bind_param('is', $studentId, $courseId); $examSubmissionStmt->execute(); $examSubmissionRows = $examSubmissionStmt->get_result()->fetch_all(MYSQLI_ASSOC); $examSubmissionStmt->close();
    $examSubmissions = []; foreach ($examSubmissionRows as $row) { $examSubmissions[(string) $row['exam_id']] ??= $row; }

    $sectionMetaStmt = $conn->prepare('SELECT section_id, title, sort_order, section_type, custom_type, metadata, release_occurrence_id FROM course_sections WHERE course_id = ?');
    $sectionMetaStmt->bind_param('s', $courseId); $sectionMetaStmt->execute(); $allSections = $sectionMetaStmt->get_result()->fetch_all(MYSQLI_ASSOC); $sectionMetaStmt->close();
    $sectionMeta = []; foreach ($allSections as $row) { $sectionMeta[(string) $row['section_id']] = $row; }
    $homeworkBySection = []; $unresolvedHomework = 0;
    foreach ($allAssignments as $assignment) {
        $dueDate = trim((string) ($assignment['due_date'] ?? ''));
        if ($dueDate !== '' && strtotime($dueDate) !== false && strtotime($dueDate) > time()) { continue; }
        $assignmentSection = trim((string) ($assignment['section_id'] ?? '')) ?: trim((string) ($assignment['item_section_id'] ?? ''));
        if ($assignmentSection === '') { $unresolvedHomework++; continue; }
        if (isset($sectionSet[$assignmentSection])) {
            $assignmentKey = mmh_learning_journey_entity_key('homework', '', (string) $assignment['assignment_id']);
            $historical = $journeyEvidence[$assignmentKey] ?? null;
            $stateSource = $submissions[(string) $assignment['assignment_id']] ?? null;
            if (!$stateSource && $historical && in_array((string) ($historical['state'] ?? ''), ['completed', 'submitted'], true)) {
                $stateSource = ['historical' => true, 'submission_source' => (string) ($historical['source'] ?? 'manual'), 'submitted_at' => ''];
            }
            $homeworkBySection[$assignmentSection][] = ['assignment' => $assignment, 'state' => mmh_parent_report_submission_state($stateSource, $assignment)];
        }
    }

    $weekly = [];
    foreach ($sections as $section) {
        $attendance = mmh_parent_report_attendance($section['attendance_status']); $recordingRows = [];
        foreach ($recordingsBySection[$section['section_id']] ?? [] as $recording) {
            $recordingId = (string) $recording['item_id']; $journeyRecording = $journeyItemsById[$recordingId] ?? [];
            $completedState = !empty($journeyRecording['is_completed']) || isset($completed[$recordingId]);
            $openedState = isset($opened[$recordingId]) || $completedState;
            if (empty($section['is_workshop']) && in_array($attendance['key'], ['present', 'late'], true)) { $label = 'Not Required'; $tone = 'muted'; $statusKey = 'not_required'; }
            elseif ($completedState || $openedState) { $label = 'Opened'; $tone = 'success'; $statusKey = $completedState ? 'completed' : 'opened'; }
            else { $label = 'Not Opened'; $tone = 'muted'; $statusKey = 'not_viewed'; }
            $recordingRows[] = ['item_id' => $recordingId, 'title' => $recording['item_title'], 'label' => $label, 'tone' => $tone, 'status_key' => $statusKey, 'opened' => $openedState || $completedState, 'completed' => $completedState, 'opened_at' => $opened[$recordingId] ?? ($completed[$recordingId] ?? '')];
        }
        $examRows = [];
        foreach ($examsBySection[$section['section_id']] ?? [] as $exam) {
            $examRows[] = ['title' => $exam['item_title'], 'state' => mmh_parent_report_exam_state($examSubmissions[mmh_parent_report_exam_id($exam)] ?? null)];
        }
        $weekly[] = ['section_id' => (string) $section['section_id'], 'title' => $section['title'], 'date' => $section['scheduled_start_at'], 'is_workshop' => !empty($section['is_workshop']), 'attendance' => $attendance, 'recordings' => $recordingRows, 'homework' => $homeworkBySection[$section['section_id']] ?? [], 'exams' => $examRows];
    }
    $minOrder = $sections ? min(array_map(static fn($s) => (int) $s['sort_order'], $sections)) : null; $outstanding = [];
    if ($minOrder !== null) foreach ($allAssignments as $assignment) {
        $dueDate = trim((string) ($assignment['due_date'] ?? ''));
        if ($dueDate !== '' && strtotime($dueDate) !== false && strtotime($dueDate) > time()) { continue; }
        $sectionId = trim((string) ($assignment['section_id'] ?? '')) ?: trim((string) ($assignment['item_section_id'] ?? ''));
        if ($sectionId === '' || !isset($sectionMeta[$sectionId]) || (int) $sectionMeta[$sectionId]['sort_order'] >= $minOrder || isset($submissions[(string) $assignment['assignment_id']])) { continue; }
        $outstanding[] = ['title' => $assignment['assignment_title'], 'section_title' => $sectionMeta[$sectionId]['title'], 'due_date' => (string) ($assignment['due_date'] ?? '')];
    }
    $unresolvedRecordings = 0; foreach ($allItems as $item) { if (mmh_parent_report_recording($item) && trim((string) ($item['section_id'] ?? '')) === '') { $unresolvedRecordings++; } }
    $unlinkedSections = 0; foreach ($allSections as $section) { if (trim((string) ($section['release_occurrence_id'] ?? '')) === '' && !mmh_parent_report_workshop_section($section)) { $unlinkedSections++; } }
    $report = ['course' => $course, 'student' => $student, 'start' => $start, 'end' => $end, 'generated_at' => date('Y-m-d H:i:s'), 'sections' => $weekly, 'outstanding' => $outstanding, 'unresolved' => ['sections' => $unlinkedSections, 'homework' => $unresolvedHomework, 'recordings' => $unresolvedRecordings], 'learning_journey' => mmh_learning_journey_resolve($conn, $studentId, $courseId), 'comment' => $comment !== null ? trim(mb_substr($comment, 0, 1000)) : mmh_parent_report_comment($conn, $courseId, $studentId, $start, $end)];
    $report['suggested_comment'] = mmh_parent_report_suggested_comment($report);
    return $report;
}

/** Backward-compatible Parent Report entry point. */
function mmh_parent_report_resolve(mysqli $conn, string $courseId, int $studentId, string $start, string $end, ?string $comment = null): array
{
    return mmh_report_resolve($conn, $courseId, $studentId, $start, $end, $comment);
}

/**
 * Student-facing, presentation-neutral summary derived from the shared report.
 * It deliberately says Recording Opened/Not Viewed because completion evidence
 * is not currently reliable. If recording_completed events exist later, the
 * same structure automatically exposes Recording Completed.
 */
function mmh_report_student_summary(array $report): array
{
    $sections = []; $weak = []; $liveTotal = $liveAttended = $liveAbsent = 0;
    $homeworkTotal = $homeworkSubmitted = $homeworkMissing = $homeworkAwaiting = $homeworkGraded = 0;
    $recordingTotal = $recordingOpened = $recordingCompleted = $recordingNotViewed = 0;
    $gradeTotal = 0.0; $gradeCount = 0;
    foreach ($report['sections'] ?? [] as $section) {
        $sectionId = (string) ($section['section_id'] ?? ''); $workshop = !empty($section['is_workshop']);
        $attendanceKey = (string) ($section['attendance']['key'] ?? 'not_recorded');
        $attendanceStatus = $workshop ? null : (in_array($attendanceKey, ['present', 'late'], true) ? ['key' => 'attended_live', 'label' => 'Attended Live'] : ['key' => $attendanceKey, 'label' => $section['attendance']['label'] ?? 'Not Recorded']);
        if (!$workshop) { $liveTotal++; if ($attendanceKey === 'present' || $attendanceKey === 'late') { $liveAttended++; } if ($attendanceKey === 'absent') { $liveAbsent++; } }

        $recordingRows = [];
        foreach ($section['recordings'] ?? [] as $recording) {
            $statusKey = (string) ($recording['status_key'] ?? (empty($recording['opened']) ? 'not_viewed' : 'opened'));
            if ($statusKey === 'not_required') { $status = ['key' => 'not_required', 'label' => 'Not Required']; }
            elseif ($statusKey === 'completed') { $status = ['key' => 'completed', 'label' => 'Recording Completed']; $recordingCompleted++; }
            elseif ($statusKey === 'opened') { $status = ['key' => 'opened', 'label' => 'Recording Opened']; $recordingOpened++; }
            else { $status = ['key' => 'not_viewed', 'label' => 'Not Viewed']; $recordingNotViewed++; }
            if ($statusKey !== 'not_required') { $recordingTotal++; }
            $recordingRows[] = ['item_id' => (string) ($recording['item_id'] ?? ''), 'title' => (string) ($recording['title'] ?? ''), 'status' => $status, 'opened_at' => (string) ($recording['opened_at'] ?? '')];
            if ($statusKey === 'not_viewed' && !$workshop && !in_array($attendanceKey, ['present', 'late'], true)) {
                $weak[$sectionId] ??= ['section_id' => $sectionId, 'section_title' => (string) $section['title'], 'reasons' => [], 'actions' => []];
                $weak[$sectionId]['reasons'][] = 'Recording not viewed';
                if ((string) ($recording['item_id'] ?? '') !== '') { $weak[$sectionId]['actions'][] = ['type' => 'recording', 'item_id' => (string) $recording['item_id'], 'label' => 'Continue Recording']; }
            }
        }
        $homeworkRows = [];
        foreach ($section['homework'] ?? [] as $homework) {
            $state = $homework['state'] ?? []; $submitted = !empty($state['submitted']); $grade = trim((string) ($state['grade'] ?? ''));
            $homeworkTotal++;
            if (!$submitted) { $status = ['key' => 'missing', 'label' => 'Missing']; $homeworkMissing++; }
            elseif ($grade !== '' && $grade !== 'Grade not entered') { $status = ['key' => 'graded', 'label' => 'Graded']; $homeworkGraded++; }
            else { $status = ['key' => 'awaiting_grading', 'label' => 'Awaiting Grading']; $homeworkSubmitted++; $homeworkAwaiting++; }
            if ($submitted && $status['key'] === 'graded') { $homeworkSubmitted++; }
            $assignment = $homework['assignment'] ?? [];
            $homeworkRows[] = ['assignment_id' => (string) ($assignment['assignment_id'] ?? ''), 'item_id' => (string) ($assignment['item_id'] ?? ''), 'title' => (string) ($assignment['assignment_title'] ?? ''), 'status' => $status, 'submitted_at' => (string) ($state['submitted_at'] ?? ''), 'grade' => $grade === '' || $grade === 'Grade not entered' ? '' : $grade, 'feedback' => (string) ($state['feedback'] ?? '')];
            if (!$submitted) {
                $weak[$sectionId] ??= ['section_id' => $sectionId, 'section_title' => (string) $section['title'], 'reasons' => [], 'actions' => []];
                $weak[$sectionId]['reasons'][] = 'Homework missing';
                if ((string) ($assignment['item_id'] ?? '') !== '') { $weak[$sectionId]['actions'][] = ['type' => 'homework', 'item_id' => (string) $assignment['item_id'], 'label' => 'Open Homework']; }
            }
            if ($grade !== '' && $grade !== 'Grade not entered' && preg_match('/^\s*(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)/', $grade, $match) && (float) $match[2] > 0) { $gradeTotal += ((float) $match[1] / (float) $match[2]) * 10; $gradeCount++; }
        }
        if (!$workshop && $attendanceKey === 'absent') {
            $weak[$sectionId] ??= ['section_id' => $sectionId, 'section_title' => (string) $section['title'], 'reasons' => [], 'actions' => []];
            $weak[$sectionId]['reasons'][] = 'Live session missed';
        }
        $sections[] = ['section_id' => $sectionId, 'title' => (string) $section['title'], 'date' => (string) ($section['date'] ?? ''), 'is_workshop' => $workshop, 'attendance' => $attendanceStatus, 'recordings' => $recordingRows, 'homework' => $homeworkRows];
    }
    foreach ($weak as &$entry) {
        $uniqueReasons = []; foreach ($entry['reasons'] as $reason) { $uniqueReasons[$reason] = true; } $entry['reasons'] = array_keys($uniqueReasons);
        $uniqueActions = []; foreach ($entry['actions'] as $action) { $uniqueActions[$action['type'] . ':' . $action['item_id']] = $action; } $entry['actions'] = array_values($uniqueActions);
    }
    unset($entry);
    $periodDays = max(1, (int) ((strtotime((string) $report['end']) - strtotime((string) $report['start'])) / 86400) + 1);
    $summary = ['period' => ['start' => (string) $report['start'], 'end' => (string) $report['end'], 'days' => $periodDays], 'sections' => $sections, 'counts' => ['live_total' => $liveTotal, 'live_attended' => $liveAttended, 'live_absent' => $liveAbsent, 'homework_total' => $homeworkTotal, 'homework_submitted' => $homeworkSubmitted, 'homework_missing' => $homeworkMissing, 'homework_awaiting_grading' => $homeworkAwaiting, 'homework_graded' => $homeworkGraded, 'recording_total' => $recordingTotal, 'recording_opened' => $recordingOpened, 'recording_completed' => $recordingCompleted, 'recording_not_viewed' => $recordingNotViewed, 'average_grade' => $gradeCount ? round($gradeTotal / $gradeCount, 1) : null], 'weak_points' => array_values($weak)];
    $summary['learning_journey'] = $report['learning_journey'] ?? [];
    return $summary;
}

function mmh_parent_report_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function mmh_parent_report_date_label($value): string { return $value === '' ? '' : (new DateTimeImmutable($value))->format('j M Y'); }

function mmh_parent_report_text(array $report): string
{
    if (isset($report['sections'][0]) && !isset($report['sections'][0]['final'])) { $report = mmh_parent_report_apply_overrides($report, []); }
    $lines = ['Math Mastery Hub', 'Weekly Report — ' . $report['student']['full_name'], mmh_parent_report_date_label($report['start']) . '–' . mmh_parent_report_date_label($report['end']), ''];
    foreach ($report['sections'] as $section) {
        $final = $section['final'];
        $lines[] = ($section['is_workshop'] ?? false ? 'Workshop — ' : '') . $section['title'] . ':';
        if (empty($section['is_workshop'])) { $lines[] = 'Live Session: ' . $final['attendance']['label']; $lines[] = 'Recording: ' . $final['recording']['label']; }
        else { $lines[] = 'Revision Video: ' . $final['revision']['label']; }
        $lines[] = 'Homework: ' . $final['homework']['label'];
        $lines[] = 'Grade: ' . mmh_parent_report_grade_label($final['homework_grade']);
        if (!empty($section['is_workshop'])) { $lines[] = 'Exam: ' . $final['exam']['label']; $lines[] = 'Exam Grade: ' . mmh_parent_report_grade_label($final['exam_grade']); }
        $lines[] = '';
    }
    $lines[] = 'Previous outstanding homework: ' . count($report['outstanding']);
    if ($report['comment'] !== '') { $lines[] = ''; $lines[] = 'Teacher comment:'; $lines[] = $report['comment']; }
    return implode("\n", $lines);
}

function mmh_parent_report_logo_data_uri(): string
{
    static $logo = null;
    if ($logo !== null) { return $logo; }
    $path = dirname(__DIR__) . '/resources/images/branding/mathhub-logo-white.png';
    $logo = is_readable($path) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)) : '';
    return $logo;
}

function mmh_parent_report_status_icon(string $label): string
{
    return match ($label) {
        'Present', 'Opened', 'Viewed', 'Submitted', 'Graded', 'Completed' => '✓',
        'Late', 'Submitted Late', 'Awaiting Grading', 'Waiting for Grade' => '!',
        'Absent', 'Missing', 'Not Completed' => '×',
        'Excused' => 'i',
        'Not Recorded', 'Not Opened', 'Not Viewed', 'Not Attempted' => '○',
        'Not Required', 'No recording', 'No Recording', 'No revision video', 'No Video', 'No homework assigned', 'No Homework', 'No Exam', 'Not Available', 'Grade not entered' => '—',
        default => '•',
    };
}

function mmh_parent_report_status_html(string $label, string $tone): string
{
    $e = 'mmh_parent_report_escape';
    return '<span class="status ' . $e($tone) . '" aria-label="' . $e($label) . '"><span class="status-icon" aria-hidden="true">' . $e(mmh_parent_report_status_icon($label)) . '</span>' . $e($label) . '</span>';
}

/** Presentation-only summary calculated from the already resolved report data. */
function mmh_parent_report_presentation(array $report): array
{
    if (isset($report['sections'][0]) && !isset($report['sections'][0]['final'])) { $report = mmh_parent_report_apply_overrides($report, []); }
    $sections = $report['sections'];
    $sessionTotal = 0;
    $attended = $homeworkTotal = $homeworkSubmitted = $outstanding = $issues = 0;
    $gradeTotal = $gradeCount = 0.0;

    foreach ($sections as $section) {
        $final = $section['final'];
        if (empty($section['is_workshop'])) {
            $sessionTotal++;
            if (in_array($final['attendance']['key'], ['present', 'late'], true)) { $attended++; }
            else { $issues++; }
        }
        if ($final['homework']['key'] !== 'no_homework') {
            $homeworkTotal++;
            if ($final['homework']['key'] === 'submitted') { $homeworkSubmitted++; } elseif ($final['homework']['key'] === 'missing') { $issues++; $outstanding++; }
            $score = $final['homework_grade']['score']; $max = $final['homework_grade']['max'];
            if ($score !== '' && $max !== '' && is_numeric($score) && is_numeric($max) && (float) $max > 0) { $gradeTotal += ((float) $score / (float) $max) * 10; $gradeCount++; }
        }
    }

    $allSessionsAttended = $sessionTotal > 0 && $attended === $sessionTotal;
    $allHomeworkSubmitted = $homeworkTotal === 0 || $homeworkSubmitted === $homeworkTotal;
    if ($sessionTotal === 0) { $overall = ['label' => 'Not Available', 'tone' => 'muted']; }
    elseif ($allSessionsAttended && $allHomeworkSubmitted) { $overall = ['label' => 'Excellent Week', 'tone' => 'success']; }
    elseif ($issues <= 2) { $overall = ['label' => 'Needs Attention', 'tone' => 'warning']; }
    else { $overall = ['label' => 'Immediate Follow-up Recommended', 'tone' => 'danger']; }

    $studentSummary = mmh_report_student_summary($report);
    $overallProgress = $studentSummary['learning_journey']['percentage'] ?? null;
    return [
        'overall' => $overall,
        'live' => $sessionTotal ? $attended . ' / ' . $sessionTotal : 'Not Available',
        'homework' => $homeworkTotal ? $homeworkSubmitted . ' / ' . $homeworkTotal : 'Not Available',
        'outstanding' => (string) $outstanding,
        'average_grade' => $gradeCount > 0 ? number_format($gradeTotal / $gradeCount, 1) . ' / 10' : 'Not Available',
        'overall_progress' => $overallProgress === null ? 'Not Available' : number_format((float) $overallProgress, 1) . '%',
    ];
}

function mmh_parent_report_html(array $report, bool $adminPreview = false): string
{
    if (isset($report['sections'][0]) && !isset($report['sections'][0]['final'])) { $report = mmh_parent_report_apply_overrides($report, []); }
    $e = 'mmh_parent_report_escape'; $summary = mmh_parent_report_presentation($report); $logo = mmh_parent_report_logo_data_uri();
    $brand = $logo !== '' ? '<img class="parent-report-logo" src="' . $e($logo) . '" alt="Math Mastery Hub">' : '<span class="parent-report-brand-text">Math Mastery Hub</span>';
    $html = '<section class="parent-report-sheet"><header class="parent-report-header"><div class="parent-report-header-top">' . $brand . '<div class="parent-report-header-title"><h1>Parent Weekly Report</h1>' . mmh_parent_report_status_html($summary['overall']['label'], $summary['overall']['tone']) . '</div></div><div class="parent-report-meta"><div><span>Student</span>&nbsp;<b>' . $e($report['student']['full_name']) . '</b></div><div><span>Course</span>&nbsp;<b>' . $e($report['course']['course_title']) . '</b></div><div><span>Week</span>&nbsp;<b>' . $e(mmh_parent_report_date_label($report['start']) . ' – ' . mmh_parent_report_date_label($report['end'])) . '</b></div><div><span>Generated</span>&nbsp;<b>' . $e(mmh_parent_report_date_label($report['generated_at'])) . '</b></div></div></header><section class="parent-report-summary" aria-label="Weekly overview"><div class="summary-card"><span>Live Sessions Attended</span><br><b>' . $e($summary['live']) . '</b></div><div class="summary-card"><span>Homework Submitted</span><br><b>' . $e($summary['homework']) . '</b></div><div class="summary-card"><span>Outstanding Homework</span><br><b>' . $e($summary['outstanding']) . '</b></div><div class="summary-card"><span>Average Grade</span><br><b>' . $e($summary['average_grade']) . '</b></div><div class="summary-card"><span>Overall Progress</span><br><b>' . $e($summary['overall_progress']) . '</b></div></section>';
    if (!$report['sections']) { $html .= '<div class="parent-report-empty">No live or Workshop Sections are available in this selected period.</div>'; }
    foreach ($report['sections'] as $section) {
        $workshop = !empty($section['is_workshop']); $final = $section['final'];
        $source = $adminPreview ? '<span class="parent-report-source ' . ($final['manual'] ? 'is-manual' : '') . '">' . ($final['manual'] ? 'Manually adjusted' : 'From LMS') . '</span>' : '';
        $html .= '<article class="parent-report-section' . ($workshop ? ' parent-report-workshop' : '') . '"><header><div>' . ($workshop ? '<span class="parent-report-workshop-label">Workshop</span>' : '') . '<h2>' . $e($section['title']) . '</h2></div>' . $source . '</header>';
        if (!$workshop) { $html .= '<div class="parent-report-row"><span class="parent-report-label">Live Session</span><div>' . mmh_parent_report_status_html($final['attendance']['label'], $final['attendance']['tone']) . '</div></div><div class="parent-report-row"><span class="parent-report-label">Recording</span><div>' . mmh_parent_report_status_html($final['recording']['label'], $final['recording']['tone']) . '</div></div>'; }
        else { $html .= '<div class="parent-report-row"><span class="parent-report-label">Revision Video</span><div>' . mmh_parent_report_status_html($final['revision']['label'], $final['revision']['tone']) . '</div></div>'; }
        $html .= '<div class="parent-report-row"><span class="parent-report-label">Homework</span><div>' . mmh_parent_report_status_html($final['homework']['label'], $final['homework']['tone']) . '</div></div><div class="parent-report-row"><span class="parent-report-label">Grade</span><div class="parent-report-grade">' . $e(mmh_parent_report_grade_label($final['homework_grade'])) . '</div></div>';
        if ($workshop) { $html .= '<div class="parent-report-row"><span class="parent-report-label">Exam</span><div>' . mmh_parent_report_status_html($final['exam']['label'], $final['exam']['tone']) . '</div></div><div class="parent-report-row"><span class="parent-report-label">Exam Grade</span><div class="parent-report-grade">' . $e(mmh_parent_report_grade_label($final['exam_grade'])) . '</div></div>'; }
        $html .= '</article>';
    }
    if ($report['comment'] !== '') { $html .= '<section class="parent-report-comment"><h2>Teacher Comment</h2><p>' . nl2br($e($report['comment'])) . '</p></section>'; }
    return $html . '</section>';
}

function mmh_parent_report_pdf(array $report): string
{
    if (!class_exists('Mpdf\\Mpdf')) { throw new RuntimeException('The PDF library is not installed.'); }
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 12]);
    $mpdf->SetTitle('Parent Weekly Report — ' . $report['student']['full_name']);
    $mpdf->WriteHTML('<style>body{font-family:sans-serif;font-size:9pt;color:#263238}.parent-report-sheet{max-width:100%}.parent-report-header{background:#006061;color:#fff;padding:10px}.parent-report-logo{height:23px;width:auto;margin:0 0 4px}.parent-report-brand-text{display:block;font-size:13pt;font-weight:bold;margin:0 0 4px}.parent-report-header-title h1{font-size:17pt;margin:0 0 4px;color:#fff}.parent-report-header-title .status{margin-bottom:5px}.parent-report-meta{border-top:1px solid #4b9293;padding-top:6px}.parent-report-meta div{display:block;margin:2px 0}.parent-report-meta span{display:inline-block;width:58px;font-size:8pt;opacity:.82}.parent-report-meta b{margin-left:3px}.parent-report-summary{width:100%;padding:7px 0;overflow:hidden}.summary-card{float:left;width:17.5%;padding:6px;border:1px solid #d9e2e2;margin-right:1%;min-height:31px}.summary-card span{display:block;font-size:7.5pt;color:#596467}.summary-card b{display:block;margin-top:2px;font-size:11pt;color:#006061}.parent-report-section,.parent-report-outstanding,.parent-report-comment{border:1px solid #d9e2e2;padding:8px;margin:7px 0;page-break-inside:avoid}.parent-report-section h2,.parent-report-outstanding h2,.parent-report-comment h2{font-size:12pt;margin:0;color:#263238}.parent-report-workshop{border-left:4px solid #fbab33;background:#fffaf2}.parent-report-workshop-label{display:block;margin:0 0 3px;font-size:8pt;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#9a6100}.parent-report-date{color:#666768;margin:2px 0 6px}.parent-report-row{border-top:1px solid #edf1f1;padding:5px 0;clear:both}.parent-report-label{float:left;width:92px;font-weight:bold}.parent-report-row>div{margin-left:96px}.parent-report-resource{margin-bottom:3px}.parent-report-resource b{margin-right:5px}.status{display:inline-block;padding:2px 5px;border-radius:3px;font-size:8pt;margin:0 2px 2px 0}.status-icon{font-weight:bold;margin-right:3px}.success{background:#e6f3ea;color:#155c31}.warning{background:#fff3dc;color:#805600}.danger{background:#fbe7e7;color:#9a2323}.info{background:#e2f0f2;color:#006061}.muted{background:#eef1f1;color:#596467}.parent-report-homework{margin:4px 0 7px}.parent-report-homework small{display:block;color:#596467;margin:2px 0}.grade-icon{color:#fbab33}.parent-report-empty{padding:9px;background:#eef1f1;margin:8px 0}.parent-report-outstanding.is-clear{background:#f5faf6;border-color:#cfe5d5}.parent-report-comment{background:#fff8ed;border-left:4px solid #fbab33}</style>' . mmh_parent_report_html($report));
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}
