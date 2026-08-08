<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assets = [
    'favicon.svg',
    'favicon-96x96.png',
    'favicon.ico',
    'apple-touch-icon.png',
    'web-app-manifest-192x192.png',
    'web-app-manifest-512x512.png',
    'site.webmanifest',
];
foreach ($assets as $asset) {
    $path = $root . DIRECTORY_SEPARATOR . $asset;
    $assert(is_file($path) && filesize($path) > 0, "Missing or empty {$asset}");
}

$manifest = json_decode((string) file_get_contents($root . '/site.webmanifest'), true);
$assert(is_array($manifest), 'site.webmanifest is not valid JSON');
$manifestSources = array_map(
    static fn(array $icon): string => (string) ($icon['src'] ?? ''),
    is_array($manifest['icons'] ?? null) ? $manifest['icons'] : []
);
$assert(in_array('/web-app-manifest-192x192.png', $manifestSources, true), 'Manifest is missing the 192px icon');
$assert(in_array('/web-app-manifest-512x512.png', $manifestSources, true), 'Manifest is missing the 512px icon');

$partial = (string) file_get_contents($root . '/views/partials/favicon.php');
$requiredTags = [
    '<link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />',
    '<link rel="icon" type="image/svg+xml" href="/favicon.svg" />',
    '<link rel="shortcut icon" href="/favicon.ico" />',
    '<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />',
    '<meta name="apple-mobile-web-app-title" content="Math Mastery Hub" />',
    '<link rel="manifest" href="/site.webmanifest" />',
];
foreach ($requiredTags as $tag) {
    $assert(substr_count($partial, $tag) === 1, "Favicon partial does not contain exactly one {$tag}");
}

$canonicalHeads = [
    'views/index.php',
    'views/layouts/header.php',
    'views/public/layouts/header.php',
    'views/public/blog.php',
    'views/public/category.php',
    'views/public/checkout.php',
    'views/public/contact.php',
    'views/public/course.php',
    'views/public/courses.php',
    'views/public/free-learning.php',
    'views/public/past-papers.php',
    'views/public/maintenance.php',
    'views/auth/login.php',
    'views/auth/register.php',
    'views/user/layouts/user/header.php',
    'views/admin/layouts/admin/header.php',
    'views/404.php',
];
foreach ($canonicalHeads as $relativePath) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    $assert(str_contains($source, "partials/favicon.php"), "{$relativePath} does not include the canonical favicon partial");
    $assert(!str_contains($source, 'resources/manifest.json'), "{$relativePath} still references the legacy manifest");
    $assert(!preg_match('/<link[^>]+rel=["\'](?:icon|manifest|shortcut icon)["\']/i', $source), "{$relativePath} contains a duplicate direct icon declaration");
}

fwrite(STDOUT, "PASS: favicon installation assets, manifest, and canonical heads\n");
