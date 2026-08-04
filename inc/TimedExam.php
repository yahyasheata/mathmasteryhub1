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
        if ($host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
        $isHost = static fn(string $domain): bool => $host === $domain || str_ends_with($host, '.' . $domain);
        $path = (string) ($parts['path'] ?? '');
        $supported = $isHost('drive.google.com') || $isHost('docs.google.com')
            || $isHost('drive.usercontent.google.com') || $isHost('sharepoint.com')
            || $isHost('onedrive.live.com') || $isHost('1drv.ms')
            || $isHost('mathmasteryhub.com');
        if (!$supported) return null;

        $type = (string) mmh_course_resource_type_for_url($url, 'external_link');
        $details = mmh_course_resource_embed_details($url, $type);
        if (($isHost('drive.google.com') || $isHost('docs.google.com')) && !$details) return null;
        $isPdf = (bool) preg_match('/\.pdf(?:$|[?#])/i', $url);
        if (!$details && !$isPdf && !$isHost('sharepoint.com') && !$isHost('onedrive.live.com') && !$isHost('1drv.ms')) return null;
        $preview = $details['embed_url'] ?? $url;
        $download = $details['download_url'] ?? null;
        if ($isPdf && $download === null) $download = $url;
        if (!$isPdf && !$details) $download = null;
        return [
            'url' => $url,
            'preview_url' => mmh_course_resource_safe_url((string) $preview),
            'download_url' => $download !== null ? mmh_course_resource_safe_url((string) $download) : null,
            'kind' => (string) ($details['kind'] ?? ($isPdf ? 'pdf' : 'external')),
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
            return ['plan' => $plan, 'task' => $task, 'exam' => mmh_timed_exam_with_window($exam, $start, $end)];
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
        if ($attempt && in_array((string) ($attempt['state'] ?? ''), ['submitted', 'auto_submitted', 'graded', 'no_submission'], true)) {
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
    function mmh_timed_exam_student_attempt(mysqli $conn, int $studentId, int $examId, bool $create = false): ?array
    {
        if ($studentId <= 0 || $examId <= 0 || !mmh_timed_exam_table_available($conn)) return null;
        $stmt = $conn->prepare('SELECT * FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ? ORDER BY id DESC LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('ii', $examId, $studentId);
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
        $window = mmh_timed_exam_window($exam);
        if (!$window['opens_at']) return null;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($now < $window['opens_at']) return null;
        try {
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT * FROM timed_exam_attempts WHERE timed_exam_id = ? AND student_id = ? AND active_key IS NOT NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to lock exam attempt.');
            $examId = (int) $exam['id'];
            $lock->bind_param('ii', $examId, $studentId);
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
            $maxAttempts = max(1, (int) ($exam['max_attempts'] ?? 1));
            if ($attemptNumber > $maxAttempts) { $conn->commit(); return null; }
            $opens = $window['opens_at']->format('Y-m-d H:i:s');
            $closes = $window['closes_at']->format('Y-m-d H:i:s');
            $grace = $window['grace_closes_at']->format('Y-m-d H:i:s');
            $activeKey = bin2hex(random_bytes(16));
            $state = 'in_progress';
            $stmt = $conn->prepare('INSERT INTO timed_exam_attempts (timed_exam_id, student_id, attempt_number, active_key, state, opens_at_utc, closes_at_utc, grace_closes_at_utc, started_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$stmt) throw new RuntimeException('Unable to prepare exam attempt.');
            $started = $now->format('Y-m-d H:i:s');
            $stmt->bind_param('iiissssss', $examId, $studentId, $attemptNumber, $activeKey, $state, $opens, $closes, $grace, $started);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                if (str_contains(strtolower($error), 'duplicate')) {
                    $conn->rollback();
                    return mmh_timed_exam_student_attempt($conn, $studentId, $examId, false);
                }
                throw new RuntimeException($error ?: 'Unable to start exam attempt.');
            }
            $newId = $stmt->insert_id;
            $stmt->close();
            $conn->commit();
            $result = mmh_timed_exam_student_attempt($conn, $studentId, $examId, false);
            if (!$result && $newId > 0) {
                $result = ['id' => $newId, 'timed_exam_id' => $examId, 'student_id' => $studentId, 'attempt_number' => $attemptNumber, 'state' => $state, 'opens_at_utc' => $opens, 'closes_at_utc' => $closes, 'grace_closes_at_utc' => $grace, 'started_at_utc' => $started, 'active_key' => $activeKey];
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
        $sql = 'SELECT * FROM timed_exam_submission_versions WHERE attempt_id = ? ORDER BY version_number DESC, id DESC LIMIT 1';
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

if (!function_exists('mmh_timed_exam_refresh_attempt')) {
    function mmh_timed_exam_refresh_attempt(mysqli $conn, array $exam, ?array $attempt): ?array
    {
        if (!$attempt || (int) ($attempt['id'] ?? 0) <= 0) return $attempt;
        $state = (string) ($attempt['state'] ?? '');
        if (in_array($state, ['submitted', 'auto_submitted', 'graded', 'no_submission'], true)) return $attempt;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $window = mmh_timed_exam_window($exam);
        if (!$window['grace_closes_at'] || $now <= $window['grace_closes_at']) return $attempt;
        try {
            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT * FROM timed_exam_attempts WHERE id = ? FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to lock attempt.');
            $id = (int) $attempt['id'];
            $lock->bind_param('i', $id);
            $lock->execute();
            $fresh = $lock->get_result()->fetch_assoc() ?: null;
            $lock->close();
            if (!$fresh || in_array((string) ($fresh['state'] ?? ''), ['submitted', 'auto_submitted', 'graded', 'no_submission'], true)) { $conn->commit(); return $fresh ?: $attempt; }
            $version = mmh_timed_exam_latest_version($conn, $id, true);
            $effectiveAt = $window['closes_at'];
            $late = 0;
            if ($version && (int) strtotime((string) $version['uploaded_at_utc']) > (int) $window['closes_at']->getTimestamp()) { $effectiveAt = $window['grace_closes_at']; $late = 1; }
            if ($version && (string) ($exam['expiry_policy'] ?? 'auto_submit_latest') === 'auto_submit_latest') {
                $versionId = (int) $version['id'];
                $versionStmt = $conn->prepare("UPDATE timed_exam_submission_versions SET status = 'auto_submitted', is_late = ?, submitted_at_utc = ? WHERE id = ? AND status = 'uploaded'");
                if (!$versionStmt) throw new RuntimeException('Unable to finalize expired upload.');
                $submittedAt = $effectiveAt->format('Y-m-d H:i:s');
                $versionStmt->bind_param('isi', $late, $submittedAt, $versionId);
                if (!$versionStmt->execute()) throw new RuntimeException($versionStmt->error);
                $versionStmt->close();
                $newState = 'auto_submitted';
                $submitted = $submittedAt;
            } else {
                $newState = 'no_submission';
                $submitted = null;
            }
            $update = $conn->prepare('UPDATE timed_exam_attempts SET state = ?, submitted_at_utc = ?, expired_at_utc = ?, active_key = NULL, is_late = ? WHERE id = ?');
            if (!$update) throw new RuntimeException('Unable to expire exam attempt.');
            $expired = $now->format('Y-m-d H:i:s');
            $update->bind_param('sssii', $newState, $submitted, $expired, $late, $id);
            if (!$update->execute()) throw new RuntimeException($update->error);
            $update->close();
            $conn->commit();
            $attempt['state'] = $newState; $attempt['submitted_at_utc'] = $submitted; $attempt['expired_at_utc'] = $expired; $attempt['is_late'] = $late; $attempt['active_key'] = null;
            return $attempt;
        } catch (Throwable $e) {
            $conn->rollback();
            return $attempt;
        }
    }
}

if (!function_exists('mmh_timed_exam_student_context')) {
    function mmh_timed_exam_student_context(mysqli $conn, array $exam, int $studentId): array
    {
        $attempt = mmh_timed_exam_student_attempt($conn, $studentId, (int) $exam['id'], false);
        $state = mmh_timed_exam_state($exam, $attempt);
        if ($state['key'] === 'open' || $state['key'] === 'grace') {
            $attempt = $attempt ?: mmh_timed_exam_create_attempt($conn, $exam, $studentId);
            $attempt = mmh_timed_exam_refresh_attempt($conn, $exam, $attempt);
            $state = mmh_timed_exam_state($exam, $attempt);
        } elseif ($state['key'] === 'expired' && $attempt) {
            $attempt = mmh_timed_exam_refresh_attempt($conn, $exam, $attempt);
            $state = mmh_timed_exam_state($exam, $attempt);
        }
        $latest = $attempt ? mmh_timed_exam_latest_version($conn, (int) $attempt['id']) : null;
        return ['exam' => $exam, 'attempt' => $attempt, 'latest_version' => $latest, 'state' => $state];
    }
}

if (!function_exists('mmh_timed_exam_upload')) {
    function mmh_timed_exam_upload(mysqli $conn, array $exam, int $studentId, array $file): array
    {
        if ($studentId <= 0) return [false, 'You must be signed in.'];
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
            $update = $conn->prepare('UPDATE timed_exam_attempts SET latest_version_id = ?, is_late = ?, state = \'in_progress\' WHERE id = ?');
            if (!$update) throw new RuntimeException('Unable to update attempt.');
            $update->bind_param('iii', $versionId, $late, $attemptId); if (!$update->execute()) throw new RuntimeException($update->error); $update->close();
            $conn->commit();
            return [true, 'Answer uploaded. Review it and submit the exam.', ['version_id' => $versionId, 'late' => $late]];
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
            $delete = $conn->prepare("DELETE FROM timed_exam_submission_versions WHERE id = ? AND attempt_id = ? AND status = 'uploaded'");
            if (!$delete) throw new RuntimeException('Unable to remove uploaded answer.');
            $delete->bind_param('ii', $versionId, $id); if (!$delete->execute() || $delete->affected_rows !== 1) throw new RuntimeException('The uploaded answer could not be removed.'); $delete->close();
            $previous = mmh_timed_exam_latest_version($conn, $id, true);
            if ($previous) {
                $latestId = (int) $previous['id']; $late = (int) ($previous['is_late'] ?? 0);
                $update = $conn->prepare('UPDATE timed_exam_attempts SET latest_version_id = ?, is_late = ? WHERE id = ?');
                if (!$update) throw new RuntimeException('Unable to update exam attempt.');
                $update->bind_param('iii', $latestId, $late, $id);
            } else {
                $update = $conn->prepare('UPDATE timed_exam_attempts SET latest_version_id = NULL, is_late = 0 WHERE id = ?');
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
        $source = (string) ($exam['paper_source'] ?? '');
        if ($source === '') $source = !empty($exam['paper_storage_key']) ? 'private_upload' : 'external_link';
        if ($source === 'external_link') {
            $resolved = mmh_timed_exam_normalize_external_paper_url((string) ($exam['paper_external_url'] ?? ''));
            if (!$resolved) { http_response_code(404); exit('Exam paper not found.'); }
            $target = $download ? ($resolved['download_url'] ?? null) : ($resolved['preview_url'] ?? null);
            if (!$target) { http_response_code(403); exit('This exam paper cannot be downloaded.'); }
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
            header('Location: ' . $target, true, 302);
            exit;
        }
        if ($source !== 'private_upload' || empty($exam['paper_storage_key'])) {
            http_response_code(404); exit('Exam paper not found.');
        }
        $path = dirname(__DIR__) . '/' . ltrim((string) $exam['paper_storage_key'], '/');
        if (!is_file($path) || str_contains((string) $exam['paper_storage_key'], '..')) { http_response_code(404); exit('Exam paper not found.'); }
        header('Content-Type: ' . ((string) ($exam['paper_mime'] ?? '') ?: 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        $disposition = $download ? 'attachment' : 'inline';
        header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', (string) ($exam['paper_original_name'] ?? 'exam-paper')) . '"');
        readfile($path); exit;
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
        $paperSource = (string) ($existing['paper_source'] ?? '');
        if ($paperSource === '') $paperSource = !empty($existing['paper_storage_key']) ? 'private_upload' : 'external_link';
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
            if (!$resolved) throw new InvalidArgumentException('Use a secure HTTPS link from Google Drive, SharePoint, OneDrive, or a supported PDF host.');
            $paperSource = 'external_link';
            $externalUrl = $resolved['url'];
            $externalPreviewUrl = (string) ($resolved['preview_url'] ?? '');
            $externalDownloadUrl = (string) ($resolved['download_url'] ?? '');
            $paperName = null;
            $paperMime = null;
            $paperSize = null;
        } elseif ($paperSource === 'external_link') {
            $externalUrl = (string) ($existing['paper_external_url'] ?? '');
        }
        if ($paperFile && ($paperFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($paperFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($paperFile['tmp_name'] ?? ''))) throw new InvalidArgumentException('The exam paper upload failed.');
            $original = trim((string) ($paperFile['name'] ?? ''));
            $tmp = (string) ($paperFile['tmp_name'] ?? '');
            $size = (int) ($paperFile['size'] ?? 0);
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if ($original === '' || $size <= 0 || $size > 524288000 || $ext !== 'pdf') throw new InvalidArgumentException('The exam paper must be a PDF up to 500 MB.');
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            $mime = $finfo ? (string) finfo_file($finfo, $tmp) : (string) ($paperFile['type'] ?? '');
            if ($finfo) finfo_close($finfo);
            if ($mime !== 'application/pdf') throw new InvalidArgumentException('The exam paper must be a valid PDF.');
            $dir = dirname(__DIR__) . '/storage/private/timed-exams/papers/' . gmdate('Y/m');
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to prepare secure paper storage.');
            $stored = bin2hex(random_bytes(20)) . '.pdf';
            $target = $dir . '/' . $stored;
            if (!move_uploaded_file($tmp, $target)) throw new RuntimeException('The exam paper could not be saved securely.');
            $storageKey = 'storage/private/timed-exams/papers/' . gmdate('Y/m') . '/' . $stored;
            $paperName = $original; $paperMime = $mime; $paperSize = $size;
            $paperSource = 'private_upload';
        }
        if ($status === 'published' && $paperSource === 'external_link' && $externalUrl === '') {
            throw new InvalidArgumentException('A published Timed Exam needs an Exam Paper Link.');
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
        $stmt = $conn->prepare('SELECT a.*, u.username, u.full_name, v.id AS version_id, v.original_filename, v.storage_key, v.mime_type, v.file_size_bytes, v.uploaded_at_utc, v.submitted_at_utc AS version_submitted_at_utc, v.status AS version_status FROM timed_exam_attempts a INNER JOIN users u ON u.user_id = a.student_id LEFT JOIN timed_exam_submission_versions v ON v.id = a.latest_version_id WHERE a.timed_exam_id = ? ORDER BY u.full_name ASC, u.username ASC, a.attempt_number ASC');
        if (!$stmt) return [];
        $stmt->bind_param('i', $examId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
    }
}

if (!function_exists('mmh_timed_exam_course_states')) {
    function mmh_timed_exam_course_states(mysqli $conn, int $studentId, string $courseId): array
    {
        if ($studentId <= 0 || $courseId === '' || !mmh_timed_exam_table_available($conn)) return [];
        $stmt = $conn->prepare('SELECT e.*, a.id AS attempt_id, a.state AS attempt_state, a.submitted_at_utc, a.is_late, a.grade, a.feedback FROM timed_exams e LEFT JOIN timed_exam_attempts a ON a.id = (SELECT MAX(a2.id) FROM timed_exam_attempts a2 WHERE a2.timed_exam_id = e.id AND a2.student_id = ?) WHERE e.course_id = ? AND e.deleted_at IS NULL AND e.status = \'published\'');
        if (!$stmt) return [];
        $stmt->bind_param('is', $studentId, $courseId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $map = [];
        foreach ($rows as $row) {
            $state = mmh_timed_exam_state($row, $row['attempt_id'] ? ['state' => $row['attempt_state']] : null);
            $map[(string) $row['item_id']] = array_merge($row, ['state_key' => $state['key'], 'state_label' => $state['label']]);
        }
        return $map;
    }
}
