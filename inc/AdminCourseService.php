<?php
/** Canonical admin mutations for course ownership and visibility. */
if (!function_exists('mmh_admin_course_archive')) {
    function mmh_admin_course_archive(mysqli $conn, int $courseId): void
    {
        if ($courseId <= 0) throw new InvalidArgumentException('Invalid course.');
        $stmt = $conn->prepare('UPDATE courses SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE course_id = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Course archive could not be prepared.');
        $stmt->bind_param('i', $courseId);
        if (!$stmt->execute() || $stmt->affected_rows < 1) { $stmt->close(); throw new RuntimeException('Course not found.'); }
        $stmt->close();
    }
}

if (!function_exists('mmh_admin_course_set_status')) {
    function mmh_admin_course_set_status(mysqli $conn, string $courseId, string $status): void
    {
        $courseId = trim($courseId);
        if ($courseId === '' || !in_array($status, ['0', '1'], true)) throw new InvalidArgumentException('Invalid course status.');
        mmh_admin_course_set_state($conn, $courseId, $status === '1' ? 'public' : 'draft');
    }
}

if (!function_exists('mmh_admin_course_set_visibility')) {
    function mmh_admin_course_set_visibility(mysqli $conn, string $courseId, string $visibility): void
    {
        $courseId = trim($courseId);
        $visibility = strtolower(trim($visibility));
        if ($courseId === '' || !in_array($visibility, ['public', 'private'], true)) {
            throw new InvalidArgumentException('Invalid course visibility.');
        }
        mmh_admin_course_set_state($conn, $courseId, $visibility);
    }
}

if (!function_exists('mmh_admin_course_set_state')) {
    function mmh_admin_course_set_state(mysqli $conn, string $courseId, string $state): void
    {
        $courseId = trim($courseId);
        $state = strtolower(trim($state));
        if ($courseId === '' || !in_array($state, ['public', 'private', 'draft'], true)) {
            throw new InvalidArgumentException('Invalid course state.');
        }
        $stmt = $conn->prepare('UPDATE courses SET course_state = ? WHERE course_id = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Course state could not be prepared.');
        $stmt->bind_param('ss', $state, $courseId);
        if (!$stmt->execute() || $stmt->affected_rows < 1) {
            $stmt->close();
            throw new RuntimeException('Course not found.');
        }
        $stmt->close();
    }
}

if (!function_exists('mmh_admin_course_archive_item')) {
    function mmh_admin_course_archive_item(mysqli $conn, string $itemId, ?string $courseId = null): void
    {
        $itemId = trim($itemId); $courseId = $courseId !== null ? trim($courseId) : null;
        if ($itemId === '') throw new InvalidArgumentException('Lesson ID is missing.');
        $conn->begin_transaction();
        try {
            if ($courseId !== null && $courseId !== '') {
                $exam = $conn->prepare("UPDATE timed_exams SET deleted_at = COALESCE(deleted_at, UTC_TIMESTAMP()), status = 'archived' WHERE course_id = ? AND item_id = ?");
                if ($exam) { $exam->bind_param('ss', $courseId, $itemId); $exam->execute(); $exam->close(); }
                $stmt = $conn->prepare('UPDATE course_items SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE item_id = ? AND course_id = ? LIMIT 1');
                $stmt->bind_param('ss', $itemId, $courseId);
            } else {
                $stmt = $conn->prepare('UPDATE course_items SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE item_id = ? LIMIT 1');
                $stmt->bind_param('s', $itemId);
            }
            if (!$stmt || !$stmt->execute() || $stmt->affected_rows < 1) throw new RuntimeException('Lesson not found.');
            $stmt->close(); $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    }
}

if (!function_exists('mmh_admin_assignment_archive')) {
    function mmh_admin_assignment_archive(mysqli $conn, string $assignmentId): void
    {
        $assignmentId = trim($assignmentId);
        if ($assignmentId === '') throw new InvalidArgumentException('Invalid assignment.');
        $stmt = $conn->prepare('UPDATE assignments SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE assignment_id = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Assignment archive could not be prepared.');
        $stmt->bind_param('s', $assignmentId);
        if (!$stmt->execute() || $stmt->affected_rows < 1) { $stmt->close(); throw new RuntimeException('Assignment not found.'); }
        $stmt->close();
    }
}

if (!function_exists('mmh_admin_student_archive')) {
    function mmh_admin_student_archive(mysqli $conn, int $userId): void
    {
        if ($userId <= 0) throw new InvalidArgumentException('Invalid student.');
        $stmt = $conn->prepare("UPDATE users SET status = 0, archived_at = COALESCE(archived_at, UTC_TIMESTAMP()) WHERE user_id = ? AND role = 'user' LIMIT 1");
        if (!$stmt) throw new RuntimeException('Student archive could not be prepared.');
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute() || $stmt->affected_rows < 1) { $stmt->close(); throw new RuntimeException('Student not found.'); }
        $stmt->close();
    }
}

if (!function_exists('mmh_admin_student_set_status')) {
    function mmh_admin_student_set_status(mysqli $conn, int $userId, int $status): void
    {
        if ($userId <= 0 || !in_array($status, [0, 1], true)) throw new InvalidArgumentException('Invalid user status.');
        $stmt = $conn->prepare('UPDATE users SET status = ? WHERE user_id = ? AND role = \'user\' LIMIT 1');
        if (!$stmt) throw new RuntimeException('Status update could not be prepared.');
        $stmt->bind_param('ii', $status, $userId);
        if (!$stmt->execute() || $stmt->affected_rows < 1) { $stmt->close(); throw new RuntimeException('Student not found.'); }
        $stmt->close();
    }
}

if (!function_exists('mmh_admin_course_reorder_items')) {
    function mmh_admin_course_reorder_items(mysqli $conn, string $courseId, array $items): int
    {
        $courseId = trim($courseId); if ($courseId === '') throw new InvalidArgumentException('Course ID is missing.');
        $sectionMap = [];
        $sectionCheck = $conn->prepare('SELECT section_id FROM course_sections WHERE course_id = ?');
        if ($sectionCheck) { $sectionCheck->bind_param('s', $courseId); $sectionCheck->execute(); foreach ($sectionCheck->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $sectionMap[(string) $row['section_id']] = true; $sectionCheck->close(); }
        $stmt = $conn->prepare('UPDATE course_items SET section_id = ?, page_order = ?, sort_order = ? WHERE id = ? AND course_id = ?');
        $orderOnly = $conn->prepare('UPDATE course_items SET page_order = ?, sort_order = ? WHERE id = ? AND course_id = ?');
        if (!$stmt || !$orderOnly) throw new RuntimeException('Lesson order could not be prepared.');
        $updated = 0;
        foreach (array_values($items) as $index => $raw) {
            if (!is_array($raw) || !is_numeric($raw['id'] ?? null)) continue;
            $order = max(1, (int) ($raw['page_order'] ?? ($index + 1))); $id = (int) $raw['id'];
            if (array_key_exists('section_id', $raw)) {
                $section = trim((string) $raw['section_id']); $section = ($section === '' || $section === '__general__') ? null : $section;
                if ($section !== null && !isset($sectionMap[$section])) continue;
                $stmt->bind_param('siiis', $section, $order, $order, $id, $courseId);
                if (!$stmt->execute()) { $stmt->close(); $orderOnly->close(); throw new RuntimeException('Lesson order could not be saved.'); }
                $updated += $stmt->affected_rows >= 0 ? 1 : 0;
            } else {
                $orderOnly->bind_param('iiis', $order, $order, $id, $courseId);
                if (!$orderOnly->execute()) { $stmt->close(); $orderOnly->close(); throw new RuntimeException('Lesson order could not be saved.'); }
                $updated += $orderOnly->affected_rows >= 0 ? 1 : 0;
            }
        }
        $stmt->close(); $orderOnly->close(); return $updated;
    }
}

if (!function_exists('mmh_admin_course_reorder_sections')) {
    function mmh_admin_course_reorder_sections(mysqli $conn, string $courseId, array $sections): int
    {
        $courseId = trim($courseId); if ($courseId === '') throw new InvalidArgumentException('Course ID is missing.');
        $allowed = [];
        $check = $conn->prepare('SELECT section_id FROM course_sections WHERE course_id = ?');
        if (!$check) throw new RuntimeException('Section order could not be prepared.');
        $check->bind_param('s', $courseId); $check->execute();
        foreach ($check->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $allowed[(string) $row['section_id']] = true;
        $check->close();
        $stmt = $conn->prepare('UPDATE course_sections SET sort_order = ? WHERE section_id = ? AND course_id = ?');
        if (!$stmt) throw new RuntimeException('Section order could not be prepared.');
        $updated = 0;
        foreach (array_values($sections) as $index => $raw) {
            $section = trim((string) $raw); if ($section === '' || $section === '__general__' || !isset($allowed[$section])) continue;
            $order = $index + 1; $stmt->bind_param('iss', $order, $section, $courseId);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Section order could not be saved.'); }
            $updated += $stmt->affected_rows >= 0 ? 1 : 0;
        }
        $stmt->close(); return $updated;
    }
}
