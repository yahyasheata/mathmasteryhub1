<?php
/**
 * The single read/write boundary for a student's Learning Journey.
 *
 * Existing LMS evidence is authoritative. student_learning_evidence contains
 * only item-level historical claims and never replaces submissions, grades,
 * attendance events, or learning events.
 */
require_once __DIR__ . '/StudentCourseAccess.php';
require_once __DIR__ . '/StudentCourseProgress.php';
require_once __DIR__ . '/AssignmentProgress.php';
require_once __DIR__ . '/CourseResourceResolver.php';
require_once __DIR__ . '/TimedExam.php';

function mmh_learning_journey_schema_available(mysqli $conn): bool
{
    static $available = null;
    if ($available !== null) { return $available; }
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_learning_evidence'");
    if (!$stmt) { return $available = false; }
    $stmt->execute();
    $available = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
    $stmt->close();
    return $available;
}

function mmh_learning_journey_entity_key(string $kind, string $itemId = '', string $assignmentId = '', string $occurrenceId = ''): string
{
    if ($occurrenceId !== '') { return 'occurrence:' . $occurrenceId; }
    if ($itemId !== '') { return 'item:' . $itemId; }
    if ($assignmentId !== '') { return 'assignment:' . $assignmentId; }
    return 'item:' . $itemId;
}

function mmh_learning_journey_item_assignment_id(array $item): string
{
    return function_exists('mmh_course_assignment_id') ? mmh_course_assignment_id($item) : trim((string) ($item['assignment_id'] ?? ''));
}

function mmh_learning_journey_item_kind(array $item): string
{
    $assignmentId = mmh_learning_journey_item_assignment_id($item);
    $type = strtolower(trim((string) ($item['template_type'] ?? $item['item_type'] ?? '')));
    if ($assignmentId !== '' || in_array($type, ['assignment', 'homework', 'classified_assignment'], true)) { return 'homework'; }
    if ($type === 'timed_exam') { return 'timed_exam'; }
    if (in_array($type, ['recording', 'video', 'lecture_recording'], true)) { return 'recording'; }
    return 'lesson';
}

function mmh_learning_journey_load_evidence(mysqli $conn, int $userId, string $courseId): array
{
    if ($userId <= 0 || $courseId === '' || !mmh_learning_journey_schema_available($conn)) { return []; }
    $stmt = $conn->prepare('SELECT * FROM student_learning_evidence WHERE user_id = ? AND course_id = ? ORDER BY id ASC');
    if (!$stmt) { return []; }
    $stmt->bind_param('is', $userId, $courseId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $map = [];
    foreach ($rows as $row) {
        $key = mmh_learning_journey_entity_key((string) ($row['item_kind'] ?? ''), (string) ($row['item_id'] ?? ''), (string) ($row['assignment_id'] ?? ''), (string) ($row['occurrence_id'] ?? ''));
        $map[$key] = $row;
    }
    return $map;
}

function mmh_learning_journey_visible_items(mysqli $conn, string $courseId): array
{
    $stmt = $conn->prepare("SELECT i.item_id, i.item_title, i.item_description, i.section_id, i.item_type, i.template_type, i.template_data, i.assignment_id, i.duration_minutes, i.sort_order, i.page_order, s.title AS section_title, s.sort_order AS section_sort_order
        FROM course_items i LEFT JOIN course_sections s ON s.course_id = i.course_id AND s.section_id = i.section_id
        WHERE i.course_id = ? AND i.archived_at IS NULL AND (i.status IS NULL OR i.status = '' OR i.status = 'published')
          AND (i.section_id IS NULL OR i.section_id = '' OR s.status IS NULL OR s.status = '' OR s.status = 'published')
        ORDER BY CASE WHEN i.section_id IS NULL OR i.section_id = '' THEN 0 ELSE 1 END, COALESCE(s.sort_order, 0), COALESCE(s.id, 0), COALESCE(i.sort_order, 0), COALESCE(i.page_order, 0), i.item_id ASC, i.id ASC");
    if (!$stmt) { return []; }
    $stmt->bind_param('s', $courseId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $items;
}

function mmh_learning_journey_live_occurrences(mysqli $conn, string $courseId): array
{
    $stmt = $conn->prepare("SELECT s.section_id, s.title AS section_title, s.sort_order AS section_sort_order, s.release_occurrence_id AS occurrence_id, o.scheduled_start_at, o.scheduled_end_at
        FROM course_sections s INNER JOIN live_session_occurrences o ON o.course_id = s.course_id AND o.occurrence_id = s.release_occurrence_id
        WHERE s.course_id = ? AND s.status = 'published' AND s.release_occurrence_id IS NOT NULL AND s.release_occurrence_id <> ''
        ORDER BY s.sort_order ASC, s.section_id ASC");
    if (!$stmt) { return []; }
    $stmt->bind_param('s', $courseId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function mmh_learning_journey_attendance(mysqli $conn, int $userId, string $courseId): array
{
    $stmt = $conn->prepare('SELECT occurrence_id, status, confirmed_source, confirmed_by, confirmed_at, teacher_note FROM live_session_attendance WHERE user_id = ? AND course_id = ?');
    if (!$stmt) { return []; }
    $stmt->bind_param('is', $userId, $courseId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $map = [];
    foreach ($rows as $row) { $map[(string) $row['occurrence_id']] = $row; }
    return $map;
}

function mmh_learning_journey_item_records(mysqli $conn, int $userId, string $courseId): array
{
    $items = mmh_learning_journey_visible_items($conn, $courseId);
    $progress = student_course_progress_load($conn, $userId, $courseId);
    $assignments = mmh_assignment_progress_load_course($conn, $userId, $courseId);
    $timedExams = mmh_timed_exam_course_states($conn, $userId, $courseId);
    $evidence = mmh_learning_journey_load_evidence($conn, $userId, $courseId);
    $records = [];
    foreach ($items as $item) {
        $itemId = (string) ($item['item_id'] ?? '');
        if ($itemId === '') { continue; }
        $assignmentId = mmh_learning_journey_item_assignment_id($item);
        $kind = mmh_learning_journey_item_kind($item);
        $key = mmh_learning_journey_entity_key($kind, $itemId, $assignmentId);
        $lmsComplete = false; $lmsState = 'not_completed';
        $p = $progress[$itemId] ?? [];
        if (!empty($p['completed_at'])) { $lmsComplete = true; $lmsState = 'completed'; }
        if ($assignmentId !== '' && isset($assignments[$assignmentId]['_state'])) {
            $state = $assignments[$assignmentId]['_state'];
            if (!empty($state['complete'])) { $lmsComplete = true; $lmsState = 'completed'; }
            elseif (!empty($state['submitted'])) { $lmsState = 'submitted'; }
        }
        $timedExam = $kind === 'timed_exam' ? ($timedExams[$itemId] ?? null) : null;
        if ($timedExam) {
            $timedState = (string) ($timedExam['state_key'] ?? 'not_completed');
            if (in_array($timedState, ['submitted', 'auto_submitted', 'graded'], true)) { $lmsComplete = true; $lmsState = 'completed'; }
            elseif ($timedState === 'in_progress' || $timedState === 'open' || $timedState === 'grace') { $lmsState = 'in_progress'; }
        }
        $historical = $evidence[$key] ?? ($assignmentId !== '' ? ($evidence['assignment:' . $assignmentId] ?? null) : null);
        $complete = $lmsComplete;
        $state = $lmsState;
        $source = $lmsComplete || $lmsState === 'submitted' ? 'lms' : '';
        if (!$complete && $lmsState !== 'submitted' && $historical) {
            $state = (string) ($historical['state'] ?? 'not_completed');
            $complete = in_array($state, ['completed', 'watched', 'submitted'], true);
            $source = (string) ($historical['source'] ?? 'manual');
        }
        $records[] = array_merge($item, [
            'item_kind' => $kind,
            'assignment_id' => $assignmentId,
            'entity_key' => $key,
            'is_completed' => $complete,
            'state' => $state,
            'evidence_source' => $source,
            'historical_evidence' => $historical,
        ]);
    }
    return $records;
}

function mmh_learning_journey_resolve(mysqli $conn, int $userId, string $courseId): array
{
    $items = mmh_learning_journey_item_records($conn, $userId, $courseId);
    $attendance = mmh_learning_journey_attendance($conn, $userId, $courseId);
    $evidence = mmh_learning_journey_load_evidence($conn, $userId, $courseId);
    $sections = [];
    foreach ($items as $item) {
        $sectionId = trim((string) ($item['section_id'] ?? '')) ?: '__general__';
        $sections[$sectionId] ??= ['section_id' => $sectionId, 'title' => (string) ($item['section_title'] ?? 'General'), 'sort_order' => (int) ($item['section_sort_order'] ?? 0), 'items' => [], 'live_sessions' => []];
        $sections[$sectionId]['items'][] = $item;
    }
    foreach (mmh_learning_journey_live_occurrences($conn, $courseId) as $occurrence) {
        $sectionId = (string) $occurrence['section_id'];
        $key = mmh_learning_journey_entity_key('live_session', '', '', (string) $occurrence['occurrence_id']);
        $lms = $attendance[(string) $occurrence['occurrence_id']] ?? null;
        $historical = $evidence[$key] ?? null;
        $status = strtolower((string) ($lms['status'] ?? 'unknown'));
        $complete = in_array($status, ['present_live', 'late', 'excused', 'manually_confirmed'], true);
        $source = $complete ? (($lms['confirmed_source'] ?? '') !== '' ? (string) $lms['confirmed_source'] : 'lms') : '';
        if (!$complete && $historical && (!$lms || $status === 'unknown')) { $status = (string) ($historical['state'] ?? 'not_recorded'); $complete = in_array($status, ['present', 'attended', 'completed'], true); $source = (string) ($historical['source'] ?? 'manual'); }
        $sections[$sectionId] ??= ['section_id' => $sectionId, 'title' => (string) $occurrence['section_title'], 'sort_order' => (int) $occurrence['section_sort_order'], 'items' => [], 'live_sessions' => []];
        $sections[$sectionId]['live_sessions'][] = array_merge($occurrence, ['entity_key' => $key, 'status' => $status, 'is_completed' => $complete, 'evidence_source' => $source, 'historical_evidence' => $historical]);
    }
    uasort($sections, static fn(array $a, array $b): int => [$a['sort_order'], $a['section_id']] <=> [$b['sort_order'], $b['section_id']]);
    $total = $completed = 0;
    foreach ($sections as &$section) {
        usort($section['items'], static fn(array $a, array $b): int => [(int) ($a['sort_order'] ?? 0), (int) ($a['page_order'] ?? 0), (string) $a['item_id']] <=> [(int) ($b['sort_order'] ?? 0), (int) ($b['page_order'] ?? 0), (string) $b['item_id']]);
        foreach ($section['items'] as $item) { $total++; if (!empty($item['is_completed'])) { $completed++; } }
        foreach ($section['live_sessions'] as $session) { $total++; if (!empty($session['is_completed'])) { $completed++; } }
    }
    unset($section);
    return ['course_id' => $courseId, 'sections' => array_values($sections), 'items' => $items, 'total' => $total, 'completed' => $completed, 'percentage' => $total > 0 ? (int) round(($completed / $total) * 100) : null];
}

function mmh_learning_journey_resume(array $journey, array $accessibleItems = []): ?array
{
    $items = $accessibleItems ?: ($journey['items'] ?? []);
    foreach ($items as $item) { if (empty($item['is_completed'])) { return $item; } }
    return $items[0] ?? null;
}

function mmh_learning_journey_validate_state(string $kind, string $state): string
{
    $allowed = ['lesson' => ['completed', 'not_completed'], 'recording' => ['watched', 'not_watched'], 'homework' => ['completed', 'not_completed'], 'live_session' => ['present', 'absent'], 'exam' => ['completed', 'not_completed'], 'timed_exam' => ['completed', 'not_completed']];
    if (!in_array($state, $allowed[$kind] ?? ['completed', 'not_completed'], true)) { throw new InvalidArgumentException('Invalid Learning Journey state.'); }
    return $state;
}

function mmh_learning_journey_save_evidence(mysqli $conn, int $userId, string $courseId, array $entity, string $state, string $source, int $adminId, string $note = ''): void
{
    if (!mmh_learning_journey_schema_available($conn)) { throw new RuntimeException('Run the student learning evidence migration before saving progress.'); }
    $kind = trim((string) ($entity['item_kind'] ?? 'lesson'));
    if ($kind === 'timed_exam') { throw new InvalidArgumentException('Timed Exams are completed by a valid exam submission, not manual evidence.'); }
    $state = mmh_learning_journey_validate_state($kind, trim($state));
    $source = strtolower(trim($source));
    if (!in_array($source, ['whatsapp', 'manual'], true)) { throw new InvalidArgumentException('Source must be WhatsApp or Manual.'); }
    $itemId = trim((string) ($entity['item_id'] ?? '')) ?: null;
    $assignmentId = trim((string) ($entity['assignment_id'] ?? '')) ?: null;
    $occurrenceId = trim((string) ($entity['occurrence_id'] ?? '')) ?: null;
    $sectionId = trim((string) ($entity['section_id'] ?? '')) ?: null;
    if ($itemId === null && $assignmentId === null && $occurrenceId === null) { throw new InvalidArgumentException('Every recorded item must reference a real course item or live occurrence.'); }
    $entityKey = mmh_learning_journey_entity_key($kind, (string) ($itemId ?? ''), (string) ($assignmentId ?? ''), (string) ($occurrenceId ?? ''));
    $note = mb_substr(trim($note), 0, 1000);
    $stmt = $conn->prepare("INSERT INTO student_learning_evidence (user_id, course_id, section_id, item_id, assignment_id, occurrence_id, entity_key, item_kind, state, source, recorded_by, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE section_id = VALUES(section_id), state = VALUES(state), source = VALUES(source), recorded_by = VALUES(recorded_by), recorded_at = CURRENT_TIMESTAMP, note = VALUES(note), updated_at = CURRENT_TIMESTAMP");
    if (!$stmt) { throw new RuntimeException('Unable to prepare Learning Journey save.'); }
    $stmt->bind_param('isssssssssis', $userId, $courseId, $sectionId, $itemId, $assignmentId, $occurrenceId, $entityKey, $kind, $state, $source, $adminId, $note);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException('Unable to save Learning Journey item: ' . $error); }
    $stmt->close();
}

function mmh_learning_journey_delete_evidence(mysqli $conn, int $userId, string $courseId, array $entity): void
{
    if (!mmh_learning_journey_schema_available($conn)) { return; }
    $kind = trim((string) ($entity['item_kind'] ?? 'lesson')); $itemId = trim((string) ($entity['item_id'] ?? '')); $assignmentId = trim((string) ($entity['assignment_id'] ?? '')); $occurrenceId = trim((string) ($entity['occurrence_id'] ?? ''));
    $stmt = $conn->prepare('DELETE FROM student_learning_evidence WHERE user_id = ? AND course_id = ? AND item_kind = ? AND ((item_id = ? AND ? <> \'\') OR (assignment_id = ? AND ? <> \'\') OR (occurrence_id = ? AND ? <> \'\'))');
    if (!$stmt) { return; }
    $stmt->bind_param('issssssss', $userId, $courseId, $kind, $itemId, $itemId, $assignmentId, $assignmentId, $occurrenceId, $occurrenceId);
    $stmt->execute(); $stmt->close();
}
