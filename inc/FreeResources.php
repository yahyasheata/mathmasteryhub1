<?php
/**
 * Free Learning Resources — Phase 1.
 *
 * Additive module only: one normalized resource model, reusable collections,
 * server-side access control, secure file/link opening, and admin helpers.
 * This file intentionally does not touch Past Papers, Course Builder,
 * Assignments, Exams, Live Sessions, or the student lesson renderer.
 */

require_once __DIR__ . '/learning_schema.php';
require_once __DIR__ . '/SchemaMigration.php';

if (!function_exists('mmh_free_response')) {
    function mmh_free_response($success, $message, array $data = [], $statusCode = 200)
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

if (!function_exists('mmh_free_flash')) {
    function mmh_free_flash($type, $message)
    {
        $_SESSION['free_learning_flash'] = [
            'type' => $type === 'success' ? 'success' : 'error',
            'message' => (string) $message,
        ];
    }
}

if (!function_exists('mmh_free_take_flash')) {
    function mmh_free_take_flash()
    {
        $flash = $_SESSION['free_learning_flash'] ?? null;
        unset($_SESSION['free_learning_flash']);
        return is_array($flash) ? $flash : null;
    }
}

if (!function_exists('mmh_free_csrf_token')) {
    function mmh_free_csrf_token()
    {
        if (empty($_SESSION['free_learning_csrf'])) {
            $_SESSION['free_learning_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['free_learning_csrf'];
    }
}

if (!function_exists('mmh_free_csrf_valid')) {
    function mmh_free_csrf_valid($token)
    {
        $stored = (string) ($_SESSION['free_learning_csrf'] ?? '');
        return is_string($token) && $stored !== '' && hash_equals($stored, $token);
    }
}

if (!function_exists('mmh_free_require_admin_csrf')) {
    function mmh_free_require_admin_csrf()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['admin']) || !mmh_free_csrf_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            exit('Invalid Free Learning request.');
        }
    }
}

if (!function_exists('mmh_free_id')) {
    function mmh_free_id($prefix)
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('mmh_free_clean')) {
    function mmh_free_clean($value, $maxLength = 0)
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($maxLength > 0 && function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return $maxLength > 0 ? substr($value, 0, $maxLength) : $value;
    }
}

if (!function_exists('mmh_free_text')) {
    function mmh_free_text($value, $maxLength = 0)
    {
        $value = trim((string) $value);
        if ($maxLength > 0 && function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return $maxLength > 0 ? substr($value, 0, $maxLength) : $value;
    }
}

if (!function_exists('mmh_free_identifier')) {
    function mmh_free_identifier($value, $maxLength = 80)
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > (int) $maxLength || !preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            return null;
        }
        return $value;
    }
}

if (!function_exists('mmh_free_slug')) {
    function mmh_free_slug($value, $fallback = 'collection')
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = trim((string) $value, '-');
        return $value !== '' ? substr($value, 0, 190) : $fallback . '-' . bin2hex(random_bytes(3));
    }
}

if (!function_exists('mmh_free_status')) {
    function mmh_free_status($value, $fallback = 'draft')
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['published', 'draft', 'archived'], true) ? $value : $fallback;
    }
}

if (!function_exists('mmh_free_access_level')) {
    function mmh_free_access_level($value)
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['public', 'registered', 'enrolled_course', 'selected_courses', 'selected_students', 'admin_only'];
        return in_array($value, $allowed, true) ? $value : 'public';
    }
}

if (!function_exists('mmh_free_resource_type')) {
    function mmh_free_resource_type($value)
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['youtube_video', 'free_notes', 'worksheet', 'classified_worksheet', 'revision_guide', 'model_answer', 'external_resource', 'custom_resource'];
        return in_array($value, $allowed, true) ? $value : 'custom_resource';
    }
}

if (!function_exists('mmh_free_resource_label')) {
    function mmh_free_resource_label($type)
    {
        $labels = [
            'youtube_video' => 'YouTube Video',
            'free_notes' => 'Free Notes',
            'worksheet' => 'Worksheet',
            'classified_worksheet' => 'Classified Worksheet',
            'revision_guide' => 'Revision Guide',
            'model_answer' => 'Model Answer',
            'external_resource' => 'External Resource',
            'custom_resource' => 'Custom Resource',
        ];
        return $labels[mmh_free_resource_type($type)] ?? 'Custom Resource';
    }
}

if (!function_exists('mmh_free_default_access')) {
    function mmh_free_default_access($type)
    {
        $type = mmh_free_resource_type($type);
        if (in_array($type, ['model_answer', 'revision_guide'], true)) {
            return 'registered';
        }
        return 'public';
    }
}

if (!function_exists('mmh_free_storage_type')) {
    function mmh_free_storage_type($value)
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['youtube', 'external', 'file'], true) ? $value : 'external';
    }
}

if (!function_exists('mmh_free_stmt')) {
    function mmh_free_stmt(mysqli $conn, $sql, array $params = [])
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        if ($params) {
            $types = str_repeat('s', count($params));
            $refs = [];
            foreach ($params as $key => $value) {
                $params[$key] = $value;
                $refs[$key] = &$params[$key];
            }
            $stmt->bind_param($types, ...$refs);
        }
        return $stmt;
    }
}

if (!function_exists('mmh_free_fetch_all')) {
    function mmh_free_fetch_all(mysqli_stmt $stmt)
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

if (!function_exists('mmh_free_execute')) {
    function mmh_free_execute(mysqli $conn, $sql, array $params = [])
    {
        $stmt = mmh_free_stmt($conn, $sql, $params);
        if (!$stmt) {
            return false;
        }
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

// Runtime pages must never perform DDL. Installation and the explicit admin
// maintenance action call mmh_free_run_schema_maintenance() instead.
if (!function_exists('mmh_free_ensure_schema')) {
    function mmh_free_ensure_schema(mysqli $conn)
    {
        if (!mmh_schema_mutations_allowed()) return;
        return;
    }
}

if (!function_exists('mmh_free_run_schema_maintenance')) {
    function mmh_free_run_schema_maintenance(mysqli $conn)
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        if (!mmh_table_exists($conn, 'free_resource_collections')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `free_resource_collections` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `collection_id` VARCHAR(40) NOT NULL,
                `title` VARCHAR(190) NOT NULL,
                `slug` VARCHAR(190) NOT NULL,
                `description` TEXT NULL,
                `thumbnail_path` VARCHAR(255) NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'published',
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_collection_id` (`collection_id`),
                UNIQUE KEY `uniq_collection_slug` (`slug`),
                KEY `idx_status_sort` (`status`, `sort_order`, `title`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'free_learning_resources')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `free_learning_resources` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` VARCHAR(40) NOT NULL,
                `title` VARCHAR(190) NOT NULL,
                `slug` VARCHAR(200) NULL,
                `resource_type` VARCHAR(40) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
                `access_level` VARCHAR(40) NOT NULL DEFAULT 'public',
                `short_description` VARCHAR(500) NULL,
                `full_description` TEXT NULL,
                `thumbnail_path` VARCHAR(255) NULL,
                `storage_type` VARCHAR(20) NOT NULL DEFAULT 'external',
                `youtube_url` TEXT NULL,
                `youtube_video_id` VARCHAR(80) NULL,
                `external_url` TEXT NULL,
                `file_path` VARCHAR(255) NULL,
                `file_original_name` VARCHAR(190) NULL,
                `file_mime` VARCHAR(120) NULL,
                `file_size` BIGINT UNSIGNED NULL,
                `primary_topic` VARCHAR(120) NULL,
                `subtopic` VARCHAR(120) NULL,
                `additional_topics` TEXT NULL,
                `exam_board` VARCHAR(80) NULL,
                `syllabus_code` VARCHAR(80) NULL,
                `year_group` VARCHAR(40) NULL,
                `paper_component` VARCHAR(80) NULL,
                `calculator_mode` VARCHAR(40) NULL,
                `difficulty` VARCHAR(40) NULL,
                `estimated_duration` INT UNSIGNED NULL,
                `keywords` TEXT NULL,
                `featured` TINYINT(1) NOT NULL DEFAULT 0,
                `homepage_priority` INT UNSIGNED NOT NULL DEFAULT 0,
                `publish_at` DATETIME NULL,
                `expires_at` DATETIME NULL,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `preview_allowed` TINYINT(1) NOT NULL DEFAULT 1,
                `download_allowed` TINYINT(1) NOT NULL DEFAULT 0,
                `linked_course_id` VARCHAR(40) NULL,
                `linked_past_paper_id` VARCHAR(40) NULL,
                `related_model_answer_id` VARCHAR(40) NULL,
                `related_video_id` VARCHAR(40) NULL,
                `related_notes_id` VARCHAR(40) NULL,
                `recommended_next_id` VARCHAR(40) NULL,
                `admin_notes` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resource_id` (`resource_id`),
                KEY `idx_type_status` (`resource_type`, `status`, `sort_order`),
                KEY `idx_access_status` (`access_level`, `status`),
                KEY `idx_featured` (`featured`, `homepage_priority`, `status`),
                KEY `idx_course` (`linked_course_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'free_resource_collection_map')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `free_resource_collection_map` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` VARCHAR(40) NOT NULL,
                `collection_id` VARCHAR(40) NOT NULL,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resource_collection` (`resource_id`, `collection_id`),
                KEY `idx_collection` (`collection_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'free_resource_access_courses')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `free_resource_access_courses` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` VARCHAR(40) NOT NULL,
                `course_id` VARCHAR(40) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resource_course` (`resource_id`, `course_id`),
                KEY `idx_course` (`course_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'free_resource_access_students')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `free_resource_access_students` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` VARCHAR(40) NOT NULL,
                `user_id` INT NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_resource_student` (`resource_id`, `user_id`),
                KEY `idx_student` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        if (!mmh_table_exists($conn, 'free_resource_relations')) {
            $conn->query("CREATE TABLE IF NOT EXISTS `free_resource_relations` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `resource_id` VARCHAR(40) NOT NULL,
                `related_resource_id` VARCHAR(40) NOT NULL,
                `relation_type` VARCHAR(40) NOT NULL DEFAULT 'related',
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_relation` (`resource_id`, `related_resource_id`, `relation_type`),
                KEY `idx_resource` (`resource_id`, `sort_order`),
                KEY `idx_related` (`related_resource_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        }

        foreach (['free_resource_collections', 'free_learning_resources', 'free_resource_collection_map', 'free_resource_access_courses', 'free_resource_access_students', 'free_resource_relations'] as $table) {
            $conn->query("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci");
        }
    }
}

if (!function_exists('mmh_free_courses')) {
    function mmh_free_courses(mysqli $conn)
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

if (!function_exists('mmh_free_students')) {
    function mmh_free_students(mysqli $conn, $limit = 500)
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

if (!function_exists('mmh_free_collections')) {
    function mmh_free_collections(mysqli $conn, $publishedOnly = false)
    {
        mmh_free_ensure_schema($conn);
        $sql = 'SELECT * FROM free_resource_collections';
        if ($publishedOnly) {
            $sql .= " WHERE status = 'published'";
        }
        $sql .= ' ORDER BY sort_order ASC, title ASC, id ASC';
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

if (!function_exists('mmh_free_collection')) {
    function mmh_free_collection(mysqli $conn, $collectionIdOrSlug)
    {
        mmh_free_ensure_schema($conn);
        $value = trim((string) $collectionIdOrSlug);
        if ($value === '') {
            return null;
        }
        $stmt = mmh_free_stmt($conn, 'SELECT * FROM free_resource_collections WHERE collection_id = ? OR slug = ? LIMIT 1', [$value, $value]);
        if (!$stmt) {
            return null;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_free_resource')) {
    function mmh_free_resource(mysqli $conn, $resourceId)
    {
        mmh_free_ensure_schema($conn);
        $value = trim((string) $resourceId);
        if ($value === '') {
            return null;
        }
        $stmt = mmh_free_stmt($conn, 'SELECT * FROM free_learning_resources WHERE resource_id = ? OR CAST(id AS CHAR) = ? LIMIT 1', [$value, $value]);
        if (!$stmt) {
            return null;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('mmh_free_resource_collections')) {
    function mmh_free_resource_collections(mysqli $conn, $resourceId)
    {
        $resourceId = mmh_free_identifier($resourceId, 40);
        if (!$resourceId) {
            return [];
        }
        $stmt = mmh_free_stmt($conn, 'SELECT c.* FROM free_resource_collection_map m INNER JOIN free_resource_collections c ON c.collection_id = m.collection_id WHERE m.resource_id = ? ORDER BY m.sort_order ASC, c.sort_order ASC, c.title ASC', [$resourceId]);
        return $stmt ? mmh_free_fetch_all($stmt) : [];
    }
}

if (!function_exists('mmh_free_resource_collection_ids')) {
    function mmh_free_resource_collection_ids(mysqli $conn, $resourceId)
    {
        return array_map(function ($row) {
            return $row['collection_id'];
        }, mmh_free_resource_collections($conn, $resourceId));
    }
}

if (!function_exists('mmh_free_resource_course_ids')) {
    function mmh_free_resource_course_ids(mysqli $conn, $resourceId)
    {
        $resourceId = mmh_free_identifier($resourceId, 40);
        if (!$resourceId) {
            return [];
        }
        $stmt = mmh_free_stmt($conn, 'SELECT course_id FROM free_resource_access_courses WHERE resource_id = ? ORDER BY course_id ASC', [$resourceId]);
        return $stmt ? array_map(function ($row) { return $row['course_id']; }, mmh_free_fetch_all($stmt)) : [];
    }
}

if (!function_exists('mmh_free_resource_student_ids')) {
    function mmh_free_resource_student_ids(mysqli $conn, $resourceId)
    {
        $resourceId = mmh_free_identifier($resourceId, 40);
        if (!$resourceId) {
            return [];
        }
        $stmt = mmh_free_stmt($conn, 'SELECT user_id FROM free_resource_access_students WHERE resource_id = ? ORDER BY user_id ASC', [$resourceId]);
        return $stmt ? array_map(function ($row) { return (int) $row['user_id']; }, mmh_free_fetch_all($stmt)) : [];
    }
}

if (!function_exists('mmh_free_admin_resources')) {
    function mmh_free_admin_resources(mysqli $conn, array $filters = [], $limit = 60, $offset = 0)
    {
        mmh_free_ensure_schema($conn);
        $where = [];
        $params = [];
        foreach (['resource_type', 'status', 'access_level'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = "r.`{$key}` = ?";
                $params[] = (string) $filters[$key];
            }
        }
        if (($filters['featured'] ?? '') !== '') {
            $where[] = 'r.featured = ?';
            $params[] = (int) !empty($filters['featured']) ? '1' : '0';
        }
        if (($filters['collection_id'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM free_resource_collection_map cm WHERE cm.resource_id = r.resource_id AND cm.collection_id = ?)';
            $params[] = (string) $filters['collection_id'];
        }
        $search = mmh_free_clean($filters['search'] ?? '', 120);
        if ($search !== '') {
            $where[] = '(r.title LIKE ? OR r.short_description LIKE ? OR r.keywords LIKE ? OR r.primary_topic LIKE ? OR r.subtopic LIKE ?)';
            $like = '%' . $search . '%';
            for ($i = 0; $i < 5; $i++) {
                $params[] = $like;
            }
        }
        $limit = max(1, min(100, (int) $limit));
        $offset = max(0, (int) $offset);
        $params[] = (string) $limit;
        $params[] = (string) $offset;
        $sql = 'SELECT r.*, GROUP_CONCAT(c.title ORDER BY c.sort_order ASC, c.title ASC SEPARATOR ", ") AS collection_titles
            FROM free_learning_resources r
            LEFT JOIN free_resource_collection_map cm ON cm.resource_id = r.resource_id
            LEFT JOIN free_resource_collections c ON c.collection_id = cm.collection_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY r.id ORDER BY r.featured DESC, r.homepage_priority ASC, r.sort_order ASC, r.updated_at DESC LIMIT ? OFFSET ?';
        $stmt = mmh_free_stmt($conn, $sql, $params);
        return $stmt ? mmh_free_fetch_all($stmt) : [];
    }
}

if (!function_exists('mmh_free_parse_datetime')) {
    function mmh_free_parse_datetime($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', substr($value, 0, 16));
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) ? $value . ':00' : null;
    }
}

if (!function_exists('mmh_free_validate_https_url')) {
    function mmh_free_validate_https_url($url, $youtubeOnly = false)
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2000 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $youtubeHosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'];
        $allowedHosts = array_merge($youtubeHosts, [
            'drive.google.com', 'docs.google.com', 'www.googleapis.com',
            'sharepoint.com', 'www.sharepoint.com', 'onedrive.live.com', '1drv.ms',
            'stream.microsoft.com', 'web.microsoftstream.com', 'vimeo.com', 'www.vimeo.com',
            'loom.com', 'www.loom.com'
        ]);
        $hostAllowed = in_array($host, $allowedHosts, true) || str_ends_with($host, '.sharepoint.com');
        if ($youtubeOnly ? !in_array($host, $youtubeHosts, true) : !$hostAllowed) {
            return null;
        }
        return $url;
    }
}

if (!function_exists('mmh_free_youtube_id')) {
    function mmh_free_youtube_id($url)
    {
        $url = mmh_free_validate_https_url($url, true);
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($host === 'youtu.be') {
            return $path !== '' ? substr($path, 0, 80) : null;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (!empty($query['v'])) {
            return substr((string) $query['v'], 0, 80);
        }
        foreach (['embed/', 'shorts/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr(trim(substr($path, strlen($prefix)), '/'), 0, 80);
            }
        }
        return null;
    }
}

if (!function_exists('mmh_free_store_thumbnail')) {
    function mmh_free_store_thumbnail(array $file)
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return [true, null];
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] > 8 * 1024 * 1024) {
            return [false, 'Choose a valid image thumbnail of 8MB or smaller.'];
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $allowed, true)) {
            return [false, 'Thumbnails must be JPG, PNG, or WEBP images.'];
        }
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) { finfo_close($finfo); }
        if ($mime !== '' && !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return [false, 'The thumbnail image type is not allowed.'];
        }
        $dir = 'uploads/free-resources/thumbnails/' . date('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            return [false, 'Unable to create the thumbnail upload directory.'];
        }
        $path = $dir . '/thumbnail_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return [false, 'The thumbnail could not be saved.'];
        }
        return [true, $path];
    }
}

if (!function_exists('mmh_free_thumbnail_value')) {
    function mmh_free_thumbnail_value($existingPath, array $file = [])
    {
        [$ok, $uploaded] = mmh_free_store_thumbnail($file);
        if (!$ok) { return [false, $uploaded]; }
        if (is_string($uploaded) && $uploaded !== '') { return [true, $uploaded]; }
        $existingPath = trim((string) $existingPath);
        if ($existingPath === '' || str_contains($existingPath, '..')) { return [true, '']; }
        return [true, $existingPath];
    }
}

if (!function_exists('mmh_free_store_file')) {
    function mmh_free_store_file(array $file)
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return [true, null];
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return [false, 'Upload failed. Please choose a valid file.'];
        }
        $maxSize = 60 * 1024 * 1024;
        if ((int) $file['size'] > $maxSize) {
            return [false, 'Free Learning files must be 60MB or smaller.'];
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
        $dir = 'uploads/free-resources/' . date('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            return [false, 'Unable to create the Free Learning upload directory.'];
        }
        $safeName = 'free_resource_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $target = $dir . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return [false, 'The uploaded file could not be saved.'];
        }
        return [true, [
            'file_path' => $target,
            'file_original_name' => mmh_free_clean($file['name'], 190),
            'file_mime' => $mime ?: ($extension === 'pdf' ? 'application/pdf' : 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension)),
            'file_size' => (int) $file['size'],
        ]];
    }
}

if (!function_exists('mmh_free_save_collection')) {
    function mmh_free_save_collection(mysqli $conn, array $data, array $files = [])
    {
        mmh_free_ensure_schema($conn);
        $collectionId = mmh_free_identifier($data['collection_id'] ?? '', 40);
        $existing = $collectionId ? mmh_free_collection($conn, $collectionId) : null;
        $title = mmh_free_clean($data['title'] ?? '', 190);
        if ($title === '') {
            return [false, 'Collection title is required.'];
        }
        $slug = mmh_free_slug($data['slug'] ?? $title, 'collection');
        $description = mmh_free_text($data['description'] ?? '', 4000);
        [$thumbnailOk, $thumbnail] = mmh_free_thumbnail_value($data['thumbnail_existing'] ?? ($data['thumbnail_path'] ?? ''), $files['thumbnail_file'] ?? []);
        if (!$thumbnailOk) { return [false, $thumbnail]; }
        $status = mmh_free_status($data['status'] ?? 'published', 'published');
        $sort = max(0, (int) ($data['sort_order'] ?? 0));
        if ($existing) {
            $ok = mmh_free_execute($conn, 'UPDATE free_resource_collections SET title=?, slug=?, description=?, thumbnail_path=?, status=?, sort_order=? WHERE collection_id=?', [$title, $slug, $description, $thumbnail, $status, (string) $sort, $collectionId]);
        } else {
            $collectionId = mmh_free_id('collection');
            $ok = mmh_free_execute($conn, 'INSERT INTO free_resource_collections (collection_id, title, slug, description, thumbnail_path, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)', [$collectionId, $title, $slug, $description, $thumbnail, $status, (string) $sort]);
        }
        return [$ok, $ok ? 'Collection saved successfully.' : 'Unable to save collection.', ['collection_id' => $collectionId]];
    }
}

if (!function_exists('mmh_free_save_resource_maps')) {
    function mmh_free_save_resource_maps(mysqli $conn, $resourceId, $collectionIds, $courseIds, $studentIds, $relations)
    {
        $resourceId = mmh_free_identifier($resourceId, 40);
        if (!$resourceId) {
            return;
        }
        foreach (['free_resource_collection_map', 'free_resource_access_courses', 'free_resource_access_students', 'free_resource_relations'] as $table) {
            mmh_free_execute($conn, "DELETE FROM `{$table}` WHERE resource_id = ?", [$resourceId]);
        }
        $collectionIds = is_array($collectionIds) ? $collectionIds : [];
        $sort = 0;
        foreach ($collectionIds as $collectionId) {
            $collectionId = mmh_free_identifier($collectionId, 40);
            if ($collectionId) {
                mmh_free_execute($conn, 'INSERT IGNORE INTO free_resource_collection_map (resource_id, collection_id, sort_order) VALUES (?, ?, ?)', [$resourceId, $collectionId, (string) $sort++]);
            }
        }
        $courseIds = is_array($courseIds) ? $courseIds : [];
        foreach ($courseIds as $courseId) {
            $courseId = mmh_free_identifier($courseId, 40);
            if ($courseId) {
                mmh_free_execute($conn, 'INSERT IGNORE INTO free_resource_access_courses (resource_id, course_id) VALUES (?, ?)', [$resourceId, $courseId]);
            }
        }
        $studentIds = is_array($studentIds) ? $studentIds : [];
        foreach ($studentIds as $studentId) {
            $studentId = (int) $studentId;
            if ($studentId > 0) {
                mmh_free_execute($conn, 'INSERT IGNORE INTO free_resource_access_students (resource_id, user_id) VALUES (?, ?)', [$resourceId, (string) $studentId]);
            }
        }
        $relations = is_array($relations) ? $relations : [];
        foreach ($relations as $type => $relatedId) {
            $relatedId = mmh_free_identifier($relatedId, 40);
            if ($relatedId && $relatedId !== $resourceId) {
                mmh_free_execute($conn, 'INSERT IGNORE INTO free_resource_relations (resource_id, related_resource_id, relation_type) VALUES (?, ?, ?)', [$resourceId, $relatedId, mmh_free_clean($type, 40)]);
            }
        }
    }
}

if (!function_exists('mmh_free_save_resource')) {
    function mmh_free_save_resource(mysqli $conn, array $data, array $files)
    {
        mmh_free_ensure_schema($conn);
        $resourceId = mmh_free_identifier($data['resource_id'] ?? '', 40);
        $existing = $resourceId ? mmh_free_resource($conn, $resourceId) : null;
        $title = mmh_free_clean($data['title'] ?? '', 190);
        if ($title === '') {
            return [false, 'Resource title is required.'];
        }
        $type = mmh_free_resource_type($data['resource_type'] ?? 'custom_resource');
        $status = mmh_free_status($data['status'] ?? 'draft', 'draft');
        $access = mmh_free_access_level($data['access_level'] ?? mmh_free_default_access($type));
        $storage = $type === 'youtube_video' ? 'youtube' : mmh_free_storage_type($data['storage_type'] ?? 'external');
        $slug = mmh_free_slug($data['slug'] ?? $title, 'resource');
        $short = mmh_free_clean($data['short_description'] ?? '', 500);
        $full = mmh_free_text($data['full_description'] ?? '', 16000);
        [$thumbnailOk, $thumbnail] = mmh_free_thumbnail_value($data['thumbnail_existing'] ?? ($existing['thumbnail_path'] ?? ''), $files['thumbnail_file'] ?? []);
        if (!$thumbnailOk) { return [false, $thumbnail]; }
        $youtubeUrl = $existing['youtube_url'] ?? null;
        $youtubeId = $existing['youtube_video_id'] ?? null;
        $externalUrl = $existing['external_url'] ?? null;
        $filePath = $existing['file_path'] ?? null;
        $fileOriginal = $existing['file_original_name'] ?? null;
        $fileMime = $existing['file_mime'] ?? null;
        $fileSize = $existing['file_size'] ?? null;

        if ($storage === 'youtube') {
            $youtubeUrl = mmh_free_validate_https_url($data['youtube_url'] ?? '', true);
            $youtubeId = mmh_free_youtube_id($youtubeUrl);
            if ($youtubeUrl === null || $youtubeId === null) {
                return [false, 'YouTube resources require a valid YouTube HTTPS URL.'];
            }
            $externalUrl = null;
        } elseif ($storage === 'external') {
            $externalUrl = mmh_free_validate_https_url($data['external_url'] ?? '');
            if ($externalUrl === null) {
                return [false, 'External resources require a valid HTTPS URL.'];
            }
            $youtubeUrl = $youtubeId = null;
        } else {
            [$fileOk, $fileData] = mmh_free_store_file($files['resource_file'] ?? []);
            if (!$fileOk) {
                return [false, $fileData];
            }
            if (is_array($fileData)) {
                $filePath = $fileData['file_path'];
                $fileOriginal = $fileData['file_original_name'];
                $fileMime = $fileData['file_mime'];
                $fileSize = $fileData['file_size'];
            }
            if (!$filePath) {
                return [false, 'Upload a file or choose URL storage.'];
            }
            $youtubeUrl = $youtubeId = $externalUrl = null;
        }

        $fields = [
            'title' => $title,
            'slug' => $slug,
            'resource_type' => $type,
            'status' => $status,
            'access_level' => $access,
            'short_description' => $short,
            'full_description' => $full,
            'thumbnail_path' => $thumbnail,
            'storage_type' => $storage,
            'youtube_url' => $youtubeUrl,
            'youtube_video_id' => $youtubeId,
            'external_url' => $externalUrl,
            'file_path' => $filePath,
            'file_original_name' => $fileOriginal,
            'file_mime' => $fileMime,
            'file_size' => $fileSize,
            'primary_topic' => mmh_free_clean($data['primary_topic'] ?? '', 120),
            'subtopic' => mmh_free_clean($data['subtopic'] ?? '', 120),
            'additional_topics' => mmh_free_text($data['additional_topics'] ?? '', 2000),
            'exam_board' => mmh_free_clean($data['exam_board'] ?? '', 80),
            'syllabus_code' => mmh_free_clean($data['syllabus_code'] ?? '', 80),
            'year_group' => mmh_free_clean($data['year_group'] ?? '', 40),
            'paper_component' => mmh_free_clean($data['paper_component'] ?? '', 80),
            'calculator_mode' => mmh_free_clean($data['calculator_mode'] ?? '', 40),
            'difficulty' => mmh_free_clean($data['difficulty'] ?? '', 40),
            'estimated_duration' => (string) max(0, (int) ($data['estimated_duration'] ?? 0)),
            'keywords' => mmh_free_text($data['keywords'] ?? '', 2000),
            'featured' => !empty($data['featured']) ? '1' : '0',
            'homepage_priority' => (string) max(0, (int) ($data['homepage_priority'] ?? 0)),
            'publish_at' => mmh_free_parse_datetime($data['publish_at'] ?? ''),
            'expires_at' => mmh_free_parse_datetime($data['expires_at'] ?? ''),
            'sort_order' => (string) max(0, (int) ($data['sort_order'] ?? 0)),
            'preview_allowed' => !empty($data['preview_allowed']) ? '1' : '0',
            'download_allowed' => !empty($data['download_allowed']) ? '1' : '0',
            'linked_course_id' => mmh_free_identifier($data['linked_course_id'] ?? '', 40),
            'linked_past_paper_id' => mmh_free_identifier($data['linked_past_paper_id'] ?? '', 40),
            'related_model_answer_id' => mmh_free_identifier($data['related_model_answer_id'] ?? '', 40),
            'related_video_id' => mmh_free_identifier($data['related_video_id'] ?? '', 40),
            'related_notes_id' => mmh_free_identifier($data['related_notes_id'] ?? '', 40),
            'recommended_next_id' => mmh_free_identifier($data['recommended_next_id'] ?? '', 40),
            'admin_notes' => mmh_free_text($data['admin_notes'] ?? '', 4000),
        ];

        if ($existing) {
            $sets = [];
            foreach ($fields as $column => $value) {
                $sets[] = "`{$column}` = ?";
            }
            $params = array_values($fields);
            $params[] = $resourceId;
            $ok = mmh_free_execute($conn, 'UPDATE free_learning_resources SET ' . implode(', ', $sets) . ' WHERE resource_id = ?', $params);
        } else {
            $resourceId = mmh_free_id('resource');
            $fields = array_merge(['resource_id' => $resourceId], $fields);
            $columns = '`' . implode('`, `', array_keys($fields)) . '`';
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $ok = mmh_free_execute($conn, "INSERT INTO free_learning_resources ({$columns}) VALUES ({$placeholders})", array_values($fields));
        }
        if (!$ok) {
            return [false, 'Unable to save resource.'];
        }
        mmh_free_save_resource_maps($conn, $resourceId, $data['collection_ids'] ?? [], $data['selected_course_ids'] ?? [], $data['selected_student_ids'] ?? [], [
            'model_answer' => $fields['related_model_answer_id'] ?? '',
            'video' => $fields['related_video_id'] ?? '',
            'notes' => $fields['related_notes_id'] ?? '',
            'recommended_next' => $fields['recommended_next_id'] ?? '',
        ]);
        return [true, 'Resource saved successfully.', ['resource_id' => $resourceId]];
    }
}

if (!function_exists('mmh_free_set_status')) {
    function mmh_free_set_status(mysqli $conn, $resourceId, $status)
    {
        $resourceId = mmh_free_identifier($resourceId, 40);
        $status = mmh_free_status($status, 'draft');
        if (!$resourceId) {
            return [false, 'Invalid resource.'];
        }
        $ok = mmh_free_execute($conn, 'UPDATE free_learning_resources SET status = ? WHERE resource_id = ?', [$status, $resourceId]);
        return [$ok, $ok ? 'Resource status updated.' : 'Unable to update resource status.'];
    }
}

if (!function_exists('mmh_free_set_featured')) {
    function mmh_free_set_featured(mysqli $conn, $resourceId, $featured)
    {
        $resourceId = mmh_free_identifier($resourceId, 40);
        if (!$resourceId) {
            return [false, 'Invalid resource.'];
        }
        $ok = mmh_free_execute($conn, 'UPDATE free_learning_resources SET featured = ? WHERE resource_id = ?', [!empty($featured) ? '1' : '0', $resourceId]);
        return [$ok, $ok ? 'Featured state updated.' : 'Unable to update featured state.'];
    }
}

if (!function_exists('mmh_free_duplicate_resource')) {
    function mmh_free_duplicate_resource(mysqli $conn, $resourceId, array $options = [])
    {
        $resource = mmh_free_resource($conn, $resourceId);
        if (!$resource) { return [false, 'Resource not found.']; }
        $copyRelations = array_key_exists('copy_relations', $options) ? !empty($options['copy_relations']) : true;
        $copyAccess = array_key_exists('copy_access', $options) ? !empty($options['copy_access']) : true;
        $copyFeatured = !empty($options['copy_featured']);
        $copyPublished = !empty($options['copy_published']);
        $newId = mmh_free_id('resource');
        $copy = $resource;
        unset($copy['id'], $copy['created_at'], $copy['updated_at']);
        $copy['resource_id'] = $newId;
        $copy['title'] = $resource['title'] . ' copy';
        $copy['slug'] = mmh_free_slug($copy['title'], 'resource');
        $copy['featured'] = $copyFeatured ? (int) $resource['featured'] : 0;
        $copy['status'] = $copyPublished ? $resource['status'] : 'draft';
        $columns = '`' . implode('`, `', array_keys($copy)) . '`';
        $placeholders = implode(', ', array_fill(0, count($copy), '?'));
        if (!mmh_free_execute($conn, "INSERT INTO free_learning_resources ({$columns}) VALUES ({$placeholders})", array_values($copy))) {
            return [false, 'Unable to duplicate resource.'];
        }
        foreach (mmh_free_resource_collections($conn, $resource['resource_id']) as $collection) {
            mmh_free_execute($conn, 'INSERT IGNORE INTO free_resource_collection_map (resource_id, collection_id, sort_order) VALUES (?, ?, ?)', [$newId, $collection['collection_id'], (string) $collection['sort_order']]);
        }
        if ($copyAccess) {
            foreach (mmh_free_resource_course_ids($conn, $resource['resource_id']) as $courseId) {
                mmh_free_execute($conn, 'INSERT IGNORE INTO free_resource_access_courses (resource_id, course_id) VALUES (?, ?)', [$newId, $courseId]);
            }
            foreach (mmh_free_resource_student_ids($conn, $resource['resource_id']) as $studentId) {
                mmh_free_execute($conn, 'INSERT IGNORE INTO free_resource_access_students (resource_id, user_id) VALUES (?, ?)', [$newId, (string) $studentId]);
            }
        }
        if ($copyRelations) {
            $stmt = mmh_free_stmt($conn, 'SELECT related_resource_id, relation_type, sort_order FROM free_resource_relations WHERE resource_id = ? ORDER BY sort_order ASC', [$resource['resource_id']]);
            foreach ($stmt ? mmh_free_fetch_all($stmt) : [] as $relation) {
                mmh_free_execute($conn, 'INSERT IGNORE INTO free_resource_relations (resource_id, related_resource_id, relation_type, sort_order) VALUES (?, ?, ?, ?)', [$newId, $relation['related_resource_id'], $relation['relation_type'], (string) $relation['sort_order']]);
            }
        }
        return [true, 'Resource duplicated. Uploaded files remain shared safely.', ['resource_id' => $newId]];
    }
}

if (!function_exists('mmh_free_archive_resource')) {
    function mmh_free_archive_resource(mysqli $conn, $resourceId)
    {
        return mmh_free_set_status($conn, $resourceId, 'archived');
    }
}

if (!function_exists('mmh_free_restore_resource')) {
    function mmh_free_restore_resource(mysqli $conn, $resourceId)
    {
        return mmh_free_set_status($conn, $resourceId, 'draft');
    }
}

if (!function_exists('mmh_free_resource_relation_count')) {
    function mmh_free_resource_relation_count(mysqli $conn, $resourceId)
    {
        $resourceId = mmh_free_identifier($resourceId, 40);
        if (!$resourceId) { return 0; }
        $stmt = mmh_free_stmt($conn, 'SELECT COUNT(*) AS total FROM free_resource_relations WHERE resource_id = ? OR related_resource_id = ?', [$resourceId, $resourceId]);
        if (!$stmt) { return 0; }
        $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('mmh_free_delete_resource')) {
    function mmh_free_delete_resource(mysqli $conn, $resourceId, $deleteUploadedFile = false)
    {
        $resource = mmh_free_resource($conn, $resourceId);
        if (!$resource) { return [false, 'Resource not found.']; }
        $resourceId = $resource['resource_id'];
        $filePath = (string) ($resource['file_path'] ?? '');
        foreach (['free_resource_collection_map', 'free_resource_access_courses', 'free_resource_access_students', 'free_resource_relations'] as $table) {
            mmh_free_execute($conn, "DELETE FROM `{$table}` WHERE resource_id = ?", [$resourceId]);
        }
        mmh_free_execute($conn, 'DELETE FROM free_resource_relations WHERE related_resource_id = ?', [$resourceId]);
        $ok = mmh_free_execute($conn, 'DELETE FROM free_learning_resources WHERE resource_id = ?', [$resourceId]);
        if ($ok && $deleteUploadedFile && $filePath !== '' && !str_contains($filePath, '..') && is_file($filePath)) { @unlink($filePath); }
        return [$ok, $ok ? ($deleteUploadedFile ? 'Resource and uploaded file deleted.' : 'Resource deleted. Uploaded file was kept.') : 'Unable to delete resource.'];
    }
}

if (!function_exists('mmh_free_set_collection_status')) {
    function mmh_free_set_collection_status(mysqli $conn, $collectionId, $status)
    {
        $collectionId = mmh_free_identifier($collectionId, 40);
        $status = mmh_free_status($status, 'draft');
        if (!$collectionId) { return [false, 'Invalid collection.']; }
        $ok = mmh_free_execute($conn, 'UPDATE free_resource_collections SET status = ? WHERE collection_id = ?', [$status, $collectionId]);
        return [$ok, $ok ? 'Collection status updated.' : 'Unable to update collection status.'];
    }
}

if (!function_exists('mmh_free_delete_collection')) {
    function mmh_free_delete_collection(mysqli $conn, $collectionId)
    {
        $collectionId = mmh_free_identifier($collectionId, 40);
        if (!$collectionId) {
            return [false, 'Invalid collection.'];
        }
        mmh_free_execute($conn, 'DELETE FROM free_resource_collection_map WHERE collection_id = ?', [$collectionId]);
        $ok = mmh_free_execute($conn, 'DELETE FROM free_resource_collections WHERE collection_id = ?', [$collectionId]);
        return [$ok, $ok ? 'Collection deleted. Resources were not deleted.' : 'Unable to delete collection.'];
    }
}

if (!function_exists('mmh_free_admin_resource_count')) {
    function mmh_free_admin_resource_count(mysqli $conn, array $filters = [])
    {
        $where = []; $params = [];
        foreach (['resource_type', 'status', 'access_level'] as $key) {
            if (($filters[$key] ?? '') !== '') { $where[] = "r.`{$key}` = ?"; $params[] = (string) $filters[$key]; }
        }
        if (($filters['featured'] ?? '') !== '') { $where[] = 'r.featured = ?'; $params[] = !empty($filters['featured']) ? '1' : '0'; }
        if (($filters['collection_id'] ?? '') !== '') { $where[] = 'EXISTS (SELECT 1 FROM free_resource_collection_map cm WHERE cm.resource_id = r.resource_id AND cm.collection_id = ?)'; $params[] = (string) $filters['collection_id']; }
        $search = mmh_free_clean($filters['search'] ?? '', 120);
        if ($search !== '') { $where[] = '(r.title LIKE ? OR r.short_description LIKE ? OR r.keywords LIKE ?)'; $like = '%' . $search . '%'; $params = array_merge($params, [$like, $like, $like]); }
        $stmt = mmh_free_stmt($conn, 'SELECT COUNT(*) AS total FROM free_learning_resources r' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);
        if (!$stmt) { return 0; }
        $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('mmh_free_collection_summaries')) {
    function mmh_free_collection_summaries(mysqli $conn)
    {
        $sql = "SELECT c.*, COUNT(DISTINCT r.id) AS resource_count,
            COUNT(DISTINCT CASE WHEN r.status = 'published' THEN r.id END) AS published_count,
            COUNT(DISTINCT CASE WHEN r.status = 'draft' THEN r.id END) AS draft_count,
            COUNT(DISTINCT CASE WHEN r.featured = 1 THEN r.id END) AS featured_count
            FROM free_resource_collections c
            LEFT JOIN free_resource_collection_map cm ON cm.collection_id = c.collection_id
            LEFT JOIN free_learning_resources r ON r.resource_id = cm.resource_id
            GROUP BY c.id ORDER BY c.sort_order ASC, c.title ASC";
        $result = $conn->query($sql); $rows = [];
        if ($result) { while ($row = $result->fetch_assoc()) { $rows[] = $row; } }
        return $rows;
    }
}

if (!function_exists('mmh_free_reorder_collections')) {
    function mmh_free_reorder_collections(mysqli $conn, array $collectionIds)
    {
        $seen = []; $sort = 0;
        foreach ($collectionIds as $collectionId) {
            $collectionId = mmh_free_identifier($collectionId, 40);
            if (!$collectionId || isset($seen[$collectionId])) { continue; }
            $seen[$collectionId] = true;
            if (!mmh_free_execute($conn, 'UPDATE free_resource_collections SET sort_order = ? WHERE collection_id = ?', [(string) $sort++, $collectionId])) { return [false, 'Unable to save collection order.']; }
        }
        return [true, 'Collection order saved.'];
    }
}

if (!function_exists('mmh_free_thumbnail_options')) {
    function mmh_free_thumbnail_options(mysqli $conn, $limit = 120)
    {
        $limit = max(1, min(300, (int) $limit));
        $sql = "SELECT thumbnail_path FROM free_learning_resources WHERE thumbnail_path IS NOT NULL AND thumbnail_path <> ''
            UNION SELECT thumbnail_path FROM free_resource_collections WHERE thumbnail_path IS NOT NULL AND thumbnail_path <> '' LIMIT {$limit}";
        $result = $conn->query($sql); $paths = [];
        if ($result) { while ($row = $result->fetch_assoc()) { $paths[] = $row['thumbnail_path']; } }
        return $paths;
    }
}

if (!function_exists('mmh_free_resource_search')) {
    function mmh_free_resource_search(mysqli $conn, $query, $limit = 12)
    {
        $query = mmh_free_clean($query, 100); if ($query === '') { return []; }
        $limit = max(1, min(30, (int) $limit)); $like = '%' . $query . '%';
        $stmt = mmh_free_stmt($conn, "SELECT resource_id, title, resource_type, status FROM free_learning_resources WHERE title LIKE ? ORDER BY status = 'published' DESC, updated_at DESC LIMIT {$limit}", [$like]);
        return $stmt ? mmh_free_fetch_all($stmt) : [];
    }
}

if (!function_exists('mmh_free_orphan_files')) {
    function mmh_free_orphan_files(mysqli $conn)
    {
        $root = 'uploads/free-resources'; if (!is_dir($root)) { return []; }
        $used = []; $result = $conn->query("SELECT file_path, thumbnail_path FROM free_learning_resources UNION SELECT '' AS file_path, thumbnail_path FROM free_resource_collections");
        if ($result) { while ($row = $result->fetch_assoc()) { foreach (['file_path','thumbnail_path'] as $field) { if (!empty($row[$field])) { $used[str_replace('\\\\','/', $row[$field])] = true; } } } }
        $orphans = []; $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) { if ($file->isFile()) { $path = str_replace('\\\\','/', $file->getPathname()); if (!isset($used[$path])) { $orphans[] = $path; } } }
        return $orphans;
    }
}

if (!function_exists('mmh_free_current_student_id')) {
    function mmh_free_current_student_id(mysqli $conn)
    {
        if (empty($_SESSION['username'])) {
            return null;
        }
        $username = trim((string) $_SESSION['username']);
        $stmt = mmh_free_stmt($conn, 'SELECT user_id FROM users WHERE username = ? OR CAST(user_id AS CHAR) = ? LIMIT 1', [$username, $username]);
        if (!$stmt) {
            return null;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['user_id'] : null;
    }
}

if (!function_exists('mmh_free_student_enrolled')) {
    function mmh_free_student_enrolled(mysqli $conn, $studentId, $courseId)
    {
        $courseId = mmh_free_identifier($courseId, 40);
        $studentId = (int) $studentId;
        if (!$courseId || $studentId <= 0) {
            return false;
        }
        $stmt = mmh_free_stmt($conn, 'SELECT id FROM course_logs WHERE user_id = ? AND course_id = ? LIMIT 1', [(string) $studentId, $courseId]);
        if (!$stmt) {
            return false;
        }
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mmh_free_is_published_now')) {
    function mmh_free_is_published_now(array $resource)
    {
        if (($resource['status'] ?? '') !== 'published') {
            return false;
        }
        $publishAt = trim((string) ($resource['publish_at'] ?? ''));
        if ($publishAt !== '' && strtotime($publishAt) > time()) {
            return false;
        }
        $expiresAt = trim((string) ($resource['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return false;
        }
        return true;
    }
}

if (!function_exists('mmh_free_can_access_resource')) {
    function mmh_free_can_access_resource(mysqli $conn, array $resource)
    {
        if (!empty($_SESSION['admin'])) {
            return [true, 'Admin access granted.'];
        }
        if (!mmh_free_is_published_now($resource)) {
            return [false, 'This resource is not published.'];
        }
        $access = mmh_free_access_level($resource['access_level'] ?? 'public');
        if ($access === 'admin_only') {
            return [false, 'This resource is hidden.'];
        }
        if ($access === 'public') {
            return [true, 'Public resource.'];
        }
        $studentId = mmh_free_current_student_id($conn);
        if (!$studentId) {
            return [false, 'Please log in to access this resource.'];
        }
        if ($access === 'registered') {
            return [true, 'Registered-user access granted.'];
        }
        if ($access === 'enrolled_course') {
            $courseId = $resource['linked_course_id'] ?? '';
            return $courseId && mmh_free_student_enrolled($conn, $studentId, $courseId)
                ? [true, 'Enrollment access granted.']
                : [false, 'This resource requires enrollment in the linked course.'];
        }
        if ($access === 'selected_courses') {
            $stmt = mmh_free_stmt($conn, 'SELECT course_id FROM free_resource_access_courses WHERE resource_id = ?', [$resource['resource_id']]);
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    if (mmh_free_student_enrolled($conn, $studentId, $row['course_id'])) {
                        $stmt->close();
                        return [true, 'Selected-course access granted.'];
                    }
                }
                $stmt->close();
            }
            return [false, 'This resource requires enrollment in a selected course.'];
        }
        if ($access === 'selected_students') {
            $stmt = mmh_free_stmt($conn, 'SELECT id FROM free_resource_access_students WHERE resource_id = ? AND user_id = ? LIMIT 1', [$resource['resource_id'], (string) $studentId]);
            if (!$stmt) {
                return [false, 'Access denied.'];
            }
            $stmt->execute();
            $ok = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            return $ok ? [true, 'Selected-student access granted.'] : [false, 'This resource is restricted to selected students.'];
        }
        return [false, 'Access denied.'];
    }
}

if (!function_exists('mmh_free_resource_event_type')) {
    function mmh_free_resource_event_type(array $resource, $isDownload = false)
    {
        if ($isDownload) {
            return 'free_resource_downloaded';
        }
        $type = mmh_free_resource_type($resource['resource_type'] ?? 'custom_resource');
        if ($type === 'youtube_video') {
            return 'free_video_opened';
        }
        if ($type === 'free_notes' || $type === 'revision_guide') {
            return 'free_notes_opened';
        }
        if ($type === 'worksheet' || $type === 'classified_worksheet') {
            return 'free_worksheet_opened';
        }
        return 'free_resource_opened';
    }
}

if (!function_exists('mmh_free_log_resource_event')) {
    function mmh_free_log_resource_event(mysqli $conn, array $resource, $isDownload = false)
    {
        if (!function_exists('mmh_log_event')) {
            return;
        }
        $studentId = mmh_free_current_student_id($conn);
        if (!$studentId) {
            return;
        }
        mmh_log_event($conn, $studentId, mmh_free_resource_event_type($resource, $isDownload), [
            'course_id' => $resource['linked_course_id'] ?? null,
            'meta' => [
                'resource_id' => $resource['resource_id'] ?? '',
                'resource_type' => $resource['resource_type'] ?? '',
                'title' => $resource['title'] ?? '',
                'primary_topic' => $resource['primary_topic'] ?? '',
                'subtopic' => $resource['subtopic'] ?? '',
            ],
        ]);
    }
}

if (!function_exists('mmh_free_open_resource')) {
    function mmh_free_open_resource(mysqli $conn, $resourceId)
    {
        $resource = mmh_free_resource($conn, $resourceId);
        if (!$resource) {
            http_response_code(404);
            echo 'Free Learning resource not found.';
            exit;
        }
        [$allowed, $reason] = mmh_free_can_access_resource($conn, $resource);
        if (!$allowed) {
            http_response_code(empty($_SESSION['username']) && empty($_SESSION['admin']) ? 401 : 403);
            echo $reason;
            exit;
        }
        $isDownload = !empty($_GET['download']);
        if ($isDownload && (int) ($resource['download_allowed'] ?? 0) !== 1) {
            http_response_code(403);
            echo 'Download is not enabled for this resource.';
            exit;
        }
        mmh_free_log_resource_event($conn, $resource, $isDownload);
        $storage = mmh_free_storage_type($resource['storage_type'] ?? '');
        if ($storage === 'youtube') {
            $target = mmh_free_validate_https_url($resource['youtube_url'] ?? '', true);
            if ($target === null) {
                http_response_code(500);
                echo 'Video link is not configured correctly.';
                exit;
            }
            header('Location: ' . $target, true, 302);
            exit;
        }
        if ($storage === 'external') {
            $target = mmh_free_validate_https_url($resource['external_url'] ?? '');
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
        $mime = $resource['file_mime'] ?: 'application/octet-stream';
        $filename = $resource['file_original_name'] ?: basename($path);
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

if (!function_exists('mmh_free_visible_filters')) {
    function mmh_free_visible_filters(array $filters = [])
    {
        return [
            'search' => mmh_free_clean($filters['search'] ?? '', 120),
            'resource_type' => mmh_free_resource_type($filters['resource_type'] ?? ''),
            'collection_id' => mmh_free_identifier($filters['collection_id'] ?? '', 40) ?: '',
            'slug' => mmh_free_clean($filters['slug'] ?? '', 190),
            'featured' => $filters['featured'] ?? '',
        ];
    }
}

if (!function_exists('mmh_free_list_resources')) {
    function mmh_free_list_resources(mysqli $conn, array $filters = [], $limit = 12, $offset = 0)
    {
        mmh_free_ensure_schema($conn);
        $where = ["r.status = 'published'", "r.access_level <> 'admin_only'", "(r.publish_at IS NULL OR r.publish_at <= NOW())", "(r.expires_at IS NULL OR r.expires_at >= NOW())"];
        $params = [];
        if (($filters['resource_type'] ?? '') !== '') {
            $where[] = 'r.resource_type = ?';
            $params[] = mmh_free_resource_type($filters['resource_type']);
        }
        if (($filters['featured'] ?? '') !== '') {
            $where[] = 'r.featured = ?';
            $params[] = !empty($filters['featured']) ? '1' : '0';
        }
        if (($filters['collection_id'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM free_resource_collection_map cm WHERE cm.resource_id = r.resource_id AND cm.collection_id = ?)';
            $params[] = (string) $filters['collection_id'];
        } elseif (($filters['slug'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM free_resource_collection_map cm INNER JOIN free_resource_collections c ON c.collection_id = cm.collection_id WHERE cm.resource_id = r.resource_id AND c.slug = ? AND c.status = "published")';
            $params[] = (string) $filters['slug'];
        }
        $search = mmh_free_clean($filters['search'] ?? '', 120);
        if ($search !== '') {
            $where[] = '(r.title LIKE ? OR r.short_description LIKE ? OR r.keywords LIKE ? OR r.primary_topic LIKE ?)';
            $like = '%' . $search . '%';
            for ($i = 0; $i < 4; $i++) {
                $params[] = $like;
            }
        }
        $limit = max(1, min(60, (int) $limit));
        $offset = max(0, (int) $offset);
        $params[] = (string) $limit;
        $params[] = (string) $offset;
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'newest')));
        $orderBy = 'r.created_at DESC, r.sort_order ASC, r.id DESC';
        if ($sort === 'az') {
            $orderBy = 'r.title ASC, r.id ASC';
        } elseif ($sort === 'featured') {
            $orderBy = 'r.featured DESC, r.homepage_priority ASC, r.sort_order ASC, r.created_at DESC';
        }
        $sql = 'SELECT r.*, GROUP_CONCAT(c.title ORDER BY c.sort_order ASC, c.title ASC SEPARATOR ", ") AS collection_titles
            FROM free_learning_resources r
            LEFT JOIN free_resource_collection_map cm ON cm.resource_id = r.resource_id
            LEFT JOIN free_resource_collections c ON c.collection_id = cm.collection_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY r.id
            ORDER BY ' . $orderBy . '
            LIMIT ? OFFSET ?';
        $stmt = mmh_free_stmt($conn, $sql, $params);
        return $stmt ? mmh_free_fetch_all($stmt) : [];
    }
}

if (!function_exists('mmh_free_count_resources')) {
    function mmh_free_count_resources(mysqli $conn, array $filters = [])
    {
        mmh_free_ensure_schema($conn);
        $where = ["r.status = 'published'", "r.access_level <> 'admin_only'", "(r.publish_at IS NULL OR r.publish_at <= NOW())", "(r.expires_at IS NULL OR r.expires_at >= NOW())"];
        $params = [];
        if (($filters['resource_type'] ?? '') !== '') {
            $where[] = 'r.resource_type = ?';
            $params[] = mmh_free_resource_type($filters['resource_type']);
        }
        if (($filters['featured'] ?? '') !== '') {
            $where[] = 'r.featured = ?';
            $params[] = !empty($filters['featured']) ? '1' : '0';
        }
        if (($filters['collection_id'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM free_resource_collection_map cm WHERE cm.resource_id = r.resource_id AND cm.collection_id = ?)';
            $params[] = (string) $filters['collection_id'];
        } elseif (($filters['slug'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM free_resource_collection_map cm INNER JOIN free_resource_collections c ON c.collection_id = cm.collection_id WHERE cm.resource_id = r.resource_id AND c.slug = ? AND c.status = "published")';
            $params[] = (string) $filters['slug'];
        }
        $search = mmh_free_clean($filters['search'] ?? '', 120);
        if ($search !== '') {
            $where[] = '(r.title LIKE ? OR r.short_description LIKE ? OR r.keywords LIKE ? OR r.primary_topic LIKE ?)';
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        $stmt = mmh_free_stmt($conn, 'SELECT COUNT(*) AS total FROM free_learning_resources r WHERE ' . implode(' AND ', $where), $params);
        if (!$stmt) {
            return 0;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['total'] ?? 0);
    }
}

if (!function_exists('mmh_free_public_collections')) {
    function mmh_free_public_collections(mysqli $conn, $limit = 0)
    {
        mmh_free_ensure_schema($conn);
        $sql = "SELECT c.*, COUNT(DISTINCT r.id) AS resource_count
            FROM free_resource_collections c
            LEFT JOIN free_resource_collection_map cm ON cm.collection_id = c.collection_id
            LEFT JOIN free_learning_resources r ON r.resource_id = cm.resource_id
                AND r.status = 'published'
                AND r.access_level <> 'admin_only'
                AND (r.publish_at IS NULL OR r.publish_at <= NOW())
                AND (r.expires_at IS NULL OR r.expires_at >= NOW())
            WHERE c.status = 'published'
            GROUP BY c.id
            ORDER BY c.sort_order ASC, c.title ASC, c.id ASC";
        $limit = (int) $limit;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . min(48, $limit);
        }
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

if (!function_exists('mmh_free_related_resources')) {
    function mmh_free_related_resources(mysqli $conn, $resourceId, $limit = 6)
    {
        $resourceId = mmh_free_identifier($resourceId, 40);
        if (!$resourceId) {
            return [];
        }
        $limit = max(1, min(12, (int) $limit));
        $sql = "SELECT r.*, rel.relation_type
            FROM free_resource_relations rel
            INNER JOIN free_learning_resources r ON r.resource_id = rel.related_resource_id
            WHERE rel.resource_id = ?
              AND r.status = 'published'
              AND r.access_level <> 'admin_only'
              AND (r.publish_at IS NULL OR r.publish_at <= NOW())
              AND (r.expires_at IS NULL OR r.expires_at >= NOW())
            ORDER BY rel.sort_order ASC, r.sort_order ASC, r.title ASC
            LIMIT {$limit}";
        $stmt = mmh_free_stmt($conn, $sql, [$resourceId]);
        return $stmt ? mmh_free_fetch_all($stmt) : [];
    }
}

if (!function_exists('mmh_free_featured_resources')) {
    function mmh_free_featured_resources(mysqli $conn, $limit = 6)
    {
        return mmh_free_list_resources($conn, ['featured' => '1'], $limit, 0);
    }
}

if (!function_exists('mmh_free_latest_resources')) {
    function mmh_free_latest_resources(mysqli $conn, $limit = 8)
    {
        return mmh_free_list_resources($conn, [], $limit, 0);
    }
}

if (!function_exists('mmh_free_resources_by_type')) {
    function mmh_free_resources_by_type(mysqli $conn, $type, $limit = 12)
    {
        return mmh_free_list_resources($conn, ['resource_type' => mmh_free_resource_type($type)], $limit, 0);
    }
}

if (!function_exists('mmh_free_resources_by_collection')) {
    function mmh_free_resources_by_collection(mysqli $conn, $collectionIdOrSlug, $limit = 12)
    {
        $collection = mmh_free_collection($conn, $collectionIdOrSlug);
        if (!$collection) {
            return [];
        }
        return mmh_free_list_resources($conn, ['collection_id' => $collection['collection_id']], $limit, 0);
    }
}

if (!function_exists('mmh_free_resource_view_state')) {
    function mmh_free_resource_view_state(mysqli $conn, array $resource)
    {
        if (!mmh_free_is_published_now($resource) || mmh_free_access_level($resource['access_level'] ?? '') === 'admin_only') {
            return ['visible' => false, 'available' => false, 'label' => 'Hidden', 'message' => 'This resource is not visible.', 'class' => 'hidden', 'actions' => []];
        }
        [$allowed, $reason] = mmh_free_can_access_resource($conn, $resource);
        if ($allowed) {
            $base = 'resources/open/' . rawurlencode((string) $resource['resource_id']);
            $actions = [];
            if ((int) ($resource['preview_allowed'] ?? 0) === 1) {
                $actions[] = ['label' => mmh_free_resource_type($resource['resource_type'] ?? '') === 'youtube_video' ? 'Watch' : 'Open', 'url' => $base, 'primary' => true];
            }
            if ((int) ($resource['download_allowed'] ?? 0) === 1 && ($resource['storage_type'] ?? '') === 'file') {
                $actions[] = ['label' => 'Download', 'url' => $base . '?download=1', 'primary' => false];
            }
            if (!$actions) {
                $actions[] = ['label' => 'Open', 'url' => $base, 'primary' => true];
            }
            return ['visible' => true, 'available' => true, 'label' => 'Available', 'message' => 'Ready to open securely.', 'class' => 'available', 'actions' => $actions];
        }
        if (empty($_SESSION['username'])) {
            return ['visible' => true, 'available' => false, 'label' => 'Login Required', 'message' => 'Sign in to access this resource.', 'class' => 'login', 'actions' => []];
        }
        return ['visible' => true, 'available' => false, 'label' => 'Restricted', 'message' => $reason, 'class' => 'locked', 'actions' => []];
    }
}

if (!function_exists('mmh_free_recently_opened_resources')) {
    function mmh_free_recently_opened_resources(mysqli $conn, $userId, $limit = 6)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || !mmh_table_exists($conn, 'learning_events')) {
            return [];
        }
        $events = ['free_resource_opened', 'free_resource_downloaded', 'free_video_opened', 'free_notes_opened', 'free_worksheet_opened'];
        $placeholders = implode(',', array_fill(0, count($events), '?'));
        $params = array_merge([(string) $userId], $events);
        $stmt = mmh_free_stmt($conn, "SELECT event_type, meta, created_at FROM learning_events WHERE user_id = ? AND event_type IN ({$placeholders}) ORDER BY created_at DESC LIMIT 30", $params);
        if (!$stmt) {
            return [];
        }
        $rows = mmh_free_fetch_all($stmt);
        $ids = [];
        $seen = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            $rid = is_array($meta) ? mmh_free_identifier($meta['resource_id'] ?? '', 40) : null;
            if ($rid && empty($seen[$rid])) {
                $ids[] = $rid;
                $seen[$rid] = $row;
            }
            if (count($ids) >= $limit) {
                break;
            }
        }
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = mmh_free_stmt($conn, "SELECT * FROM free_learning_resources WHERE resource_id IN ({$ph})", $ids);
        if (!$stmt) {
            return [];
        }
        $resources = mmh_free_fetch_all($stmt);
        $byId = [];
        foreach ($resources as $resource) {
            $byId[$resource['resource_id']] = $resource;
        }
        $recent = [];
        foreach ($ids as $rid) {
            if (!isset($byId[$rid])) {
                continue;
            }
            $item = $byId[$rid];
            $item['event_type'] = $seen[$rid]['event_type'] ?? '';
            $item['opened_at'] = $seen[$rid]['created_at'] ?? '';
            $recent[] = $item;
        }
        return $recent;
    }
}
?>
