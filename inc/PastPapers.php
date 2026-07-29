<?php
/**
 * Past Papers Center Phase 1.
 *
 * Additive module only: normalized exam-board/syllabus/paper/resource storage,
 * server-side access control, safe uploads/links, and reusable helpers for the
 * future public/student frontends. Nothing here modifies Course Builder,
 * Assignments, Exams, Learning Rules, or the student renderer.
 */

require_once __DIR__ . '/learning_schema.php';

if (!function_exists('mmh_past_response')) {
    function mmh_past_response($success, $message, array $data = [], $statusCode = 200)
    {
        if (!headers_sent()) {
            http_response_code((int) $statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array_merge([
            'success' => (bool) $success,
            'status' => $success ? 1 : 0,
            'message' => $message,
        ], $data));
        exit;
    }
}

if (!function_exists('mmh_past_flash')) {
    function mmh_past_flash($type, $message)
    {
        $_SESSION['past_papers_flash'] = [
            'type' => $type === 'success' ? 'success' : 'error',
            'message' => (string) $message,
        ];
    }
}

if (!function_exists('mmh_past_take_flash')) {
    function mmh_past_take_flash()
    {
        $flash = $_SESSION['past_papers_flash'] ?? null;
        unset($_SESSION['past_papers_flash']);
        return is_array($flash) ? $flash : null;
    }
}

if (!function_exists('mmh_past_id')) {
    function mmh_past_id($prefix)
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('mmh_past_clean')) {
    function mmh_past_clean($value, $maxLength = 0)
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($maxLength > 0 && function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return $maxLength > 0 ? substr($value, 0, $maxLength) : $value;
    }
}

if (!function_exists('mmh_past_identifier')) {
    function mmh_past_identifier($value, $maxLength = 80)
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > (int) $maxLength || !preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            return null;
        }
        return $value;
    }
}

if (!function_exists('mmh_past_status')) {
    function mmh_past_status($value, $fallback = 'draft')
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['published', 'draft', 'inactive'], true) ? $value : $fallback;
    }
}

if (!function_exists('mmh_past_session')) {
    function mmh_past_session($value)
    {
        $value = trim((string) $value);
        $allowed = ['January', 'February/March', 'May/June', 'October/November', 'Custom'];
        return in_array($value, $allowed, true) ? $value : 'Custom';
    }
}

if (!function_exists('mmh_past_access_level')) {
    function mmh_past_access_level($value)
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['public', 'logged_in', 'enrolled_course', 'selected_courses', 'selected_students', 'admin_only'];
        return in_array($value, $allowed, true) ? $value : 'public';
    }
}

if (!function_exists('mmh_past_unlock_rule')) {
    function mmh_past_unlock_rule($value)
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['immediate', 'specific_datetime', 'manual', 'after_question_opened', 'after_homework_submission', 'after_teacher_approval'];
        return in_array($value, $allowed, true) ? $value : 'immediate';
    }
}

if (!function_exists('mmh_past_resource_type')) {
    function mmh_past_resource_type($value)
    {
        // `solution_video` is the established persisted value. Accept the new
        // public/admin name `video_solution` without rewriting existing rows.
        $value = strtolower(trim((string) $value));
        $value = $value === 'video_solution' ? 'solution_video' : $value;
        $allowed = ['question_paper', 'mark_scheme', 'model_answer', 'solution_video', 'examiner_report', 'insert', 'formula_sheet', 'data_booklet', 'grade_boundaries', 'source_booklet', 'pre_release_material', 'custom'];
        return in_array($value, $allowed, true) ? $value : 'custom';
    }
}

if (!function_exists('mmh_past_resource_types')) {
    function mmh_past_resource_types()
    {
        return ['question_paper', 'mark_scheme', 'model_answer', 'solution_video', 'examiner_report', 'grade_boundaries', 'insert', 'formula_sheet', 'data_booklet', 'source_booklet', 'pre_release_material', 'custom'];
    }
}

if (!function_exists('mmh_past_resource_label')) {
    function mmh_past_resource_label($type, $custom = '')
    {
        $labels = [
            'question_paper' => 'Question Paper',
            'mark_scheme' => 'Mark Scheme',
            'model_answer' => 'Model Answer',
            'solution_video' => 'Video Solution',
            'examiner_report' => 'Examiner Report',
            'insert' => 'Insert',
            'formula_sheet' => 'Formula Sheet',
            'data_booklet' => 'Data Booklet',
            'grade_boundaries' => 'Grade Boundaries',
            'source_booklet' => 'Source Booklet',
            'pre_release_material' => 'Pre-release Material',
            'custom' => mmh_past_clean($custom, 80) ?: 'Additional Resource',
        ];
        return $labels[mmh_past_resource_type($type)] ?? 'Additional Resource';
    }
}

if (!function_exists('mmh_past_default_access')) {
    function mmh_past_default_access($type)
    {
        $type = mmh_past_resource_type($type);
        if (in_array($type, ['model_answer', 'solution_video'], true)) {
            return 'enrolled_course';
        }
        return 'public';
    }
}

if (!function_exists('mmh_past_ensure_schema')) {
    function mmh_past_ensure_schema(mysqli $conn)
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        if (!mmh_table_exists($conn, 'past_paper_exam_boards')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `past_paper_exam_boards` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `board_id` VARCHAR(40) NOT NULL,
                `name` VARCHAR(120) NOT NULL,
                `code` VARCHAR(60) NULL,
                `description` TEXT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'published',
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_board_id` (`board_id`),
                UNIQUE KEY `uniq_board_name` (`name`),
                KEY `idx_status_sort` (`status`, `sort_order`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'past_paper_syllabuses')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `past_paper_syllabuses` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `syllabus_id` VARCHAR(40) NOT NULL,
                `exam_board_id` VARCHAR(40) NOT NULL,
                `syllabus_code` VARCHAR(80) NOT NULL,
                `public_title` VARCHAR(190) NOT NULL,
                `internal_title` VARCHAR(190) NULL,
                `description` TEXT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'published',
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `course_id` VARCHAR(40) NULL,
                `thumbnail_path` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_syllabus_id` (`syllabus_id`),
                UNIQUE KEY `uniq_board_code` (`exam_board_id`, `syllabus_code`),
                KEY `idx_board_status_sort` (`exam_board_id`, `status`, `sort_order`),
                KEY `idx_course` (`course_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'past_papers')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `past_papers` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `paper_id` VARCHAR(40) NOT NULL,
                `exam_board_id` VARCHAR(40) NOT NULL,
                `syllabus_id` VARCHAR(40) NOT NULL,
                `course_id` VARCHAR(40) NULL,
                `year` SMALLINT UNSIGNED NOT NULL,
                `exam_session` VARCHAR(40) NOT NULL,
                `custom_session` VARCHAR(80) NULL,
                `paper_number` VARCHAR(80) NOT NULL,
                `variant` VARCHAR(80) NOT NULL,
                `qualification_level` VARCHAR(80) NULL,
                `tier` VARCHAR(80) NULL,
                `region` VARCHAR(80) NULL,
                `calculator_mode` VARCHAR(40) NULL,
                `maximum_marks` INT UNSIGNED NULL,
                `duration_minutes` INT UNSIGNED NULL,
                `paper_date` DATE NULL,
                `short_title` VARCHAR(190) NULL,
                `description` TEXT NULL,
                `primary_topic_id` INT UNSIGNED NULL,
                `additional_topic_ids` TEXT NULL,
                `keywords` TEXT NULL,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_paper_id` (`paper_id`),
                KEY `idx_filter` (`exam_board_id`, `syllabus_id`, `year`, `exam_session`, `status`),
                KEY `idx_course_status` (`course_id`, `status`),
                KEY `idx_topic` (`primary_topic_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'past_paper_resources')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `past_paper_resources` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` VARCHAR(40) NOT NULL,
                `paper_id` VARCHAR(40) NOT NULL,
                `resource_type` VARCHAR(40) NOT NULL,
                `custom_type` VARCHAR(80) NULL,
                `display_title` VARCHAR(190) NOT NULL,
                `storage_type` VARCHAR(20) NOT NULL DEFAULT 'file',
                `file_path` VARCHAR(255) NULL,
                `original_filename` VARCHAR(190) NULL,
                `mime_type` VARCHAR(120) NULL,
                `file_size` BIGINT UNSIGNED NULL,
                `external_url` TEXT NULL,
                `description` TEXT NULL,
                `access_level` VARCHAR(40) NOT NULL DEFAULT 'public',
                `unlock_rule` VARCHAR(60) NOT NULL DEFAULT 'immediate',
                `unlock_at` DATETIME NULL,
                `manual_unlocked` TINYINT(1) NOT NULL DEFAULT 1,
                `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `download_allowed` TINYINT(1) NOT NULL DEFAULT 1,
                `preview_allowed` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resource_id` (`resource_id`),
                KEY `idx_paper_status_sort` (`paper_id`, `status`, `sort_order`),
                KEY `idx_access` (`access_level`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'past_paper_resource_courses')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `past_paper_resource_courses` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` VARCHAR(40) NOT NULL,
                `course_id` VARCHAR(40) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resource_course` (`resource_id`, `course_id`),
                KEY `idx_course` (`course_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'past_paper_resource_students')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `past_paper_resource_students` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` VARCHAR(40) NOT NULL,
                `user_id` INT NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resource_student` (`resource_id`, `user_id`),
                KEY `idx_student` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        // Drive-import source metadata is additive. Existing uploaded and
        // manually-created resources remain unchanged until imported.
        mmh_add_column_if_missing($conn, 'past_paper_resources', 'drive_source_id', "VARCHAR(40) NULL");
        mmh_add_column_if_missing($conn, 'past_paper_resources', 'drive_file_id', "VARCHAR(128) NULL");
        mmh_add_column_if_missing($conn, 'past_paper_resources', 'drive_fingerprint', "VARCHAR(160) NULL");
        mmh_add_column_if_missing($conn, 'past_paper_resources', 'drive_modified_at', "DATETIME NULL");
        mmh_add_column_if_missing($conn, 'past_paper_resources', 'drive_source_path', "TEXT NULL");
        mmh_add_column_if_missing($conn, 'past_paper_resources', 'drive_source_status', "VARCHAR(20) NOT NULL DEFAULT 'available'");
        mmh_add_column_if_missing($conn, 'past_paper_resources', 'drive_imported_at', "DATETIME NULL");
        mmh_add_column_if_missing($conn, 'past_paper_resources', 'drive_imported_by', "VARCHAR(190) NULL");
        mmh_add_index_if_missing($conn, 'past_paper_resources', 'idx_drive_source_status', '`drive_source_id`, `drive_source_status`');
        mmh_add_index_if_missing($conn, 'past_paper_resources', 'idx_drive_file', '`drive_file_id`', true);

        foreach (['past_paper_exam_boards', 'past_paper_syllabuses', 'past_papers', 'past_paper_resources', 'past_paper_resource_courses', 'past_paper_resource_students'] as $table) {
            $conn->query("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci");
        }
    }
}

if (!function_exists('mmh_past_fetch_all')) {
    function mmh_past_fetch_all(mysqli_stmt $stmt)
    {
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mmh_past_exam_boards')) {
    function mmh_past_exam_boards(mysqli $conn, $activeOnly = false)
    {
        mmh_past_ensure_schema($conn);
        $sql = 'SELECT * FROM past_paper_exam_boards';
        if ($activeOnly) {
            $sql .= " WHERE status = 'published'";
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC';
        $result = $conn->query($sql);
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('mmh_past_syllabuses')) {
    function mmh_past_syllabuses(mysqli $conn, $boardId = '', $activeOnly = false)
    {
        mmh_past_ensure_schema($conn);
        $where = [];
        $params = [];
        $types = '';
        if ($boardId !== '') {
            $where[] = 's.exam_board_id = ?';
            $params[] = $boardId;
            $types .= 's';
        }
        if ($activeOnly) {
            $where[] = "s.status = 'published'";
        }
        $sql = 'SELECT s.*, b.name AS board_name FROM past_paper_syllabuses s LEFT JOIN past_paper_exam_boards b ON b.board_id = s.exam_board_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY b.sort_order ASC, s.sort_order ASC, s.public_title ASC, s.id ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        return mmh_past_fetch_all($stmt);
    }
}

if (!function_exists('mmh_past_courses')) {
    function mmh_past_courses(mysqli $conn)
    {
        $rows = [];
        $result = $conn->query("SELECT course_id, course_title FROM courses ORDER BY course_title ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('mmh_past_students')) {
    function mmh_past_students(mysqli $conn, $limit = 500)
    {
        $limit = max(1, min(1000, (int) $limit));
        $rows = [];
        $result = $conn->query("SELECT user_id, username, full_name FROM users WHERE role = 'user' AND status = '1' ORDER BY full_name ASC, username ASC LIMIT {$limit}");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('mmh_past_save_board')) {
    function mmh_past_save_board(mysqli $conn, array $data)
    {
        mmh_past_ensure_schema($conn);
        $boardId = mmh_past_identifier($data['board_id'] ?? '', 40);
        $name = mmh_past_clean($data['name'] ?? '', 120);
        if ($name === '') {
            return [false, 'Exam Board name is required.'];
        }
        $code = mmh_past_clean($data['code'] ?? '', 60);
        $description = mmh_past_clean($data['description'] ?? '', 4000);
        $status = mmh_past_status($data['status'] ?? 'published', 'published');
        $sort = max(0, (int) ($data['sort_order'] ?? 0));
        if ($boardId) {
            $stmt = $conn->prepare('UPDATE past_paper_exam_boards SET name=?, code=?, description=?, status=?, sort_order=? WHERE board_id=?');
            $stmt->bind_param('ssssis', $name, $code, $description, $status, $sort, $boardId);
        } else {
            $boardId = mmh_past_id('board');
            $stmt = $conn->prepare('INSERT INTO past_paper_exam_boards (board_id, name, code, description, status, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssi', $boardId, $name, $code, $description, $status, $sort);
        }
        if (!$stmt || !$stmt->execute()) {
            $message = $conn->errno === 1062 ? 'An Exam Board with this name already exists.' : 'Unable to save Exam Board.';
            if ($stmt) {
                $stmt->close();
            }
            return [false, $message];
        }
        $stmt->close();
        return [true, 'Exam Board saved successfully.', ['board_id' => $boardId]];
    }
}

if (!function_exists('mmh_past_save_syllabus')) {
    function mmh_past_save_syllabus(mysqli $conn, array $data)
    {
        mmh_past_ensure_schema($conn);
        $syllabusId = mmh_past_identifier($data['syllabus_id'] ?? '', 40);
        $boardId = mmh_past_identifier($data['exam_board_id'] ?? '', 40);
        $code = mmh_past_clean($data['syllabus_code'] ?? '', 80);
        $title = mmh_past_clean($data['public_title'] ?? '', 190);
        if (!$boardId || $code === '' || $title === '') {
            return [false, 'Exam Board, syllabus code, and public title are required.'];
        }
        $internal = mmh_past_clean($data['internal_title'] ?? '', 190);
        $description = mmh_past_clean($data['description'] ?? '', 4000);
        $status = mmh_past_status($data['status'] ?? 'published', 'published');
        $sort = max(0, (int) ($data['sort_order'] ?? 0));
        $courseId = mmh_past_identifier($data['course_id'] ?? '', 40) ?: null;
        $thumbnail = mmh_past_clean($data['thumbnail_path'] ?? '', 255);
        if ($syllabusId) {
            $stmt = $conn->prepare('UPDATE past_paper_syllabuses SET exam_board_id=?, syllabus_code=?, public_title=?, internal_title=?, description=?, status=?, sort_order=?, course_id=?, thumbnail_path=? WHERE syllabus_id=?');
            $stmt->bind_param('ssssssisss', $boardId, $code, $title, $internal, $description, $status, $sort, $courseId, $thumbnail, $syllabusId);
        } else {
            $syllabusId = mmh_past_id('syllabus');
            $stmt = $conn->prepare('INSERT INTO past_paper_syllabuses (syllabus_id, exam_board_id, syllabus_code, public_title, internal_title, description, status, sort_order, course_id, thumbnail_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssssiss', $syllabusId, $boardId, $code, $title, $internal, $description, $status, $sort, $courseId, $thumbnail);
        }
        if (!$stmt || !$stmt->execute()) {
            $message = $conn->errno === 1062 ? 'This syllabus code already exists for the selected Exam Board.' : 'Unable to save syllabus.';
            if ($stmt) {
                $stmt->close();
            }
            return [false, $message];
        }
        $stmt->close();
        return [true, 'Syllabus saved successfully.', ['syllabus_id' => $syllabusId]];
    }
}

if (!function_exists('mmh_past_syllabus')) {
    function mmh_past_syllabus(mysqli $conn, $syllabusId)
    {
        $syllabusId = mmh_past_identifier($syllabusId, 40);
        if (!$syllabusId) {
            return null;
        }
        $stmt = $conn->prepare('SELECT * FROM past_paper_syllabuses WHERE syllabus_id = ? LIMIT 1');
        $stmt->bind_param('s', $syllabusId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_past_save_paper')) {
    function mmh_past_save_paper(mysqli $conn, array $data)
    {
        mmh_past_ensure_schema($conn);
        $paperId = mmh_past_identifier($data['paper_id'] ?? '', 40);
        $boardId = mmh_past_identifier($data['exam_board_id'] ?? '', 40);
        $syllabusId = mmh_past_identifier($data['syllabus_id'] ?? '', 40);
        $year = (int) ($data['year'] ?? 0);
        $session = mmh_past_session($data['exam_session'] ?? 'Custom');
        $paper = mmh_past_clean($data['paper_number'] ?? '', 80);
        $variant = mmh_past_clean($data['variant'] ?? '', 80);
        if (!$boardId || !$syllabusId || $year < 1900 || $year > 2100 || $paper === '' || $variant === '') {
            return [false, 'Exam Board, Syllabus, Year, Session, Paper, and Variant/Component are required.'];
        }
        $syllabus = mmh_past_syllabus($conn, $syllabusId);
        $courseId = mmh_past_identifier($data['course_id'] ?? '', 40) ?: ($syllabus['course_id'] ?? null);
        $customSession = $session === 'Custom' ? mmh_past_clean($data['custom_session'] ?? '', 80) : '';
        $qualification = mmh_past_clean($data['qualification_level'] ?? '', 80);
        $tier = mmh_past_clean($data['tier'] ?? '', 80);
        $region = mmh_past_clean($data['region'] ?? '', 80);
        $calculator = mmh_past_clean($data['calculator_mode'] ?? '', 40);
        $marks = isset($data['maximum_marks']) && $data['maximum_marks'] !== '' ? max(0, (int) $data['maximum_marks']) : null;
        $duration = isset($data['duration_minutes']) && $data['duration_minutes'] !== '' ? max(0, (int) $data['duration_minutes']) : null;
        $paperDate = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', (string) ($data['paper_date'] ?? '')) ? $data['paper_date'] : null;
        $shortTitle = mmh_past_clean($data['short_title'] ?? '', 190);
        $description = mmh_past_clean($data['description'] ?? '', 6000);
        $primaryTopic = isset($data['primary_topic_id']) && is_numeric($data['primary_topic_id']) ? (int) $data['primary_topic_id'] : null;
        $additionalTopics = is_array($data['additional_topic_ids'] ?? null) ? array_values(array_filter(array_map('intval', $data['additional_topic_ids']))) : [];
        $additionalJson = json_encode($additionalTopics, JSON_UNESCAPED_SLASHES);
        $keywords = mmh_past_clean($data['keywords'] ?? '', 1000);
        $sort = max(0, (int) ($data['sort_order'] ?? 0));
        $status = mmh_past_status($data['status'] ?? 'draft');
        if ($paperId) {
            $stmt = $conn->prepare('UPDATE past_papers SET exam_board_id=?, syllabus_id=?, course_id=?, year=?, exam_session=?, custom_session=?, paper_number=?, variant=?, qualification_level=?, tier=?, region=?, calculator_mode=?, maximum_marks=?, duration_minutes=?, paper_date=?, short_title=?, description=?, primary_topic_id=?, additional_topic_ids=?, keywords=?, sort_order=?, status=? WHERE paper_id=?');
            $stmt->bind_param('sssissssssssiisssississ', $boardId, $syllabusId, $courseId, $year, $session, $customSession, $paper, $variant, $qualification, $tier, $region, $calculator, $marks, $duration, $paperDate, $shortTitle, $description, $primaryTopic, $additionalJson, $keywords, $sort, $status, $paperId);
        } else {
            $paperId = mmh_past_id('paper');
            $stmt = $conn->prepare('INSERT INTO past_papers (paper_id, exam_board_id, syllabus_id, course_id, year, exam_session, custom_session, paper_number, variant, qualification_level, tier, region, calculator_mode, maximum_marks, duration_minutes, paper_date, short_title, description, primary_topic_id, additional_topic_ids, keywords, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssissssssssiisssissis', $paperId, $boardId, $syllabusId, $courseId, $year, $session, $customSession, $paper, $variant, $qualification, $tier, $region, $calculator, $marks, $duration, $paperDate, $shortTitle, $description, $primaryTopic, $additionalJson, $keywords, $sort, $status);
        }
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            return [false, 'Unable to save Past Paper.'];
        }
        $stmt->close();
        return [true, 'Past Paper saved successfully.', ['paper_id' => $paperId]];
    }
}

if (!function_exists('mmh_past_paper')) {
    function mmh_past_paper(mysqli $conn, $paperId)
    {
        $paperId = mmh_past_identifier($paperId, 40);
        if (!$paperId) {
            return null;
        }
        $stmt = $conn->prepare('SELECT p.*, b.name AS board_name, s.public_title AS syllabus_title, s.syllabus_code, s.course_id AS syllabus_course_id FROM past_papers p LEFT JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id LEFT JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id WHERE p.paper_id = ? LIMIT 1');
        $stmt->bind_param('s', $paperId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_past_papers')) {
    function mmh_past_papers(mysqli $conn, array $filters = [], $limit = 50, $offset = 0)
    {
        mmh_past_ensure_schema($conn);
        $where = [];
        $params = [];
        $types = '';
        foreach ([['exam_board_id', 's'], ['syllabus_id', 's'], ['year', 'i'], ['exam_session', 's'], ['paper_number', 's'], ['variant', 's'], ['status', 's']] as $filter) {
            [$key, $type] = $filter;
            if (($filters[$key] ?? '') !== '') {
                $where[] = "p.`{$key}` = ?";
                $params[] = $type === 'i' ? (int) $filters[$key] : (string) $filters[$key];
                $types .= $type;
            }
        }
        $search = mmh_past_clean($filters['search'] ?? '', 120);
        if ($search !== '') {
            $where[] = '(p.short_title LIKE ? OR p.description LIKE ? OR p.paper_number LIKE ? OR p.variant LIKE ? OR p.keywords LIKE ? OR s.public_title LIKE ? OR b.name LIKE ?)';
            $like = '%' . $search . '%';
            for ($i = 0; $i < 7; $i++) {
                $params[] = $like;
                $types .= 's';
            }
        }
        $sql = 'SELECT p.*, b.name AS board_name, s.public_title AS syllabus_title, s.syllabus_code, COUNT(r.id) AS resource_count
            FROM past_papers p
            LEFT JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id
            LEFT JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id
            LEFT JOIN past_paper_resources r ON r.paper_id = p.paper_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY p.id ORDER BY p.year DESC, p.sort_order ASC, p.created_at DESC LIMIT ? OFFSET ?';
        $params[] = max(1, min(100, (int) $limit));
        $params[] = max(0, (int) $offset);
        $types .= 'ii';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        return mmh_past_fetch_all($stmt);
    }
}

if (!function_exists('mmh_past_resource')) {
    function mmh_past_resource(mysqli $conn, $resourceId)
    {
        $resourceId = mmh_past_identifier($resourceId, 40);
        if (!$resourceId) {
            return null;
        }
        $stmt = $conn->prepare('SELECT r.*, p.exam_board_id, p.syllabus_id, p.course_id AS paper_course_id, p.status AS paper_status, p.year, p.exam_session, p.paper_number, p.variant, p.short_title, s.course_id AS syllabus_course_id FROM past_paper_resources r INNER JOIN past_papers p ON p.paper_id = r.paper_id LEFT JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id WHERE r.resource_id = ? LIMIT 1');
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_past_resources')) {
    function mmh_past_resources(mysqli $conn, $paperId, $publishedOnly = false)
    {
        $paperId = mmh_past_identifier($paperId, 40);
        if (!$paperId) {
            return [];
        }
        $sql = 'SELECT * FROM past_paper_resources WHERE paper_id = ?';
        if ($publishedOnly) {
            $sql .= " AND status = 'published'";
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $paperId);
        return mmh_past_fetch_all($stmt);
    }
}

if (!function_exists('mmh_past_normalize_external_url')) {
    function mmh_past_normalize_external_url($url)
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2000 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || !empty($parts['user']) || !empty($parts['pass'])) {
            return null;
        }
        $host = preg_replace('/^www\./', '', $host);
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be'], true)) {
            $videoId = $host === 'youtu.be' ? trim($path, '/') : (string) ($query['v'] ?? '');
            if ($videoId === '' && preg_match('~^/(?:shorts|embed)/([A-Za-z0-9_-]{6,32})~', $path, $match)) {
                $videoId = $match[1];
            }
            if (!preg_match('/^[A-Za-z0-9_-]{6,32}$/', $videoId)) {
                return null;
            }
            return 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
        }
        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true) && preg_match('~/(?:video/)?([0-9]{5,20})(?:/|$)~', $path, $match)) {
            return 'https://vimeo.com/' . $match[1];
        }
        return $url;
    }
}

if (!function_exists('mmh_past_validate_external_url')) {
    function mmh_past_validate_external_url($url)
    {
        return mmh_past_normalize_external_url($url);
    }
}

if (!function_exists('mmh_past_drive_file_id')) {
    function mmh_past_drive_file_id($value)
    {
        $value = trim((string) $value);
        if (preg_match('/\A[A-Za-z0-9_-]{10,128}\z/', $value)) return $value;
        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['drive.google.com', 'www.drive.google.com', 'docs.google.com', 'www.docs.google.com'], true)) return '';
        $path = (string) ($parts['path'] ?? ''); parse_str((string) ($parts['query'] ?? ''), $query);
        if (!empty($query['id']) && preg_match('/\A[A-Za-z0-9_-]{10,128}\z/', (string) $query['id'])) return (string) $query['id'];
        if (preg_match('~/(?:file|document|spreadsheets|presentation)/d/([A-Za-z0-9_-]{10,128})~', $path, $match)) return $match[1];
        return '';
    }
}

if (!function_exists('mmh_past_store_file')) {
    function mmh_past_store_file(array $file)
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return [true, null];
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return [false, 'Upload failed. Please choose a valid file.'];
        }
        $maxSize = 60 * 1024 * 1024;
        if ((int) $file['size'] > $maxSize) {
            return [false, 'Past Paper files must be 60MB or smaller.'];
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $allowedExtensions, true)) {
            return [false, 'Supported files are PDF, JPG, PNG, and WEBP.'];
        }
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $allowedMime = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
            return [false, 'The uploaded file type is not allowed.'];
        }
        $dir = 'uploads/past-papers/' . date('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            return [false, 'Unable to create the Past Papers upload directory.'];
        }
        $safeName = 'past_paper_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $target = $dir . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return [false, 'The uploaded file could not be saved.'];
        }
        return [true, [
            'file_path' => $target,
            'original_filename' => mmh_past_clean($file['name'], 190),
            'mime_type' => $mime ?: ($extension === 'pdf' ? 'application/pdf' : 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension)),
            'file_size' => (int) $file['size'],
        ]];
    }
}

if (!function_exists('mmh_past_primary_type_exists')) {
    function mmh_past_primary_type_exists(mysqli $conn, $paperId, $type, $exceptResourceId = '')
    {
        // Supplemental teacher resources may legitimately have alternatives.
        if (in_array($type, ['custom', 'model_answer', 'solution_video'], true)) {
            return false;
        }
        $sql = 'SELECT resource_id FROM past_paper_resources WHERE paper_id = ? AND resource_type = ?';
        $params = [$paperId, $type];
        $types = 'ss';
        if ($exceptResourceId !== '') {
            $sql .= ' AND resource_id <> ?';
            $params[] = $exceptResourceId;
            $types .= 's';
        }
        $sql .= ' LIMIT 1';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('mmh_past_save_resource')) {
    function mmh_past_save_resource(mysqli $conn, array $data, array $files)
    {
        mmh_past_ensure_schema($conn);
        $resourceId = mmh_past_identifier($data['resource_id'] ?? '', 40);
        $paperId = mmh_past_identifier($data['paper_id'] ?? '', 40);
        if (!$paperId || !mmh_past_paper($conn, $paperId)) {
            return [false, 'Choose a valid Past Paper before adding resources.'];
        }
        $type = mmh_past_resource_type($data['resource_type'] ?? 'custom');
        if (mmh_past_primary_type_exists($conn, $paperId, $type, $resourceId ?: '')) {
            return [false, 'This paper already has a primary ' . mmh_past_resource_label($type) . '. Edit the existing resource or use a custom resource.'];
        }
        $customType = mmh_past_clean($data['custom_type'] ?? '', 80);
        $title = mmh_past_clean($data['display_title'] ?? '', 190) ?: mmh_past_resource_label($type, $customType);
        $description = mmh_past_clean($data['description'] ?? '', 4000);
        $storageType = ($data['storage_type'] ?? 'file') === 'url' ? 'url' : 'file';
        $access = mmh_past_access_level($data['access_level'] ?? mmh_past_default_access($type));
        $unlockRule = mmh_past_unlock_rule($data['unlock_rule'] ?? 'immediate');
        $unlockAt = $unlockRule === 'specific_datetime' && trim((string) ($data['unlock_at'] ?? '')) !== '' ? str_replace('T', ' ', substr((string) $data['unlock_at'], 0, 16)) . ':00' : null;
        $manualUnlocked = $unlockRule === 'manual' ? (int) !empty($data['manual_unlocked']) : 1;
        $status = mmh_past_status($data['status'] ?? 'draft');
        $sort = max(0, (int) ($data['sort_order'] ?? 0));
        $download = !empty($data['download_allowed']) ? 1 : 0;
        $preview = !empty($data['preview_allowed']) ? 1 : 0;
        $external = null;
        $filePath = $original = $mime = null;
        $fileSize = null;
        $existing = $resourceId ? mmh_past_resource($conn, $resourceId) : null;
        if ($existing) {
            $filePath = $existing['file_path'];
            $original = $existing['original_filename'];
            $mime = $existing['mime_type'];
            $fileSize = $existing['file_size'] !== null ? (int) $existing['file_size'] : null;
            $external = $existing['external_url'];
        }
        $driveFileId = '';
        if ($storageType === 'url') {
            $rawExternal = trim((string) ($data['external_url'] ?? ''));
            $driveFileId = mmh_past_drive_file_id($data['drive_file_id'] ?? '');
            if ($rawExternal === '' && $driveFileId !== '') $rawExternal = 'https://drive.google.com/file/d/' . rawurlencode($driveFileId) . '/view';
            $external = mmh_past_validate_external_url($rawExternal);
            if ($external === null) {
                return [false, 'External resources must use a valid HTTPS URL.'];
            }
            if ($driveFileId === '') $driveFileId = mmh_past_drive_file_id($external);
            $filePath = $original = $mime = null;
            $fileSize = null;
        } else {
            [$fileOk, $fileData] = mmh_past_store_file($files['resource_file'] ?? []);
            if (!$fileOk) {
                return [false, $fileData];
            }
            if (is_array($fileData)) {
                $filePath = $fileData['file_path'];
                $original = $fileData['original_filename'];
                $mime = $fileData['mime_type'];
                $fileSize = $fileData['file_size'];
            }
            if (!$filePath) {
                return [false, 'Upload a file or switch the resource to External URL.'];
            }
            $external = null;
        }
        if ($resourceId && $existing) {
            $stmt = $conn->prepare('UPDATE past_paper_resources SET paper_id=?, resource_type=?, custom_type=?, display_title=?, storage_type=?, file_path=?, original_filename=?, mime_type=?, file_size=?, external_url=?, description=?, access_level=?, unlock_rule=?, unlock_at=?, manual_unlocked=?, status=?, sort_order=?, download_allowed=?, preview_allowed=? WHERE resource_id=?');
            $stmt->bind_param('ssssssssisssssisiiss', $paperId, $type, $customType, $title, $storageType, $filePath, $original, $mime, $fileSize, $external, $description, $access, $unlockRule, $unlockAt, $manualUnlocked, $status, $sort, $download, $preview, $resourceId);
        } else {
            $resourceId = mmh_past_id('resource');
            $stmt = $conn->prepare('INSERT INTO past_paper_resources (resource_id, paper_id, resource_type, custom_type, display_title, storage_type, file_path, original_filename, mime_type, file_size, external_url, description, access_level, unlock_rule, unlock_at, manual_unlocked, status, sort_order, download_allowed, preview_allowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssssssisssssisiis', $resourceId, $paperId, $type, $customType, $title, $storageType, $filePath, $original, $mime, $fileSize, $external, $description, $access, $unlockRule, $unlockAt, $manualUnlocked, $status, $sort, $download, $preview);
        }
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            return [false, 'Unable to save resource.'];
        }
        $stmt->close();
        if ($storageType === 'url' && $driveFileId !== '') {
            $drive = $conn->prepare('UPDATE past_paper_resources SET drive_file_id = ? WHERE resource_id = ?');
            if ($drive) { $drive->bind_param('ss', $driveFileId, $resourceId); $drive->execute(); $drive->close(); }
        }
        mmh_past_save_resource_scope($conn, $resourceId, $data['selected_course_ids'] ?? [], $data['selected_student_ids'] ?? []);
        return [true, 'Resource saved successfully.', ['resource_id' => $resourceId]];
    }
}

if (!function_exists('mmh_past_save_resource_scope')) {
    function mmh_past_save_resource_scope(mysqli $conn, $resourceId, $courseIds, $studentIds)
    {
        $resourceId = mmh_past_identifier($resourceId, 40);
        if (!$resourceId) {
            return;
        }
        $stmt = $conn->prepare('DELETE FROM past_paper_resource_courses WHERE resource_id = ?');
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('DELETE FROM past_paper_resource_students WHERE resource_id = ?');
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $stmt->close();
        $courseIds = is_array($courseIds) ? $courseIds : [];
        foreach ($courseIds as $courseId) {
            $courseId = mmh_past_identifier($courseId, 40);
            if (!$courseId) {
                continue;
            }
            $insert = $conn->prepare('INSERT IGNORE INTO past_paper_resource_courses (resource_id, course_id) VALUES (?, ?)');
            $insert->bind_param('ss', $resourceId, $courseId);
            $insert->execute();
            $insert->close();
        }
        $studentIds = is_array($studentIds) ? $studentIds : [];
        foreach ($studentIds as $studentId) {
            $studentId = (int) $studentId;
            if ($studentId <= 0) {
                continue;
            }
            $insert = $conn->prepare('INSERT IGNORE INTO past_paper_resource_students (resource_id, user_id) VALUES (?, ?)');
            $insert->bind_param('si', $resourceId, $studentId);
            $insert->execute();
            $insert->close();
        }
    }
}

if (!function_exists('mmh_past_duplicate_paper')) {
    function mmh_past_duplicate_paper(mysqli $conn, $paperId)
    {
        $paper = mmh_past_paper($conn, $paperId);
        if (!$paper) {
            return [false, 'Past Paper not found.'];
        }
        $newId = mmh_past_id('paper');
        $stmt = $conn->prepare('INSERT INTO past_papers (paper_id, exam_board_id, syllabus_id, course_id, year, exam_session, custom_session, paper_number, variant, qualification_level, tier, region, calculator_mode, maximum_marks, duration_minutes, paper_date, short_title, description, primary_topic_id, additional_topic_ids, keywords, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $status = 'draft';
        $title = trim((string) ($paper['short_title'] ?? '')) !== '' ? (string) $paper['short_title'] . ' copy' : null;
        $stmt->bind_param('ssssissssssssiisssissis', $newId, $paper['exam_board_id'], $paper['syllabus_id'], $paper['course_id'], $paper['year'], $paper['exam_session'], $paper['custom_session'], $paper['paper_number'], $paper['variant'], $paper['qualification_level'], $paper['tier'], $paper['region'], $paper['calculator_mode'], $paper['maximum_marks'], $paper['duration_minutes'], $paper['paper_date'], $title, $paper['description'], $paper['primary_topic_id'], $paper['additional_topic_ids'], $paper['keywords'], $paper['sort_order'], $status);
        if (!$stmt->execute()) {
            $stmt->close();
            return [false, 'Unable to duplicate Past Paper.'];
        }
        $stmt->close();
        $resources = mmh_past_resources($conn, $paperId, false);
        foreach ($resources as $resource) {
            $resourceId = mmh_past_id('resource');
            $storageType = 'url';
            $filePath = $original = $mime = null;
            $fileSize = null;
            $external = null;
            $statusResource = 'draft';
            $stmt = $conn->prepare('INSERT INTO past_paper_resources (resource_id, paper_id, resource_type, custom_type, display_title, storage_type, file_path, original_filename, mime_type, file_size, external_url, description, access_level, unlock_rule, unlock_at, manual_unlocked, status, sort_order, download_allowed, preview_allowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $note = trim((string) ($resource['description'] ?? ''));
            $note = trim($note . "\nFile/link intentionally not duplicated. Reattach if this copy should publish a resource.");
            $stmt->bind_param('sssssssssisssssisiis', $resourceId, $newId, $resource['resource_type'], $resource['custom_type'], $resource['display_title'], $storageType, $filePath, $original, $mime, $fileSize, $external, $note, $resource['access_level'], $resource['unlock_rule'], $resource['unlock_at'], $resource['manual_unlocked'], $statusResource, $resource['sort_order'], $resource['download_allowed'], $resource['preview_allowed']);
            $stmt->execute();
            $stmt->close();
        }
        return [true, 'Past Paper duplicated as a draft without duplicating files.', ['paper_id' => $newId]];
    }
}

if (!function_exists('mmh_past_set_paper_status')) {
    function mmh_past_set_paper_status(mysqli $conn, $paperId, $status)
    {
        $paperId = mmh_past_identifier($paperId, 40);
        $status = mmh_past_status($status, 'draft');
        if (!$paperId) {
            return [false, 'Invalid Past Paper.'];
        }
        $stmt = $conn->prepare('UPDATE past_papers SET status = ? WHERE paper_id = ?');
        $stmt->bind_param('ss', $status, $paperId);
        $ok = $stmt->execute();
        $stmt->close();
        return [$ok, $ok ? 'Past Paper status updated.' : 'Unable to update Past Paper status.'];
    }
}

if (!function_exists('mmh_past_delete_paper')) {
    function mmh_past_delete_paper(mysqli $conn, $paperId)
    {
        $paperId = mmh_past_identifier($paperId, 40);
        if (!$paperId) {
            return [false, 'Invalid Past Paper.'];
        }
        $conn->begin_transaction();
        try {
            $resources = mmh_past_resources($conn, $paperId, false);
            foreach ($resources as $resource) {
                $rid = $resource['resource_id'];
                $stmt = $conn->prepare('DELETE FROM past_paper_resource_courses WHERE resource_id = ?');
                $stmt->bind_param('s', $rid);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare('DELETE FROM past_paper_resource_students WHERE resource_id = ?');
                $stmt->bind_param('s', $rid);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare('DELETE FROM past_paper_resources WHERE paper_id = ?');
            $stmt->bind_param('s', $paperId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM past_papers WHERE paper_id = ?');
            $stmt->bind_param('s', $paperId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            return [true, 'Past Paper deleted. Uploaded files were left on disk for safety.'];
        } catch (Throwable $e) {
            $conn->rollback();
            return [false, 'Unable to delete Past Paper.'];
        }
    }
}

if (!function_exists('mmh_past_resource_scope_ids')) {
    function mmh_past_resource_scope_ids(mysqli $conn, $resourceId)
    {
        $resourceId = mmh_past_identifier($resourceId, 40);
        if (!$resourceId) return ['course_ids' => [], 'student_ids' => []];
        $courseIds = [];
        $studentIds = [];
        $stmt = $conn->prepare('SELECT course_id FROM past_paper_resource_courses WHERE resource_id = ?');
        if ($stmt) { $stmt->bind_param('s', $resourceId); foreach (mmh_past_fetch_all($stmt) as $row) $courseIds[] = (string) $row['course_id']; }
        $stmt = $conn->prepare('SELECT user_id FROM past_paper_resource_students WHERE resource_id = ?');
        if ($stmt) { $stmt->bind_param('s', $resourceId); foreach (mmh_past_fetch_all($stmt) as $row) $studentIds[] = (string) $row['user_id']; }
        return ['course_ids' => $courseIds, 'student_ids' => $studentIds];
    }
}

if (!function_exists('mmh_past_bulk_csrf_token')) {
    function mmh_past_bulk_csrf_token()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['past_papers_bulk_csrf'])) $_SESSION['past_papers_bulk_csrf'] = bin2hex(random_bytes(32));
        return (string) $_SESSION['past_papers_bulk_csrf'];
    }
}

if (!function_exists('mmh_past_bulk_csrf_valid')) {
    function mmh_past_bulk_csrf_valid($token)
    {
        $stored = (string) ($_SESSION['past_papers_bulk_csrf'] ?? '');
        return is_string($token) && $stored !== '' && hash_equals($stored, $token);
    }
}

if (!function_exists('mmh_past_bulk_session')) {
    function mmh_past_bulk_session()
    {
        $preview = $_SESSION['past_papers_bulk_preview'] ?? [];
        return is_array($preview) ? $preview : [];
    }
}

if (!function_exists('mmh_past_bulk_parse_csv')) {
    function mmh_past_bulk_parse_csv(mysqli $conn, $csv)
    {
        $csv = trim((string) $csv);
        if ($csv === '') return [false, 'Paste a CSV header and at least one row.', []];
        $lines = preg_split('/\r\n|\r|\n/', $csv);
        if (count($lines) < 2 || count($lines) > 201) return [false, 'Provide between 1 and 200 CSV rows per preview.', []];
        $headers = array_map(fn($value) => strtolower(trim((string) $value)), str_getcsv((string) array_shift($lines), ',', '"', '\\'));
        $required = ['board', 'syllabus_code', 'exam_year', 'session', 'resource_type', 'url'];
        if (array_diff($required, $headers)) return [false, 'CSV header must include: ' . implode(', ', $required) . '.', []];
        $index = array_flip($headers);
        $rows = [];
        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '') continue;
            $values = str_getcsv($line, ',', '"', '\\');
            $row = [];
            foreach ($headers as $position => $header) $row[$header] = trim((string) ($values[$position] ?? ''));
            $resourceTypeInput = strtolower($row['resource_type'] ?? '');
            $type = mmh_past_resource_type($resourceTypeInput);
            $result = ['line' => $lineNumber + 2, 'data' => $row, 'type' => $type, 'paper_id' => '', 'paper_label' => '', 'duplicate' => false, 'status' => 'review', 'message' => ''];
            if (!in_array($resourceTypeInput, ['model_answer', 'video_solution', 'solution_video'], true)) { $result['status'] = 'invalid'; $result['message'] = 'Only model_answer and video_solution are supported by this bulk entry.'; $rows[] = $result; continue; }
            $url = mmh_past_validate_external_url($row['url'] ?? '');
            if ($url === null) { $result['status'] = 'invalid'; $result['message'] = 'A valid HTTPS URL is required.'; $rows[] = $result; continue; }
            $row['url'] = $url; $result['data'] = $row;
            $boardValue = $row['board'] ?? '';
            $stmt = $conn->prepare('SELECT board_id FROM past_paper_exam_boards WHERE LOWER(board_id) = LOWER(?) OR LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?) LIMIT 2');
            $stmt->bind_param('sss', $boardValue, $boardValue, $boardValue); $boards = mmh_past_fetch_all($stmt);
            if (count($boards) !== 1) { $result['status'] = 'unmatched'; $result['message'] = count($boards) ? 'Board matches more than one record.' : 'Board not found.'; $rows[] = $result; continue; }
            $boardId = $boards[0]['board_id'];
            $syllabusCode = $row['syllabus_code'] ?? '';
            $stmt = $conn->prepare('SELECT syllabus_id, public_title FROM past_paper_syllabuses WHERE exam_board_id = ? AND LOWER(syllabus_code) = LOWER(?) LIMIT 2');
            $stmt->bind_param('ss', $boardId, $syllabusCode); $syllabuses = mmh_past_fetch_all($stmt);
            if (count($syllabuses) !== 1) { $result['status'] = 'unmatched'; $result['message'] = count($syllabuses) ? 'Syllabus matches more than one record.' : 'Syllabus code not found for this board.'; $rows[] = $result; continue; }
            $paperNumber = ($row['component_code'] ?? '') ?: ($row['paper_label'] ?? '');
            $variant = $row['variant'] ?? '';
            $year = (int) ($row['exam_year'] ?? 0);
            $session = mmh_past_session($row['session'] ?? '');
            if ($paperNumber === '' || $variant === '' || $year < 1900 || $session === 'Custom') { $result['status'] = 'unmatched'; $result['message'] = 'Year, a standard session, paper/component, and variant are required for an exact paper match.'; $rows[] = $result; continue; }
            $stmt = $conn->prepare('SELECT paper_id, year, exam_session, paper_number, variant FROM past_papers WHERE exam_board_id = ? AND syllabus_id = ? AND year = ? AND exam_session = ? AND paper_number = ? AND variant = ? LIMIT 2');
            $stmt->bind_param('ssisss', $boardId, $syllabuses[0]['syllabus_id'], $year, $session, $paperNumber, $variant); $papers = mmh_past_fetch_all($stmt);
            if (count($papers) !== 1) { $result['status'] = 'unmatched'; $result['message'] = count($papers) ? 'Paper identity is ambiguous.' : 'No exact existing paper matches this row.'; $rows[] = $result; continue; }
            $paper = $papers[0]; $result['paper_id'] = $paper['paper_id']; $result['paper_label'] = $paper['year'] . ' ' . $paper['exam_session'] . ' ' . $paper['paper_number'] . ' ' . $paper['variant'];
            $stmt = $conn->prepare("SELECT resource_id FROM past_paper_resources WHERE paper_id = ? AND resource_type = ? AND storage_type = 'url' AND external_url = ? LIMIT 1");
            $stmt->bind_param('sss', $paper['paper_id'], $type, $url); $stmt->execute(); $duplicate = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($duplicate) { $result['status'] = 'duplicate'; $result['duplicate'] = true; $result['message'] = 'The same protected link is already attached.'; }
            else { $result['status'] = 'ready'; $result['message'] = 'Ready to attach.'; }
            $rows[] = $result;
        }
        if (!$rows) return [false, 'No usable CSV rows were found.', []];
        $_SESSION['past_papers_bulk_preview'] = ['rows' => $rows, 'created_at' => time()];
        return [true, 'Bulk preview created. Review matched rows before importing.', $rows];
    }
}

if (!function_exists('mmh_past_bulk_import_rows')) {
    function mmh_past_bulk_import_rows(mysqli $conn, array $indexes)
    {
        $preview = mmh_past_bulk_session(); $rows = $preview['rows'] ?? [];
        $indexes = array_values(array_unique(array_filter(array_map('intval', $indexes), fn($index) => $index >= 0 && $index < count($rows))));
        if (!$indexes) return [false, 'Select at least one preview row.', []];
        if (count($indexes) > 50) return [false, 'Import at most 50 rows per batch.', []];
        $saved = 0; $skipped = 0; $errors = [];
        foreach ($indexes as $index) {
            $row = $rows[$index] ?? [];
            if (($row['status'] ?? '') !== 'ready' || empty($row['paper_id'])) { $skipped++; continue; }
            $data = $row['data'] ?? [];
            [$ok, $message] = mmh_past_save_resource($conn, [
                'paper_id' => $row['paper_id'], 'resource_type' => $row['type'],
                'display_title' => mmh_past_clean($data['title'] ?? '', 190), 'storage_type' => 'url',
                'external_url' => $data['url'] ?? '', 'access_level' => $data['access_level'] ?? mmh_past_default_access($row['type']),
                'status' => $data['status'] ?? 'published', 'unlock_rule' => 'immediate',
                'preview_allowed' => 1, 'download_allowed' => 0,
            ], []);
            if ($ok) { $rows[$index]['status'] = 'imported'; $rows[$index]['message'] = 'Attached successfully.'; $saved++; }
            else { $rows[$index]['status'] = 'error'; $rows[$index]['message'] = $message; $errors[] = 'Line ' . ($row['line'] ?? '?') . ': ' . $message; }
        }
        $_SESSION['past_papers_bulk_preview']['rows'] = $rows;
        return [empty($errors), $saved . ' resource(s) attached' . ($skipped ? '; ' . $skipped . ' skipped' : '') . '.', ['saved' => $saved, 'skipped' => $skipped, 'errors' => $errors]];
    }
}

if (!function_exists('mmh_past_delete_resource')) {
    function mmh_past_delete_resource(mysqli $conn, $resourceId)
    {
        $resourceId = mmh_past_identifier($resourceId, 40);
        if (!$resourceId) {
            return [false, 'Invalid resource.'];
        }
        $stmt = $conn->prepare('DELETE FROM past_paper_resource_courses WHERE resource_id = ?');
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('DELETE FROM past_paper_resource_students WHERE resource_id = ?');
        $stmt->bind_param('s', $resourceId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('DELETE FROM past_paper_resources WHERE resource_id = ?');
        $stmt->bind_param('s', $resourceId);
        $ok = $stmt->execute();
        $stmt->close();
        return [$ok, $ok ? 'Resource deleted. Uploaded files were left on disk for safety.' : 'Unable to delete resource.'];
    }
}

if (!function_exists('mmh_past_current_student_id')) {
    function mmh_past_current_student_id(mysqli $conn)
    {
        if (empty($_SESSION['username'])) {
            return null;
        }
        $username = trim((string) $_SESSION['username']);
        $stmt = $conn->prepare('SELECT user_id FROM users WHERE username = ? OR CAST(user_id AS CHAR) = ? LIMIT 1');
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['user_id'] : null;
    }
}

if (!function_exists('mmh_past_student_enrolled')) {
    function mmh_past_student_enrolled(mysqli $conn, $studentId, $courseId)
    {
        $courseId = mmh_past_identifier($courseId, 40);
        $studentId = (int) $studentId;
        if (!$courseId || $studentId <= 0) {
            return false;
        }
        $stmt = $conn->prepare('SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1');
        $stmt->bind_param('is', $studentId, $courseId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mmh_past_resource_unlocked')) {
    function mmh_past_resource_unlocked(array $resource)
    {
        $rule = mmh_past_unlock_rule($resource['unlock_rule'] ?? 'immediate');
        if ($rule === 'immediate') {
            return true;
        }
        if ($rule === 'manual') {
            return (int) ($resource['manual_unlocked'] ?? 0) === 1;
        }
        if ($rule === 'specific_datetime') {
            $unlockAt = trim((string) ($resource['unlock_at'] ?? ''));
            return $unlockAt !== '' && strtotime($unlockAt) <= time();
        }
        return false;
    }
}

if (!function_exists('mmh_past_can_access_resource')) {
    function mmh_past_can_access_resource(mysqli $conn, array $resource)
    {
        if (!empty($_SESSION['admin'])) {
            return [true, 'Admin access granted.'];
        }
        if (($resource['drive_source_status'] ?? 'available') === 'missing') {
            return [false, 'This source file is no longer available.'];
        }
        if (($resource['paper_status'] ?? '') !== 'published' || ($resource['status'] ?? '') !== 'published') {
            return [false, 'This resource is not published.'];
        }
        if (!mmh_past_resource_unlocked($resource)) {
            return [false, 'This resource is not unlocked yet.'];
        }
        $access = mmh_past_access_level($resource['access_level'] ?? 'public');
        if ($access === 'public') {
            return [true, 'Public resource.'];
        }
        if ($access === 'admin_only') {
            return [false, 'This resource is hidden.'];
        }
        $studentId = mmh_past_current_student_id($conn);
        if (!$studentId) {
            return [false, 'Please log in to access this resource.'];
        }
        if ($access === 'logged_in') {
            return [true, 'Logged-in access granted.'];
        }
        $linkedCourse = $resource['paper_course_id'] ?: ($resource['syllabus_course_id'] ?? '');
        if ($access === 'enrolled_course') {
            return $linkedCourse && mmh_past_student_enrolled($conn, $studentId, $linkedCourse)
                ? [true, 'Enrollment access granted.']
                : [false, 'This resource requires enrollment in the linked course.'];
        }
        if ($access === 'selected_courses') {
            $stmt = $conn->prepare('SELECT course_id FROM past_paper_resource_courses WHERE resource_id = ?');
            $stmt->bind_param('s', $resource['resource_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (mmh_past_student_enrolled($conn, $studentId, $row['course_id'])) {
                    $stmt->close();
                    return [true, 'Selected-course access granted.'];
                }
            }
            $stmt->close();
            return [false, 'This resource requires enrollment in a selected course.'];
        }
        if ($access === 'selected_students') {
            $stmt = $conn->prepare('SELECT id FROM past_paper_resource_students WHERE resource_id = ? AND user_id = ? LIMIT 1');
            $stmt->bind_param('si', $resource['resource_id'], $studentId);
            $stmt->execute();
            $ok = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            return $ok ? [true, 'Selected-student access granted.'] : [false, 'This resource is restricted to selected students.'];
        }
        return [false, 'Access denied.'];
    }
}


if (!function_exists('mmh_past_resource_event_type')) {
    function mmh_past_resource_event_type(array $resource, $isDownload = false)
    {
        if ($isDownload) {
            return 'past_paper_downloaded';
        }
        $type = mmh_past_resource_type($resource['resource_type'] ?? 'custom');
        $map = [
            'question_paper' => 'question_paper_opened',
            'mark_scheme' => 'mark_scheme_opened',
            'model_answer' => 'model_answer_opened',
            'solution_video' => 'solution_video_opened',
        ];
        return $map[$type] ?? 'past_paper_viewed';
    }
}

if (!function_exists('mmh_past_log_resource_event')) {
    function mmh_past_log_resource_event(mysqli $conn, array $resource, $isDownload = false)
    {
        if (!function_exists('mmh_log_event')) {
            return;
        }
        $studentId = mmh_past_current_student_id($conn);
        if (!$studentId) {
            return;
        }
        $courseId = $resource['paper_course_id'] ?: ($resource['syllabus_course_id'] ?? null);
        mmh_log_event($conn, $studentId, mmh_past_resource_event_type($resource, $isDownload), [
            'course_id' => $courseId,
            'meta' => [
                'paper_id' => $resource['paper_id'] ?? '',
                'resource_id' => $resource['resource_id'] ?? '',
                'resource_type' => $resource['resource_type'] ?? '',
                'exam_board_id' => $resource['exam_board_id'] ?? '',
                'syllabus_id' => $resource['syllabus_id'] ?? '',
                'year' => $resource['year'] ?? '',
                'session' => $resource['exam_session'] ?? '',
                'paper_number' => $resource['paper_number'] ?? '',
                'variant' => $resource['variant'] ?? '',
            ],
        ]);
    }
}

if (!function_exists('mmh_past_open_resource')) {
    function mmh_past_open_resource(mysqli $conn, $resourceId)
    {
        $resource = mmh_past_resource($conn, $resourceId);
        if (!$resource) {
            http_response_code(404);
            echo 'Past Paper resource not found.';
            exit;
        }
        [$allowed, $reason] = mmh_past_can_access_resource($conn, $resource);
        if (!$allowed) {
            http_response_code(empty($_SESSION['username']) && empty($_SESSION['admin']) ? 401 : 403);
            echo $reason;
            exit;
        }
        $isDownload = !empty($_GET['download']) && (int) ($resource['download_allowed'] ?? 0) === 1;
        mmh_past_log_resource_event($conn, $resource, $isDownload);
        if (($resource['storage_type'] ?? '') === 'url') {
            $target = mmh_past_validate_external_url($resource['external_url'] ?? '');
            if ($target === null) {
                http_response_code(500);
                echo 'Resource link is not configured correctly.';
                exit;
            }
            header('Location: ' . $target, true, 302);
            exit;
        }
        $path = (string) ($resource['file_path'] ?? '');
        if ($path === '' || str_contains($path, '..') || !is_file($path)) {
            http_response_code(404);
            echo 'Resource file not found.';
            exit;
        }
        $mime = $resource['mime_type'] ?: 'application/octet-stream';
        $filename = $resource['original_filename'] ?: basename($path);
        $disposition = $isDownload ? 'attachment' : 'inline';
        if ($disposition === 'inline' && (int) ($resource['preview_allowed'] ?? 0) !== 1) {
            $disposition = 'attachment';
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"');
        readfile($path);
        exit;
    }
}

if (!function_exists('mmh_past_is_edexcel_board')) {
    function mmh_past_is_edexcel_board(array $paper): bool
    {
        $board = strtolower(trim((string)($paper['board_name'] ?? $paper['exam_board_name'] ?? '')));
        return str_contains($board, 'edexcel') || str_contains($board, 'pearson');
    }
}

if (!function_exists('mmh_past_is_foundation_paper')) {
    function mmh_past_is_foundation_paper(array $paper): bool
    {
        if (!mmh_past_is_edexcel_board($paper)) return false;
        $tier = strtolower(trim((string)($paper['tier'] ?? '')));
        if (str_contains($tier, 'foundation')) return true;
        foreach (['paper_number', 'variant'] as $field) {
            $value = strtoupper(trim((string)($paper[$field] ?? '')));
            $value = preg_replace('/^PAPER\s*/', '', $value);
            $value = preg_replace('/\s+/', '', $value);
            if (preg_match('/^\d+F(?:R)?$/', $value)) return true;
        }
        return false;
    }
}

if (!function_exists('mmh_past_student_foundation_exclusion_sql')) {
    function mmh_past_student_foundation_exclusion_sql(string $paperAlias = 'p', string $boardAlias = 'b'): string
    {
        $paper = preg_replace('/[^a-zA-Z0-9_]/', '', $paperAlias) ?: 'p';
        $board = preg_replace('/[^a-zA-Z0-9_]/', '', $boardAlias) ?: 'b';
        $paperCode = "REPLACE(UPPER(REPLACE({$paper}.paper_number, ' ', '')), 'PAPER', '')";
        $variantCode = "REPLACE(UPPER(REPLACE({$paper}.variant, ' ', '')), 'PAPER', '')";
        return "NOT ((LOWER({$board}.name) LIKE '%edexcel%' OR LOWER({$board}.name) LIKE '%pearson%') AND (LOWER(COALESCE({$paper}.tier, '')) LIKE '%foundation%' OR {$paperCode} REGEXP '^[0-9]+F(R)?$' OR {$variantCode} REGEXP '^[0-9]+F(R)?$'))";
    }
}

if (!function_exists('mmh_past_normalize_paper_label')) {
    function mmh_past_normalize_paper_label(array $paper): array
    {
        $rawPaper = trim((string)($paper['paper_number'] ?? ''));
        $rawVariant = trim((string)($paper['variant'] ?? ''));
        $board = strtolower(trim((string)($paper['board_name'] ?? '')));
        $compactPaper = strtoupper(preg_replace('/\s+/', '', preg_replace('/^paper\s*/i', '', $rawPaper)));
        $compactVariant = strtoupper(preg_replace('/\s+/', '', preg_replace('/^paper\s*/i', '', $rawVariant)));

        if (str_contains($board, 'edexcel') || str_contains($board, 'pearson')) {
            $component = preg_match('/^\d+H(R)?$/', $compactPaper) ? $compactPaper : $compactVariant;
            if (preg_match('/^(\d+)H(R)?$/', $component, $matches)) {
                return [
                    'key' => 'edexcel:' . $matches[1] . 'H',
                    'label' => 'Paper ' . $matches[1] . 'H',
                    'variant' => !empty($matches[2]) ? 'R' : '',
                    'board_family' => 'edexcel',
                ];
            }
        }

        if (str_contains($board, 'cambridge')) {
            $component = preg_match('/^\d{2}$/', $compactPaper) ? $compactPaper : $compactVariant;
            if (preg_match('/^(\d)([123])$/', $component, $matches)) {
                return [
                    'key' => 'cambridge:' . $matches[1],
                    'label' => 'Paper ' . $matches[1],
                    'variant' => 'Variant ' . $matches[2],
                    'board_family' => 'cambridge',
                ];
            }
            if (preg_match('/^(\d)$/', $compactPaper, $matches)) {
                return [
                    'key' => 'cambridge:' . $matches[1],
                    'label' => 'Paper ' . $matches[1],
                    'variant' => $rawVariant !== '' ? 'Variant ' . $rawVariant : '',
                    'board_family' => 'cambridge',
                ];
            }
        }

        $label = $rawPaper !== '' ? $rawPaper : 'Paper';
        if (!preg_match('/^(paper|component)\b/i', $label)) $label = 'Paper ' . $label;
        return ['key' => 'raw:' . strtolower($label), 'label' => $label, 'variant' => $rawVariant, 'board_family' => 'other'];
    }
}

if (!function_exists('mmh_past_archive_group_sql')) {
    function mmh_past_archive_group_sql(string $group, string $paperAlias = 'p', string $boardAlias = 'b'): string
    {
        $paper = preg_replace('/[^a-zA-Z0-9_]/', '', $paperAlias) ?: 'p';
        $board = preg_replace('/[^a-zA-Z0-9_]/', '', $boardAlias) ?: 'b';
        $group = trim($group);
        $paperCode = "REPLACE(UPPER(REPLACE({$paper}.paper_number, ' ', '')), 'PAPER', '')";
        $variantCode = "REPLACE(UPPER(REPLACE({$paper}.variant, ' ', '')), 'PAPER', '')";
        if (preg_match('/^edexcel:(\d+)H$/', $group, $matches)) {
            $number = (int)$matches[1];
            return "((LOWER({$board}.name) LIKE '%edexcel%' OR LOWER({$board}.name) LIKE '%pearson%') AND ({$paperCode} REGEXP '^{$number}HR?$' OR {$variantCode} REGEXP '^{$number}HR?$'))";
        }
        if (preg_match('/^cambridge:(\d+)$/', $group, $matches)) {
            $number = (int)$matches[1];
            return "(LOWER({$board}.name) LIKE '%cambridge%' AND ({$paperCode} REGEXP '^{$number}([123])?$' OR {$variantCode} REGEXP '^{$number}[123]$'))";
        }
        return '';
    }
}

if (!function_exists('mmh_past_archive_navigation')) {
    function mmh_past_archive_navigation(mysqli $conn, array $filters = []): array
    {
        $where = ["p.status = 'published'", "s.status = 'published'", "b.status = 'published'", mmh_past_student_foundation_exclusion_sql('p', 'b')];
        $params = [];
        $types = '';
        $boardFilter = mmh_past_identifier($filters['exam_board_id'] ?? '', 40);
        if ($boardFilter) { $where[] = 'p.exam_board_id = ?'; $params[] = $boardFilter; $types .= 's'; }
        $syllabusFilter = mmh_past_identifier($filters['syllabus_id'] ?? '', 40);
        if ($syllabusFilter) { $where[] = 'p.syllabus_id = ?'; $params[] = $syllabusFilter; $types .= 's'; }
        $sql = 'SELECT p.paper_id, p.exam_board_id, p.syllabus_id, p.paper_number, p.variant, p.tier, b.name AS board_name, s.public_title AS syllabus_title, s.syllabus_code
            FROM past_papers p INNER JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id INNER JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY b.sort_order ASC, s.sort_order ASC, p.paper_number ASC, p.variant ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return ['boards' => [], 'syllabuses' => [], 'paper_groups' => [], 'selected_board_id' => '', 'selected_syllabus_id' => '', 'selected_paper_group' => ''];
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $rows = mmh_past_fetch_all($stmt);
        $boards = [];
        foreach ($rows as $row) $boards[$row['exam_board_id']] = ['board_id' => $row['exam_board_id'], 'name' => $row['board_name']];
        $selectedBoard = $boardFilter && isset($boards[$boardFilter]) ? $boardFilter : (string)array_key_first($boards);
        $syllabuses = [];
        foreach ($rows as $row) if ((string)$row['exam_board_id'] === $selectedBoard) $syllabuses[$row['syllabus_id']] = ['syllabus_id' => $row['syllabus_id'], 'public_title' => $row['syllabus_title'], 'syllabus_code' => $row['syllabus_code']];
        $selectedSyllabus = $syllabusFilter && isset($syllabuses[$syllabusFilter]) ? $syllabusFilter : (string)array_key_first($syllabuses);
        $groups = [];
        foreach ($rows as $row) {
            if ((string)$row['exam_board_id'] !== $selectedBoard || (string)$row['syllabus_id'] !== $selectedSyllabus) continue;
            $normalized = mmh_past_normalize_paper_label($row);
            if (!isset($groups[$normalized['key']])) $groups[$normalized['key']] = ['key' => $normalized['key'], 'label' => $normalized['label'], 'count' => 0];
            $groups[$normalized['key']]['count']++;
        }
        uasort($groups, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
        $requestedGroup = mmh_past_clean($filters['paper_group'] ?? '', 60);
        $selectedGroup = $requestedGroup && isset($groups[$requestedGroup]) ? $requestedGroup : (string)array_key_first($groups);
        return ['boards' => array_values($boards), 'syllabuses' => array_values($syllabuses), 'paper_groups' => array_values($groups), 'selected_board_id' => $selectedBoard, 'selected_syllabus_id' => $selectedSyllabus, 'selected_paper_group' => $selectedGroup];
    }
}

if (!function_exists('mmh_past_frontend_filters')) {
    function mmh_past_frontend_filters(array $source)
    {
        return [
            'search' => mmh_past_clean($source['search'] ?? '', 120),
            'exam_board_id' => mmh_past_identifier($source['exam_board_id'] ?? '', 40) ?: '',
            'syllabus_id' => mmh_past_identifier($source['syllabus_id'] ?? '', 40) ?: '',
            'year' => isset($source['year']) && is_numeric($source['year']) ? (int) $source['year'] : '',
            'exam_session' => mmh_past_clean($source['exam_session'] ?? '', 40),
            'paper_number' => mmh_past_clean($source['paper_number'] ?? '', 80),
            'paper_group' => mmh_past_clean($source['paper_group'] ?? '', 60),
            'variant' => mmh_past_clean($source['variant'] ?? '', 80),
            'resource_type' => ($source['resource_type'] ?? '') === '' ? '' : mmh_past_resource_type($source['resource_type']),
            'sort' => in_array(($source['sort'] ?? 'newest'), ['newest', 'oldest', 'paper', 'session'], true) ? ($source['sort'] ?? 'newest') : 'newest',
            'course_id' => mmh_past_identifier($source['course_id'] ?? '', 40) ?: '',
        ];
    }
}

if (!function_exists('mmh_past_filter_options')) {
    function mmh_past_filter_options(mysqli $conn)
    {
        mmh_past_ensure_schema($conn);
        $options = [
            'boards' => [],
            'syllabuses' => [],
            'years' => [],
            'sessions' => [],
            'papers' => [],
            'variants' => [],
        ];
        $visibleWhere = "p.status = 'published' AND " . mmh_past_student_foundation_exclusion_sql('p', 'b');
        $sql = "SELECT DISTINCT b.board_id, b.name
            FROM past_papers p
            INNER JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id AND b.status = 'published'
            INNER JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id AND s.status = 'published'
            WHERE {$visibleWhere}
            ORDER BY b.sort_order ASC, b.name ASC";
        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) { $options['boards'][] = $row; }
        }
        $sql = "SELECT DISTINCT s.syllabus_id, s.public_title, s.syllabus_code, s.exam_board_id, b.name AS board_name
            FROM past_papers p
            INNER JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id AND s.status = 'published'
            INNER JOIN past_paper_exam_boards b ON b.board_id = s.exam_board_id AND b.status = 'published'
            WHERE {$visibleWhere}
            ORDER BY b.sort_order ASC, s.sort_order ASC, s.public_title ASC";
        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) { $options['syllabuses'][] = $row; }
        }
        foreach (['years' => 'year', 'sessions' => 'exam_session', 'papers' => 'paper_number', 'variants' => 'variant'] as $bucket => $column) {
            $order = $column === 'year' ? ' ORDER BY p.year DESC' : " ORDER BY p.`{$column}` ASC";
            $result = $conn->query("SELECT DISTINCT p.`{$column}` AS value FROM past_papers p INNER JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id AND s.status = 'published' INNER JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id AND b.status = 'published' WHERE {$visibleWhere} AND p.`{$column}` IS NOT NULL AND p.`{$column}` <> ''{$order}");
            if ($result) {
                while ($row = $result->fetch_assoc()) { $options[$bucket][] = $row['value']; }
            }
        }
        return $options;
    }
}

if (!function_exists('mmh_past_published_syllabuses')) {
    function mmh_past_published_syllabuses(mysqli $conn, $limit = 12)
    {
        mmh_past_ensure_schema($conn);
        $limit = max(1, min(24, (int) $limit));
        $sql = "SELECT s.syllabus_id, s.syllabus_code, s.public_title, s.description, s.thumbnail_path, s.course_id, b.name AS board_name,
                c.id AS course_numeric_id, c.course_title,
                COUNT(DISTINCT p.paper_id) AS paper_count,
                GROUP_CONCAT(DISTINCT p.year ORDER BY p.year DESC SEPARATOR ', ') AS years,
                GROUP_CONCAT(DISTINCT p.exam_session ORDER BY p.exam_session ASC SEPARATOR ', ') AS sessions
            FROM past_paper_syllabuses s
            INNER JOIN past_paper_exam_boards b ON b.board_id = s.exam_board_id AND b.status = 'published'
            INNER JOIN past_papers p ON p.syllabus_id = s.syllabus_id AND p.status = 'published'
            LEFT JOIN courses c ON c.course_id = s.course_id
            WHERE s.status = 'published'
            GROUP BY s.id
            ORDER BY s.sort_order ASC, paper_count DESC, s.public_title ASC
            LIMIT ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) { return []; }
        $stmt->bind_param('i', $limit);
        return mmh_past_fetch_all($stmt);
    }
}

if (!function_exists('mmh_past_frontend_papers')) {
    function mmh_past_frontend_papers(mysqli $conn, array $filters = [], $limit = 40, $offset = 0)
    {
        mmh_past_ensure_schema($conn);
        $where = ["p.status = 'published'", "s.status = 'published'", "b.status = 'published'", mmh_past_student_foundation_exclusion_sql('p', 'b')];
        $params = [];
        $types = '';
        foreach ([['exam_board_id', 's'], ['syllabus_id', 's'], ['year', 'i'], ['exam_session', 's'], ['paper_number', 's'], ['variant', 's']] as $filter) {
            [$key, $type] = $filter;
            if (($filters[$key] ?? '') !== '') {
                $where[] = "p.`{$key}` = ?";
                $params[] = $type === 'i' ? (int) $filters[$key] : (string) $filters[$key];
                $types .= $type;
            }
        }
        $paperGroupSql = mmh_past_archive_group_sql((string)($filters['paper_group'] ?? ''), 'p', 'b');
        if ($paperGroupSql !== '') $where[] = $paperGroupSql;
        $courseFilter = mmh_past_identifier($filters['course_id'] ?? '', 40);
        if ($courseFilter) {
            $where[] = 'COALESCE(NULLIF(p.course_id, ""), NULLIF(s.course_id, "")) = ?';
            $params[] = $courseFilter;
            $types .= 's';
        }
        $search = mmh_past_clean($filters['search'] ?? '', 120);
        if ($search !== '') {
            $where[] = '(p.short_title LIKE ? OR p.description LIKE ? OR p.paper_number LIKE ? OR p.variant LIKE ? OR p.keywords LIKE ? OR s.public_title LIKE ? OR s.syllabus_code LIKE ? OR b.name LIKE ?)';
            $like = '%' . $search . '%';
            for ($i = 0; $i < 8; $i++) { $params[] = $like; $types .= 's'; }
        }
        $limit = max(1, min(80, (int) $limit));
        $offset = max(0, (int) $offset);
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        $sql = 'SELECT p.*, b.name AS board_name, s.public_title AS syllabus_title, s.syllabus_code, s.description AS syllabus_description, s.thumbnail_path, s.course_id AS syllabus_course_id, c.id AS course_numeric_id, c.course_title,
                COALESCE(rc.visible_resource_count, 0) AS visible_resource_count,
                COALESCE(rc.question_count, 0) AS question_count,
                COALESCE(rc.mark_scheme_count, 0) AS mark_scheme_count,
                COALESCE(rc.model_answer_count, 0) AS model_answer_count,
                COALESCE(rc.solution_video_count, 0) AS solution_video_count
            FROM past_papers p
            INNER JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id
            INNER JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id
            LEFT JOIN courses c ON c.course_id = COALESCE(NULLIF(p.course_id, ""), NULLIF(s.course_id, ""))
            LEFT JOIN (
                SELECT paper_id,
                    COUNT(id) AS visible_resource_count,
                    SUM(CASE WHEN resource_type = "question_paper" THEN 1 ELSE 0 END) AS question_count,
                    SUM(CASE WHEN resource_type = "mark_scheme" THEN 1 ELSE 0 END) AS mark_scheme_count,
                    SUM(CASE WHEN resource_type = "model_answer" THEN 1 ELSE 0 END) AS model_answer_count,
                    SUM(CASE WHEN resource_type = "solution_video" THEN 1 ELSE 0 END) AS solution_video_count
                FROM past_paper_resources
                WHERE status = "published" AND access_level <> "admin_only"
                GROUP BY paper_id
            ) rc ON rc.paper_id = p.paper_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.year DESC, p.sort_order ASC, p.created_at DESC
            LIMIT ? OFFSET ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) { return []; }
        $stmt->bind_param($types, ...$params);
        return mmh_past_fetch_all($stmt);
    }
}

if (!function_exists('mmh_past_frontend_listing')) {
    function mmh_past_frontend_listing(mysqli $conn, array $filters = [], $page = 1, $perPage = 25)
    {
        mmh_past_ensure_schema($conn);
        $where = ["p.status = 'published'", "s.status = 'published'", "b.status = 'published'", mmh_past_student_foundation_exclusion_sql('p', 'b')];
        $params = [];
        $types = '';
        foreach ([['exam_board_id', 's'], ['syllabus_id', 's'], ['year', 'i'], ['exam_session', 's'], ['paper_number', 's'], ['variant', 's']] as $filter) {
            [$key, $type] = $filter;
            if (($filters[$key] ?? '') !== '') {
                $where[] = "p.`{$key}` = ?";
                $params[] = $type === 'i' ? (int) $filters[$key] : (string) $filters[$key];
                $types .= $type;
            }
        }
        $paperGroupSql = mmh_past_archive_group_sql((string)($filters['paper_group'] ?? ''), 'p', 'b');
        if ($paperGroupSql !== '') $where[] = $paperGroupSql;
        $courseFilter = mmh_past_identifier($filters['course_id'] ?? '', 40);
        if ($courseFilter) {
            $where[] = 'COALESCE(NULLIF(p.course_id, ""), NULLIF(s.course_id, "")) = ?';
            $params[] = $courseFilter;
            $types .= 's';
        }
        $resourceType = ($filters['resource_type'] ?? '') === '' ? '' : mmh_past_resource_type($filters['resource_type']);
        if ($resourceType !== '') {
            $where[] = "EXISTS (SELECT 1 FROM past_paper_resources rf WHERE rf.paper_id = p.paper_id AND rf.status = 'published' AND rf.access_level <> 'admin_only' AND rf.resource_type = ?)";
            $params[] = $resourceType;
            $types .= 's';
        }
        $search = mmh_past_clean($filters['search'] ?? '', 120);
        if ($search !== '') {
            $where[] = '(p.short_title LIKE ? OR p.description LIKE ? OR p.paper_number LIKE ? OR p.variant LIKE ? OR p.keywords LIKE ? OR s.public_title LIKE ? OR s.syllabus_code LIKE ? OR b.name LIKE ?)';
            $like = '%' . $search . '%';
            for ($i = 0; $i < 8; $i++) { $params[] = $like; $types .= 's'; }
        }
        $from = ' FROM past_papers p INNER JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id INNER JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id ';
        $whereSql = implode(' AND ', $where);
        $count = $conn->prepare('SELECT COUNT(*) AS total' . $from . ' WHERE ' . $whereSql);
        if (!$count) return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'pages' => 0, 'offset' => 0];
        if ($types !== '') $count->bind_param($types, ...$params);
        $count->execute();
        $total = (int) (($count->get_result()->fetch_assoc()['total'] ?? 0));
        $count->close();
        $perPage = max(10, min(50, (int) $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $page), $pages);
        $offset = ($page - 1) * $perPage;
        $sessionOrder = "CASE p.exam_session WHEN 'January' THEN 1 WHEN 'February/March' THEN 2 WHEN 'May/June' THEN 3 WHEN 'October/November' THEN 4 ELSE 5 END";
        $sort = $filters['sort'] ?? 'newest';
        $orderBy = match ($sort) {
            'oldest' => "p.year ASC, {$sessionOrder} ASC, p.paper_number ASC, p.variant ASC, p.id ASC",
            'paper' => "p.paper_number ASC, p.variant ASC, p.year DESC, {$sessionOrder} DESC, p.id ASC",
            'session' => "{$sessionOrder} ASC, p.year DESC, p.paper_number ASC, p.variant ASC, p.id ASC",
            default => "p.year DESC, {$sessionOrder} DESC, p.paper_number ASC, p.variant ASC, p.id ASC",
        };
        $sql = 'SELECT p.paper_id, p.exam_board_id, p.syllabus_id, p.course_id, p.year, p.exam_session, p.custom_session, p.paper_number, p.variant, p.tier, p.short_title, p.description, b.name AS board_name, s.public_title AS syllabus_title, s.syllabus_code, s.course_id AS syllabus_course_id,
                MAX(CASE WHEN r.resource_type = "question_paper" THEN 1 ELSE 0 END) AS has_question_paper,
                MAX(CASE WHEN r.resource_type = "mark_scheme" THEN 1 ELSE 0 END) AS has_mark_scheme,
                MAX(CASE WHEN r.resource_type = "examiner_report" THEN 1 ELSE 0 END) AS has_examiner_report,
                MAX(CASE WHEN r.resource_type = "grade_boundaries" THEN 1 ELSE 0 END) AS has_grade_boundaries,
                MAX(CASE WHEN r.resource_type = "insert" THEN 1 ELSE 0 END) AS has_insert,
                MAX(CASE WHEN r.resource_type = "formula_sheet" THEN 1 ELSE 0 END) AS has_formula_sheet
            ' . $from . ' LEFT JOIN past_paper_resources r ON r.paper_id = p.paper_id AND r.status = "published" AND r.access_level <> "admin_only"
            WHERE ' . $whereSql . ' GROUP BY p.id ORDER BY ' . $orderBy . ' LIMIT ? OFFSET ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0, 'offset' => 0];
        $bindTypes = $types . 'ii';
        $bindParams = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($bindTypes, ...$bindParams);
        return ['rows' => mmh_past_fetch_all($stmt), 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'offset' => $offset];
    }
}

if (!function_exists('mmh_past_listing_resources_for_papers')) {
    function mmh_past_listing_resources_for_papers(mysqli $conn, array $paperIds, $studentId)
    {
        $paperIds = array_values(array_unique(array_filter(array_map(fn($id) => mmh_past_identifier($id, 40), $paperIds))));
        if (!$paperIds) return [];
        $placeholders = implode(',', array_fill(0, count($paperIds), '?'));
        $studentId = max(0, (int) $studentId);
        $sql = "SELECT r.*, p.exam_board_id, p.syllabus_id, p.course_id AS paper_course_id, p.status AS paper_status, p.year, p.exam_session, p.paper_number, p.variant, s.course_id AS syllabus_course_id,
                EXISTS(SELECT 1 FROM course_logs cl WHERE cl.user_id = ? AND cl.course_id = COALESCE(NULLIF(p.course_id, ''), NULLIF(s.course_id, ''))) AS listing_linked_enrolled,
                EXISTS(SELECT 1 FROM past_paper_resource_courses prc INNER JOIN course_logs cl2 ON cl2.course_id = prc.course_id AND cl2.user_id = ? WHERE prc.resource_id = r.resource_id) AS listing_selected_course_enrolled,
                EXISTS(SELECT 1 FROM past_paper_resource_students prs WHERE prs.resource_id = r.resource_id AND prs.user_id = ?) AS listing_selected_student
            FROM past_paper_resources r
            INNER JOIN past_papers p ON p.paper_id = r.paper_id
            LEFT JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id
            WHERE r.paper_id IN ({$placeholders}) AND r.access_level <> 'admin_only' AND r.status = 'published' AND p.status = 'published'
            ORDER BY r.paper_id ASC, r.sort_order ASC, r.id ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $types = 'iii' . str_repeat('s', count($paperIds));
        $params = array_merge([$studentId, $studentId, $studentId], $paperIds);
        $stmt->bind_param($types, ...$params);
        $grouped = [];
        foreach (mmh_past_fetch_all($stmt) as $row) $grouped[$row['paper_id']][] = $row;
        return $grouped;
    }
}

if (!function_exists('mmh_past_listing_resource_state')) {
    function mmh_past_listing_resource_state(array $resource)
    {
        if (($resource['drive_source_status'] ?? 'available') === 'missing') return ['available' => false, 'reason' => 'Source unavailable'];
        if (!mmh_past_resource_unlocked($resource)) return ['available' => false, 'reason' => 'Not unlocked'];
        return match (mmh_past_access_level($resource['access_level'] ?? 'public')) {
            'public', 'logged_in' => ['available' => true, 'reason' => ''],
            'enrolled_course' => !empty($resource['listing_linked_enrolled']) ? ['available' => true, 'reason' => ''] : ['available' => false, 'reason' => 'Course access required'],
            'selected_courses' => !empty($resource['listing_selected_course_enrolled']) ? ['available' => true, 'reason' => ''] : ['available' => false, 'reason' => 'Selected course access required'],
            'selected_students' => !empty($resource['listing_selected_student']) ? ['available' => true, 'reason' => ''] : ['available' => false, 'reason' => 'Teacher access required'],
            default => ['available' => false, 'reason' => 'Unavailable'],
        };
    }
}

if (!function_exists('mmh_past_resources_for_papers')) {
    function mmh_past_resources_for_papers(mysqli $conn, array $paperIds, $publishedOnly = true)
    {
        $paperIds = array_values(array_unique(array_filter(array_map(function ($id) { return mmh_past_identifier($id, 40); }, $paperIds))));
        if (!$paperIds) { return []; }
        $placeholders = implode(',', array_fill(0, count($paperIds), '?'));
        $types = str_repeat('s', count($paperIds));
        $sql = "SELECT r.*, p.exam_board_id, p.syllabus_id, p.course_id AS paper_course_id, p.status AS paper_status, p.year, p.exam_session, p.paper_number, p.variant, p.short_title,
                s.course_id AS syllabus_course_id, c.id AS course_numeric_id, c.course_title
            FROM past_paper_resources r
            INNER JOIN past_papers p ON p.paper_id = r.paper_id
            LEFT JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id
            LEFT JOIN courses c ON c.course_id = COALESCE(NULLIF(p.course_id, ''), NULLIF(s.course_id, ''))
            WHERE r.paper_id IN ({$placeholders}) AND r.access_level <> 'admin_only'";
        if ($publishedOnly) { $sql .= " AND r.status = 'published' AND p.status = 'published'"; }
        $sql .= ' ORDER BY r.paper_id ASC, r.sort_order ASC, r.id ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) { return []; }
        $stmt->bind_param($types, ...$paperIds);
        $rows = mmh_past_fetch_all($stmt);
        $grouped = [];
        foreach ($rows as $row) { $grouped[$row['paper_id']][] = $row; }
        return $grouped;
    }
}

if (!function_exists('mmh_past_resource_icon')) {
    function mmh_past_resource_icon($type)
    {
        $map = [
            'question_paper' => 'fa-file-alt',
            'mark_scheme' => 'fa-tasks',
            'model_answer' => 'fa-check-circle',
            'solution_video' => 'fa-play-circle',
            'examiner_report' => 'fa-search',
            'insert' => 'fa-file-download',
            'formula_sheet' => 'fa-square-root-alt',
            'data_booklet' => 'fa-book-open',
            'grade_boundaries' => 'fa-chart-bar',
            'source_booklet' => 'fa-book',
            'pre_release_material' => 'fa-file-contract',
            'custom' => 'fa-folder-open',
        ];
        return $map[mmh_past_resource_type($type)] ?? 'fa-folder-open';
    }
}

if (!function_exists('mmh_past_duration_label')) {
    function mmh_past_duration_label($minutes)
    {
        $minutes = (int) $minutes;
        if ($minutes <= 0) { return ''; }
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;
        if ($hours > 0 && $remaining > 0) { return $hours . 'h ' . $remaining . 'm'; }
        return $hours > 0 ? $hours . 'h' : $remaining . 'm';
    }
}

if (!function_exists('mmh_past_resource_view_state')) {
    function mmh_past_resource_view_state(mysqli $conn, array $resource)
    {
        if (($resource['paper_status'] ?? '') !== 'published' || ($resource['status'] ?? '') !== 'published' || mmh_past_access_level($resource['access_level'] ?? '') === 'admin_only') {
            return ['visible' => false, 'available' => false, 'label' => 'Hidden', 'message' => 'This resource is not visible.', 'class' => 'hidden', 'actions' => []];
        }
        $rule = mmh_past_unlock_rule($resource['unlock_rule'] ?? 'immediate');
        if ($rule === 'manual' && (int)($resource['manual_unlocked'] ?? 0) !== 1) {
            return ['visible' => true, 'available' => false, 'label' => 'Manually Locked', 'message' => 'Your teacher has not unlocked this resource yet.', 'class' => 'locked', 'actions' => []];
        }
        if ($rule === 'specific_datetime') {
            $unlockAt = trim((string)($resource['unlock_at'] ?? ''));
            if ($unlockAt === '' || strtotime($unlockAt) > time()) {
                $date = $unlockAt !== '' ? date('M j, Y g:i A', strtotime($unlockAt)) : 'a scheduled time';
                return ['visible' => true, 'available' => false, 'label' => 'Unlocks on ' . $date, 'message' => 'This resource is scheduled for release.', 'class' => 'locked', 'actions' => []];
            }
        }
        [$allowed, $reason] = mmh_past_can_access_resource($conn, $resource);
        if ($allowed) {
            $base = 'past-papers/resource/' . rawurlencode((string)$resource['resource_id']);
            $actions = [];
            if ((int)($resource['preview_allowed'] ?? 0) === 1) {
                $actions[] = ['label' => mmh_past_resource_type($resource['resource_type'] ?? '') === 'solution_video' ? 'Watch Solution' : 'View', 'url' => $base, 'primary' => true];
            }
            if ((int)($resource['download_allowed'] ?? 0) === 1 && ($resource['storage_type'] ?? '') === 'file') {
                $actions[] = ['label' => 'Download', 'url' => $base . '?download=1', 'primary' => false];
            }
            if (!$actions) {
                $actions[] = ['label' => 'Open', 'url' => $base, 'primary' => true];
            }
            return ['visible' => true, 'available' => true, 'label' => 'Available', 'message' => 'Ready to open securely.', 'class' => 'available', 'actions' => $actions];
        }
        $access = mmh_past_access_level($resource['access_level'] ?? 'public');
        if (empty($_SESSION['username'])) {
            return ['visible' => true, 'available' => false, 'label' => 'Login Required', 'message' => 'Sign in to check whether this resource is available for your account.', 'class' => 'login', 'actions' => []];
        }
        if ($access === 'enrolled_course') {
            $courseTitle = trim((string)($resource['course_title'] ?? ''));
            $message = $courseTitle !== '' ? 'Available after enrolling in ' . $courseTitle . '.' : 'Available after enrolling in the linked course.';
            return ['visible' => true, 'available' => false, 'label' => 'Course Enrollment Required', 'message' => $message, 'class' => 'course', 'actions' => []];
        }
        if ($access === 'selected_courses') {
            return ['visible' => true, 'available' => false, 'label' => 'Course Enrollment Required', 'message' => 'Restricted to selected course students.', 'class' => 'course', 'actions' => []];
        }
        if ($access === 'selected_students') {
            return ['visible' => true, 'available' => false, 'label' => 'Restricted to Selected Students', 'message' => 'Your teacher controls access to this resource.', 'class' => 'selected', 'actions' => []];
        }
        return ['visible' => true, 'available' => false, 'label' => 'Not Available', 'message' => $reason, 'class' => 'locked', 'actions' => []];
    }
}

if (!function_exists('mmh_past_recent_activity')) {
    function mmh_past_recent_activity(mysqli $conn, $userId, $limit = 6)
    {
        $userId = (int) $userId;
        if ($userId <= 0) { return []; }
        $events = ['past_paper_viewed', 'question_paper_opened', 'mark_scheme_opened', 'model_answer_opened', 'solution_video_opened', 'past_paper_downloaded'];
        $placeholders = implode(',', array_fill(0, count($events), '?'));
        $types = 'i' . str_repeat('s', count($events));
        $params = array_merge([$userId], $events);
        $stmt = $conn->prepare("SELECT event_type, meta, created_at FROM learning_events WHERE user_id = ? AND event_type IN ({$placeholders}) ORDER BY created_at DESC LIMIT 30");
        if (!$stmt) { return []; }
        $stmt->bind_param($types, ...$params);
        $rows = mmh_past_fetch_all($stmt);
        $resourceIds = [];
        $seen = [];
        foreach ($rows as $row) {
            $meta = json_decode((string)($row['meta'] ?? ''), true);
            $rid = is_array($meta) ? mmh_past_identifier($meta['resource_id'] ?? '', 40) : null;
            if ($rid && empty($seen[$rid])) { $resourceIds[] = $rid; $seen[$rid] = $row; }
            if (count($resourceIds) >= $limit) { break; }
        }
        if (!$resourceIds) { return []; }
        $ph = implode(',', array_fill(0, count($resourceIds), '?'));
        $stmt = $conn->prepare("SELECT r.resource_id, r.resource_type, r.custom_type, r.display_title, r.paper_id, p.year, p.exam_session, p.paper_number, p.variant, s.public_title AS syllabus_title, b.name AS board_name
            FROM past_paper_resources r
            INNER JOIN past_papers p ON p.paper_id = r.paper_id
            INNER JOIN past_paper_syllabuses s ON s.syllabus_id = p.syllabus_id
            INNER JOIN past_paper_exam_boards b ON b.board_id = p.exam_board_id
            WHERE r.resource_id IN ({$ph})");
        if (!$stmt) { return []; }
        $types = str_repeat('s', count($resourceIds));
        $stmt->bind_param($types, ...$resourceIds);
        $resources = mmh_past_fetch_all($stmt);
        $byId = [];
        foreach ($resources as $resource) { $byId[$resource['resource_id']] = $resource; }
        $recent = [];
        foreach ($resourceIds as $rid) {
            if (!isset($byId[$rid])) { continue; }
            $item = $byId[$rid];
            $item['event_type'] = $seen[$rid]['event_type'] ?? '';
            $item['created_at'] = $seen[$rid]['created_at'] ?? '';
            $recent[] = $item;
        }
        return $recent;
    }
}

if (!function_exists('mmh_past_course_preview_papers')) {
    function mmh_past_course_preview_papers(mysqli $conn, $courseId, $limit = 3)
    {
        $courseId = mmh_past_identifier($courseId, 40);
        if (!$courseId) { return []; }
        return mmh_past_frontend_papers($conn, ['course_id' => $courseId], $limit, 0);
    }
}

?>
