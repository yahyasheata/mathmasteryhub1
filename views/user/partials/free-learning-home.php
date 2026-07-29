<?php
require_once 'inc/FreeResources.php';
$freeLearningHomeConn = db();
$freeLearningHomeFeatured = mmh_free_featured_resources($freeLearningHomeConn, 3);
$freeLearningHomeLatest = mmh_free_latest_resources($freeLearningHomeConn, 3);
$freeLearningHomeCollections = mmh_free_public_collections($freeLearningHomeConn, 4);
$freeLearningHomeBase = rtrim((string) $baseUrl, '/') . '/free-learning';
?>
<section class="learning-home-section" id="free-learning">
    <div class="learning-home-section-heading split"><div><span>Free Learning Center</span><h2>Study something useful today.</h2></div><a class="learning-home-btn secondary" href="<?=learning_home_html($freeLearningHomeBase)?>">Open Free Learning</a></div>
    <div class="learning-home-card-grid feature-grid">
        <a class="learning-home-feature-card active" href="<?=learning_home_html($freeLearningHomeBase)?>"><span class="fas fa-book-open"></span><div><strong>Browse Resources</strong><small>Videos, notes, worksheets and revision support in one place.</small></div></a>
        <a class="learning-home-feature-card active" href="<?=learning_home_html($freeLearningHomeBase)?>?browse=1&amp;type=youtube_video"><span class="fab fa-youtube"></span><div><strong>Video Lessons</strong><small>Find concise explanations by topic.</small></div></a>
        <a class="learning-home-feature-card active" href="<?=learning_home_html($freeLearningHomeBase)?>?browse=1&amp;type=free_notes"><span class="fas fa-sticky-note"></span><div><strong>Free Notes</strong><small>Open revision notes and study guides.</small></div></a>
        <a class="learning-home-feature-card active" href="<?=learning_home_html($freeLearningHomeBase)?>?browse=1&amp;type=worksheet"><span class="fas fa-edit"></span><div><strong>Worksheets</strong><small>Practise with focused mathematics resources.</small></div></a>
    </div>
</section>

<section class="learning-home-section">
    <div class="learning-home-section-heading split"><div><span>Featured resources</span><h2>Picked for focused study.</h2></div><a class="learning-home-btn ghost" href="<?=learning_home_html($freeLearningHomeBase)?>?browse=1&amp;featured=1">View all</a></div>
    <?php if ($freeLearningHomeFeatured): ?><div class="learning-home-mini-list"><?php foreach ($freeLearningHomeFeatured as $resource): ?><a href="<?=learning_home_html($freeLearningHomeBase . '/resource/' . rawurlencode((string)$resource['resource_id']))?>"><strong><?=learning_home_html($resource['title'])?></strong><small><?=learning_home_html(mmh_free_resource_label($resource['resource_type']))?><?=!empty($resource['estimated_duration']) ? ' · ' . (int)$resource['estimated_duration'] . ' min' : ''?></small></a><?php endforeach; ?></div><?php else: ?><div class="learning-home-empty compact"><span class="fas fa-star"></span><p>Featured Free Learning resources appear here when they are published.</p></div><?php endif; ?>
</section>

<section class="learning-home-section">
    <div class="learning-home-section-heading split"><div><span>Browse collections</span><h2>Find the topic you need.</h2></div><a class="learning-home-btn ghost" href="<?=learning_home_html($freeLearningHomeBase)?>#collections">Explore topics</a></div>
    <?php if ($freeLearningHomeCollections): ?><div class="learning-home-mini-list"><?php foreach ($freeLearningHomeCollections as $collection): ?><a href="<?=learning_home_html($freeLearningHomeBase . '/collection/' . rawurlencode((string)$collection['slug']))?>"><strong><?=learning_home_html($collection['title'])?></strong><small><?= (int)$collection['resource_count'] ?> resources<?=!empty($collection['description']) ? ' · ' . learning_home_html($collection['description']) : ''?></small></a><?php endforeach; ?></div><?php else: ?><div class="learning-home-empty compact"><span class="fas fa-folder-open"></span><p>Published collections appear here automatically.</p></div><?php endif; ?>
</section>

<section class="learning-home-section">
    <div class="learning-home-section-heading split"><div><span>Latest resources</span><h2>New ways to practise.</h2></div><a class="learning-home-btn ghost" href="<?=learning_home_html($freeLearningHomeBase)?>?browse=1&amp;sort=newest">See all</a></div>
    <?php if ($freeLearningHomeLatest): ?><div class="learning-home-mini-list"><?php foreach ($freeLearningHomeLatest as $resource): ?><a href="<?=learning_home_html($freeLearningHomeBase . '/resource/' . rawurlencode((string)$resource['resource_id']))?>"><strong><?=learning_home_html($resource['title'])?></strong><small><?=learning_home_html(mmh_free_resource_label($resource['resource_type']))?></small></a><?php endforeach; ?></div><?php else: ?><div class="learning-home-empty compact"><span class="fas fa-clock"></span><p>Newly published Free Learning resources appear here automatically.</p></div><?php endif; ?>
</section>
