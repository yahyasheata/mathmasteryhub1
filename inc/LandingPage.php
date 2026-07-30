<?php
/**
 * Landing page content helpers.
 *
 * Repeatable landing content lives in one small table and the existing
 * settings table remains responsible for section toggles/headings.
 */

if (!function_exists('mmh_landing_ensure_schema')) {
    function mmh_landing_ensure_schema(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS `landing_page_items` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `section_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
            `item_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
            `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
            `icon` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `title` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `description` text COLLATE utf8mb4_unicode_ci,
            `value` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `label` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `question` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `answer` text COLLATE utf8mb4_unicode_ci,
            `student_name` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `grade` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `exam_board` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `quote` text COLLATE utf8mb4_unicode_ci,
            `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `sort_order` int unsigned NOT NULL DEFAULT '0',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_landing_section` (`section_key`, `status`, `sort_order`, `id`),
            KEY `idx_landing_type` (`item_type`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('mmh_landing_item_types')) {
    function mmh_landing_item_types(): array
    {
        return [
            'trust_stats' => 'stat',
            'why' => 'feature',
            'features' => 'feature',
            'testimonials' => 'testimonial',
            'faq' => 'faq',
        ];
    }
}

if (!function_exists('mmh_landing_items')) {
    function mmh_landing_items(mysqli $conn, string $sectionKey, bool $publishedOnly = true): array
    {
        mmh_landing_ensure_schema($conn);
        if (!isset(mmh_landing_item_types()[$sectionKey])) return [];

        $sql = 'SELECT * FROM landing_page_items WHERE section_key = ?';
        if ($publishedOnly) $sql .= " AND status = 'published'";
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('s', $sectionKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            if (mmh_landing_item_has_content($row)) $items[] = $row;
        }
        $stmt->close();
        return $items;
    }
}

if (!function_exists('mmh_landing_grouped_items')) {
    function mmh_landing_grouped_items(mysqli $conn, bool $publishedOnly = false): array
    {
        $grouped = [];
        foreach (array_keys(mmh_landing_item_types()) as $sectionKey) {
            $grouped[$sectionKey] = mmh_landing_items($conn, $sectionKey, $publishedOnly);
        }
        return $grouped;
    }
}

if (!function_exists('mmh_landing_item_has_content')) {
    function mmh_landing_item_has_content(array $item): bool
    {
        $section = (string) ($item['section_key'] ?? '');
        if ($section === 'trust_stats') {
            return trim((string) ($item['value'] ?? '')) !== '' && trim((string) ($item['label'] ?? '')) !== '';
        }
        if ($section === 'faq') {
            return trim((string) ($item['question'] ?? '')) !== '' && trim((string) ($item['answer'] ?? '')) !== '';
        }
        if ($section === 'testimonials') {
            return trim((string) ($item['student_name'] ?? '')) !== '' && trim((string) ($item['quote'] ?? '')) !== '';
        }
        return trim((string) ($item['title'] ?? '')) !== '' && trim((string) ($item['description'] ?? '')) !== '';
    }
}

if (!function_exists('mmh_landing_section_enabled')) {
    function mmh_landing_section_enabled(array $settings, string $sectionKey, string $default = '1'): bool
    {
        return mmh_site_setting_truthy($settings['landing_' . $sectionKey . '_enabled'] ?? $default);
    }
}

if (!function_exists('mmh_landing_setting')) {
    function mmh_landing_setting(array $settings, string $key, string $fallback = ''): string
    {
        $value = trim((string) ($settings[$key] ?? ''));
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('mmh_landing_icon_class')) {
    function mmh_landing_icon_class(?string $icon, string $fallback = 'fas fa-circle'): string
    {
        $icon = trim((string) $icon);
        if ($icon === '') return $fallback;
        if (preg_match('/^(fa[srb]|fab) fa-[a-z0-9-]+$/i', $icon)) return $icon;
        if (preg_match('/^fa-[a-z0-9-]+$/i', $icon)) return 'fas ' . $icon;
        return $fallback;
    }
}

if (!function_exists('mmh_landing_photo_url')) {
    function mmh_landing_photo_url(?string $path): string
    {
        $asset = mmh_site_settings_valid_local_asset((string) $path);
        return $asset ? mmh_site_public_url($asset) : '';
    }
}
