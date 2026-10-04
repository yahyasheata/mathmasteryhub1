<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$router = file_get_contents($root . '/index.php');
$view = file_get_contents($root . '/views/index.php');
$landingCss = file_get_contents($root . '/resources/css/public-landing.css');
$navigation = file_get_contents($root . '/views/public/layouts/aside.php');
foreach ([$router, $view, $landingCss, $navigation] as $source) {
    if (!is_string($source)) throw new RuntimeException('Unable to inspect public homepage sources.');
}

if (!str_contains($router, "include('views/index.php');")) throw new RuntimeException('The public homepage route is not wired to the expected view.');
if (!str_contains($view, "WHERE courses.course_state = 'public'")) throw new RuntimeException('Homepage Course filtering must remain public-only.');
if (!str_contains($view, "rawurlencode((string) (\$course['id'] ?? ''))")) throw new RuntimeException('Homepage Course detail links changed.');
if (!str_contains($view, "\$homeBasePath . '/category/' . (\$category['category_link'] ?? '')")) throw new RuntimeException('Homepage category links changed.');
if (!str_contains($view, 'mmh_site_settings_valid_local_asset($course[\'course_image\'] ?? \'\')')) throw new RuntimeException('Course artwork must use the existing safe local-asset validation.');
if (!str_contains($view, '(float) $course[\'preDiscount_course_price\'] > (float) ($course[\'course_price\'] ?? 0)')) throw new RuntimeException('Previous-price eligibility changed.');
if (!str_contains($view, 'if ($homeCoursesEnabled) {') || !str_contains($view, 'if ($courseImageUrl !== \'\')')) throw new RuntimeException('Course artwork must remain conditional on visible, valid local imagery.');

foreach (['Student workspace', 'Expanding Expressions', '68%', 'Due soon', 'landing-dashboard-preview', 'landing-course-label'] as $removedMockup) {
    if (str_contains($view, $removedMockup)) throw new RuntimeException('Removed template/mockup content remains: ' . $removedMockup);
}
if (str_contains($view, '$homeSecondaryLabel') || str_contains($view, '$homeSecondaryUrl')) throw new RuntimeException('Hero must have only one primary action.');
if (!str_contains($view, '$hasDistinctFinalCta')) throw new RuntimeException('The repeated final CTA must be shown only for a distinct destination/action.');

$sectionOrder = [
    'id="home"',
    'id="courses"',
    'landing-paths',
    'landing-stats',
    'landing-why',
    'landing-experience',
    'landing-testimonials',
    'landing-faq',
    'landing-final-cta',
];
$lastPosition = -1;
foreach ($sectionOrder as $section) {
    $position = strpos($view, $section);
    if ($position === false || $position <= $lastPosition) throw new RuntimeException('Homepage product section order is missing or incorrect at: ' . $section);
    $lastPosition = $position;
}

foreach (['theme-toggle-mobile', 'menu-toggle', '/auth/login', '/auth/register'] as $navigationContract) {
    if (!str_contains($navigation, $navigationContract)) throw new RuntimeException('Existing public navigation behavior is missing: ' . $navigationContract);
}
if (!str_contains($landingCss, '.landing-course-image img') || !str_contains($landingCss, 'object-fit: cover')) throw new RuntimeException('Course cover cropping styles are missing.');
if (!str_contains($landingCss, 'backdrop-filter: none') || !str_contains($landingCss, 'box-shadow: none')) throw new RuntimeException('Decorative header/CTA effects were not reduced.');
if (!str_contains($landingCss, '.landing-hero-grid.has-course-art')) throw new RuntimeException('Hero art layout is missing.');

echo "Public homepage visual contract passed.\n";
