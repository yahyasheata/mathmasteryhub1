<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tokens = file_get_contents($root . '/resources/css/design-system.css');
$themeScript = file_get_contents($root . '/resources/js/theme-preference.js');
$carouselScript = file_get_contents($root . '/resources/js/landing-course-carousel.js');
$landingView = file_get_contents($root . '/views/index.php');
$adminHeader = file_get_contents($root . '/views/admin/layouts/admin/header.php');
$publicHeader = file_get_contents($root . '/views/layouts/header.php');
$publicShellHeader = file_get_contents($root . '/views/public/layouts/header.php');
$studentHeader = file_get_contents($root . '/views/user/layouts/user/header.php');
$publicNavigation = file_get_contents($root . '/views/public/layouts/aside.php');
$authLogin = file_get_contents($root . '/views/auth/login.php');
$authRegister = file_get_contents($root . '/views/auth/register.php');
$adminTopNavigation = file_get_contents($root . '/views/admin/layouts/admin/top-nav.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    '--bg-primary:', '--surface:', '--surface-elevated:', '--surface-muted:', '--surface-inset:',
    '--text-primary:', '--text-secondary:', '--text-muted:', '--border:', '--border-strong:',
    '--primary-action:', '--primary-foreground:', '--secondary-action:', '--secondary-foreground:',
    '--disabled-bg:', '--disabled-text:', '--success-soft:', '--warning-soft:', '--danger-soft:', '--info-soft:',
] as $token) {
    $assert(str_contains((string) $tokens, $token), 'Shared theme token is missing: ' . $token);
}

$assert(preg_match('/html\.dark,[\s\S]*?--bg-primary:\s*#111C1B;[\s\S]*?--surface:\s*#152321;[\s\S]*?--text-primary:\s*#E9ECE5;/i', (string) $tokens) === 1, 'Dark theme does not define the approved deep-teal surface and warm text hierarchy.');
$assert(str_contains((string) $tokens, '--radius-sm: 8px;') && str_contains((string) $tokens, '--radius-lg: 20px;'), 'Shared corner radii do not match the approved modestly rounded scale.');
$assert(str_contains((string) $tokens, '.mmh-theme-switch__knob') && str_contains((string) $tokens, '.dark .mmh-theme-switch__knob'), 'Shared light/dark capsule switch styles are missing.');
$assert(str_contains((string) $themeScript, "var canonicalKey = 'theme';") && str_contains((string) $themeScript, 'data-mmh-theme-toggle') && str_contains((string) $themeScript, "theme === 'dark' ? 'fa-moon' : 'fa-sun'"), 'Shared theme preference storage or single-icon state handling is missing.');
$assert(!str_contains((string) $tokens, '#theme-toggle,') && !str_contains((string) $tokens, '#theme-toggle-mobile'), 'Legacy ID-based theme styles must not override the shared capsule control.');
foreach ([$publicNavigation, $authLogin, $authRegister, $adminTopNavigation] as $switchPage) {
    $assert(!preg_match('/<span class="mmh-theme-switch__knob"[^>]*>\s*<i[^>]*><\/i>\s*<i[^>]*><\/i>/', (string) $switchPage), 'A shared theme switch must contain only one active icon node.');
}
$assert(str_contains((string) file_get_contents($root . '/resources/css/auth-pages.css'), 'max-width: 46rem;'), 'Auth card width should stay proportionate to the shared content scale.');
$assert(str_contains((string) $publicNavigation, 'width: 100%;') && str_contains((string) $publicNavigation, 'border-radius: 0;'), 'Public header must remain a full-width square-cornered bar.');
$assert(str_contains((string) file_get_contents($root . '/resources/css/admin-shell.css'), 'border-radius:0 !important;'), 'Admin top navigation must remain square-cornered.');
$assert(str_contains((string) $landingView, 'role="region" aria-roledescription="carousel"') && str_contains((string) $landingView, '$carouselCourses = $homeCourses;'), 'Homepage course carousel must expose accessible controls for the full existing public course list.');
$assert(str_contains((string) $carouselScript, "'(prefers-reduced-motion: reduce)'" ) && str_contains((string) $carouselScript, "'mouseenter'") && str_contains((string) $carouselScript, "'focusin'"), 'Course carousel must respect reduced motion and pause during hover or keyboard focus.');
$assert(preg_match('/\.btn-primary,[\s\S]*?background:\s*var\(--primary-action\)[\s\S]*?color:\s*var\(--primary-foreground\)/', (string) $tokens) === 1, 'Primary buttons must pair the brand action fill with its readable foreground.');
$assert(str_contains((string) $tokens, '.alert-success { background: var(--success-soft); color: var(--success);') && str_contains((string) $tokens, '.modal-content {'), 'Shared alerts or modal surface styles are missing.');
$assert(!str_contains((string) $adminHeader, '--bg-primary: #0f1718'), 'Admin shell still overrides the canonical dark surface palette.');
$assert(substr_count((string) $publicHeader, '--main-color-rgb: var(--primary-rgb);') === 2, 'Public compatibility colors do not follow the theme token.');
$assert(substr_count((string) $publicShellHeader, '--main-color-rgb: var(--primary-rgb);') === 2, 'Public shell compatibility colors do not follow the theme token.');
$assert(substr_count((string) $studentHeader, '--main-color-rgb: var(--primary-rgb);') === 2, 'Student compatibility colors do not follow the theme token.');

echo "Shared theme color contract checks passed.\n";
