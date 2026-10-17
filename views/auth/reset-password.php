<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/PasswordReset.php';
mmh_password_reset_no_store_headers();
$site_settings = getSiteSettings();
$site_name = (string) ($site_settings['website_name'] ?? 'Math Mastery Hub');
$authBaseUrl = rtrim(mmh_site_public_base_path(), '/') . '/auth';
$authBrandLogoUrl = mmh_site_public_url('resources/images/branding/mathhub-logo-light.png') . '?v=' . (@filemtime('resources/images/branding/mathhub-logo-light.png') ?: 1);
$authDarkLogoUrl = mmh_site_public_url('resources/images/branding/mathhub-logo-white.png');
$authCsrfToken = mmh_auth_csrf_token();
$resetToken = trim((string) ($_GET['token'] ?? ''));
$resetAvailable = mmh_password_reset_lookup(db(), $resetToken) !== null;
$resetError = mmh_auth_flash('password_reset_error');
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset your password · <?=htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8')?></title>
<?php include __DIR__ . '/../partials/favicon.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/fontawsome5.min.css')?>">
<script src="<?=mmh_site_public_url('resources/js/theme-preference.js')?>"></script>
<link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/design-system.css')?>">
<style>body{font-family:var(--font-sans)}.password-reset-shell{min-height:100vh;display:grid;place-items:center;padding:2rem 1rem}.password-reset-card{width:min(100%,30rem);padding:2rem;border:1px solid var(--border);border-radius:var(--radius-xl);background:var(--surface);box-shadow:var(--shadow-sm)}.password-reset-card label{display:block;font-weight:600;margin-bottom:.45rem}.password-reset-card input{width:100%;padding:.75rem;border:1px solid var(--border-strong);border-radius:var(--radius-sm);background:var(--surface-elevated);color:var(--text-primary)}.password-reset-card input:focus{outline:3px solid var(--primary-ring);outline-offset:2px}.password-reset-card h1{font-family:var(--font-heading);font-weight:400}.password-reset-logo{width:9rem;height:auto;margin:0 auto 1.5rem;display:block}.password-reset-logo--dark{display:none}.dark .password-reset-logo--light{display:none}.dark .password-reset-logo--dark{display:block}.password-reset-theme{position:fixed;top:1rem;right:1rem;z-index:5}</style>
</head>
<body class="mmh-auth-page ds-bg-primary ds-text-primary"><button type="button" class="mmh-theme-switch password-reset-theme" data-mmh-theme-toggle aria-label="Switch theme" aria-pressed="false"><span class="mmh-theme-switch__knob" aria-hidden="true"><i class="fas fa-sun mmh-theme-switch__icon"></i></span></button><main class="password-reset-shell"><section class="password-reset-card" aria-labelledby="reset-title"><img class="password-reset-logo password-reset-logo--light" src="<?=htmlspecialchars($authBrandLogoUrl, ENT_QUOTES, 'UTF-8')?>" alt="<?=htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8')?>"><img class="password-reset-logo password-reset-logo--dark" src="<?=htmlspecialchars($authDarkLogoUrl, ENT_QUOTES, 'UTF-8')?>" alt="<?=htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8')?>"><?php if (!$resetAvailable): ?><h1 id="reset-title" class="text-2xl font-bold text-center mb-3">This reset link is invalid or has expired.</h1><p class="ds-text-secondary text-center"><a class="text-primary hover:underline" href="<?=$authBaseUrl?>/forgot-password">Request a new reset link</a></p><?php else: ?><h1 id="reset-title" class="text-2xl font-bold text-center mb-2">Reset your password</h1><p class="ds-text-secondary text-center mb-6">Choose a new password with at least 8 characters.</p><?php if ($resetError): ?><div class="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm" role="alert"><?=htmlspecialchars($resetError, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?><form method="post" action="<?=$authBaseUrl?>/reset-password"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($authCsrfToken, ENT_QUOTES, 'UTF-8')?>"><input type="hidden" name="token" value="<?=htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8')?>"><label for="new-password">New password</label><input id="new-password" name="password" type="password" minlength="8" maxlength="190" autocomplete="new-password" required><label class="mt-4" for="confirm-password">Confirm new password</label><input id="confirm-password" name="password_confirmation" type="password" minlength="8" maxlength="190" autocomplete="new-password" required><button class="mt-5 w-full rounded-lg bg-primary px-4 py-3 font-semibold ds-text-inverse" type="submit">Reset password</button></form><?php endif; ?><p class="mt-5 text-center"><a class="text-primary hover:underline" href="<?=$authBaseUrl?>/login">Back to login</a></p></section></main></body></html>
