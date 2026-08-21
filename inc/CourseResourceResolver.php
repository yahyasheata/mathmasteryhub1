<?php
/**
 * Resolves how an already-authorized course lesson should open.
 *
 * Authorization deliberately stays in StudentCourseAccess. This helper only
 * decides whether the lesson is a single external resource worth opening
 * immediately, or rich lesson content that should remain on the course page.
 */
require_once __DIR__ . '/LearningEvents.php';
require_once __DIR__ . '/AcademicMetadata.php';

if (!function_exists('mmh_course_resource_safe_url')) {
    function mmh_course_resource_safe_url($value)
    {
        $value = trim((string) $value);
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        return in_array($scheme, ['http', 'https'], true) && $host !== '' ? $value : null;
    }
}

if (!function_exists('mmh_course_resource_is_microsoft_stream_embed_url')) {
    /** Identify the legacy SharePoint iframe endpoint so Recording items can
     * be reported for manual replacement instead of being framed. */
    function mmh_course_resource_is_microsoft_stream_embed_url($value): bool
    {
        $url = mmh_course_resource_safe_url($value);
        if ($url === null || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $parts = parse_url($url) ?: [];
        if (($parts['user'] ?? '') !== '' || ($parts['pass'] ?? '') !== '') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (!($host === 'sharepoint.com' || str_ends_with($host, '.sharepoint.com'))
            || stripos($path, '/_layouts/15/embed.aspx') === false) {
            return false;
        }

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach ($query as $key => $value) {
            if (strtolower((string) $key) === 'uniqueid' && trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('mmh_course_resource_microsoft_recording_status')) {
    /**
     * Classify a Microsoft recording URL without attempting to manufacture a
     * sharing link. Only HTTPS SharePoint/Teams URLs that are not the legacy
     * iframe endpoint are usable as external recording targets.
     */
    function mmh_course_resource_microsoft_recording_status($value): array
    {
        $url = mmh_course_resource_safe_url($value);
        if ($url === null) {
            return ['state' => 'malformed', 'url' => null, 'is_microsoft' => false];
        }

        $parts = parse_url($url) ?: [];
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $isSharePoint = $host === 'sharepoint.com' || str_ends_with($host, '.sharepoint.com');
        $isTeams = $host === 'teams.microsoft.com' || str_ends_with($host, '.teams.microsoft.com');
        if (($parts['user'] ?? '') !== '' || ($parts['pass'] ?? '') !== '') {
            return ['state' => 'malformed', 'url' => null, 'is_microsoft' => $isSharePoint || $isTeams];
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return ['state' => 'insecure', 'url' => $url, 'is_microsoft' => $isSharePoint || $isTeams];
        }
        if (!$isSharePoint && !$isTeams) {
            return ['state' => 'unsupported', 'url' => $url, 'is_microsoft' => false];
        }
        if ($isSharePoint && stripos($path, '/_layouts/15/embed.aspx') !== false) {
            return ['state' => 'legacy_embed', 'url' => $url, 'is_microsoft' => true];
        }
        return ['state' => 'external', 'url' => $url, 'provider' => $isTeams ? 'teams' : 'sharepoint', 'is_microsoft' => true];
    }
}

if (!function_exists('mmh_course_resource_external_recording')) {
    /** Build the canonical external-recording resolution used by all viewers. */
    function mmh_course_resource_external_recording($url, $description = ''): ?array
    {
        $status = mmh_course_resource_microsoft_recording_status($url);
        if (($status['state'] ?? '') !== 'external') {
            return null;
        }
        return [
            'action' => 'recording_external',
            'url' => $status['url'],
            'open_url' => $status['url'],
            'description' => (string) $description,
            'label' => 'Recording',
            'icon' => 'fas fa-play-circle',
            'event_type' => 'recording_started',
            'provider' => $status['provider'] ?? 'sharepoint',
            'open_in_new_tab' => true,
        ];
    }
}

if (!function_exists('mmh_course_resource_extract_microsoft_stream_embed')) {
    /**
     * Accept either the official embed URL or one otherwise-empty iframe tag.
     * Only the decoded src value is returned; no pasted markup or attributes
     * are ever stored or rendered by the LMS.
     */
    function mmh_course_resource_extract_microsoft_stream_embed($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '<') || str_contains($value, '>')) {
            if (preg_match('/<\s*(?:script|style|object|embed)\b|\bon[a-z0-9_-]+\s*=|\bsrcdoc\s*=/i', $value)
                || !preg_match('/^\s*<iframe\b([^>]*)>\s*<\/iframe>\s*$/is', $value, $iframe)) {
                return null;
            }
            if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', (string) $iframe[1], $src)) {
                return null;
            }
            $value = html_entity_decode((string) $src[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $url = mmh_course_resource_safe_url($value);
        return $url !== null && mmh_course_resource_is_microsoft_stream_embed_url($url) ? $url : null;
    }
}

if (!function_exists('mmh_course_resource_template_data')) {
    function mmh_course_resource_template_data($value)
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('mmh_course_assignment_links')) {
    /**
     * Return every explicit, supported assignment relationship on a course
     * item. Arbitrary numbers in prose are deliberately ignored.
     */
    function mmh_course_assignment_links(array $item): array
    {
        $data = mmh_course_resource_template_data($item['template_data'] ?? '');
        $candidates = [
            'course_items.assignment_id' => $item['assignment_id'] ?? '',
            'template_data.assignment_id' => $data['assignment_id'] ?? '',
            'template_data.assignment.assignment_id' => $data['assignment']['assignment_id'] ?? '',
            'template_data.homework_resource.assignment_id' => $data['homework_resource']['assignment_id'] ?? '',
            'template_data.resource.assignment_id' => $data['resource']['assignment_id'] ?? '',
        ];
        $links = [];
        foreach ($candidates as $source => $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && strlen($candidate) <= 40 && preg_match('/\A[A-Za-z0-9_-]+\z/', $candidate)) {
                $links[$candidate][] = $source;
            }
        }
        if (preg_match_all('/\bdata-assignment-id\s*=\s*(["\'])\s*([A-Za-z0-9_-]{1,40})\s*\1/i', (string) ($item['item_description'] ?? ''), $matches)) {
            foreach ($matches[2] as $candidate) {
                $links[(string) $candidate][] = 'legacy_html.data-assignment-id';
            }
        }
        return $links;
    }
}

if (!function_exists('mmh_course_assignment_id')) {
    function mmh_course_assignment_id(array $item): string
    {
        $links = mmh_course_assignment_links($item);
        return $links ? (string) array_key_first($links) : '';
    }
}

if (!function_exists('mmh_course_item_is_notes')) {
    /**
     * Semantic Notes check shared by legacy and structured course-item views.
     * Legacy Notes remain template_type=notes; native Notes are structured
     * resources with resource_type=notes.
     */
    function mmh_course_item_is_notes(array $item): bool
    {
        if (strtolower(trim((string) ($item['template_type'] ?? ''))) === 'notes') {
            return true;
        }

        if (strtolower(trim((string) ($item['template_type'] ?? ''))) !== 'resource') {
            return false;
        }

        $data = mmh_course_resource_template_data($item['template_data'] ?? '');
        $resourceType = $data['resource_type'] ?? ($data['resource']['type'] ?? '');
        return strtolower(trim((string) $resourceType)) === 'notes';
    }
}

if (!function_exists('mmh_course_resource_legacy_text_is_generic')) {
    function mmh_course_resource_legacy_text_is_generic($value, $itemTitle = '')
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');
        $text = strtolower(trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $text))));
        // Legacy cards occasionally contain an encoding artefact before their
        // visual heading. It is decorative, not teacher guidance.
        $text = preg_replace('/^[\s\p{P}\p{S}]+/u', '', $text);
        $title = strtolower(trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', (string) $itemTitle))));
        // A generated bridge-card header repeats the lesson title once. Only
        // remove that leading label; the same words inside genuine guidance
        // must still keep the lesson rich.
        if ($title !== '') {
            // Whitespace and non-breaking-space variants in old card headers
            // must not stop an otherwise exact lesson-title match.
            $text = trim((string) preg_replace('/^' . preg_quote($title, '/') . '[\s\p{Z}\p{P}]*/u', '', $text, 1));
        }
        if ($text === '') {
            return true;
        }
        // These sentences are emitted by the original one-link Recording,
        // Notes, and Model Answer cards. They add no teacher guidance, so they
        // must not force a bridge page when there is exactly one safe target.
        $boilerplate = [
            'this recording has been prepared to help you review the lecture.',
            'click below to open it directly in a new tab.',
            'watch the lecture above or open it directly on youtube for the best experience.',
            'watch the lecture above or open it directly on youtube for the best experience',
            'this model answer have been prepared to help you check the homework efficiently.',
            'this model answer has been prepared to help you review the lecture efficiently.',
            'these notes have been prepared to help you review the lecture.',
            'you can view or download them directly from the link below.',
            'you can view or download them directly from the link below',
            'you can view or download it directly by clicking the button below.',
            'you can view or download it directly by clicking the button below',
            'this sheet was prepared to help you',
            'click the button below',
            'open / download',
            'open / download model answer',
            'open the recording',
            'open in google drive',
            'download pdf',
        ];
        foreach ($boilerplate as $phrase) {
            $text = trim(str_replace($phrase, '', $text));
        }
        // Known generated legacy-card variants. These patterns deliberately
        // match the original template wording only; open-ended teacher prose
        // remains meaningful content and therefore stays rendered.
        $generatedPatterns = [
            '/\bthis (?:homework|revision|sheet|recording) have been prepared to help you (?:review|practice|reivse) (?:the )?lecture(?: efficiently)?\.?/u',
            '/\bthese notes have been prepared to help you (?:review|reivse) (?:the )?lecture(?: efficiently)?\.?/u',
            '/\bthis model answer have been prepared to help you check the (?:homework|sheet) efficiently\.?/u',
            '/\bthis model answer has been prepared to help you review the lecture efficiently\.?/u',
            '/\byou can view(?: it| them)?(?: directly)? (?:from the link below|by clicking the button below)\.?/u',
            '/\byou can view or download (?:it|them) directly (?:from the link below|by clicking the button below)\.?/u',
            '/\byou can view it them directly from the link below\.?/u',
            '/\bopen\s*\/?\s*download(?:\s+model\s+answer)?\b/u',
        ];
        foreach ($generatedPatterns as $pattern) {
            $text = trim((string) preg_replace($pattern, '', $text));
        }
        $text = trim(preg_replace('/[\s\p{P}]+/u', ' ', $text));
        // Generated headers vary between courses. These labels are only
        // ignored when they are all that remains after known boilerplate is
        // removed; descriptive sentences still keep the lesson rich.
        $genericHeadings = [
            'lecture recording',
            'lecture notes',
            'notes',
            'recording',
            'homework model answer',
            'open download model answer',
            'model answer',
        ];
        return $text === '' || in_array($text, $genericHeadings, true);
    }
}

if (!function_exists('mmh_course_resource_simple_legacy_url')) {
    function mmh_course_resource_simple_legacy_url($html, $itemTitle = '')
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        $anchors = [];
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>/is', $html, $matches)) {
            foreach ($matches[2] as $value) {
                $url = mmh_course_resource_safe_url(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
                if ($url !== null) {
                    $anchors[$url] = $url;
                }
            }
        }
        $embedded = [];
        preg_match_all('/<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>/is', $html, $iframeMatches);

        $outsideLinks = preg_replace('/<a\b[^>]*>.*?<\/a>/is', '', $html);
        $outsideLinks = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $outsideLinks);
        $outsideLinks = preg_replace('/<(?:iframe|button)\b[^>]*\/?>/is', '', $outsideLinks);
        $generic = mmh_course_resource_legacy_text_is_generic($outsideLinks, $itemTitle);

        // A single safe iframe with no teacher-authored context is the legacy
        // equivalent of a single resource. Prefer it over its optional
        // fallback anchor so YouTube/Drive/SharePoint keep their viewer form.
        foreach ($iframeMatches[2] ?? [] as $value) {
            $url = mmh_course_resource_safe_url(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
            if ($url !== null) {
                $embedded[$url] = $url;
            }
        }
        if (count($embedded) === 1 && $generic) {
            return array_values($embedded)[0];
        }
        if (count($anchors) === 1 && $generic) {
            return array_values($anchors)[0];
        }

        if (preg_match_all('/<button\b[^>]*\bdata-src\s*=\s*(["\'])(.*?)\1[^>]*>/is', $html, $matches)) {
            foreach ($matches[2] as $value) {
                $url = mmh_course_resource_safe_url(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
                if ($url !== null) {
                    $embedded[$url] = $url;
                }
            }
        }
        if (count($embedded) !== 1 || !$generic) {
            return null;
        }
        $url = array_values($embedded)[0];
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        foreach (['drive.google.com', 'docs.google.com', 'youtube.com', 'youtu.be', 'sharepoint.com', 'teams.microsoft.com'] as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return $url;
            }
        }
        return null;
    }
}

if (!function_exists('mmh_course_resource_has_meaningful_description')) {
    function mmh_course_resource_has_meaningful_description($value, $itemTitle = '')
    {
        return !mmh_course_resource_legacy_text_is_generic((string) $value, $itemTitle);
    }
}

if (!function_exists('mmh_course_resource_meta')) {
    function mmh_course_resource_meta($type)
    {
        $type = strtolower(trim((string) $type));
        // Font Awesome 5 Free only: every class here is present in the bundled
        // solid, regular, or brands font files. Keep this as the one shared
        // resource icon source for course lists, the viewer, and admin tooling.
        $map = [
            'recording' => ['Recording', 'fas fa-play-circle'],
            'video' => ['Video', 'fas fa-play-circle'],
            'notes' => ['Notes', 'fas fa-file-alt'],
            'classified_assignment' => ['Assignment', 'fas fa-clipboard-list'],
            'assignment' => ['Assignment', 'fas fa-clipboard-list'],
            'quiz' => ['Assignment', 'fas fa-edit'],
            'exam' => ['Exam', 'fas fa-clipboard-list'],
            'timed_exam' => ['Timed Exam', 'fas fa-stopwatch'],
            'assignment_model_answer' => ['Model Answer', 'fas fa-graduation-cap'],
            'model_answer' => ['Model Answer', 'fas fa-graduation-cap'],
            'worksheet' => ['Worksheet', 'fas fa-file-alt'],
            'revision_sheet' => ['Revision Sheet', 'fas fa-sync-alt'],
            'booklet' => ['Booklet', 'fas fa-book'],
            'custom_lesson' => ['Custom Lesson', 'fas fa-file-alt'],
            'custom_html' => ['Learning Material', 'fas fa-file-alt'],
            'embed' => ['Embedded Lesson', 'fas fa-play-circle'],
            'google_drive_folder' => ['Google Drive Folder', 'fas fa-folder-open'],
            'pdf' => ['PDF', 'fas fa-file-pdf'],
            'download' => ['Download', 'fas fa-download'],
            'google_drive' => ['Google Drive', 'fab fa-google-drive'],
            'onedrive' => ['OneDrive', 'fas fa-cloud'],
            'teams' => ['Teams Session', 'fas fa-video'],
            'live_session' => ['Teams Session', 'fas fa-video'],
            'youtube' => ['YouTube Video', 'fab fa-youtube'],
            'youtube_video' => ['YouTube Video', 'fab fa-youtube'],
            'external_link' => ['External Resource', 'fas fa-external-link-alt'],
            'file' => ['Learning Material', 'fas fa-file-alt'],
        ];
        return $map[$type] ?? ['Learning Material', 'fas fa-file-alt'];
    }
}

if (!function_exists('mmh_course_resource_display_meta')) {
    /** Keep provider terminology consistent without changing resource types. */
    function mmh_course_resource_display_meta($type, $provider = '', $url = '')
    {
        $provider = strtolower(trim((string) $provider));
        if ($provider === 'microsoft_stream' || mmh_course_resource_is_microsoft_stream_embed_url($url)) {
            return ['Microsoft Stream', 'fas fa-video'];
        }
        if ($provider === 'teams') {
            return ['Teams Recording', 'fas fa-video'];
        }
        if ($provider === 'sharepoint' && in_array(strtolower(trim((string) $type)), ['video', 'recording'], true)) {
            return ['SharePoint Video', 'fas fa-video'];
        }
        return mmh_course_resource_meta($type);
    }
}

if (!function_exists('mmh_course_resource_pdf_initial_view')) {
    /**
     * Native browser and PDF.js viewers honour this standard PDF open hint when
     * available. It is deliberately added only to direct PDFs; provider-owned
     * viewers such as Drive keep their own supported preview URL.
     */
    function mmh_course_resource_pdf_initial_view($url)
    {
        $url = (string) $url;
        if ($url === '') {
            return $url;
        }
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        if (is_string($fragment) && $fragment !== '') {
            return $url;
        }
        return $url . '#zoom=page-width';
    }
}

if (!function_exists('mmh_course_resource_embed_details')) {
    /**
     * Returns an allowlisted preview configuration for a single already-safe
     * external target. Unknown providers deliberately return null so the
     * protected endpoint falls back to a normal redirect.
     */
    function mmh_course_resource_embed_details($url, $type = '')
    {
        $url = mmh_course_resource_safe_url($url);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $type = strtolower(trim((string) $type));
        $isHost = static function ($domain) use ($host) {
            return $host === $domain || str_ends_with($host, '.' . $domain);
        };

        // Teams is intentionally never iframe-embedded.
        if ($isHost('teams.microsoft.com') || in_array($type, ['teams', 'live_session'], true)) {
            return null;
        }

        if ($isHost('drive.google.com') || $isHost('docs.google.com') || $isHost('drive.usercontent.google.com')) {
            // Drive folders have their own permission and navigation model.
            if (preg_match('~/(?:drive/)?folders/[^/?]+~i', $path)) {
                return null;
            }

            $preview = null;
            $download = null;
            if (preg_match('~/file/d/([^/?]+)~i', $path, $match)) {
                $fileId = rawurlencode($match[1]);
                $preview = 'https://drive.google.com/file/d/' . $fileId . '/preview';
                $download = 'https://drive.google.com/uc?export=download&id=' . $fileId;
            } elseif (!empty($query['id']) && preg_match('/^[A-Za-z0-9_-]+$/', (string) $query['id'])) {
                $fileId = rawurlencode((string) $query['id']);
                $preview = 'https://drive.google.com/file/d/' . $fileId . '/preview';
                $download = 'https://drive.google.com/uc?export=download&id=' . $fileId;
            } elseif (preg_match('~/(document|presentation|spreadsheets)/d/([^/?]+)~i', $path, $match)) {
                $kind = strtolower($match[1]);
                $fileId = rawurlencode($match[2]);
                $preview = 'https://docs.google.com/' . $kind . '/d/' . $fileId . '/preview';
            }
            if ($preview !== null) {
                return ['embed_url' => $preview, 'open_url' => $url, 'download_url' => $download, 'kind' => 'google'];
            }
            return null;
        }

        if ($isHost('youtube.com') || $isHost('youtu.be') || $isHost('youtube-nocookie.com')) {
            $videoId = '';
            if ($isHost('youtu.be')) {
                $videoId = trim(explode('/', trim($path, '/'))[0] ?? '');
            } elseif (preg_match('~/embed/([^/?]+)~i', $path, $match)) {
                $videoId = $match[1];
            } else {
                $videoId = (string) ($query['v'] ?? '');
            }
            if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
                $embedQuery = [];
                if (isset($query['start']) && ctype_digit((string) $query['start'])) {
                    $embedQuery['start'] = (string) $query['start'];
                }
                $embedUrl = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId);
                if ($embedQuery) {
                    $embedUrl .= '?' . http_build_query($embedQuery, '', '&', PHP_QUERY_RFC3986);
                }
                return ['embed_url' => $embedUrl, 'open_url' => $url, 'download_url' => null, 'kind' => 'youtube'];
            }
            return null;
        }

        // A PDF can be rendered by the browser in an iframe. This remains a
        // protected viewer because the original URL is only emitted afterward.
        if ($type === 'pdf' || preg_match('/\.pdf(?:$|[?#])/i', $url)) {
            return ['embed_url' => mmh_course_resource_pdf_initial_view($url), 'open_url' => $url, 'download_url' => $url, 'kind' => 'pdf'];
        }

        // Only SharePoint's official Stream endpoint with a UniqueId is
        // embeddable. Other SharePoint pages safely use the protected fallback.
        if (mmh_course_resource_is_microsoft_stream_embed_url($url)) {
            return ['embed_url' => $url, 'open_url' => $url, 'download_url' => null, 'kind' => 'microsoft_stream'];
        }

        return null;
    }
}

if (!function_exists('mmh_course_resource_type_for_url')) {
    /** Conservative provider inference for structured Homework slots. */
    function mmh_course_resource_type_for_url($url, $fallback = 'external_link')
    {
        $url = mmh_course_resource_safe_url($url);
        if ($url === null) {
            return $fallback;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if ($host === 'drive.google.com' || str_ends_with($host, '.drive.google.com') || $host === 'docs.google.com' || str_ends_with($host, '.docs.google.com')) {
            return preg_match('~/(?:drive/)?folders/~', $path) ? 'google_drive_folder' : 'google_drive';
        }
        if ($host === 'youtube.com' || str_ends_with($host, '.youtube.com') || $host === 'youtu.be') {
            return 'youtube';
        }
        if ($host === 'teams.microsoft.com' || str_ends_with($host, '.teams.microsoft.com')) {
            return 'teams';
        }
        if (str_ends_with($path, '.pdf')) {
            return 'pdf';
        }
        if ($host === 'sharepoint.com' || str_ends_with($host, '.sharepoint.com')) {
            return 'recording';
        }
        return $fallback;
    }
}

if (!function_exists('mmh_course_resource_resolve_core')) {
    function mmh_course_resource_resolve_core(array $item)
    {
        $type = strtolower(trim((string) ($item['template_type'] ?? '')));
        $legacyType = strtolower(trim((string) ($item['item_type'] ?? '')));
        $effectiveType = $type !== '' ? $type : $legacyType;
        $data = mmh_course_resource_template_data($item['template_data'] ?? '');
        $title = trim((string) ($item['item_title'] ?? ''));
        // Native resource records are populated by the one-time legacy
        // migration. Once present, their structured target is authoritative:
        // do not return to item_description parsing for a migrated item.
        $nativeResourceUrl = mmh_course_resource_safe_url($data['resource_url'] ?? $data['resource']['url'] ?? '');
        $nativeResourceType = strtolower(trim((string) ($data['resource_type'] ?? $data['resource']['type'] ?? '')));
        $nativeResourceProvider = strtolower(trim((string) ($data['resource_provider'] ?? $data['resource']['provider'] ?? '')));
        if ($type === 'resource' && $nativeResourceUrl !== null) {
            $nativeResourceType = $nativeResourceType !== '' ? $nativeResourceType : 'external_link';
            [$label, $icon] = mmh_course_resource_display_meta($nativeResourceType, $nativeResourceProvider, $nativeResourceUrl);
            if (in_array($nativeResourceType, ['recording', 'video'], true)
                || in_array($nativeResourceProvider, ['sharepoint', 'microsoft_stream', 'teams'], true)) {
                $recordingStatus = mmh_course_resource_microsoft_recording_status($nativeResourceUrl);
                if (($recordingStatus['state'] ?? '') === 'external') {
                    return mmh_course_resource_external_recording($nativeResourceUrl, $data['description'] ?? '');
                }
                if (!empty($recordingStatus['is_microsoft']) || in_array($nativeResourceProvider, ['sharepoint', 'microsoft_stream', 'teams'], true)) {
                    return [
                        'action' => 'recording_unavailable',
                        'label' => 'Recording',
                        'icon' => 'fas fa-play-circle',
                        'reason' => 'Recording link needs to be updated.',
                        'recording_link_state' => $recordingStatus['state'],
                    ];
                }
            }
            $embed = !empty($data['embed_enabled']) ? mmh_course_resource_embed_details($nativeResourceUrl, $nativeResourceType) : null;
            $eventType = mmh_lesson_open_event($nativeResourceType);
            if ($embed !== null) {
                return [
                    'action' => 'embed',
                    'url' => $nativeResourceUrl,
                    'embed_url' => $embed['embed_url'],
                    'open_url' => $embed['open_url'],
                    'download_url' => $embed['download_url'],
                    'embed_kind' => $embed['kind'],
                    'description' => (string) ($data['description'] ?? ''),
                    'label' => $label,
                    'icon' => $icon,
                    'event_type' => $eventType,
                ];
            }
            return [
                'action' => 'redirect',
                'url' => $nativeResourceUrl,
                'label' => $label,
                'icon' => $icon,
                'event_type' => $eventType,
                'open_in_new_tab' => true,
            ];
        }
        if ($type === 'resource') {
            [$label, $icon] = mmh_course_resource_meta($nativeResourceType ?: 'external_link');
            return ['action' => 'unavailable', 'label' => $label, 'icon' => $icon, 'reason' => 'This structured resource is not available.'];
        }
        [$label, $icon] = mmh_course_resource_meta($effectiveType);

        // Homework is a first-class learning surface. Modern Lesson Manager
        // records already carry this relationship in structured fields; older
        // assignment cards are adapted conservatively without modifying their
        // original HTML or treating arbitrary quiz content as homework.
        $assignmentId = mmh_course_assignment_id($item);
        $homeworkData = is_array($data['homework_resource'] ?? null) ? $data['homework_resource'] : [];
        $homeworkUrl = mmh_course_resource_safe_url($homeworkData['url'] ?? $data['url'] ?? $data['assignment_drive_url'] ?? '');
        $homeworkData2 = is_array($data['homework_resource_2'] ?? null) ? $data['homework_resource_2'] : [];
        $homeworkUrl2 = mmh_course_resource_safe_url($homeworkData2['url'] ?? $data['assignment_drive_url_2'] ?? '');
        if ($homeworkUrl === null && preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>/is', (string) ($item['item_description'] ?? ''), $homeworkLinks)) {
            foreach ($homeworkLinks[2] as $candidate) {
                $candidate = mmh_course_resource_safe_url(html_entity_decode((string) $candidate, ENT_QUOTES, 'UTF-8'));
                if ($candidate !== null) {
                    $homeworkUrl = $candidate;
                    break;
                }
            }
        }
        $isHomework = in_array($effectiveType, ['classified_assignment', 'assignment', 'homework'], true)
            || ($type === '' && $legacyType === 'quiz' && $assignmentId !== '');
        if ($isHomework && $assignmentId !== '') {
            $homeworkType = strtolower(trim((string) ($homeworkData['resource_type'] ?? $homeworkData['type'] ?? '')));
            $homeworkType = $homeworkType !== '' ? $homeworkType : mmh_course_resource_type_for_url($homeworkUrl, 'google_drive');
            $modelAnswerData = is_array($data['model_answer_resource'] ?? null) ? $data['model_answer_resource'] : [];
            $modelAnswerUrl = mmh_course_resource_safe_url($modelAnswerData['url'] ?? '');
            $modelAnswerType = strtolower(trim((string) ($modelAnswerData['resource_type'] ?? $modelAnswerData['type'] ?? '')));
            $modelAnswerType = $modelAnswerType !== '' ? $modelAnswerType : mmh_course_resource_type_for_url($modelAnswerUrl, 'google_drive');
            $modelAnswerRelease = strtolower(trim((string) ($modelAnswerData['release'] ?? $data['model_answer_release'] ?? 'hidden')));
            if (!in_array($modelAnswerRelease, ['hidden', 'immediate', 'after_due', 'after_submission'], true)) {
                $modelAnswerRelease = 'hidden';
            }
            return [
                'action' => 'homework',
                'assignment_id' => $assignmentId,
                'homework_url' => $homeworkUrl,
                'homework_resource' => [
                    'url' => $homeworkUrl,
                    'provider' => (string) ($homeworkData['provider'] ?? $homeworkType),
                    'resource_type' => $homeworkType,
                    'embed' => !array_key_exists('embed', $homeworkData) || !empty($homeworkData['embed']),
                ],
                'homework_resource_2' => $homeworkUrl2 === null ? null : [
                    'url' => $homeworkUrl2,
                    'provider' => (string) ($homeworkData2['provider'] ?? mmh_course_resource_type_for_url($homeworkUrl2, 'external_link')),
                    'resource_type' => (string) ($homeworkData2['resource_type'] ?? $homeworkData2['type'] ?? mmh_course_resource_type_for_url($homeworkUrl2, 'external_link')),
                    'embed' => !array_key_exists('embed', $homeworkData2) || !empty($homeworkData2['embed']),
                ],
                'model_answer_resource' => $modelAnswerUrl === null ? null : [
                    'url' => $modelAnswerUrl,
                    'provider' => (string) ($modelAnswerData['provider'] ?? $modelAnswerType),
                    'resource_type' => $modelAnswerType,
                    'embed' => !array_key_exists('embed', $modelAnswerData) || !empty($modelAnswerData['embed']),
                    'release' => $modelAnswerRelease,
                ],
                'description' => (string) ($data['instructions'] ?? $data['description'] ?? ''),
                'label' => 'Homework',
                'icon' => 'fas fa-clipboard-list',
                'event_type' => mmh_lesson_open_event('classified_assignment'),
            ];
        }

        if ($effectiveType === 'timed_exam') {
            return ['action' => 'timed_exam', 'label' => 'Timed Exam', 'icon' => 'fas fa-stopwatch', 'reason' => 'This lesson opens in the protected Timed Exam workspace.'];
        }
        $richTypes = ['classified_assignment', 'assignment', 'exam', 'custom_lesson', 'custom_html', 'embed'];
        if (in_array($effectiveType, $richTypes, true)) {
            return ['action' => 'render', 'label' => $label, 'icon' => $icon, 'reason' => 'This lesson contains interactive or rich content.'];
        }

        $directTypes = ['recording', 'video', 'pdf', 'download', 'google_drive', 'onedrive', 'teams', 'live_session', 'youtube', 'youtube_video', 'external_link', 'file'];
        $url = null;
        $structuredDescription = $data['description'] ?? '';
        $hasStructuredContent = mmh_course_resource_has_meaningful_description($structuredDescription, $title);
        if (in_array($effectiveType, $directTypes, true)) {
            $url = mmh_course_resource_safe_url($data['url'] ?? $data['external_url'] ?? '');
            // Structured teacher description is meaningful LMS content. Keep
            // the existing lesson page instead of bypassing it.
            if ($url !== null && $hasStructuredContent) {
                return ['action' => 'render', 'label' => $label, 'icon' => $icon, 'reason' => 'This lesson includes teacher guidance.'];
            }
            if ($url === null) {
                $url = mmh_course_resource_simple_legacy_url($item['item_description'] ?? '', $title);
            }
            if ($url === null && $effectiveType === 'recording') {
                return ['action' => 'recording_unavailable', 'label' => 'Recording', 'icon' => 'fas fa-play-circle', 'reason' => 'Recording link needs to be updated.'];
            }
            if ($url !== null && in_array($effectiveType, ['recording', 'video'], true)) {
                $recordingStatus = mmh_course_resource_microsoft_recording_status($url);
                if (($recordingStatus['state'] ?? '') === 'external') {
                    return mmh_course_resource_external_recording($url, $structuredDescription);
                }
                if (!empty($recordingStatus['is_microsoft'])) {
                    return [
                        'action' => 'recording_unavailable',
                        'label' => 'Recording',
                        'icon' => 'fas fa-play-circle',
                        'reason' => 'Recording link needs to be updated.',
                        'recording_link_state' => $recordingStatus['state'],
                    ];
                }
            }
        }

        if ($effectiveType === 'assignment_model_answer') {
            $modelAnswerUrl = mmh_course_resource_safe_url($data['url'] ?? $data['external_url'] ?? '');
            $hasAdditionalContent = mmh_course_resource_has_meaningful_description($data['description'] ?? '', $title);
            if ($modelAnswerUrl !== null && !$hasAdditionalContent) {
                $url = $modelAnswerUrl;
            } elseif ($modelAnswerUrl === null) {
                $legacyUrl = mmh_course_resource_simple_legacy_url($item['item_description'] ?? '', $title);
                if ($legacyUrl !== null) {
                    $url = $legacyUrl;
                } elseif (trim(strip_tags((string) ($item['item_description'] ?? ''))) !== '') {
                    return ['action' => 'render', 'label' => $label, 'icon' => $icon, 'reason' => 'This model answer includes additional content.'];
                } else {
                    return ['action' => 'unavailable', 'label' => $label, 'icon' => $icon, 'reason' => 'This model answer is not available yet.'];
                }
            }
        }

        if ($effectiveType === 'notes') {
            $notesBody = $data['content'] ?? ($item['item_description'] ?? '');
            $notesDescription = $data['description'] ?? '';
            if ($url === null && !mmh_course_resource_has_meaningful_description($notesDescription, $title)) {
                $url = mmh_course_resource_safe_url($data['url'] ?? $data['external_url'] ?? '')
                    ?: mmh_course_resource_simple_legacy_url($notesBody, $title);
            }
        }
        if ($url === null && $type === '') {
            $url = mmh_course_resource_simple_legacy_url($item['item_description'] ?? '', $title);
        }
        if ($url !== null) {
            $eventType = mmh_lesson_open_event($effectiveType);
            $embed = mmh_course_resource_embed_details($url, $effectiveType);
            if ($embed !== null) {
                return [
                    'action' => 'embed',
                    'url' => $url,
                    'embed_url' => $embed['embed_url'],
                    'open_url' => $embed['open_url'],
                    'download_url' => $embed['download_url'],
                    'embed_kind' => $embed['kind'],
                    'description' => $structuredDescription,
                    'label' => $label,
                    'icon' => $icon,
                    'event_type' => $eventType,
                ];
            }
            return [
                'action' => 'redirect', 'url' => $url, 'label' => $label, 'icon' => $icon,
                'event_type' => $eventType,
                'open_in_new_tab' => true,
            ];
        }
        return ['action' => 'render', 'label' => $label, 'icon' => $icon, 'reason' => 'This lesson includes additional content.'];
    }
}

if (!function_exists('mmh_course_resource_resolve')) {
    /**
     * Public resolver wrapper. Resource behavior remains unchanged while the
     * resolved metadata is added from the section plus lesson-only overrides.
     */
    function mmh_course_resource_resolve(array $item)
    {
        $resolution = mmh_course_resource_resolve_core($item);
        $hierarchy = mmh_hierarchical_metadata_resolve(
            $item['section_metadata'] ?? '',
            $item['metadata'] ?? ''
        );
        $resolution['resolved_metadata'] = $hierarchy['metadata'];
        $resolution['metadata_sources'] = $hierarchy['sources'];
        return $resolution;
    }
}
