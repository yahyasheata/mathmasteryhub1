<?php
$site_settings = isset($site_settings) && is_array($site_settings) ? $site_settings : getSiteSettings();
$site_name = $site_name ?? ($site_settings['website_name'] ?? 'Math Mastery Hub');
$footerDescription = trim((string) ($site_settings['footer_description'] ?? '')) ?: (string) ($site_settings['website_bio'] ?? '');
$footerBaseUrl = rtrim(mmh_site_public_base_path(), '/');
$footerLogoUrl = mmh_site_settings_asset_url($site_settings, 'website_logo', 'resources/images/default/wide-logo.png');
$footerDarkLogoUrl = mmh_site_public_url('resources/images/branding/mathhub-logo-white.png');
$footerSocialLinks = [
  'twitter_link' => ['X / Twitter', 'fab fa-twitter'], 'facebook_link' => ['Facebook', 'fab fa-facebook-f'], 'instagram_link' => ['Instagram', 'fab fa-instagram'], 'youtube_link' => ['YouTube', 'fab fa-youtube'], 'whatsapp_link' => ['WhatsApp', 'fab fa-whatsapp'],
];
$footerSocialLinks = array_filter($footerSocialLinks, static fn(array $meta, string $key): bool => mmh_site_settings_safe_external_url((string) ($site_settings[$key] ?? '')), ARRAY_FILTER_USE_BOTH);
?>
<style>
  .public-site-footer { margin-top: auto; padding: clamp(2.75rem, 6vw, 4.5rem) 0 1.25rem; border-top: 1px solid var(--border); color: var(--text-secondary); background: var(--bg-primary); }
  .public-footer-shell { width: min(1180px, calc(100% - 32px)); margin: 0 auto; }
  .public-footer-grid { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(150px, .7fr) minmax(190px, .8fr); gap: clamp(1.5rem, 5vw, 4rem); }
  .public-footer-brand p { max-width: 480px; margin: .8rem 0 0; color: var(--text-secondary); font-size: .92rem; line-height: 1.65; }
  .public-footer-logo-link { display: inline-flex; align-items: center; }
  .public-footer-logo { width: min(160px, 100%); height: auto; display: block; }
  .public-footer-links h2, .public-footer-social h2 { margin: .15rem 0 .85rem; color: var(--text-primary); font-size: .94rem; font-weight: 800; }
  .public-footer-links ul { display: grid; gap: .55rem; margin: 0; padding: 0; list-style: none; }
  .public-footer-links a { color: var(--text-secondary); font-size: .9rem; text-decoration: none; }
  .public-footer-links a:hover, .public-footer-links a:focus-visible { color: var(--primary); text-decoration: none; }
  .public-footer-social nav { display: flex; flex-wrap: wrap; gap: .5rem; }
  .public-footer-social a { width: 38px; height: 38px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 50%; color: var(--text-secondary); background: var(--surface-muted); text-decoration: none; transition: color 150ms ease, background 150ms ease, border-color 150ms ease, transform 150ms ease; }
  .public-footer-social a:hover { transform: translateY(-1px); border-color: var(--primary); color: var(--text-inverse); background: var(--primary); }
  .public-footer-social a:focus-visible { outline: 3px solid color-mix(in srgb, var(--primary) 45%, transparent); outline-offset: 2px; }
  .public-footer-bottom { margin-top: clamp(2rem, 5vw, 3.25rem); padding-top: 1.15rem; border-top: 1px solid var(--border); text-align: center; }
  .public-footer-bottom p { margin: 0; color: var(--text-muted); font-size: .82rem; }
  @media (max-width: 900px) { .public-footer-grid { grid-template-columns: minmax(0, 1.3fr) 1fr; } .public-footer-social { grid-column: 1 / -1; } }
  @media (max-width: 700px) { .public-footer-shell { width: min(100% - 20px, 1180px); } }
  @media (max-width: 580px) { .public-footer-grid { grid-template-columns: 1fr; } .public-footer-social { grid-column: auto; } }
</style>
<footer class="public-site-footer">
  <div class="public-footer-shell">
    <div class="public-footer-grid">
      <section class="public-footer-brand" aria-label="About <?=htmlspecialchars((string)($site_settings['website_name'] ?? 'Math Mastery Hub'), ENT_QUOTES, 'UTF-8')?>">
        <a href="<?=$footerBaseUrl ?: '/'?>" class="public-footer-logo-link">
          <img src="<?=htmlspecialchars($footerLogoUrl, ENT_QUOTES, 'UTF-8')?>" class="public-footer-logo mathhub-logo mathhub-logo--light" alt="<?=htmlspecialchars((string)($site_settings['website_name'] ?? 'Math Mastery Hub'), ENT_QUOTES, 'UTF-8')?>">
          <img src="<?=htmlspecialchars($footerDarkLogoUrl, ENT_QUOTES, 'UTF-8')?>" class="public-footer-logo mathhub-logo mathhub-logo--dark" alt="">
        </a>
        <p><?=htmlspecialchars($footerDescription, ENT_QUOTES, 'UTF-8')?></p>
      </section>
      <section class="public-footer-links" aria-labelledby="public-footer-links-title">
        <h2 id="public-footer-links-title">Links</h2>
        <ul>
          <li><a href="<?=$footerBaseUrl ?: '/'?>">Home</a></li>
          <li><a href="<?=$footerBaseUrl?>/courses">Courses</a></li>
          <li><a href="<?=$footerBaseUrl?>/past-papers">Past Papers</a></li>
          <li><a href="<?=$footerBaseUrl?>/free-learning">Free Learning</a></li>
          <li><a href="<?=$footerBaseUrl?>/contact">Contact Us</a></li>
        </ul>
      </section>
      <?php if (mmh_site_setting_truthy($site_settings['footer_show_social'] ?? '1') && $footerSocialLinks): ?><section class="public-footer-social" aria-labelledby="public-footer-social-title">
        <h2 id="public-footer-social-title">Follow Us</h2>
        <nav aria-label="Social links">
          <?php foreach ($footerSocialLinks as $footerKey => [$footerLabel, $footerIcon]): ?><a href="<?=htmlspecialchars((string) $site_settings[$footerKey], ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener noreferrer" aria-label="<?=htmlspecialchars($footerLabel, ENT_QUOTES, 'UTF-8')?>"><i class="<?=$footerIcon?>" aria-hidden="true"></i></a><?php endforeach; ?>
        </nav>
      </section><?php endif; ?>
    </div>
    <div class="public-footer-bottom"><p>&copy; <?=date('Y')?> <?=htmlspecialchars((string)($site_settings['website_name'] ?? 'Math Mastery Hub'), ENT_QUOTES, 'UTF-8')?> · All rights reserved</p></div>
  </div>
</footer>
