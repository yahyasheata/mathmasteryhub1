<?php
$publicBaseUrl = rtrim(mmh_site_public_base_path(), '/');
$publicSiteName = $site_name ?? ($site_settings['website_name'] ?? 'Math Mastery Hub');
$publicLogoUrl = mmh_site_settings_asset_url($site_settings, 'website_logo', 'resources/images/default/wide-logo.png');
$publicDarkLogoUrl = mmh_site_public_url('resources/images/branding/mathhub-logo-white.png');
$publicUserLoggedIn = !empty($_SESSION['username']);
$publicUserName = 'Account';
$publicUserIsEnrolled = false;

if ($publicUserLoggedIn && function_exists('db')) {
    try {
        $publicConn = db();
        if (function_exists('getUserData')) {
            $publicUserData = getUserData($_SESSION['username']);
            $publicNameCandidate = trim((string)($publicUserData['full_name'] ?? ''));
            if ($publicNameCandidate !== '') { $publicUserName = $publicNameCandidate; }
        }
        if (function_exists('getUserInfo')) {
            $publicInfo = getUserInfo($_SESSION['username']);
            $publicUserId = (int)($publicInfo->user_id ?? 0);
            if ($publicUserId > 0) {
                $publicEnrollStmt = $publicConn->prepare('SELECT COUNT(*) AS total FROM course_logs WHERE user_id = ?');
                if ($publicEnrollStmt) {
                    $publicEnrollStmt->bind_param('i', $publicUserId);
                    $publicEnrollStmt->execute();
                    $publicEnrollRow = $publicEnrollStmt->get_result()->fetch_assoc();
                    $publicUserIsEnrolled = (int)($publicEnrollRow['total'] ?? 0) > 0;
                    $publicEnrollStmt->close();
                }
            }
        }
    } catch (Throwable $publicNavError) {
        $publicUserName = 'Account';
        $publicUserIsEnrolled = false;
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
$publicAccountItems = $publicUserIsEnrolled ? [
    ['label' => 'My Courses', 'href' => $publicBaseUrl . '/user/my-courses'],
    ['label' => 'My Progress', 'href' => $publicBaseUrl . '/user/analytics'],
    ['label' => 'Assignments', 'href' => $publicBaseUrl . '/user/assignments'],
    ['label' => 'Live Sessions', 'href' => $publicBaseUrl . '/user/live-sessions'],
    ['label' => 'Notifications', 'href' => $publicBaseUrl . '/user/notifications'],
    ['label' => 'Settings', 'href' => $publicBaseUrl . '/user/settings'],
    ['label' => 'Logout', 'href' => $publicBaseUrl . '/user/logout'],
] : [
    ['label' => 'Browse Courses', 'href' => $publicBaseUrl . '/courses'],
    ['label' => 'Past Papers', 'href' => $publicBaseUrl . '/past-papers'],
    ['label' => 'Account Settings', 'href' => $publicBaseUrl . '/user/settings'],
    ['label' => 'Logout', 'href' => $publicBaseUrl . '/user/logout'],
];
function public_nav_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<script>
  (function() {
    var root = document.documentElement;
    function storedTheme() {
      try {
        return localStorage.getItem('theme') || localStorage.getItem('math-mastery-student-theme');
      } catch (error) {
        return null;
      }
    }
    function preferredTheme() {
      var savedTheme = storedTheme();
      if (savedTheme === 'light' || savedTheme === 'dark') { return savedTheme; }
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function applyTheme(theme, persist) {
      var normalized = theme === 'dark' ? 'dark' : 'light';
      root.classList.toggle('dark', normalized === 'dark');
      root.dataset.studentTheme = normalized;
      root.style.colorScheme = normalized;
      if (persist) {
        try {
          localStorage.setItem('theme', normalized);
          localStorage.setItem('math-mastery-student-theme', normalized);
        } catch (error) {}
      }
    }
    window.mmhApplyTheme = applyTheme;
    window.mmhCurrentTheme = function() { return root.classList.contains('dark') ? 'dark' : 'light'; };
    applyTheme(preferredTheme(), false);
  })();
</script>
<style>
  .public-site-header {
    position: sticky;
    top: 0;
    z-index: 50;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    backdrop-filter: saturate(140%) blur(16px);
    transition: background var(--transition-fast), border-color var(--transition-fast), box-shadow var(--transition-fast);
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
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    text-decoration: none;
    white-space: nowrap;
  }
  .public-dropdown-menu a:hover {
    color: var(--text-primary);
    background: var(--surface-hover);
  }
  .public-auth-actions {
    display: flex;
    align-items: center;
    gap: .65rem;
  }
  .public-nav-button {
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    border-radius: var(--radius-md);
    padding: .55rem .95rem;
    font-weight: 800;
    text-decoration: none;
    border: 1px solid var(--border);
  }
  .public-nav-button.primary {
    background: var(--primary);
    color: var(--text-inverse);
    border-color: var(--primary);
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
  .public-mobile-toggle,
  .public-theme-toggle {
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
  .public-mobile-toggle:hover,
  .public-theme-toggle:hover {
    color: var(--primary);
    background: var(--surface-hover);
  }
  .public-theme-icon--dark { display: none; }
  .dark .public-theme-icon--light { display: none; }
  .dark .public-theme-icon--dark { display: inline-flex; }
  .public-mobile-menu {
    display: none;
    position: absolute;
    left: 0;
    right: 0;
    top: 100%;
    z-index: 55;
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
    .public-nav-button { padding-inline: .75rem; }
  }
  @media (max-width: 1023px) {
    .public-site-header { position: sticky; }
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
<header class="public-site-header">
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

                <button id="theme-toggle" type="button" aria-label="Toggle theme" title="Toggle theme" class="public-theme-toggle">
                    <i class="fas fa-moon public-theme-icon--light" aria-hidden="true"></i>
                    <i class="fas fa-sun public-theme-icon--dark" aria-hidden="true"></i>
                </button>
                
                <?php if ($publicUserLoggedIn): ?>
                    <details class="public-dropdown">
                        <summary class="public-account-toggle public-nav-button secondary"><i class="fas fa-user-circle" aria-hidden="true"></i> <?=public_nav_html($publicUserName)?> <i class="fas fa-chevron-down text-xs" aria-hidden="true"></i></summary>
                        <div class="public-dropdown-menu">
                            <?php foreach ($publicAccountItems as $publicAccountItem): ?>
                                <a href="<?=public_nav_html($publicAccountItem['href'])?>"><?=public_nav_html($publicAccountItem['label'])?></a>
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
                <button id="theme-toggle-mobile" type="button" aria-label="Toggle theme" title="Toggle theme" class="public-theme-toggle">
                    <i class="fas fa-moon public-theme-icon--light" aria-hidden="true"></i>
                    <i class="fas fa-sun public-theme-icon--dark" aria-hidden="true"></i>
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
                        <a href="<?=public_nav_html($publicAccountItem['href'])?>"><?=public_nav_html($publicAccountItem['label'])?></a>
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
    var themeToggle = document.getElementById('theme-toggle');
    var themeToggleMobile = document.getElementById('theme-toggle-mobile');
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
    function toggleSharedTheme(event) {
      if (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
      if (typeof window.mmhApplyTheme === 'function') {
        window.mmhApplyTheme(document.documentElement.classList.contains('dark') ? 'light' : 'dark', true);
      }
    }
    if (toggle) { toggle.addEventListener('click', togglePublicMobileMenu); }
    if (themeToggle) { themeToggle.addEventListener('click', toggleSharedTheme); }
    if (themeToggleMobile) { themeToggleMobile.addEventListener('click', toggleSharedTheme); }
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
  })();
</script>
