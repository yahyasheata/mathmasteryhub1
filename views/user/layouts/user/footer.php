<?php
$site_settings = isset($site_settings) && is_array($site_settings) ? $site_settings : getSiteSettings();
$site_name = $site_name ?? ($site_settings['website_name'] ?? 'Math Mastery Hub');
include 'views/public/layouts/footer.php';
