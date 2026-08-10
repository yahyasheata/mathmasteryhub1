<?php
/**
 * Canonical student access policy for Assignment Model Answers.
 *
 * The assignment itself remains the source of truth for the resource. This
 * service only answers whether an enrolled student may open that resource.
 */
require_once __DIR__ . '/StudentCourseAccess.php';

if (!function_exists('mmh_assignment_model_answer_access_normalize_mode')) {
    function mmh_assignment_model_answer_access_normalize_mode($mode): string
    {
        $mode = strtolower(trim((string) $mode));
        return in_array($mode, ['all', 'selected', 'none'], true) ? $mode : 'all';
    }
}

if (!function_exists('mmh_assignment_model_answer_access_mode')) {
    /** Missing/legacy policy columns deliberately preserve the old all-students behavior. */
    function mmh_assignment_model_answer_access_mode(mysqli $conn, string $assignmentId): string
    {
        if ($assignmentId === '') {
            return 'all';
        }
        $stmt = $conn->prepare('SELECT model_answer_access_mode FROM assignments WHERE assignment_id = ? AND archived_at IS NULL LIMIT 1');
        if (!$stmt) {
            return 'all';
        }
        $stmt->bind_param('s', $assignmentId);
        if (!$stmt->execute()) {
            $stmt->close();
            return 'all';
        }
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return mmh_assignment_model_answer_access_normalize_mode($row['model_answer_access_mode'] ?? 'all');
    }
}

if (!function_exists('mmh_assignment_model_answer_access_selected_ids')) {
    function mmh_assignment_model_answer_access_selected_ids(mysqli $conn, string $assignmentId): array
    {
        if ($assignmentId === '') {
            return [];
        }
        $stmt = $conn->prepare('SELECT user_id FROM assignment_model_answer_access WHERE assignment_id = ? ORDER BY user_id ASC');
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $assignmentId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['user_id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        $stmt->close();
        return array_values(array_unique($ids));
    }
}

if (!function_exists('mmh_assignment_model_answer_access_enrolled_students')) {
    /** Only active student accounts with an enrollment row are returned. */
    function mmh_assignment_model_answer_access_enrolled_students(mysqli $conn, string $courseId, string $search = ''): array
    {
        if ($courseId === '') return [];
        $sql = "SELECT DISTINCT u.user_id, u.full_name, u.username
                FROM course_logs cl
                INNER JOIN users u ON u.user_id = cl.user_id
                WHERE cl.course_id = ? AND u.role = 'user' AND u.status = '1' AND u.archived_at IS NULL";
        $params = [$courseId];
        $types = 's';
        $search = trim($search);
        if ($search !== '') {
            $sql .= ' AND (u.full_name LIKE ? OR u.username LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $types .= 'ss';
        }
        $sql .= ' ORDER BY u.full_name ASC, u.username ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $refs = [$types];
        foreach ($params as $key => $value) $refs[] = &$params[$key];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_assignment_model_answer_access_can')) {
    /** Policy check for the protected Model Answer only. */
    function mmh_assignment_model_answer_access_can(mysqli $conn, string $assignmentId, string $courseId, int $studentId): bool
    {
        if ($assignmentId === '' || $courseId === '' || $studentId <= 0) return false;
        $assignment = $conn->prepare('SELECT assignment_id, course_id FROM assignments WHERE assignment_id = ? AND course_id = ? AND archived_at IS NULL LIMIT 1');
        if (!$assignment) return false;
        $assignment->bind_param('ss', $assignmentId, $courseId);
        $assignment->execute();
        $row = $assignment->get_result()->fetch_assoc() ?: null;
        $assignment->close();
        if (!$row || !student_course_access_enrolled($conn, $studentId, (string) $row['course_id'])) return false;

        $mode = mmh_assignment_model_answer_access_mode($conn, $assignmentId);
        if ($mode === 'none') return false;
        if ($mode === 'all') return true;

        $stmt = $conn->prepare('SELECT 1 FROM assignment_model_answer_access WHERE assignment_id = ? AND user_id = ? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('si', $assignmentId, $studentId);
        $stmt->execute();
        $allowed = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $allowed;
    }
}

if (!function_exists('mmh_assignment_model_answer_access_save')) {
    /** Must be called inside the caller's existing assignment transaction. */
    function mmh_assignment_model_answer_access_save(mysqli $conn, string $assignmentId, string $courseId, $mode, array $selectedIds): void
    {
        $mode = mmh_assignment_model_answer_access_normalize_mode($mode);
        $assignment = $conn->prepare('SELECT assignment_id, course_id FROM assignments WHERE assignment_id = ? AND course_id = ? AND archived_at IS NULL LIMIT 1 FOR UPDATE');
        if (!$assignment) throw new RuntimeException('Unable to validate Model Answer access policy.');
        $assignment->bind_param('ss', $assignmentId, $courseId);
        if (!$assignment->execute()) {
            $assignment->close();
            throw new RuntimeException('Unable to validate Model Answer access policy.');
        }
        $exists = $assignment->get_result()->fetch_assoc();
        $assignment->close();
        if (!$exists) throw new RuntimeException('Assignment not found while saving Model Answer access.');

        $clean = [];
        foreach ($selectedIds as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) $clean[(int) $id] = (int) $id;
        }
        $clean = array_values($clean);
        if ($mode !== 'selected') $clean = [];
        if ($mode === 'selected' && $clean) {
            $placeholders = implode(',', array_fill(0, count($clean), '?'));
            $sql = "SELECT DISTINCT u.user_id FROM course_logs cl INNER JOIN users u ON u.user_id = cl.user_id
                    WHERE cl.course_id = ? AND u.role = 'user' AND u.status = '1' AND u.archived_at IS NULL
                      AND u.user_id IN ({$placeholders})";
            $params = array_merge([$courseId], $clean);
            $types = 's' . str_repeat('i', count($clean));
            $check = $conn->prepare($sql);
            if (!$check) throw new RuntimeException('Unable to validate selected students.');
            $refs = [$types];
            foreach ($params as $key => $value) $refs[] = &$params[$key];
            call_user_func_array([$check, 'bind_param'], $refs);
            $check->execute();
            $valid = [];
            $result = $check->get_result();
            while ($row = $result->fetch_assoc()) $valid[(int) $row['user_id']] = true;
            $check->close();
            if (count($valid) !== count($clean)) throw new RuntimeException('Selected students must be active and enrolled in this course.');
        }

        $update = $conn->prepare('UPDATE assignments SET model_answer_access_mode = ? WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        if (!$update) throw new RuntimeException('Unable to save Model Answer access mode.');
        $update->bind_param('sss', $mode, $assignmentId, $courseId);
        if (!$update->execute()) {
            $error = $update->error;
            $update->close();
            throw new RuntimeException($error ?: 'Unable to save Model Answer access mode.');
        }
        $update->close();

        $delete = $conn->prepare('DELETE FROM assignment_model_answer_access WHERE assignment_id = ?');
        if (!$delete) throw new RuntimeException('Unable to replace Model Answer access list.');
        $delete->bind_param('s', $assignmentId);
        if (!$delete->execute()) {
            $error = $delete->error;
            $delete->close();
            throw new RuntimeException($error ?: 'Unable to replace Model Answer access list.');
        }
        $delete->close();
        if ($mode !== 'selected') return;

        $insert = $conn->prepare('INSERT INTO assignment_model_answer_access (assignment_id, user_id) VALUES (?, ?)');
        if (!$insert) throw new RuntimeException('Unable to save Model Answer access list.');
        foreach ($clean as $id) {
            $insert->bind_param('si', $assignmentId, $id);
            if (!$insert->execute()) {
                $error = $insert->error;
                $insert->close();
                throw new RuntimeException($error ?: 'Unable to save Model Answer access list.');
            }
        }
        $insert->close();
    }
}

if (!function_exists('mmh_assignment_model_answer_access_clone')) {
    /** Copy policy to a duplicate, retaining only users enrolled in the destination course. */
    function mmh_assignment_model_answer_access_clone(mysqli $conn, string $sourceAssignmentId, string $newAssignmentId, string $courseId): void
    {
        $mode = mmh_assignment_model_answer_access_mode($conn, $sourceAssignmentId);
        $update = $conn->prepare('UPDATE assignments SET model_answer_access_mode = ? WHERE assignment_id = ? AND course_id = ? LIMIT 1');
        if ($update) {
            $update->bind_param('sss', $mode, $newAssignmentId, $courseId);
            $update->execute();
            $update->close();
        }
        if ($mode !== 'selected') return;
        $sourceIds = mmh_assignment_model_answer_access_selected_ids($conn, $sourceAssignmentId);
        if (!$sourceIds) return;
        $insert = $conn->prepare('INSERT IGNORE INTO assignment_model_answer_access (assignment_id, user_id) SELECT ?, cl.user_id FROM course_logs cl INNER JOIN users u ON u.user_id = cl.user_id WHERE cl.course_id = ? AND u.role = \'user\' AND u.status = \'1\' AND u.archived_at IS NULL AND cl.user_id = ?');
        if (!$insert) return;
        foreach ($sourceIds as $id) {
            $insert->bind_param('ssi', $newAssignmentId, $courseId, $id);
            $insert->execute();
        }
        $insert->close();
    }
}
