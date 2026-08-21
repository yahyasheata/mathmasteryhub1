<?php
/**
 * Shared assignment lifecycle and completion resolver.
 *
 * This layer is intentionally read-only. It derives a student's assignment
 * state from existing assignments/submissions and the three requirement
 * settings added by the schema bootstrap. Pages and access helpers use the
 * same result instead of each inferring "complete" differently.
 */
require_once __DIR__ . '/learning_schema.php';
require_once __DIR__ . '/CourseResourceResolver.php';
require_once __DIR__ . '/CourseAssignmentLinks.php';

if (!function_exists('mmh_assignment_progress_id')) {
    function mmh_assignment_progress_id($value, $maxLength = 40)
    {
        $value = trim((string) $value);
        return $value !== '' && strlen($value) <= (int) $maxLength && preg_match('/\A[A-Za-z0-9_-]+\z/', $value) ? $value : null;
    }
}

if (!function_exists('mmh_assignment_progress_section_id')) {
    function mmh_assignment_progress_section_id($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '__general__') {
            return '';
        }
        return mmh_assignment_progress_id($value);
    }
}

if (!function_exists('mmh_assignment_progress_requirement_scope')) {
    function mmh_assignment_progress_requirement_scope($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['optional', 'lesson', 'section'], true) ? $value : 'optional';
    }
}

if (!function_exists('mmh_assignment_progress_completion_rule')) {
    function mmh_assignment_progress_completion_rule($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['submission', 'teacher_approval', 'valid_score', 'minimum_score'], true) ? $value : 'submission';
    }
}

if (!function_exists('mmh_assignment_progress_requirement_label')) {
    function mmh_assignment_progress_requirement_label($scope)
    {
        $labels = [
            'optional' => 'Optional',
            'lesson' => 'Required for lesson completion',
            'section' => 'Required for section completion',
        ];
        $scope = mmh_assignment_progress_requirement_scope($scope);
        return $labels[$scope];
    }
}

if (!function_exists('mmh_assignment_progress_rule_label')) {
    function mmh_assignment_progress_rule_label($rule, $minimumScore = null)
    {
        $rule = mmh_assignment_progress_completion_rule($rule);
        if ($rule === 'teacher_approval') {
            return 'Teacher approval required';
        }
        if ($rule === 'valid_score') {
            return 'Valid final score required';
        }
        if ($rule === 'minimum_score') {
            $score = is_numeric($minimumScore) ? rtrim(rtrim(number_format((float) $minimumScore, 2, '.', ''), '0'), '.') : '';
            return $score === '' ? 'Minimum valid score required' : 'Minimum score: ' . $score;
        }
        return 'Submission required';
    }
}

if (!function_exists('mmh_assignment_late_submission_active')) {
    /** Whether an explicit legacy late-submission exception is open now. */
    function mmh_assignment_late_submission_active(array $assignment, $now = null)
    {
        if ((int) ($assignment['late_submission_enabled'] ?? 0) !== 1) {
            return false;
        }
        $until = trim((string) ($assignment['late_submission_until'] ?? ''));
        $timestamp = $until === '' ? false : strtotime($until);
        $now = $now === null ? time() : (int) $now;
        return $timestamp !== false && $timestamp >= $now;
    }
}

if (!function_exists('mmh_assignment_submission_open')) {
    /** Preserves normal due dates unless an administrator opened a live exception. */
    function mmh_assignment_submission_open(array $assignment, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $due = trim((string) ($assignment['due_date'] ?? ''));
        $dueTimestamp = $due === '' ? false : strtotime($due);
        return $dueTimestamp === false || $dueTimestamp >= $now || mmh_assignment_late_submission_active($assignment, $now);
    }
}

if (!function_exists('mmh_assignment_submission_max_files')) {
    function mmh_assignment_submission_max_files(): int
    {
        $value = defined('MMH_ASSIGNMENT_MAX_FILES') ? (int) constant('MMH_ASSIGNMENT_MAX_FILES') : 10;
        return max(1, min($value, 25));
    }
}

if (!function_exists('mmh_assignment_submission_max_file_bytes')) {
    function mmh_assignment_submission_max_file_bytes(): int
    {
        $value = defined('MMH_ASSIGNMENT_MAX_FILE_BYTES') ? (int) constant('MMH_ASSIGNMENT_MAX_FILE_BYTES') : 20 * 1024 * 1024;
        return max(1024 * 1024, min($value, 50 * 1024 * 1024));
    }
}

if (!function_exists('mmh_assignment_submission_allowed_mimes')) {
    function mmh_assignment_submission_allowed_mimes(): array
    {
        return [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ];
    }
}

if (!function_exists('mmh_assignment_submission_file_url')) {
    function mmh_assignment_submission_file_url($baseUrl, $fileId, bool $admin = false): string
    {
        $fileId = filter_var($fileId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($fileId === false) return '';
        return rtrim((string) $baseUrl, '/') . ($admin ? '/admin/submission-file/' : '/user/submission-file/') . (int) $fileId;
    }
}

if (!function_exists('mmh_assignment_submission_files')) {
    /** Return imported attachments when present; legacy and LMS rows fall back to file_path. */
    function mmh_assignment_submission_files(mysqli $conn, ?array $submission)
    {
        if (!$submission || empty($submission['id'])) {
            return [];
        }
        $files = [];
        $submissionId = (int) $submission['id'];
        $stmt = $conn->prepare('SELECT id, file_path, original_filename FROM assignment_submission_files WHERE submission_id = ? ORDER BY id ASC');
        if ($stmt) {
            $stmt->bind_param('i', $submissionId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $files[] = $row;
                }
            }
            $stmt->close();
        }
        if (!$files && trim((string) ($submission['file_path'] ?? '')) !== '') {
            $files[] = ['id' => null, 'file_path' => (string) $submission['file_path'], 'original_filename' => null];
        }
        return $files;
    }
}

if (!function_exists('mmh_assignment_progress_latest_submissions')) {
    function mmh_assignment_progress_latest_submissions(mysqli $conn, $studentId, $courseId)
    {
        $rows = [];
        $studentId = (int) $studentId;
        $courseId = mmh_assignment_progress_id($courseId);
        if ($studentId <= 0 || $courseId === null) {
            return $rows;
        }
        $stmt = $conn->prepare('SELECT s.id, s.assignment_id, s.student_id, s.file_path, s.submitted_at, s.grade, s.feedback, s.self_score, s.self_score_status, s.verification_note, s.verified_at, s.verified_by, s.submission_source, s.imported_by, s.imported_at, s.original_submitted_at, s.import_notes FROM assignment_submissions AS s INNER JOIN assignments AS a ON a.assignment_id = s.assignment_id WHERE s.student_id = ? AND a.course_id = ? ORDER BY s.assignment_id ASC, s.submitted_at DESC, s.id DESC');
        if (!$stmt) {
            return $rows;
        }
        $stmt->bind_param('is', $studentId, $courseId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $assignmentId = (string) $row['assignment_id'];
                if (!isset($rows[$assignmentId])) {
                    $rows[$assignmentId] = $row;
                }
            }
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_assignment_progress_decode_template_data')) {
    function mmh_assignment_progress_decode_template_data($value)
    {
        $data = json_decode((string) $value, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('mmh_assignment_progress_legacy_assignment_ids')) {
    function mmh_assignment_progress_legacy_assignment_ids($html)
    {
        $html = (string) $html;
        if ($html === '' || stripos($html, 'show-assignment') === false) {
            return [];
        }
        preg_match_all('/\bdata-assignment-id\s*=\s*(["\'])\s*([A-Za-z0-9_-]{1,40})\s*\1/i', $html, $matches);
        $ids = [];
        foreach ($matches[2] ?? [] as $candidate) {
            $assignmentId = mmh_assignment_progress_id($candidate);
            if ($assignmentId !== null) {
                $ids[$assignmentId] = true;
            }
        }
        return array_keys($ids);
    }
}

if (!function_exists('mmh_assignment_progress_course_sources')) {
    function mmh_assignment_progress_repair_duplicate_sources(mysqli $conn, $courseId, array $items): void
    {
        $groups = [];
        foreach ($items as $item) {
            $assignmentId = mmh_course_assignment_id($item);
            $templateType = strtolower(trim((string) ($item['template_type'] ?? '')));
            $itemType = strtolower(trim((string) ($item['item_type'] ?? '')));
            if ($assignmentId === '' || !(in_array($templateType, ['classified_assignment', 'assignment', 'homework'], true) || in_array($itemType, ['quiz', 'assignment', 'homework'], true))) {
                continue;
            }
            $groups[$assignmentId][] = $item;
        }
        $duplicates = array_filter($groups, static fn(array $group): bool => count($group) > 1);
        if (!$duplicates) {
            return;
        }

        $conn->begin_transaction();
        try {
            foreach ($duplicates as $sourceAssignmentId => $group) {
                $ownerStmt = $conn->prepare('SELECT item_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1 FOR UPDATE');
                $ownerStmt->bind_param('ss', $sourceAssignmentId, $courseId);
                $ownerStmt->execute();
                $owner = trim((string) ($ownerStmt->get_result()->fetch_assoc()['item_id'] ?? ''));
                $ownerStmt->close();
                $ownerKept = false;
                foreach ($group as $item) {
                    $itemId = (string) ($item['item_id'] ?? '');
                    if ($itemId === '') {
                        continue;
                    }
                    $currentStmt = $conn->prepare('SELECT assignment_id FROM course_items WHERE course_id = ? AND item_id = ? LIMIT 1 FOR UPDATE');
                    $currentStmt->bind_param('ss', $courseId, $itemId);
                    $currentStmt->execute();
                    $currentId = trim((string) ($currentStmt->get_result()->fetch_assoc()['assignment_id'] ?? ''));
                    $currentStmt->close();
                    if ($currentId !== $sourceAssignmentId) {
                        continue;
                    }
                    if (($owner !== '' && $itemId === $owner) || ($owner === '' && !$ownerKept)) {
                        $ownerKept = true;
                        continue;
                    }
                    $sectionId = (string) ($item['section_id'] ?? '');
                    $newId = mmh_course_assignment_clone_for_item($conn, (string) $courseId, $sourceAssignmentId, $itemId, $sectionId);
                    if ($newId === null) {
                        throw new RuntimeException('Unable to clone visible assignment ' . $sourceAssignmentId . '.');
                    }
                    mmh_course_assignment_relink_item($conn, (string) $courseId, $itemId, $sourceAssignmentId, $newId);
                }
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    function mmh_assignment_progress_course_sources(mysqli $conn, $courseId)
    {
        $courseId = mmh_assignment_progress_id($courseId);
        if ($courseId === null) {
            return ['items' => [], 'assignments' => []];
        }

        $stmt = $conn->prepare(
            "SELECT i.id, i.item_id, i.item_title, i.item_description, i.course_id, i.section_id, i.item_type,
                    i.template_type, i.template_data, i.assignment_id, i.due_date, i.page_order, i.sort_order,
                    s.title AS section_title, s.sort_order AS section_sort_order,
                    i.sort_order AS item_sort_order
             FROM course_items AS i
             LEFT JOIN course_sections AS s
                ON s.course_id = i.course_id
               AND s.section_id = i.section_id
             WHERE i.course_id = ?
               AND (i.status IS NULL OR i.status = '' OR i.status = 'published')
               AND (i.section_id IS NULL OR i.section_id = '' OR s.status IS NULL OR s.status = '' OR s.status = 'published')
             ORDER BY COALESCE(s.sort_order, 2147483647) ASC,
                      COALESCE(i.sort_order, i.page_order, i.id) ASC,
                      i.id ASC"
        );
        if (!$stmt) {
            return ['items' => [], 'assignments' => []];
        }

        $stmt->bind_param('s', $courseId);
        $items = [];
        $visibleRows = [];
        $assignmentSources = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($item = $result->fetch_assoc()) {
                $itemId = mmh_assignment_progress_id($item['item_id'] ?? '');
                if ($itemId === null) {
                    continue;
                }

                $visibleRows[] = $item;
            }
        }
        $stmt->close();

        // Production courses may contain copied homework cards that still
        // point at the source assignment. Repair those relationships from the
        // visible course structure before building the student list.
        mmh_assignment_progress_repair_duplicate_sources($conn, $courseId, $visibleRows);
        foreach ($visibleRows as $item) {
                $itemId = mmh_assignment_progress_id($item['item_id'] ?? '');
                if ($itemId === null) {
                    continue;
                }

                $source = [
                    'item_id' => $itemId,
                    'section_id' => mmh_assignment_progress_section_id($item['section_id'] ?? '') ?? '',
                    'item_title' => (string) ($item['item_title'] ?? ''),
                    'section_title' => (string) ($item['section_title'] ?? ''),
                    'section_sort_order' => (int) ($item['section_sort_order'] ?? PHP_INT_MAX),
                    'due_date' => (string) ($item['due_date'] ?? ''),
                    'item_sort_order' => (int) ($item['item_sort_order'] ?? $item['sort_order'] ?? $item['page_order'] ?? $item['id'] ?? PHP_INT_MAX),
                    'row_id' => (int) ($item['id'] ?? 0),
                ];
                $items[$itemId] = $source;

                $templateType = strtolower(trim((string) ($item['template_type'] ?? '')));
                $itemType = strtolower(trim((string) ($item['item_type'] ?? '')));
                $assignmentLinks = mmh_course_assignment_links($item);
                $isAssignmentItem = in_array($templateType, ['classified_assignment', 'assignment', 'homework', 'exam'], true)
                    || in_array($itemType, ['quiz', 'assignment', 'homework'], true)
                    || !empty($assignmentLinks);

                if (!$isAssignmentItem) {
                    continue;
                }

                foreach (array_keys($assignmentLinks) as $assignmentId) {
                    if (!isset($assignmentSources[$assignmentId])) {
                        $assignmentSources[$assignmentId] = $source;
                    }
                }
        }

        return ['items' => $items, 'assignments' => $assignmentSources];
    }
}

if (!function_exists('mmh_assignment_progress_is_teacher_confirmed')) {
    function mmh_assignment_progress_is_teacher_confirmed(?array $submission)
    {
        if (!$submission) {
            return false;
        }
        $status = strtolower(trim((string) ($submission['self_score_status'] ?? '')));
        if (in_array($status, ['verified', 'corrected_by_teacher'], true)) {
            return true;
        }
        // Legacy records used a teacher-entered grade before verification
        // statuses existed; preserve that established completion evidence.
        return in_array($status, ['', 'not_required'], true)
            && trim((string) ($submission['grade'] ?? '')) !== '';
    }
}

if (!function_exists('mmh_assignment_progress_valid_score')) {
    function mmh_assignment_progress_valid_score(?array $submission)
    {
        if (!$submission) {
            return null;
        }
        $status = strtolower(trim((string) ($submission['self_score_status'] ?? '')));
        if ($status === 'rejected' || !is_numeric($submission['grade'] ?? null)) {
            return null;
        }
        if (in_array($status, ['verified', 'corrected_by_teacher', 'auto_accepted', '', 'not_required'], true)) {
            return (float) $submission['grade'];
        }
        return null;
    }
}

if (!function_exists('mmh_assignment_progress_evaluate')) {
    function mmh_assignment_progress_evaluate(array $assignment, ?array $submission = null, $ruleOverride = null, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $scope = mmh_assignment_progress_requirement_scope($assignment['completion_requirement'] ?? 'optional');
        $rule = $ruleOverride === null
            ? mmh_assignment_progress_completion_rule($assignment['completion_rule'] ?? 'submission')
            : mmh_assignment_progress_completion_rule($ruleOverride);
        $minimumScore = is_numeric($assignment['minimum_score'] ?? null) ? (float) $assignment['minimum_score'] : null;
        $required = $scope !== 'optional';
        $submitted = $submission !== null;
        $verification = strtolower(trim((string) ($submission['self_score_status'] ?? '')));
        $dueTimestamp = !empty($assignment['due_date']) ? strtotime((string) $assignment['due_date']) : false;
        $lateWindowOpen = !$submitted && mmh_assignment_late_submission_active($assignment, $now);
        $overdue = !$submitted && $dueTimestamp !== false && $dueTimestamp < $now && !$lateWindowOpen;
        $dueSoon = !$submitted && $dueTimestamp !== false && $dueTimestamp >= $now && $dueTimestamp <= ($now + 72 * 3600);
        $teacherConfirmed = mmh_assignment_progress_is_teacher_confirmed($submission);
        $validScore = mmh_assignment_progress_valid_score($submission);
        $complete = false;
        $state = 'not_started';
        $reason = 'Start this assignment when you are ready.';
        $action = 'submit';

        if ($submitted && $verification === 'rejected') {
            $state = 'needs_revision';
            $reason = 'Your teacher requested a revision before this requirement is complete.';
            $action = 'revise';
        } elseif ($submitted && $rule === 'submission') {
            $complete = true;
            $state = $required ? 'submitted' : 'optional_completed';
            $reason = $required ? 'Submission received.' : 'Optional assignment completed.';
            $action = 'view';
        } elseif ($submitted && $rule === 'teacher_approval') {
            if ($teacherConfirmed) {
                $complete = true;
                $state = $required ? 'approved' : 'optional_completed';
                $reason = $required ? 'Approved by your teacher.' : 'Optional assignment approved.';
                $action = 'view';
            } else {
                $state = 'awaiting_review';
                $reason = 'Submitted and waiting for teacher confirmation.';
                $action = 'wait';
            }
        } elseif ($submitted && $rule === 'valid_score') {
            if ($validScore !== null) {
                $complete = true;
                $state = $required ? 'approved' : 'optional_completed';
                $reason = $required ? 'A valid final score has been recorded.' : 'Optional assignment has a valid final score.';
                $action = 'view';
            } else {
                $state = 'awaiting_review';
                $reason = 'Submitted and waiting for a valid final score.';
                $action = 'wait';
            }
        } elseif ($submitted && $rule === 'minimum_score') {
            if ($validScore !== null && $minimumScore !== null && $validScore >= $minimumScore) {
                $complete = true;
                $state = $required ? 'approved' : 'optional_completed';
                $reason = 'Minimum score requirement met.';
                $action = 'view';
            } elseif ($validScore !== null && $minimumScore !== null) {
                $state = 'needs_revision';
                $reason = 'The minimum score has not been met yet.';
                $action = 'revise';
            } else {
                $state = 'awaiting_review';
                $reason = 'Submitted and waiting for a valid final score.';
                $action = 'wait';
            }
        } elseif (!$submitted && $lateWindowOpen) {
            $state = 'legacy_late';
            $reason = 'Legacy late submission is temporarily available until ' . (string) ($assignment['late_submission_until'] ?? '') . '.';
            $action = 'submit';
        } elseif (!$submitted && $overdue) {
            $state = 'overdue';
            $reason = 'The submission deadline has passed.';
            $action = 'view';
        } elseif (!$submitted && $dueSoon) {
            $state = 'due_soon';
            $reason = 'This assignment is due soon.';
            $action = 'submit';
        }

        $labels = [
            'not_started' => 'Not started',
            'due_soon' => 'Due soon',
            'submitted' => 'Submitted',
            'awaiting_review' => 'Awaiting teacher review',
            'approved' => 'Approved',
            'needs_revision' => 'Needs revision',
            'overdue' => 'Overdue',
            'legacy_late' => 'Legacy Late Submission',
            'optional_completed' => 'Optional completed',
        ];
        return [
            'state' => $state,
            'label' => $labels[$state] ?? 'Not started',
            'required' => $required,
            'completion_requirement' => $scope,
            'completion_rule' => $rule,
            'completion_label' => mmh_assignment_progress_rule_label($rule, $minimumScore),
            'minimum_score' => $minimumScore,
            'complete' => $complete,
            'blocked' => $required && !$complete,
            'blocking_reason' => $required && !$complete ? $reason : '',
            'reason' => $reason,
            'action' => $action,
            'submitted' => $submitted,
            'overdue' => $overdue,
            'late_submission_open' => $lateWindowOpen,
            'due_soon' => $dueSoon,
            'due_timestamp' => $dueTimestamp === false ? null : $dueTimestamp,
            'teacher_confirmed' => $teacherConfirmed,
            'valid_score' => $validScore,
        ];
    }
}

if (!function_exists('mmh_assignment_progress_load_course')) {
    function mmh_assignment_progress_load_course(mysqli $conn, $studentId, $courseId)
    {
        mmh_ensure_learning_schema($conn);
        $studentId = (int) $studentId;
        $courseId = mmh_assignment_progress_id($courseId);
        if ($studentId <= 0 || $courseId === null) {
            return [];
        }
        $sources = mmh_assignment_progress_course_sources($conn, $courseId);
        $activeItems = $sources['items'];
        $assignmentSources = $sources['assignments'];
        $stmt = $conn->prepare(
            "SELECT a.assignment_id, a.assignment_title, a.assignment_description, a.due_date, a.late_submission_enabled, a.late_submission_until,
                    a.file_path, a.course_id, a.section_id, a.item_id, a.max_score, a.passing_score, a.allow_self_score,
                    a.require_teacher_verification, a.completion_requirement, a.completion_rule, a.minimum_score, a.id
             FROM assignments AS a
             WHERE a.course_id = ?
             ORDER BY a.id ASC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $courseId);
        $assignments = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $assignmentId = (string) $row['assignment_id'];
                $source = null;
                $itemId = mmh_assignment_progress_id($row['item_id'] ?? '');
                if ($itemId !== null && isset($activeItems[$itemId])) {
                    $source = $activeItems[$itemId];
                } elseif (isset($assignmentSources[$assignmentId])) {
                    $source = $assignmentSources[$assignmentId];
                }
                if ($source === null) {
                    continue;
                }

                if (trim((string) ($row['item_id'] ?? '')) === '') {
                    $row['item_id'] = $source['item_id'];
                }
                if (trim((string) ($row['section_id'] ?? '')) === '') {
                    $row['section_id'] = $source['section_id'];
                }
                $row['_source_item_id'] = $source['item_id'];
                $row['_source_section_id'] = $source['section_id'];
                $row['_source_item_title'] = $source['item_title'];
                $row['_source_section_title'] = $source['section_title'];
                $row['_section_sort_order'] = $source['section_sort_order'];
                $row['_item_sort_order'] = $source['item_sort_order'];
                $row['_source_row_id'] = $source['row_id'];
                unset($row['id']);
                $assignments[$assignmentId] = $row;
            }
        }
        $stmt->close();
        $submissions = mmh_assignment_progress_latest_submissions($conn, $studentId, $courseId);
        foreach ($assignments as $assignmentId => &$assignment) {
            $assignment['_submission'] = $submissions[$assignmentId] ?? null;
            $assignment['_state'] = mmh_assignment_progress_evaluate($assignment, $assignment['_submission']);
        }
        unset($assignment);
        // The course structure is authoritative. Assignment state and due
        // date must never change the pedagogical order of the list.
        uksort($assignments, static function ($a, $b) use (&$assignments) {
            $left = $assignments[$a];
            $right = $assignments[$b];
            return ((int) ($left['_section_sort_order'] ?? PHP_INT_MAX) <=> (int) ($right['_section_sort_order'] ?? PHP_INT_MAX))
                ?: ((int) ($left['_item_sort_order'] ?? PHP_INT_MAX) <=> (int) ($right['_item_sort_order'] ?? PHP_INT_MAX))
                ?: ((int) ($left['_source_row_id'] ?? PHP_INT_MAX) <=> (int) ($right['_source_row_id'] ?? PHP_INT_MAX))
                ?: strcmp((string) $a, (string) $b);
        });
        return $assignments;
    }
}

if (!function_exists('mmh_assignment_progress_section_state')) {
    function mmh_assignment_progress_section_state(array $assignmentMap, $sectionId)
    {
        $sectionId = mmh_assignment_progress_section_id($sectionId);
        $requirements = [];
        if ($sectionId === null) {
            return ['has_requirements' => false, 'complete' => true, 'required_count' => 0, 'completed_count' => 0, 'outstanding_count' => 0, 'requirements' => [], 'blocking_reason' => ''];
        }
        foreach ($assignmentMap as $assignment) {
            $scope = mmh_assignment_progress_requirement_scope($assignment['completion_requirement'] ?? 'optional');
            if ($scope === 'optional' || !in_array($scope, ['lesson', 'section'], true)) {
                continue;
            }
            if (mmh_assignment_progress_section_id($assignment['section_id'] ?? '') !== $sectionId) {
                continue;
            }
            $requirements[] = $assignment;
        }
        $completed = 0;
        $outstanding = [];
        foreach ($requirements as $assignment) {
            if (!empty($assignment['_state']['complete'])) {
                $completed++;
            } else {
                $outstanding[] = $assignment;
            }
        }
        $first = $outstanding[0] ?? null;
        return [
            'has_requirements' => count($requirements) > 0,
            'complete' => count($requirements) === $completed,
            'required_count' => count($requirements),
            'completed_count' => $completed,
            'outstanding_count' => count($outstanding),
            'requirements' => $requirements,
            'outstanding' => $outstanding,
            'blocking_reason' => $first ? 'Required work remaining: ' . (string) ($first['assignment_title'] ?? 'Assignment') . ' — ' . (string) ($first['_state']['label'] ?? 'Not started') . '.' : '',
        ];
    }
}

if (!function_exists('mmh_assignment_progress_item_state')) {
    function mmh_assignment_progress_item_state(array $assignmentMap, $itemId, $sectionId = '')
    {
        $itemId = mmh_assignment_progress_id($itemId);
        $sectionId = mmh_assignment_progress_section_id($sectionId);
        $requirements = [];
        if ($itemId === null || $sectionId === null) {
            return ['has_requirements' => false, 'complete' => true, 'required_count' => 0, 'completed_count' => 0, 'outstanding_count' => 0, 'requirements' => [], 'blocking_reason' => ''];
        }
        foreach ($assignmentMap as $assignment) {
            if (mmh_assignment_progress_requirement_scope($assignment['completion_requirement'] ?? 'optional') !== 'lesson') {
                continue;
            }
            if ((string) ($assignment['item_id'] ?? '') !== $itemId || mmh_assignment_progress_section_id($assignment['section_id'] ?? '') !== $sectionId) {
                continue;
            }
            $requirements[] = $assignment;
        }
        $completed = 0;
        $outstanding = [];
        foreach ($requirements as $assignment) {
            if (!empty($assignment['_state']['complete'])) {
                $completed++;
            } else {
                $outstanding[] = $assignment;
            }
        }
        $first = $outstanding[0] ?? null;
        return [
            'has_requirements' => count($requirements) > 0,
            'complete' => count($requirements) === $completed,
            'required_count' => count($requirements),
            'completed_count' => $completed,
            'outstanding_count' => count($outstanding),
            'requirements' => $requirements,
            'outstanding' => $outstanding,
            'blocking_reason' => $first ? 'Required work remaining: ' . (string) ($first['assignment_title'] ?? 'Assignment') . ' — ' . (string) ($first['_state']['label'] ?? 'Not started') . '.' : '',
        ];
    }
}

if (!function_exists('mmh_assignment_progress_attention')) {
    function mmh_assignment_progress_attention(array $assignmentMap)
    {
        $priority = ['overdue' => 0, 'needs_revision' => 1, 'due_soon' => 2, 'awaiting_review' => 3, 'not_started' => 4, 'submitted' => 5, 'approved' => 6, 'optional_completed' => 7];
        $rows = [];
        foreach ($assignmentMap as $assignment) {
            $state = $assignment['_state'] ?? [];
            if (empty($state['blocked'])) {
                continue;
            }
            $assignment['_priority'] = $priority[$state['state'] ?? 'not_started'] ?? 99;
            $rows[] = $assignment;
        }
        usort($rows, function ($a, $b) {
            $priority = (int) ($a['_priority'] ?? 99) <=> (int) ($b['_priority'] ?? 99);
            if ($priority !== 0) {
                return $priority;
            }
            return strcmp((string) ($a['due_date'] ?? ''), (string) ($b['due_date'] ?? ''));
        });
        return $rows;
    }
}

if (!function_exists('mmh_assignment_progress_validate_context')) {
    function mmh_assignment_progress_validate_context(mysqli $conn, $courseId, $sectionId, $itemId, $scope)
    {
        $courseId = mmh_assignment_progress_id($courseId);
        $scope = mmh_assignment_progress_requirement_scope($scope);
        $sectionId = mmh_assignment_progress_section_id($sectionId);
        $itemId = trim((string) $itemId);
        $itemId = $itemId === '' ? '' : mmh_assignment_progress_id($itemId);
        if ($courseId === null || $sectionId === null || $itemId === null) {
            return ['ok' => false, 'message' => 'Invalid course-content reference.'];
        }
        if ($scope === 'lesson' && $itemId === '') {
            return ['ok' => false, 'message' => 'Choose the lesson this required assignment completes.'];
        }
        if ($scope === 'section' && $sectionId === '') {
            return ['ok' => false, 'message' => 'Choose the section this required assignment completes.'];
        }
        if ($sectionId !== '') {
            $stmt = $conn->prepare('SELECT section_id FROM course_sections WHERE course_id = ? AND section_id = ? LIMIT 1');
            if (!$stmt) {
                return ['ok' => false, 'message' => 'Unable to validate the selected section.'];
            }
            $stmt->bind_param('ss', $courseId, $sectionId);
            $stmt->execute();
            $valid = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if (!$valid) {
                return ['ok' => false, 'message' => 'The selected section does not belong to this course.'];
            }
        }
        if ($itemId !== '') {
            $stmt = $conn->prepare('SELECT section_id FROM course_items WHERE course_id = ? AND item_id = ? LIMIT 1');
            if (!$stmt) {
                return ['ok' => false, 'message' => 'Unable to validate the selected lesson.'];
            }
            $stmt->bind_param('ss', $courseId, $itemId);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$item) {
                return ['ok' => false, 'message' => 'The selected lesson does not belong to this course.'];
            }
            $itemSectionId = mmh_assignment_progress_section_id($item['section_id'] ?? '');
            if ($sectionId === '') {
                $sectionId = $itemSectionId;
            } elseif ($itemSectionId !== $sectionId) {
                return ['ok' => false, 'message' => 'The lesson must belong to the selected section.'];
            }
        }
        return ['ok' => true, 'course_id' => $courseId, 'section_id' => $sectionId, 'item_id' => $itemId];
    }
}
