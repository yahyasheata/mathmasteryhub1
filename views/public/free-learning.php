<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/FreeResources.php';

$site_settings = getSiteSettings();
$site_name = $site_settings['website_name'] ?? 'Math Mastery Hub';
$freeLearningBaseUrl = rtrim((string) ($baseUrl ?? ''), '/');
$freeLearningConn = db();
$freeLearningRouteBase = $freeLearningBaseUrl . '/free-learning';
$freeLearningMode = $freeLearningMode ?? 'home';
$freeLearningCollectionSlug = $freeLearningCollectionSlug ?? '';
$freeLearningResourceId = $freeLearningResourceId ?? '';

if (!function_exists('free_learning_html')) {
    function free_learning_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('free_learning_url')) {
    function free_learning_url($base, array $query = []) {
        $query = array_filter($query, static function ($value) { return $value !== '' && $value !== null; });
        return $base . ($query ? '?' . http_build_query($query) : '');
    }
}
if (!function_exists('free_learning_thumbnail')) {
    function free_learning_thumbnail($path, $base) {
        $path = trim((string) $path);
        if ($path === '' || str_contains($path, '..')) { return ''; }
        if (preg_match('#^https://#i', $path)) { return $path; }
        return $base . '/' . ltrim($path, '/');
    }
}

$freeLearningTypes = [
    'youtube_video' => ['label' => 'Video', 'icon' => 'fas fa-play-circle'],
    'free_notes' => ['label' => 'Notes', 'icon' => 'fas fa-sticky-note'],
    'worksheet' => ['label' => 'Worksheet', 'icon' => 'fas fa-file-alt'],
    'classified_worksheet' => ['label' => 'Classified Worksheet', 'icon' => 'fas fa-layer-group'],
    'revision_guide' => ['label' => 'Revision Guide', 'icon' => 'fas fa-book-open'],
    'model_answer' => ['label' => 'Model Answer', 'icon' => 'fas fa-check-circle'],
    'external_resource' => ['label' => 'External Resource', 'icon' => 'fas fa-external-link-alt'],
    'custom_resource' => ['label' => 'Resource', 'icon' => 'fas fa-shapes'],
];
$freeLearningType = trim((string) ($_GET['type'] ?? ($freeLearningPresetType ?? '')));
if (!array_key_exists($freeLearningType, $freeLearningTypes)) { $freeLearningType = ''; }
$freeLearningSearch = mmh_free_clean($_GET['q'] ?? '', 120);
$freeLearningSort = strtolower(trim((string) ($_GET['sort'] ?? 'newest')));
if (!in_array($freeLearningSort, ['newest', 'az', 'featured'], true)) { $freeLearningSort = 'newest'; }
$freeLearningPage = max(1, (int) ($_GET['page'] ?? 1));
$freeLearningPerPage = 12;
$freeLearningCollections = mmh_free_public_collections($freeLearningConn);
$freeLearningCollection = null;
$freeLearningResource = null;
$freeLearningRelated = [];
$freeLearningResources = [];
$freeLearningTotal = 0;
$freeLearningAccessMessage = '';

if ($freeLearningMode === 'collection') {
    $freeLearningCollection = mmh_free_collection($freeLearningConn, $freeLearningCollectionSlug);
    if (!$freeLearningCollection || ($freeLearningCollection['status'] ?? '') !== 'published') {
        http_response_code(404);
        $freeLearningMode = 'missing';
    }
}

if ($freeLearningMode === 'resource') {
    $freeLearningResource = mmh_free_resource($freeLearningConn, $freeLearningResourceId);
    if (!$freeLearningResource) {
        http_response_code(404);
        $freeLearningMode = 'missing';
    } else {
        [$freeLearningAllowed, $freeLearningAccessMessage] = mmh_free_can_access_resource($freeLearningConn, $freeLearningResource);
        if (!$freeLearningAllowed) {
            http_response_code(empty($_SESSION['username']) && empty($_SESSION['admin']) ? 401 : 403);
            $freeLearningMode = 'restricted';
        } else {
            foreach (mmh_free_related_resources($freeLearningConn, $freeLearningResource['resource_id'], 6) as $related) {
                [$relatedAllowed] = mmh_free_can_access_resource($freeLearningConn, $related);
                if ($relatedAllowed) { $freeLearningRelated[] = $related; }
            }
        }
    }
}

if ($freeLearningMode === 'browse' || $freeLearningMode === 'collection') {
    $freeLearningFilters = [
        'search' => $freeLearningSearch,
        'resource_type' => $freeLearningType,
        'sort' => $freeLearningSort,
    ];
    if ($freeLearningMode === 'collection') { $freeLearningFilters['collection_id'] = $freeLearningCollection['collection_id']; }
    elseif (!empty($_GET['collection'])) {
        $requestedCollection = mmh_free_collection($freeLearningConn, $_GET['collection']);
        if ($requestedCollection && ($requestedCollection['status'] ?? '') === 'published') {
            $freeLearningFilters['collection_id'] = $requestedCollection['collection_id'];
        }
    }
    if (!empty($_GET['featured'])) { $freeLearningFilters['featured'] = '1'; }
    $freeLearningTotal = mmh_free_count_resources($freeLearningConn, $freeLearningFilters);
    $freeLearningResources = mmh_free_list_resources($freeLearningConn, $freeLearningFilters, $freeLearningPerPage, ($freeLearningPage - 1) * $freeLearningPerPage);
}

$freeLearningFeatured = $freeLearningMode === 'home' ? mmh_free_featured_resources($freeLearningConn, 6) : [];
$freeLearningLatest = $freeLearningMode === 'home' ? mmh_free_latest_resources($freeLearningConn, 6) : [];
$freeLearningRecent = [];
if ($freeLearningMode === 'home' && function_exists('mmh_free_current_student_id')) {
    $freeLearningStudentId = mmh_free_current_student_id($freeLearningConn);
    if ($freeLearningStudentId) { $freeLearningRecent = mmh_free_recently_opened_resources($freeLearningConn, $freeLearningStudentId, 4); }
}

$freeLearningTitle = 'Free Learning Center';
if ($freeLearningMode === 'collection') { $freeLearningTitle = $freeLearningCollection['title']; }
elseif ($freeLearningMode === 'resource' && $freeLearningResource) { $freeLearningTitle = $freeLearningResource['title']; }
elseif ($freeLearningMode === 'browse') { $freeLearningTitle = 'Browse Free Resources'; }
elseif ($freeLearningMode === 'missing') { $freeLearningTitle = 'Resource not found'; }
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<?php include __DIR__ . '/../partials/favicon.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= free_learning_html($freeLearningTitle) ?> | <?= free_learning_html($site_name) ?></title>
    <link rel="stylesheet" href="<?= free_learning_html($freeLearningBaseUrl) ?>/resources/css/design-system.css" data-design-system="mathhub">
    <link rel="stylesheet" href="<?= free_learning_html($freeLearningBaseUrl) ?>/resources/build/assets/app-38448552.css">
    <link rel="stylesheet" href="<?= free_learning_html($freeLearningBaseUrl) ?>/resources/css/fontawsome5.min.css">
    <link rel="stylesheet" href="<?= free_learning_html($freeLearningBaseUrl) ?>/resources/css/public-resources.css">
</head>
<body class="ds-bg-primary ds-text-primary free-learning-page">
<?php include 'views/public/layouts/aside.php'; ?>
<main class="free-learning-main">
<?php if ($freeLearningMode === 'missing' || $freeLearningMode === 'restricted'): ?>
    <section class="free-learning-shell free-learning-state">
        <span class="free-learning-state-icon <?= $freeLearningMode === 'restricted' ? 'fas fa-lock' : 'fas fa-search' ?>" aria-hidden="true"></span>
        <p class="free-learning-eyebrow">Free Learning Center</p>
        <h1><?= $freeLearningMode === 'restricted' ? 'This resource is not available to your account.' : 'We could not find that resource.' ?></h1>
        <p><?= free_learning_html($freeLearningMode === 'restricted' ? $freeLearningAccessMessage : 'It may have been moved, unpublished, or removed.') ?></p>
        <a class="free-learning-button primary" href="<?= free_learning_html($freeLearningRouteBase) ?>">Return to Free Learning</a>
    </section>
<?php elseif ($freeLearningMode === 'resource' && $freeLearningResource): ?>
    <?php
    $resourceType = $freeLearningTypes[$freeLearningResource['resource_type']] ?? $freeLearningTypes['custom_resource'];
    $resourceCollections = mmh_free_resource_collections($freeLearningConn, $freeLearningResource['resource_id']);
    $protectedOpen = $freeLearningBaseUrl . '/resources/open/' . rawurlencode($freeLearningResource['resource_id']);
    $resourceThumb = free_learning_thumbnail($freeLearningResource['thumbnail_path'] ?? '', $freeLearningBaseUrl);
    $isYouTube = ($freeLearningResource['storage_type'] ?? '') === 'youtube' && preg_match('/^[A-Za-z0-9_-]{6,80}$/', (string) ($freeLearningResource['youtube_video_id'] ?? ''));
    $isPreviewableFile = ($freeLearningResource['storage_type'] ?? '') === 'file' && (int) ($freeLearningResource['preview_allowed'] ?? 0) === 1;
    ?>
    <section class="free-learning-resource-hero">
        <div class="free-learning-shell">
            <nav class="free-learning-breadcrumb" aria-label="Breadcrumb"><a href="<?= free_learning_html($freeLearningRouteBase) ?>">Free Learning</a><span>/</span><?php if ($resourceCollections): ?><a href="<?= free_learning_html($freeLearningRouteBase . '/collection/' . rawurlencode($resourceCollections[0]['slug'])) ?>"><?= free_learning_html($resourceCollections[0]['title']) ?></a><span>/</span><?php endif; ?><span><?= free_learning_html($freeLearningResource['title']) ?></span></nav>
            <div class="free-learning-resource-heading">
                <?php if ($resourceThumb): ?><img src="<?= free_learning_html($resourceThumb) ?>" alt="" loading="lazy"><?php else: ?><span class="free-learning-resource-icon <?= free_learning_html($resourceType['icon']) ?>" aria-hidden="true"></span><?php endif; ?>
                <div><span class="free-learning-type-badge type-<?= free_learning_html($freeLearningResource['resource_type']) ?>"><i class="<?= free_learning_html($resourceType['icon']) ?>" aria-hidden="true"></i><?= free_learning_html($resourceType['label']) ?></span><h1><?= free_learning_html($freeLearningResource['title']) ?></h1><?php if (!empty($freeLearningResource['short_description'])): ?><p><?= free_learning_html($freeLearningResource['short_description']) ?></p><?php endif; ?></div>
            </div>
        </div>
    </section>
    <section class="free-learning-shell free-learning-resource-layout">
        <article class="free-learning-resource-content">
            <?php if ($isYouTube): ?><div class="free-learning-embed free-learning-video"><iframe src="https://www.youtube-nocookie.com/embed/<?= free_learning_html($freeLearningResource['youtube_video_id']) ?>" title="<?= free_learning_html($freeLearningResource['title']) ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>
            <?php elseif ($isPreviewableFile && ($freeLearningResource['file_mime'] ?? '') === 'application/pdf'): ?><div class="free-learning-embed free-learning-pdf"><iframe src="<?= free_learning_html($protectedOpen) ?>" title="<?= free_learning_html($freeLearningResource['title']) ?>" loading="lazy"></iframe></div>
            <?php elseif ($isPreviewableFile && str_starts_with((string) ($freeLearningResource['file_mime'] ?? ''), 'image/')): ?><div class="free-learning-image-preview"><img src="<?= free_learning_html($protectedOpen) ?>" alt="<?= free_learning_html($freeLearningResource['title']) ?>" loading="lazy"></div><?php endif; ?>
            <?php if (!empty($freeLearningResource['full_description'])): ?><div class="free-learning-description"><?= nl2br(free_learning_html($freeLearningResource['full_description'])) ?></div><?php endif; ?>
        </article>
        <aside class="free-learning-resource-aside">
            <div class="free-learning-resource-actions">
                <a class="free-learning-button primary" href="<?= free_learning_html($protectedOpen) ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt" aria-hidden="true"></i>Open Resource</a>
                <?php if (($freeLearningResource['storage_type'] ?? '') === 'file' && (int) ($freeLearningResource['download_allowed'] ?? 0) === 1): ?><a class="free-learning-button secondary" href="<?= free_learning_html($protectedOpen . '?download=1') ?>"><i class="fas fa-download" aria-hidden="true"></i>Download</a><?php endif; ?>
                <button class="free-learning-button ghost" type="button" data-share-url="<?= free_learning_html($freeLearningRouteBase . '/resource/' . rawurlencode($freeLearningResource['resource_id'])) ?>"><i class="fas fa-share-alt" aria-hidden="true"></i>Share</button>
            </div>
            <dl class="free-learning-meta">
                <?php if ($resourceCollections): ?><div><dt>Collection</dt><dd><?= free_learning_html(implode(', ', array_column($resourceCollections, 'title'))) ?></dd></div><?php endif; ?>
                <?php if (!empty($freeLearningResource['estimated_duration'])): ?><div><dt>Study time</dt><dd><?= (int) $freeLearningResource['estimated_duration'] ?> min</dd></div><?php endif; ?>
                <?php if (!empty($freeLearningResource['difficulty'])): ?><div><dt>Difficulty</dt><dd><?= free_learning_html($freeLearningResource['difficulty']) ?></dd></div><?php endif; ?>
                <?php if (!empty($freeLearningResource['primary_topic'])): ?><div><dt>Topic</dt><dd><?= free_learning_html($freeLearningResource['primary_topic']) ?></dd></div><?php endif; ?>
            </dl>
        </aside>
    </section>
    <?php if ($freeLearningRelated): ?><section class="free-learning-shell free-learning-section"><div class="free-learning-section-heading"><p class="free-learning-eyebrow">Keep learning</p><h2>Related resources</h2></div><div class="free-learning-resource-grid"><?php foreach ($freeLearningRelated as $related): $relatedType = $freeLearningTypes[$related['resource_type']] ?? $freeLearningTypes['custom_resource']; ?><a class="free-learning-resource-card compact" href="<?= free_learning_html($freeLearningRouteBase . '/resource/' . rawurlencode($related['resource_id'])) ?>"><span class="free-learning-card-icon <?= free_learning_html($relatedType['icon']) ?>" aria-hidden="true"></span><div><small><?= free_learning_html(ucwords(str_replace('_', ' ', $related['relation_type']))) ?></small><strong><?= free_learning_html($related['title']) ?></strong><span><?= free_learning_html($relatedType['label']) ?></span></div></a><?php endforeach; ?></div></section><?php endif; ?>
<?php elseif ($freeLearningMode === 'home'): ?>
    <section class="free-learning-hero"><div class="free-learning-shell free-learning-hero-grid"><div><p class="free-learning-eyebrow">Free Learning Center</p><h1>Build stronger maths habits, one resource at a time.</h1><p>Free videos, worksheets, notes, model answers and revision resources—organised for focused study.</p><div class="free-learning-actions"><a class="free-learning-button primary" href="<?= free_learning_html(free_learning_url($freeLearningRouteBase, ['browse' => 1])) ?>">Browse Resources</a><a class="free-learning-button secondary" href="#collections">Featured Collections</a></div></div><aside class="free-learning-hero-note"><i class="fas fa-compass" aria-hidden="true"></i><strong>Start where you need help.</strong><span>Browse by topic, choose a resource type, and study at your own pace.</span></aside></div></section>
    <section class="free-learning-shell free-learning-section"><?php if ($freeLearningRecent): ?><div class="free-learning-section-heading split"><div><p class="free-learning-eyebrow">Your activity</p><h2>Continue learning</h2></div><a href="<?= free_learning_html(free_learning_url($freeLearningRouteBase, ['browse' => 1])) ?>">Browse all</a></div><div class="free-learning-resource-grid"><?php foreach ($freeLearningRecent as $resource): $type = $freeLearningTypes[$resource['resource_type']] ?? $freeLearningTypes['custom_resource']; ?><a class="free-learning-resource-card compact" href="<?= free_learning_html($freeLearningRouteBase . '/resource/' . rawurlencode($resource['resource_id'])) ?>"><span class="free-learning-card-icon <?= free_learning_html($type['icon']) ?>" aria-hidden="true"></span><div><small><?= free_learning_html($type['label']) ?></small><strong><?= free_learning_html($resource['title']) ?></strong></div></a><?php endforeach; ?></div><?php endif; ?></section>
    <section class="free-learning-shell free-learning-section"><div class="free-learning-section-heading split"><div><p class="free-learning-eyebrow">Selected for you</p><h2>Featured resources</h2></div><a href="<?= free_learning_html(free_learning_url($freeLearningRouteBase, ['browse' => 1, 'featured' => 1, 'sort' => 'featured'])) ?>">View featured</a></div><?php if ($freeLearningFeatured): ?><div class="free-learning-resource-grid"><?php foreach ($freeLearningFeatured as $resource): include 'views/public/partials/free-learning-resource-card.php'; endforeach; ?></div><?php else: ?><div class="free-learning-empty"><i class="fas fa-star" aria-hidden="true"></i><div><strong>Featured resources will appear here.</strong><p>Published featured content is shown automatically.</p></div></div><?php endif; ?></section>
    <section class="free-learning-shell free-learning-section" id="collections"><div class="free-learning-section-heading split"><div><p class="free-learning-eyebrow">Browse by topic</p><h2>Collections</h2></div><a href="<?= free_learning_html(free_learning_url($freeLearningRouteBase, ['browse' => 1])) ?>">Browse every resource</a></div><?php if ($freeLearningCollections): ?><div class="free-learning-collection-grid"><?php foreach ($freeLearningCollections as $collection): $collectionThumb = free_learning_thumbnail($collection['thumbnail_path'] ?? '', $freeLearningBaseUrl); ?><a class="free-learning-collection-card" href="<?= free_learning_html($freeLearningRouteBase . '/collection/' . rawurlencode($collection['slug'])) ?>"><?php if ($collectionThumb): ?><img src="<?= free_learning_html($collectionThumb) ?>" alt="" loading="lazy"><?php else: ?><span class="free-learning-collection-icon fas fa-folder-open" aria-hidden="true"></span><?php endif; ?><div><h3><?= free_learning_html($collection['title']) ?></h3><p><?= free_learning_html($collection['description'] ?: 'Explore resources in this collection.') ?></p><small><?= (int) $collection['resource_count'] ?> resources</small></div></a><?php endforeach; ?></div><?php else: ?><div class="free-learning-empty"><i class="fas fa-folder-open" aria-hidden="true"></i><div><strong>Collections are ready for your first resources.</strong><p>Create and publish a collection in the existing Admin Free Learning module.</p></div></div><?php endif; ?></section>
    <section class="free-learning-shell free-learning-section"><div class="free-learning-section-heading split"><div><p class="free-learning-eyebrow">Recently added</p><h2>Latest resources</h2></div><a href="<?= free_learning_html(free_learning_url($freeLearningRouteBase, ['browse' => 1, 'sort' => 'newest'])) ?>">See all</a></div><?php if ($freeLearningLatest): ?><div class="free-learning-resource-grid"><?php foreach ($freeLearningLatest as $resource): include 'views/public/partials/free-learning-resource-card.php'; endforeach; ?></div><?php else: ?><div class="free-learning-empty"><i class="fas fa-book-reader" aria-hidden="true"></i><div><strong>No resources have been published yet.</strong><p>New free resources will appear here as soon as they are published.</p></div></div><?php endif; ?></section>
<?php else: ?>
    <?php $filterCollection = !empty($freeLearningFilters['collection_id']) ? mmh_free_collection($freeLearningConn, $freeLearningFilters['collection_id']) : null; ?>
    <section class="free-learning-browse-hero"><div class="free-learning-shell"><nav class="free-learning-breadcrumb" aria-label="Breadcrumb"><a href="<?= free_learning_html($freeLearningRouteBase) ?>">Free Learning</a><span>/</span><span><?= free_learning_html($freeLearningMode === 'collection' ? $freeLearningCollection['title'] : 'Browse resources') ?></span></nav><p class="free-learning-eyebrow"><?= $freeLearningMode === 'collection' ? 'Collection' : 'Resource Library' ?></p><h1><?= free_learning_html($freeLearningMode === 'collection' ? $freeLearningCollection['title'] : 'Browse free resources') ?></h1><?php if ($freeLearningMode === 'collection' && !empty($freeLearningCollection['description'])): ?><p><?= free_learning_html($freeLearningCollection['description']) ?></p><?php else: ?><p>Search a focused library of videos, notes, worksheets, revision guides and model answers.</p><?php endif; ?></div></section>
    <section class="free-learning-shell free-learning-browse-section"><form class="free-learning-filters" method="get" action="<?= free_learning_html($freeLearningMode === 'collection' ? $freeLearningRouteBase . '/collection/' . rawurlencode($freeLearningCollection['slug']) : $freeLearningRouteBase) ?>"><input type="hidden" name="browse" value="1"><label class="free-learning-search"><span class="fas fa-search" aria-hidden="true"></span><input name="q" value="<?= free_learning_html($freeLearningSearch) ?>" placeholder="Search titles, topics or keywords"></label><?php if ($freeLearningMode !== 'collection'): ?><select name="collection"><option value="">All collections</option><?php foreach ($freeLearningCollections as $collection): ?><option value="<?= free_learning_html($collection['collection_id']) ?>" <?= $filterCollection && $filterCollection['collection_id'] === $collection['collection_id'] ? 'selected' : '' ?>><?= free_learning_html($collection['title']) ?></option><?php endforeach; ?></select><?php endif; ?><select name="type"><option value="">All resource types</option><?php foreach ($freeLearningTypes as $typeKey => $type): ?><option value="<?= free_learning_html($typeKey) ?>" <?= $freeLearningType === $typeKey ? 'selected' : '' ?>><?= free_learning_html($type['label']) ?></option><?php endforeach; ?></select><select name="featured"><option value="">All resources</option><option value="1" <?= !empty($_GET['featured']) ? 'selected' : '' ?>>Featured only</option></select><select name="sort"><option value="newest" <?= $freeLearningSort === 'newest' ? 'selected' : '' ?>>Newest</option><option value="featured" <?= $freeLearningSort === 'featured' ? 'selected' : '' ?>>Featured first</option><option value="az" <?= $freeLearningSort === 'az' ? 'selected' : '' ?>>A–Z</option></select><button class="free-learning-button primary" type="submit">Apply</button></form><div class="free-learning-results-heading"><p><strong><?= (int) $freeLearningTotal ?></strong> <?= $freeLearningTotal === 1 ? 'resource' : 'resources' ?></p><?php if ($freeLearningSearch || $freeLearningType || $filterCollection): ?><a href="<?= free_learning_html($freeLearningMode === 'collection' ? $freeLearningRouteBase . '/collection/' . rawurlencode($freeLearningCollection['slug']) : free_learning_url($freeLearningRouteBase, ['browse' => 1])) ?>">Clear filters</a><?php endif; ?></div><?php if ($freeLearningResources): ?><div class="free-learning-resource-grid"><?php foreach ($freeLearningResources as $resource): include 'views/public/partials/free-learning-resource-card.php'; endforeach; ?></div><?php else: ?><div class="free-learning-empty"><i class="fas fa-search" aria-hidden="true"></i><div><strong>No matching resources found.</strong><p>Try a different search term or remove a filter.</p></div></div><?php endif; ?><?php $freeLearningPages = max(1, (int) ceil($freeLearningTotal / $freeLearningPerPage)); if ($freeLearningPages > 1): ?><nav class="free-learning-pagination" aria-label="Resource pages"><?php for ($i = 1; $i <= $freeLearningPages; $i++): $pageQuery = ['browse' => 1, 'q' => $freeLearningSearch, 'type' => $freeLearningType, 'sort' => $freeLearningSort, 'page' => $i]; if ($freeLearningMode !== 'collection' && $filterCollection) { $pageQuery['collection'] = $filterCollection['collection_id']; } ?><a class="<?= $i === $freeLearningPage ? 'active' : '' ?>" href="<?= free_learning_html($freeLearningMode === 'collection' ? free_learning_url($freeLearningRouteBase . '/collection/' . rawurlencode($freeLearningCollection['slug']), $pageQuery) : free_learning_url($freeLearningRouteBase, $pageQuery)) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?></section>
<?php endif; ?>
</main>
<?php include 'views/public/layouts/footer.php'; ?>
<script>
(function () {
  document.querySelectorAll('[data-share-url]').forEach(function (button) {
    button.addEventListener('click', function () {
      var url = button.getAttribute('data-share-url');
      if (!url) return;
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(url); }
      else { window.prompt('Copy this resource link:', url); }
      button.classList.add('is-copied');
      button.textContent = 'Link copied';
      window.setTimeout(function () { button.classList.remove('is-copied'); button.innerHTML = '<i class="fas fa-share-alt" aria-hidden="true"></i>Share'; }, 1600);
    });
  });
})();
</script>
</body>
</html>
