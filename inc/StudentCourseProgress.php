<?php
/**
 * Shared B4C lesson-progress helpers.
 *
 * Progress is intentionally stored separately from lesson HTML and Learning
 * Events. The course page supplies its already-loaded, already-authorized
 * lesson inventory so calculations do not introduce per-lesson queries.
 */

require_once __DIR__ . '/StudentCourseAccess.php';
require_once __DIR__ . '/AssignmentProgress.php';

if (!function_exists('student_course_progress_available')) {
    function student_course_progress_available(mysqli $conn)
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        $table = 'course_item_progress';
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        if (!$stmt) {
            return $available = false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $available = (int) ($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('student_course_progress_load')) {
    function student_course_progress_load(mysqli $conn, $userId, $courseId)
    {
        $progress = [];
        $userId = (int) $userId;
        $courseId = student_course_access_identifier($courseId, 40);
        if ($userId <= 0 || $courseId === null || !student_course_progress_available($conn)) {
            return $progress;
        }

        $stmt = $conn->prepare('SELECT item_id, last_viewed_at, completed_at, completion_source FROM course_item_progress WHERE user_id = ? AND course_id = ?');
        if (!$stmt) {
            return $progress;
        }
        $stmt->bind_param('is', $userId, $courseId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $progress[(string) $row['item_id']] = $row;
        }
        $stmt->close();

        return $progress;
    }
}

if (!function_exists('student_course_progress_record_viewed')) {
    function student_course_progress_record_viewed(mysqli $conn, $userId, $courseId, $itemId)
    {
        $userId = (int) $userId;
        $courseId = student_course_access_identifier($courseId, 40);
        $itemId = student_course_access_identifier($itemId, 40);
        if ($userId <= 0 || $courseId === null || $itemId === null || !student_course_progress_available($conn)) {
            return false;
        }

        // Deliberately does not change completed_at or completion_source.
        $stmt = $conn->prepare('INSERT INTO course_item_progress (user_id, course_id, item_id, last_viewed_at, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE last_viewed_at = NOW(), updated_at = NOW()');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iss', $userId, $courseId, $itemId);
        $saved = $stmt->execute();
        $stmt->close();

        return (bool) $saved;
    }
}

if (!function_exists('student_course_progress_mark_complete')) {
    function student_course_progress_mark_complete(mysqli $conn, $userId, $courseId, $itemId, $source = 'manual')
    {
        $userId = (int) $userId;
        $courseId = student_course_access_identifier($courseId, 40);
        $itemId = student_course_access_identifier($itemId, 40);
        $source = student_course_access_identifier($source, 50);
        if ($userId <= 0 || $courseId === null || $itemId === null || $source === null || !student_course_progress_available($conn)) {
            return false;
        }

        // Preserve the first completion timestamp/source. Repeated browser
        // requests stay idempotent while still refreshing last_viewed_at.
        $stmt = $conn->prepare('INSERT INTO course_item_progress (user_id, course_id, item_id, last_viewed_at, completed_at, completion_source, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW(), ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE last_viewed_at = NOW(), completed_at = COALESCE(completed_at, VALUES(completed_at)), completion_source = COALESCE(completion_source, VALUES(completion_source)), updated_at = NOW()');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isss', $userId, $courseId, $itemId, $source);
        $saved = $stmt->execute();
        $stmt->close();

        return (bool) $saved;
    }
}

if (!function_exists('student_course_progress_is_completed')) {
    function student_course_progress_is_completed(array $progressMap, $itemId)
    {
        return !empty($progressMap[(string) $itemId]['completed_at']);
    }
}

if (!function_exists('student_course_progress_item_depends_on_assessment')) {
    function student_course_progress_item_depends_on_assessment(array $item)
    {
        $templateType = strtolower(trim((string) ($item['template_type'] ?? '')));
        $itemType = strtolower(trim((string) ($item['item_type'] ?? '')));
        $assessmentTypes = ['classified_assignment', 'assignment', 'homework', 'exam', 'past_paper', 'quiz'];
        if (in_array($templateType, $assessmentTypes, true) || in_array($itemType, $assessmentTypes, true)) {
            return true;
        }

        $data = json_decode((string) ($item['template_data'] ?? ''), true);
        if (is_array($data) && (
            trim((string) ($data['assignment_id'] ?? '')) !== '' ||
            trim((string) ($data['exam_id'] ?? '')) !== ''
        )) {
            return true;
        }

        return trim((string) ($item['assignment_id'] ?? '')) !== '';
    }
}

if (!function_exists('student_course_progress_manual_completion_eligible')) {
    function student_course_progress_manual_completion_eligible(array $item)
    {
        return !student_course_progress_item_depends_on_assessment($item);
    }
}

if (!function_exists('student_course_progress_assignment_id')) {
    function student_course_progress_assignment_id(array $item)
    {
        $assignmentId = trim((string) ($item['assignment_id'] ?? ''));
        if ($assignmentId !== '') {
            return $assignmentId;
        }
        $data = json_decode((string) ($item['template_data'] ?? ''), true);
        return is_array($data) ? trim((string) ($data['assignment_id'] ?? '')) : '';
    }
}

if (!function_exists('student_course_progress_assignment_state')) {
    function student_course_progress_assignment_state(array $item, array $assignmentMap)
    {
        $assignmentId = student_course_progress_assignment_id($item);
        return $assignmentId !== '' && isset($assignmentMap[$assignmentId])
            ? ($assignmentMap[$assignmentId]['_state'] ?? null)
            : null;
    }
}

if (!function_exists('student_course_progress_calculate')) {
    function student_course_progress_calculate(array $accessibleItems, array $progressMap, array $assignmentMap = [])
    {
        $eligible = 0;
        $completed = 0;
        $incomplete = 0;
        $remainingMinutes = 0;
        $knownRemainingCount = 0;
        $countedRequiredAssignments = [];
        $accessibleSections = [];

        foreach ($accessibleItems as $item) {
            $sectionId = trim((string) ($item['section_id'] ?? ''));
            if ($sectionId !== '') {
                $accessibleSections[$sectionId] = true;
            }
            $manualEligible = !empty($item['manual_eligible']);
            $assignmentState = student_course_progress_assignment_state($item, $assignmentMap);
            // Lesson-scoped requirements complete their linked lesson. Section
            // requirements are counted once below, not once per lesson card.
            $requiredAssignment = !empty($assignmentState['required'])
                && ($assignmentState['completion_requirement'] ?? '') === 'lesson';
            if (!$manualEligible && !$requiredAssignment) {
                continue;
            }
            $eligible++;
            $isComplete = $manualEligible
                ? student_course_progress_is_completed($progressMap, $item['item_id'] ?? '')
                : !empty($assignmentState['complete']);
            if ($requiredAssignment) {
                $assignmentId = student_course_progress_assignment_id($item);
                if ($assignmentId !== '') {
                    $countedRequiredAssignments[$assignmentId] = true;
                }
            }
            if ($isComplete) {
                $completed++;
                continue;
            }

            $incomplete++;
            $duration = isset($item['duration_minutes']) && is_numeric($item['duration_minutes']) ? (int) $item['duration_minutes'] : 0;
            if ($duration > 0) {
                $remainingMinutes += $duration;
                $knownRemainingCount++;
            }
        }

        // A section-scoped required assignment may not have a dedicated
        // lesson card. Count it once when its section is accessible so the
        // course percentage cannot report complete while required work remains.
        foreach ($assignmentMap as $assignmentId => $assignment) {
            $state = $assignment['_state'] ?? [];
            if (empty($state['required']) || ($state['completion_requirement'] ?? '') !== 'section') {
                continue;
            }
            $sectionId = trim((string) ($assignment['section_id'] ?? ''));
            if ($sectionId === '' || empty($accessibleSections[$sectionId]) || isset($countedRequiredAssignments[$assignmentId])) {
                continue;
            }
            $eligible++;
            if (!empty($state['complete'])) {
                $completed++;
            } else {
                $incomplete++;
            }
        }

        return [
            'available' => $eligible > 0,
            'eligible_count' => $eligible,
            'completed_count' => $completed,
            'incomplete_count' => $incomplete,
            'percentage' => $eligible > 0 ? (int) round(($completed / $eligible) * 100) : null,
            'remaining_minutes' => $remainingMinutes,
            'known_remaining_count' => $knownRemainingCount,
            'unknown_remaining_count' => $incomplete - $knownRemainingCount,
        ];
    }
}

if (!function_exists('student_course_progress_resolve_resume')) {
    function student_course_progress_resolve_resume(array $accessibleItems, array $progressMap)
    {
        $lastViewed = null;
        $lastViewedTimestamp = 0;
        foreach ($accessibleItems as $item) {
            $progress = $progressMap[(string) ($item['item_id'] ?? '')] ?? null;
            $timestamp = !empty($progress['last_viewed_at']) ? strtotime((string) $progress['last_viewed_at']) : 0;
            if ($timestamp !== false && $timestamp > $lastViewedTimestamp) {
                $lastViewed = $item;
                $lastViewedTimestamp = $timestamp;
            }
        }
        if ($lastViewed !== null) {
            return $lastViewed;
        }

        // Assessment-dependent lessons are deliberately outside the manual
        // progress model, so they cannot block a student's normal resume.
        foreach ($accessibleItems as $item) {
            if (!empty($item['manual_eligible']) && !student_course_progress_is_completed($progressMap, $item['item_id'] ?? '')) {
                return $item;
            }
        }

        return $accessibleItems[0] ?? null;
    }
}
