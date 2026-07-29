<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';

$conn = db();
$pageName = 'settings';
$settings = mmh_site_settings($conn);
$flash = $_SESSION['mmh_site_settings_flash'] ?? null;
unset($_SESSION['mmh_site_settings_flash']);
$old = is_array($flash['old'] ?? null) ? $flash['old'] : [];
$values = array_merge($settings, $old);
$errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
$lastUpdated = $conn->query('SELECT MAX(updated_at) AS updated_at FROM settings')->fetch_assoc()['updated_at'] ?? null;
$driveConfigured = trim((string) getenv('MMH_GOOGLE_DRIVE_API_KEY')) !== '';
$mailConfigured = trim((string) getenv('MAIL_HOST')) !== '' || trim((string) getenv('SMTP_HOST')) !== '';
$csrfToken = mmh_auth_csrf_token();

function mmh_settings_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function mmh_settings_value(array $values, string $key): string { return (string) ($values[$key] ?? ''); }
function mmh_settings_checked(array $values, string $key): string { return mmh_site_setting_truthy($values[$key] ?? '0') ? ' checked' : ''; }
function mmh_settings_error(array $errors, string $key): string { return isset($errors[$key]) ? '<small class="admin-settings-error">' . mmh_settings_escape($errors[$key]) . '</small>' : ''; }
function mmh_settings_datetime(array $values, string $key): string { $value = trim(mmh_settings_value($values, $key)); return $value === '' ? '' : date('Y-m-d\TH:i', strtotime($value)); }
function mmh_settings_asset_url(string $baseUrl, array $values, string $key): string { $fallbacks = ['website_logo' => 'resources/images/default/wide-logo.png', 'website_wide_logo' => 'resources/images/default/wide-logo.png', 'website_icon' => 'resources/images/default/favicon.png', 'website_cover' => 'resources/images/default/cover.png']; return mmh_site_settings_asset_url($values, $key, $fallbacks[$key] ?? 'resources/images/default/logo.png'); }

$navigation = [
    'home' => ['Home', '/'],
    'courses' => ['Courses', '/courses'],
    'past_papers' => ['Past Papers', '/past-papers'],
    'free_learning' => ['Free Learning', '/free-learning'],
    'blog' => ['Blog', '/blog'],
    'contact' => ['Contact', '/contact'],
];
$adminSettingsBase = rtrim(mmh_site_public_base_path(), '/');
$settingsSaveState = (($flash['type'] ?? '') === 'success') ? 'All changes saved' : 'No unsaved changes';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Website Settings · <?=mmh_settings_escape($settings['website_name'])?></title>
  <?php include 'layouts/admin/header.php'; ?>
  <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/admin-settings.css')?>?v=<?=@filemtime('resources/css/admin-settings.css') ?: 1?>">
</head>
<body class="dash ds-bg-primary admin-settings-page">
<form method="POST" action="<?=$adminSettingsBase?>/resources/logout" id="logout-form" class="d-none"></form>
<div class="col-12 d-flex">
  <?php include 'layouts/admin/aside.php'; ?>
  <div class="main-content in-active admin-settings-main">
    <?php include 'layouts/admin/top-nav.php'; ?>
    <main class="admin-settings-shell" id="main-content">
      <header class="admin-settings-header">
        <div>
          <p class="admin-settings-eyebrow">Platform administration</p>
          <h1>Website Settings</h1>
          <p>Manage the public identity, homepage messaging, navigation, support details, SEO, and operational notices from one place.</p>
        </div>
        <div class="admin-settings-header-meta">
          <a class="btn btn-outline-secondary" href="<?= $adminSettingsBase !== '' ? $adminSettingsBase : '/' ?>" target="_blank" rel="noopener"><span class="fas fa-external-link-alt" aria-hidden="true"></span> View website</a>
          <?php if ($lastUpdated): ?><small>Last settings update <?=mmh_settings_escape(date('M j, Y · g:i A', strtotime($lastUpdated)))?></small><?php endif; ?>
        </div>
      </header>

      <?php if ($flash): ?><div class="admin-settings-notice <?=($flash['type'] ?? '') === 'success' ? 'is-success' : 'is-error'?>" role="status"><span class="<?=($flash['type'] ?? '') === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle'?>" aria-hidden="true"></span><?=mmh_settings_escape($flash['message'] ?? '')?></div><?php endif; ?>

      <form class="admin-settings-layout" method="post" action="<?=$adminSettingsBase?>/admin/requests/settings/update" enctype="multipart/form-data" data-settings-form>
        <input type="hidden" name="csrf_token" value="<?=mmh_settings_escape($csrfToken)?>">
        <nav class="admin-settings-nav" aria-label="Website settings sections">
          <?php foreach ([
            'general' => ['fa-sliders-h', 'General'], 'branding' => ['fa-image', 'Branding'], 'homepage' => ['fa-home', 'Homepage'], 'navigation' => ['fa-bars', 'Navigation'], 'contact' => ['fa-address-card', 'Contact & Social'], 'footer' => ['fa-columns', 'Footer'], 'seo' => ['fa-search', 'SEO'], 'announcements' => ['fa-bullhorn', 'Announcements'], 'integrations' => ['fa-plug', 'Integrations'], 'maintenance' => ['fa-tools', 'Maintenance'],
          ] as $id => [$icon, $label]): ?><button type="button" class="admin-settings-nav-item <?=$id === 'general' ? 'is-active' : ''?>" data-settings-tab="<?=$id?>"><span class="fas <?=$icon?>" aria-hidden="true"></span><?=$label?></button><?php endforeach; ?>
        </nav>

        <div class="admin-settings-content">
          <section class="admin-settings-panel is-active" data-settings-panel="general" aria-labelledby="general-title">
            <div class="admin-settings-panel-heading"><div><h2 id="general-title">General</h2><p>Core public identity and support details shown across the website.</p></div></div>
            <div class="admin-settings-card"><div class="admin-settings-grid two">
              <label>Website name<input class="form-control <?=isset($errors['website_name'])?'is-invalid':''?>" name="settings[website_name]" value="<?=mmh_settings_escape(mmh_settings_value($values,'website_name'))?>" maxlength="190" required><small>Used in page titles, navigation, and footer copyright.</small><?=mmh_settings_error($errors,'website_name')?></label>
              <label>Website tagline<input class="form-control <?=isset($errors['website_tagline'])?'is-invalid':''?>" name="settings[website_tagline]" value="<?=mmh_settings_escape(mmh_settings_value($values,'website_tagline'))?>" maxlength="240"><small>A short supporting line for public-facing areas.</small><?=mmh_settings_error($errors,'website_tagline')?></label>
              <label class="span-all">About the website<textarea class="form-control <?=isset($errors['website_bio'])?'is-invalid':''?>" name="settings[website_bio]" rows="6" maxlength="3000"><?=mmh_settings_escape(mmh_settings_value($values,'website_bio'))?></textarea><small>Shown in the public footer and used as a safe fallback description.</small><?=mmh_settings_error($errors,'website_bio')?></label>
              <label>Support email<input class="form-control <?=isset($errors['contact_email'])?'is-invalid':''?>" type="email" name="settings[contact_email]" value="<?=mmh_settings_escape(mmh_settings_value($values,'contact_email'))?>" maxlength="190" autocomplete="email"><small>Displayed on the Contact page.</small><?=mmh_settings_error($errors,'contact_email')?></label>
              <label>Public support phone<input class="form-control" type="tel" name="settings[phone]" value="<?=mmh_settings_escape(mmh_settings_value($values,'phone'))?>" maxlength="80"><small>Optional public support number.</small><?=mmh_settings_error($errors,'phone')?></label>
              <label>WhatsApp number<input class="form-control" type="tel" name="settings[whatsapp_phone]" value="<?=mmh_settings_escape(mmh_settings_value($values,'whatsapp_phone'))?>" maxlength="80"><small>Display number only; the WhatsApp URL is managed below.</small><?=mmh_settings_error($errors,'whatsapp_phone')?></label>
              <div class="admin-settings-readonly"><span class="fas fa-clock" aria-hidden="true"></span><div><strong>Platform timezone</strong><p>Africa/Cairo is currently server-managed for reliable learning and schedule timestamps.</p></div></div>
            </div></div>
          </section>

          <section class="admin-settings-panel" data-settings-panel="branding" aria-labelledby="branding-title">
            <div class="admin-settings-panel-heading"><div><h2 id="branding-title">Branding</h2><p>Upload replacement assets safely. Existing assets stay in place if validation fails.</p></div></div>
            <div class="admin-settings-grid asset-grid">
              <?php foreach ([
                'website_logo' => ['Main logo', 'Recommended: transparent PNG or WebP, at least 320px wide.', 'Used on light public surfaces.'],
                'website_wide_logo' => ['Compact / wide logo', 'Optional. PNG or WebP, at least 240px wide.', 'Reserved for layouts that support a compact brand mark.'],
                'website_icon' => ['Favicon', 'PNG, WebP, or ICO. Recommended: square 512 × 512.', 'Used by browser tabs and saved shortcuts.'],
                'website_cover' => ['Social sharing image', 'JPG, PNG, WebP, or GIF. Recommended: 1200 × 630.', 'Used as the default social-sharing image.'],
              ] as $key => [$label, $help, $usage]): $assetUrl = mmh_settings_asset_url($baseUrl,$values,$key); ?>
              <article class="admin-settings-asset" data-asset-card>
                <div class="admin-settings-asset-preview" data-asset-preview><?php if ($assetUrl): ?><img src="<?=mmh_settings_escape($assetUrl)?>" alt="Current <?=strtolower($label)?>"><?php else: ?><span class="fas fa-image" aria-hidden="true"></span><small>No image selected</small><?php endif; ?></div>
                <div><h3><?=$label?></h3><p><?=$usage?></p><small><?=$help?> Maximum 5 MB. SVG is not accepted.</small></div>
                <label class="btn btn-outline-secondary admin-settings-upload"><span class="fas fa-upload" aria-hidden="true"></span> Replace<input type="file" name="settings[<?=$key?>]" accept="image/jpeg,image/png,image/webp,image/gif,image/x-icon,image/vnd.microsoft.icon" data-asset-input></label>
                <?=mmh_settings_error($errors,$key)?></article><?php endforeach; ?>
            </div>
          </section>

          <section class="admin-settings-panel" data-settings-panel="homepage" aria-labelledby="homepage-title">
            <div class="admin-settings-panel-heading"><div><h2 id="homepage-title">Homepage</h2><p>Manage the existing hero and course introduction without editing the homepage template.</p></div></div>
            <div class="admin-settings-card"><div class="admin-settings-toggle-row"><input id="home-hero-enabled" type="checkbox" name="settings[home_hero_enabled]" value="1"<?=mmh_settings_checked($values,'home_hero_enabled')?>><label for="home-hero-enabled"><strong>Show homepage hero</strong><small>Hides the existing hero section when turned off.</small></label></div><div class="admin-settings-grid two">
              <label class="span-all">Hero headline<input class="form-control" name="settings[home_hero_title]" value="<?=mmh_settings_escape(mmh_settings_value($values,'home_hero_title'))?>" maxlength="240"><small>Use <code>{site_name}</code> to insert the current website name.</small><?=mmh_settings_error($errors,'home_hero_title')?></label>
              <label class="span-all">Hero supporting text<textarea class="form-control" name="settings[home_hero_description]" rows="4" maxlength="1000"><?=mmh_settings_escape(mmh_settings_value($values,'home_hero_description'))?></textarea><?=mmh_settings_error($errors,'home_hero_description')?></label>
              <label>Primary button label<input class="form-control" name="settings[home_primary_label]" value="<?=mmh_settings_escape(mmh_settings_value($values,'home_primary_label'))?>" maxlength="80"></label>
              <label>Primary button URL<input class="form-control" name="settings[home_primary_url]" value="<?=mmh_settings_escape(mmh_settings_value($values,'home_primary_url'))?>" maxlength="500"><small>Use a site-relative path or HTTPS URL.</small><?=mmh_settings_error($errors,'home_primary_url')?></label>
              <label>Secondary button label<input class="form-control" name="settings[home_secondary_label]" value="<?=mmh_settings_escape(mmh_settings_value($values,'home_secondary_label'))?>" maxlength="80"></label>
              <label>Secondary button URL<input class="form-control" name="settings[home_secondary_url]" value="<?=mmh_settings_escape(mmh_settings_value($values,'home_secondary_url'))?>" maxlength="500"><small>Use a site-relative path or HTTPS URL.</small><?=mmh_settings_error($errors,'home_secondary_url')?></label>
            </div></div>
            <div class="admin-settings-card"><div class="admin-settings-toggle-row"><input id="home-courses-enabled" type="checkbox" name="settings[home_courses_enabled]" value="1"<?=mmh_settings_checked($values,'home_courses_enabled')?>><label for="home-courses-enabled"><strong>Show featured courses section</strong><small>Controls the existing homepage course section.</small></label></div><div class="admin-settings-grid two"><label>Courses heading<input class="form-control" name="settings[home_courses_heading]" value="<?=mmh_settings_escape(mmh_settings_value($values,'home_courses_heading'))?>" maxlength="160"></label><label>Courses description<textarea class="form-control" name="settings[home_courses_description]" rows="2" maxlength="600"><?=mmh_settings_escape(mmh_settings_value($values,'home_courses_description'))?></textarea></label></div></div>
          </section>

          <section class="admin-settings-panel" data-settings-panel="navigation" aria-labelledby="navigation-title">
            <div class="admin-settings-panel-heading"><div><h2 id="navigation-title">Navigation</h2><p>Rename, show, hide, and order the existing public navigation routes. Destinations remain protected fixed routes.</p></div></div>
            <div class="admin-settings-card admin-settings-navigation-table"><div class="admin-settings-navigation-head"><span>Link</span><span>Label</span><span>Visible</span><span>Order</span></div><?php foreach($navigation as $key => [$fallbackLabel,$path]): ?><div class="admin-settings-navigation-row"><span><strong><?=$fallbackLabel?></strong><small><?=mmh_settings_escape($path ?: '/')?></small></span><label><input class="form-control" name="settings[nav_<?=$key?>_label]" value="<?=mmh_settings_escape(mmh_settings_value($values,'nav_'.$key.'_label'))?>" maxlength="60"></label><label class="admin-settings-switch"><input type="checkbox" name="settings[nav_<?=$key?>_enabled]" value="1"<?=mmh_settings_checked($values,'nav_'.$key.'_enabled')?>><span>Visible</span></label><label><input class="form-control" type="number" min="0" max="999" name="settings[nav_<?=$key?>_order]" value="<?=mmh_settings_escape(mmh_settings_value($values,'nav_'.$key.'_order'))?>"></label></div><?php endforeach; ?></div>
          </section>

          <section class="admin-settings-panel" data-settings-panel="contact" aria-labelledby="contact-title">
            <div class="admin-settings-panel-heading"><div><h2 id="contact-title">Contact &amp; Social</h2><p>Optional social links appear only when a valid URL is saved.</p></div></div>
            <div class="admin-settings-card"><div class="admin-settings-grid two"><?php foreach(['facebook_link'=>'Facebook URL','instagram_link'=>'Instagram URL','youtube_link'=>'YouTube URL','telegram_link'=>'Telegram URL','twitter_link'=>'X / Twitter URL','whatsapp_link'=>'WhatsApp URL'] as $key=>$label): ?><label><?=$label?><input class="form-control <?=isset($errors[$key])?'is-invalid':''?>" type="url" name="settings[<?=$key?>]" value="<?=mmh_settings_escape(mmh_settings_value($values,$key))?>" maxlength="500" placeholder="https://..."><small>Optional HTTPS link.</small><?=mmh_settings_error($errors,$key)?></label><?php endforeach;?></div></div>
          </section>

          <section class="admin-settings-panel" data-settings-panel="footer" aria-labelledby="footer-title">
            <div class="admin-settings-panel-heading"><div><h2 id="footer-title">Footer</h2><p>Keep footer content concise and let empty optional social links disappear naturally.</p></div></div>
            <div class="admin-settings-card"><div class="admin-settings-grid"><label>Footer description<textarea class="form-control" name="settings[footer_description]" rows="5" maxlength="1200"><?=mmh_settings_escape(mmh_settings_value($values,'footer_description'))?></textarea><small>When empty, the website About text is used.</small><?=mmh_settings_error($errors,'footer_description')?></label><div class="admin-settings-toggle-row"><input id="footer-show-social" type="checkbox" name="settings[footer_show_social]" value="1"<?=mmh_settings_checked($values,'footer_show_social')?>><label for="footer-show-social"><strong>Show social links</strong><small>Only saved links are shown.</small></label></div></div></div>
          </section>

          <section class="admin-settings-panel" data-settings-panel="seo" aria-labelledby="seo-title">
            <div class="admin-settings-panel-heading"><div><h2 id="seo-title">SEO</h2><p>Safe defaults used by the current SEO helper. Verification scripts and raw HTML are intentionally not accepted.</p></div></div>
            <div class="admin-settings-card"><div class="admin-settings-grid two"><label>Default title format<input class="form-control" name="settings[seo_default_title]" value="<?=mmh_settings_escape(mmh_settings_value($values,'seo_default_title'))?>" maxlength="190"><small>Use <code>{site_name}</code> for the current site name.</small></label><label>Canonical base URL<input class="form-control <?=isset($errors['seo_canonical_base_url'])?'is-invalid':''?>" type="url" name="settings[seo_canonical_base_url]" value="<?=mmh_settings_escape(mmh_settings_value($values,'seo_canonical_base_url'))?>" maxlength="500" placeholder="https://example.com"><small>Optional; leave empty to use the current domain.</small><?=mmh_settings_error($errors,'seo_canonical_base_url')?></label><label class="span-all">Default meta description<textarea class="form-control" name="settings[seo_default_description]" rows="3" maxlength="320"><?=mmh_settings_escape(mmh_settings_value($values,'seo_default_description'))?></textarea><small>Used only when a page has no more specific description.</small></label><label class="span-all">Keywords<textarea class="form-control" name="settings[website_keywords]" rows="3" maxlength="1000" placeholder="Separate keywords with commas"><?=mmh_settings_escape(mmh_settings_value($values,'website_keywords'))?></textarea></label><div class="admin-settings-toggle-row"><input id="seo-indexing" type="checkbox" name="settings[seo_indexing]" value="1"<?=mmh_settings_checked($values,'seo_indexing')?>><label for="seo-indexing"><strong>Allow search indexing</strong><small>When turned off, the shared SEO helper requests noindex.</small></label></div></div></div>
          </section>

          <section class="admin-settings-panel" data-settings-panel="announcements" aria-labelledby="announcements-title">
            <div class="admin-settings-panel-heading"><div><h2 id="announcements-title">Announcement</h2><p>A small, scheduled platform notice rendered by the shared public navigation shell.</p></div></div>
            <div class="admin-settings-card"><div class="admin-settings-toggle-row"><input id="announcement-enabled" type="checkbox" name="settings[announcement_enabled]" value="1"<?=mmh_settings_checked($values,'announcement_enabled')?>><label for="announcement-enabled"><strong>Enable announcement</strong><small>Expired notices disappear automatically.</small></label></div><div class="admin-settings-grid two"><label class="span-all">Message<textarea class="form-control" name="settings[announcement_message]" rows="4" maxlength="600"><?=mmh_settings_escape(mmh_settings_value($values,'announcement_message'))?></textarea></label><label>Style<select class="form-select" name="settings[announcement_type]"><?php foreach(['info'=>'Information','success'=>'Success','warning'=>'Warning','urgent'=>'Urgent'] as $value=>$label): ?><option value="<?=$value?>" <?=mmh_settings_value($values,'announcement_type')===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Audience<select class="form-select" name="settings[announcement_audience]"><?php foreach(['all'=>'Everyone','public'=>'Visitors only','students'=>'Signed-in students only'] as $value=>$label): ?><option value="<?=$value?>" <?=mmh_settings_value($values,'announcement_audience')===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Action label<input class="form-control" name="settings[announcement_action_label]" value="<?=mmh_settings_escape(mmh_settings_value($values,'announcement_action_label'))?>" maxlength="80"></label><label>Action URL<input class="form-control" name="settings[announcement_action_url]" value="<?=mmh_settings_escape(mmh_settings_value($values,'announcement_action_url'))?>" maxlength="500" placeholder="/courses or https://..."><?=mmh_settings_error($errors,'announcement_action_url')?></label><label>Start date and time<input class="form-control" type="datetime-local" name="settings[announcement_starts_at]" value="<?=mmh_settings_escape(mmh_settings_datetime($values,'announcement_starts_at'))?>"></label><label>End date and time<input class="form-control" type="datetime-local" name="settings[announcement_ends_at]" value="<?=mmh_settings_escape(mmh_settings_datetime($values,'announcement_ends_at'))?>"></label></div></div>
          </section>

          <section class="admin-settings-panel" data-settings-panel="integrations" aria-labelledby="integrations-title">
            <div class="admin-settings-panel-heading"><div><h2 id="integrations-title">Integrations</h2><p>Configuration status only. Credentials remain in environment configuration and are never shown here.</p></div></div>
            <div class="admin-settings-status-grid"><article class="admin-settings-status <?= $driveConfigured ? 'is-ready' : ''?>"><span class="fab fa-google-drive" aria-hidden="true"></span><div><h3>Google Drive importer</h3><p><?= $driveConfigured ? 'API key configured in the environment.' : 'No API key detected. Configure MMH_GOOGLE_DRIVE_API_KEY in the environment.'?></p></div><strong><?= $driveConfigured ? 'Configured' : 'Needs setup'?></strong></article><article class="admin-settings-status <?= $mailConfigured ? 'is-ready' : ''?>"><span class="fas fa-envelope" aria-hidden="true"></span><div><h3>Email delivery</h3><p><?= $mailConfigured ? 'Mail transport environment configuration detected.' : 'No mail transport environment configuration detected.'?></p></div><strong><?= $mailConfigured ? 'Configured' : 'Needs setup'?></strong></article></div>
          </section>

          <section class="admin-settings-panel" data-settings-panel="maintenance" aria-labelledby="maintenance-title">
            <div class="admin-settings-panel-heading"><div><h2 id="maintenance-title">Maintenance</h2><p>Use only for planned work. Authenticated administrators bypass the public maintenance screen.</p></div></div>
            <div class="admin-settings-card admin-settings-danger-zone"><div class="admin-settings-toggle-row"><input id="maintenance-enabled" type="checkbox" name="settings[maintenance_enabled]" value="1"<?=mmh_settings_checked($values,'maintenance_enabled')?>><label for="maintenance-enabled"><strong>Enable maintenance mode</strong><small>Public and student requests show a concise maintenance page; Admin routes remain available.</small></label></div><div class="admin-settings-grid"><label>Maintenance title<input class="form-control" name="settings[maintenance_title]" value="<?=mmh_settings_escape(mmh_settings_value($values,'maintenance_title'))?>" maxlength="190"></label><label>Maintenance message<textarea class="form-control" name="settings[maintenance_message]" rows="4" maxlength="1000"><?=mmh_settings_escape(mmh_settings_value($values,'maintenance_message'))?></textarea></label><label>Estimated reopening<input class="form-control" type="datetime-local" name="settings[maintenance_reopen_at]" value="<?=mmh_settings_escape(mmh_settings_datetime($values,'maintenance_reopen_at'))?>"></label></div></div>
          </section>
        </div>

        <footer class="admin-settings-savebar"><div><strong data-settings-state><?=mmh_settings_escape($settingsSaveState)?></strong><small>Changes apply after a successful save. Existing assets are preserved until replacement succeeds.</small></div><button class="btn btn-primary" type="submit" data-settings-submit><span class="fas fa-save" aria-hidden="true"></span><span data-settings-submit-label>Save changes</span></button></footer>
      </form>
    </main>
  </div>
</div>
<script>
(function () {
  var form=document.querySelector('[data-settings-form]'); if(!form)return;
  var nav=Array.from(document.querySelectorAll('[data-settings-tab]')), panels=Array.from(document.querySelectorAll('[data-settings-panel]'));
  function openTab(id){nav.forEach(function(item){item.classList.toggle('is-active',item.dataset.settingsTab===id);});panels.forEach(function(panel){panel.classList.toggle('is-active',panel.dataset.settingsPanel===id);});}
  nav.forEach(function(button){button.addEventListener('click',function(){openTab(button.dataset.settingsTab);});});
  var dirty=false, state=document.querySelector('[data-settings-state]');
  function markDirty(){if(!dirty){dirty=true;if(state)state.textContent='Unsaved changes';}}
  form.addEventListener('input',markDirty); form.addEventListener('change',markDirty);
  form.querySelectorAll('[data-asset-input]').forEach(function(input){input.addEventListener('change',function(){var file=input.files&&input.files[0], card=input.closest('[data-asset-card]'), preview=card&&card.querySelector('[data-asset-preview]');if(!file||!preview)return;var reader=new FileReader();reader.onload=function(event){preview.innerHTML='<img src="'+event.target.result+'" alt="Selected image preview">';};reader.readAsDataURL(file);});});
  form.addEventListener('submit',function(){var submit=form.querySelector('[data-settings-submit]'),label=form.querySelector('[data-settings-submit-label]');if(submit){submit.disabled=true;submit.setAttribute('aria-busy','true');}if(label)label.textContent='Saving changes…';});
  window.addEventListener('beforeunload',function(event){if(!dirty)return;event.preventDefault();event.returnValue='';});
})();
</script>
</body>
</html>
