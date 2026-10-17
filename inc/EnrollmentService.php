<?php
/** Canonical, idempotent enrollment writer. */
if (!function_exists('mmh_enrollment_lock_name')) {
    function mmh_enrollment_lock_name(int $userId, string $courseId): string
    {
        return 'mmh_enrollment_' . $userId . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $courseId);
    }
}

if (!function_exists('mmh_enrollment_lock')) {
    function mmh_enrollment_lock(mysqli $conn, string $lockName): bool
    {
        $stmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
        if (!$stmt) return false;
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
        $acquired = (int) ($stmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
        $stmt->close();
        return $acquired;
    }
}

if (!function_exists('mmh_enrollment_unlock')) {
    function mmh_enrollment_unlock(mysqli $conn, string $lockName): void
    {
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($stmt) {
            $stmt->bind_param('s', $lockName);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('mmh_enrollment_unit_access_valid')) {
    function mmh_enrollment_unit_access_valid($value): bool
    {
        return in_array((string) $value, ['unit1', 'unit2', 'both'], true);
    }
}

if (!function_exists('mmh_enrollment_course_unit_state')) {
    /** Detect modular setup from existing Section data. A missing additive schema is the pre-migration state. */
    function mmh_enrollment_course_unit_state(mysqli $conn, string $courseId): ?array
    {
        $schema = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'course_sections' AND COLUMN_NAME = 'access_unit') OR (TABLE_NAME = 'course_logs' AND COLUMN_NAME = 'unit_access'))");
        if (!$schema) return null;
        $schema->execute();
        $ready = (int) (($schema->get_result()->fetch_assoc()['total'] ?? 0)) === 2;
        $schema->close();
        if (!$ready) return ['ready' => false, 'candidate' => false, 'restricted' => false];

        $stmt = $conn->prepare('SELECT title, section_type, access_unit FROM course_sections WHERE course_id = ?');
        if (!$stmt) return null;
        $stmt->bind_param('s', $courseId);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $candidate = false;
        $restricted = false;
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $title = (string) ($row['title'] ?? '');
            if (strtolower(trim((string) ($row['section_type'] ?? ''))) === 'unit' || preg_match('/\bunit\s*[-:]?\s*[12]\b/i', $title)) $candidate = true;
            if (in_array((string) ($row['access_unit'] ?? 'shared'), ['unit1', 'unit2'], true)) $restricted = true;
        }
        $stmt->close();
        return ['ready' => true, 'candidate' => $candidate || $restricted, 'restricted' => $restricted];
    }
}

if (!function_exists('mmh_enrollment_ensure')) {
    function mmh_enrollment_ensure(mysqli $conn, int $userId, string $courseId, string $courseTitle, ?string $purchaseDate = null, bool $manageTransaction = true, ?string $unitAccess = null): bool
    {
        if ($userId <= 0 || trim($courseId) === '') return false;
        $courseCheck = $conn->prepare("SELECT course_id FROM courses WHERE course_id = ? AND archived_at IS NULL AND course_state IN ('public', 'private') LIMIT 1");
        if (!$courseCheck) return false;
        $courseCheck->bind_param('s', $courseId);
        $courseCheck->execute();
        $courseAvailable = (bool) $courseCheck->get_result()->fetch_assoc();
        $courseCheck->close();
        if (!$courseAvailable) return false;
        $ownsTransaction = $manageTransaction;
        if ($ownsTransaction && !$conn->begin_transaction()) return false;
        $lockName = mmh_enrollment_lock_name($userId, $courseId);
        if (!mmh_enrollment_lock($conn, $lockName)) { if ($ownsTransaction) $conn->rollback(); return false; }
        try {
            $courseLock = $conn->prepare("SELECT course_id FROM courses WHERE course_id = ? AND archived_at IS NULL AND course_state IN ('public', 'private') LIMIT 1 FOR UPDATE");
            if (!$courseLock) throw new RuntimeException('Course availability could not be checked.');
            $courseLock->bind_param('s', $courseId);
            $courseLock->execute();
            $courseAvailable = (bool) $courseLock->get_result()->fetch_assoc();
            $courseLock->close();
            if (!$courseAvailable) throw new RuntimeException('Course is unavailable.');
            $unitState = mmh_enrollment_course_unit_state($conn, $courseId);
            if ($unitState === null) throw new RuntimeException('Course access could not be checked.');
            if ($unitAccess !== null && !mmh_enrollment_unit_access_valid($unitAccess)) throw new RuntimeException('Choose Unit 1, Unit 2, or Both Units.');
            if (!empty($unitState['candidate']) && !mmh_enrollment_unit_access_valid($unitAccess)) throw new RuntimeException('Explicit Unit Access is required for this course.');
            $lookup = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1 FOR UPDATE');
            if (!$lookup) throw new RuntimeException('Enrollment lookup could not be prepared.');
            $lookup->bind_param('is', $userId, $courseId);
            $lookup->execute();
            $existing = (bool) $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if (!$existing) {
                $date = $purchaseDate ?: gmdate('Y-m-d H:i:s');
                $insertSql = !empty($unitState['ready'])
                    ? 'INSERT INTO course_logs (user_id, course_id, course_title, purchase_date, unit_access) VALUES (?, ?, ?, ?, ?)'
                    : 'INSERT INTO course_logs (user_id, course_id, course_title, purchase_date) VALUES (?, ?, ?, ?)';
                $insert = $conn->prepare($insertSql);
                if (!$insert) throw new RuntimeException('Enrollment insert could not be prepared.');
                if (!empty($unitState['ready'])) $insert->bind_param('issss', $userId, $courseId, $courseTitle, $date, $unitAccess);
                else $insert->bind_param('isss', $userId, $courseId, $courseTitle, $date);
                $insert->execute();
                $insert->close();
            }
            if ($ownsTransaction) $conn->commit();
            mmh_enrollment_unlock($conn, $lockName);
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction) $conn->rollback();
            mmh_enrollment_unlock($conn, $lockName);
            error_log('Enrollment write failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mmh_enrollment_remove')) {
    /** Remove only the course_logs relationship; all course history remains intact. */
    function mmh_enrollment_remove(mysqli $conn, int $userId, string $courseId, bool $manageTransaction = true): bool
    {
        if ($userId <= 0 || trim($courseId) === '') return false;
        $ownsTransaction = $manageTransaction;
        if ($ownsTransaction && !$conn->begin_transaction()) return false;
        $lockName = mmh_enrollment_lock_name($userId, $courseId);
        if (!mmh_enrollment_lock($conn, $lockName)) { if ($ownsTransaction) $conn->rollback(); return false; }
        try {
            $check = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1 FOR UPDATE');
            if (!$check) throw new RuntimeException('Enrollment lookup could not be prepared.');
            $check->bind_param('is', $userId, $courseId);
            $check->execute();
            $exists = (bool) $check->get_result()->fetch_assoc();
            $check->close();
            if (!$exists) throw new RuntimeException('Student is no longer enrolled in this course.');

            $delete = $conn->prepare('DELETE FROM course_logs WHERE user_id = ? AND course_id = ?');
            if (!$delete) throw new RuntimeException('Enrollment removal could not be prepared.');
            $delete->bind_param('is', $userId, $courseId);
            if (!$delete->execute() || $delete->affected_rows < 1) throw new RuntimeException('Enrollment could not be removed.');
            $delete->close();
            if ($ownsTransaction) $conn->commit();
            mmh_enrollment_unlock($conn, $lockName);
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction) $conn->rollback();
            mmh_enrollment_unlock($conn, $lockName);
            error_log('Enrollment removal failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mmh_enrollment_move')) {
    /** Move one enrollment transactionally without copying any course history. */
    function mmh_enrollment_move(mysqli $conn, int $userId, string $sourceCourseId, string $targetCourseId, bool $manageTransaction = true, ?string $targetUnitAccess = null): bool
    {
        $sourceCourseId = trim($sourceCourseId);
        $targetCourseId = trim($targetCourseId);
        if ($userId <= 0 || $sourceCourseId === '' || $targetCourseId === '' || $sourceCourseId === $targetCourseId) return false;

        $target = $conn->prepare("SELECT course_id, course_title FROM courses WHERE course_id = ? AND archived_at IS NULL AND course_state IN ('public', 'private') LIMIT 1");
        if (!$target) return false;
        $target->bind_param('s', $targetCourseId);
        $target->execute();
        $targetCourse = $target->get_result()->fetch_assoc();
        $target->close();
        if (!$targetCourse) return false;

        $ownsTransaction = $manageTransaction;
        if ($ownsTransaction && !$conn->begin_transaction()) return false;
        $lockNames = [$sourceCourseId, $targetCourseId];
        sort($lockNames, SORT_STRING);
        $acquired = [];
        try {
            $targetLock = $conn->prepare("SELECT course_id, course_title FROM courses WHERE course_id = ? AND archived_at IS NULL AND course_state IN ('public', 'private') LIMIT 1 FOR UPDATE");
            if (!$targetLock) throw new RuntimeException('Target course could not be checked.');
            $targetLock->bind_param('s', $targetCourseId);
            $targetLock->execute();
            $targetCourse = $targetLock->get_result()->fetch_assoc();
            $targetLock->close();
            if (!$targetCourse) throw new RuntimeException('Target course is unavailable.');
            $unitState = mmh_enrollment_course_unit_state($conn, $targetCourseId);
            if ($unitState === null) throw new RuntimeException('Target course access could not be checked.');
            if ($targetUnitAccess !== null && !mmh_enrollment_unit_access_valid($targetUnitAccess)) throw new RuntimeException('Choose Unit 1, Unit 2, or Both Units.');
            if (!empty($unitState['candidate']) && !mmh_enrollment_unit_access_valid($targetUnitAccess)) throw new RuntimeException('Explicit Unit Access is required for the target course.');
            foreach ($lockNames as $lockedCourseId) {
                $lockName = mmh_enrollment_lock_name($userId, $lockedCourseId);
                if (!mmh_enrollment_lock($conn, $lockName)) throw new RuntimeException('Enrollment is being changed by another request.');
                $acquired[] = $lockName;
            }
            $source = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1 FOR UPDATE');
            if (!$source) throw new RuntimeException('Source enrollment lookup could not be prepared.');
            $source->bind_param('is', $userId, $sourceCourseId);
            $source->execute();
            if (!$source->get_result()->fetch_assoc()) throw new RuntimeException('Student is no longer enrolled in the source course.');
            $source->close();

            $existing = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1 FOR UPDATE');
            if (!$existing) throw new RuntimeException('Target enrollment lookup could not be prepared.');
            $existing->bind_param('is', $userId, $targetCourseId);
            $existing->execute();
            if ($existing->get_result()->fetch_assoc()) throw new RuntimeException('Student is already enrolled in the target course.');
            $existing->close();

            $date = gmdate('Y-m-d H:i:s');
            $insertSql = !empty($unitState['ready'])
                ? 'INSERT INTO course_logs (user_id, course_id, course_title, purchase_date, unit_access) VALUES (?, ?, ?, ?, ?)'
                : 'INSERT INTO course_logs (user_id, course_id, course_title, purchase_date) VALUES (?, ?, ?, ?)';
            $insert = $conn->prepare($insertSql);
            if (!$insert) throw new RuntimeException('Target enrollment could not be prepared.');
            $targetId = (string) $targetCourse['course_id'];
            $title = (string) ($targetCourse['course_title'] ?? '');
            if (!empty($unitState['ready'])) $insert->bind_param('issss', $userId, $targetId, $title, $date, $targetUnitAccess);
            else $insert->bind_param('isss', $userId, $targetId, $title, $date);
            if (!$insert->execute()) throw new RuntimeException('Target enrollment could not be created.');
            $insert->close();

            $delete = $conn->prepare('DELETE FROM course_logs WHERE user_id = ? AND course_id = ?');
            if (!$delete) throw new RuntimeException('Source enrollment removal could not be prepared.');
            $delete->bind_param('is', $userId, $sourceCourseId);
            if (!$delete->execute() || $delete->affected_rows < 1) throw new RuntimeException('Source enrollment could not be removed.');
            $delete->close();
            if ($ownsTransaction) $conn->commit();
            foreach (array_reverse($acquired) as $lockName) mmh_enrollment_unlock($conn, $lockName);
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction) $conn->rollback();
            foreach (array_reverse($acquired) as $lockName) mmh_enrollment_unlock($conn, $lockName);
            error_log('Enrollment move failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mmh_enrollment_remove_batch')) {
    function mmh_enrollment_remove_batch(mysqli $conn, array $userIds, string $courseId): bool
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
        if (!$userIds || trim($courseId) === '') return false;
        $ownsTransaction = true;
        if ($ownsTransaction && !$conn->begin_transaction()) return false;
        try {
            foreach ($userIds as $userId) if (!mmh_enrollment_remove($conn, $userId, $courseId, false)) throw new RuntimeException('One or more enrollments could not be removed.');
            if ($ownsTransaction) $conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction) $conn->rollback();
            error_log('Enrollment batch removal failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mmh_enrollment_move_batch')) {
    function mmh_enrollment_move_batch(mysqli $conn, array $userIds, string $sourceCourseId, string $targetCourseId, ?string $targetUnitAccess = null): bool
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
        if (!$userIds || trim($sourceCourseId) === '' || trim($targetCourseId) === '' || $sourceCourseId === $targetCourseId) return false;
        $ownsTransaction = true;
        if ($ownsTransaction && !$conn->begin_transaction()) return false;
        try {
            foreach ($userIds as $userId) if (!mmh_enrollment_move($conn, $userId, $sourceCourseId, $targetCourseId, false, $targetUnitAccess)) throw new RuntimeException('One or more enrollments could not be moved.');
            if ($ownsTransaction) $conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction) $conn->rollback();
            error_log('Enrollment batch move failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mmh_enrollment_set_unit_access_batch')) {
    function mmh_enrollment_set_unit_access_batch(mysqli $conn, array $userIds, string $courseId, string $unitAccess): bool
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
        if (!$userIds || trim($courseId) === '' || !mmh_enrollment_unit_access_valid($unitAccess)) return false;
        if (!$conn->begin_transaction()) return false;
        try {
            $courseLock = $conn->prepare('SELECT course_id FROM courses WHERE course_id = ? LIMIT 1 FOR UPDATE');
            if (!$courseLock) throw new RuntimeException('Course could not be checked.');
            $courseLock->bind_param('s', $courseId);
            $courseLock->execute();
            $courseExists = (bool) $courseLock->get_result()->fetch_assoc();
            $courseLock->close();
            $unitState = $courseExists ? mmh_enrollment_course_unit_state($conn, $courseId) : null;
            if ($unitState === null || empty($unitState['ready']) || empty($unitState['candidate'])) throw new RuntimeException('This Course does not use Unit access.');

            foreach ($userIds as $userId) {
                $check = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1 FOR UPDATE');
                if (!$check) throw new RuntimeException('Enrollment could not be checked.');
                $check->bind_param('is', $userId, $courseId);
                $check->execute();
                $exists = (bool) $check->get_result()->fetch_assoc();
                $check->close();
                if (!$exists) throw new RuntimeException('A selected student is no longer enrolled.');
                $update = $conn->prepare('UPDATE course_logs SET unit_access = ? WHERE user_id = ? AND course_id = ?');
                if (!$update) throw new RuntimeException('Unit access could not be prepared.');
                $update->bind_param('sis', $unitAccess, $userId, $courseId);
                if (!$update->execute()) throw new RuntimeException('Unit access could not be saved.');
                $update->close();
            }
            if (!$conn->commit()) throw new RuntimeException('Unit access changes could not be committed.');
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Enrollment Unit access update failed: ' . $e->getMessage());
            return false;
        }
    }
}
