<?php include 'views/user/partials/free-learning-home.php'; ?>

<section class="learning-home-section">
    <div class="learning-home-section-heading"><span>Blog / Announcements</span><h2>What is new?</h2></div>
    <?php if (!empty($announcements)): ?><div class="learning-home-mini-list"><?php foreach ($announcements as $item): ?><article><strong><?=learning_home_html($item['title'])?></strong><small><?=learning_home_html($item['message'])?> · <?=learning_home_relative_time($item['created_at'], '')?></small></article><?php endforeach; ?></div><?php else: ?><div class="learning-home-empty compact"><span class="fas fa-bell"></span><p>No announcements yet.</p></div><?php endif; ?>
</section>

<section class="learning-home-section">
    <div class="learning-home-section-heading"><span>Featured Premium Courses</span><h2>When you are ready for the full learning path.</h2></div>
    <div class="learning-home-course-grid">
        <?php foreach ($featured_courses as $course): ?>
            <article class="learning-home-course-card"><img src="<?=learning_home_html(rtrim((string)$baseUrl, '/') . '/' . ltrim((string)$course['course_image'], '/'))?>" alt="<?=learning_home_html($course['course_title'])?>"><div><small><?=learning_home_html($course['category_title'] ?: 'Course')?></small><h3><?=learning_home_html($course['course_title'])?></h3><p><?=learning_home_html($course['course_description'])?></p><a class="learning-home-btn ghost" href="<?=learning_home_html(rtrim((string)$baseUrl, '/'))?>/course/<?=learning_home_html($course['id'])?>">View Course</a></div></article>
        <?php endforeach; ?>
    </div>
</section>
