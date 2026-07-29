<?php
$site_settings = isset($site_settings) && is_array($site_settings) ? $site_settings : getSiteSettings();
$site_name = $site_name ?? ($site_settings['website_name'] ?? 'Math Mastery Hub');

// Unified Website Experience: private student pages now reuse the public website
// header. Authentication unlocks private destinations through the account menu;
// it does not switch the student into a separate website shell.
include 'views/public/layouts/aside.php';
