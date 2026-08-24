<?php
/**
 * Shared security primitives for the authenticated admin surface.
 *
 * This file intentionally has no database dependency so it can also be used
 * by bootstrap files to stop direct execution before any application output.
 */

if (!function_exists('mmh_admin_block_direct_internal_file')) {
    function mmh_admin_block_direct_internal_file(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $root = realpath(dirname(__DIR__));
        if ($script === false || $root === false) {
            return;
        }

        $script = str_replace('\\', '/', $script);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (strncmp($script, $root . '/', strlen($root) + 1) !== 0) {
            return;
        }

        $relative = ltrim(substr($script, strlen($root)), '/');
        $publicEntryPoints = ['index.php'];
        if (in_array($relative, $publicEntryPoints, true)) {
            return;
        }

        $internalPrefixes = [
            'views/',
            'connection/',
            'inc/',
            'database/',
            'vendor/',
        ];
        $isInternal = false;
        foreach ($internalPrefixes as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                $isInternal = true;
                break;
            }
        }
        if ($relative === '__init.php') {
            $isInternal = true;
        }

        if ($isInternal) {
            http_response_code(404);
            exit('Not found.');
        }
    }
}

if (!function_exists('mmh_admin_response_headers')) {
    function mmh_admin_response_headers(): void
    {
        if (function_exists('mmh_send_private_response_headers')) {
            mmh_send_private_response_headers();
            return;
        }
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Cookie');
    }
}

if (!function_exists('mmh_admin_csrf_token')) {
    function mmh_admin_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['mmh_admin_csrf_token'])) {
            $_SESSION['mmh_admin_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['mmh_admin_csrf_token'];
    }
}

if (!function_exists('mmh_admin_csrf_rotate')) {
    function mmh_admin_csrf_rotate(): string
    {
        unset($_SESSION['mmh_admin_csrf_token']);
        return mmh_admin_csrf_token();
    }
}

if (!function_exists('mmh_admin_csrf_request_token')) {
    function mmh_admin_csrf_request_token(): string
    {
        $headerToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '');
        if ($headerToken !== '') {
            return $headerToken;
        }
        foreach (['mmh_csrf_token', 'csrf_token', '_token'] as $field) {
            if (isset($_POST[$field]) && is_string($_POST[$field])) {
                return $_POST[$field];
            }
        }
        return '';
    }
}

if (!function_exists('mmh_admin_csrf_valid')) {
    function mmh_admin_csrf_valid(?string $token = null): bool
    {
        $token = $token ?? mmh_admin_csrf_request_token();
        $stored = (string) ($_SESSION['mmh_admin_csrf_token'] ?? '');
        return $stored !== '' && $token !== '' && hash_equals($stored, $token);
    }
}

if (!function_exists('mmh_admin_is_ajax')) {
    function mmh_admin_is_ajax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }
}

if (!function_exists('mmh_admin_forbidden')) {
    function mmh_admin_forbidden(string $message = 'Forbidden'): void
    {
        http_response_code(403);
        if (mmh_admin_is_ajax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 0, 'message' => $message]);
        } else {
            echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        }
        exit;
    }
}

if (!function_exists('mmh_admin_fresh_session_user')) {
    /** Read the role/status from the database on every protected admin request. */
    function mmh_admin_fresh_session_user(): ?array
    {
        $username = trim((string) ($_SESSION['admin'] ?? ''));
        if ($username === '') {
            return null;
        }
        $conn = ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null;
        static $loading = false;
        if (!$conn && !$loading) {
            $config = dirname(__DIR__) . '/connection/config.php';
            if (is_file($config)) {
                $loading = true;
                require_once $config;
                $loading = false;
                $conn = ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null;
            }
        }
        if (!$conn) {
            return null;
        }
        $hasArchive = false;
        $columnCheck = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'archived_at' LIMIT 1");
        if ($columnCheck) {
            $columnCheck->execute();
            $hasArchive = (bool) $columnCheck->get_result()->fetch_assoc();
            $columnCheck->close();
        }
        $archiveSelect = $hasArchive ? 'archived_at' : 'NULL AS archived_at';
        $stmt = $conn->prepare("SELECT user_id, username, role, status, {$archiveSelect} FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!is_array($row) || strtolower((string) ($row['role'] ?? '')) !== 'admin' || (string) ($row['status'] ?? '') !== '1' || trim((string) ($row['archived_at'] ?? '')) !== '') {
            return null;
        }
        return $row;
    }
}

if (!function_exists('mmh_admin_revoke_stale_session')) {
    function mmh_admin_revoke_stale_session(): void
    {
        $username = trim((string) ($_SESSION['admin'] ?? ''));
        unset($_SESSION['admin'], $_SESSION['mmh_admin_csrf_token']);
        if ($username !== '') {
            $_SESSION['username'] = $username;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

if (!function_exists('mmh_admin_require_admin')) {
    function mmh_admin_require_admin(bool $redirectGet = true): void
    {
        if (mmh_admin_fresh_session_user() !== null) {
            return;
        }
        if (!empty($_SESSION['admin'])) {
            mmh_admin_revoke_stale_session();
        }
        if ($redirectGet && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $base = function_exists('mmh_current_request_base_url') ? mmh_current_request_base_url() : '';
            header('Location: ' . rtrim($base, '/') . '/auth/login');
            exit;
        }
        mmh_admin_forbidden('Administrator access is required.');
    }
}

if (!function_exists('mmh_admin_require_mutation')) {
    function mmh_admin_require_mutation(): void
    {
        mmh_admin_require_admin(false);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('Method Not Allowed');
        }
        if (!mmh_admin_csrf_valid()) {
            mmh_admin_forbidden('Invalid or missing security token.');
        }
    }
}

if (!function_exists('mmh_admin_allowed_page')) {
    function mmh_admin_allowed_page(string $page): bool
    {
        static $pages = [
            'assignment-submissions', 'assignments', 'categories', 'course-content-preview',
            'course-content', 'courses', 'dashboard', 'exam-submissions', 'exams',
            'admin-management',
            'file-upload', 'files', 'free-learning', 'live-sessions', 'parent-reports',
            'past-papers', 'previous-progress', 'profile', 'recovery-plan-assignments',
            'recovery-plan-templates', 'recovery-plan', 'settings', 'timed-exam-submissions',
            'users',
        ];
        return in_array($page, $pages, true);
    }
}

if (!function_exists('mmh_admin_allowed_handler')) {
    function mmh_admin_allowed_handler(string $page, string $action): ?string
    {
        static $files = [
            'save-admin-management.php', 'add-assignment.php', 'add-category.php', 'add-course.php', 'add-exam.php',
            'add-item.php', 'add-section.php', 'add-user.php', 'addBalance-user.php',
            'addUser-course.php', 'archive-resource-free-learning.php', 'assign-recovery-plan-template.php',
            'bulk-import-past-papers.php', 'bulk-items.php', 'bulk-preview-past-papers.php',
            'cleanup-files-free-learning.php', 'delete-assignment.php', 'delete-category.php',
            'delete-collection-free-learning.php', 'delete-course.php', 'delete-exam.php',
            'delete-file.php', 'delete-item.php', 'delete-occurrence-live-session.php',
            'delete-paper-past-papers.php', 'delete-previous-progress.php', 'delete-resource-free-learning.php',
            'delete-resource-past-papers.php', 'delete-schedule-live-session.php', 'delete-section.php',
            'delete-user.php', 'download-timed-exam-answer.php', 'duplicate-item.php',
            'duplicate-paper-past-papers.php', 'duplicate-resource-free-learning.php', 'duplicate-section.php',
            'edit-assignment.php', 'edit-category.php', 'edit-course.php', 'edit-exam.php',
            'edit-user.php', 'feature-resource-free-learning.php', 'feedback-assignment.php',
            'feedback-exam.php', 'form-item.php', 'form-section.php', 'get-courses.php',
            'grade-timed-exam.php', 'import-drive-past-papers.php', 'import-legacy-submission.php',
            'import-previous-progress.php', 'integrity-section.php', 'items-item.php',
            'keep-alive.php', 'maintenance-free-learning.php', 'notification-course.php',
            'notification-user.php', 'profile-settings.php', 'purge-resource-free-learning.php',
            'reanalyze-drive-past-papers.php', 'reorder-collections-free-learning.php',
            'restore-resource-free-learning.php', 'save-attendance-live-session.php',
            'save-board-past-papers.php', 'save-collection-free-learning.php', 'save-paper-past-papers.php',
            'save-previous-progress.php', 'save-recovery-plan-template.php', 'save-recovery-plan.php',
            'save-resource-free-learning.php', 'save-resource-past-papers.php', 'save-schedule-live-session.php',
            'save-syllabus-past-papers.php', 'scan-drive-past-papers.php', 'search-resource-free-learning.php',
            'status-collection-free-learning.php', 'status-course.php', 'status-item.php',
            'status-paper-past-papers.php', 'status-resource-free-learning.php', 'status-user.php',
            'sorting-item.php', 'sorting-section.php', 'title-item.php', 'title-section.php',
            'traffic-analytics.php', 'transactions-analytics.php', 'update-occurrence-live-session.php',
            'update-settings.php', 'upload-file.php', 'upload-image.php',
        ];
        $filename = $action . '-' . $page . '.php';
        return in_array($filename, $files, true) ? $filename : null;
    }
}
