<?php
/** Canonical Assignment service for admin reads, statistics, and grading. */
require_once __DIR__ . '/AssignmentIdentity.php';
if (!function_exists('mmh_admin_assignment_item_map')) {
    /** Return only Assignment course elements; unlinked rows remain compatibility data. */
    function mmh_admin_assignment_item_map(mysqli $conn): array
    {
        $map = [];
        $conflicts = [];
        $itemArchiveFilter = mmh_assignment_identity_column_exists($conn, 'course_items', 'archived_at') ? " AND (i.archived_at IS NULL OR i.archived_at = '')" : '';
        $result = $conn->query("SELECT i.id AS item_db_id, i.course_id, i.item_id, i.item_title, i.item_type, i.template_type, i.template_data, i.item_description, i.assignment_id, i.status AS item_status FROM course_items i WHERE i.template_type = 'classified_assignment'" . $itemArchiveFilter . " AND (i.status IS NULL OR i.status = '' OR i.status = 'published')");
        if (!$result) throw new RuntimeException('Unable to load Assignment Course Items: ' . $conn->error);
        while ($item = $result->fetch_assoc()) {
            $identity = mmh_assignment_identity_for_item($conn, $item, true);
            $assignmentId = (string) ($identity['assignment_id'] ?? '');
            if (($identity['status'] ?? '') === 'DUPLICATE_CANONICAL_CLAIM') {
                $assignmentId = (string) ($identity['canonical_assignment_id'] ?? '');
                if ($assignmentId !== '') { unset($map[$assignmentId]); $conflicts[$assignmentId] = true; }
                continue;
            }
            if ($assignmentId === '' || !in_array((string) ($identity['status'] ?? ''), ['CLEAN', 'CONFLICT', 'LEGACY_FALLBACK'], true) || isset($conflicts[$assignmentId])) continue;
            if (isset($map[$assignmentId])) { unset($map[$assignmentId]); $conflicts[$assignmentId] = true; continue; }
            $stmt = $conn->prepare('SELECT assignment_id, course_id FROM assignments WHERE assignment_id = ? AND course_id = ? AND archived_at IS NULL LIMIT 1');
            if (!$stmt) continue;
            $stmt->bind_param('ss', $assignmentId, $item['course_id']);
            $stmt->execute();
            $assignment = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$assignment) continue;
            $map[$assignmentId] = array_merge($assignment, [
                'item_id' => (string) $item['item_id'],
                'item_db_id' => (int) $item['item_db_id'],
                'item_title' => (string) $item['item_title'],
                'template_type' => (string) $item['template_type'],
                'item_status' => (string) $item['item_status'],
                '_identity_status' => (string) $identity['status'],
            ]);
        }
        $map['_identity_conflicts'] = array_fill_keys(array_keys($conflicts), 'DUPLICATE_CANONICAL_CLAIM');
        return $map;
    }
}

if (!function_exists('mmh_admin_assignment_operational_stats')) {
    /** Batch-load the operational metadata shown on Course Content cards. */
    function mmh_admin_assignment_operational_stats(mysqli $conn, string $courseId, array $assignmentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $assignmentIds), static function ($id) {
            return $id !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id);
        })));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT a.assignment_id, a.assignment_title, a.due_date, a.completion_requirement, a.completion_rule,
                    COUNT(DISTINCT s.id) AS submission_count,
                    COUNT(DISTINCT CASE WHEN s.grade IS NULL AND s.self_score IS NULL THEN s.id END) AS needs_review,
                    (SELECT COUNT(DISTINCT cl.user_id) FROM course_logs cl WHERE cl.course_id = a.course_id) AS enrolled_count
             FROM assignments a
             LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id
             WHERE a.course_id = ? AND a.archived_at IS NULL AND a.assignment_id IN ({$placeholders})
             GROUP BY a.assignment_id, a.assignment_title, a.due_date, a.completion_requirement, a.completion_rule");
        if (!$stmt) {
            return [];
        }
        $params = array_merge([$courseId], $ids);
        $types = 's' . str_repeat('s', count($ids));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $stats[(string) $row['assignment_id']] = $row;
        }
        $stmt->close();
        return $stats;
    }
}

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

if (!function_exists('mmh_admin_assignment_submission_context')) {
    function mmh_admin_assignment_submission_context(mysqli $conn, int $submissionId): ?array
    {
        $stmt = $conn->prepare('SELECT s.feedback, s.student_id, s.assignment_id, s.self_score, a.course_id, a.section_id, a.max_score FROM assignment_submissions s LEFT JOIN assignments a ON s.assignment_id = a.assignment_id WHERE s.id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        $context = mmh_assignment_identity_item_for_assignment($conn, (string) ($row['course_id'] ?? ''), (string) ($row['assignment_id'] ?? ''), false);
        if (($context['status'] ?? '') === 'FOUND' && is_array($context['item'] ?? null)) {
            $row['item_id'] = (string) ($context['item']['item_id'] ?? '');
            $row['section_id'] = (string) ($context['item']['section_id'] ?? $row['section_id'] ?? '');
        } else {
            $row['item_id'] = '';
        }
        return $row;
    }
}

if (!function_exists('mmh_admin_assignment_upsert_definition')) {
    /** Canonical create/update operation used by the Course Content element. */
    function mmh_admin_assignment_upsert_definition(mysqli $conn, array $fields, string $requestedId = '', string $editedItemId = ''): string
    {
        $courseId = (string) ($fields['course_id'] ?? '');
        $allowed = ['assignment_title', 'assignment_description', 'due_date', 'late_submission_enabled', 'late_submission_until', 'file_path', 'course_id', 'section_id', 'homework_type', 'allow_self_score', 'require_teacher_verification', 'max_score', 'topic_id', 'subtopic_id', 'additional_topic_ids', 'difficulty', 'estimated_time', 'passing_score', 'weight', 'skills_tested', 'calculator_mode', 'exam_board', 'paper', 'teacher_notes', 'importance', 'category', 'learning_objectives', 'keywords', 'week', 'unit', 'term', 'syllabus_code', 'recommended_recording_item_id', 'recommended_notes_item_id', 'recommended_revision_item_id'];
        $values = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $values[$column] = $fields[$column];
            }
        }
        $existing = null;
        if ($requestedId !== '') {
            $check = $conn->prepare('SELECT assignment_id, course_id FROM assignments WHERE assignment_id = ? AND course_id = ? LIMIT 1');
            if (!$check) {
                throw new RuntimeException('Unable to validate the assignment owner.');
            }
            $check->bind_param('ss', $requestedId, $courseId);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc() ?: null;
            $check->close();
        }
        $ownsExisting = false;
        if ($existing && $editedItemId !== '') {
            $itemStmt = $conn->prepare('SELECT * FROM course_items WHERE course_id = ? AND item_id = ? LIMIT 1');
            if ($itemStmt) {
                $itemStmt->bind_param('ss', $courseId, $editedItemId);
                $itemStmt->execute();
                $editedItem = $itemStmt->get_result()->fetch_assoc() ?: null;
                $itemStmt->close();
                if ($editedItem) {
                    $identity = mmh_assignment_identity_for_item($conn, $editedItem, true);
                    $ownsExisting = (string) ($identity['assignment_id'] ?? '') === $requestedId
                        && in_array((string) ($identity['status'] ?? ''), ['CLEAN', 'CONFLICT', 'LEGACY_FALLBACK'], true);
                }
            }
        } elseif ($existing) {
            $claims = $conn->prepare('SELECT COUNT(*) AS total FROM course_items WHERE course_id = ? AND CAST(assignment_id AS CHAR) = ?');
            if ($claims) {
                $claims->bind_param('ss', $courseId, $requestedId);
                $claims->execute();
                $ownsExisting = (int) (($claims->get_result()->fetch_assoc()['total'] ?? 0)) === 0;
                $claims->close();
            }
        }
        if ($ownsExisting) {
            $sets = [];
            $params = [];
            foreach ($values as $column => $value) {
                $sets[] = $column . ' = ?';
                $params[] = $value;
            }
            $params[] = $requestedId;
            $stmt = $conn->prepare('UPDATE assignments SET ' . implode(', ', $sets) . ' WHERE assignment_id = ? LIMIT 1');
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the assignment update.');
            }
            $types = str_repeat('s', count($params));
            $refs = [$types];
            foreach ($params as $key => $value) {
                $refs[] = &$params[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException($error ?: 'Unable to update the assignment.');
            }
            $stmt->close();
            return $requestedId;
        }

        do {
            $assignmentId = (string) random_int(10000, 99999);
            $check = $conn->prepare('SELECT 1 FROM assignments WHERE assignment_id = ? LIMIT 1');
            $check->bind_param('s', $assignmentId);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();
        } while ($exists);
        $columns = array_merge(['assignment_id'], array_keys($values));
        $insertValues = array_merge([$assignmentId], array_values($values));
        $stmt = $conn->prepare('INSERT INTO assignments (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the assignment create.');
        }
        $types = str_repeat('s', count($insertValues));
        $refs = [$types];
        foreach ($insertValues as $key => $value) {
            $refs[] = &$insertValues[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error ?: 'Unable to create the assignment.');
        }
        $stmt->close();
        return $assignmentId;
    }
}

if (!function_exists('mmh_admin_assignment_save_verification')) {
    function mmh_admin_assignment_save_verification(mysqli $conn, int $submissionId, ?string $feedbackPath, ?string $grade, string $verificationStatus, string $verificationNote, ?int $verifiedBy): bool
    {
        $stmt = $conn->prepare('UPDATE assignment_submissions SET feedback = ?, grade = ?, self_score_status = ?, verification_note = ?, verified_at = NOW(), verified_by = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssssii', $feedbackPath, $grade, $verificationStatus, $verificationNote, $verifiedBy, $submissionId);
        $saved = $stmt->execute();
        $stmt->close();
        return $saved;
    }
}

if (!function_exists('mmh_admin_assignment_link_item')) {
    function mmh_admin_assignment_link_item(mysqli $conn, string $courseId, string $assignmentId, string $itemId, string $sectionId = ''): bool
    {
        if ($assignmentId === '') {
            return false;
        }
        $itemStmt = $conn->prepare('SELECT * FROM course_items WHERE course_id = ? AND item_id = ? LIMIT 1');
        if (!$itemStmt) return false;
        $itemStmt->bind_param('ss', $courseId, $itemId);
        if (!$itemStmt->execute()) { $itemStmt->close(); return false; }
        $item = $itemStmt->get_result()->fetch_assoc() ?: null;
        $itemStmt->close();
        if (!$item) return false;
        $identity = mmh_assignment_identity_for_item($conn, $item, false);
        if (($identity['assignment_id'] ?? '') !== $assignmentId
            || !in_array((string) ($identity['status'] ?? ''), ['CLEAN', 'CONFLICT'], true)) return false;
        // This only synchronizes a derived compatibility field after the
        // canonical Course Item relationship has already been saved.
        $stmt = $conn->prepare('UPDATE assignments SET item_id = ?, section_id = ? WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $section = $sectionId !== '' ? $sectionId : null;
        $stmt->bind_param('ssss', $itemId, $section, $assignmentId, $courseId);
        $saved = $stmt->execute();
        $stmt->close();
        return $saved;
    }
}

if (!function_exists('mmh_admin_assessment_source')) {
    function mmh_admin_assessment_source(array $item): string
    {
        $type = strtolower(trim((string) ($item['template_type'] ?? $item['item_type'] ?? '')));
        return $type === 'timed_exam' ? 'timed_exams' : 'assignments';
    }
}
