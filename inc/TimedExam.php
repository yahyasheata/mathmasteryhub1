<?php
declare(strict_types=1);

require_once __DIR__ . '/StudentCourseAccess.php';
require_once __DIR__ . '/StudentCourseCsrf.php';
require_once __DIR__ . '/CourseResourceResolver.php';

if (!function_exists('mmh_timed_exam_normalize_external_paper_url')) {
    /**
     * Normalize a teacher-provided cloud paper link through the shared course
     * resource resolver. Only HTTPS links from supported providers are stored;
     * the browser never receives this value before the server opens the exam.
     */
    function mmh_timed_exam_normalize_external_paper_url(string $value): ?array
    {
        $url = mmh_course_resource_safe_url(trim($value));
        if ($url === null || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') return null;
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host !== 'drive.google.com' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
        $path = (string) ($parts['path'] ?? '');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $fileId = null;
        if (preg_match('~^/file/d/([A-Za-z0-9_-]+)/(?:view|preview)/?$~i', $path, $match)) {
            $fileId = $match[1];
        } elseif (preg_match('~^/(?:open|uc)/?$~i', $path) && !empty($query['id']) && preg_match('/^[A-Za-z0-9_-]+$/', (string) $query['id'])) {
            $fileId = (string) $query['id'];
        }
        // Folders, malformed paths, and links without an explicit file id are
        // rejected rather than passed through to Google unchanged.
        if ($fileId === null) return null;
        $fileId = rawurlencode($fileId);
        $preview = 'https://drive.google.com/file/d/' . $fileId . '/preview';
        $open = 'https://drive.google.com/file/d/' . $fileId . '/view';
        $download = 'https://drive.google.com/uc?export=download&id=' . $fileId;
        return [
            'url' => $url,
            'file_id' => $fileId,
            'preview_url' => $preview,
            'open_url' => $open,
            'download_url' => $download,
            'kind' => 'google_drive',
        ];
    }
}

if (!function_exists('mmh_timed_exam_table_available')) {
    function mmh_timed_exam_table_available(mysqli $conn): bool
    {
        static $cache = [];
        $key = spl_object_id($conn);
        if (array_key_exists($key, $cache)) return $cache[$key];
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'timed_exams'");
        if (!$stmt) return $cache[$key] = false;
        $stmt->execute();
        $cache[$key] = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0;
        $stmt->close();
        return $cache[$key];
    }
}

if (!function_exists('mmh_timed_exam_lifecycle_schema_available')) {
    function mmh_timed_exam_lifecycle_schema_available(mysqli $conn): bool
    {
        static $cache = [];
        $key = spl_object_id($conn);
        if (array_key_exists($key, $cache)) return $cache[$key];
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'timed_exam_attempts' AND COLUMN_NAME = 'attempt_scope') OR (TABLE_NAME = 'timed_exams' AND COLUMN_NAME = 'roster_finalized_at_utc'))");
        if (!$stmt) return $cache[$key] = false;
        $stmt->execute();
        $cache[$key] = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) === 2;
        $stmt->close();
        return $cache[$key];
    }
}

if (!function_exists('mmh_timed_exam_terminal_states')) {
    function mmh_timed_exam_terminal_states(): array
    {
        return ['submitted', 'auto_submitted', 'no_submission', 'graded'];
    }
}

if (!function_exists('mmh_timed_exam_state_completes_learning')) {
    function mmh_timed_exam_state_completes_learning(string $state): bool
    {
        return in_array($state, ['submitted', 'auto_submitted', 'graded'], true);
    }
}

if (!function_exists('mmh_timed_exam_attempt_scope')) {
    function mmh_timed_exam_attempt_scope(array $exam): string
    {
        $scope = trim((string) ($exam['_attempt_scope'] ?? 'primary'));
        return preg_match('/\A(?:primary|recovery:[0-9]+:[0-9]+|legacy:[0-9]+)\z/', $scope) ? $scope : 'primary';
    }
}

if (!function_exists('mmh_timed_exam_log_lifecycle_error')) {
    function mmh_timed_exam_log_lifecycle_error(string $operation, array $context, Throwable $exception): void
    {
        error_log(sprintf(
            'Timed Exam lifecycle failure operation=%s exam_id=%d attempt_id=%d student_id=%d error=%s',
            preg_replace('/[^a-z0-9_-]+/i', '_', $operation),
            (int) ($context['exam_id'] ?? 0),
            (int) ($context['attempt_id'] ?? 0),
            (int) ($context['student_id'] ?? 0),
            preg_replace('/[\r\n]+/', ' ', $exception->getMessage())
        ));
    }
}

if (!function_exists('mmh_timed_exam_parse_allowed_types')) {
    function mmh_timed_exam_parse_allowed_types(string $value): array
    {
        $types = [];
        foreach (preg_split('/[,\s]+/', strtolower(trim($value))) ?: [] as $type) {
            $type = ltrim(trim($type), '.');
            if (in_array($type, ['pdf', 'jpg', 'jpeg', 'png'], true)) $types[$type] = true;
        }
        return array_keys($types) ?: ['pdf'];
    }
}

if (!function_exists('mmh_timed_exam_datetime_to_utc')) {
    function mmh_timed_exam_datetime_to_utc(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get()));
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('mmh_timed_exam_datetime_for_input')) {
    function mmh_timed_exam_datetime_for_input(?string $utcValue): string
    {
        $utcValue = trim((string) $utcValue);
        if ($utcValue === '') return '';
        try {
            return (new DateTimeImmutable($utcValue, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d\\TH:i');
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('mmh_timed_exam_load')) {
    function mmh_timed_exam_load(mysqli $conn, string $courseId, int $examId, bool $includeDraft = false): ?array
    {
        if ($examId <= 0 || !mmh_timed_exam_table_available($conn)) return null;
        $sql = 'SELECT e.*, i.item_title, i.item_description, i.section_id, i.status AS item_status,
                    s.title AS section_title, s.sort_order AS section_sort_order
                FROM timed_exams e
                INNER JOIN course_items i ON i.item_id = e.item_id AND i.course_id = e.course_id
                LEFT JOIN course_sections s ON s.course_id = i.course_id AND s.section_id = i.section_id
                WHERE e.id = ? AND e.course_id = ? AND e.deleted_at IS NULL';
        if (!$includeDraft) $sql .= " AND e.status = 'published' AND (i.status IS NULL OR i.status = '' OR i.status = 'published')";
        $sql .= ' LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('is', $examId, $courseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row) $row['allowed_answer_types_list'] = mmh_timed_exam_parse_allowed_types((string) ($row['allowed_answer_types'] ?? ''));
        return $row;
    }
}

if (!function_exists('mmh_timed_exam_load_for_item')) {
    function mmh_timed_exam_load_for_item(mysqli $conn, string $courseId, string $itemId, bool $includeDraft = false): ?array
    {
        if ($itemId === '' || !mmh_timed_exam_table_available($conn)) return null;
        $stmt = $conn->prepare('SELECT id FROM timed_exams WHERE course_id = ? AND item_id = ? AND deleted_at IS NULL LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('ss', $courseId, $itemId);
        $stmt->execute();
        $id = (int) (($stmt->get_result()->fetch_assoc()['id'] ?? 0));
        $stmt->close();
        return $id > 0 ? mmh_timed_exam_load($conn, $courseId, $id, $includeDraft) : null;
    }
}

if (!function_exists('mmh_timed_exam_window')) {
    function mmh_timed_exam_window(array $exam): array
    {
        $opens = trim((string) ($exam['scheduled_start_at_utc'] ?? ''));
        if ($opens === '') return ['opens_at' => null, 'closes_at' => null, 'grace_closes_at' => null];
        try {
            $start = new DateTimeImmutable($opens, new DateTimeZone('UTC'));
            $close = $start->modify('+' . max(1, (int) ($exam['duration_minutes'] ?? 60)) . ' minutes');
            $grace = $close->modify('+' . max(0, (int) ($exam['grace_minutes'] ?? 0)) . ' minutes');
            return ['opens_at' => $start, 'closes_at' => $close, 'grace_closes_at' => $grace];
        } catch (Throwable $e) {
            return ['opens_at' => null, 'closes_at' => null, 'grace_closes_at' => null];
        }
    }
}

if (!function_exists('mmh_timed_exam_with_window')) {
    function mmh_timed_exam_with_window(array $exam, string $startUtc, string $endUtc): array
    {
        try {
            $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
            $end = new DateTimeImmutable($endUtc, new DateTimeZone('UTC'));
            $minutes = max(1, (int) ceil(($end->getTimestamp() - $start->getTimestamp()) / 60));
            if ($end <= $start) return $exam;
            $exam['scheduled_start_at_utc'] = $start->format('Y-m-d H:i:s');
            $exam['duration_minutes'] = $minutes;
            $exam['grace_minutes'] = 0;
            $exam['late_submission_allowed'] = 0;
            return $exam;
        } catch (Throwable $e) { return $exam; }
    }
}

if (!function_exists('mmh_timed_exam_resolve_recovery')) {
    function mmh_timed_exam_resolve_recovery(mysqli $conn, array $exam, int $studentId, int $planId, int $taskId): ?array
    {
        if ($planId <= 0 || $taskId <= 0 || empty($exam['recovery_allowed'])) return null;
        require_once __DIR__ . '/RecoveryPlan.php';
        $plan = mmh_recovery_plan_load($conn, $studentId, (string) ($exam['course_id'] ?? ''), $planId);
        $context = $plan ? mmh_recovery_plan_task_context($plan, $taskId) : [];
        $task = $context['current'] ?? null;
        if (!$task || (string) ($task['item_id'] ?? '') !== (string) ($exam['item_id'] ?? '')) return null;
        $start = trim((string) ($exam['recovery_window_start_at_utc'] ?? ''));
        $end = trim((string) ($exam['recovery_window_end_at_utc'] ?? ''));
        if ($start === '' || $end === '') return null;
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $startDate = new DateTimeImmutable($start, new DateTimeZone('UTC')); $endDate = new DateTimeImmutable($end, new DateTimeZone('UTC'));
            if ($now < $startDate || $now > $endDate) return null;
            $recoveryExam = mmh_timed_exam_with_window($exam, $start, $end);
            $recoveryExam['_attempt_scope'] = 'recovery:' . $planId . ':' . $taskId;
            return ['plan' => $plan, 'task' => $task, 'exam' => $recoveryExam];
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('mmh_timed_exam_state')) {
    function mmh_timed_exam_state(array $exam, ?array $attempt = null, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $window = mmh_timed_exam_window($exam);
        if (!$window['opens_at']) return ['key' => 'unavailable', 'label' => 'Not scheduled', 'remaining_seconds' => 0, 'window' => $window];
        if ($now < $window['opens_at']) return ['key' => 'before', 'label' => 'Upcoming', 'remaining_seconds' => max(0, $window['opens_at']->getTimestamp() - $now->getTimestamp()), 'window' => $window];
        if ($attempt && in_array((string) ($attempt['state'] ?? ''), mmh_timed_exam_terminal_states(), true)) {
            $key = (string) $attempt['state'];
            return ['key' => $key, 'label' => ucwords(str_replace('_', ' ', $key)), 'remaining_seconds' => 0, 'window' => $window];
        }
        if ($now <= $window['closes_at']) return ['key' => 'open', 'label' => 'Open', 'remaining_seconds' => max(0, $window['closes_at']->getTimestamp() - $now->getTimestamp()), 'window' => $window];
        if ((int) ($exam['grace_minutes'] ?? 0) > 0 && !empty($exam['late_submission_allowed']) && $now <= $window['grace_closes_at']) {
            return ['key' => 'grace', 'label' => 'Grace period', 'remaining_seconds' => max(0, $window['grace_closes_at']->getTimestamp() - $now->getTimestamp()), 'window' => $window];
        }
        return ['key' => 'expired', 'label' => 'Time expired', 'remaining_seconds' => 0, 'window' => $window];
    }
}

if (!function_exists('mmh_timed_exam_student_attempt')) {
    function mmh_timed_exam_student_attempt(mysqli $conn, int $studentId, int $examId, bool $create = false, string $scope = 'primary'): ?array
    {
        if ($studentId <= 0 || $examId <= 0 || !mmh_timed_exam_table_available($conn)) return null;
        $scope = preg_match('/\A(?:primary|recovery:[0-9]+:[0-9]+|legacy:[0-9]+)\z/', $scope) ? $scope : 'primary';
        $sql = mmh_timed_exam_lifecycle_schema_available($conn)
            ? 'SELECT * FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ? AND attempt_scope = ? ORDER BY id DESC LIMIT 1'
            : 'SELECT * FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ? ORDER BY id DESC LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        if (mmh_timed_exam_lifecycle_schema_available($conn)) $stmt->bind_param('iis', $examId, $studentId, $scope);
        else $stmt->bind_param('ii', $examId, $studentId);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($attempt || !$create) return $attempt;
        return null;
    }
}

if (!function_exists('mmh_timed_exam_create_attempt')) {
    function mmh_timed_exam_create_attempt(mysqli $conn, array $exam, int $studentId): ?array
    {
        if ($studentId <= 0 || (int) ($exam['id'] ?? 0) <= 0) return null;
        $courseId = trim((string) ($exam['course_id'] ?? ''));
        if ($courseId === '' || !student_course_access_enrolled($conn, $studentId, $courseId)) return null;
        $window = mmh_timed_exam_window($exam);
        if (!$window['opens_at']) return null;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($now < $window['opens_at']) return null;
        $scope = mmh_timed_exam_attempt_scope($exam);
        try {
            $conn->begin_transaction();
            $lockSql = mmh_timed_exam_lifecycle_schema_available($conn)
                ? 'SELECT * FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ? AND attempt_scope = ? ORDER BY id DESC LIMIT 1 FOR UPDATE'
                : 'SELECT * FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE';
            $lock = $conn->prepare($lockSql);
            if (!$lock) throw new RuntimeException('Unable to lock exam attempt.');
            $examId = (int) $exam['id'];
            if (mmh_timed_exam_lifecycle_schema_available($conn)) $lock->bind_param('iis', $examId, $studentId, $scope);
            else $lock->bind_param('ii', $examId, $studentId);
            $lock->execute();
            $existing = $lock->get_result()->fetch_assoc() ?: null;
            $lock->close();
            if ($existing) { $conn->commit(); return $existing; }
            $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ?');
            if (!$countStmt) throw new RuntimeException('Unable to count exam attempts.');
            $countStmt->bind_param('ii', $examId, $studentId);
            $countStmt->execute();
            $attemptNumber = ((int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0)) + 1;
            $countStmt->close();
            $opens = $window['opens_at']->format('Y-m-d H:i:s');
            $closes = $window['closes_at']->format('Y-m-d H:i:s');
            $grace = $window['grace_closes_at']->format('Y-m-d H:i:s');
            $activeKey = bin2hex(random_bytes(16));
            $state = 'in_progress';
            $insertSql = mmh_timed_exam_lifecycle_schema_available($conn)
                ? 'INSERT INTO timed_exam_attempts (timed_exam_id, student_id, attempt_number, attempt_scope, active_key, state, opens_at_utc, closes_at_utc, grace_closes_at_utc, started_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO timed_exam_attempts (timed_exam_id, student_id, attempt_number, active_key, state, opens_at_utc, closes_at_utc, grace_closes_at_utc, started_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $conn->prepare($insertSql);
            if (!$stmt) throw new RuntimeException('Unable to prepare exam attempt.');
            $started = $now->format('Y-m-d H:i:s');
            if (mmh_timed_exam_lifecycle_schema_available($conn)) $stmt->bind_param('iiisssssss', $examId, $studentId, $attemptNumber, $scope, $activeKey, $state, $opens, $closes, $grace, $started);
            else $stmt->bind_param('iiissssss', $examId, $studentId, $attemptNumber, $activeKey, $state, $opens, $closes, $grace, $started);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                if (str_contains(strtolower($error), 'duplicate')) {
                    $conn->rollback();
                    return mmh_timed_exam_student_attempt($conn, $studentId, $examId, false, $scope);
                }
                throw new RuntimeException($error ?: 'Unable to start exam attempt.');
            }
            $newId = $stmt->insert_id;
            $stmt->close();
            $conn->commit();
            $result = mmh_timed_exam_student_attempt($conn, $studentId, $examId, false, $scope);
            if (!$result && $newId > 0) {
                $result = ['id' => $newId, 'timed_exam_id' => $examId, 'student_id' => $studentId, 'attempt_number' => $attemptNumber, 'attempt_scope' => $scope, 'state' => $state, 'opens_at_utc' => $opens, 'closes_at_utc' => $closes, 'grace_closes_at_utc' => $grace, 'started_at_utc' => $started, 'active_key' => $activeKey];
            }
            return $result;
        } catch (Throwable $e) {
            $conn->rollback();
            return null;
        }
    }
}

if (!function_exists('mmh_timed_exam_latest_version')) {
    function mmh_timed_exam_latest_version(mysqli $conn, int $attemptId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT * FROM timed_exam_submission_versions WHERE attempt_id = ? AND status <> 'removed' ORDER BY version_number DESC, id DESC LIMIT 1";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $attemptId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('mmh_timed_exam_upload_version_count')) {
    function mmh_timed_exam_upload_version_count(mysqli $conn, int $attemptId, bool $forUpdate = false): int
    {
        if ($attemptId <= 0) return 0;
        $sql = $forUpdate
            ? 'SELECT id FROM timed_exam_submission_versions WHERE attempt_id = ? FOR UPDATE'
            : 'SELECT COUNT(*) AS total FROM timed_exam_submission_versions WHERE attempt_id = ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return 0;
        $stmt->bind_param('i', $attemptId);
        $stmt->execute();
        $result = $stmt->get_result();
        $total = $forUpdate ? $result->num_rows : (int) ($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $total;
    }
}

if (!function_exists('mmh_timed_exam_attempt_deadline')) {
    function mmh_timed_exam_attempt_deadline(array $exam, ?array $attempt = null): array
    {
        $window = mmh_timed_exam_window($exam);
        if ($attempt && !empty($attempt['opens_at_utc']) && !empty($attempt['closes_at_utc']) && !empty($attempt['grace_closes_at_utc'])) {
            try {
                $window = [
                    'opens_at' => new DateTimeImmutable((string) $attempt['opens_at_utc'], new DateTimeZone('UTC')),
                    'closes_at' => new DateTimeImmutable((string) $attempt['closes_at_utc'], new DateTimeZone('UTC')),
                    'grace_closes_at' => new DateTimeImmutable((string) $attempt['grace_closes_at_utc'], new DateTimeZone('UTC')),
                ];
            } catch (Throwable $exception) {
                return mmh_timed_exam_window($exam);
            }
        }
        return $window;
    }
}

if (!function_exists('mmh_timed_exam_effective_deadline')) {
    function mmh_timed_exam_effective_deadline(array $exam, ?array $attempt = null): ?DateTimeImmutable
    {
        $window = mmh_timed_exam_attempt_deadline($exam, $attempt);
        if (!$window['closes_at']) return null;
        return (int) ($exam['grace_minutes'] ?? 0) > 0 && !empty($exam['late_submission_allowed'])
            ? $window['grace_closes_at']
            : $window['closes_at'];
    }
}

if (!function_exists('mmh_timed_exam_utc_datetime')) {
    function mmh_timed_exam_utc_datetime(?string $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            return null;
        }
    }
}

if (!function_exists('mmh_timed_exam_upload_capacity')) {
    function mmh_timed_exam_upload_capacity(mysqli $conn, array $exam, int $attemptId, bool $forUpdate = false): array
    {
        $limit = max(1, (int) ($exam['max_attempts'] ?? 1));
        $used = mmh_timed_exam_upload_version_count($conn, $attemptId, $forUpdate);
        return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used), 'allowed' => $used < $limit];
    }
}

if (!function_exists('mmh_timed_exam_insert_notification')) {
    function mmh_timed_exam_insert_notification(mysqli $conn, int $studentId, string $title, string $message): void
    {
        $check = $conn->prepare('SELECT id FROM notifications WHERE user_id = ? AND title = ? AND message = ? LIMIT 1');
        if (!$check) throw new RuntimeException('Unable to verify Timed Exam notification state.');
        $check->bind_param('iss', $studentId, $title, $message);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        if ($exists) return;
        $insert = $conn->prepare('INSERT INTO notifications (user_id, title, message, status) VALUES (?, ?, ?, 0)');
        if (!$insert) throw new RuntimeException('Unable to create Timed Exam notification.');
        $insert->bind_param('iss', $studentId, $title, $message);
        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            throw new RuntimeException($error ?: 'Unable to create Timed Exam notification.');
        }
        $insert->close();
    }
}

if (!function_exists('mmh_timed_exam_finalize_attempt')) {
    /** Canonical, idempotent expiry transition for one primary or Recovery attempt scope. */
    function mmh_timed_exam_finalize_attempt(mysqli $conn, array $exam, int $studentId, ?int $attemptId = null, ?DateTimeImmutable $now = null, bool $dryRun = false): array
    {
        $examId = (int) ($exam['id'] ?? 0);
        $scope = mmh_timed_exam_attempt_scope($exam);
        $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($examId <= 0 || $studentId <= 0) return ['success' => false, 'state' => '', 'changed' => false, 'message' => 'Invalid Timed Exam finalization context.'];
        $courseId = trim((string) ($exam['course_id'] ?? ''));
        if ($courseId === '' || !student_course_access_enrolled($conn, $studentId, $courseId)) {
            return ['success' => false, 'state' => '', 'changed' => false, 'message' => 'Student is not eligible for this Timed Exam.'];
        }
        try {
            $conn->begin_transaction();
            if ($attemptId !== null && $attemptId > 0) {
                $lockSql = mmh_timed_exam_lifecycle_schema_available($conn)
                    ? 'SELECT * FROM timed_exam_attempts WHERE id = ? AND timed_exam_id = ? AND student_id = ? AND attempt_scope = ? FOR UPDATE'
                    : 'SELECT * FROM timed_exam_attempts WHERE id = ? AND timed_exam_id = ? AND student_id = ? FOR UPDATE';
                $lock = $conn->prepare($lockSql);
                if (!$lock) throw new RuntimeException('Unable to lock Timed Exam attempt.');
                if (mmh_timed_exam_lifecycle_schema_available($conn)) $lock->bind_param('iiis', $attemptId, $examId, $studentId, $scope);
                else $lock->bind_param('iii', $attemptId, $examId, $studentId);
            } else {
                $lockSql = mmh_timed_exam_lifecycle_schema_available($conn)
                    ? 'SELECT * FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ? AND attempt_scope = ? ORDER BY id DESC LIMIT 1 FOR UPDATE'
                    : 'SELECT * FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE';
                $lock = $conn->prepare($lockSql);
                if (!$lock) throw new RuntimeException('Unable to lock Timed Exam attempt.');
                if (mmh_timed_exam_lifecycle_schema_available($conn)) $lock->bind_param('iis', $examId, $studentId, $scope);
                else $lock->bind_param('ii', $examId, $studentId);
            }
            $lock->execute();
            $fresh = $lock->get_result()->fetch_assoc() ?: null;
            $lock->close();
            if ($attemptId !== null && $attemptId > 0 && !$fresh) {
                throw new RuntimeException('Timed Exam attempt does not match the requested lifecycle scope.');
            }
            if ($fresh && in_array((string) ($fresh['state'] ?? ''), mmh_timed_exam_terminal_states(), true)) {
                $dryRun ? $conn->rollback() : $conn->commit();
                return ['success' => true, 'state' => (string) $fresh['state'], 'changed' => false, 'already_terminal' => true, 'attempt' => $fresh];
            }

            $window = mmh_timed_exam_attempt_deadline($exam, $fresh);
            $deadline = mmh_timed_exam_effective_deadline($exam, $fresh);
            if (!$deadline || $now <= $deadline) {
                $conn->rollback();
                return ['success' => true, 'state' => (string) ($fresh['state'] ?? 'not_started'), 'changed' => false, 'not_due' => true, 'attempt' => $fresh];
            }

            if (!$fresh) {
                $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ?');
                if (!$countStmt) throw new RuntimeException('Unable to count Timed Exam attempts.');
                $countStmt->bind_param('ii', $examId, $studentId);
                $countStmt->execute();
                $attemptNumber = ((int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0)) + 1;
                $countStmt->close();
                $opens = $window['opens_at']->format('Y-m-d H:i:s');
                $closes = $window['closes_at']->format('Y-m-d H:i:s');
                $graceCloses = $window['grace_closes_at']->format('Y-m-d H:i:s');
                $expiredAt = $deadline->format('Y-m-d H:i:s');
                $insertSql = mmh_timed_exam_lifecycle_schema_available($conn)
                    ? "INSERT INTO timed_exam_attempts (timed_exam_id, student_id, attempt_number, attempt_scope, active_key, state, opens_at_utc, closes_at_utc, grace_closes_at_utc, expired_at_utc) VALUES (?, ?, ?, ?, NULL, 'no_submission', ?, ?, ?, ?)"
                    : "INSERT INTO timed_exam_attempts (timed_exam_id, student_id, attempt_number, active_key, state, opens_at_utc, closes_at_utc, grace_closes_at_utc, expired_at_utc) VALUES (?, ?, ?, NULL, 'no_submission', ?, ?, ?, ?)";
                $insert = $conn->prepare($insertSql);
                if (!$insert) throw new RuntimeException('Unable to create no-submission Timed Exam outcome.');
                if (mmh_timed_exam_lifecycle_schema_available($conn)) $insert->bind_param('iiisssss', $examId, $studentId, $attemptNumber, $scope, $opens, $closes, $graceCloses, $expiredAt);
                else $insert->bind_param('iiissss', $examId, $studentId, $attemptNumber, $opens, $closes, $graceCloses, $expiredAt);
                if (!$insert->execute()) {
                    $error = $insert->error;
                    $insert->close();
                    if (str_contains(strtolower($error), 'duplicate')) {
                        $conn->rollback();
                        return mmh_timed_exam_finalize_attempt($conn, $exam, $studentId, null, $now, $dryRun);
                    }
                    throw new RuntimeException($error ?: 'Unable to create no-submission Timed Exam outcome.');
                }
                $newId = (int) $insert->insert_id;
                $insert->close();
                $fresh = ['id' => $newId, 'timed_exam_id' => $examId, 'student_id' => $studentId, 'attempt_number' => $attemptNumber, 'attempt_scope' => $scope, 'state' => 'no_submission', 'opens_at_utc' => $opens, 'closes_at_utc' => $closes, 'grace_closes_at_utc' => $graceCloses, 'expired_at_utc' => $expiredAt];
                $dryRun ? $conn->rollback() : $conn->commit();
                return ['success' => true, 'state' => 'no_submission', 'changed' => true, 'created' => true, 'attempt' => $fresh];
            }

            $id = (int) $fresh['id'];
            $version = mmh_timed_exam_latest_version($conn, $id, true);
            $late = 0;
            $uploadedAt = $version ? mmh_timed_exam_utc_datetime((string) ($version['uploaded_at_utc'] ?? '')) : null;
            if ($uploadedAt && $uploadedAt > $window['closes_at']) $late = 1;
            $versionState = (string) ($version['status'] ?? '');
            if ($version && in_array($versionState, ['final', 'auto_submitted'], true)) {
                $newState = $versionState === 'final' ? 'submitted' : 'auto_submitted';
                $submitted = (string) ($version['submitted_at_utc'] ?? '') ?: $deadline->format('Y-m-d H:i:s');
                $late = (int) ($version['is_late'] ?? $late);
            } elseif ($version && $versionState === 'uploaded' && (string) ($exam['expiry_policy'] ?? 'auto_submit_latest') === 'auto_submit_latest') {
                $storageKey = trim((string) ($version['storage_key'] ?? ''));
                $storagePath = dirname(__DIR__) . '/' . ltrim($storageKey, '/');
                if ($storageKey === '' || str_contains($storageKey, '..') || !is_file($storagePath)) {
                    error_log(sprintf('Timed Exam invalid uploaded answer exam_id=%d attempt_id=%d student_id=%d', $examId, $id, $studentId));
                    $newState = 'no_submission';
                    $submitted = null;
                } else {
                    $versionId = (int) $version['id'];
                    $versionStmt = $conn->prepare("UPDATE timed_exam_submission_versions SET status = 'auto_submitted', is_late = ?, submitted_at_utc = ? WHERE id = ? AND status = 'uploaded'");
                    if (!$versionStmt) throw new RuntimeException('Unable to finalize expired upload.');
                    $submittedAt = $deadline->format('Y-m-d H:i:s');
                    $versionStmt->bind_param('isi', $late, $submittedAt, $versionId);
                    if (!$versionStmt->execute() || $versionStmt->affected_rows !== 1) throw new RuntimeException($versionStmt->error ?: 'Expired upload was not finalized.');
                    $versionStmt->close();
                    $newState = 'auto_submitted';
                    $submitted = $submittedAt;
                }
            } else {
                $newState = 'no_submission';
                $submitted = null;
            }
            $update = $conn->prepare('UPDATE timed_exam_attempts SET state = ?, submitted_at_utc = ?, expired_at_utc = ?, active_key = NULL, is_late = ? WHERE id = ?');
            if (!$update) throw new RuntimeException('Unable to expire exam attempt.');
            $expired = $deadline->format('Y-m-d H:i:s');
            $update->bind_param('sssii', $newState, $submitted, $expired, $late, $id);
            if (!$update->execute()) throw new RuntimeException($update->error);
            $update->close();
            if ($newState === 'auto_submitted') {
                mmh_timed_exam_insert_notification($conn, $studentId, 'Timed Exam submitted automatically', 'Your latest uploaded answer for ' . (string) ($exam['title'] ?? 'your Timed Exam') . ' was submitted automatically when the exam window ended.');
            }
            $fresh['state'] = $newState; $fresh['submitted_at_utc'] = $submitted; $fresh['expired_at_utc'] = $expired; $fresh['is_late'] = $late; $fresh['active_key'] = null;
            $dryRun ? $conn->rollback() : $conn->commit();
            return ['success' => true, 'state' => $newState, 'changed' => true, 'attempt' => $fresh, 'version' => $version];
        } catch (Throwable $e) {
            $conn->rollback();
            mmh_timed_exam_log_lifecycle_error('finalize_attempt', ['exam_id' => $examId, 'attempt_id' => $attemptId, 'student_id' => $studentId], $e);
            return ['success' => false, 'state' => '', 'changed' => false, 'message' => 'Timed Exam finalization failed. The operation has been logged for review.'];
        }
    }
}

if (!function_exists('mmh_timed_exam_finalize_exam_roster')) {
    /** Finalize every student enrolled on or before the primary exam deadline. */
    function mmh_timed_exam_finalize_exam_roster(mysqli $conn, array $exam, bool $dryRun = false, ?DateTimeImmutable $now = null): array
    {
        $counts = ['eligible' => 0, 'submitted' => 0, 'auto_submitted' => 0, 'no_submission' => 0, 'already_terminal' => 0, 'failed' => 0, 'changed' => 0];
        $window = mmh_timed_exam_window($exam);
        $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $deadlineAt = mmh_timed_exam_effective_deadline($exam);
        if (($exam['status'] ?? '') !== 'published' || !$deadlineAt || $now <= $deadlineAt) return $counts + ['due' => false];
        $courseId = (string) ($exam['course_id'] ?? '');
        $itemId = (string) ($exam['item_id'] ?? '');
        $deadline = $deadlineAt->format('Y-m-d H:i:s');
        $eligibility = $conn->prepare("SELECT c.course_id FROM courses c INNER JOIN course_items i ON i.course_id = c.course_id AND i.item_id = ? WHERE c.course_id = ? AND (c.archived_at IS NULL OR c.archived_at > ?) AND c.course_state IN ('public','private') AND (i.archived_at IS NULL OR i.archived_at > ?) AND (i.status IS NULL OR i.status = '' OR i.status = 'published') LIMIT 1");
        if (!$eligibility) return $counts + ['due' => true, 'failed' => 1, 'errors' => ['Unable to validate Timed Exam publication eligibility.']];
        $eligibility->bind_param('ssss', $itemId, $courseId, $deadline, $deadline);
        $eligibility->execute();
        $eligibleExam = $eligibility->get_result()->num_rows === 1;
        $eligibility->close();
        if (!$eligibleExam) return $counts + ['due' => false];
        $stmt = $conn->prepare("SELECT DISTINCT u.user_id FROM course_logs cl INNER JOIN users u ON u.user_id = cl.user_id WHERE cl.course_id = ? AND u.role = 'user' AND ((u.status = '1' AND u.archived_at IS NULL) OR u.archived_at > ?) AND (cl.purchase_date IS NULL OR cl.purchase_date <= ?) ORDER BY u.user_id ASC");
        if (!$stmt) return $counts + ['due' => true, 'failed' => 1, 'errors' => ['Unable to load the eligible Timed Exam roster.']];
        $stmt->bind_param('sss', $courseId, $deadline, $deadline);
        $stmt->execute();
        $students = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id'));
        $stmt->close();
        $counts['eligible'] = count($students);
        $errors = [];
        $primaryExam = $exam;
        $primaryExam['_attempt_scope'] = 'primary';
        foreach ($students as $studentId) {
            $result = mmh_timed_exam_finalize_attempt($conn, $primaryExam, $studentId, null, $now, $dryRun);
            if (empty($result['success'])) {
                $counts['failed']++;
                $errors[] = 'student_id=' . $studentId;
                continue;
            }
            $state = (string) ($result['state'] ?? '');
            if (isset($counts[$state])) $counts[$state]++;
            if (!empty($result['already_terminal'])) $counts['already_terminal']++;
            if (!empty($result['changed'])) $counts['changed']++;
        }
        if (!$dryRun && $counts['failed'] === 0 && mmh_timed_exam_lifecycle_schema_available($conn)) {
            $examId = (int) ($exam['id'] ?? 0);
            $mark = $conn->prepare('UPDATE timed_exams SET roster_finalized_at_utc = COALESCE(roster_finalized_at_utc, ?) WHERE id = ?');
            if ($mark) {
                $finalizedAt = $now->format('Y-m-d H:i:s');
                $mark->bind_param('si', $finalizedAt, $examId);
                if (!$mark->execute()) { $counts['failed']++; $errors[] = 'roster_marker'; }
                $mark->close();
            } else { $counts['failed']++; $errors[] = 'roster_marker'; }
        }
        return $counts + ['due' => true, 'errors' => $errors];
    }
}

if (!function_exists('mmh_timed_exam_release_result')) {
    /** Release one graded result and its notification exactly once. */
    function mmh_timed_exam_release_result(mysqli $conn, array $exam, int $attemptId, bool $force = false, bool $dryRun = false, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $examId = (int) ($exam['id'] ?? 0);
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare('SELECT * FROM timed_exam_attempts WHERE id = ? AND timed_exam_id = ? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('Unable to lock the graded Timed Exam attempt.');
            $stmt->bind_param('ii', $attemptId, $examId);
            $stmt->execute();
            $attempt = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
            if (!$attempt || (string) ($attempt['state'] ?? '') !== 'graded') throw new RuntimeException('Only a graded Timed Exam can be released.');
            if (!empty($attempt['results_released_at_utc'])) {
                $dryRun ? $conn->rollback() : $conn->commit();
                return ['success' => true, 'released' => false, 'already_released' => true, 'attempt' => $attempt];
            }
            $scheduled = mmh_timed_exam_utc_datetime((string) ($exam['results_release_at_utc'] ?? ''));
            $due = $scheduled !== null && $now >= $scheduled;
            if (!$force && !$due) {
                $conn->rollback();
                return ['success' => true, 'released' => false, 'not_due' => true, 'attempt' => $attempt];
            }
            $releasedAt = $now->format('Y-m-d H:i:s');
            $update = $conn->prepare('UPDATE timed_exam_attempts SET results_released_at_utc = ? WHERE id = ? AND results_released_at_utc IS NULL');
            if (!$update) throw new RuntimeException('Unable to release the Timed Exam result.');
            $update->bind_param('si', $releasedAt, $attemptId);
            if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('Unable to persist the Timed Exam result release.');
            $update->close();
            mmh_timed_exam_insert_notification($conn, (int) $attempt['student_id'], 'Timed Exam result available', 'Your result for ' . (string) ($exam['title'] ?? 'your Timed Exam') . ' is now available.');
            $dryRun ? $conn->rollback() : $conn->commit();
            return ['success' => true, 'released' => true, 'released_at_utc' => $releasedAt, 'attempt' => $attempt];
        } catch (Throwable $exception) {
            $conn->rollback();
            mmh_timed_exam_log_lifecycle_error('release_result', ['exam_id' => $examId, 'attempt_id' => $attemptId, 'student_id' => 0], $exception);
            return ['success' => false, 'released' => false, 'message' => 'Timed Exam result release failed. The operation has been logged for review.'];
        }
    }
}

if (!function_exists('mmh_timed_exam_save_grade')) {
    /** Save a review without implicitly releasing it to the student. */
    function mmh_timed_exam_save_grade(mysqli $conn, array $exam, int $attemptId, ?float $grade, string $feedback): array
    {
        $examId = (int) ($exam['id'] ?? 0);
        $maxMarks = $exam['max_marks'] ?? null;
        if ($examId <= 0 || $attemptId <= 0 || ($grade !== null && $grade < 0) || ($maxMarks !== null && $grade !== null && $grade > (float) $maxMarks)) {
            return ['success' => false, 'message' => 'Invalid Timed Exam grade.'];
        }
        try {
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT * FROM timed_exam_attempts WHERE id = ? AND timed_exam_id = ? FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to lock the submitted Timed Exam attempt.');
            $lock->bind_param('ii', $attemptId, $examId);
            $lock->execute();
            $attempt = $lock->get_result()->fetch_assoc() ?: null;
            $lock->close();
            if (!$attempt || !in_array((string) ($attempt['state'] ?? ''), ['submitted', 'auto_submitted', 'graded'], true)) {
                throw new RuntimeException('Only a submitted Timed Exam can be graded.');
            }
            $update = $conn->prepare("UPDATE timed_exam_attempts SET state = 'graded', grade = ?, feedback = ? WHERE id = ? AND timed_exam_id = ?");
            if (!$update) throw new RuntimeException('Unable to save the Timed Exam grade.');
            $update->bind_param('dsii', $grade, $feedback, $attemptId, $examId);
            if (!$update->execute() || $update->affected_rows > 1) throw new RuntimeException($update->error ?: 'Unable to save the Timed Exam grade.');
            $update->close();
            $conn->commit();
            return ['success' => true, 'attempt_id' => $attemptId, 'released' => !empty($attempt['results_released_at_utc'])];
        } catch (Throwable $exception) {
            $conn->rollback();
            mmh_timed_exam_log_lifecycle_error('save_grade', ['exam_id' => $examId, 'attempt_id' => $attemptId, 'student_id' => 0], $exception);
            return ['success' => false, 'message' => $exception->getMessage() ?: 'The Timed Exam grade could not be saved.'];
        }
    }
}

if (!function_exists('mmh_timed_exam_release_due_results')) {
    function mmh_timed_exam_release_due_results(mysqli $conn, array $exam, bool $dryRun = false, ?DateTimeImmutable $now = null): array
    {
        $counts = ['eligible' => 0, 'released' => 0, 'already_released' => 0, 'failed' => 0];
        $scheduled = mmh_timed_exam_utc_datetime((string) ($exam['results_release_at_utc'] ?? ''));
        $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($scheduled === null || $now < $scheduled) return $counts + ['due' => false];
        $stmt = $conn->prepare("SELECT id FROM timed_exam_attempts WHERE timed_exam_id = ? AND state = 'graded' ORDER BY id ASC");
        if (!$stmt) return $counts + ['due' => true, 'failed' => 1];
        $examId = (int) ($exam['id'] ?? 0);
        $stmt->bind_param('i', $examId);
        $stmt->execute();
        $attemptIds = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $stmt->close();
        $counts['eligible'] = count($attemptIds);
        foreach ($attemptIds as $attemptId) {
            $result = mmh_timed_exam_release_result($conn, $exam, $attemptId, false, $dryRun, $now);
            if (empty($result['success'])) $counts['failed']++;
            elseif (!empty($result['released'])) $counts['released']++;
            elseif (!empty($result['already_released'])) $counts['already_released']++;
        }
        return $counts + ['due' => true];
    }
}

if (!function_exists('mmh_timed_exam_refresh_attempt')) {
    /** Backward-compatible read fallback; canonical expiry logic lives in finalize_attempt(). */
    function mmh_timed_exam_refresh_attempt(mysqli $conn, array $exam, ?array $attempt): ?array
    {
        if (!$attempt) return null;
        $result = mmh_timed_exam_finalize_attempt($conn, $exam, (int) ($attempt['student_id'] ?? 0), (int) ($attempt['id'] ?? 0));
        return !empty($result['success']) && is_array($result['attempt'] ?? null) ? $result['attempt'] : $attempt;
    }
}

if (!function_exists('mmh_timed_exam_student_context')) {
    function mmh_timed_exam_student_context(mysqli $conn, array $exam, int $studentId): array
    {
        $scope = mmh_timed_exam_attempt_scope($exam);
        $attempt = mmh_timed_exam_student_attempt($conn, $studentId, (int) $exam['id'], false, $scope);
        $state = mmh_timed_exam_state($exam, $attempt);
        if ($state['key'] === 'open' || $state['key'] === 'grace') {
            $attempt = $attempt ?: mmh_timed_exam_create_attempt($conn, $exam, $studentId);
            $state = mmh_timed_exam_state($exam, $attempt);
        } elseif ($state['key'] === 'expired') {
            $finalized = mmh_timed_exam_finalize_attempt($conn, $exam, $studentId, $attempt ? (int) $attempt['id'] : null);
            if (!empty($finalized['success'])) {
                $attempt = $finalized['attempt'] ?? mmh_timed_exam_student_attempt($conn, $studentId, (int) $exam['id'], false, $scope);
                $state = mmh_timed_exam_state($exam, $attempt);
            } else {
                $state = ['key' => 'finalization_error', 'label' => 'Finalization pending', 'remaining_seconds' => 0, 'window' => mmh_timed_exam_window($exam)];
            }
        }
        if ($attempt && (string) ($attempt['state'] ?? '') === 'graded' && empty($attempt['results_released_at_utc'])) {
            $release = mmh_timed_exam_release_result($conn, $exam, (int) $attempt['id']);
            if (!empty($release['released'])) {
                $attempt = mmh_timed_exam_student_attempt($conn, $studentId, (int) $exam['id'], false, $scope) ?: $attempt;
            }
        }
        $latest = $attempt ? mmh_timed_exam_latest_version($conn, (int) $attempt['id']) : null;
        $uploadLimit = max(1, (int) ($exam['max_attempts'] ?? 1));
        $uploadCount = $attempt ? mmh_timed_exam_upload_version_count($conn, (int) $attempt['id']) : 0;
        return ['exam' => $exam, 'attempt' => $attempt, 'latest_version' => $latest, 'state' => $state, 'upload_version_limit' => $uploadLimit, 'upload_version_count' => $uploadCount, 'upload_versions_remaining' => max(0, $uploadLimit - $uploadCount)];
    }
}

if (!function_exists('mmh_timed_exam_upload')) {
    function mmh_timed_exam_upload(mysqli $conn, array $exam, int $studentId, array $file): array
    {
        if ($studentId <= 0) return [false, 'You must be signed in.'];
        if (!student_course_access_enrolled($conn, $studentId, (string) ($exam['course_id'] ?? ''))) return [false, 'This Timed Exam is not available.'];
        $context = mmh_timed_exam_student_context($conn, $exam, $studentId);
        $state = $context['state']['key'] ?? '';
        if (!in_array($state, ['open', 'grace'], true)) return [false, 'Uploads are closed for this exam.'];
        $attempt = $context['attempt'] ?: mmh_timed_exam_create_attempt($conn, $exam, $studentId);
        if (!$attempt) return [false, 'This exam attempt is no longer available.'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [false, 'Choose a valid answer file.'];
        $original = trim((string) ($file['name'] ?? ''));
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($original === '' || $tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > (int) ($exam['max_file_size_bytes'] ?? 10485760)) return [false, 'The answer file is missing or exceeds the configured size limit.'];
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $exam['allowed_answer_types_list'] ?? ['pdf'], true)) return [false, 'This answer file type is not allowed.'];
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mime = $finfo ? (string) finfo_file($finfo, $tmp) : (string) ($file['type'] ?? '');
        if ($finfo) finfo_close($finfo);
        $mimeMap = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']];
        if (!in_array($mime, $mimeMap[$ext] ?? [], true)) return [false, 'The answer file content does not match its extension.'];
        $dir = dirname(__DIR__) . '/storage/private/timed-exams/answers/' . gmdate('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) return [false, 'Unable to prepare secure answer storage.'];
        $storedName = bin2hex(random_bytes(20)) . '.' . $ext;
        $path = $dir . '/' . $storedName;
        if (!move_uploaded_file($tmp, $path)) return [false, 'The answer file could not be saved securely.'];
        $relative = 'storage/private/timed-exams/answers/' . gmdate('Y/m') . '/' . $storedName;
        try {
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT * FROM timed_exam_attempts WHERE id = ? FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to lock exam attempt.');
            $attemptId = (int) $attempt['id'];
            $lock->bind_param('i', $attemptId); $lock->execute();
            $fresh = $lock->get_result()->fetch_assoc() ?: null; $lock->close();
            if (!$fresh) throw new RuntimeException('Exam attempt not found.');
            $freshContext = mmh_timed_exam_state($exam, $fresh);
            if (!in_array($freshContext['key'], ['open', 'grace'], true)) throw new RuntimeException('The exam window has closed.');
            if (in_array((string) ($fresh['state'] ?? ''), mmh_timed_exam_terminal_states(), true)) throw new RuntimeException('This exam has already been finalized.');
            $capacity = mmh_timed_exam_upload_capacity($conn, $exam, $attemptId, true);
            if (empty($capacity['allowed'])) throw new RuntimeException('You have used all available answer uploads for this exam.');
            $count = $conn->prepare('SELECT COALESCE(MAX(version_number), 0) AS last_version FROM timed_exam_submission_versions WHERE attempt_id = ?');
            if (!$count) throw new RuntimeException('Unable to count answer versions.');
            $count->bind_param('i', $attemptId); $count->execute();
            $versionNumber = ((int) ($count->get_result()->fetch_assoc()['last_version'] ?? 0)) + 1; $count->close();
            $late = $freshContext['key'] === 'grace' ? 1 : 0;
            $uploadedAt = gmdate('Y-m-d H:i:s');
            $sha = hash_file('sha256', $path) ?: null;
            $insert = $conn->prepare("INSERT INTO timed_exam_submission_versions (attempt_id, version_number, original_filename, storage_key, mime_type, file_size_bytes, sha256, status, is_late, uploaded_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, 'uploaded', ?, ?)");
            if (!$insert) throw new RuntimeException('Unable to prepare answer upload.');
            $insert->bind_param('iisssisis', $attemptId, $versionNumber, $original, $relative, $mime, $size, $sha, $late, $uploadedAt);
            if (!$insert->execute()) throw new RuntimeException($insert->error);
            $insert->close();
            $versionId = (int) $conn->insert_id;
            $update = $conn->prepare("UPDATE timed_exam_attempts SET latest_version_id = ?, is_late = ?, state = 'uploaded' WHERE id = ?");
            if (!$update) throw new RuntimeException('Unable to update attempt.');
            $update->bind_param('iii', $versionId, $late, $attemptId); if (!$update->execute()) throw new RuntimeException($update->error); $update->close();
            $conn->commit();
            return [true, 'Answer uploaded. Review it and submit the exam.', ['version_id' => $versionId, 'late' => $late, 'upload_versions_remaining' => max(0, (int) $capacity['remaining'] - 1)]];
        } catch (Throwable $e) {
            $conn->rollback();
            @unlink($path);
            return [false, $e->getMessage() ?: 'The answer could not be uploaded.'];
        }
    }
}

if (!function_exists('mmh_timed_exam_submit')) {
    function mmh_timed_exam_submit(mysqli $conn, array $exam, int $studentId): array
    {
        if (!student_course_access_enrolled($conn, $studentId, (string) ($exam['course_id'] ?? ''))) return [false, 'This Timed Exam is not available.'];
        $context = mmh_timed_exam_student_context($conn, $exam, $studentId);
        $attempt = $context['attempt'];
        if (!$attempt) return [false, 'Upload an answer before submitting the exam.'];
        try {
            $conn->begin_transaction();
            $id = (int) $attempt['id'];
            $lock = $conn->prepare('SELECT * FROM timed_exam_attempts WHERE id = ? FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to lock exam attempt.');
            $lock->bind_param('i', $id); $lock->execute(); $fresh = $lock->get_result()->fetch_assoc() ?: null; $lock->close();
            if (!$fresh) throw new RuntimeException('Exam attempt not found.');
            if (in_array((string) ($fresh['state'] ?? ''), ['submitted', 'auto_submitted', 'graded', 'no_submission'], true)) { $conn->commit(); return [true, 'This exam was already submitted.', ['already_submitted' => true]]; }
            $state = mmh_timed_exam_state($exam, $fresh);
            if (!in_array($state['key'], ['open', 'grace'], true)) throw new RuntimeException('The exam window has closed.');
            $version = mmh_timed_exam_latest_version($conn, $id, true);
            if (!$version || (string) ($version['status'] ?? '') !== 'uploaded') throw new RuntimeException('Upload an answer before submitting the exam.');
            $late = $state['key'] === 'grace' ? 1 : 0;
            $submittedAt = gmdate('Y-m-d H:i:s');
            $versionId = (int) $version['id'];
            $versionUpdate = $conn->prepare("UPDATE timed_exam_submission_versions SET status = 'final', is_late = ?, submitted_at_utc = ? WHERE id = ? AND status = 'uploaded'");
            if (!$versionUpdate) throw new RuntimeException('Unable to finalize answer.');
            $versionUpdate->bind_param('isi', $late, $submittedAt, $versionId); if (!$versionUpdate->execute()) throw new RuntimeException($versionUpdate->error); $versionUpdate->close();
            $attemptUpdate = $conn->prepare("UPDATE timed_exam_attempts SET state = 'submitted', submitted_at_utc = ?, is_late = ?, active_key = NULL WHERE id = ?");
            if (!$attemptUpdate) throw new RuntimeException('Unable to finalize exam.');
            $attemptUpdate->bind_param('sii', $submittedAt, $late, $id); if (!$attemptUpdate->execute()) throw new RuntimeException($attemptUpdate->error); $attemptUpdate->close();
            $conn->commit();
            return [true, 'Exam submitted successfully.', ['late' => $late]];
        } catch (Throwable $e) {
            $conn->rollback();
            return [false, $e->getMessage() ?: 'The exam could not be submitted.'];
        }
    }
}

if (!function_exists('mmh_timed_exam_remove_latest_upload')) {
    /** Remove only the current replaceable upload; finalized submissions are immutable. */
    function mmh_timed_exam_remove_latest_upload(mysqli $conn, array $exam, int $studentId): array
    {
        if (!student_course_access_enrolled($conn, $studentId, (string) ($exam['course_id'] ?? ''))) return [false, 'This Timed Exam is not available.'];
        $context = mmh_timed_exam_student_context($conn, $exam, $studentId);
        $attempt = $context['attempt'] ?? null;
        if (!$attempt) return [false, 'There is no uploaded answer to remove.'];
        try {
            $conn->begin_transaction();
            $id = (int) ($attempt['id'] ?? 0);
            $lock = $conn->prepare('SELECT * FROM timed_exam_attempts WHERE id = ? AND student_id = ? AND timed_exam_id = ? FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to lock exam attempt.');
            $examId = (int) ($exam['id'] ?? 0);
            $lock->bind_param('iii', $id, $studentId, $examId); $lock->execute();
            $fresh = $lock->get_result()->fetch_assoc() ?: null; $lock->close();
            if (!$fresh) throw new RuntimeException('Exam attempt not found.');
            $state = mmh_timed_exam_state($exam, $fresh);
            if (!in_array($state['key'], ['open', 'grace'], true)) throw new RuntimeException('Uploaded answers can only be removed before final submission.');
            $version = mmh_timed_exam_latest_version($conn, $id, true);
            if (!$version || (string) ($version['status'] ?? '') !== 'uploaded') throw new RuntimeException('There is no replaceable uploaded answer.');
            $versionId = (int) $version['id'];
            $delete = $conn->prepare("UPDATE timed_exam_submission_versions SET status = 'removed' WHERE id = ? AND attempt_id = ? AND status = 'uploaded'");
            if (!$delete) throw new RuntimeException('Unable to remove uploaded answer.');
            $delete->bind_param('ii', $versionId, $id); if (!$delete->execute() || $delete->affected_rows !== 1) throw new RuntimeException('The uploaded answer could not be removed.'); $delete->close();
            $previous = mmh_timed_exam_latest_version($conn, $id, true);
            if ($previous) {
                $latestId = (int) $previous['id']; $late = (int) ($previous['is_late'] ?? 0);
                $update = $conn->prepare("UPDATE timed_exam_attempts SET latest_version_id = ?, is_late = ?, state = 'uploaded' WHERE id = ?");
                if (!$update) throw new RuntimeException('Unable to update exam attempt.');
                $update->bind_param('iii', $latestId, $late, $id);
            } else {
                $update = $conn->prepare("UPDATE timed_exam_attempts SET latest_version_id = NULL, is_late = 0, state = 'in_progress' WHERE id = ?");
                if (!$update) throw new RuntimeException('Unable to update exam attempt.');
                $update->bind_param('i', $id);
            }
            if (!$update->execute()) throw new RuntimeException($update->error); $update->close();
            $conn->commit();
            $path = dirname(__DIR__) . '/' . ltrim((string) ($version['storage_key'] ?? ''), '/');
            if ($path !== dirname(__DIR__) . '/' && is_file($path)) @unlink($path);
            return [true, 'Uploaded answer removed.'];
        } catch (Throwable $e) {
            $conn->rollback();
            return [false, $e->getMessage() ?: 'The uploaded answer could not be removed.'];
        }
    }
}

if (!function_exists('mmh_timed_exam_download')) {
    function mmh_timed_exam_download(mysqli $conn, array $exam, bool $download = false): void
    {
        if ((!$download && empty($exam['paper_view_allowed'])) || ($download && empty($exam['paper_download_allowed']))) {
            http_response_code(403); exit('This exam paper is not available.');
        }
        $resolved = mmh_timed_exam_normalize_external_paper_url((string) ($exam['paper_external_url'] ?? ''));
        if (!$resolved) {
            http_response_code(409);
            exit('This exam paper needs a Google Drive link. Please contact your teacher.');
        }
        $target = $download ? $resolved['download_url'] : $resolved['open_url'];
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $target, true, 302);
        exit;
    }
}

if (!function_exists('mmh_timed_exam_answer_download')) {
    function mmh_timed_exam_answer_download(mysqli $conn, array $version): void
    {
        $key = (string) ($version['storage_key'] ?? '');
        $path = dirname(__DIR__) . '/' . ltrim($key, '/');
        if ($key === '' || str_contains($key, '..') || !is_file($path)) { http_response_code(404); exit('Answer file not found.'); }
        header('Content-Type: ' . ((string) ($version['mime_type'] ?? '') ?: 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) ($version['original_filename'] ?? 'answer')) . '"');
        readfile($path); exit;
    }
}

if (!function_exists('mmh_timed_exam_save_config')) {
    /** Save the normalized configuration attached to a canonical course item. */
    function mmh_timed_exam_save_config(mysqli $conn, string $courseId, string $itemId, array $data, ?array $paperFile, ?int $adminId = null): int
    {
        if (!mmh_timed_exam_table_available($conn)) throw new RuntimeException('Run the Timed Exam migration before saving an exam.');
        $title = trim((string) ($data['title'] ?? ''));
        $instructions = (string) ($data['instructions'] ?? '');
        $status = in_array((string) ($data['status'] ?? 'draft'), ['draft', 'published', 'archived'], true) ? (string) $data['status'] : 'draft';
        $timingMode = 'fixed_window';
        $scheduled = mmh_timed_exam_datetime_to_utc($data['scheduled_start_at'] ?? null);
        if ($status === 'published' && $scheduled === null) throw new InvalidArgumentException('A published Timed Exam needs a scheduled start time.');
        $duration = max(1, min(1440, (int) ($data['duration_minutes'] ?? 60)));
        $grace = max(0, min(1440, (int) ($data['grace_minutes'] ?? 0)));
        // Backward-compatible column name: max_attempts now consistently
        // means successful answer upload/replacement versions.
        $maxAttempts = max(1, min(20, (int) ($data['max_attempts'] ?? 1)));
        $types = mmh_timed_exam_parse_allowed_types((string) ($data['allowed_answer_types'] ?? 'pdf,jpg,jpeg,png'));
        $maxSize = max(1024, min(524288000, (int) ($data['max_file_size_bytes'] ?? 10485760)));
        $viewAllowed = !empty($data['paper_view_allowed']) ? 1 : 0;
        $downloadAllowed = !empty($data['paper_download_allowed']) ? 1 : 0;
        $lateAllowed = !empty($data['late_submission_allowed']) ? 1 : 0;
        $expiryPolicy = 'auto_submit_latest';
        $maxMarks = ($data['max_marks'] ?? '') === '' ? null : max(0, (float) $data['max_marks']);
        $release = mmh_timed_exam_datetime_to_utc($data['results_release_at'] ?? null);
        $recoveryStart = mmh_timed_exam_datetime_to_utc($data['recovery_window_start_at'] ?? null);
        $recoveryEnd = mmh_timed_exam_datetime_to_utc($data['recovery_window_end_at'] ?? null);
        $recoveryAllowed = !empty($data['recovery_allowed']) ? 1 : 0;
        $existing = mmh_timed_exam_load_for_item($conn, $courseId, $itemId, true);
        $paperSource = 'external_link';
        $storageKey = $existing['paper_storage_key'] ?? null;
        $paperName = $existing['paper_original_name'] ?? null;
        $paperMime = $existing['paper_mime'] ?? null;
        $paperSize = $existing['paper_size_bytes'] ?? null;
        $externalUrl = trim((string) ($data['paper_external_url'] ?? ''));
        $externalPreviewUrl = (string) ($existing['paper_external_preview_url'] ?? '');
        $externalDownloadUrl = (string) ($existing['paper_external_download_url'] ?? '');
        $fallbackInstructions = trim((string) ($data['paper_fallback_instructions'] ?? ($existing['paper_fallback_instructions'] ?? '')));
        if ($externalUrl !== '') {
            $resolved = mmh_timed_exam_normalize_external_paper_url($externalUrl);
            if (!$resolved) throw new InvalidArgumentException('Use a secure HTTPS Google Drive file link.');
            $paperSource = 'external_link';
            $externalUrl = $resolved['url'];
            $externalPreviewUrl = (string) ($resolved['preview_url'] ?? '');
            $externalDownloadUrl = (string) ($resolved['download_url'] ?? '');
            // Keep legacy storage metadata intact for audit/compatibility, but
            // never serve it once an exam is configured for a Drive paper.
        } elseif ($externalUrl === '') {
            $externalUrl = (string) ($existing['paper_external_url'] ?? '');
        }
        if ($paperFile && ($paperFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Timed Exams now use a Google Drive Exam Paper Link. Uploaded paper files are no longer supported.');
        }
        if ($externalUrl === '') {
            throw new InvalidArgumentException('Add a Google Drive Exam Paper Link before saving this Timed Exam.');
        }
        $jsonTypes = implode(',', $types);
        $createdBy = $adminId ?: null;
        if ($existing) {
            $stmt = $conn->prepare('UPDATE timed_exams SET title = ?, instructions = ?, status = ?, timing_mode = ?, scheduled_start_at_utc = ?, duration_minutes = ?, grace_minutes = ?, max_attempts = ?, allowed_answer_types = ?, max_file_size_bytes = ?, paper_source = ?, paper_external_url = ?, paper_external_preview_url = ?, paper_external_download_url = ?, paper_fallback_instructions = ?, paper_storage_key = ?, paper_original_name = ?, paper_mime = ?, paper_size_bytes = ?, paper_view_allowed = ?, paper_download_allowed = ?, late_submission_allowed = ?, expiry_policy = ?, max_marks = ?, results_release_at_utc = ?, recovery_window_start_at_utc = ?, recovery_window_end_at_utc = ?, recovery_allowed = ?, updated_by = ? WHERE id = ?');
            if (!$stmt) throw new RuntimeException('Unable to prepare Timed Exam update.');
            $id = (int) $existing['id'];
            $stmt->bind_param('sssssiiisissssssssiiiisdsssiii', $title, $instructions, $status, $timingMode, $scheduled, $duration, $grace, $maxAttempts, $jsonTypes, $maxSize, $paperSource, $externalUrl, $externalPreviewUrl, $externalDownloadUrl, $fallbackInstructions, $storageKey, $paperName, $paperMime, $paperSize, $viewAllowed, $downloadAllowed, $lateAllowed, $expiryPolicy, $maxMarks, $release, $recoveryStart, $recoveryEnd, $recoveryAllowed, $createdBy, $id);
            if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
            $stmt->close();
            if (mmh_timed_exam_lifecycle_schema_available($conn)) {
                $resetRoster = $conn->prepare('UPDATE timed_exams SET roster_finalized_at_utc = NULL WHERE id = ?');
                if (!$resetRoster) throw new RuntimeException('Unable to reset Timed Exam roster finalization after configuration changed.');
                $resetRoster->bind_param('i', $id);
                if (!$resetRoster->execute()) { $error = $resetRoster->error; $resetRoster->close(); throw new RuntimeException($error); }
                $resetRoster->close();
            }
            return $id;
        }
        $stmt = $conn->prepare('INSERT INTO timed_exams (course_id, item_id, title, instructions, status, timing_mode, scheduled_start_at_utc, duration_minutes, grace_minutes, max_attempts, allowed_answer_types, max_file_size_bytes, paper_source, paper_external_url, paper_external_preview_url, paper_external_download_url, paper_fallback_instructions, paper_storage_key, paper_original_name, paper_mime, paper_size_bytes, paper_view_allowed, paper_download_allowed, late_submission_allowed, expiry_policy, max_marks, results_release_at_utc, recovery_window_start_at_utc, recovery_window_end_at_utc, recovery_allowed, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Unable to prepare Timed Exam save.');
        $stmt->bind_param('sssssssiiisissssssssiiiisdsssiii', $courseId, $itemId, $title, $instructions, $status, $timingMode, $scheduled, $duration, $grace, $maxAttempts, $jsonTypes, $maxSize, $paperSource, $externalUrl, $externalPreviewUrl, $externalDownloadUrl, $fallbackInstructions, $storageKey, $paperName, $paperMime, $paperSize, $viewAllowed, $downloadAllowed, $lateAllowed, $expiryPolicy, $maxMarks, $release, $recoveryStart, $recoveryEnd, $recoveryAllowed, $createdBy, $createdBy);
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
        $id = (int) $stmt->insert_id; $stmt->close();
        return $id;
    }
}

if (!function_exists('mmh_timed_exam_admin_attempts')) {
    function mmh_timed_exam_admin_attempts(mysqli $conn, int $examId): array
    {
        $examStmt = $conn->prepare('SELECT * FROM timed_exams WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $exam = null;
        if ($examStmt) {
            $examStmt->bind_param('i', $examId);
            $examStmt->execute();
            $exam = $examStmt->get_result()->fetch_assoc() ?: null;
            $examStmt->close();
        }
        if ($exam && (string) ($exam['status'] ?? '') === 'published') {
            $roster = mmh_timed_exam_finalize_exam_roster($conn, $exam);
            if (($roster['failed'] ?? 0) > 0) {
                error_log('Timed Exam admin roster fallback had failures exam_id=' . $examId . ' failed=' . (int) $roster['failed']);
            }
            $release = mmh_timed_exam_release_due_results($conn, $exam);
            if (($release['failed'] ?? 0) > 0) {
                error_log('Timed Exam admin release fallback had failures exam_id=' . $examId . ' failed=' . (int) $release['failed']);
            }
        }
        $stmt = $conn->prepare('SELECT a.*, u.username, u.full_name, v.id AS version_id, v.original_filename, v.storage_key, v.mime_type, v.file_size_bytes, v.uploaded_at_utc, v.submitted_at_utc AS version_submitted_at_utc, v.status AS version_status FROM timed_exam_attempts a INNER JOIN users u ON u.user_id = a.student_id LEFT JOIN timed_exam_submission_versions v ON v.id = a.latest_version_id WHERE a.timed_exam_id = ? ORDER BY u.full_name ASC, u.username ASC, a.attempt_number ASC');
        if (!$stmt) return [];
        $stmt->bind_param('i', $examId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
    }
}

if (!function_exists('mmh_timed_exam_course_states')) {
    function mmh_timed_exam_course_states(mysqli $conn, int $studentId, string $courseId, bool $includeRecoveryCompletion = false): array
    {
        if ($studentId <= 0 || $courseId === '' || !mmh_timed_exam_table_available($conn)) return [];
        $lifecycleSchema = mmh_timed_exam_lifecycle_schema_available($conn);
        $scopeFilter = $lifecycleSchema
            ? ($includeRecoveryCompletion
                ? " AND (a2.attempt_scope = 'primary' OR a2.state IN ('submitted','auto_submitted','graded'))"
                : " AND a2.attempt_scope = 'primary'")
            : '';
        $attemptOrder = $includeRecoveryCompletion
            ? " ORDER BY CASE WHEN a2.state IN ('submitted','auto_submitted','graded') THEN 0 ELSE 1 END, a2.id DESC LIMIT 1"
            : ' ORDER BY a2.id DESC LIMIT 1';
        $stmt = $conn->prepare('SELECT e.*, a.id AS attempt_id, a.state AS attempt_state, a.submitted_at_utc, a.is_late, a.grade, a.feedback, a.results_released_at_utc AS attempt_results_released_at_utc FROM timed_exams e LEFT JOIN timed_exam_attempts a ON a.id = (SELECT a2.id FROM timed_exam_attempts a2 WHERE a2.timed_exam_id = e.id AND a2.student_id = ?' . $scopeFilter . $attemptOrder . ') WHERE e.course_id = ? AND e.deleted_at IS NULL AND e.status = \'published\'');
        if (!$stmt) return [];
        $stmt->bind_param('is', $studentId, $courseId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $map = [];
        foreach ($rows as $row) {
            $attempt = $row['attempt_id'] ? ['id' => (int) $row['attempt_id'], 'student_id' => $studentId, 'state' => $row['attempt_state'], 'results_released_at_utc' => $row['attempt_results_released_at_utc'] ?? null] : null;
            $state = mmh_timed_exam_state($row, $attempt);
            if (($state['key'] ?? '') === 'expired') {
                $finalized = mmh_timed_exam_finalize_attempt($conn, $row, $studentId, $attempt ? (int) $attempt['id'] : null);
                if (!empty($finalized['success'])) {
                    $attempt = $finalized['attempt'] ?? mmh_timed_exam_student_attempt($conn, $studentId, (int) $row['id'], false, 'primary');
                    $state = mmh_timed_exam_state($row, $attempt);
                    $row['attempt_id'] = $attempt['id'] ?? null;
                    $row['attempt_state'] = $attempt['state'] ?? null;
                    $row['submitted_at_utc'] = $attempt['submitted_at_utc'] ?? null;
                    $row['is_late'] = $attempt['is_late'] ?? 0;
                } else {
                    $state = ['key' => 'finalization_error', 'label' => 'Finalization pending'];
                }
            }
            if (($attempt['state'] ?? '') === 'graded' && empty($attempt['results_released_at_utc'])) {
                $released = mmh_timed_exam_release_result($conn, $row, (int) $attempt['id']);
                if (!empty($released['released'])) $row['attempt_results_released_at_utc'] = $released['released_at_utc'];
            }
            $map[(string) $row['item_id']] = array_merge($row, ['state_key' => $state['key'], 'state_label' => $state['label']]);
        }
        return $map;
    }
}
