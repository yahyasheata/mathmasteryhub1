<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/PasswordReset.php';
mmh_password_reset_no_store_headers();
$site_settings = getSiteSettings();
$site_name = (string) ($site_settings['website_name'] ?? 'Math Mastery Hub');
$authRootUrl = mmh_site_public_base_path() ?: '/';
$authBaseUrl = rtrim(mmh_site_public_base_path(), '/') . '/auth';
$authBrandLogoUrl = mmh_site_public_url('resources/images/branding/mathhub-logo-light.png') . '?v=' . (@filemtime('resources/images/branding/mathhub-logo-light.png') ?: 1);
$authDarkLogoUrl = mmh_site_public_url('resources/images/branding/mathhub-logo-white.png');
$authMessage = mmh_auth_flash('password_reset');
$authError = mmh_auth_flash('password_reset_error');
$authCsrfToken = mmh_auth_csrf_token();
$authSupportWhatsAppUrl = mmh_site_settings_whatsapp_url($site_settings);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot your password? · <?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?></title>
  <?php include __DIR__ . '/../partials/favicon.php'; ?>
  <script>
    (function () {
      try {
        var savedTheme = window.localStorage.getItem('theme') || window.localStorage.getItem('math-mastery-student-theme');
        var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        var dark = savedTheme === 'dark' || (!savedTheme && prefersDark);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
      } catch (error) {}
    }());
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <script src="<?= mmh_site_public_url('resources/js/theme-preference.js') ?>"></script>
  <link rel="stylesheet" href="<?= mmh_site_public_url('resources/css/fontawsome5.min.css') ?>">
  <link rel="stylesheet" href="<?= mmh_site_public_url('resources/css/design-system.css') ?>" data-design-system="mathhub">
  <style>
    html, body { margin: 0; }
    .password-reset-page, .password-reset-card, .password-reset-input, .password-reset-submit { box-sizing: border-box; }
    .password-reset-page {
      position: relative;
      display: grid;
      place-items: center;
      min-height: 100svh;
      padding: clamp(1.25rem, 4vw, 3rem) 1rem;
      overflow: hidden;
      isolation: isolate;
    }
    .password-reset-page::before {
      position: absolute;
      z-index: -1;
      top: -12rem;
      left: 50%;
      width: min(42rem, 100vw);
      height: 28rem;
      border-radius: 50%;
      background: var(--primary-soft);
      content: "";
      filter: blur(24px);
      opacity: .6;
      transform: translateX(-50%);
    }
    .password-reset-card {
      width: min(100%, 33rem);
      padding: clamp(1.4rem, 4vw, 2.5rem);
      border: 1px solid var(--border);
      border-radius: var(--radius-xl);
      background: var(--surface-elevated);
      box-shadow: var(--shadow-lg);
    }
    .password-reset-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: clamp(1.6rem, 4vw, 2.2rem);
    }
    .password-reset-brand {
      display: inline-flex;
      align-items: center;
      min-width: 0;
      border-radius: var(--radius-sm);
    }
    .password-reset-brand:focus-visible,
    .password-reset-theme:focus-visible,
    .password-reset-back:focus-visible {
      outline: 3px solid var(--primary-ring);
      outline-offset: 3px;
    }
    .password-reset-logo {
      display: block;
      width: clamp(7.2rem, 30vw, 9.25rem);
      height: auto;
      max-width: 100%;
    }
    .password-reset-logo--dark { display: none; }
    html.dark .password-reset-logo--light,
    html[data-theme="dark"] .password-reset-logo--light { display: none; }
    html.dark .password-reset-logo--dark,
    html[data-theme="dark"] .password-reset-logo--dark { display: block; }
    .password-reset-theme.mmh-theme-switch { flex: 0 0 84px; }
    .password-reset-heading { margin: 0; color: var(--text-primary); font-family: var(--font-heading); font-size: clamp(1.65rem, 5vw, 2.15rem); font-weight: 400; letter-spacing: -.025em; line-height: var(--line-tight); }
    .password-reset-intro { max-width: 31rem; margin: .7rem 0 1.65rem; color: var(--text-secondary); font-size: .96rem; line-height: 1.55; }
    .password-reset-feedback {
      display: grid;
      grid-template-columns: 2rem minmax(0, 1fr);
      gap: .75rem;
      align-items: start;
      margin: 0 0 1.25rem;
      padding: .9rem 1rem;
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      background: var(--surface-muted);
      color: var(--text-secondary);
      font-size: .87rem;
      line-height: 1.45;
    }
    .password-reset-feedback-icon { display: grid; place-items: center; width: 2rem; height: 2rem; border-radius: 50%; background: var(--success-soft); color: var(--success); }
    .password-reset-feedback--error .password-reset-feedback-icon { background: var(--danger-soft); color: var(--danger); }
    .password-reset-feedback strong { display: block; margin-bottom: .15rem; color: var(--text-primary); font-size: .9rem; }
    .password-reset-form { display: grid; gap: 1.1rem; }
    .password-reset-field { display: grid; gap: .45rem; }
    .password-reset-field label { color: var(--text-primary); font-size: .84rem; font-weight: 750; }
    .password-reset-input-wrap { position: relative; }
    .password-reset-input-icon { position: absolute; top: 50%; left: 1rem; color: var(--text-muted); pointer-events: none; transform: translateY(-50%); }
    .password-reset-input {
      display: block;
      width: 100%;
      min-height: 3.1rem;
      padding: .75rem 1rem .75rem 2.85rem;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      background: var(--surface-inset);
      color: var(--text-primary);
      font: inherit;
      line-height: 1.35;
      transition: border-color var(--transition), box-shadow var(--transition), background var(--transition);
    }
    .password-reset-input::placeholder { color: var(--text-muted); opacity: .8; }
    .password-reset-input:hover { border-color: var(--primary); }
    .password-reset-input:focus { border-color: var(--primary); outline: 0; box-shadow: var(--shadow-focus); background: var(--surface); }
    .password-reset-submit {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: .55rem;
      width: 100%;
      min-height: 3.15rem;
      padding: .8rem 1.1rem;
      border: 1px solid var(--primary-action);
      border-radius: var(--radius-sm);
      background: var(--primary-action);
      color: var(--primary-foreground);
      cursor: pointer;
      font: inherit;
      font-weight: 800;
      transition: background var(--transition), border-color var(--transition), box-shadow var(--transition), transform var(--transition-fast);
    }
    .password-reset-submit:hover { border-color: var(--primary-action-hover); background: var(--primary-action-hover); color: var(--primary-foreground); box-shadow: var(--shadow-xs); }
    .password-reset-submit:focus-visible { outline: 3px solid var(--primary-ring); outline-offset: 3px; }
    .password-reset-submit:active { transform: translateY(1px); }
    .password-reset-support { margin: 1.35rem 0 0; color: var(--text-muted); font-size: .78rem; line-height: 1.5; text-align: center; }
    .password-reset-support strong { color: var(--text-secondary); font-weight: 700; }
    .password-reset-support-actions { display: grid; gap: .65rem; margin-top: 1.35rem; text-align: center; }
    .password-reset-support-actions p { margin: 0; color: var(--text-muted); font-size: .82rem; line-height: 1.5; }
    .password-reset-support-link { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 2.65rem; padding: .65rem 1rem; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); color: var(--text-primary); font-weight: 750; text-decoration: none; transition: border-color var(--transition), background var(--transition), box-shadow var(--transition); }
    .password-reset-support-link:hover { border-color: var(--primary); background: var(--surface-muted); box-shadow: var(--shadow-sm); }
    .password-reset-support-link:focus-visible { outline: 3px solid var(--primary-ring); outline-offset: 3px; }
    .password-reset-back { display: inline-flex; align-items: center; gap: .45rem; margin-top: 1.35rem; color: var(--secondary); font-size: .87rem; font-weight: 750; text-decoration: none; }
    .password-reset-back:hover { color: var(--secondary-hover); text-decoration: underline; }
    @media (max-width: 520px) {
      .password-reset-page { align-items: start; padding-top: 1.25rem; }
      .password-reset-card { padding: 1.35rem; }
      .password-reset-topbar { margin-bottom: 1.8rem; }
    }
    @media (prefers-reduced-motion: reduce) {
      .password-reset-input, .password-reset-submit { transition: none; }
    }
  </style>
</head>
<body class="mmh-auth-page ds-bg-primary ds-text-primary">
  <main class="password-reset-page">
    <section class="password-reset-card" aria-labelledby="forgot-title">
      <div class="password-reset-topbar">
        <a class="password-reset-brand" href="<?= htmlspecialchars($authRootUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Back to home">
          <img class="password-reset-logo password-reset-logo--light" src="<?= htmlspecialchars($authBrandLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?>">
          <img class="password-reset-logo password-reset-logo--dark" src="<?= htmlspecialchars($authDarkLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?>">
        </a>
        <button class="password-reset-theme mmh-theme-switch" id="password-reset-theme" data-mmh-theme-toggle type="button" aria-label="Switch theme" aria-pressed="false">
          <span class="mmh-theme-switch__knob" aria-hidden="true"><svg class="mmh-theme-switch__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g class="mmh-theme-icon-sun" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2.5v2.2M12 19.3v2.2M4.7 4.7l1.55 1.55m11.5 11.5 1.55 1.55M2.5 12h2.2m14.6 0h2.2M4.7 19.3l1.55-1.55m11.5-11.5L19.3 4.7"></path></g><svg class="mmh-theme-icon-moon" x="0" y="0" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true" focusable="false" fill="#193A34"><path d="M6 .278a.77.77 0 0 1 .08.858 7.2 7.2 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277q.792-.001 1.533-.16a.79.79 0 0 1 .81.316.73.73 0 0 1-.031.893A8.35 8.35 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.75.75 0 0 1 6 .278"/><path d="M10.794 3.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387a1.73 1.73 0 0 0-1.097 1.097l-.387 1.162a.217.217 0 0 1-.412 0l-.387-1.162A1.73 1.73 0 0 0 9.31 6.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387a1.73 1.73 0 0 0 1.097-1.097zM13.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.16 1.16 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.16 1.16 0 0 0-.732-.732l-.774-.258a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732z"/></svg></svg></span>
        </button>
      </div>

      <h1 class="password-reset-heading" id="forgot-title">Forgot your password?</h1>
      <p class="password-reset-intro">Enter the email associated with your account and we'll send you a secure reset link.</p>

      <?php if ($authMessage): ?>
        <div class="password-reset-feedback" role="status" aria-live="polite">
          <span class="password-reset-feedback-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
          <div><strong>Check your email</strong><?= htmlspecialchars($authMessage, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>
      <?php if ($authError): ?>
        <div class="password-reset-feedback password-reset-feedback--error" role="alert" aria-live="assertive">
          <span class="password-reset-feedback-icon" aria-hidden="true"><i class="fas fa-exclamation"></i></span>
          <div><strong>We couldn't send that request</strong><?= htmlspecialchars($authError, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <form class="password-reset-form" method="post" action="<?= htmlspecialchars($authBaseUrl . '/forgot-password', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($authCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="password-reset-field">
          <label for="reset-email">Email address</label>
          <div class="password-reset-input-wrap">
            <i class="password-reset-input-icon fas fa-envelope" aria-hidden="true"></i>
            <input class="password-reset-input" id="reset-email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="190" placeholder="you@example.com" required>
          </div>
        </div>
        <button class="password-reset-submit" type="submit"><span>Send reset link</span><i class="fas fa-arrow-right" aria-hidden="true"></i></button>
      </form>

      <?php if ($authSupportWhatsAppUrl !== ''): ?>
        <div class="password-reset-support-actions">
          <p>Don't have an email linked to your account? Contact our support team and we'll help you recover your account.</p>
          <a class="password-reset-support-link" href="<?= htmlspecialchars($authSupportWhatsAppUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-whatsapp" aria-hidden="true"></i><span>WhatsApp Support</span></a>
        </div>
      <?php else: ?>
        <p class="password-reset-support">Don't have an email linked to your account? <strong>Contact support.</strong></p>
      <?php endif; ?>
      <a class="password-reset-back" href="<?= htmlspecialchars($authBaseUrl . '/login', ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i><span>Back to login</span></a>
    </section>
  </main>
</body>
</html>
