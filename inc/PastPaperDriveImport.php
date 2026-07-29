<?php
/**
 * Server-side Google Drive import support for the existing Past Papers module.
 *
 * This file deliberately keeps Google credentials in server environment
 * variables. Scans create only admin-only source/job/candidate records; paper
 * and resource records are created only after an explicit confirmed import.
 */

require_once __DIR__ . '/PastPapers.php';
require_once __DIR__ . '/GoogleDriveCredentialProvider.php';

if (!function_exists('mmh_past_drive_csrf_token')) {
    function mmh_past_drive_csrf_token()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['past_paper_drive_csrf'])) {
            $_SESSION['past_paper_drive_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['past_paper_drive_csrf'];
    }
}

if (!function_exists('mmh_past_drive_csrf_valid')) {
    function mmh_past_drive_csrf_valid($token)
    {
        $stored = (string) ($_SESSION['past_paper_drive_csrf'] ?? '');
        return is_string($token) && $stored !== '' && hash_equals($stored, $token);
    }
}

if (!function_exists('mmh_past_drive_admin_guard')) {
    function mmh_past_drive_admin_guard()
    {
        return isset($_SESSION['admin']) && trim((string) $_SESSION['admin']) !== '';
    }
}

if (!function_exists('mmh_past_drive_env')) {
    function mmh_past_drive_env($key)
    {
        $value = getenv($key);
        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('mmh_past_drive_connection')) {
    function mmh_past_drive_connection()
    {
        $status = mmh_google_drive_credential_status();
        return [
            'available' => (bool) $status['available'],
            'mode' => (string) $status['mode'],
            'label' => (string) $status['label'],
            'message' => (string) $status['message'],
        ];
    }
}

if (!function_exists('mmh_past_drive_ensure_schema')) {
    function mmh_past_drive_ensure_schema(mysqli $conn)
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        mmh_past_ensure_schema($conn);

        if (!mmh_table_exists($conn, 'past_paper_drive_sources')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `past_paper_drive_sources` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `source_id` VARCHAR(40) NOT NULL,
                `folder_id` VARCHAR(128) NOT NULL,
                `display_name` VARCHAR(190) NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `last_scan_at` DATETIME NULL,
                `last_sync_at` DATETIME NULL,
                `last_error` TEXT NULL,
                `created_by` VARCHAR(190) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_drive_source_id` (`source_id`),
                UNIQUE KEY `uniq_drive_folder_id` (`folder_id`),
                KEY `idx_drive_source_status` (`status`, `updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'past_paper_drive_jobs')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `past_paper_drive_jobs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `job_id` VARCHAR(40) NOT NULL,
                `source_id` VARCHAR(40) NOT NULL,
                `job_type` VARCHAR(20) NOT NULL DEFAULT 'scan',
                `status` VARCHAR(20) NOT NULL DEFAULT 'running',
                `summary_json` MEDIUMTEXT NULL,
                `error_message` TEXT NULL,
                `created_by` VARCHAR(190) NULL,
                `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `completed_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_drive_job_id` (`job_id`),
                KEY `idx_drive_job_source` (`source_id`, `started_at`),
                KEY `idx_drive_job_status` (`status`, `started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'past_paper_drive_candidates')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `past_paper_drive_candidates` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `candidate_id` VARCHAR(40) NOT NULL,
                `job_id` VARCHAR(40) NOT NULL,
                `source_id` VARCHAR(40) NOT NULL,
                `drive_file_id` VARCHAR(128) NOT NULL,
                `source_path` TEXT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `mime_type` VARCHAR(190) NULL,
                `file_size` BIGINT NULL,
                `modified_at` DATETIME NULL,
                `source_fingerprint` VARCHAR(160) NOT NULL,
                `metadata_json` MEDIUMTEXT NOT NULL,
                `proposed_action` VARCHAR(32) NOT NULL,
                `confidence` VARCHAR(20) NOT NULL DEFAULT 'manual',
                `warning_message` TEXT NULL,
                `correction_json` MEDIUMTEXT NULL,
                `result_status` VARCHAR(32) NULL,
                `linked_paper_id` VARCHAR(40) NULL,
                `linked_resource_id` VARCHAR(40) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_drive_candidate_id` (`candidate_id`),
                UNIQUE KEY `uniq_drive_job_file` (`job_id`, `drive_file_id`),
                KEY `idx_drive_candidate_job` (`job_id`, `proposed_action`),
                KEY `idx_drive_candidate_source_file` (`source_id`, `drive_file_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }
        // Added independently so existing production candidate tables remain compatible.
        $candidateColumns = [
            'source_folder_id' => 'VARCHAR(128) NULL',
            'parent_folder_id' => 'VARCHAR(128) NULL',
            'parent_folder_name' => 'VARCHAR(190) NULL',
            'relative_folder_path' => 'TEXT NULL',
            'folder_path_json' => 'MEDIUMTEXT NULL',
            'folder_depth' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            // Bounded batches must retain actionable outcomes after the
            // request ends, so an administrator can correct and retry only
            // the candidates that need attention.
            'result_message' => 'TEXT NULL',
            'failure_code' => 'VARCHAR(64) NULL',
            'failure_context_json' => 'MEDIUMTEXT NULL',
            'failed_at' => 'DATETIME NULL',
        ];
        foreach ($candidateColumns as $column => $definition) {
            $safeColumn = str_replace('`', '', $column);
            $check = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
            $table = 'past_paper_drive_candidates';
            $check->bind_param('ss', $table, $safeColumn);
            $check->execute();
            $exists = (bool) $check->get_result()->fetch_row();
            $check->close();
            if (!$exists) {
                $conn->query('ALTER TABLE `past_paper_drive_candidates` ADD COLUMN `' . $safeColumn . '` ' . $definition);
            }
        }
        // Bounded import batches select only unfinished automatic candidates.
        // These composite indexes prevent a full candidate-table scan as jobs grow.
        mmh_add_index_if_missing($conn, 'past_paper_drive_candidates', 'idx_drive_candidate_batch', '`job_id`, `result_status`, `proposed_action`, `id`');
        mmh_add_index_if_missing($conn, 'past_paper_drive_candidates', 'idx_drive_candidate_cursor', '`job_id`, `id`');
        mmh_add_index_if_missing($conn, 'past_paper_drive_candidates', 'idx_drive_candidate_failures', '`job_id`, `result_status`, `failure_code`, `id`');
        mmh_add_index_if_missing($conn, 'past_papers', 'idx_drive_paper_identity', '`exam_board_id`, `syllabus_id`, `year`, `exam_session`, `paper_number`(32), `variant`(32)');
    }
}

if (!function_exists('mmh_past_drive_parse_folder_id')) {
    function mmh_past_drive_parse_folder_id($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/\A[A-Za-z0-9_-]{10,128}\z/', $value)) {
            return $value;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'drive.google.com' && !str_ends_with($host, '.drive.google.com')) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if (preg_match('~/(?:drive/)?folders/([A-Za-z0-9_-]{10,128})~', $path, $match)) {
            return $match[1];
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $id = (string) ($query['id'] ?? '');
        return preg_match('/\A[A-Za-z0-9_-]{10,128}\z/', $id) ? $id : null;
    }
}

if (!function_exists('mmh_past_drive_trace_context')) {
    /**
     * Scan-only diagnostic context. Values are intentionally non-sensitive:
     * Drive IDs and page tokens are logged only as short hashes.
     */
    function mmh_past_drive_trace_context(array $replace = [])
    {
        static $context = [
            'enabled' => false,
            'started_at' => 0.0,
            'request_count' => 0,
            'job_id' => '',
        ];
        if ($replace) {
            $context = array_merge($context, $replace);
        }
        return $context;
    }
}

if (!function_exists('mmh_past_drive_trace')) {
    function mmh_past_drive_trace($event, array $details = [])
    {
        $context = mmh_past_drive_trace_context();
        if (empty($context['enabled'])) {
            return;
        }
        $payload = array_merge([
            'event' => (string) $event,
            'job' => $context['job_id'] !== '' ? substr(hash('sha256', $context['job_id']), 0, 12) : 'preflight',
            'request_count' => (int) $context['request_count'],
            'elapsed_ms' => (int) round((microtime(true) - (float) $context['started_at']) * 1000),
        ], $details);
        error_log('[PastPaperDrive] ' . json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('mmh_past_drive_trace_id')) {
    function mmh_past_drive_trace_id($value)
    {
        return substr(hash('sha256', (string) $value), 0, 12);
    }
}

if (!function_exists('mmh_past_drive_api')) {
    function mmh_past_drive_api($path, array $query = [])
    {
        $connection = mmh_past_drive_connection();
        if (!$connection['available']) {
            return [false, null, $connection['message'], 0];
        }
        if (!function_exists('curl_init')) {
            return [false, null, 'The server does not have cURL enabled for Google Drive API requests.', 0];
        }
        $credential = mmh_google_drive_access_credential();
        $token = '';
        if (in_array($connection['mode'], ['explicit_access_token', 'service_account'], true)) {
            if (empty($credential['available']) || empty($credential['access_token'])) {
                return [false, null, (string) ($credential['message'] ?? 'Google Drive authentication failed.'), 0];
            }
            $token = (string) $credential['access_token'];
        } elseif ($connection['mode'] === 'api_key') {
            $query['key'] = mmh_past_drive_env('MMH_GOOGLE_DRIVE_API_KEY');
        } else {
            return [false, null, $connection['message'], 0];
        }
        $query['supportsAllDrives'] = 'true';
        $context = mmh_past_drive_trace_context();
        $context = mmh_past_drive_trace_context(['request_count' => (int) $context['request_count'] + 1]);
        $pathLabel = str_starts_with((string) $path, 'files/') ? 'file_metadata' : 'files_list';
        mmh_past_drive_trace('api_request', [
            'request_type' => $pathLabel,
            'page_token' => empty($query['pageToken']) ? 'initial' : mmh_past_drive_trace_id($query['pageToken']),
            'page_size' => (int) ($query['pageSize'] ?? 0),
        ]);
        $url = 'https://www.googleapis.com/drive/v3/' . ltrim($path, '/') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($url);
        $requestStartedAt = microtime(true);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => array_filter([
                'Accept: application/json',
                $token !== '' ? 'Authorization: Bearer ' . $token : null,
            ]),
        ]);
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        mmh_past_drive_trace('api_response', [
            'http_status' => $status,
            'curl_errno' => (int) $errno,
            'duration_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
            'body_bytes' => is_string($body) ? strlen($body) : 0,
        ]);
        // PHP 8+ releases the cURL handle automatically; curl_close() is a
        // no-op and deprecated on the local PHP 8.5 runtime.
        if ($errno !== 0 || $body === false) {
            return [false, null, 'Google Drive could not be reached. Please retry the scan.', $status];
        }
        $data = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            $friendly = match ($status) {
                400 => 'Google Drive rejected the folder request.',
                401, 403 => 'Google Drive denied access to this request.',
                404 => 'Google Drive folder or file was not found.',
                429 => 'Google Drive quota was reached. Please retry later.',
                default => 'Google Drive returned an unexpected response. Please retry later.',
            };
            // Google API errors are safe to show to an authenticated admin once
            // reduced to status/message; request URLs and keys are never shown.
            $googleStatus = mmh_past_clean($data['error']['status'] ?? '', 60);
            $googleMessage = mmh_past_clean($data['error']['message'] ?? '', 320);
            if ($googleMessage !== '') {
                $friendly .= ' ' . ($googleStatus !== '' ? '[' . $googleStatus . '] ' : '') . $googleMessage;
            }
            return [false, null, $friendly, $status];
        }
        return [true, $data, '', $status];
    }
}

if (!function_exists('mmh_past_drive_datetime')) {
    function mmh_past_drive_datetime($value)
    {
        $timestamp = strtotime((string) $value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}

if (!function_exists('mmh_past_drive_fingerprint')) {
    function mmh_past_drive_fingerprint(array $file)
    {
        return hash('sha256', implode('|', [
            (string) ($file['id'] ?? ''),
            (string) ($file['modifiedTime'] ?? ''),
            (string) ($file['size'] ?? ''),
            (string) ($file['md5Checksum'] ?? ''),
        ]));
    }
}

if (!function_exists('mmh_past_drive_supported_file')) {
    function mmh_past_drive_supported_file(array $file)
    {
        $mime = strtolower((string) ($file['mimeType'] ?? ''));
        if ($mime === 'application/pdf') {
            return [true, 'pdf'];
        }
        if (in_array($mime, [
            'application/vnd.google-apps.document',
            'application/vnd.google-apps.presentation',
            'application/vnd.google-apps.spreadsheet',
        ], true)) {
            return [true, 'google_workspace'];
        }
        return [false, 'unsupported'];
    }
}

if (!function_exists('mmh_past_drive_session_from_text')) {
    function mmh_past_drive_session_from_text($text)
    {
        $text = strtolower((string) $text);
        if (preg_match('/(?:feb(?:ruary)?\s*[-\/]?\s*mar(?:ch)?|\bfm\b|\bfeb\/?mar\b)/', $text)) {
            return 'February/March';
        }
        if (preg_match('/(?:may\s*[-\/]?\s*jun(?:e)?|\bmj\b|\bmay\/?jun\b)/', $text)) {
            return 'May/June';
        }
        if (preg_match('/(?:oct(?:ober)?\s*[-\/]?\s*nov(?:ember)?|\bon\b|\boct\/?nov\b)/', $text)) {
            return 'October/November';
        }
        if (preg_match('/\bjan(?:uary)?\b/', $text)) {
            return 'January';
        }
        return '';
    }
}

if (!function_exists('mmh_past_drive_type_from_code')) {
    function mmh_past_drive_type_from_code($code)
    {
        $code = strtolower(trim((string) $code));
        return match ($code) {
            'qp', 'que', 'question', 'questionpaper' => ['question_paper', ''],
            'ms', 'rms', 'msc', 'markscheme', 'mark_scheme' => ['mark_scheme', ''],
            'ma', 'modelanswer', 'model_answer', 'workedsolution', 'sampleanswer', 'teachersolution' => ['model_answer', ''],
            'vs', 'video_solution', 'solution_video', 'videowalkthrough', 'walkthrough' => ['solution_video', ''],
            'er', 'examinerreport', 'examiner_report' => ['examiner_report', ''],
            'in', 'insert' => ['insert', ''],
            'fs', 'formula', 'formula_sheet' => ['formula_sheet', ''],
            'db', 'data', 'data_booklet' => ['data_booklet', ''],
            'gt', 'grade_threshold', 'grade_boundaries' => ['grade_boundaries', ''],
            'sb', 'source_booklet' => ['source_booklet', ''],
            'pr', 'pre_release' => ['pre_release_material', ''],
            'ci' => ['custom', 'Confidential Instructions'],
            'sf' => ['custom', 'Speaking Form'],
            default => ['', ''],
        };
    }
}

if (!function_exists('mmh_past_drive_find_board')) {
    function mmh_past_drive_find_board(array $boards, $text)
    {
        $text = strtolower((string) $text);
        $aliases = [
            'cambridge' => ['cambridge', 'cie', 'caie'],
            'edexcel' => ['edexcel', 'pearson'],
            'oxfordaqa' => ['oxfordaqa', 'oxford aqa'],
        ];
        foreach ($boards as $board) {
            $name = strtolower((string) ($board['name'] ?? ''));
            $code = strtolower((string) ($board['code'] ?? ''));
            $needles = array_filter([$name, $code]);
            foreach ($aliases as $key => $values) {
                if (str_contains($name, $key) || str_contains($code, $key)) {
                    $needles = array_merge($needles, $values);
                }
            }
            foreach (array_unique($needles) as $needle) {
                if ($needle !== '' && str_contains($text, $needle)) {
                    return $board;
                }
            }
        }
        return null;
    }
}

if (!function_exists('mmh_past_drive_find_syllabus')) {
    function mmh_past_drive_find_syllabus(array $syllabuses, $code, $boardId = '')
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }
        $matches = [];
        foreach ($syllabuses as $syllabus) {
            if (strtoupper((string) ($syllabus['syllabus_code'] ?? '')) !== $code) {
                continue;
            }
            if ($boardId !== '' && (string) ($syllabus['exam_board_id'] ?? '') !== $boardId) {
                continue;
            }
            $matches[] = $syllabus;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }
}

if (!function_exists('mmh_past_drive_parse_metadata_legacy')) {
    function mmh_past_drive_parse_metadata_legacy(array $file, $sourcePath, array $boards, array $syllabuses)
    {
        $name = (string) ($file['name'] ?? '');
        $stem = preg_replace('/\.[A-Za-z0-9]{1,8}\z/', '', $name);
        $text = strtolower($sourcePath . ' ' . $stem);
        $metadata = [
            'provider' => 'google_drive',
            'exam_board_id' => '',
            'syllabus_id' => '',
            'subject_code' => '',
            'year' => 0,
            'exam_session' => '',
            'paper_number' => '',
            'variant' => '',
            'tier' => '',
            'resource_type' => '',
            'custom_type' => '',
            'parser' => 'generic',
            'warnings' => [],
        ];
        $board = mmh_past_drive_find_board($boards, $text);
        if ($board) {
            $metadata['exam_board_id'] = (string) $board['board_id'];
        }
        if (preg_match('/(?<!\d)(20\d{2})(?!\d)/', $text, $match)) {
            $metadata['year'] = (int) $match[1];
        }
        $metadata['exam_session'] = mmh_past_drive_session_from_text($text);

        // Cambridge convention: 0580_s23_qp_21.pdf
        if (preg_match('/(?:^|[_\s-])(\d{4,5})[_\s-]([msw])(\d{2})[_\s-](qp|ms|er|gt|in|ci|sf)(?:[_\s-])?(\d{1,3})?(?:[_\s-]|$)/i', $stem, $match)) {
            $metadata['parser'] = 'cambridge';
            $metadata['subject_code'] = $match[1];
            $metadata['year'] = 2000 + (int) $match[3];
            $metadata['exam_session'] = ['m' => 'February/March', 's' => 'May/June', 'w' => 'October/November'][strtolower($match[2])] ?? '';
            [$metadata['resource_type'], $metadata['custom_type']] = mmh_past_drive_type_from_code($match[4]);
            $component = (string) ($match[5] ?? '');
            if ($component !== '') {
                $metadata['paper_number'] = 'Paper ' . substr($component, 0, 1);
                $metadata['variant'] = strlen($component) > 1 ? substr($component, 1) : $component;
            }
            if ($metadata['exam_board_id'] === '') {
                foreach ($boards as $candidateBoard) {
                    if (preg_match('/cambridge|cie|caie/i', (string) ($candidateBoard['name'] ?? '') . ' ' . (string) ($candidateBoard['code'] ?? ''))) {
                        $metadata['exam_board_id'] = (string) $candidateBoard['board_id'];
                        break;
                    }
                }
            }
        } elseif (preg_match('/(?:^|[_\s-])([a-z]{0,6}\d[a-z0-9]{0,10})[_\s-]([0-9][a-z]{1,3}|wma\d{2}\/?\d{2})[_\s-](que|qp|ms|rms|markscheme|mark_scheme)(?:[_\s-]|$)/i', $stem, $match)) {
            // Edexcel filenames vary widely, so only clear component patterns
            // are marked safe. Other Edexcel files remain reviewable.
            $metadata['parser'] = 'edexcel';
            $metadata['subject_code'] = strtoupper($match[1]);
            if (preg_match('/(?<!\d)(20\d{2})(?!\d)/', $stem, $yearMatch)) {
                $metadata['year'] = (int) $yearMatch[1];
            }
            $component = strtoupper($match[2]);
            $metadata['paper_number'] = $component;
            $metadata['variant'] = $component;
            [$metadata['resource_type'], $metadata['custom_type']] = mmh_past_drive_type_from_code($match[3]);
            if ($metadata['exam_board_id'] === '') {
                foreach ($boards as $candidateBoard) {
                    if (preg_match('/edexcel|pearson/i', (string) ($candidateBoard['name'] ?? '') . ' ' . (string) ($candidateBoard['code'] ?? ''))) {
                        $metadata['exam_board_id'] = (string) $candidateBoard['board_id'];
                        break;
                    }
                }
            }
        } else {
            if (preg_match('/(?:^|[_\s-])(qp|ms|er|gt|in|ci|sf|que|rms|markscheme|mark_scheme)(?:[_\s-]|$)/i', $stem, $match)) {
                [$metadata['resource_type'], $metadata['custom_type']] = mmh_past_drive_type_from_code($match[1]);
            }
            if (preg_match('/(?:^|[_\s-])paper[_\s-]?(\d+)([a-z]{0,2})(?:[_\s-]|$)/i', $stem, $match)) {
                $component = strtoupper($match[1] . $match[2]);
                $metadata['component_code'] = $component;
                $metadata['paper_number'] = 'Paper ' . $component;
                $metadata['variant'] = $component;
            } elseif (preg_match('/(?:paper|p)[_\s-]?(\d+[a-z]?)/i', $stem, $match)) {
                $metadata['paper_number'] = 'Paper ' . strtoupper($match[1]);
            }
            if (preg_match('/(?:variant|var|v)[_\s-]?(\d+[a-z]{0,2})/i', $stem, $match)) {
                $metadata['variant'] = strtoupper($match[1]);
            }
        }

        $syllabus = mmh_past_drive_find_syllabus($syllabuses, $metadata['subject_code'], $metadata['exam_board_id']);
        if (!$syllabus && $metadata['subject_code'] !== '' && $metadata['exam_board_id'] === '') {
            $syllabus = mmh_past_drive_find_syllabus($syllabuses, $metadata['subject_code']);
            if ($syllabus) {
                $metadata['exam_board_id'] = (string) $syllabus['exam_board_id'];
            }
        }
        if ($syllabus) {
            $metadata['syllabus_id'] = (string) $syllabus['syllabus_id'];
        }
        foreach (['exam_board_id' => 'Exam Board', 'syllabus_id' => 'Syllabus', 'year' => 'Year', 'exam_session' => 'Session', 'paper_number' => 'Paper', 'variant' => 'Variant', 'resource_type' => 'Document type'] as $field => $label) {
            if (empty($metadata[$field])) {
                $metadata['warnings'][] = $label . ' could not be detected automatically.';
            }
        }
        return $metadata;
    }
}

if (!function_exists('mmh_past_drive_folder_names')) {
    function mmh_past_drive_folder_names(array $folderPath)
    {
        $names = [];
        foreach ($folderPath as $folder) {
            $name = is_array($folder) ? ($folder['name'] ?? '') : $folder;
            $name = mmh_past_clean($name, 190);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }
}

if (!function_exists('mmh_past_drive_folder_context')) {
    function mmh_past_drive_folder_context(array $folderPath, $sourceFolderId = '')
    {
        $normalized = [];
        foreach ($folderPath as $folder) {
            $id = is_array($folder) ? mmh_past_clean($folder['id'] ?? '', 128) : '';
            $name = is_array($folder) ? mmh_past_clean($folder['name'] ?? '', 190) : mmh_past_clean($folder, 190);
            if ($name !== '') {
                $normalized[] = ['id' => $id, 'name' => $name];
            }
        }
        $names = mmh_past_drive_folder_names($normalized);
        $parent = $normalized ? $normalized[count($normalized) - 1] : ['id' => '', 'name' => ''];
        return [
            'source_folder_id' => mmh_past_clean($sourceFolderId, 128),
            'parent_folder_id' => $parent['id'],
            'parent_folder_name' => $parent['name'],
            'relative_folder_path' => implode(' / ', array_slice($names, 1)),
            'folder_path_json' => json_encode($normalized, JSON_UNESCAPED_SLASHES),
            'folder_depth' => max(0, count($normalized) - 1),
            'source_path' => implode(' / ', $names),
            'folder_path' => $normalized,
        ];
    }
}

if (!function_exists('mmh_past_drive_session_alias')) {
    function mmh_past_drive_session_alias($text)
    {
        $text = strtolower(trim((string) $text));
        if (preg_match('/(?:feb(?:ruary)?\s*[-\/]?\s*mar(?:ch)?|\bfm\b|\bfeb\/?mar\b)/', $text)) return 'February/March';
        if (preg_match('/(?:may\s*[-\/]?\s*jun(?:e)?|\bmj\b|\bmay\/?jun\b|\bsummer\b|\bjune\b)/', $text)) return 'May/June';
        if (preg_match('/(?:oct(?:ober)?\s*[-\/]?\s*nov(?:ember)?|\bon\b|\boct\/?nov\b|\bwinter\b)/', $text)) return 'October/November';
        if (preg_match('/\bjan(?:uary)?\b/', $text)) return 'January';
        return '';
    }
}

if (!function_exists('mmh_past_drive_document_type_alias')) {
    function mmh_past_drive_document_type_alias($text)
    {
        $text = strtolower((string) $text);
        $patterns = [
            'model_answer' => '/(?:\bmodel[\s_-]*answers?\b|\bworked[\s_-]*solutions?\b|\bsample[\s_-]*answers?\b|\bteacher[\s_-]*solutions?\b)/',
            'solution_video' => '/(?:\bvideo[\s_-]*solutions?\b|\bsolution[\s_-]*videos?\b|\bvideo[\s_-]*walkthroughs?\b|\bwalkthroughs?\b)/',
            'mark_scheme' => '/(?:\bmark[\s_-]*schemes?\b|\bms\b|\brms\b|\bmsc\b)/',
            'question_paper' => '/(?:\bquestion[\s_-]*papers?\b|\bquestion\b|\bqp\b|\bque\b|\bpapers\b)/',
            'examiner_report' => '/(?:\bexaminer[\s_-]*reports?\b|\ber\b)/',
            'grade_boundaries' => '/(?:\bgrade[\s_-]*(?:thresholds?|boundaries)\b|\bgt\b)/',
            'formula_sheet' => '/(?:\bformula[\s_-]*sheet\b|\bfs\b)/',
            'data_booklet' => '/(?:\bdata[\s_-]*booklet\b|\bdb\b)/',
            'insert' => '/(?:\binserts?\b|\bin\b)/',
        ];
        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $text)) return $type;
        }
        return '';
    }
}

if (!function_exists('mmh_past_drive_board_from_text')) {
    function mmh_past_drive_board_from_text($text, array $boards)
    {
        $text = strtolower((string) $text);
        $aliases = [
            'Cambridge' => ['cambridge', 'caie', 'cie'],
            'Edexcel' => ['edexcel', 'pearson edexcel', 'pearson'],
            'OxfordAQA' => ['oxford aqa', 'oxfordaqa', 'aqa'],
        ];
        foreach ($aliases as $label => $values) {
            foreach ($values as $value) {
                if (str_contains($text, $value)) {
                    foreach ($boards as $board) {
                        $haystack = strtolower(($board['name'] ?? '') . ' ' . ($board['code'] ?? ''));
                        if (str_contains($haystack, strtolower($label)) || ($label === 'OxfordAQA' && str_contains($haystack, 'aqa')) || ($label === 'Edexcel' && (str_contains($haystack, 'edexcel') || str_contains($haystack, 'pearson')))) {
                            return [(string) $board['board_id'], $label];
                        }
                    }
                    return ['', $label];
                }
            }
        }
        return ['', ''];
    }
}

if (!function_exists('mmh_past_drive_subject_from_text')) {
    function mmh_past_drive_subject_from_text($text)
    {
        $text = (string) $text;
        if (preg_match('/(?<!\d)(0\d{3,4}|\dMA\d|WMA\d{2}(?:\/\d{2})?)(?![A-Z0-9])/i', $text, $match)) {
            return [strtoupper($match[1]), ''];
        }
        if (preg_match('/(?<![A-Z0-9])(\d[A-Z]{1,4}\d{0,3})(?![A-Z0-9])/i', $text, $match)) {
            return [strtoupper($match[1]), ''];
        }
        if (preg_match('/\b((?:international\s+|additional\s+)?(?:mathematics|physics|chemistry|biology)(?:\s+[A-Z])?)(?:\s*\([^)]*\))?/i', $text, $match)) {
            return ['', trim($match[1])];
        }
        return ['', ''];
    }
}

if (!function_exists('mmh_past_drive_session_storage_value')) {
    function mmh_past_drive_session_storage_value($session)
    {
        return ['Jan' => 'January', 'Feb/Mar' => 'February/March', 'May/Jun' => 'May/June', 'Oct/Nov' => 'October/November'][$session] ?? '';
    }
}

if (!function_exists('mmh_past_drive_session_canonical')) {
    function mmh_past_drive_session_canonical($text)
    {
        $text = strtolower(trim((string) $text));
        if (preg_match('/(?:feb(?:ruary)?\s*[-\/]?\s*mar(?:ch)?|\bfm\b|\bfeb\/?mar\b|\bmar(?:ch)?\b)/', $text)) return 'Feb/Mar';
        if (preg_match('/(?:may\s*[-\/]?\s*jun(?:e)?|\bmj\b|\bmay\/?jun\b|\bjun(?:e)?\b|\bsummer\b)/', $text)) return 'May/Jun';
        if (preg_match('/(?:oct(?:ober)?\s*[-\/]?\s*nov(?:ember)?|\bon\b|\boct\/?nov\b|\boct(?:ober)?\b|\bnov(?:ember)?\b|\bwinter\b)/', $text)) return 'Oct/Nov';
        if (preg_match('/\bjan(?:uary)?\b/', $text)) return 'Jan';
        return '';
    }
}

if (!function_exists('mmh_past_drive_resolve_candidate_metadata')) {
    function mmh_past_drive_resolve_candidate_metadata($fileName, array $folderPath, array $boards, array $syllabuses)
    {
        $fileName = mmh_past_clean($fileName, 255);
        $stem = preg_replace('/\.[A-Za-z0-9]{1,8}\z/', '', $fileName);
        $folders = mmh_past_drive_folder_names($folderPath);
        $metadata = [
            'provider' => 'google_drive', 'exam_board_id' => '', 'board' => '', 'syllabus_id' => '',
            'syllabus_folder' => '', 'subject_code' => '', 'subject_name' => '', 'qualification' => '',
            'specification_year' => 0, 'exam_year' => 0, 'session' => '',
            // Existing import storage remains compatible with these mirrored fields.
            'year' => 0, 'exam_session' => '',
            'paper_number' => '', 'component_code' => '', 'variant' => '', 'tier' => '',
            'resource_type' => '', 'custom_type' => '', 'parser' => 'path_hierarchy_v2',
            'recognition_sources' => [], 'warnings' => [], 'mapping_status' => '', 'mapping_notice' => '',
            'confidence' => 'low',
        ];
        $ranks = [];
        $set = function ($field, $value, $source, $rank, $warnOnConflict = true) use (&$metadata, &$ranks) {
            if ($value === '' || $value === 0 || $value === null) return;
            if (empty($metadata[$field])) {
                $metadata[$field] = $value;
                $metadata['recognition_sources'][$field] = $source;
                $ranks[$field] = $rank;
                return;
            }
            if ((string) $metadata[$field] === (string) $value) return;
            if ($rank < ($ranks[$field] ?? 99)) {
                if ($warnOnConflict) $metadata['warnings'][] = ucfirst(str_replace('_', ' ', $field)) . ' conflict: ' . $metadata[$field] . ' (' . $metadata['recognition_sources'][$field] . ') versus ' . $value . ' (' . $source . ').';
                $metadata[$field] = $value;
                $metadata['recognition_sources'][$field] = $source;
                $ranks[$field] = $rank;
                return;
            }
            if ($warnOnConflict && $rank <= (($ranks[$field] ?? 99) + 0.01)) {
                $metadata['warnings'][] = ucfirst(str_replace('_', ' ', $field)) . ' conflict: ' . $metadata[$field] . ' (' . $metadata['recognition_sources'][$field] . ') versus ' . $value . ' (' . $source . ').';
            }
        };
        $setBoard = function ($text, $source, $rank) use ($boards, $set) {
            [$boardId, $boardName] = mmh_past_drive_board_from_text($text, $boards);
            $set('board', $boardName, $source, $rank);
            $set('exam_board_id', $boardId, $source, $rank);
        };

        // Level 1: syllabus/subject folder. A year in parentheses here is a
        // specification year only, never an examination year.
        $syllabusFolder = $folders[0] ?? '';
        if ($syllabusFolder !== '') {
            $metadata['syllabus_folder'] = $syllabusFolder;
            $metadata['recognition_sources']['syllabus_folder'] = 'folder:' . $syllabusFolder;
            $setBoard($syllabusFolder, 'folder:' . $syllabusFolder, 1.0);
            [$folderCode, $folderSubject] = mmh_past_drive_subject_from_text($syllabusFolder);
            $set('subject_code', $folderCode, 'folder:' . $syllabusFolder, 1.0);
            // The full syllabus-folder label is the authoritative subject title.
            // A generic subject regex may otherwise truncate names such as
            // “Mathematics Syllabus A” to “Mathematics S”.
            $subjectLabel = trim((string) preg_replace('/\s*\(\s*(?:19|20)\d{2}\s*\)/', '', $syllabusFolder));
            $set('subject_name', $subjectLabel, 'folder:' . $syllabusFolder, 1.0);
            if (preg_match('/\(\s*((?:19|20)\d{2})\s*\)/', $syllabusFolder, $m)) {
                $set('specification_year', (int) $m[1], 'folder:' . $syllabusFolder, 1.0, false);
            }
            if (preg_match('/\b(syllabus\s*[A-Z]|international\s+[A-Z\s]+|additional\s+[A-Z\s]+)/i', $syllabusFolder, $m)) {
                $set('qualification', trim($m[1]), 'folder:' . $syllabusFolder, 1.0, false);
            }
        }

        // Explicit filename convention: 0580_w25_qp_22.pdf. This is strongest
        // only for examination date/session, component and token confirmation.
        $filenameType = '';
        if (preg_match('/(?:^|[_\s-])(\d{4,5})[_\s-]([msw])(\d{2})[_\s-](qp|ms|er|gt|in|ci|sf)(?:[_\s-])?(\d{1,3})?(?:[_\s-]|$)/i', $stem, $m)) {
            $set('subject_code', $m[1], 'filename:' . $fileName, 2.0);
            $set('exam_year', 2000 + (int) $m[3], 'filename:' . $fileName, 1.0);
            $set('session', ['m' => 'Feb/Mar', 's' => 'May/Jun', 'w' => 'Oct/Nov'][strtolower($m[2])] ?? '', 'filename:' . $fileName, 1.0);
            $filenameType = mmh_past_drive_type_from_code($m[4])[0];
            if (!empty($m[5])) {
                $component = (string) $m[5];
                $set('component_code', $component, 'filename:' . $fileName, 1.0);
                $set('paper_number', 'Paper ' . substr($component, 0, 1), 'filename:' . $fileName, 1.0);
                if (strlen($component) > 1) $set('variant', substr($component, 1), 'filename:' . $fileName, 1.0);
            }
            $metadata['parser'] = 'cambridge';
        } elseif (preg_match('/(?:^|[_\s-])(\dMA\d|WMA\d{2}(?:\/\d{2})?)[_\s-]+([0-9][A-Z]{1,2})[_\s-]+(que|qp|ms|rms|msc)(?:[_\s-]|$)/i', $stem, $m)) {
            $set('subject_code', strtoupper($m[1]), 'filename:' . $fileName, 2.0);
            $set('component_code', strtoupper($m[2]), 'filename:' . $fileName, 1.0);
            $set('paper_number', 'Paper ' . strtoupper($m[2]), 'filename:' . $fileName, 1.0);
            $set('variant', strtoupper($m[2]), 'filename:' . $fileName, 1.0);
            $filenameType = mmh_past_drive_type_from_code($m[3])[0];
            $metadata['parser'] = 'edexcel';
        } else {
            [$code] = mmh_past_drive_subject_from_text($stem);
            $set('subject_code', $code, 'filename:' . $fileName, 2.0);
            $filenameType = mmh_past_drive_document_type_alias(str_replace('_', ' ', $stem));
            // Older Edexcel-style filenames can omit the syllabus code but
            // still state a stable Paper/component such as Paper1FR.
            if (preg_match('/(?:^|[_\s-])paper[_\s-]?(\d+)([a-z]{0,2})(?:[_\s-]|$)/i', $stem, $match)) {
                $component = strtoupper($match[1] . $match[2]);
                $set('component_code', $component, 'filename:' . $fileName, 1.0);
                $set('paper_number', 'Paper ' . $component, 'filename:' . $fileName, 1.0);
                $set('variant', $component, 'filename:' . $fileName, 1.0);
            } elseif (preg_match('/(?:^|[_\s-])(\d[A-Z]{1,2})[_\s-]+(?:qp|ms|rms|msc)(?:[_\s-]|$)/i', $stem, $match) || preg_match('/\b(?:question[\s_-]*paper|mark[\s_-]*scheme)\s+(\d[A-Z]{1,2})(?=[\s(_-]|$)/i', $stem, $match)) {
                // Compact older Edexcel filenames, e.g. “1FR MS.pdf” or
                // “Mark Scheme 1FR (Jun 2025).pdf”.
                $component = strtoupper($match[1]);
                $set('component_code', $component, 'filename:' . $fileName, 1.0);
                $set('paper_number', 'Paper ' . $component, 'filename:' . $fileName, 1.0);
                $set('variant', $component, 'filename:' . $fileName, 1.0);
            }
        }

        // Level 2: closest folder carrying examination year/session. Root
        // syllabus folders are deliberately excluded from this search.
        for ($i = count($folders) - 2; $i >= 1; $i--) {
            $label = $folders[$i];
            $session = mmh_past_drive_session_canonical($label);
            preg_match('/(?<!\d)((?:19|20)\d{2})(?!\d)/', $label, $yearMatch);
            if ($session !== '' || !empty($yearMatch[1])) {
                $set('exam_year', !empty($yearMatch[1]) ? (int) $yearMatch[1] : 0, 'folder:' . $label, 2.0);
                $set('session', $session, 'folder:' . $label, 2.0);
                break;
            }
        }
        // Last resort path detection cannot consider a parenthetical syllabus year.
        if (empty($metadata['exam_year']) || empty($metadata['session'])) {
            foreach (array_slice($folders, 1) as $label) {
                if (empty($metadata['exam_year']) && preg_match('/(?<!\d)((?:19|20)\d{2})(?!\d)/', $label, $m)) $set('exam_year', (int) $m[1], 'path:' . $label, 3.0);
                if (empty($metadata['session'])) $set('session', mmh_past_drive_session_canonical($label), 'path:' . $label, 3.0);
            }
        }

        // Level 3: document type belongs to the immediate parent. Filename can
        // confirm it but may not override it; an explicit disagreement is shown.
        $parent = $folders ? $folders[count($folders) - 1] : '';
        $parentType = mmh_past_drive_document_type_alias($parent);
        $set('resource_type', $parentType, 'parent-folder:' . $parent, 1.0);
        $set('resource_type', $filenameType, 'filename:' . $fileName, 2.0);
        if ($parentType !== '' && $filenameType !== '' && $parentType !== $filenameType) {
            $metadata['warnings'][] = 'Document type conflict: ' . $parentType . ' (parent-folder:' . $parent . ') versus ' . $filenameType . ' (filename:' . $fileName . ').';
        }
        if (empty($metadata['resource_type'])) {
            for ($i = count($folders) - 2; $i >= 0; $i--) {
                $set('resource_type', mmh_past_drive_document_type_alias($folders[$i]), 'ancestor:' . $folders[$i], 3.0);
            }
        }

        // Board can also be inferred from a known Edexcel component when its
        // syllabus-folder name is generic.
        if (empty($metadata['board']) && preg_match('/^(?:4MA|WMA)/i', (string) $metadata['subject_code'])) {
            $set('board', 'Edexcel', 'filename:' . $fileName, 2.5);
            foreach ($boards as $board) {
                if (preg_match('/edexcel|pearson/i', (string) ($board['name'] ?? '') . ' ' . (string) ($board['code'] ?? ''))) {
                    $set('exam_board_id', (string) $board['board_id'], 'filename:' . $fileName, 2.5);
                    break;
                }
            }
        }
        if (empty($metadata['board']) && preg_match('/^0\d{3,4}$/', (string) $metadata['subject_code']) && stripos($syllabusFolder, 'cambridge') !== false) {
            $set('board', 'Cambridge', 'folder:' . $syllabusFolder, 1.0);
        }

        $metadata['year'] = (int) $metadata['exam_year'];
        $metadata['exam_session'] = mmh_past_drive_session_storage_value($metadata['session']);
        if ($metadata['exam_year']) $metadata['recognition_sources']['year'] = $metadata['recognition_sources']['exam_year'] ?? 'exam year mirror';
        if ($metadata['exam_session'] !== '') $metadata['recognition_sources']['exam_session'] = $metadata['recognition_sources']['session'] ?? 'session mirror';

        $syllabus = mmh_past_drive_find_syllabus($syllabuses, $metadata['subject_code'], $metadata['exam_board_id']);
        if (!$syllabus && $metadata['subject_code'] !== '') $syllabus = mmh_past_drive_find_syllabus($syllabuses, $metadata['subject_code']);
        if ($syllabus) {
            $metadata['syllabus_id'] = (string) $syllabus['syllabus_id'];
            if (empty($metadata['exam_board_id'])) $metadata['exam_board_id'] = (string) $syllabus['exam_board_id'];
            $metadata['mapping_status'] = 'Mapped';
        } else {
            $metadata['mapping_status'] = 'LMS syllabus mapping required';
            $metadata['mapping_notice'] = 'Metadata recognized; LMS syllabus mapping required.';
        }

        $needsComponent = in_array($metadata['resource_type'], ['question_paper', 'mark_scheme'], true);
        foreach (['subject_name' => 'Subject', 'exam_year' => 'Exam year', 'session' => 'Session', 'resource_type' => 'Document type'] as $field => $label) {
            if (empty($metadata[$field])) $metadata['warnings'][] = $label . ' could not be detected automatically.';
        }
        if ($needsComponent) {
            foreach (['component_code' => 'Component', 'paper_number' => 'Paper', 'variant' => 'Variant'] as $field => $label) {
                if (empty($metadata[$field])) $metadata['warnings'][] = $label . ' could not be detected automatically.';
            }
        }
        $hasConflict = false;
        foreach ($metadata['warnings'] as $warning) if (str_contains(strtolower((string) $warning), 'conflict')) { $hasConflict = true; break; }
        $core = (!empty($metadata['subject_name']) || !empty($metadata['subject_code']) ? 1 : 0) + (!empty($metadata['exam_year']) ? 1 : 0) + (!empty($metadata['session']) ? 1 : 0) + (!empty($metadata['resource_type']) ? 1 : 0) + (!$needsComponent || (!empty($metadata['component_code']) && !empty($metadata['paper_number']) && !empty($metadata['variant'])) ? 1 : 0);
        $metadata['confidence'] = !$hasConflict && $core >= 5 ? 'high' : ($core >= 3 ? 'medium' : 'low');
        $pairSubject = strtoupper($metadata['subject_code'] ?: preg_replace('/\s+/', ' ', strtolower($metadata['subject_name'])));
        $pairComponent = $metadata['component_code'] ?: $metadata['paper_number'];
        $metadata['pairing_key'] = implode('|', [strtolower($metadata['board']), $pairSubject, $metadata['exam_year'], $metadata['session'], $pairComponent, $metadata['variant']]);
        return $metadata;
    }
}

if (!function_exists('mmh_past_drive_parse_metadata')) {
    function mmh_past_drive_parse_metadata(array $file, array $folderPath, array $boards, array $syllabuses)
    {
        $metadata = mmh_past_drive_resolve_candidate_metadata($file['name'] ?? '', $folderPath, $boards, $syllabuses);
        // Preserve proven legacy parsing only as a fallback for fields the new
        // resolver cannot identify; it never overwrites path-aware findings.
        $legacyPath = implode(' / ', mmh_past_drive_folder_names($folderPath)) . ' / ' . ($file['name'] ?? '');
        $legacy = mmh_past_drive_parse_metadata_legacy($file, $legacyPath, $boards, $syllabuses);
        foreach (['exam_board_id', 'syllabus_id', 'subject_code', 'year', 'exam_session', 'paper_number', 'variant', 'tier', 'resource_type', 'custom_type'] as $field) {
            if (empty($metadata[$field]) && !empty($legacy[$field])) {
                $metadata[$field] = $legacy[$field];
                $metadata['recognition_sources'][$field] = 'legacy fallback';
            }
        }
        if (empty($metadata['exam_year']) && !empty($metadata['year'])) {
            $metadata['exam_year'] = (int) $metadata['year'];
            $metadata['recognition_sources']['exam_year'] = 'legacy fallback';
        }
        if (empty($metadata['session']) && !empty($metadata['exam_session'])) {
            $metadata['session'] = ['January' => 'Jan', 'February/March' => 'Feb/Mar', 'May/June' => 'May/Jun', 'October/November' => 'Oct/Nov'][$metadata['exam_session']] ?? '';
            $metadata['recognition_sources']['session'] = 'legacy fallback';
        }
        return $metadata;
    }
}

if (!function_exists('mmh_past_drive_source')) {
    function mmh_past_drive_source(mysqli $conn, $sourceId)
    {
        $sourceId = mmh_past_identifier($sourceId, 40);
        if (!$sourceId) {
            return null;
        }
        $stmt = $conn->prepare('SELECT * FROM past_paper_drive_sources WHERE source_id = ? LIMIT 1');
        $stmt->bind_param('s', $sourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_past_drive_sources')) {
    function mmh_past_drive_sources(mysqli $conn)
    {
        mmh_past_drive_ensure_schema($conn);
        $result = $conn->query("SELECT * FROM past_paper_drive_sources ORDER BY updated_at DESC, id DESC");
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('mmh_past_drive_upsert_source')) {
    function mmh_past_drive_upsert_source(mysqli $conn, $folderId, $displayName, $admin)
    {
        $stmt = $conn->prepare('SELECT * FROM past_paper_drive_sources WHERE folder_id = ? LIMIT 1');
        $stmt->bind_param('s', $folderId);
        $stmt->execute();
        $source = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($source) {
            $stmt = $conn->prepare("UPDATE past_paper_drive_sources SET display_name = ?, status = 'active', last_error = NULL WHERE source_id = ?");
            $stmt->bind_param('ss', $displayName, $source['source_id']);
            $stmt->execute();
            $stmt->close();
            return $source['source_id'];
        }
        $sourceId = mmh_past_id('drive');
        $stmt = $conn->prepare('INSERT INTO past_paper_drive_sources (source_id, folder_id, display_name, created_by) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $sourceId, $folderId, $displayName, $admin);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $stmt->close();
        return $sourceId;
    }
}

if (!function_exists('mmh_past_drive_job')) {
    function mmh_past_drive_job(mysqli $conn, $jobId)
    {
        $jobId = mmh_past_identifier($jobId, 40);
        if (!$jobId) {
            return null;
        }
        $stmt = $conn->prepare('SELECT j.*, s.display_name AS source_name, s.folder_id FROM past_paper_drive_jobs j INNER JOIN past_paper_drive_sources s ON s.source_id = j.source_id WHERE j.job_id = ? LIMIT 1');
        $stmt->bind_param('s', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_past_drive_candidates')) {
    function mmh_past_drive_candidates(mysqli $conn, $jobId, $filter = '')
    {
        $jobId = mmh_past_identifier($jobId, 40);
        if (!$jobId) {
            return [];
        }
        $sql = 'SELECT * FROM past_paper_drive_candidates WHERE job_id = ?';
        $params = [$jobId];
        $types = 's';
        $allowed = ['create', 'update', 'skip_duplicate', 'manual_review', 'unsupported', 'error'];
        if (in_array($filter, $allowed, true)) {
            $sql .= ' AND proposed_action = ?';
            $params[] = $filter;
            $types .= 's';
        }
        $sql .= ' ORDER BY FIELD(proposed_action, "create", "update", "skip_duplicate", "manual_review", "unsupported", "error"), source_path ASC, file_name ASC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        return mmh_past_fetch_all($stmt);
    }
}

if (!function_exists('mmh_past_drive_candidate_filter')) {
    function mmh_past_drive_candidate_filter($filter)
    {
        $filter = (string) $filter;
        $legacy = ['create', 'update', 'skip_duplicate', 'manual_review', 'unsupported', 'error'];
        if (in_array($filter, $legacy, true)) return [$filter === '' ? '' : 'proposed_action = ?', $filter === '' ? [] : [$filter], $filter === '' ? '' : 's'];
        $filters = [
            'created' => ["result_status = 'created'", [], ''],
            'failed' => ["result_status = 'failed'", [], ''],
            'skipped' => ["proposed_action = 'skip_duplicate'", [], ''],
            'mapping_required' => ["proposed_action = 'manual_review' AND result_status IS NULL", [], ''],
            'pending' => ["proposed_action IN ('create', 'update') AND result_status IS NULL", [], ''],
        ];
        return $filters[$filter] ?? ['', [], ''];
    }
}

if (!function_exists('mmh_past_drive_candidate_summary')) {
    function mmh_past_drive_candidate_summary(mysqli $conn, $jobId)
    {
        $jobId = mmh_past_identifier($jobId, 40);
        $summary = ['total' => 0, 'mapped' => 0, 'eligible' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0, 'mapping_required' => 0, 'pending' => 0];
        if (!$jobId) return $summary;
        $stmt = $conn->prepare('SELECT metadata_json, proposed_action, result_status FROM past_paper_drive_candidates WHERE job_id = ?');
        $stmt->bind_param('s', $jobId);
        foreach (mmh_past_fetch_all($stmt) as $candidate) {
            $summary['total']++;
            $meta = mmh_past_drive_read_metadata($candidate);
            if (!empty($meta['syllabus_id'])) $summary['mapped']++;
            $action = (string) ($candidate['proposed_action'] ?? '');
            $result = (string) ($candidate['result_status'] ?? '');
            if (in_array($action, ['create', 'update'], true)) $summary['eligible']++;
            if ($result === 'created') $summary['created']++;
            if ($result === 'updated') $summary['updated']++;
            if ($result === 'failed') $summary['failed']++;
            if ($action === 'skip_duplicate') $summary['skipped']++;
            if ($action === 'manual_review' && $result === '') $summary['mapping_required']++;
            if (in_array($action, ['create', 'update'], true) && $result === '') $summary['pending']++;
        }
        return $summary;
    }
}

if (!function_exists('mmh_past_drive_candidates_page')) {
    function mmh_past_drive_candidates_page(mysqli $conn, $jobId, $filter = '', $page = 1, $perPage = 50)
    {
        $jobId = mmh_past_identifier($jobId, 40);
        $page = max(1, (int) $page);
        $perPage = max(1, min(50, (int) $perPage));
        if (!$jobId) return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0];
        [$filterWhere, $filterParams, $filterTypes] = mmh_past_drive_candidate_filter($filter);
        $where = 'job_id = ?' . ($filterWhere !== '' ? ' AND ' . $filterWhere : '');
        $params = array_merge([$jobId], $filterParams);
        $types = 's' . $filterTypes;
        $count = $conn->prepare('SELECT COUNT(*) AS total FROM past_paper_drive_candidates WHERE ' . $where);
        $count->bind_param($types, ...$params);
        $count->execute();
        $total = (int) (($count->get_result()->fetch_assoc()['total'] ?? 0));
        $count->close();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT * FROM past_paper_drive_candidates WHERE ' . $where . ' ORDER BY FIELD(result_status, "failed", "created", "updated", ""), FIELD(proposed_action, "create", "update", "skip_duplicate", "manual_review", "unsupported", "error"), id ASC LIMIT ? OFFSET ?';
        $stmt = $conn->prepare($sql);
        $bindTypes = $types . 'ii';
        $bindParams = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($bindTypes, ...$bindParams);
        $rows = mmh_past_fetch_all($stmt);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages];
    }
}

if (!function_exists('mmh_past_drive_candidate')) {
    function mmh_past_drive_candidate(mysqli $conn, $jobId, $candidateId)
    {
        $jobId = mmh_past_identifier($jobId, 40);
        $candidateId = mmh_past_identifier($candidateId, 40);
        if (!$jobId || !$candidateId) return null;
        $stmt = $conn->prepare('SELECT * FROM past_paper_drive_candidates WHERE job_id = ? AND candidate_id = ? LIMIT 1');
        $stmt->bind_param('ss', $jobId, $candidateId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_past_drive_candidate_folder_context')) {
    function mmh_past_drive_candidate_folder_context(array $candidate, $sourceFolderId = '')
    {
        $path = json_decode((string) ($candidate['folder_path_json'] ?? ''), true);
        if (!is_array($path) || !$path) {
            $sourcePath = (string) ($candidate['source_path'] ?? '');
            $fileName = (string) ($candidate['file_name'] ?? '');
            if ($fileName !== '' && str_ends_with($sourcePath, ' / ' . $fileName)) {
                $sourcePath = substr($sourcePath, 0, -strlen(' / ' . $fileName));
            }
            $path = array_map(static fn($name) => ['id' => '', 'name' => trim($name)], array_filter(explode(' / ', $sourcePath), 'strlen'));
        }
        if (!$path && !empty($candidate['parent_folder_name'])) {
            $path = [['id' => '', 'name' => (string) $candidate['parent_folder_name']]];
        }
        return mmh_past_drive_folder_context($path, $candidate['source_folder_id'] ?? $sourceFolderId);
    }
}

if (!function_exists('mmh_past_drive_reanalyze_batch')) {
    function mmh_past_drive_reanalyze_batch(mysqli $conn, $jobId, $admin, $restart = false, $limit = 50)
    {
        mmh_past_drive_ensure_schema($conn);
        $job = mmh_past_drive_job($conn, $jobId);
        if (!$job || !in_array((string) $job['status'], ['completed', 'paused'], true)) {
            return [false, 'Only a completed or paused dry-run can be re-analyzed.', []];
        }
        $source = mmh_past_drive_source($conn, (string) $job['source_id']);
        $summary = json_decode((string) ($job['summary_json'] ?? ''), true) ?: [];
        $state = is_array($summary['reanalyze_state'] ?? null) ? $summary['reanalyze_state'] : [];
        if ($restart || empty($state)) {
            $state = ['last_id' => 0, 'processed' => 0, 'batches_completed' => 0, 'counts' => ['create' => 0, 'update' => 0, 'skip_duplicate' => 0, 'manual_review' => 0, 'unsupported' => 0, 'error' => 0], 'completed' => false];
        }
        $limit = max(1, min(50, (int) $limit));
        $totalStmt = $conn->prepare('SELECT COUNT(*) AS total FROM past_paper_drive_candidates WHERE job_id = ?');
        $totalStmt->bind_param('s', $job['job_id']);
        $totalStmt->execute();
        $total = (int) (($totalStmt->get_result()->fetch_assoc()['total'] ?? 0));
        $totalStmt->close();
        $lastId = max(0, (int) ($state['last_id'] ?? 0));
        $select = $conn->prepare('SELECT * FROM past_paper_drive_candidates WHERE job_id = ? AND id > ? ORDER BY id ASC LIMIT ?');
        $select->bind_param('sii', $job['job_id'], $lastId, $limit);
        $candidates = mmh_past_fetch_all($select);
        if (!$candidates) {
            $state['completed'] = true;
            $state['total'] = $total;
            $state['updated_at'] = date('c');
            $summary['reanalyze_state'] = $state;
            $summaryJson = json_encode($summary, JSON_UNESCAPED_SLASHES);
            $update = $conn->prepare('UPDATE past_paper_drive_jobs SET summary_json = ?, error_message = NULL WHERE job_id = ?');
            $update->bind_param('ss', $summaryJson, $job['job_id']);
            $update->execute();
            $update->close();
            return [true, 'Metadata re-analysis is already complete.', ['job_id' => $job['job_id'], 'reanalyzed' => 0, 'total' => $total, 'completed' => true]];
        }
        $boards = mmh_past_exam_boards($conn, false);
        $syllabuses = mmh_past_syllabuses($conn, '', false);
        $counts = is_array($state['counts'] ?? null) ? $state['counts'] : [];
        foreach (['create', 'update', 'skip_duplicate', 'manual_review', 'unsupported', 'error'] as $key) $counts[$key] = (int) ($counts[$key] ?? 0);
        $updated = 0;
        $newLastId = $lastId;
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('UPDATE past_paper_drive_candidates SET source_folder_id = ?, parent_folder_id = ?, parent_folder_name = ?, relative_folder_path = ?, folder_path_json = ?, folder_depth = ?, source_path = ?, metadata_json = ?, proposed_action = ?, confidence = ?, warning_message = ? WHERE candidate_id = ? AND job_id = ?');
            foreach ($candidates as $candidate) {
                $context = mmh_past_drive_candidate_folder_context($candidate, $source['folder_id'] ?? '');
                $file = ['id' => (string) $candidate['drive_file_id'], 'name' => (string) $candidate['file_name'], 'mimeType' => (string) $candidate['mime_type'], 'size' => $candidate['file_size'], 'modifiedTime' => (string) $candidate['modified_at']];
                $metadata = mmh_past_drive_parse_metadata($file, $context['folder_path'], $boards, $syllabuses);
                $classification = mmh_past_drive_candidate_action($conn, $file, $metadata, mmh_past_drive_supported_file($file)[0]);
                $action = $classification[0];
                $counts[$action]++;
                $warning = implode(' ', array_values(array_unique(array_filter(array_merge($metadata['warnings'], [(string) $classification[2]])))));
                $confidence = $metadata['confidence'] ?? $classification[1];
                $sourcePath = trim(($context['source_path'] ?: 'Google Drive') . ' / ' . $file['name']);
                $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES);
                $stmt->bind_param('sssssisssssss', $context['source_folder_id'], $context['parent_folder_id'], $context['parent_folder_name'], $context['relative_folder_path'], $context['folder_path_json'], $context['folder_depth'], $sourcePath, $metadataJson, $action, $confidence, $warning, $candidate['candidate_id'], $job['job_id']);
                if (!$stmt->execute()) throw new RuntimeException('Unable to update a re-analyzed candidate.');
                $updated++;
                $newLastId = max($newLastId, (int) $candidate['id']);
            }
            $stmt->close();
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            return [false, 'Metadata re-analysis batch rolled back safely. No candidates were changed.', []];
        }
        $state['last_id'] = $newLastId;
        $state['processed'] = (int) ($state['processed'] ?? 0) + $updated;
        $state['batches_completed'] = (int) ($state['batches_completed'] ?? 0) + 1;
        $state['counts'] = $counts;
        $state['total'] = $total;
        $state['completed'] = $state['processed'] >= $total;
        $state['updated_at'] = date('c');
        $state['updated_by'] = $admin;
        $summary['reanalyze_state'] = $state;
        if ($state['completed']) {
            foreach ($counts as $key => $count) $summary[$key] = $count;
            $summary['reanalyzed_candidates'] = $state['processed'];
            $summary['reanalyzed_at'] = date('Y-m-d H:i:s');
        }
        $summaryJson = json_encode($summary, JSON_UNESCAPED_SLASHES);
        $update = $conn->prepare('UPDATE past_paper_drive_jobs SET summary_json = ?, error_message = NULL WHERE job_id = ?');
        $update->bind_param('ss', $summaryJson, $job['job_id']);
        $update->execute();
        $update->close();
        $message = $state['completed'] ? 'Metadata re-analysis completed without contacting Google Drive.' : sprintf('Metadata re-analysis processed %d candidates. %d remain; continue when ready.', $updated, max(0, $total - $state['processed']));
        return [true, $message, ['job_id' => $job['job_id'], 'reanalyzed' => $updated, 'processed' => $state['processed'], 'total' => $total, 'completed' => $state['completed']]];
    }
}

if (!function_exists('mmh_past_drive_reanalyze_job')) {
    // Kept for the existing request route; each invocation handles one batch.
    function mmh_past_drive_reanalyze_job(mysqli $conn, $jobId, $admin, $restart = false)
    {
        return mmh_past_drive_reanalyze_batch($conn, $jobId, $admin, $restart, 50);
    }
}

if (!function_exists('mmh_past_drive_existing_resource')) {
    function mmh_past_drive_existing_resource(mysqli $conn, $fileId)
    {
        // Also recognize a manually-created Drive URL. If it refers to the
        // same stable file ID, the importer adopts its source metadata instead
        // of creating a duplicate resource.
        $like = '%' . $fileId . '%';
        $stmt = $conn->prepare('SELECT * FROM past_paper_resources WHERE drive_file_id = ? OR external_url LIKE ? LIMIT 1');
        $stmt->bind_param('ss', $fileId, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_past_drive_existing_paper')) {
    function mmh_past_drive_existing_paper(mysqli $conn, array $metadata)
    {
        if (empty($metadata['exam_board_id']) || empty($metadata['syllabus_id']) || empty($metadata['year']) || empty($metadata['exam_session']) || empty($metadata['paper_number']) || empty($metadata['variant'])) {
            return null;
        }
        $stmt = $conn->prepare('SELECT * FROM past_papers WHERE exam_board_id = ? AND syllabus_id = ? AND year = ? AND exam_session = ? AND paper_number = ? AND variant = ? LIMIT 1');
        $stmt->bind_param('ssisss', $metadata['exam_board_id'], $metadata['syllabus_id'], $metadata['year'], $metadata['exam_session'], $metadata['paper_number'], $metadata['variant']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_past_drive_candidate_action')) {
    function mmh_past_drive_candidate_action(mysqli $conn, array $file, array $metadata, $supported)
    {
        if (!$supported) {
            return ['unsupported', 'manual', 'Only PDF files and Google Workspace documents are supported by this importer.'];
        }
        $existing = mmh_past_drive_existing_resource($conn, (string) $file['id']);
        $fingerprint = mmh_past_drive_fingerprint($file);
        if ($existing) {
            if ((string) ($existing['drive_fingerprint'] ?? '') === $fingerprint && (string) ($existing['drive_source_status'] ?? 'available') === 'available') {
                return ['skip_duplicate', 'high', 'This exact Google Drive file is already imported and unchanged.'];
            }
            return ['update', 'high', 'This Google Drive file is already imported; its source metadata will be refreshed without creating a duplicate.'];
        }
        if (!empty($metadata['warnings'])) {
            return ['manual_review', 'manual', implode(' ', $metadata['warnings'])];
        }
        if (empty($metadata['syllabus_id'])) {
            return ['manual_review', $metadata['confidence'] ?? 'medium', $metadata['mapping_notice'] ?: 'Metadata recognized; LMS syllabus mapping required.'];
        }
        $paper = mmh_past_drive_existing_paper($conn, $metadata);
        if ($paper && mmh_past_primary_type_exists($conn, $paper['paper_id'], $metadata['resource_type'])) {
            return ['manual_review', 'medium', 'A different primary resource of this type already exists for the detected paper. Review before replacing or adding it.'];
        }
        return ['create', $metadata['parser'] === 'generic' ? 'medium' : 'high', 'Ready to create or pair with the detected Past Paper.'];
    }
}

if (!function_exists('mmh_past_drive_create_job')) {
    function mmh_past_drive_create_job(mysqli $conn, $sourceId, $type, $admin)
    {
        $jobId = mmh_past_id('drivejob');
        $type = $type === 'sync' ? 'sync' : 'scan';
        $status = 'running';
        $stmt = $conn->prepare('INSERT INTO past_paper_drive_jobs (job_id, source_id, job_type, status, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $jobId, $sourceId, $type, $status, $admin);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $stmt->close();
        return $jobId;
    }
}

if (!function_exists('mmh_past_drive_finish_job')) {
    function mmh_past_drive_finish_job(mysqli $conn, $jobId, $status, array $summary, $error = '')
    {
        $json = json_encode($summary, JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare('UPDATE past_paper_drive_jobs SET status = ?, summary_json = ?, error_message = ?, completed_at = NOW() WHERE job_id = ?');
        $stmt->bind_param('ssss', $status, $json, $error, $jobId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('mmh_past_drive_drive_url')) {
    function mmh_past_drive_drive_url($fileId, $mimeType = '')
    {
        $id = rawurlencode((string) $fileId);
        return match (strtolower((string) $mimeType)) {
            'application/vnd.google-apps.document' => 'https://docs.google.com/document/d/' . $id . '/export?format=pdf',
            'application/vnd.google-apps.presentation' => 'https://docs.google.com/presentation/d/' . $id . '/export/pdf',
            'application/vnd.google-apps.spreadsheet' => 'https://docs.google.com/spreadsheets/d/' . $id . '/export?format=pdf',
            default => 'https://drive.google.com/open?id=' . $id,
        };
    }
}

if (!function_exists('mmh_past_drive_insert_candidate')) {
    function mmh_past_drive_insert_candidate(mysqli $conn, $jobId, $sourceId, array $file, $path, array $folderContext, array $metadata, array $classification)
    {
        $candidateId = mmh_past_id('candidate');
        $fileId = (string) $file['id'];
        $name = mmh_past_clean($file['name'] ?? '', 255);
        $mime = mmh_past_clean($file['mimeType'] ?? '', 190);
        $size = isset($file['size']) && is_numeric($file['size']) ? (int) $file['size'] : null;
        $modified = mmh_past_drive_datetime($file['modifiedTime'] ?? '');
        $fingerprint = mmh_past_drive_fingerprint($file);
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        [$action, $classificationConfidence, $warning] = $classification;
        $confidence = $metadata['confidence'] ?? $classificationConfidence;
        $warning = implode(' ', array_values(array_unique(array_filter(array_merge($metadata['warnings'] ?? [], [$warning])))));
        $sourceFolderId = mmh_past_clean($folderContext['source_folder_id'] ?? '', 128);
        $parentFolderId = mmh_past_clean($folderContext['parent_folder_id'] ?? '', 128);
        $parentFolderName = mmh_past_clean($folderContext['parent_folder_name'] ?? '', 190);
        $relativeFolderPath = (string) ($folderContext['relative_folder_path'] ?? '');
        $folderPathJson = (string) ($folderContext['folder_path_json'] ?? '[]');
        $folderDepth = max(0, (int) ($folderContext['folder_depth'] ?? 0));
        $stmt = $conn->prepare('INSERT INTO past_paper_drive_candidates (candidate_id, job_id, source_id, drive_file_id, source_path, file_name, mime_type, file_size, modified_at, source_fingerprint, source_folder_id, parent_folder_id, parent_folder_name, relative_folder_path, folder_path_json, folder_depth, metadata_json, proposed_action, confidence, warning_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssssssisssssssissss', $candidateId, $jobId, $sourceId, $fileId, $path, $name, $mime, $size, $modified, $fingerprint, $sourceFolderId, $parentFolderId, $parentFolderName, $relativeFolderPath, $folderPathJson, $folderDepth, $json, $action, $confidence, $warning);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('mmh_past_drive_restore_found')) {
    function mmh_past_drive_restore_found(mysqli $conn, $sourceId, array $foundIds)
    {
        if (!$foundIds) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($foundIds), '?'));
        $types = 's' . str_repeat('s', count($foundIds));
        $params = array_merge([$sourceId], $foundIds);
        $stmt = $conn->prepare("UPDATE past_paper_resources SET drive_source_status = 'available' WHERE drive_source_id = ? AND drive_file_id IN ({$placeholders})");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('mmh_past_drive_mark_missing')) {
    function mmh_past_drive_mark_missing(mysqli $conn, $sourceId, array $foundIds)
    {
        if (!$foundIds) {
            $stmt = $conn->prepare("UPDATE past_paper_resources SET drive_source_status = 'missing' WHERE drive_source_id = ? AND drive_file_id IS NOT NULL");
            $stmt->bind_param('s', $sourceId);
            $stmt->execute();
            $stmt->close();
            return;
        }
        $placeholders = implode(',', array_fill(0, count($foundIds), '?'));
        $types = 's' . str_repeat('s', count($foundIds));
        $params = array_merge([$sourceId], $foundIds);
        $stmt = $conn->prepare("UPDATE past_paper_resources SET drive_source_status = 'missing' WHERE drive_source_id = ? AND drive_file_id IS NOT NULL AND drive_file_id NOT IN ({$placeholders})");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('mmh_past_drive_save_progress')) {
    function mmh_past_drive_save_progress(mysqli $conn, $jobId, array $summary)
    {
        $json = json_encode($summary, JSON_UNESCAPED_SLASHES);
        $status = 'paused';
        $stmt = $conn->prepare('UPDATE past_paper_drive_jobs SET status = ?, summary_json = ?, error_message = NULL, completed_at = NULL WHERE job_id = ?');
        $stmt->bind_param('sss', $status, $json, $jobId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('mmh_past_drive_finalize_source_status')) {
    function mmh_past_drive_finalize_source_status(mysqli $conn, $sourceId, $jobId)
    {
        // A completed job has one candidate for every non-folder item it saw.
        // Joining that set avoids building an unbounded IN (...) list for large
        // Drive folders and only runs after a full traversal is complete.
        $stmt = $conn->prepare("UPDATE past_paper_resources r INNER JOIN past_paper_drive_candidates c ON c.job_id = ? AND c.drive_file_id = r.drive_file_id SET r.drive_source_status = 'available' WHERE r.drive_source_id = ?");
        $stmt->bind_param('ss', $jobId, $sourceId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE past_paper_resources r LEFT JOIN past_paper_drive_candidates c ON c.job_id = ? AND c.drive_file_id = r.drive_file_id SET r.drive_source_status = 'missing' WHERE r.drive_source_id = ? AND r.drive_file_id IS NOT NULL AND c.id IS NULL");
        $stmt->bind_param('ss', $jobId, $sourceId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('mmh_past_drive_scan')) {
    function mmh_past_drive_scan(mysqli $conn, array $input, $admin, $sync = false)
    {
        mmh_past_drive_ensure_schema($conn);
        $connection = mmh_past_drive_connection();
        if (!$connection['available']) {
            return [false, $connection['message'], []];
        }

        $resumeJobId = mmh_past_identifier($input['resume_job_id'] ?? '', 40);
        $jobId = '';
        $sourceId = '';
        $folderId = '';
        $queue = [];
        $scheduled = [];
        $files = 0;
        $folders = 0;
        $pages = 0;
        $counts = ['create' => 0, 'update' => 0, 'skip_duplicate' => 0, 'manual_review' => 0, 'unsupported' => 0, 'error' => 0];

        if ($resumeJobId) {
            $job = mmh_past_drive_job($conn, $resumeJobId);
            if (!$job || !in_array((string) $job['status'], ['paused', 'running'], true)) {
                return [false, 'This Drive scan cannot be resumed. Start a new scan instead.', []];
            }
            $summary = json_decode((string) ($job['summary_json'] ?? ''), true);
            $state = is_array($summary['scan_state'] ?? null) ? $summary['scan_state'] : null;
            if (!$state || empty($state['queue']) || !is_array($state['queue'])) {
                return [false, 'This Drive scan has no safe continuation state. Start a new scan instead.', []];
            }
            $source = mmh_past_drive_source($conn, (string) $job['source_id']);
            if (!$source) {
                return [false, 'The configured Google Drive source is no longer available.', []];
            }
            $jobId = (string) $job['job_id'];
            $sourceId = (string) $source['source_id'];
            $folderId = (string) $source['folder_id'];
            $sync = (string) $job['job_type'] === 'sync';
            foreach ($state['queue'] as $entry) {
                $id = mmh_past_drive_parse_folder_id($entry['id'] ?? '');
                if (!$id) {
                    continue;
                }
                $legacyNames = array_values(array_filter(array_map('trim', explode(' / ', (string) ($entry['path'] ?? 'Google Drive')))));
                $folderPath = is_array($entry['folder_path'] ?? null) ? $entry['folder_path'] : array_map(static fn($name) => ['id' => '', 'name' => $name], $legacyNames);
                $queue[] = [
                    'id' => $id,
                    'path' => mmh_past_clean($entry['path'] ?? 'Google Drive', 400),
                    'folder_path' => $folderPath,
                    'depth' => max(0, (int) ($entry['depth'] ?? 0)),
                    'page_token' => is_string($entry['page_token'] ?? null) ? (string) $entry['page_token'] : '',
                    'seen_tokens' => is_array($entry['seen_tokens'] ?? null) ? array_values($entry['seen_tokens']) : [],
                ];
            }
            foreach (array_keys(is_array($state['scheduled'] ?? null) ? $state['scheduled'] : []) as $id) {
                if (mmh_past_drive_parse_folder_id($id)) {
                    $scheduled[$id] = true;
                }
            }
            $files = max(0, (int) ($state['files_scanned'] ?? 0));
            $folders = max(0, (int) ($state['folders_scanned'] ?? 0));
            $pages = max(0, (int) ($state['pages_scanned'] ?? 0));
            foreach ($counts as $key => $unused) {
                $counts[$key] = max(0, (int) (($state['counts'][$key] ?? 0)));
            }
            if (!$queue) {
                return [false, 'This Drive scan has no valid folders left to resume. Start a new scan instead.', []];
            }
        } else {
            $source = null;
            $sourceIdInput = mmh_past_identifier($input['source_id'] ?? '', 40);
            if ($sourceIdInput) {
                $source = mmh_past_drive_source($conn, $sourceIdInput);
            }
            $folderId = $source['folder_id'] ?? mmh_past_drive_parse_folder_id($input['folder_url'] ?? '');
            if (!$folderId) {
                return [false, 'Enter a valid Google Drive folder URL or choose a configured source.', []];
            }
            [$ok, $folder, $message] = mmh_past_drive_api('files/' . rawurlencode($folderId), [
                'fields' => 'id,name,mimeType,modifiedTime',
            ]);
            if (!$ok) {
                return [false, $message, []];
            }
            if (($folder['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
                return [false, 'The supplied Google Drive link is not a folder.', []];
            }
            $sourceId = $source['source_id'] ?? mmh_past_drive_upsert_source($conn, $folderId, mmh_past_clean($folder['name'] ?? 'Google Drive folder', 190), $admin);
            if (!$sourceId) {
                return [false, 'Unable to save the Google Drive source configuration.', []];
            }
            $jobId = mmh_past_drive_create_job($conn, $sourceId, $sync ? 'sync' : 'scan', $admin);
            if (!$jobId) {
                return [false, 'Unable to start the Google Drive scan.', []];
            }
            $rootName = mmh_past_clean($folder['name'] ?? 'Google Drive', 190);
            $queue = [[
                'id' => $folderId,
                'path' => $rootName,
                'folder_path' => [['id' => $folderId, 'name' => $rootName]],
                'depth' => 0,
                'page_token' => '',
                'seen_tokens' => [],
            ]];
            $scheduled[$folderId] = true;
        }

        $boards = mmh_past_exam_boards($conn, false);
        $syllabuses = mmh_past_syllabuses($conn, '', false);
        mmh_past_drive_trace_context([
            'enabled' => true,
            'started_at' => microtime(true),
            'request_count' => 0,
            'job_id' => $jobId,
        ]);
        mmh_past_drive_trace($resumeJobId ? 'scan_resumed' : 'scan_started', [
            'root_folder' => mmh_past_drive_trace_id($folderId),
            'queued_folders' => count($queue),
            'files_scanned' => $files,
        ]);

        // Each Google list call returns at most 100 items. A web request handles
        // a bounded number of pages, then persists the exact queue/page-token
        // state for the existing Continue scan action.
        $maxRequestsPerBatch = 18;
        $maxElapsedSeconds = 14.0;
        $paused = false;
        try {
            while ($queue) {
                $context = mmh_past_drive_trace_context();
                if ((int) $context['request_count'] >= $maxRequestsPerBatch || (microtime(true) - (float) $context['started_at']) >= $maxElapsedSeconds) {
                    $paused = true;
                    break;
                }
                $current = array_shift($queue);
                $pageToken = (string) ($current['page_token'] ?? '');
                $seenTokens = array_fill_keys(array_filter($current['seen_tokens'] ?? [], 'is_string'), true);
                if ($pageToken === '') {
                    $folders++;
                }
                mmh_past_drive_trace('enter_folder', [
                    'folder' => mmh_past_drive_trace_id($current['id']),
                    'depth' => (int) ($current['depth'] ?? 0),
                    'queue_remaining' => count($queue),
                    'folders_scanned' => $folders,
                    'files_scanned' => $files,
                    'page_token' => $pageToken === '' ? 'initial' : mmh_past_drive_trace_id($pageToken),
                ]);
                $query = [
                    'q' => "'" . $current['id'] . "' in parents and trashed = false",
                    'pageSize' => 100,
                    'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,size,md5Checksum,webViewLink,parents)',
                    'includeItemsFromAllDrives' => 'true',
                ];
                if ($pageToken !== '') {
                    $query['pageToken'] = $pageToken;
                }
                [$listed, $data, $listMessage] = mmh_past_drive_api('files', $query);
                if (!$listed) {
                    throw new RuntimeException($listMessage);
                }
                $pages++;
                $children = $data['files'] ?? [];
                $childFolders = 0;
                foreach ($children as $child) {
                    if (($child['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                        $childFolders++;
                    }
                }
                mmh_past_drive_trace('folder_page', [
                    'folder' => mmh_past_drive_trace_id($current['id']),
                    'depth' => (int) ($current['depth'] ?? 0),
                    'children' => count($children),
                    'child_folders' => $childFolders,
                    'page_token' => $pageToken === '' ? 'initial' : mmh_past_drive_trace_id($pageToken),
                ]);
                foreach ($children as $file) {
                    if (($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                        $childId = mmh_past_drive_parse_folder_id($file['id'] ?? '');
                        if ($childId && !isset($scheduled[$childId])) {
                            $scheduled[$childId] = true;
                            $childName = mmh_past_clean($file['name'] ?? 'Folder', 190);
                            $childPath = is_array($current['folder_path'] ?? null) ? $current['folder_path'] : [];
                            $childPath[] = ['id' => $childId, 'name' => $childName];
                            $queue[] = [
                                'id' => $childId,
                                'path' => $current['path'] . ' / ' . $childName,
                                'folder_path' => $childPath,
                                'depth' => (int) ($current['depth'] ?? 0) + 1,
                                'page_token' => '',
                                'seen_tokens' => [],
                            ];
                        }
                        continue;
                    }
                    $files++;
                    [$supported] = mmh_past_drive_supported_file($file);
                    $folderPath = is_array($current['folder_path'] ?? null) ? $current['folder_path'] : [['id' => $folderId, 'name' => $current['path']]];
                    $folderContext = mmh_past_drive_folder_context($folderPath, $folderId);
                    $path = $folderContext['source_path'] . ' / ' . mmh_past_clean($file['name'] ?? 'File', 255);
                    $metadata = mmh_past_drive_parse_metadata($file, $folderContext['folder_path'], $boards, $syllabuses);
                    $classification = mmh_past_drive_candidate_action($conn, $file, $metadata, $supported);
                    $counts[$classification[0]]++;
                    mmh_past_drive_insert_candidate($conn, $jobId, $sourceId, $file, $path, $folderContext, $metadata, $classification);
                }
                $nextPageToken = (string) ($data['nextPageToken'] ?? '');
                if ($nextPageToken !== '') {
                    if ($nextPageToken === $pageToken || isset($seenTokens[$nextPageToken])) {
                        throw new RuntimeException('Google Drive repeated a page token while scanning a folder.');
                    }
                    $seenTokens[$nextPageToken] = true;
                    $current['page_token'] = $nextPageToken;
                    $current['seen_tokens'] = array_keys($seenTokens);
                    $queue[] = $current;
                }
            }

            $summary = array_merge($counts, [
                'files_scanned' => $files,
                'folders_scanned' => $folders,
                'pages_scanned' => $pages,
                'source_id' => $sourceId,
                'connection_mode' => $connection['mode'],
                'remaining_folders' => count($queue),
                'batch_limited' => $paused,
            ]);
            if ($paused) {
                $summary['scan_state'] = [
                    'queue' => array_values($queue),
                    'scheduled' => $scheduled,
                    'files_scanned' => $files,
                    'folders_scanned' => $folders,
                    'pages_scanned' => $pages,
                    'counts' => $counts,
                ];
                mmh_past_drive_save_progress($conn, $jobId, $summary);
                mmh_past_drive_trace('scan_paused', ['remaining_folders' => count($queue), 'files_scanned' => $files, 'folders_scanned' => $folders]);
                return [true, 'Drive scan paused safely. Continue scanning to finish the dry run.', ['job_id' => $jobId, 'summary' => $summary, 'paused' => true]];
            }

            mmh_past_drive_finalize_source_status($conn, $sourceId, $jobId);
            unset($summary['batch_limited']);
            $summary['batch_limited'] = false;
            mmh_past_drive_finish_job($conn, $jobId, 'completed', $summary);
            $stmt = $conn->prepare('UPDATE past_paper_drive_sources SET last_scan_at = NOW(), last_sync_at = CASE WHEN ? = 1 THEN NOW() ELSE last_sync_at END, last_error = NULL WHERE source_id = ?');
            $syncValue = $sync ? 1 : 0;
            $stmt->bind_param('is', $syncValue, $sourceId);
            $stmt->execute();
            $stmt->close();
            mmh_past_drive_trace('scan_completed', ['files_scanned' => $files, 'folders_scanned' => $folders, 'pages_scanned' => $pages]);
            return [true, 'Drive scan completed. Review the dry-run candidates before importing.', ['job_id' => $jobId, 'summary' => $summary]];
        } catch (Throwable $error) {
            $summary = array_merge($counts, ['files_scanned' => $files, 'folders_scanned' => $folders, 'pages_scanned' => $pages, 'source_id' => $sourceId]);
            mmh_past_drive_finish_job($conn, $jobId, 'failed', $summary, 'Scan failed.');
            $stmt = $conn->prepare('UPDATE past_paper_drive_sources SET last_error = ? WHERE source_id = ?');
            $friendly = 'Drive scan failed. No Past Paper records were changed.';
            $stmt->bind_param('ss', $friendly, $sourceId);
            $stmt->execute();
            $stmt->close();
            mmh_past_drive_trace('scan_failed', ['reason' => get_class($error)]);
            return [false, $friendly, ['job_id' => $jobId]];
        }
    }
}

if (!function_exists('mmh_past_drive_read_metadata')) {
    function mmh_past_drive_read_metadata(array $candidate)
    {
        $metadata = json_decode((string) ($candidate['metadata_json'] ?? ''), true);
        return is_array($metadata) ? $metadata : [];
    }
}

if (!function_exists('mmh_past_drive_valid_import_metadata')) {
    function mmh_past_drive_valid_import_metadata(mysqli $conn, array $candidate, array $correction)
    {
        $metadata = mmh_past_drive_read_metadata($candidate);
        foreach (['exam_board_id', 'syllabus_id', 'year', 'exam_session', 'paper_number', 'variant', 'resource_type', 'custom_type', 'tier'] as $field) {
            if (array_key_exists($field, $correction)) {
                $metadata[$field] = is_string($correction[$field]) ? trim($correction[$field]) : $correction[$field];
            }
        }
        $metadata['exam_board_id'] = mmh_past_identifier($metadata['exam_board_id'] ?? '', 40) ?: '';
        $metadata['syllabus_id'] = mmh_past_identifier($metadata['syllabus_id'] ?? '', 40) ?: '';
        $metadata['year'] = (int) ($metadata['year'] ?? 0);
        $metadata['exam_session'] = mmh_past_session($metadata['exam_session'] ?? 'Custom');
        $metadata['paper_number'] = mmh_past_clean($metadata['paper_number'] ?? '', 80);
        $metadata['variant'] = mmh_past_clean($metadata['variant'] ?? '', 80);
        $metadata['resource_type'] = mmh_past_resource_type($metadata['resource_type'] ?? 'custom');
        $metadata['custom_type'] = mmh_past_clean($metadata['custom_type'] ?? '', 80);
        $metadata['tier'] = mmh_past_clean($metadata['tier'] ?? '', 80);
        // Publication and access are separate. Automatic Drive imports use
        // the same published convention as visible manually-created papers;
        // the resource access level still controls who may open it.
        $status = mmh_past_status($correction['publish_state'] ?? 'published', 'published');
        $board = null;
        if ($metadata['exam_board_id'] !== '') {
            $stmt = $conn->prepare('SELECT board_id FROM past_paper_exam_boards WHERE board_id = ? LIMIT 1');
            $stmt->bind_param('s', $metadata['exam_board_id']);
            $stmt->execute();
            $board = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $syllabus = $metadata['syllabus_id'] !== '' ? mmh_past_syllabus($conn, $metadata['syllabus_id']) : null;
        if (!$board || !$syllabus || (string) $syllabus['exam_board_id'] !== $metadata['exam_board_id'] || $metadata['year'] < 1900 || $metadata['year'] > 2100 || $metadata['paper_number'] === '' || $metadata['variant'] === '') {
            return [false, 'Choose a valid Board and Syllabus, and provide Year, Session, Paper, Variant, and document type.', []];
        }
        return [true, '', ['metadata' => $metadata, 'status' => $status]];
    }
}

if (!function_exists('mmh_past_drive_save_candidate_correction')) {
    function mmh_past_drive_save_candidate_correction(mysqli $conn, $jobId, $candidateId, array $input, $admin)
    {
        mmh_past_drive_ensure_schema($conn);
        $job = mmh_past_drive_job($conn, $jobId);
        $candidate = $job ? mmh_past_drive_candidate($conn, $job['job_id'], $candidateId) : null;
        if (!$job || !$candidate || in_array((string) ($candidate['result_status'] ?? ''), ['created', 'updated'], true)) {
            return [false, 'This candidate is no longer available for correction.', []];
        }
        $allowed = ['exam_board_id', 'syllabus_id', 'year', 'exam_session', 'paper_number', 'variant', 'resource_type', 'custom_type', 'tier', 'publish_state'];
        $correction = [];
        foreach ($allowed as $field) if (array_key_exists($field, $input) && !is_array($input[$field])) $correction[$field] = trim((string) $input[$field]);
        [$valid, $message] = mmh_past_drive_valid_import_metadata($conn, $candidate, $correction);
        if (!$valid) return [false, $message, []];
        $json = json_encode($correction, JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare("UPDATE past_paper_drive_candidates SET correction_json = ?, proposed_action = 'create', result_status = NULL, warning_message = NULL, result_message = NULL, failure_code = NULL, failure_context_json = NULL, failed_at = NULL WHERE candidate_id = ? AND job_id = ?");
        $stmt->bind_param('sss', $json, $candidate['candidate_id'], $job['job_id']);
        if (!$stmt->execute()) { $stmt->close(); return [false, 'Unable to save this candidate correction.', []]; }
        $stmt->close();
        $summary = json_decode((string) ($job['summary_json'] ?? ''), true) ?: [];
        $state = is_array($summary['import_state'] ?? null) ? $summary['import_state'] : [];
        if (is_array($state['progress'] ?? null)) {
            $state['progress']['eligible'] = (int) ($state['progress']['eligible'] ?? 0) + 1;
            $state['progress']['pending'] = (int) ($state['progress']['pending'] ?? 0) + 1;
            $state['progress']['manual_review'] = max(0, (int) ($state['progress']['manual_review'] ?? 0) - 1);
            $state['progress']['percent'] = $state['progress']['eligible'] > 0 ? min(100, (int) floor((((int) ($state['progress']['completed'] ?? 0) + (int) ($state['progress']['failed'] ?? 0)) / $state['progress']['eligible']) * 100)) : 100;
        }
        $state['last_candidate_db_id'] = min((int) ($state['last_candidate_db_id'] ?? 0), max(0, (int) $candidate['id'] - 1));
        $state['updated_at'] = date('c');
        $state['updated_by'] = $admin;
        $summary['import_state'] = $state;
        $summaryJson = json_encode($summary, JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare("UPDATE past_paper_drive_jobs SET status = 'importing', summary_json = ?, error_message = NULL, completed_at = NULL WHERE job_id = ?");
        $stmt->bind_param('ss', $summaryJson, $job['job_id']);
        $stmt->execute();
        $stmt->close();
        return [true, 'Candidate correction saved and queued for the next import batch.', ['candidate_id' => $candidate['candidate_id']]];
    }
}

if (!function_exists('mmh_past_drive_update_resource_source')) {
    function mmh_past_drive_update_resource_source(mysqli $conn, $resourceId, $sourceId, array $candidate, $admin)
    {
        $url = mmh_past_drive_drive_url($candidate['drive_file_id'], $candidate['mime_type'] ?? '');
        $stmt = $conn->prepare("UPDATE past_paper_resources SET storage_type = 'url', external_url = ?, original_filename = ?, mime_type = ?, file_size = ?, drive_source_id = ?, drive_file_id = ?, drive_fingerprint = ?, drive_modified_at = ?, drive_source_path = ?, drive_source_status = 'available', drive_imported_at = NOW(), drive_imported_by = ? WHERE resource_id = ?");
        $stmt->bind_param('sssisssssss', $url, $candidate['file_name'], $candidate['mime_type'], $candidate['file_size'], $sourceId, $candidate['drive_file_id'], $candidate['source_fingerprint'], $candidate['modified_at'], $candidate['source_path'], $admin, $resourceId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mmh_past_drive_import_one')) {
    function mmh_past_drive_import_one(mysqli $conn, array $candidate, array $correction, $admin)
    {
        // The completed scan already captured and validated this immutable
        // Drive snapshot. Revalidating every candidate here created N blocking
        // cURL calls per form submission and caused the line-280 timeout.
        // A later Drive sync remains responsible for source freshness.
        if (empty($candidate['drive_file_id']) || empty($candidate['source_fingerprint'])) {
            return [false, 'This scan candidate has no valid Drive snapshot. Run a fresh scan before importing it.', []];
        }
        $snapshot = ['mimeType' => (string) ($candidate['mime_type'] ?? '')];
        [$supported] = mmh_past_drive_supported_file($snapshot);
        if (!$supported) {
            return [false, 'This scan candidate is no longer a supported PDF or Google Workspace document.', []];
        }
        $existing = mmh_past_drive_existing_resource($conn, $candidate['drive_file_id']);
        $fresh = $candidate;
        $sourceId = $candidate['source_id'];
        if ($existing) {
            if (!mmh_past_drive_update_resource_source($conn, $existing['resource_id'], $sourceId, $fresh, $admin)) {
                return [false, 'Unable to update the existing Drive resource.', []];
            }
            return [true, 'Updated existing Drive resource.', ['action' => 'updated', 'paper_id' => $existing['paper_id'], 'resource_id' => $existing['resource_id']]];
        }
        [$valid, $message, $effective] = mmh_past_drive_valid_import_metadata($conn, $candidate, $correction);
        if (!$valid) {
            return [false, $message, []];
        }
        $metadata = $effective['metadata'];
        $paper = mmh_past_drive_existing_paper($conn, $metadata);
        if (!$paper) {
            [$paperOk, $paperMessage, $paperData] = array_pad(mmh_past_save_paper($conn, [
                'exam_board_id' => $metadata['exam_board_id'],
                'syllabus_id' => $metadata['syllabus_id'],
                'year' => $metadata['year'],
                'exam_session' => $metadata['exam_session'],
                'paper_number' => $metadata['paper_number'],
                'variant' => $metadata['variant'],
                'tier' => $metadata['tier'],
                'short_title' => $metadata['year'] . ' ' . $metadata['exam_session'] . ' ' . $metadata['paper_number'] . ' ' . $metadata['variant'],
                'status' => $effective['status'],
            ]), 3, []);
            if (!$paperOk) {
                return [false, $paperMessage, []];
            }
            $paper = mmh_past_paper($conn, $paperData['paper_id']);
        }
        if (mmh_past_primary_type_exists($conn, $paper['paper_id'], $metadata['resource_type'])) {
            return [false, 'A resource of this type already exists for the target paper. Review it manually to avoid overwriting a teacher-managed resource.', []];
        }
        [$resourceOk, $resourceMessage, $resourceData] = array_pad(mmh_past_save_resource($conn, [
            'paper_id' => $paper['paper_id'],
            'resource_type' => $metadata['resource_type'],
            'custom_type' => $metadata['custom_type'],
            'display_title' => mmh_past_resource_label($metadata['resource_type'], $metadata['custom_type']),
            'storage_type' => 'url',
            'external_url' => mmh_past_drive_drive_url($candidate['drive_file_id'], $fresh['mime_type'] ?? ''),
            'access_level' => mmh_past_default_access($metadata['resource_type']),
            'unlock_rule' => 'immediate',
            'status' => $effective['status'],
            'download_allowed' => 1,
            'preview_allowed' => 1,
        ], []), 3, []);
        if (!$resourceOk) {
            return [false, $resourceMessage, []];
        }
        if (!mmh_past_drive_update_resource_source($conn, $resourceData['resource_id'], $sourceId, $fresh, $admin)) {
            return [false, 'Resource was created but Drive source metadata could not be saved.', []];
        }
        return [true, 'Created and paired the Drive resource.', ['action' => 'created', 'paper_id' => $paper['paper_id'], 'resource_id' => $resourceData['resource_id']]];
    }
}

if (!function_exists('mmh_past_drive_import_progress')) {
    function mmh_past_drive_import_progress(mysqli $conn, $jobId)
    {
        $stmt = $conn->prepare("SELECT
            SUM(CASE WHEN proposed_action IN ('create','update') THEN 1 ELSE 0 END) AS eligible,
            SUM(CASE WHEN result_status IN ('created','updated') THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN result_status = 'failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN proposed_action IN ('create','update') AND result_status IS NULL THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN proposed_action = 'manual_review' AND result_status IS NULL THEN 1 ELSE 0 END) AS manual_review
            FROM past_paper_drive_candidates WHERE job_id = ?");
        $stmt->bind_param('s', $jobId);
        $stmt->execute();
        $progress = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        foreach (['eligible', 'completed', 'failed', 'pending', 'manual_review'] as $field) $progress[$field] = (int) ($progress[$field] ?? 0);
        $handled = $progress['completed'] + $progress['failed'];
        $progress['percent'] = $progress['eligible'] > 0 ? min(100, (int) floor(($handled / $progress['eligible']) * 100)) : 100;
        return $progress;
    }
}

if (!function_exists('mmh_past_drive_pending_import_candidates')) {
    function mmh_past_drive_pending_import_candidates(mysqli $conn, $jobId, $afterId = 0, $limit = 50)
    {
        $limit = max(1, min(50, (int) $limit));
        $afterId = max(0, (int) $afterId);
        $stmt = $conn->prepare("SELECT * FROM past_paper_drive_candidates FORCE INDEX (idx_drive_candidate_cursor)
            WHERE job_id = ? AND id > ? AND proposed_action IN ('create','update') AND result_status IS NULL
            ORDER BY id ASC LIMIT ?");
        $stmt->bind_param('sii', $jobId, $afterId, $limit);
        return mmh_past_fetch_all($stmt);
    }
}

if (!function_exists('mmh_past_drive_store_import_progress')) {
    function mmh_past_drive_store_import_progress(mysqli $conn, array $job, array $batch, array $progress, $admin)
    {
        $summary = json_decode((string) ($job['summary_json'] ?? ''), true);
        $summary = is_array($summary) ? $summary : [];
        $state = is_array($summary['import_state'] ?? null) ? $summary['import_state'] : [];
        $state['batches_completed'] = (int) ($state['batches_completed'] ?? 0) + 1;
        $state['last_batch'] = $batch;
        $state['last_candidate_db_id'] = max((int) ($state['last_candidate_db_id'] ?? 0), (int) ($batch['last_candidate_db_id'] ?? 0));
        $state['progress'] = $progress;
        $state['updated_at'] = date('c');
        $state['updated_by'] = $admin;
        $summary['import_state'] = $state;
        $summary['import_result'] = $progress;
        $finished = $progress['pending'] === 0;
        $status = $finished ? 'imported' : 'importing';
        $summaryJson = json_encode($summary, JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare('UPDATE past_paper_drive_jobs SET status = ?, summary_json = ?, error_message = NULL, completed_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END WHERE job_id = ?');
        $finishedInt = $finished ? 1 : 0;
        $stmt->bind_param('ssis', $status, $summaryJson, $finishedInt, $job['job_id']);
        $stmt->execute();
        $stmt->close();
        return $finished;
    }
}

if (!function_exists('mmh_past_drive_failure_details')) {
    function mmh_past_drive_failure_details(mysqli $conn, array $candidate, $message = '')
    {
        $message = mmh_past_clean($message, 500);
        $lower = strtolower($message);
        $code = 'unexpected_exception';
        if (str_contains($lower, 'choose a valid board') || str_contains($lower, 'provide year')) {
            $code = 'missing_required_metadata';
        } elseif (str_contains($lower, 'resource of this type already exists') || str_contains($lower, 'already imported')) {
            $code = 'duplicate_conflict';
        } elseif (str_contains($lower, 'supported pdf') || str_contains($lower, 'supported google workspace')) {
            $code = 'unsupported_document_type';
        } elseif (str_contains($lower, 'unable to update the existing drive resource')) {
            $code = 'database_update_failed';
        } elseif (str_contains($lower, 'resource was created but')) {
            $code = 'resource_link_failed';
        } elseif (str_contains($lower, 'unable to save') || str_contains($lower, 'unable to create')) {
            $code = 'database_insert_failed';
        } elseif (str_contains($lower, 'drive snapshot') || str_contains($lower, 'source')) {
            $code = 'source_unavailable';
        }
        $context = json_encode([
            'operation' => 'import_batch',
            'proposed_action' => (string) ($candidate['proposed_action'] ?? ''),
            'resource_type' => (string) (mmh_past_drive_read_metadata($candidate)['resource_type'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);
        return [$code, $message ?: 'The import could not complete. Review this candidate and retry after correcting its metadata.', $context ?: '{}'];
    }
}

if (!function_exists('mmh_past_drive_backfill_failure_details')) {
    function mmh_past_drive_backfill_failure_details(mysqli $conn, $jobId)
    {
        mmh_past_drive_ensure_schema($conn);
        $jobId = mmh_past_identifier($jobId, 40);
        if (!$jobId) return 0;
        $stmt = $conn->prepare("SELECT * FROM past_paper_drive_candidates WHERE job_id = ? AND result_status = 'failed' AND (failure_code IS NULL OR failure_code = '')");
        $stmt->bind_param('s', $jobId);
        $rows = mmh_past_fetch_all($stmt);
        if (!$rows) return 0;
        $update = $conn->prepare('UPDATE past_paper_drive_candidates SET result_message = ?, failure_code = ?, failure_context_json = ?, failed_at = COALESCE(failed_at, updated_at, NOW()) WHERE candidate_id = ? AND job_id = ?');
        $updated = 0;
        foreach ($rows as $candidate) {
            $correction = json_decode((string) ($candidate['correction_json'] ?? ''), true);
            $correction = is_array($correction) ? $correction : [];
            [$valid, $message, $effective] = mmh_past_drive_valid_import_metadata($conn, $candidate, $correction);
            if (!$valid) {
                [$code, $reason, $context] = mmh_past_drive_failure_details($conn, $candidate, $message);
            } else {
                $metadata = $effective['metadata'];
                $paper = mmh_past_drive_existing_paper($conn, $metadata);
                $reason = ($paper && mmh_past_primary_type_exists($conn, $paper['paper_id'], $metadata['resource_type']))
                    ? 'A resource of this type already exists for the target paper. Review it before retrying.'
                    : 'The import did not complete. Review the candidate metadata and retry it individually.';
                [$code, $reason, $context] = mmh_past_drive_failure_details($conn, $candidate, $reason);
            }
            $update->bind_param('sssss', $reason, $code, $context, $candidate['candidate_id'], $jobId);
            if ($update->execute()) $updated++;
        }
        $update->close();
        return $updated;
    }
}

if (!function_exists('mmh_past_drive_publish_created_records')) {
    function mmh_past_drive_publish_created_records(mysqli $conn, $jobId)
    {
        mmh_past_drive_ensure_schema($conn);
        $jobId = mmh_past_identifier($jobId, 40);
        if (!$jobId) return [false, 'Choose a valid import job.', []];
        $conn->begin_transaction();
        try {
            // Only recover records created by this importer job, and only where
            // their already-linked board and syllabus are public-ready.
            $resources = $conn->prepare("UPDATE past_paper_resources r
                INNER JOIN past_paper_drive_candidates c ON c.linked_resource_id = r.resource_id AND c.job_id = ? AND c.result_status = 'created'
                INNER JOIN past_papers p ON p.paper_id = r.paper_id
                INNER JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id AND b.status = 'published'
                INNER JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id AND s.status = 'published'
                SET r.status = 'published'
                WHERE r.status = 'draft'");
            $resources->bind_param('s', $jobId);
            $resources->execute();
            $resourceCount = $resources->affected_rows;
            $resources->close();
            $papers = $conn->prepare("UPDATE past_papers p
                INNER JOIN past_paper_drive_candidates c ON c.linked_paper_id = p.paper_id AND c.job_id = ? AND c.result_status = 'created'
                INNER JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id AND b.status = 'published'
                INNER JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id AND s.status = 'published'
                SET p.status = 'published'
                WHERE p.status = 'draft'");
            $papers->bind_param('s', $jobId);
            $papers->execute();
            $paperCount = $papers->affected_rows;
            $papers->close();
            $conn->commit();
            return [true, sprintf('Published %d imported paper records and %d imported resources.', $paperCount, $resourceCount), ['papers' => $paperCount, 'resources' => $resourceCount]];
        } catch (Throwable $error) {
            $conn->rollback();
            return [false, 'Unable to publish the successful imported records. No visibility fields were changed.', []];
        }
    }
}

if (!function_exists('mmh_past_drive_import_batch')) {
    function mmh_past_drive_import_batch(mysqli $conn, $jobId, $admin, $limit = 50)
    {
        mmh_past_drive_ensure_schema($conn);
        $jobId = mmh_past_identifier($jobId, 40);
        $job = $jobId ? mmh_past_drive_job($conn, $jobId) : null;
        if (!$job || !in_array((string) $job['status'], ['completed', 'importing', 'imported'], true)) {
            return [false, 'Choose a completed Drive scan before importing.', []];
        }
        $summary = json_decode((string) ($job['summary_json'] ?? ''), true);
        $summary = is_array($summary) ? $summary : [];
        $state = is_array($summary['import_state'] ?? null) ? $summary['import_state'] : [];
        $cursor = max(0, (int) ($state['last_candidate_db_id'] ?? 0));
        $progress = is_array($state['progress'] ?? null) ? $state['progress'] : mmh_past_drive_import_progress($conn, $job['job_id']);
        foreach (['eligible', 'completed', 'failed', 'pending', 'manual_review', 'percent'] as $field) $progress[$field] = (int) ($progress[$field] ?? 0);
        $candidates = mmh_past_drive_pending_import_candidates($conn, $job['job_id'], $cursor, $limit);
        if (!$candidates) {
            return [true, 'No automatic candidates remain. Manual-review items stay queued for individual correction.', ['batch_id' => '', 'processed' => 0, 'progress' => $progress, 'completed' => $progress['pending'] === 0]];
        }
        $batchId = mmh_past_id('importbatch');
        $results = ['batch_id' => $batchId, 'processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'messages' => [], 'last_candidate_db_id' => $cursor];
        $update = $conn->prepare('UPDATE past_paper_drive_candidates SET result_status = ?, linked_paper_id = ?, linked_resource_id = ?, result_message = ?, failure_code = ?, failure_context_json = ?, failed_at = IF(? = \'failed\', NOW(), NULL) WHERE candidate_id = ? AND job_id = ?');
        $conn->begin_transaction();
        try {
            foreach ($candidates as $index => $candidate) {
                $savepoint = 'drive_candidate_' . $index;
                $conn->query('SAVEPOINT ' . $savepoint);
                $correction = json_decode((string) ($candidate['correction_json'] ?? ''), true);
                $correction = is_array($correction) ? $correction : [];
                $status = 'failed';
                $paperId = $resourceId = '';
                $resultMessage = '';
                $failureCode = $failureContext = null;
                try {
                    [$ok, $message, $data] = mmh_past_drive_import_one($conn, $candidate, $correction, $admin);
                    if (!$ok) throw new RuntimeException($message);
                    $status = (string) $data['action'];
                    $paperId = (string) ($data['paper_id'] ?? '');
                    $resourceId = (string) ($data['resource_id'] ?? '');
                    $resultMessage = mmh_past_clean($message, 500);
                    $results[$status] = ($results[$status] ?? 0) + 1;
                } catch (Throwable $error) {
                    $conn->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $results['failed']++;
                    [$failureCode, $resultMessage, $failureContext] = mmh_past_drive_failure_details($conn, $candidate, $error->getMessage());
                    if (count($results['messages']) < 5) $results['messages'][] = $candidate['file_name'] . ': ' . mmh_past_clean($resultMessage, 220);
                }
                $statusForTimestamp = $status;
                $update->bind_param('sssssssss', $status, $paperId, $resourceId, $resultMessage, $failureCode, $failureContext, $statusForTimestamp, $candidate['candidate_id'], $job['job_id']);
                if (!$update->execute()) throw new RuntimeException('Unable to persist batch progress.');
                $results['processed']++;
                $results['last_candidate_db_id'] = max((int) $results['last_candidate_db_id'], (int) $candidate['id']);
            }
            $update->close();
            $conn->commit();
        } catch (Throwable $error) {
            if ($update) $update->close();
            $conn->rollback();
            return [false, 'The import batch rolled back safely. No records from this batch were imported.', []];
        }
        $progress['completed'] += (int) $results['created'] + (int) $results['updated'];
        $progress['failed'] += (int) $results['failed'];
        $progress['pending'] = max(0, (int) $progress['pending'] - (int) $results['processed']);
        $handled = (int) $progress['completed'] + (int) $progress['failed'];
        $progress['percent'] = $progress['eligible'] > 0 ? min(100, (int) floor(($handled / $progress['eligible']) * 100)) : 100;
        $completed = mmh_past_drive_store_import_progress($conn, $job, $results, $progress, $admin);
        $results['progress'] = $progress;
        $results['completed'] = $completed;
        $message = sprintf('Processed batch of %d: %d created, %d updated, %d failed. %d automatic candidates remain.', $results['processed'], $results['created'], $results['updated'], $results['failed'], $progress['pending']);
        return [true, $message, $results];
    }
}

if (!function_exists('mmh_past_drive_import_job')) {
    // Backward-compatible entry point: browser payload is intentionally ignored.
    // The database queue, not POST arrays, selects the next bounded batch.
    function mmh_past_drive_import_job(mysqli $conn, $jobId, array $postedCandidates, $admin)
    {
        return mmh_past_drive_import_batch($conn, $jobId, $admin, 50);
    }
}

