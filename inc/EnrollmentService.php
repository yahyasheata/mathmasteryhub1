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

if (!function_exists('mmh_enrollment_ensure')) {
    function mmh_enrollment_ensure(mysqli $conn, int $userId, string $courseId, string $courseTitle, ?string $purchaseDate = null, bool $manageTransaction = true): bool
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
            $lookup = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1 FOR UPDATE');
            if (!$lookup) throw new RuntimeException('Enrollment lookup could not be prepared.');
            $lookup->bind_param('is', $userId, $courseId);
            $lookup->execute();
            $existing = (bool) $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if (!$existing) {
                $date = $purchaseDate ?: gmdate('Y-m-d H:i:s');
                $insert = $conn->prepare('INSERT INTO course_logs (user_id, course_id, course_title, purchase_date) VALUES (?, ?, ?, ?)');
                if (!$insert) throw new RuntimeException('Enrollment insert could not be prepared.');
                $insert->bind_param('isss', $userId, $courseId, $courseTitle, $date);
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
    function mmh_enrollment_move(mysqli $conn, int $userId, string $sourceCourseId, string $targetCourseId, bool $manageTransaction = true): bool
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
            $insert = $conn->prepare('INSERT INTO course_logs (user_id, course_id, course_title, purchase_date) VALUES (?, ?, ?, ?)');
            if (!$insert) throw new RuntimeException('Target enrollment could not be prepared.');
            $targetId = (string) $targetCourse['course_id'];
            $title = (string) ($targetCourse['course_title'] ?? '');
            $insert->bind_param('isss', $userId, $targetId, $title, $date);
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
    function mmh_enrollment_move_batch(mysqli $conn, array $userIds, string $sourceCourseId, string $targetCourseId): bool
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
        if (!$userIds || trim($sourceCourseId) === '' || trim($targetCourseId) === '' || $sourceCourseId === $targetCourseId) return false;
        $ownsTransaction = true;
        if ($ownsTransaction && !$conn->begin_transaction()) return false;
        try {
            foreach ($userIds as $userId) if (!mmh_enrollment_move($conn, $userId, $sourceCourseId, $targetCourseId, false)) throw new RuntimeException('One or more enrollments could not be moved.');
            if ($ownsTransaction) $conn->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction) $conn->rollback();
            error_log('Enrollment batch move failed: ' . $e->getMessage());
            return false;
        }
    }
}
