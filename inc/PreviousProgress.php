<?php
/**
 * Summarized previous learning completed before Math Mastery Hub tracking.
 *
 * These records are intentionally per-student/per-course totals. They do not
 * create lessons, attendance rows, submissions, learning events, or progress
 * rows, so existing LMS evidence remains factual and separate.
 */
require_once __DIR__ . '/learning_schema.php';

if (!function_exists('mmh_previous_progress_ensure_schema')) {
    function mmh_previous_progress_ensure_schema(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS `student_previous_progress` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` VARCHAR(40) NOT NULL,
            `student_id` INT NOT NULL,
            `homework_completed` INT UNSIGNED NOT NULL DEFAULT 0,
            `homework_total` INT UNSIGNED NOT NULL DEFAULT 0,
            `attendance_completed` INT UNSIGNED NOT NULL DEFAULT 0,
            `attendance_total` INT UNSIGNED NOT NULL DEFAULT 0,
            `quiz_average` DECIMAL(5,2) NULL,
            `source` VARCHAR(120) NULL,
            `teacher_comment` VARCHAR(1000) NULL,
            `created_by` INT NULL,
            `updated_by` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_previous_progress_student_course` (`course_id`, `student_id`),
            KEY `idx_previous_progress_course` (`course_id`),
            KEY `idx_previous_progress_student` (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('mmh_previous_progress_clamp_count')) {
    function mmh_previous_progress_clamp_count($value): int
    {
        $value = trim((string) $value);
        if ($value === '') { return 0; }
        if (!ctype_digit($value)) { throw new InvalidArgumentException('Progress counts must be whole numbers.'); }
        return min((int) $value, 1000000);
    }
}

if (!function_exists('mmh_previous_progress_quiz_average')) {
    function mmh_previous_progress_quiz_average($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') { return null; }
        if (!is_numeric($value)) { throw new InvalidArgumentException('Quiz average must be a number between 0 and 100.'); }
        $number = (float) $value;
        if ($number < 0 || $number > 100) { throw new InvalidArgumentException('Quiz average must be between 0 and 100.'); }
        return number_format($number, 2, '.', '');
    }
}

if (!function_exists('mmh_previous_progress_clean_text')) {
    function mmh_previous_progress_clean_text($value, int $max = 1000): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));
        return mb_substr((string) $value, 0, $max);
    }
}

if (!function_exists('mmh_previous_progress_validate_payload')) {
    function mmh_previous_progress_validate_payload(array $input): array
    {
        $courseId = mb_substr(trim((string) ($input['course_id'] ?? '')), 0, 40);
        $studentId = (int) ($input['student_id'] ?? 0);
        $homeworkCompleted = mmh_previous_progress_clamp_count($input['homework_completed'] ?? 0);
        $homeworkTotal = mmh_previous_progress_clamp_count($input['homework_total'] ?? 0);
        $attendanceCompleted = mmh_previous_progress_clamp_count($input['attendance_completed'] ?? 0);
        $attendanceTotal = mmh_previous_progress_clamp_count($input['attendance_total'] ?? 0);
        if ($courseId === '' || $studentId <= 0) { throw new InvalidArgumentException('Choose a course and student.'); }
        if ($homeworkCompleted > $homeworkTotal) { throw new InvalidArgumentException('Homework completed cannot be greater than homework total.'); }
        if ($attendanceCompleted > $attendanceTotal) { throw new InvalidArgumentException('Attendance completed cannot be greater than attendance total.'); }
        return [
            'course_id' => $courseId,
            'student_id' => $studentId,
            'homework_completed' => $homeworkCompleted,
            'homework_total' => $homeworkTotal,
            'attendance_completed' => $attendanceCompleted,
            'attendance_total' => $attendanceTotal,
            'quiz_average' => mmh_previous_progress_quiz_average($input['quiz_average'] ?? ''),
            'source' => mmh_previous_progress_clean_text($input['source'] ?? '', 120),
            'teacher_comment' => mmh_previous_progress_clean_text($input['teacher_comment'] ?? '', 1000),
        ];
    }
}

if (!function_exists('mmh_previous_progress_student_enrolled')) {
    function mmh_previous_progress_student_enrolled(mysqli $conn, string $courseId, int $studentId): bool
    {
        $stmt = $conn->prepare('SELECT id FROM course_logs WHERE course_id = ? AND user_id = ? LIMIT 1');
        if (!$stmt) { return false; }
        $stmt->bind_param('si', $courseId, $studentId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mmh_previous_progress_save')) {
    function mmh_previous_progress_save(mysqli $conn, array $payload, ?int $adminId): void
    {
        mmh_previous_progress_ensure_schema($conn);
        if (!mmh_previous_progress_student_enrolled($conn, $payload['course_id'], (int) $payload['student_id'])) {
            throw new InvalidArgumentException('The selected student is not enrolled in this course.');
        }
        $stmt = $conn->prepare("INSERT INTO student_previous_progress
            (course_id, student_id, homework_completed, homework_total, attendance_completed, attendance_total, quiz_average, source, teacher_comment, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE homework_completed = VALUES(homework_completed), homework_total = VALUES(homework_total),
                attendance_completed = VALUES(attendance_completed), attendance_total = VALUES(attendance_total),
                quiz_average = VALUES(quiz_average), source = VALUES(source), teacher_comment = VALUES(teacher_comment),
                updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP");
        if (!$stmt) { throw new RuntimeException('Unable to prepare previous progress save.'); }
        $quizAverage = $payload['quiz_average'];
        $source = $payload['source'] !== '' ? $payload['source'] : null;
        $comment = $payload['teacher_comment'] !== '' ? $payload['teacher_comment'] : null;
        $stmt->bind_param(
            'siiiiisssii',
            $payload['course_id'],
            $payload['student_id'],
            $payload['homework_completed'],
            $payload['homework_total'],
            $payload['attendance_completed'],
            $payload['attendance_total'],
            $quizAverage,
            $source,
            $comment,
            $adminId,
            $adminId
        );
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException('Unable to save previous progress: ' . $error); }
        $stmt->close();
    }
}

if (!function_exists('mmh_previous_progress_delete')) {
    function mmh_previous_progress_delete(mysqli $conn, int $id): void
    {
        mmh_previous_progress_ensure_schema($conn);
        $stmt = $conn->prepare('DELETE FROM student_previous_progress WHERE id = ? LIMIT 1');
        if (!$stmt) { throw new RuntimeException('Unable to prepare previous progress delete.'); }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException('Unable to delete previous progress: ' . $error); }
        $stmt->close();
    }
}

if (!function_exists('mmh_previous_progress_load')) {
    function mmh_previous_progress_load(mysqli $conn, string $courseId, int $studentId): array
    {
        mmh_previous_progress_ensure_schema($conn);
        $stmt = $conn->prepare('SELECT * FROM student_previous_progress WHERE course_id = ? AND student_id = ? LIMIT 1');
        if (!$stmt) { return mmh_previous_progress_empty(); }
        $stmt->bind_param('si', $courseId, $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return $row ? mmh_previous_progress_normalize_row($row) : mmh_previous_progress_empty();
    }
}

if (!function_exists('mmh_previous_progress_empty')) {
    function mmh_previous_progress_empty(): array
    {
        return [
            'id' => null, 'course_id' => '', 'student_id' => 0,
            'homework_completed' => 0, 'homework_total' => 0,
            'attendance_completed' => 0, 'attendance_total' => 0,
            'quiz_average' => null, 'source' => '', 'teacher_comment' => '',
            'has_record' => false,
        ];
    }
}

if (!function_exists('mmh_previous_progress_normalize_row')) {
    function mmh_previous_progress_normalize_row(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'course_id' => (string) ($row['course_id'] ?? ''),
            'student_id' => (int) ($row['student_id'] ?? 0),
            'homework_completed' => (int) ($row['homework_completed'] ?? 0),
            'homework_total' => (int) ($row['homework_total'] ?? 0),
            'attendance_completed' => (int) ($row['attendance_completed'] ?? 0),
            'attendance_total' => (int) ($row['attendance_total'] ?? 0),
            'quiz_average' => $row['quiz_average'] === null || $row['quiz_average'] === '' ? null : (float) $row['quiz_average'],
            'source' => (string) ($row['source'] ?? ''),
            'teacher_comment' => (string) ($row['teacher_comment'] ?? ''),
            'has_record' => true,
        ];
    }
}

if (!function_exists('mmh_previous_progress_lms_counts')) {
    function mmh_previous_progress_lms_counts(array $summary): array
    {
        $counts = $summary['counts'] ?? [];
        return [
            'homework_completed' => (int) ($counts['homework_submitted'] ?? 0),
            'homework_total' => (int) ($counts['homework_total'] ?? 0),
            'attendance_completed' => (int) ($counts['live_attended'] ?? 0),
            'attendance_total' => (int) ($counts['live_total'] ?? 0),
            'quiz_average' => $counts['average_grade'] ?? null,
        ];
    }
}

if (!function_exists('mmh_previous_progress_percent')) {
    function mmh_previous_progress_percent(int $completed, int $total): ?float
    {
        return $total > 0 ? round(($completed / $total) * 100, 1) : null;
    }
}

if (!function_exists('mmh_previous_progress_combine')) {
    function mmh_previous_progress_combine(array $previous, array $lms): array
    {
        $previousTotal = (int) $previous['homework_total'] + (int) $previous['attendance_total'];
        $previousCompleted = (int) $previous['homework_completed'] + (int) $previous['attendance_completed'];
        $lmsTotal = (int) $lms['homework_total'] + (int) $lms['attendance_total'];
        $lmsCompleted = (int) $lms['homework_completed'] + (int) $lms['attendance_completed'];
        $overallTotal = $previousTotal + $lmsTotal;
        $overallCompleted = $previousCompleted + $lmsCompleted;
        return [
            'previous' => array_merge($previous, [
                'completed' => $previousCompleted,
                'total' => $previousTotal,
                'percent' => mmh_previous_progress_percent($previousCompleted, $previousTotal),
            ]),
            'lms' => array_merge($lms, [
                'completed' => $lmsCompleted,
                'total' => $lmsTotal,
                'percent' => mmh_previous_progress_percent($lmsCompleted, $lmsTotal),
            ]),
            'overall' => [
                'homework_completed' => (int) $previous['homework_completed'] + (int) $lms['homework_completed'],
                'homework_total' => (int) $previous['homework_total'] + (int) $lms['homework_total'],
                'attendance_completed' => (int) $previous['attendance_completed'] + (int) $lms['attendance_completed'],
                'attendance_total' => (int) $previous['attendance_total'] + (int) $lms['attendance_total'],
                'completed' => $overallCompleted,
                'total' => $overallTotal,
                'percent' => mmh_previous_progress_percent($overallCompleted, $overallTotal),
            ],
        ];
    }
}

if (!function_exists('mmh_previous_progress_rows')) {
    function mmh_previous_progress_rows(mysqli $conn, string $courseId = ''): array
    {
        mmh_previous_progress_ensure_schema($conn);
        $sql = "SELECT p.*, c.course_title, u.full_name, u.username
                FROM student_previous_progress p
                INNER JOIN courses c ON c.course_id = p.course_id
                INNER JOIN users u ON u.user_id = p.student_id";
        if ($courseId !== '') { $sql .= ' WHERE p.course_id = ?'; }
        $sql .= ' ORDER BY c.course_title ASC, u.full_name ASC, u.username ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) { return []; }
        if ($courseId !== '') { $stmt->bind_param('s', $courseId); }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_previous_progress_parse_csv')) {
    function mmh_previous_progress_parse_csv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) { throw new RuntimeException('Unable to read the uploaded CSV file.'); }
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if (!$headers) { fclose($handle); throw new InvalidArgumentException('The import file is empty.'); }
        $headers = array_map(static fn($h) => strtolower(trim((string) $h)), $headers);
        $rows = [];
        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count(array_filter($values, static fn($v) => trim((string) $v) !== '')) === 0) { continue; }
            $row = [];
            foreach ($headers as $index => $header) { $row[$header] = $values[$index] ?? ''; }
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }
}

if (!function_exists('mmh_previous_progress_parse_xlsx')) {
    function mmh_previous_progress_parse_xlsx(string $path): array
    {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('Excel import requires the PHP ZipArchive extension. Save the sheet as CSV and import again.'); }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) { throw new RuntimeException('Unable to read the uploaded Excel file.'); }
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $parts = [];
                    if (isset($si->t)) { $parts[] = (string) $si->t; }
                    foreach ($si->r ?? [] as $run) { $parts[] = (string) ($run->t ?? ''); }
                    $sharedStrings[] = implode('', $parts);
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) { throw new RuntimeException('The Excel file does not contain a first worksheet.'); }
        $xml = simplexml_load_string($sheetXml);
        if (!$xml) { throw new RuntimeException('Unable to parse the Excel worksheet.'); }
        $table = [];
        foreach ($xml->sheetData->row as $rowNode) {
            $row = [];
            foreach ($rowNode->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                preg_match('/^([A-Z]+)/', $ref, $match);
                $index = 0;
                foreach (str_split($match[1] ?? 'A') as $char) { $index = $index * 26 + (ord($char) - 64); }
                $index--;
                $type = (string) ($cell['t'] ?? '');
                $value = (string) ($cell->v ?? '');
                if ($type === 's') { $value = $sharedStrings[(int) $value] ?? ''; }
                elseif ($type === 'inlineStr') { $value = (string) ($cell->is->t ?? ''); }
                $row[$index] = $value;
            }
            if ($row) {
                ksort($row);
                $table[] = $row;
            }
        }
        if (!$table) { throw new InvalidArgumentException('The import file is empty.'); }
        $headers = array_map(static fn($h) => strtolower(trim((string) $h)), array_values($table[0]));
        $rows = [];
        foreach (array_slice($table, 1) as $values) {
            $values = array_values($values);
            if (count(array_filter($values, static fn($v) => trim((string) $v) !== '')) === 0) { continue; }
            $row = [];
            foreach ($headers as $index => $header) { $row[$header] = $values[$index] ?? ''; }
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('mmh_previous_progress_import')) {
    function mmh_previous_progress_import(mysqli $conn, string $path, string $filename, ?int $adminId): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $rows = $extension === 'xlsx' ? mmh_previous_progress_parse_xlsx($path) : mmh_previous_progress_parse_csv($path);
        $imported = 0; $skipped = [];
        foreach ($rows as $number => $row) {
            $payload = [
                'course_id' => $row['course_id'] ?? '',
                'student_id' => $row['student_id'] ?? '',
                'homework_completed' => $row['homework_completed'] ?? $row['homework completed'] ?? 0,
                'homework_total' => $row['homework_total'] ?? $row['homework total'] ?? 0,
                'attendance_completed' => $row['attendance_completed'] ?? $row['attendance completed'] ?? 0,
                'attendance_total' => $row['attendance_total'] ?? $row['attendance total'] ?? 0,
                'quiz_average' => $row['quiz_average'] ?? $row['quiz average'] ?? '',
                'source' => $row['source'] ?? 'Teacher Record',
                'teacher_comment' => $row['teacher_comment'] ?? $row['teacher comment'] ?? '',
            ];
            try {
                $clean = mmh_previous_progress_validate_payload($payload);
                mmh_previous_progress_save($conn, $clean, $adminId);
                $imported++;
            } catch (Throwable $exception) {
                $skipped[] = 'Row ' . ($number + 2) . ': ' . $exception->getMessage();
            }
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
