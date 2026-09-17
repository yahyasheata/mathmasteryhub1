<?php
declare(strict_types=1);

/**
 * Read-only Google Classroom scanner for the Past Papers migration preview.
 *
 * This module intentionally never writes Past Paper or Past Paper Resource
 * rows. OAuth credentials and the current preview remain in the administrator
 * session only; no migration-state tables are introduced.
 */

require_once __DIR__ . '/PastPapers.php';

if (!function_exists('mmh_classroom_oauth_config')) {
    function mmh_classroom_oauth_config(string $baseUrl = ''): array
    {
        $env = static function (string $key): string {
            $value = getenv($key);
            if (!is_string($value) || trim($value) === '') $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
            return is_string($value) ? trim($value) : '';
        };
        $clientId = $env('MMH_GOOGLE_CLASSROOM_CLIENT_ID') ?: $env('MMH_GOOGLE_CLIENT_ID');
        $clientSecret = $env('MMH_GOOGLE_CLASSROOM_CLIENT_SECRET') ?: $env('MMH_GOOGLE_CLIENT_SECRET');
        $redirectUri = $env('MMH_GOOGLE_CLASSROOM_REDIRECT_URI');
        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/classroom.courses.readonly',
                'https://www.googleapis.com/auth/classroom.topics.readonly',
                'https://www.googleapis.com/auth/classroom.courseworkmaterials.readonly',
                'https://www.googleapis.com/auth/classroom.coursework.students.readonly',
            ]),
            'base_url' => rtrim($baseUrl, '/'),
        ];
    }
}

if (!function_exists('mmh_classroom_oauth_status')) {
    function mmh_classroom_oauth_status(string $baseUrl = ''): array
    {
        $config = mmh_classroom_oauth_config($baseUrl);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $missing = [];
        if ($config['client_id'] === '') $missing[] = 'MMH_GOOGLE_CLASSROOM_CLIENT_ID';
        if ($config['client_secret'] === '') $missing[] = 'MMH_GOOGLE_CLASSROOM_CLIENT_SECRET';
        if ($config['redirect_uri'] === '') $missing[] = 'MMH_GOOGLE_CLASSROOM_REDIRECT_URI';
        if (!is_file($autoload)) $missing[] = 'vendor/autoload.php';
        if ($missing) {
            return ['available' => false, 'label' => 'Google Classroom is not configured', 'message' => 'Provide: ' . implode(', ', $missing) . '.', 'config' => $config];
        }
        require_once $autoload;
        if (!class_exists('Google\\Client') || !class_exists('Google\\Service\\Classroom')) {
            return ['available' => false, 'label' => 'Google Classroom client unavailable', 'message' => 'Install the Google PHP API client with Classroom services enabled.', 'config' => $config];
        }
        return ['available' => true, 'label' => 'Google Classroom OAuth ready', 'message' => 'Read-only Classroom scopes are configured.', 'config' => $config];
    }
}

if (!function_exists('mmh_classroom_oauth_start_url')) {
    function mmh_classroom_oauth_start_url(string $baseUrl = ''): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $status = mmh_classroom_oauth_status($baseUrl);
        if (!$status['available']) return null;
        $config = $status['config'];
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $_SESSION['mmh_classroom_oauth_state'] = ['hash' => hash('sha256', $state), 'expires_at' => time() + 600];
        $params = [
            'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code', 'scope' => $config['scope'], 'state' => $state,
            'access_type' => 'offline', 'prompt' => 'consent',
        ];
        return $config['authorization_endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('mmh_classroom_oauth_callback')) {
    function mmh_classroom_oauth_callback(string $baseUrl = ''): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $pending = $_SESSION['mmh_classroom_oauth_state'] ?? null;
        unset($_SESSION['mmh_classroom_oauth_state']);
        $state = trim((string) ($_GET['state'] ?? ''));
        if (!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < time() || $state === '' || !hash_equals((string) ($pending['hash'] ?? ''), hash('sha256', $state))) {
            return [false, 'This Google authorization request expired. Please connect again.'];
        }
        if (!empty($_GET['error'])) return [false, 'Google Classroom access was cancelled or denied.'];
        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '' || strlen($code) > 4096) return [false, 'Google did not return a valid authorization code.'];
        $config = mmh_classroom_oauth_config($baseUrl);
        $curl = curl_init($config['token_endpoint']);
        if ($curl === false) return [false, 'Unable to initialize Google authorization.'];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code', 'code' => $code,
                'redirect_uri' => $config['redirect_uri'], 'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ], '', '&', PHP_QUERY_RFC3986),
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $token = json_decode(is_string($body) ? $body : '', true);
        if ($status < 200 || $status >= 300 || !is_array($token) || empty($token['access_token'])) return [false, 'Google could not grant Classroom read access. Check the OAuth configuration and consent screen.'];
        $_SESSION['mmh_classroom_token'] = [
            'access_token' => (string) $token['access_token'],
            'refresh_token' => (string) ($token['refresh_token'] ?? ($_SESSION['mmh_classroom_token']['refresh_token'] ?? '')),
            'expires_at' => time() + max(60, (int) ($token['expires_in'] ?? 3600)),
        ];
        return [true, 'Google Classroom connected with read-only access.'];
    }
}

if (!function_exists('mmh_classroom_access_token')) {
    function mmh_classroom_access_token(string $baseUrl = ''): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $token = is_array($_SESSION['mmh_classroom_token'] ?? null) ? $_SESSION['mmh_classroom_token'] : [];
        if (!empty($token['access_token']) && (int) ($token['expires_at'] ?? 0) > time() + 60) return (string) $token['access_token'];
        if (empty($token['refresh_token'])) return null;
        $config = mmh_classroom_oauth_config($baseUrl);
        $curl = curl_init($config['token_endpoint']);
        if ($curl === false) return null;
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token'], 'client_id' => $config['client_id'], 'client_secret' => $config['client_secret']], '', '&', PHP_QUERY_RFC3986)]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $fresh = json_decode(is_string($body) ? $body : '', true);
        if ($status < 200 || $status >= 300 || !is_array($fresh) || empty($fresh['access_token'])) return null;
        $_SESSION['mmh_classroom_token']['access_token'] = (string) $fresh['access_token'];
        $_SESSION['mmh_classroom_token']['expires_at'] = time() + max(60, (int) ($fresh['expires_in'] ?? 3600));
        return (string) $fresh['access_token'];
    }
}

if (!function_exists('mmh_classroom_service')) {
    function mmh_classroom_service(string $baseUrl = ''): ?object
    {
        $accessToken = mmh_classroom_access_token($baseUrl);
        if ($accessToken === null) return null;
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) return null;
        require_once $autoload;
        if (!class_exists('Google\\Client') || !class_exists('Google\\Service\\Classroom')) return null;
        $client = new Google\Client();
        $client->setAccessToken(['access_token' => $accessToken]);
        return new Google\Service\Classroom($client);
    }
}

if (!function_exists('mmh_classroom_approved_topic_keys')) {
    function mmh_classroom_approved_topic_keys(): array
    {
        return [
            'may june|2022|1', 'may june|2022|2', 'may june|2022|3',
            'oct nov|2022|1', 'oct nov|2022|2', 'oct nov|2022|3',
            'may june|2023|1', 'may june|2023|2',
        ];
    }
}

if (!function_exists('mmh_classroom_is_target_syllabus')) {
    /** The Phase 2 mapping is intentionally limited to Cambridge Mathematics 0580. */
    function mmh_classroom_is_target_syllabus(array $syllabus): bool
    {
        return preg_match('/\A0*580\z/', trim((string) ($syllabus['syllabus_code'] ?? ''))) === 1
            && stripos((string) ($syllabus['board_name'] ?? ''), 'cambridge') !== false;
    }
}

if (!function_exists('mmh_classroom_normalize_topic_name')) {
    function mmh_classroom_normalize_topic_name(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\([^)]*\)/u', ' ', $name) ?? $name;
        $name = str_replace(['/', '-', '_'], ' ', $name);
        $name = preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name;
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }
}

if (!function_exists('mmh_classroom_parse_topic')) {
    function mmh_classroom_parse_topic(string $name): ?array
    {
        $normalized = mmh_classroom_normalize_topic_name($name);
        $session = null;
        foreach ([['may june', 'May/June'], ['m j', 'May/June'], ['oct nov', 'October/November'], ['october november', 'October/November'], ['o n', 'October/November']] as [$needle, $label]) {
            if (str_contains($normalized, $needle)) { $session = [$needle, $label]; break; }
        }
        if ($session === null) return null;
        $rest = trim(str_replace($session[0], '', $normalized));
        if (!preg_match('/\A(20\d{2})\s+v?([1-3])\z/', $rest, $match)) return null;
        $key = ($session[0] === 'october november' || $session[0] === 'oct nov' || $session[0] === 'o n' ? 'oct nov' : 'may june') . '|' . $match[1] . '|' . $match[2];
        if (!in_array($key, mmh_classroom_approved_topic_keys(), true)) return null;
        return ['key' => $key, 'session' => $session[1], 'year' => (int) $match[1], 'variant_number' => (int) $match[2], 'variant' => 'V' . $match[2], 'original_title' => $name, 'normalized_title' => $normalized];
    }
}

if (!function_exists('mmh_classroom_classify_title')) {
    function mmh_classroom_classify_title(string $title): ?array
    {
        $normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title));
        $type = null;
        if (preg_match('/\b(solution\s+video|video\s+solution)\b/i', $normalized)) $type = 'video_solution';
        elseif (preg_match('/\bma\b|model\s+answer/i', $normalized)) $type = 'model_answer';
        elseif (preg_match('/\bqp\b|question\s+paper/i', $normalized)) $type = 'question_paper';
        if ($type === null) return null;
        $papers = [];
        if (preg_match('/(?:paper\s*2|\bp2\b)/i', $normalized)) $papers[] = 2;
        if (preg_match('/(?:paper\s*4|\bp4\b)/i', $normalized)) $papers[] = 4;
        if (!$papers) return ['resource_type' => $type, 'papers' => [], 'normalized_title' => $normalized, 'status' => 'NEEDS REVIEW', 'warning' => 'The resource type is known, but the paper number is missing.'];
        return ['resource_type' => $type, 'papers' => array_values(array_unique($papers)), 'normalized_title' => $normalized, 'status' => 'READY', 'warning' => ''];
    }
}

if (!function_exists('mmh_classroom_component')) {
    function mmh_classroom_component(int $paperNumber, int $variant): string
    {
        return (string) ($paperNumber * 10 + $variant);
    }
}

if (!function_exists('mmh_classroom_attachment_papers')) {
    function mmh_classroom_attachment_papers(string $title, array $classification, int $topicVariant): array
    {
        $normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title));
        $found = [];
        $variant = max(1, min(3, $topicVariant));
        if (preg_match('/(?:0580\s*\/\s*)?' . (20 + $variant) . '\\b|\\bp' . (20 + $variant) . '\\b|paper\\s*2\\b/i', $normalized)) $found[] = 2;
        if (preg_match('/(?:0580\s*\/\s*)?' . (40 + $variant) . '\\b|\\bp' . (40 + $variant) . '\\b|paper\\s*4\\b/i', $normalized)) $found[] = 4;
        $found = array_values(array_unique(array_intersect($found, $classification['papers'] ?? [])));
        return $found ?: ($classification['papers'] ?? []);
    }
}

if (!function_exists('mmh_classroom_normalize_attachment')) {
    function mmh_classroom_normalize_attachment(object $material): array
    {
        $result = ['attachment_type' => 'unknown', 'title' => '', 'url' => '', 'drive_file_id' => '', 'youtube_id' => ''];
        if (method_exists($material, 'getDriveFile') && $material->getDriveFile()) {
            $shared = $material->getDriveFile(); $drive = method_exists($shared, 'getDriveFile') ? $shared->getDriveFile() : null;
            if ($drive) {
                $result['attachment_type'] = 'google_drive';
                $result['title'] = (string) ($drive->getTitle() ?? '');
                $result['drive_file_id'] = (string) ($drive->getId() ?? '');
                $result['url'] = (string) ($drive->getAlternateLink() ?? '');
                if ($result['url'] === '' && $result['drive_file_id'] !== '') $result['url'] = 'https://drive.google.com/file/d/' . rawurlencode($result['drive_file_id']) . '/view';
                return $result;
            }
        }
        if (method_exists($material, 'getYoutubeVideo') && $material->getYoutubeVideo()) {
            $video = $material->getYoutubeVideo(); $result['attachment_type'] = 'youtube';
            $result['title'] = (string) ($video->getTitle() ?? ''); $result['youtube_id'] = (string) ($video->getId() ?? ''); $result['url'] = (string) ($video->getAlternateLink() ?? '');
            return $result;
        }
        if (method_exists($material, 'getLink') && $material->getLink()) {
            $link = $material->getLink(); $result['attachment_type'] = 'external_link'; $result['title'] = (string) ($link->getTitle() ?? ''); $result['url'] = (string) ($link->getUrl() ?? '');
            return $result;
        }
        return $result;
    }
}

if (!function_exists('mmh_classroom_topic_item_preview')) {
    function mmh_classroom_topic_item_preview(array $item, array $topic, array $syllabus, array &$warnings): array
    {
        $classification = mmh_classroom_classify_title((string) ($item['title'] ?? ''));
        if ($classification === null) return [];
        $attachments = is_array($item['attachments'] ?? null) ? $item['attachments'] : [];
        if (count($classification['papers'] ?? []) > 1 && count($attachments) > 1) {
            $variant = max(1, min(3, (int) ($topic['variant_number'] ?? 1)));
            $allIdentified = true;
            foreach ($attachments as $candidateAttachment) {
                $candidateTitle = strtolower((string) ($candidateAttachment['title'] ?? ''));
                if (!preg_match('/(?:paper\s*[24]|\bp(?:' . (20 + $variant) . '|' . (40 + $variant) . ')\b|(?:0580\s*\/\s*)?(?:' . (20 + $variant) . '|' . (40 + $variant) . ')\b)/i', $candidateTitle)) { $allIdentified = false; break; }
            }
            if (!$allIdentified) {
                $warnings[] = ['status' => 'NEEDS REVIEW', 'message' => 'Cannot determine which attachment belongs to Paper 2 vs Paper 4.', 'item_title' => $item['title'] ?? ''];
                return [];
            }
        }
        $rows = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $attachmentTitle = trim((string) ($attachment['title'] ?? ''));
            $paperNumbers = mmh_classroom_attachment_papers($attachmentTitle, $classification, (int) $topic['variant_number']);
            foreach ($paperNumbers as $paperNumber) {
                $rows[] = [
                    'resource_type' => $classification['resource_type'], 'paper_number' => $paperNumber,
                    'source' => ['coursework_type' => $item['item_type'] ?? '', 'coursework_id' => $item['id'] ?? '', 'item_title' => $item['title'] ?? '', 'topic_id' => $topic['id'] ?? '', 'topic_title' => $topic['original_title'] ?? '', 'attachment_type' => $attachment['attachment_type'] ?? 'unknown', 'attachment_title' => $attachmentTitle, 'source_url' => $attachment['url'] ?? '', 'drive_file_id' => $attachment['drive_file_id'] ?? '', 'youtube_id' => $attachment['youtube_id'] ?? ''],
                ];
            }
        }
        if (!$attachments) $warnings[] = ['status' => 'NEEDS REVIEW', 'message' => 'The Classroom item has no supported attachment.', 'item_title' => $item['title'] ?? ''];
        return $rows;
    }
}

if (!function_exists('mmh_classroom_lookup_existing_paper')) {
    function mmh_classroom_lookup_existing_paper(mysqli $conn, string $syllabusId, int $year, string $session, string $paperNumber, string $variant): ?array
    {
        $stmt = $conn->prepare('SELECT paper_id, status, short_title FROM past_papers WHERE syllabus_id = ? AND year = ? AND exam_session = ? AND paper_number = ? AND variant = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('sisss', $syllabusId, $year, $session, $paperNumber, $variant); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close(); return $row;
    }
}

if (!function_exists('mmh_classroom_build_preview')) {
    /** Build candidates from already-fetched metadata. This function is pure apart from the optional duplicate lookup callback. */
    function mmh_classroom_build_preview(array $topics, array $items, array $syllabus, ?callable $duplicateLookup = null): array
    {
        $approved = []; $ignored = [];
        foreach ($topics as $topic) {
            $parsed = mmh_classroom_parse_topic((string) ($topic['title'] ?? ''));
            if ($parsed === null) { $ignored[] = ['id' => (string) ($topic['id'] ?? ''), 'title' => (string) ($topic['title'] ?? ''), 'reason' => 'Ignored — outside approved migration scope']; continue; }
            $approved[$parsed['key']] = array_merge($topic, $parsed);
        }
        $warnings = []; $candidates = [];
        foreach ($approved as $topic) {
            $byPaper = [2 => [], 4 => []];
            foreach ($items as $item) {
                if ((string) ($item['topic_id'] ?? '') !== (string) ($topic['id'] ?? '')) continue;
                foreach (mmh_classroom_topic_item_preview($item, $topic, $syllabus, $warnings) as $row) $byPaper[(int) $row['paper_number']][] = $row;
            }
            foreach ([2, 4] as $paperNumber) {
                $resources = []; $seen = [];
                foreach ($byPaper[$paperNumber] as $row) {
                    $key = ($row['resource_type'] ?? '') . '|' . (($row['source']['source_url'] ?? '') ?: ($row['source']['drive_file_id'] ?? '') ?: ($row['source']['coursework_id'] ?? ''));
                    if ($key === '|' || isset($seen[$key])) continue; $seen[$key] = true; $resources[] = $row;
                }
                $types = array_values(array_unique(array_map(static fn($row) => (string) $row['resource_type'], $resources)));
                $missing = array_values(array_diff(['question_paper', 'model_answer', 'video_solution'], $types));
                $status = $missing ? 'NEEDS REVIEW' : 'READY';
                $candidate = ['status' => $status, 'topic' => ['id' => (string) ($topic['id'] ?? ''), 'title' => (string) ($topic['original_title'] ?? ''), 'session' => $topic['session'], 'year' => $topic['year'], 'variant' => $topic['variant']], 'syllabus' => ['id' => (string) ($syllabus['syllabus_id'] ?? ''), 'title' => (string) ($syllabus['public_title'] ?? ''), 'code' => (string) ($syllabus['syllabus_code'] ?? ''), 'board' => (string) ($syllabus['board_name'] ?? '')], 'paper_number' => 'Paper ' . $paperNumber, 'paper' => $paperNumber, 'variant' => $topic['variant'], 'component' => mmh_classroom_component($paperNumber, (int) $topic['variant_number']), 'resources' => $resources, 'missing_resources' => $missing, 'existing_paper' => null];
                if ($duplicateLookup) $candidate['existing_paper'] = $duplicateLookup($candidate);
                $candidates[] = $candidate;
            }
        }
        $counts = ['question_paper' => 0, 'model_answer' => 0, 'video_solution' => 0]; $statusCounts = ['READY' => 0, 'WARNING' => 0, 'NEEDS REVIEW' => 0, 'ERROR' => 0];
        foreach ($candidates as $candidate) { $statusCounts[$candidate['status']] = ($statusCounts[$candidate['status']] ?? 0) + 1; foreach ($candidate['resources'] as $resource) if (isset($counts[$resource['resource_type']])) $counts[$resource['resource_type']]++; }
        foreach ($warnings as $warning) $statusCounts[$warning['status']] = ($statusCounts[$warning['status']] ?? 0) + 1;
        return ['approved_topics' => array_values($approved), 'ignored_topics' => $ignored, 'candidates' => $candidates, 'warnings' => $warnings, 'summary' => ['approved_topics' => count($approved), 'approved_expected' => 8, 'candidates' => count($candidates), 'candidates_expected' => 16, 'resources' => $counts, 'statuses' => $statusCounts, 'ignored_topics' => count($ignored)]];
    }
}

if (!function_exists('mmh_classroom_api_list_all')) {
    function mmh_classroom_api_list_all(callable $request, string $collectionKey): array
    {
        $rows = []; $pageToken = '';
        do { $response = $request($pageToken); if (!is_object($response)) break; $values = method_exists($response, 'get' . ucfirst($collectionKey)) ? $response->{'get' . ucfirst($collectionKey)}() : []; if (is_array($values)) $rows = array_merge($rows, $values); $pageToken = method_exists($response, 'getNextPageToken') ? (string) ($response->getNextPageToken() ?? '') : ''; } while ($pageToken !== '' && count($rows) < 2000);
        return $rows;
    }
}

if (!function_exists('mmh_classroom_scan')) {
    function mmh_classroom_scan(mysqli $conn, string $courseId, string $syllabusId, string $baseUrl = ''): array
    {
        $courseId = trim($courseId); $syllabusId = trim($syllabusId);
        if ($courseId === '' || strlen($courseId) > 200 || !preg_match('/\A[A-Za-z0-9_-]+\z/', $courseId)) return [false, 'Choose a valid Classroom course.', null];
        $syllabus = mmh_past_syllabus($conn, $syllabusId);
        if (!$syllabus || !mmh_classroom_is_target_syllabus($syllabus)) return [false, 'Select the configured Cambridge Mathematics 0580 syllabus.', null];
        $service = mmh_classroom_service($baseUrl); if (!$service) return [false, 'Connect a Google account with Classroom read-only access first.', null];
        $stage = 'scan.start';
        try {
            $stage = 'topics.list';
            $topics = mmh_classroom_api_list_all(static fn($page) => $service->courses_topics->listCoursesTopics($courseId, array_filter(['pageSize' => 100, 'pageToken' => $page, 'fields' => 'topic(id,name),nextPageToken'], static fn($v) => $v !== '')), 'topic');
            $topicRows = array_map(static fn($topic) => ['id' => (string) ($topic->getId() ?? ''), 'title' => (string) ($topic->getName() ?? '')], $topics);
            $metadata = [];
            $stage = 'courseWorkMaterials.list';
            $listedMaterials = mmh_classroom_api_list_all(static fn($page) => $service->courses_courseWorkMaterials->listCoursesCourseWorkMaterials($courseId, array_filter(['courseWorkMaterialStates' => 'PUBLISHED', 'pageSize' => 100, 'pageToken' => $page, 'fields' => 'courseWorkMaterial(id,title,topicId),nextPageToken'], static fn($v) => $v !== '')), 'courseWorkMaterial');
            foreach ($listedMaterials as $item) $metadata[] = ['item_type' => 'coursework_material', 'id' => (string) ($item->getId() ?? ''), 'title' => (string) ($item->getTitle() ?? ''), 'topic_id' => (string) ($item->getTopicId() ?? '')];
            $stage = 'courseWork.list';
            $listedWork = mmh_classroom_api_list_all(static fn($page) => $service->courses_courseWork->listCoursesCourseWork($courseId, array_filter(['courseWorkStates' => 'PUBLISHED', 'pageSize' => 100, 'pageToken' => $page, 'fields' => 'courseWork(id,title,topicId),nextPageToken'], static fn($v) => $v !== '')), 'courseWork');
            foreach ($listedWork as $item) $metadata[] = ['item_type' => 'coursework', 'id' => (string) ($item->getId() ?? ''), 'title' => (string) ($item->getTitle() ?? ''), 'topic_id' => (string) ($item->getTopicId() ?? '')];
            $approvedIds = []; foreach ($topicRows as $topic) if (mmh_classroom_parse_topic($topic['title'])) $approvedIds[(string) $topic['id']] = true;
            $items = [];
            foreach ($metadata as $item) {
                if ($item['topic_id'] === '' || !isset($approvedIds[$item['topic_id']])) continue;
                $stage = $item['item_type'] === 'coursework_material' ? 'courseWorkMaterials.get' : 'courseWork.get';
                $full = $item['item_type'] === 'coursework_material' ? $service->courses_courseWorkMaterials->get($courseId, $item['id'], ['fields' => 'id,title,topicId,materials']) : $service->courses_courseWork->get($courseId, $item['id'], ['fields' => 'id,title,topicId,materials']);
                $materials = method_exists($full, 'getMaterials') ? (array) ($full->getMaterials() ?? []) : [];
                $items[] = array_merge($item, ['attachments' => array_map('mmh_classroom_normalize_attachment', $materials)]);
            }
            $stage = 'preview.build';
            $preview = mmh_classroom_build_preview($topicRows, $items, $syllabus, static fn(array $candidate) => mmh_classroom_lookup_existing_paper($conn, (string) $syllabus['syllabus_id'], (int) $candidate['topic']['year'], (string) $candidate['topic']['session'], (string) $candidate['paper_number'], (string) $candidate['variant']));
            $preview['course'] = ['id' => $courseId]; $preview['scanned_at'] = date('c');
            return [true, 'Read-only Classroom scan complete. No Past Paper records were changed.', $preview];
        } catch (Throwable $exception) {
            $detail = preg_replace('/https?:\/\/\S+/i', '[url]', trim($exception->getMessage())) ?: 'unknown error';
            error_log('[PastPaperClassroom] scan failed stage=' . $stage . ' course=' . $courseId . ' class=' . get_class($exception) . ' code=' . (int) $exception->getCode() . ' message=' . substr($detail, 0, 300));
            return [false, 'Google Classroom could not be scanned. Check the connected account and API configuration.', null];
        }
    }
}

if (!function_exists('mmh_classroom_list_courses')) {
    function mmh_classroom_list_courses(string $baseUrl = ''): array
    {
        $service = mmh_classroom_service($baseUrl);
        if (!$service) return [[], 'Connect a Google account with Classroom read-only access first.'];
        try {
            $courses = mmh_classroom_api_list_all(static fn($page) => $service->courses->listCourses(array_filter(['pageSize' => 100, 'pageToken' => $page, 'fields' => 'courses(id,name,section,courseState),nextPageToken'], static fn($v) => $v !== '')), 'courses');
            $rows = [];
            foreach ($courses as $course) {
                $id = trim((string) ($course->getId() ?? '')); $name = trim((string) ($course->getName() ?? ''));
                if ($id === '' || $name === '') continue;
                $rows[] = ['id' => $id, 'name' => $name, 'section' => trim((string) ($course->getSection() ?? '')), 'state' => (string) ($course->getCourseState() ?? '')];
            }
            usort($rows, static fn(array $a, array $b): int => strcasecmp($a['name'] . ' ' . $a['section'], $b['name'] . ' ' . $b['section']));
            return [$rows, ''];
        } catch (Throwable $exception) {
            error_log('[PastPaperClassroom] course list failed: ' . $exception->getMessage());
            return [[], 'Google Classroom courses could not be loaded. Check the connected account and Classroom API access.'];
        }
    }
}
