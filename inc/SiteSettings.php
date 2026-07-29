<?php
/**
 * Centralized, backward-compatible site settings access.
 * The legacy settings table remains the source of truth; defaults are never
 * written during a public request and only validated Admin saves add new keys.
 */

if (!function_exists('mmh_site_settings_defaults')) {
    function mmh_site_settings_defaults(): array
    {
        return [
            'website_name' => 'Math Mastery Hub',
            'website_bio' => '',
            'website_keywords' => '',
            'website_logo' => 'resources/images/default/wide-logo.png',
            'website_wide_logo' => '',
            'website_icon' => 'resources/images/default/favicon.png',
            'website_cover' => '',
            'contact_email' => '',
            'phone' => '',
            'phone2' => '',
            'whatsapp_phone' => '',
            'facebook_link' => '',
            'instagram_link' => '',
            'youtube_link' => '',
            'telegram_link' => '',
            'twitter_link' => '',
            'whatsapp_link' => '',
            'dashboard_dark_mode' => '1',
            'website_tagline' => 'Clear mathematics learning for IGCSE and A-Level students.',
            'footer_description' => '',
            'footer_show_social' => '1',
            'home_hero_enabled' => '1',
            'home_hero_title' => 'Welcome to {site_name}',
            'home_hero_description' => 'Discover structured mathematics courses and learning resources designed for confident progress.',
            'home_primary_label' => 'Browse Courses',
            'home_primary_url' => '/courses',
            'home_secondary_label' => 'Join the Community',
            'home_secondary_url' => '/register',
            'home_courses_enabled' => '1',
            'home_courses_heading' => 'Explore Our Courses',
            'home_courses_description' => 'Browse available courses and start learning today.',
            'nav_home_label' => 'Home',
            'nav_courses_label' => 'Courses',
            'nav_past_papers_label' => 'Past Papers',
            'nav_free_learning_label' => 'Free Learning',
            'nav_blog_label' => 'Blog',
            'nav_contact_label' => 'Contact',
            'nav_home_enabled' => '1',
            'nav_courses_enabled' => '1',
            'nav_past_papers_enabled' => '1',
            'nav_free_learning_enabled' => '1',
            'nav_blog_enabled' => '1',
            'nav_contact_enabled' => '1',
            'nav_home_order' => '10',
            'nav_courses_order' => '20',
            'nav_past_papers_order' => '30',
            'nav_free_learning_order' => '40',
            'nav_blog_order' => '50',
            'nav_contact_order' => '60',
            'seo_default_title' => '{site_name}',
            'seo_default_description' => '',
            'seo_canonical_base_url' => '',
            'seo_indexing' => '1',
            'announcement_enabled' => '0',
            'announcement_message' => '',
            'announcement_type' => 'info',
            'announcement_audience' => 'all',
            'announcement_action_label' => '',
            'announcement_action_url' => '',
            'announcement_starts_at' => '',
            'announcement_ends_at' => '',
            'maintenance_enabled' => '0',
            'maintenance_title' => 'We are improving Math Mastery Hub',
            'maintenance_message' => 'The site is briefly unavailable while scheduled maintenance is completed.',
            'maintenance_reopen_at' => '',
        ];
    }
}

if (!function_exists('mmh_site_settings')) {
    function mmh_site_settings(mysqli $conn): array
    {
        $settings = mmh_site_settings_defaults();
        $result = $conn->query('SELECT `key`, `value` FROM `settings`');
        if (!$result) {
            return $settings;
        }
        while ($row = $result->fetch_assoc()) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '') {
                $settings[$key] = (string) ($row['value'] ?? '');
            }
        }
        return $settings;
    }
}

if (!function_exists('mmh_site_public_base_path')) {
    /** Return a same-origin path prefix that remains valid on local ports and subdirectory installs. */
    function mmh_site_public_base_path(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $directory = str_replace('\\', '/', dirname($script));
        return ($directory === '/' || $directory === '.' || $directory === '\\') ? '' : rtrim($directory, '/');
    }
}

if (!function_exists('mmh_site_public_url')) {
    /** Build a root-relative, encoded local asset URL. Never emit a workstation filesystem path. */
    function mmh_site_public_url(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) return mmh_site_public_base_path() . '/';
        $segments = array_map(static fn(string $segment): string => rawurlencode($segment), explode('/', $path));
        return mmh_site_public_base_path() . '/' . implode('/', $segments);
    }
}

if (!function_exists('mmh_site_settings_valid_local_asset')) {
    /** Resolve a stored public asset only when it is an existing file below approved public directories. */
    function mmh_site_settings_valid_local_asset($asset): ?string
    {
        $asset = str_replace('\\', '/', trim((string) $asset));
        $asset = ltrim($asset, '/');
        if ($asset === '' || str_contains($asset, '..') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $asset)) return null;
        if (!str_starts_with($asset, 'uploads/') && !str_starts_with($asset, 'resources/images/')) return null;
        $root = realpath(dirname(__DIR__));
        $file = $root ? realpath($root . DIRECTORY_SEPARATOR . $asset) : false;
        if ($root === false || $file === false || !is_file($file) || !is_readable($file)) return null;
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($file, $rootPrefix)) return null;
        return str_replace('\\', '/', $asset);
    }
}

if (!function_exists('mmh_site_settings_asset')) {
    /** Return a valid configured asset or a built-in fallback; callers never receive an empty/broken src. */
    function mmh_site_settings_asset(array $settings, string $key, string $fallback): string
    {
        return mmh_site_settings_valid_local_asset($settings[$key] ?? '')
            ?? mmh_site_settings_valid_local_asset($fallback)
            ?? 'resources/images/default/logo.png';
    }
}

if (!function_exists('mmh_site_settings_asset_url')) {
    function mmh_site_settings_asset_url(array $settings, string $key, string $fallback): string
    {
        return mmh_site_public_url(mmh_site_settings_asset($settings, $key, $fallback));
    }
}

if (!function_exists('mmh_site_setting_truthy')) {
    function mmh_site_setting_truthy($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('mmh_site_settings_allowed_keys')) {
    function mmh_site_settings_allowed_keys(): array
    {
        return [
            'website_name' => ['type' => 'text', 'max' => 190, 'category' => 'General'],
            'website_tagline' => ['type' => 'text', 'max' => 240, 'category' => 'General'],
            'website_bio' => ['type' => 'textarea', 'max' => 3000, 'category' => 'General'],
            'contact_email' => ['type' => 'email', 'max' => 190, 'category' => 'Contact'],
            'phone' => ['type' => 'text', 'max' => 80, 'category' => 'Contact'],
            'phone2' => ['type' => 'text', 'max' => 80, 'category' => 'Contact'],
            'whatsapp_phone' => ['type' => 'text', 'max' => 80, 'category' => 'Contact'],
            'facebook_link' => ['type' => 'url', 'max' => 500, 'category' => 'Contact'],
            'instagram_link' => ['type' => 'url', 'max' => 500, 'category' => 'Contact'],
            'youtube_link' => ['type' => 'url', 'max' => 500, 'category' => 'Contact'],
            'telegram_link' => ['type' => 'url', 'max' => 500, 'category' => 'Contact'],
            'twitter_link' => ['type' => 'url', 'max' => 500, 'category' => 'Contact'],
            'whatsapp_link' => ['type' => 'url', 'max' => 500, 'category' => 'Contact'],
            'footer_description' => ['type' => 'textarea', 'max' => 1200, 'category' => 'Footer'],
            'footer_show_social' => ['type' => 'toggle', 'category' => 'Footer'],
            'home_hero_enabled' => ['type' => 'toggle', 'category' => 'Homepage'],
            'home_hero_title' => ['type' => 'text', 'max' => 240, 'category' => 'Homepage'],
            'home_hero_description' => ['type' => 'textarea', 'max' => 1000, 'category' => 'Homepage'],
            'home_primary_label' => ['type' => 'text', 'max' => 80, 'category' => 'Homepage'],
            'home_primary_url' => ['type' => 'internal_or_url', 'max' => 500, 'category' => 'Homepage'],
            'home_secondary_label' => ['type' => 'text', 'max' => 80, 'category' => 'Homepage'],
            'home_secondary_url' => ['type' => 'internal_or_url', 'max' => 500, 'category' => 'Homepage'],
            'home_courses_enabled' => ['type' => 'toggle', 'category' => 'Homepage'],
            'home_courses_heading' => ['type' => 'text', 'max' => 160, 'category' => 'Homepage'],
            'home_courses_description' => ['type' => 'textarea', 'max' => 600, 'category' => 'Homepage'],
            'website_keywords' => ['type' => 'textarea', 'max' => 1000, 'category' => 'SEO'],
            'seo_default_title' => ['type' => 'text', 'max' => 190, 'category' => 'SEO'],
            'seo_default_description' => ['type' => 'textarea', 'max' => 320, 'category' => 'SEO'],
            'seo_canonical_base_url' => ['type' => 'url', 'max' => 500, 'category' => 'SEO'],
            'seo_indexing' => ['type' => 'toggle', 'category' => 'SEO'],
            'announcement_enabled' => ['type' => 'toggle', 'category' => 'Announcements'],
            'announcement_message' => ['type' => 'textarea', 'max' => 600, 'category' => 'Announcements'],
            'announcement_type' => ['type' => 'choice', 'choices' => ['info', 'success', 'warning', 'urgent'], 'category' => 'Announcements'],
            'announcement_audience' => ['type' => 'choice', 'choices' => ['all', 'public', 'students'], 'category' => 'Announcements'],
            'announcement_action_label' => ['type' => 'text', 'max' => 80, 'category' => 'Announcements'],
            'announcement_action_url' => ['type' => 'internal_or_url', 'max' => 500, 'category' => 'Announcements'],
            'announcement_starts_at' => ['type' => 'datetime', 'category' => 'Announcements'],
            'announcement_ends_at' => ['type' => 'datetime', 'category' => 'Announcements'],
            'maintenance_enabled' => ['type' => 'toggle', 'category' => 'Maintenance'],
            'maintenance_title' => ['type' => 'text', 'max' => 190, 'category' => 'Maintenance'],
            'maintenance_message' => ['type' => 'textarea', 'max' => 1000, 'category' => 'Maintenance'],
            'maintenance_reopen_at' => ['type' => 'datetime', 'category' => 'Maintenance'],
        ];
    }
}

if (!function_exists('mmh_site_settings_navigation_keys')) {
    function mmh_site_settings_navigation_keys(): array
    {
        $keys = [];
        foreach (['home', 'courses', 'past_papers', 'free_learning', 'blog', 'contact'] as $item) {
            $keys['nav_' . $item . '_label'] = ['type' => 'text', 'max' => 60, 'category' => 'Navigation'];
            $keys['nav_' . $item . '_enabled'] = ['type' => 'toggle', 'category' => 'Navigation'];
            $keys['nav_' . $item . '_order'] = ['type' => 'order', 'category' => 'Navigation'];
        }
        return $keys;
    }
}

if (!function_exists('mmh_site_settings_definition')) {
    function mmh_site_settings_definition(): array
    {
        return array_merge(mmh_site_settings_allowed_keys(), mmh_site_settings_navigation_keys());
    }
}

if (!function_exists('mmh_site_settings_validate')) {
    function mmh_site_settings_validate(string $key, $value): array
    {
        $definition = mmh_site_settings_definition()[$key] ?? null;
        if (!$definition) {
            return [false, '', 'This setting is not available.'];
        }
        $value = is_scalar($value) ? trim((string) $value) : '';
        $type = $definition['type'];
        if ($type === 'toggle') {
            return [true, mmh_site_setting_truthy($value) ? '1' : '0', ''];
        }
        if ($type === 'order') {
            if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 999) {
                return [false, '', 'Enter an order between 0 and 999.'];
            }
            return [true, (string) (int) $value, ''];
        }
        if ($type === 'choice') {
            if (!in_array($value, $definition['choices'], true)) {
                return [false, '', 'Choose a valid option.'];
            }
            return [true, $value, ''];
        }
        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return [false, '', 'Enter a valid email address.'];
        }
        if ($type === 'url' && $value !== '' && !mmh_site_settings_safe_external_url($value)) {
            return [false, '', 'Use a valid HTTPS URL.'];
        }
        if ($type === 'internal_or_url' && $value !== '' && !mmh_site_settings_safe_internal_or_external_url($value)) {
            return [false, '', 'Use a site-relative path or a valid HTTPS URL.'];
        }
        if ($type === 'datetime' && $value !== '') {
            $date = DateTime::createFromFormat('Y-m-d\TH:i', $value) ?: DateTime::createFromFormat('Y-m-d H:i:s', $value);
            if (!$date) {
                return [false, '', 'Enter a valid date and time.'];
            }
            $value = $date->format('Y-m-d H:i:s');
        }
        $max = (int) ($definition['max'] ?? 0);
        if ($max > 0 && (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > $max) {
            return [false, '', 'This value is too long.'];
        }
        return [true, $value, ''];
    }
}

if (!function_exists('mmh_site_settings_safe_external_url')) {
    function mmh_site_settings_safe_external_url(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && !empty($parts['host']);
    }
}

if (!function_exists('mmh_site_settings_safe_internal_or_external_url')) {
    function mmh_site_settings_safe_internal_or_external_url(string $url): bool
    {
        return str_starts_with($url, '/') || mmh_site_settings_safe_external_url($url);
    }
}

if (!function_exists('mmh_site_settings_upsert')) {
    function mmh_site_settings_upsert(mysqli $conn, string $key, string $value, string $category): bool
    {
        $find = $conn->prepare('SELECT id FROM settings WHERE `key` = ? ORDER BY id ASC LIMIT 1');
        if (!$find) return false;
        $find->bind_param('s', $key);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        if ($row) {
            $id = (int) $row['id'];
            $update = $conn->prepare('UPDATE settings SET value = ?, category = ?, updated_at = NOW() WHERE id = ?');
            if (!$update) return false;
            $update->bind_param('ssi', $value, $category, $id);
            $ok = $update->execute();
            $update->close();
            return $ok;
        }
        $order = 0;
        $insert = $conn->prepare('INSERT INTO settings (`key`, value, `order`, category, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        if (!$insert) return false;
        $insert->bind_param('ssis', $key, $value, $order, $category);
        $ok = $insert->execute();
        $insert->close();
        return $ok;
    }
}

if (!function_exists('mmh_site_settings_active_announcement')) {
    function mmh_site_settings_active_announcement(array $settings, bool $studentSignedIn): ?array
    {
        if (!mmh_site_setting_truthy($settings['announcement_enabled'] ?? '0')) return null;
        $message = trim((string) ($settings['announcement_message'] ?? ''));
        if ($message === '') return null;
        $audience = (string) ($settings['announcement_audience'] ?? 'all');
        if ($audience === 'public' && $studentSignedIn) return null;
        if ($audience === 'students' && !$studentSignedIn) return null;
        $now = time();
        foreach (['announcement_starts_at' => 'start', 'announcement_ends_at' => 'end'] as $key => $kind) {
            $raw = trim((string) ($settings[$key] ?? ''));
            if ($raw === '') continue;
            $stamp = strtotime($raw);
            if ($stamp !== false && (($kind === 'start' && $now < $stamp) || ($kind === 'end' && $now > $stamp))) return null;
        }
        return [
            'message' => $message,
            'type' => in_array(($settings['announcement_type'] ?? ''), ['info', 'success', 'warning', 'urgent'], true) ? $settings['announcement_type'] : 'info',
            'action_label' => trim((string) ($settings['announcement_action_label'] ?? '')),
            'action_url' => trim((string) ($settings['announcement_action_url'] ?? '')),
        ];
    }
}

if (!function_exists('mmh_site_settings_maintenance_enabled')) {
    function mmh_site_settings_maintenance_enabled(array $settings): bool
    {
        return mmh_site_setting_truthy($settings['maintenance_enabled'] ?? '0');
    }
}
