<?php
/**
 * Shared student-course authorization helpers.
 *
 * These helpers deliberately stay small and procedural to match the existing
 * application. They centralize the access checks used by the course page and
 * its state-changing student endpoints, while keeping legacy NULL/empty
 * publication states visible.
 */
require_once __DIR__ . '/learning_schema.php';
require_once __DIR__ . '/CourseSectionAvailability.php';
require_once __DIR__ . '/AssignmentProgress.php';
require_once __DIR__ . '/CourseVisibility.php';

if (!function_exists('student_course_access_identifier')) {
    function student_course_access_identifier($value, $maxLength = 40)
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > (int) $maxLength || !preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            return null;
        }

        return $value;
    }
}

if (!function_exists('student_course_access_course_url')) {
    /**
     * Builds a canonical internal student-course URL. The optional return mode
     * keeps a selected lesson visible without re-triggering a direct resource
     * redirect when a viewer sends the student back to the course page.
     */
    function student_course_access_course_url($baseUrl, $courseId, $itemId = null, $returnToCourse = false)
    {
        $base = rtrim((string) $baseUrl, '/');
        $courseId = student_course_access_identifier($courseId, 40);
        if ($courseId === null) {
            return $base . '/user/my-courses';
        }

        $url = $base . '/user/course/' . rawurlencode($courseId);
        $itemId = $itemId === null ? null : student_course_access_identifier($itemId, 40);
        if ($itemId !== null) {
            $params = ['lesson' => $itemId];
            if ($returnToCourse) {
                $params['return'] = '1';
            }
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }
}

if (!function_exists('student_course_access_visible_status')) {
    function student_course_access_visible_status($status)
    {
        return strtolower(trim((string) $status)) !== 'draft';
    }
}

if (!function_exists('student_course_access_normalize_section_id')) {
    function student_course_access_normalize_section_id($sectionId)
    {
        $sectionId = trim((string) $sectionId);
        if ($sectionId === '' || $sectionId === '__general__') {
            return '';
        }

        return student_course_access_identifier($sectionId);
    }
}

if (!function_exists('student_course_access_student_id')) {
    function student_course_access_student_id(mysqli $conn, $username)
    {
        $username = trim((string) $username);
        if ($username === '' || strlen($username) > 120) {
            return null;
        }

        $stmt = $conn->prepare('SELECT user_id FROM users WHERE username = ? OR CAST(user_id AS CHAR) = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null;
    }
}

if (!function_exists('student_course_access_course')) {
    function student_course_access_course(mysqli $conn, $courseId)
    {
        $courseId = student_course_access_identifier($courseId, 40);
        if ($courseId === null) {
            return null;
        }

        // Route URLs historically accept either courses.id or courses.course_id.
        // Always return the canonical course_id used by enrollment and lessons.
        $stmt = $conn->prepare("SELECT id, course_id, course_title, course_description, course_image, course_category, username, sequential_learning, course_status, course_visibility, course_state
            FROM courses
            WHERE archived_at IS NULL AND course_state IN ('public', 'private')
              AND (course_id = ? OR CAST(id AS CHAR) = ?)
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $courseId, $courseId);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $course ?: null;
    }
}

if (!function_exists('student_course_access_authorized_course')) {
    function student_course_access_authorized_course(mysqli $conn, $userId, $courseId): ?array
    {
        $course = student_course_access_course($conn, $courseId);
        if (!$course || !mmh_course_is_student_available($course)) {
            return null;
        }
        return student_course_access_enrolled($conn, $userId, (string) ($course['course_id'] ?? '')) ? $course : null;
    }
}

if (!function_exists('student_course_access_enrolled')) {
    function student_course_access_enrolled(mysqli $conn, $userId, $courseId)
    {
        $courseId = student_course_access_identifier($courseId, 40);
        $userId = (int) $userId;
        if ($courseId === null || $userId <= 0) {
            return false;
        }

        $stmt = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $userId, $courseId);
        $stmt->execute();
        $enrolled = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $enrolled;
    }
}

if (!function_exists('student_course_access_item')) {
    function student_course_access_item(mysqli $conn, $courseId, $itemId)
    {
        $courseId = student_course_access_identifier($courseId, 40);
        $itemId = student_course_access_identifier($itemId, 40);
        if ($courseId === null || $itemId === null) {
            return null;
        }

        $stmt = $conn->prepare("SELECT id, item_id, item_title, item_description, course_id, section_id, item_type, template_type, template_data, assignment_id, duration_minutes, status
            FROM course_items
            WHERE course_id = ? AND item_id = ? AND archived_at IS NULL
              AND (status IS NULL OR status = '' OR status = 'published')
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $courseId, $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $item ?: null;
    }
}

if (!function_exists('student_course_access_section')) {
    function student_course_access_section(mysqli $conn, $courseId, $sectionId)
    {
        mmh_ensure_learning_schema($conn);
        $courseId = student_course_access_identifier($courseId, 40);
        $sectionId = student_course_access_identifier($sectionId, 40);
        if ($courseId === null || $sectionId === null) {
            return null;
        }

        $stmt = $conn->prepare("SELECT id, section_id, course_id, title, description, sort_order, status, unlock_mode,
                completion_rule, unlock_at, unlock_timezone, unlock_homework_id, manual_unlocked,
                release_mode, release_override, release_at, release_timezone, release_occurrence_id, release_delay_minutes, metadata
            FROM course_sections
            WHERE course_id = ? AND section_id = ?
              AND (status IS NULL OR status = '' OR status = 'published')
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $courseId, $sectionId);
        $stmt->execute();
        $section = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $section ?: null;
    }
}

if (!function_exists('student_course_access_item_matches_section')) {
    function student_course_access_item_matches_section(array $item, $submittedSectionId)
    {
        $itemSectionId = student_course_access_normalize_section_id($item['section_id'] ?? '');
        $submittedSectionId = student_course_access_normalize_section_id($submittedSectionId);

        return $itemSectionId !== null && $submittedSectionId !== null && $itemSectionId === $submittedSectionId;
    }
}

if (!function_exists('student_course_access_learning_override')) {
    function student_course_access_learning_override(mysqli $conn, $courseId, $userId)
    {
        $default = ['sequential_override' => 'inherit', 'unlocked_sections' => []];
        $stmt = $conn->prepare('SELECT sequential_override, unlocked_sections FROM course_learning_overrides WHERE course_id = ? AND user_id = ? LIMIT 1');
        if (!$stmt) {
            return $default;
        }
        $stmt->bind_param('si', $courseId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return $default;
        }

        $unlocked = json_decode((string) ($row['unlocked_sections'] ?? '[]'), true);
        return [
            'sequential_override' => $row['sequential_override'] ?: 'inherit',
            'unlocked_sections' => is_array($unlocked) ? array_map('strval', $unlocked) : [],
        ];
    }
}

if (!function_exists('student_course_access_learning_enabled')) {
    function student_course_access_learning_enabled($courseSequential, array $override)
    {
        $mode = $override['sequential_override'] ?? 'inherit';
        if ($mode === 'on' || $mode === 'unlock_selected') {
            return true;
        }
        if ($mode === 'off' || $mode === 'unlock_all') {
            return false;
        }

        return (int) $courseSequential === 1;
    }
}

if (!function_exists('student_course_access_progress_map')) {
    function student_course_access_progress_map(mysqli $conn, $courseId, $userId)
    {
        $progress = [];
        $stmt = $conn->prepare('SELECT section_id, completed_at, source FROM course_section_progress WHERE course_id = ? AND user_id = ?');
        if (!$stmt) {
            return $progress;
        }
        $stmt->bind_param('si', $courseId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $progress[(string) $row['section_id']] = $row;
        }
        $stmt->close();

        return $progress;
    }
}

if (!function_exists('student_course_access_homework_done')) {
    function student_course_access_homework_done(mysqli $conn, $assignmentId, $userId, $approvalRequired = false)
    {
        $assignmentId = student_course_access_identifier($assignmentId, 40);
        $userId = (int) $userId;
        if ($assignmentId === null || $userId <= 0) {
            return false;
        }
        $stmt = $conn->prepare('SELECT a.assignment_id, a.course_id, a.completion_requirement, a.completion_rule, a.minimum_score, s.id, s.submitted_at, s.grade, s.self_score, s.self_score_status, s.verified_at FROM assignments AS a LEFT JOIN assignment_submissions AS s ON s.id = (SELECT s2.id FROM assignment_submissions AS s2 WHERE s2.assignment_id = a.assignment_id AND s2.student_id = ? ORDER BY s2.submitted_at DESC, s2.id DESC LIMIT 1) WHERE a.assignment_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $userId, $assignmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
        $submission = !empty($row['id']) ? $row : null;
        $rule = $approvalRequired ? 'teacher_approval' : 'submission';
        return !empty(mmh_assignment_progress_evaluate($row, $submission, $rule)['complete']);
    }
}

if (!function_exists('student_course_access_section_completion_state')) {
    function student_course_access_section_completion_state(mysqli $conn, array $section, array $progressMap, $userId, ?array $assignmentMap = null)
    {
        $sectionId = (string) ($section['key'] ?? $section['section_id'] ?? '');
        if ($sectionId === '' || $sectionId === '__general__') {
            return ['complete' => false, 'requirements' => ['has_requirements' => false, 'complete' => true, 'required_count' => 0, 'completed_count' => 0, 'outstanding_count' => 0, 'blocking_reason' => '']];
        }
        $assignmentMap = $assignmentMap ?? mmh_assignment_progress_load_course($conn, (int) $userId, (string) ($section['course_id'] ?? ''));
        $requirements = mmh_assignment_progress_section_state($assignmentMap, $sectionId);
        // A configured required assignment is an explicit completion path for
        // this section. It completes automatically once all such requirements
        // are satisfied, so teacher grading is reflected on the next request.
        if (!empty($requirements['has_requirements'])) {
            return ['complete' => !empty($requirements['complete']), 'requirements' => $requirements];
        }
        if (isset($progressMap[$sectionId])) {
            return ['complete' => true, 'requirements' => $requirements];
        }
        $rule = (string) ($section['completion_rule'] ?? 'manual_completion');
        $assignmentId = $section['unlock_homework_id'] ?? '';
        if ($rule === 'homework_submitted') {
            return ['complete' => student_course_access_homework_done($conn, $assignmentId, $userId, false), 'requirements' => $requirements];
        }
        if ($rule === 'homework_approved') {
            return ['complete' => student_course_access_homework_done($conn, $assignmentId, $userId, true), 'requirements' => $requirements];
        }
        return ['complete' => false, 'requirements' => $requirements];
    }
}

if (!function_exists('student_course_access_section_completed')) {
    function student_course_access_section_completed(mysqli $conn, array $section, array $progressMap, $userId, ?array $assignmentMap = null)
    {
        $state = student_course_access_section_completion_state($conn, $section, $progressMap, $userId, $assignmentMap);
        return !empty($state['complete']);
    }
}

if (!function_exists('student_course_access_unlock_date_label')) {
    function student_course_access_unlock_date_label($unlockAt, $timezone)
    {
        if (empty($unlockAt)) {
            return '';
        }
        try {
            $date = new DateTime((string) $unlockAt, new DateTimeZone($timezone ?: 'Africa/Cairo'));
            return $date->format('j M Y h:i A');
        } catch (Throwable $e) {
            $timestamp = strtotime((string) $unlockAt);
            return $timestamp ? date('j M Y h:i A', $timestamp) : (string) $unlockAt;
        }
    }
}

if (!function_exists('student_course_access_section_unlock_state')) {
    function student_course_access_section_unlock_state(mysqli $conn, array $section, $index, array $sections, array $completedMap, array $override, $learningEnabled, $userId)
    {
        $key = (string) ($section['key'] ?? $section['section_id'] ?? '');
        if ($key === '' || $key === '__general__') {
            return ['locked' => false, 'reason' => '', 'badge' => 'Available'];
        }

        // Release scheduling is independent from Sequential Learning. An
        // explicit teacher override applies to the release rule only; the
        // established prerequisite checks below still remain authoritative.
        $release = mmh_section_release_state($conn, $section);
        if (!empty($release['locked'])) {
            return [
                'locked' => true,
                'reason' => (string) ($release['reason'] ?? 'This section is not available yet.'),
                'badge' => (string) ($release['badge'] ?? 'Locked'),
                'release' => $release,
            ];
        }
        if (!$learningEnabled) {
            return ['locked' => false, 'reason' => '', 'badge' => (string) ($release['badge'] ?? 'Available'), 'release' => $release];
        }

        $mode = $override['sequential_override'] ?? 'inherit';
        if ($mode === 'unlock_all') {
            return ['locked' => false, 'reason' => '', 'badge' => 'Unlocked'];
        }
        if (in_array($key, $override['unlocked_sections'] ?? [], true)) {
            return ['locked' => false, 'reason' => 'Unlocked for you by your teacher.', 'badge' => 'Unlocked'];
        }

        $unlockMode = $section['unlock_mode'] ?: 'always';
        $homeworkId = $section['unlock_homework_id'] ?? '';
        switch ($unlockMode) {
            case 'after_previous_completed':
                $previous = null;
                for ($previousIndex = (int) $index - 1; $previousIndex >= 0; $previousIndex--) {
                    $candidate = $sections[$previousIndex] ?? null;
                    if ($candidate && (string) ($candidate['key'] ?? $candidate['section_id'] ?? '') !== '__general__') {
                        $previous = $candidate;
                        break;
                    }
                }
                if (!$previous) {
                    return ['locked' => false, 'reason' => '', 'badge' => 'Available'];
                }
                $previousId = (string) ($previous['key'] ?? $previous['section_id'] ?? '');
                if (!empty($completedMap[$previousId])) {
                    return ['locked' => false, 'reason' => '', 'badge' => 'Available'];
                }
                return ['locked' => true, 'reason' => 'Complete ' . (($previous['title'] ?? '') ?: 'the previous section') . ' first.', 'badge' => 'Locked'];

            case 'on_date':
                $unlockAt = $section['unlock_at'] ?? '';
                if ($unlockAt === '' || strtotime((string) $unlockAt) <= time()) {
                    return ['locked' => false, 'reason' => '', 'badge' => 'Available'];
                }
                return ['locked' => true, 'reason' => 'Available on ' . student_course_access_unlock_date_label($unlockAt, $section['unlock_timezone'] ?? 'Africa/Cairo') . '.', 'badge' => 'Available Later'];

            case 'manual_unlock':
                if (!empty($section['manual_unlocked'])) {
                    return ['locked' => false, 'reason' => '', 'badge' => 'Available'];
                }
                return ['locked' => true, 'reason' => 'Locked by teacher.', 'badge' => 'Locked'];

            case 'after_homework_submission':
                if (student_course_access_homework_done($conn, $homeworkId, $userId, false)) {
                    return ['locked' => false, 'reason' => '', 'badge' => 'Available'];
                }
                return ['locked' => true, 'reason' => 'Homework submission required.', 'badge' => 'Homework Required'];

            case 'after_homework_approval':
                if (student_course_access_homework_done($conn, $homeworkId, $userId, true)) {
                    return ['locked' => false, 'reason' => '', 'badge' => 'Available'];
                }
                return ['locked' => true, 'reason' => 'Homework approval required.', 'badge' => 'Approval Required'];

            case 'custom_rule':
                return ['locked' => true, 'reason' => 'Custom rule is reserved for this section.', 'badge' => 'Locked'];

            case 'always':
            default:
                return ['locked' => false, 'reason' => '', 'badge' => 'Available'];
        }
    }
}

if (!function_exists('student_course_access_visible_sections')) {
    function student_course_access_visible_sections(mysqli $conn, $courseId)
    {
        mmh_ensure_learning_schema($conn);
        $sections = [];
        $stmt = $conn->prepare("SELECT s.section_id, s.course_id, s.title, s.description, s.sort_order, s.status, s.unlock_mode,
                s.completion_rule, s.unlock_at, s.unlock_timezone, s.unlock_homework_id, s.manual_unlocked,
                s.release_mode, s.release_override, s.release_at, s.release_timezone, s.release_occurrence_id, s.release_delay_minutes, s.metadata
            FROM course_sections AS s
            WHERE s.course_id = ?
              AND (s.status IS NULL OR s.status = '' OR s.status = 'published')
              AND EXISTS (
                SELECT 1 FROM course_items AS i
                WHERE i.course_id = s.course_id AND i.section_id = s.section_id AND i.archived_at IS NULL
                  AND (i.status IS NULL OR i.status = '' OR i.status = 'published')
              )
            ORDER BY s.sort_order ASC, s.id ASC");
        if (!$stmt) {
            return $sections;
        }
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['key'] = (string) $row['section_id'];
            $sections[] = $row;
        }
        $stmt->close();

        return $sections;
    }
}

if (!function_exists('student_course_access_has_visible_general_item')) {
    function student_course_access_has_visible_general_item(mysqli $conn, $courseId)
    {
        $courseId = student_course_access_identifier($courseId, 40);
        if ($courseId === null) {
            return false;
        }

        $stmt = $conn->prepare("SELECT id FROM course_items
            WHERE course_id = ? AND archived_at IS NULL AND (section_id IS NULL OR section_id = '')
              AND (status IS NULL OR status = '' OR status = 'published')
            LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $hasItem = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $hasItem;
    }
}

if (!function_exists('student_course_access_section_state')) {
    function student_course_access_section_state(mysqli $conn, array $course, $sectionId, $userId)
    {
        $section = student_course_access_section($conn, $course['course_id'] ?? '', $sectionId);
        if (!$section) {
            return null;
        }

        $sections = student_course_access_visible_sections($conn, $course['course_id']);
        $index = null;
        foreach ($sections as $position => $candidate) {
            if ((string) $candidate['section_id'] === (string) $section['section_id']) {
                $index = $position;
                break;
            }
        }
        if ($index === null) {
            return null;
        }

        $override = student_course_access_learning_override($conn, $course['course_id'], $userId);
        $learningEnabled = student_course_access_learning_enabled($course['sequential_learning'] ?? 0, $override);
        $progress = student_course_access_progress_map($conn, $course['course_id'], $userId);
        $assignmentMap = mmh_assignment_progress_load_course($conn, $userId, $course['course_id']);
        $completed = [];
        foreach ($sections as $candidate) {
            $completed[(string) $candidate['section_id']] = student_course_access_section_completed($conn, $candidate, $progress, $userId, $assignmentMap);
        }
        $state = student_course_access_section_unlock_state($conn, $section, $index, $sections, $completed, $override, $learningEnabled, $userId);

        return [
            'section' => $section,
            'state' => $state,
            'completed' => !empty($completed[(string) $section['section_id']]),
        ];
    }
}

if (!function_exists('student_course_access_selected_item')) {
    function student_course_access_selected_item(mysqli $conn, array $course, $itemId, $submittedSectionId, $userId)
    {
        $item = student_course_access_item($conn, $course['course_id'] ?? '', $itemId);
        if (!$item || !student_course_access_item_matches_section($item, $submittedSectionId)) {
            return null;
        }

        $sectionId = student_course_access_normalize_section_id($item['section_id'] ?? '');
        if ($sectionId === '') {
            $item['section_metadata'] = '';
            return ['item' => $item, 'section_id' => '', 'section_state' => ['state' => ['locked' => false]]];
        }

        $sectionState = student_course_access_section_state($conn, $course, $sectionId, $userId);
        if (!$sectionState || !empty($sectionState['state']['locked'])) {
            return null;
        }

        $item['section_metadata'] = (string) (($sectionState['section']['metadata'] ?? ''));
        return ['item' => $item, 'section_id' => $sectionId, 'section_state' => $sectionState];
    }
}

if (!function_exists('student_course_access_ordered_items')) {
    /**
     * Returns the visible, unlocked course sequence for a student. This is
     * intentionally shared by protected resource views so Previous/Next never
     * bypasses the established publication or section-release rules.
     */
    function student_course_access_ordered_items(mysqli $conn, array $course, $userId)
    {
        $courseId = student_course_access_identifier($course['course_id'] ?? '', 40);
        $userId = (int) $userId;
        if ($courseId === null || $userId <= 0) {
            return [];
        }

        $stmt = $conn->prepare("SELECT i.id, i.item_id, i.item_title, i.item_description, i.course_id, i.section_id,
                i.item_type, i.template_type, i.template_data, i.assignment_id, i.duration_minutes, i.status,
                s.title AS section_title, s.sort_order AS section_sort_order, s.metadata AS section_metadata
            FROM course_items AS i
            LEFT JOIN course_sections AS s ON s.course_id = i.course_id AND s.section_id = i.section_id
              AND (s.status IS NULL OR s.status = '' OR s.status = 'published')
            WHERE i.course_id = ? AND i.archived_at IS NULL AND (i.status IS NULL OR i.status = '' OR i.status = 'published')
              AND (i.section_id IS NULL OR i.section_id = '' OR s.section_id IS NOT NULL)
            ORDER BY CASE WHEN i.section_id IS NULL OR i.section_id = '' THEN 0 ELSE 1 END ASC,
                s.sort_order ASC, s.id ASC, i.sort_order ASC, i.page_order ASC, i.item_id ASC, i.id ASC");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $courseId);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        $sectionStates = [];
        while ($item = $result->fetch_assoc()) {
            $sectionId = student_course_access_normalize_section_id($item['section_id'] ?? '');
            if ($sectionId === null) {
                continue;
            }
            if ($sectionId !== '') {
                if (!array_key_exists($sectionId, $sectionStates)) {
                    $sectionStates[$sectionId] = student_course_access_section_state($conn, $course, $sectionId, $userId);
                }
                if (!$sectionStates[$sectionId] || !empty($sectionStates[$sectionId]['state']['locked'])) {
                    continue;
                }
            }
            $item['section_id'] = $sectionId;
            $items[] = $item;
        }
        $stmt->close();

        return $items;
    }
}

if (!function_exists('student_course_access_assignment')) {
    function student_course_access_assignment(mysqli $conn, $assignmentId)
    {
        $assignmentId = student_course_access_identifier($assignmentId, 40);
        if ($assignmentId === null) {
            return null;
        }

        $stmt = $conn->prepare('SELECT assignment_id, assignment_title, due_date, late_submission_enabled, late_submission_until, course_id, section_id, item_id, allow_self_score, require_teacher_verification, max_score, completion_requirement, completion_rule, minimum_score FROM assignments WHERE assignment_id = ? AND archived_at IS NULL LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $assignmentId);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $assignment ?: null;
    }
}

if (!function_exists('student_course_access_assignment_matches_item')) {
    function student_course_access_assignment_matches_item(array $assignment, array $item)
    {
        $assignmentSection = student_course_access_normalize_section_id($assignment['section_id'] ?? '');
        $itemSection = student_course_access_normalize_section_id($item['section_id'] ?? '');
        if ($assignmentSection === null || $itemSection === null) {
            return false;
        }
        if ($assignmentSection !== '' && $assignmentSection !== $itemSection) {
            return false;
        }

        $assignmentItem = trim((string) ($assignment['item_id'] ?? ''));
        if ($assignmentItem !== '') {
            if ($assignmentItem !== (string) ($item['item_id'] ?? '') || $assignmentSection !== $itemSection) {
                return false;
            }
        }

        $itemAssignment = trim((string) ($item['assignment_id'] ?? ''));
        if ($itemAssignment !== '' && $itemAssignment !== (string) ($assignment['assignment_id'] ?? '')) {
            return false;
        }

        return true;
    }
}

if (!function_exists('student_course_access_table_column')) {
    function student_course_access_table_column(mysqli $conn, $table, $column)
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        if (!$stmt) {
            return $cache[$key] = false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $cache[$key] = (int) ($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('student_course_access_exam')) {
    function student_course_access_exam(mysqli $conn, $examId, $courseId)
    {
        $examId = student_course_access_identifier($examId, 40);
        $courseId = student_course_access_identifier($courseId, 40);
        if ($examId === null || $courseId === null) {
            return null;
        }

        $hasSection = student_course_access_table_column($conn, 'exams', 'section_id');
        $hasItem = student_course_access_table_column($conn, 'exams', 'item_id');
        $columns = 'exam_id, course_id';
        if ($hasSection) {
            $columns .= ', section_id';
        }
        if ($hasItem) {
            $columns .= ', item_id';
        }

        $stmt = $conn->prepare("SELECT {$columns} FROM exams WHERE exam_id = ? AND course_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $examId, $courseId);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exam) {
            return null;
        }

        $exam['section_id'] = $hasSection ? ($exam['section_id'] ?? '') : '';
        $exam['item_id'] = $hasItem ? ($exam['item_id'] ?? '') : '';
        return $exam;
    }
}

if (!function_exists('student_course_access_completion_source_allowed')) {
    function student_course_access_completion_source_allowed(mysqli $conn, array $section, $source, $userId)
    {
        $source = trim((string) $source);
        $rule = trim((string) ($section['completion_rule'] ?? 'manual_completion')) ?: 'manual_completion';

        if ($rule === 'opening_section') {
            return $source === 'opening_section';
        }
        if (in_array($rule, ['manual_completion', 'watching_recordings', 'viewing_notes', 'all_lessons_completed'], true)) {
            // These rules are currently completed through the existing manual
            // section action; no lesson-progress store exists in this phase.
            return $source === 'manual_completion';
        }
        if ($rule === 'homework_submitted') {
            return $source === 'homework_submitted'
                && student_course_access_homework_done($conn, $section['unlock_homework_id'] ?? '', $userId, false);
        }
        if ($rule === 'homework_approved') {
            return $source === 'homework_approved'
                && student_course_access_homework_done($conn, $section['unlock_homework_id'] ?? '', $userId, true);
        }

        return false;
    }
}
