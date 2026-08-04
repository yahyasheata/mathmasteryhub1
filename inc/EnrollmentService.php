<?php
/** Canonical, idempotent enrollment writer. */
if (!function_exists('mmh_enrollment_ensure')) {
    function mmh_enrollment_ensure(mysqli $conn, int $userId, string $courseId, string $courseTitle, ?string $purchaseDate = null): bool
    {
        if ($userId <= 0 || trim($courseId) === '') return false;
        $ownsTransaction = !$conn->in_transaction;
        if ($ownsTransaction && !$conn->begin_transaction()) return false;
        $lockName = 'mmh_enrollment_' . $userId . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $courseId);
        $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
        if (!$lockStmt) { if ($ownsTransaction) $conn->rollback(); return false; }
        $lockStmt->bind_param('s', $lockName);
        $lockStmt->execute();
        $lockAcquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
        $lockStmt->close();
        if (!$lockAcquired) { if ($ownsTransaction) $conn->rollback(); return false; }
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
            $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
            if ($release) { $release->bind_param('s', $lockName); $release->execute(); $release->close(); }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction) $conn->rollback();
            $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
            if ($release) { $release->bind_param('s', $lockName); $release->execute(); $release->close(); }
            error_log('Enrollment write failed: ' . $e->getMessage());
            return false;
        }
    }
}
