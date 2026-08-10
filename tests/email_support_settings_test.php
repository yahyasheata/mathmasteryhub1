<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test can only run from the command line.\n");
}

require_once dirname(__DIR__) . '/inc/SiteSettings.php';
require_once dirname(__DIR__) . '/inc/PasswordReset.php';

if (mmh_site_settings_normalize_whatsapp_phone('+20 101 234 5678') !== '201012345678') {
    throw new RuntimeException('International WhatsApp number normalization failed.');
}
if (mmh_site_settings_normalize_whatsapp_phone('javascript:alert(1)') !== null
    || mmh_site_settings_normalize_whatsapp_phone('01012345678') !== null) {
    throw new RuntimeException('Unsafe or non-international WhatsApp input was accepted.');
}
$whatsappUrl = mmh_site_settings_whatsapp_url(['support_whatsapp' => '+201012345678']);
if (!str_starts_with($whatsappUrl, 'https://wa.me/201012345678?text=') || str_contains($whatsappUrl, '+201012345678')) {
    throw new RuntimeException('WhatsApp URL generation is not canonical.');
}
if (!str_contains($whatsappUrl, rawurlencode('Hello Math Mastery Hub Support, I need help recovering access to my account.'))) {
    throw new RuntimeException('WhatsApp support message is not URL encoded.');
}

$previous = [];
foreach (['RESEND_API_KEY', 'MAIL_FROM_EMAIL', 'MAIL_FROM_NAME', 'APP_PUBLIC_URL'] as $key) {
    $previous[$key] = getenv($key);
}
putenv('RESEND_API_KEY=test-only-key');
putenv('MAIL_FROM_EMAIL=no-reply@example.com');
putenv('MAIL_FROM_NAME=Math Mastery Hub');
putenv('APP_PUBLIC_URL=https://mathmasteryhub.com');
$configured = mmh_password_reset_resend_config();
if (empty($configured['configured']) || ($configured['provider'] ?? '') !== 'Resend') {
    throw new RuntimeException('Resend configuration was not detected from the canonical environment rules.');
}
putenv('APP_PUBLIC_URL=http://mathmasteryhub.com');
if (!empty(mmh_password_reset_resend_config()['configured'])) {
    throw new RuntimeException('Non-HTTPS APP_PUBLIC_URL was accepted.');
}
foreach ($previous as $key => $value) {
    if ($value === false) putenv($key);
    else putenv($key . '=' . $value);
}

$settingsSource = file_get_contents(dirname(__DIR__) . '/views/admin/settings.php');
$forgotSource = file_get_contents(dirname(__DIR__) . '/views/auth/forgot-password.php');
if (!is_string($settingsSource) || !is_string($forgotSource)) throw new RuntimeException('Unable to inspect settings/auth views.');
if (!str_contains($settingsSource, 'mmh_password_reset_resend_config') || !str_contains($settingsSource, 'Resend · Email delivery is configured.')) {
    throw new RuntimeException('Admin Integrations is not using the canonical Resend status.');
}
if (str_contains($settingsSource, 'RESEND_API_KEY') || str_contains($settingsSource, 'getenv(\'MAIL_HOST\')')) {
    throw new RuntimeException('Sensitive or legacy mail configuration leaked into the Admin UI.');
}
if (!str_contains($forgotSource, 'mmh_site_settings_whatsapp_url') || !str_contains($forgotSource, 'noopener noreferrer')) {
    throw new RuntimeException('Forgot Password support CTA is not connected safely.');
}
if (preg_match('/wa\.me\/[+0-9]/i', $forgotSource)) {
    throw new RuntimeException('Forgot Password contains a hard-coded WhatsApp destination.');
}

echo "Resend status, safe WhatsApp normalization, settings persistence contract, and Forgot Password integration checks passed.\n";
