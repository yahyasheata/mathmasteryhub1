<?php
$publicBaseUrl = rtrim(mmh_site_public_base_path(), '/');
$publicSiteName = $site_name ?? ($site_settings['website_name'] ?? 'Math Mastery Hub');
$publicLogoUrl = mmh_site_public_url('resources/images/branding/mathhub-logo-light.png') . '?v=' . (@filemtime('resources/images/branding/mathhub-logo-light.png') ?: 1);
$publicDarkLogoUrl = mmh_site_public_url('resources/images/branding/mathhub-logo-white.png');
$publicUserLoggedIn = !empty($_SESSION['username']);
$publicUserName = 'Account';

if ($publicUserLoggedIn && function_exists('db')) {
    try {
        if (function_exists('getUserData')) {
            $publicUserData = getUserData($_SESSION['username']);
            $publicNameCandidate = trim((string)($publicUserData['full_name'] ?? ''));
            if ($publicNameCandidate !== '') { $publicUserName = $publicNameCandidate; }
        }
    } catch (Throwable $publicNavError) {
        $publicUserName = 'Account';
    }
}

$publicNavigationMap = [
    'home' => ['label' => 'Home', 'href' => $publicBaseUrl . '/'],
    'courses' => ['label' => 'Courses', 'href' => $publicBaseUrl . '/courses'],
    'past_papers' => ['label' => 'Past Papers', 'href' => $publicBaseUrl . '/past-papers'],
    'free_learning' => ['label' => 'Free Learning', 'href' => $publicBaseUrl . '/free-learning'],
    'blog' => ['label' => 'Blog', 'href' => $publicBaseUrl . '/blog'],
    'contact' => ['label' => 'Contact', 'href' => $publicBaseUrl . '/contact'],
];
$publicOrderedNavigation = [];
foreach ($publicNavigationMap as $publicNavKey => $publicNavDefault) {
    if (!mmh_site_setting_truthy($site_settings['nav_' . $publicNavKey . '_enabled'] ?? '1')) continue;
    $label = trim((string) ($site_settings['nav_' . $publicNavKey . '_label'] ?? $publicNavDefault['label']));
    $publicOrderedNavigation[] = [
        'label' => $label !== '' ? $label : $publicNavDefault['label'],
        'href' => $publicNavDefault['href'],
        'order' => (int) ($site_settings['nav_' . $publicNavKey . '_order'] ?? 100),
    ];
}
usort($publicOrderedNavigation, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
if (!$publicOrderedNavigation) $publicOrderedNavigation[] = ['label' => 'Home', 'href' => $publicBaseUrl . '/', 'order' => 0];
$publicNavItems = array_slice($publicOrderedNavigation, 0, 4);
$publicMoreItems = array_slice($publicOrderedNavigation, 4);
$publicAnnouncement = mmh_site_settings_active_announcement($site_settings, $publicUserLoggedIn);
$publicAccountItems = [
    ['label' => 'My Courses', 'href' => $publicBaseUrl . '/user/my-courses'],
    ['label' => 'My Progress', 'href' => $publicBaseUrl . '/user/analytics'],
    ['label' => 'Revision Plans', 'href' => $publicBaseUrl . '/user/revision-plans'],
    ['label' => 'Assignments', 'href' => $publicBaseUrl . '/user/assignments'],
    ['label' => 'Live Sessions', 'href' => $publicBaseUrl . '/user/live-sessions'],
    ['label' => 'Notifications', 'href' => $publicBaseUrl . '/user/notifications'],
    ['label' => 'Settings', 'href' => $publicBaseUrl . '/user/settings'],
    ['label' => 'Logout', 'href' => $publicBaseUrl . '/user/logout'],
];
function public_nav_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<style>
  .public-site-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    width: 100%;
    border-radius: 0;
    z-index: 50;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    box-shadow: none;
    backdrop-filter: none;
    transition: background var(--transition-fast), border-color var(--transition-fast), box-shadow var(--transition-fast);
  }
  body {
    --public-header-offset: 72px;
    padding-top: var(--public-header-offset);
  }
  .public-header-shell {
    width: min(1180px, calc(100% - 32px));
    margin: 0 auto;
  }
  .public-header-row {
    min-height: 72px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
  }
  .public-logo-link {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    flex: 0 0 auto;
    text-decoration: none;
  }
  .public-logo-link img {
    width: 112px;
    height: auto;
  }
  .public-desktop-nav {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 1.05rem;
    margin-left: auto;
  }
  .public-site-nav {
    display: flex;
    align-items: center;
    gap: 1.05rem;
    white-space: nowrap;
  }
  .public-site-nav a,
  .public-more-toggle,
  .public-account-toggle {
    color: var(--text-secondary);
    font-weight: 700;
    text-decoration: none;
    transition: color var(--transition-fast), background var(--transition-fast), border-color var(--transition-fast);
  }
  .public-site-nav a:hover,
  .public-site-nav a.is-active,
  .public-more-toggle:hover,
  .public-account-toggle:hover {
    color: var(--primary);
  }
  .public-dropdown { position: relative; }
  .public-dropdown > summary {
    list-style: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
  }
  .public-dropdown > summary::-webkit-details-marker { display: none; }
  .public-dropdown-menu {
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    min-width: 220px;
    padding: .55rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface-elevated, var(--surface));
    box-shadow: var(--shadow-lg);
    z-index: 60;
    display: grid;
    gap: .2rem;
  }
  .public-dropdown-menu a {
    display: flex;
    align-items: center;
    min-height: 38px;
    padding: .55rem .75rem;
    border-radius: var(--radius-sm);
    color: var(--text-secondary);
    text-decoration: none;
    white-space: nowrap;
  }
  .public-dropdown-menu a:hover {
    color: var(--text-primary);
    background: var(--surface-hover);
  }
  .public-account-dropdown > summary { max-width: 280px; }
  .public-account-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .public-account-menu {
    width: min(248px, calc(100vw - 24px));
    max-height: min(65vh, 360px);
    top: calc(100% + 4px);
    right: 0;
    overflow-y: auto;
    padding: .3rem;
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md, var(--shadow-sm));
    gap: .12rem;
  }
  .public-account-menu a {
    min-height: 36px;
    padding: .42rem .6rem;
    border-radius: var(--radius-sm);
    font-family: var(--font-sans);
    font-size: .88rem;
    font-weight: 550;
    line-height: 1.25;
    white-space: normal;
  }
  .public-account-menu a:not(.is-logout) + a:not(.is-logout) {
    border-top: 1px solid color-mix(in srgb, var(--border) 72%, transparent);
  }
  .public-account-menu a:hover,
  .public-account-menu a:active {
    color: var(--text-primary);
    background: var(--surface-hover);
  }
  .public-account-menu a:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 1px;
    color: var(--text-primary);
    background: var(--surface-hover);
  }
  .public-account-menu a.is-logout,
  .public-mobile-account-group a.is-logout {
    margin-top: .25rem;
    padding-top: .65rem;
    border-top: 1px solid var(--border);
    color: var(--danger, var(--text-secondary));
  }
  .public-mobile-account-group a.is-logout { margin-top: .2rem; }
  .public-auth-actions {
    display: flex;
    align-items: center;
    gap: .65rem;
  }
  .public-nav-button,
  button.public-nav-button {
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    border-radius: var(--radius-sm);
    padding: 8.8px 15.2px;
    font: 800 16px/normal var(--font-sans);
    font-family: var(--font-sans) !important;
    box-sizing: border-box;
    text-decoration: none;
    border: 1px solid var(--border);
  }
  #public-site-header .public-nav-button { font-family: var(--font-sans) !important; }
  .public-nav-button.primary {
    background: var(--primary-action);
    color: var(--primary-foreground);
    border-color: var(--primary-action);
  }
  .public-nav-button.primary:hover {
    background: var(--primary-action-hover);
    border-color: var(--primary-action-hover);
  }
  .public-nav-button.secondary {
    color: var(--text-secondary);
    background: var(--surface);
  }
  .public-mobile-actions {
    display: none;
    align-items: center;
    gap: .65rem;
  }
  .public-mobile-toggle {
    min-width: 40px;
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    background: var(--surface);
    cursor: pointer;
    transition: color var(--transition-fast), background var(--transition-fast), border-color var(--transition-fast);
  }
  .public-mobile-toggle:hover {
    color: var(--primary);
    background: var(--surface-hover);
  }
  .public-theme-toggle.mmh-theme-switch:hover { filter:brightness(.97); }
  .public-mobile-menu {
    display: none;
    position: absolute;
    left: 0;
    right: 0;
    top: 100%;
    z-index: 55;
    max-height: calc(100vh - var(--public-header-offset, 66px));
    max-height: calc(100dvh - var(--public-header-offset, 66px));
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    border-top: 1px solid var(--border);
    background: var(--surface-elevated, var(--surface));
    box-shadow: var(--shadow-lg);
  }
  .public-mobile-grid {
    width: min(1180px, calc(100% - 32px));
    margin: 0 auto;
    padding: .85rem 0 1rem;
    display: grid;
    gap: .25rem;
  }
  .public-mobile-grid a {
    min-height: 42px;
    display: flex;
    align-items: center;
    padding: .55rem .25rem;
    color: var(--text-secondary);
    text-decoration: none;
    font-weight: 700;
    border-radius: var(--radius-md);
  }
  .public-mobile-grid a:hover {
    color: var(--primary);
    background: var(--surface-hover);
  }
  .public-site-announcement { border-bottom: 1px solid var(--border); background: var(--surface-muted); color: var(--text-secondary); }
  .public-site-announcement > div { min-height: 40px; display:flex; align-items:center; gap:.65rem; padding:.5rem 0; font-size:.82rem; }
  .public-site-announcement p { margin:0; flex:1; }
  .public-site-announcement a { color:inherit; font-weight:800; text-decoration:underline; }
  .public-site-announcement--success { color:var(--success); }
  .public-site-announcement--warning,.public-site-announcement--urgent { color:var(--warning); }
  .public-site-announcement--urgent { border-bottom-color:color-mix(in srgb,var(--danger) 42%,var(--border)); color:var(--danger); }
  .public-mobile-account-group {
    margin-top: .45rem;
    padding-top: .45rem;
    border-top: 1px solid var(--border);
  }
  @media (max-width: 1120px) {
    .public-site-nav,
    .public-desktop-nav { gap: .78rem; }
    .public-nav-button { padding-inline: 12px; }
  }
  @media (max-width: 1023px) {
    body { --public-header-offset: 66px; }
    .public-header-row { min-height: 66px; }
    .public-desktop-nav { display: none; }
    .public-mobile-actions { display: flex; }
    .public-mobile-menu:not(.hidden) { display: block; }
  }
  @media (min-width: 1024px) {
    .public-mobile-menu { display: none; }
  }
  @media (max-width: 640px) {
    .public-header-shell,
    .public-mobile-grid { width: min(100% - 20px, 1180px); }
    .public-logo-link img { width: 100px; }
  }
</style>
<header id="public-site-header" class="public-site-header">
    <div class="public-header-shell">
        <div class="public-header-row">
            <a href="<?=public_nav_html($publicBaseUrl)?>/" class="public-logo-link" aria-label="<?=public_nav_html($publicSiteName)?> home">
              <img src="<?=public_nav_html($publicLogoUrl)?>" style="width: 112px;" alt="<?=public_nav_html($publicSiteName);?>" class="mathhub-logo mathhub-logo--light">
              <img src="<?=public_nav_html($publicDarkLogoUrl)?>" style="width: 112px;" alt="<?=public_nav_html($publicSiteName);?>" class="mathhub-logo mathhub-logo--dark">
            </a>
            
            <nav class="public-desktop-nav" aria-label="Public navigation">
                <div class="public-site-nav">
                    <?php foreach ($publicNavItems as $publicNavItem): ?>
                        <a href="<?=public_nav_html($publicNavItem['href'])?>"><?=public_nav_html($publicNavItem['label'])?></a>
                    <?php endforeach; ?>
                    <?php if ($publicMoreItems): ?><details class="public-dropdown">
                        <summary class="public-more-toggle">More <i class="fas fa-chevron-down text-xs" aria-hidden="true"></i></summary>
                        <div class="public-dropdown-menu">
                            <?php foreach ($publicMoreItems as $publicMoreItem): ?>
                                <a href="<?=public_nav_html($publicMoreItem['href'])?>"><?=public_nav_html($publicMoreItem['label'])?></a>
                            <?php endforeach; ?>
                        </div>
                    </details><?php endif; ?>
                </div>

                <button id="theme-toggle" type="button" aria-label="Switch theme" class="public-theme-toggle mmh-theme-switch" data-mmh-theme-toggle aria-pressed="false">
                    <span class="mmh-theme-switch__knob" aria-hidden="true"><svg class="mmh-theme-switch__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g class="mmh-theme-icon-sun" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2.5v2.2M12 19.3v2.2M4.7 4.7l1.55 1.55m11.5 11.5 1.55 1.55M2.5 12h2.2m14.6 0h2.2M4.7 19.3l1.55-1.55m11.5-11.5L19.3 4.7"></path></g><svg class="mmh-theme-icon-moon" x="0" y="0" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true" focusable="false" fill="#193A34"><path d="M6 .278a.77.77 0 0 1 .08.858 7.2 7.2 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277q.792-.001 1.533-.16a.79.79 0 0 1 .81.316.73.73 0 0 1-.031.893A8.35 8.35 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.75.75 0 0 1 6 .278"/><path d="M10.794 3.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387a1.73 1.73 0 0 0-1.097 1.097l-.387 1.162a.217.217 0 0 1-.412 0l-.387-1.162A1.73 1.73 0 0 0 9.31 6.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387a1.73 1.73 0 0 0 1.097-1.097zM13.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.16 1.16 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.16 1.16 0 0 0-.732-.732l-.774-.258a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732z"/></svg></svg></span>
                </button>
                
                <?php if ($publicUserLoggedIn): ?>
                    <details class="public-dropdown public-account-dropdown">
                        <summary class="public-account-toggle public-nav-button secondary"><i class="fas fa-user-circle" aria-hidden="true"></i> <span class="public-account-name"><?=public_nav_html($publicUserName)?></span> <i class="fas fa-chevron-down text-xs" aria-hidden="true"></i></summary>
                        <div class="public-dropdown-menu public-account-menu">
                            <?php foreach ($publicAccountItems as $publicAccountItem): ?>
                                <a class="<?= $publicAccountItem['label'] === 'Logout' ? 'is-logout' : ''; ?>" href="<?=public_nav_html($publicAccountItem['href'])?>"><?=public_nav_html($publicAccountItem['label'])?></a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php else: ?>
                    <div class="public-auth-actions">
                        <a href="<?=public_nav_html($publicBaseUrl)?>/auth/login" class="public-nav-button secondary">Login</a>
                        <a href="<?=public_nav_html($publicBaseUrl)?>/auth/register" class="public-nav-button primary">Register</a>
                    </div>
                <?php endif; ?>
            </nav>
            
            <div class="public-mobile-actions">
                <button id="theme-toggle-mobile" type="button" aria-label="Switch theme" class="public-theme-toggle mmh-theme-switch" data-mmh-theme-toggle aria-pressed="false">
                    <span class="mmh-theme-switch__knob" aria-hidden="true"><svg class="mmh-theme-switch__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g class="mmh-theme-icon-sun" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2.5v2.2M12 19.3v2.2M4.7 4.7l1.55 1.55m11.5 11.5 1.55 1.55M2.5 12h2.2m14.6 0h2.2M4.7 19.3l1.55-1.55m11.5-11.5L19.3 4.7"></path></g><svg class="mmh-theme-icon-moon" x="0" y="0" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true" focusable="false" fill="#193A34"><path d="M6 .278a.77.77 0 0 1 .08.858 7.2 7.2 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277q.792-.001 1.533-.16a.79.79 0 0 1 .81.316.73.73 0 0 1-.031.893A8.35 8.35 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.75.75 0 0 1 6 .278"/><path d="M10.794 3.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387a1.73 1.73 0 0 0-1.097 1.097l-.387 1.162a.217.217 0 0 1-.412 0l-.387-1.162A1.73 1.73 0 0 0 9.31 6.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387a1.73 1.73 0 0 0 1.097-1.097zM13.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.16 1.16 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.16 1.16 0 0 0-.732-.732l-.774-.258a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732z"/></svg></svg></span>
                </button>
                <button id="menu-toggle" type="button" aria-label="Open navigation menu" title="Open navigation menu" class="public-mobile-toggle" aria-controls="mobile-menu" aria-expanded="false">
                    <i class="fas fa-bars ds-icon ds-icon-lg" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
    
    <div id="mobile-menu" class="hidden public-mobile-menu">
        <div class="public-mobile-grid">
            <?php foreach ($publicNavItems as $publicNavItem): ?>
                <a href="<?=public_nav_html($publicNavItem['href'])?>"><?=public_nav_html($publicNavItem['label'])?></a>
            <?php endforeach; ?>
            <?php foreach ($publicMoreItems as $publicMoreItem): ?>
                <a href="<?=public_nav_html($publicMoreItem['href'])?>"><?=public_nav_html($publicMoreItem['label'])?></a>
            <?php endforeach; ?>
            <div class="public-mobile-account-group public-mobile-grid">
                <?php if ($publicUserLoggedIn): ?>
                    <?php foreach ($publicAccountItems as $publicAccountItem): ?>
                        <a class="<?= $publicAccountItem['label'] === 'Logout' ? 'is-logout' : ''; ?>" href="<?=public_nav_html($publicAccountItem['href'])?>"><?=public_nav_html($publicAccountItem['label'])?></a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <a href="<?=public_nav_html($publicBaseUrl)?>/auth/login">Login</a>
                    <a href="<?=public_nav_html($publicBaseUrl)?>/auth/register" class="public-nav-button primary">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>
<?php if ($publicAnnouncement): ?>
  <aside class="public-site-announcement public-site-announcement--<?=public_nav_html($publicAnnouncement['type'])?>" role="status">
    <div class="public-header-shell"><span class="fas fa-bullhorn" aria-hidden="true"></span><p><?=public_nav_html($publicAnnouncement['message'])?></p><?php if ($publicAnnouncement['action_label'] !== '' && $publicAnnouncement['action_url'] !== ''): ?><a href="<?=public_nav_html(str_starts_with($publicAnnouncement['action_url'], '/') ? $publicBaseUrl . $publicAnnouncement['action_url'] : $publicAnnouncement['action_url'])?>"<?=str_starts_with($publicAnnouncement['action_url'], 'https://') ? ' target="_blank" rel="noopener noreferrer"' : ''?>><?=public_nav_html($publicAnnouncement['action_label'])?></a><?php endif; ?></div>
  </aside>
<?php endif; ?>

<script>
  (function() {
    var menu = document.getElementById('mobile-menu');
    var toggle = document.getElementById('menu-toggle');
    function closePublicMobileMenu() {
      if (menu) { menu.classList.add('hidden'); }
      if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
    }
    function togglePublicMobileMenu(event) {
      if (!menu || !toggle) { return; }
      event.preventDefault();
      event.stopImmediatePropagation();
      menu.classList.toggle('hidden');
      toggle.setAttribute('aria-expanded', menu.classList.contains('hidden') ? 'false' : 'true');
    }
    if (toggle) { toggle.addEventListener('click', togglePublicMobileMenu); }
    if (menu) {
      document.addEventListener('click', function(event) {
        if (!menu.classList.contains('hidden') && !menu.contains(event.target) && (!toggle || !toggle.contains(event.target))) {
          closePublicMobileMenu();
        }
      });
      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') { closePublicMobileMenu(); }
      });
      menu.querySelectorAll('a').forEach(function(link) {
        link.addEventListener('click', closePublicMobileMenu);
      });
    }
    var accountMenus = Array.prototype.slice.call(document.querySelectorAll('.public-account-dropdown'));
    function closeAccountMenus(returnFocus) {
      accountMenus.forEach(function(details) {
        if (!details.open) return;
        details.open = false;
        if (returnFocus) {
          var summary = details.querySelector('summary');
          if (summary) summary.focus();
        }
      });
    }
    document.addEventListener('click', function(event) {
      if (!event.target.closest || !event.target.closest('.public-account-dropdown')) closeAccountMenus(false);
    });
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') closeAccountMenus(true);
    });
  })();
</script>
