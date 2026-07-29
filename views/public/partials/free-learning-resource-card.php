<?php
$type = $freeLearningTypes[$resource['resource_type']] ?? $freeLearningTypes['custom_resource'];
$resourceThumb = free_learning_thumbnail($resource['thumbnail_path'] ?? '', $freeLearningBaseUrl);
$resourceUrl = $freeLearningRouteBase . '/resource/' . rawurlencode($resource['resource_id']);
?>
<article class="free-learning-resource-card">
    <a class="free-learning-resource-thumb" href="<?= free_learning_html($resourceUrl) ?>" aria-label="View <?= free_learning_html($resource['title']) ?>">
        <?php if ($resourceThumb): ?><img src="<?= free_learning_html($resourceThumb) ?>" alt="" loading="lazy"><?php else: ?><span class="<?= free_learning_html($type['icon']) ?>" aria-hidden="true"></span><?php endif; ?>
    </a>
    <div class="free-learning-resource-card-body">
        <span class="free-learning-type-badge type-<?= free_learning_html($resource['resource_type']) ?>"><i class="<?= free_learning_html($type['icon']) ?>" aria-hidden="true"></i><?= free_learning_html($type['label']) ?></span>
        <h3><a href="<?= free_learning_html($resourceUrl) ?>"><?= free_learning_html($resource['title']) ?></a></h3>
        <?php if (!empty($resource['short_description'])): ?><p><?= free_learning_html($resource['short_description']) ?></p><?php endif; ?>
        <div class="free-learning-card-meta"><?php if (!empty($resource['estimated_duration'])): ?><span><i class="far fa-clock" aria-hidden="true"></i><?= (int) $resource['estimated_duration'] ?> min</span><?php endif; ?><?php if (!empty($resource['difficulty'])): ?><span><?= free_learning_html($resource['difficulty']) ?></span><?php endif; ?></div>
        <div class="free-learning-card-actions"><a class="free-learning-card-open" href="<?= free_learning_html($resourceUrl) ?>">Open</a><button type="button" class="free-learning-save-placeholder" disabled title="Save for later is planned for a future phase">Save for later</button><button type="button" class="free-learning-share" data-share-url="<?= free_learning_html($resourceUrl) ?>" aria-label="Share <?= free_learning_html($resource['title']) ?>"><i class="fas fa-share-alt" aria-hidden="true"></i></button></div>
    </div>
</article>
