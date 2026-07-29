<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$site_settings = getSiteSettings();
$site_name = $site_settings['website_name'] ?? 'Math Mastery Hub';
$resourceBaseUrl = rtrim((string)$baseUrl, '/');
function resource_page_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$resourcePageCards = $resourcePageCards ?? [];
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?=resource_page_html($resourcePageTitle ?? 'Resources')?> | <?=resource_page_html($site_name)?></title>
    <link rel="stylesheet" href="<?=resource_page_html($resourceBaseUrl)?>/resources/css/design-system.css" data-design-system="mathhub">
    <link rel="stylesheet" href="<?=resource_page_html($resourceBaseUrl)?>/resources/build/assets/app-38448552.css">
    <link rel="stylesheet" href="<?=resource_page_html($resourceBaseUrl)?>/resources/css/fontawsome5.min.css">
    <link rel="stylesheet" href="<?=resource_page_html($resourceBaseUrl)?>/resources/css/public-resources.css">
</head>
<body class="ds-bg-primary ds-text-primary public-resource-page">
<?php include 'views/public/layouts/aside.php'; ?>
<main class="public-resource-main">
    <section class="public-resource-hero">
        <div class="public-resource-shell public-resource-hero-grid">
            <div>
                <span class="public-resource-kicker"><?=resource_page_html($resourcePageKicker ?? 'Free Learning Center')?></span>
                <h1><?=resource_page_html($resourcePageTitle ?? 'Learning Resources')?></h1>
                <p><?=resource_page_html($resourcePageDescription ?? 'This public learning area is being prepared inside the Math Mastery Hub ecosystem.')?></p>
                <div class="public-resource-actions">
                    <a class="public-resource-btn primary" href="<?=resource_page_html($resourceBaseUrl)?>/courses">Browse Courses</a>
                    <a class="public-resource-btn secondary" href="<?=resource_page_html($resourceBaseUrl)?>/past-papers">Open Past Papers</a>
                </div>
            </div>
            <aside class="public-resource-preview-card">
                <span class="<?=resource_page_html($resourcePageIcon ?? 'fas fa-layer-group')?>" aria-hidden="true"></span>
                <strong><?=resource_page_html($resourcePageStatusTitle ?? 'Resource area prepared')?></strong>
                <p><?=resource_page_html($resourcePageStatusText ?? 'New public learning resources will appear here once published.')?></p>
            </aside>
        </div>
    </section>
    <section class="public-resource-shell public-resource-section">
        <div class="public-resource-section-heading">
            <span>Learning pathways</span>
            <h2><?=resource_page_html($resourcePageSectionTitle ?? 'What will live here')?></h2>
        </div>
        <div class="public-resource-grid">
            <?php foreach ($resourcePageCards as $card): ?>
                <article class="public-resource-card">
                    <span class="<?=resource_page_html($card['icon'] ?? 'fas fa-book-open')?>" aria-hidden="true"></span>
                    <strong><?=resource_page_html($card['title'] ?? '')?></strong>
                    <p><?=resource_page_html($card['text'] ?? '')?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php include 'views/public/layouts/footer.php'; ?>
</body>
</html>
