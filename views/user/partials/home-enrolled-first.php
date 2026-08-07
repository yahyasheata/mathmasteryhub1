<?php
require_once 'inc/StudentResourceGateway.php';
$continueCourseId = (string) ($continue_course['courseId'] ?? '');
$continueItemId = (string) ($continue_course['item_id'] ?? '');
$continueHref = $continueItemId !== ''
    ? mmh_student_resource_url($baseUrl, $continueCourseId, $continueItemId)
    : rtrim((string) $baseUrl, '/') . '/user/course/' . rawurlencode($continueCourseId);
?>
<section class="learning-home-section" id="continue-learning">
    <div class="learning-home-section-heading"><span>Continue Learning</span><h2>Pick up from your course workspace.</h2></div>
    <?php if ($continue_course): ?>
        <article class="learning-home-continue-card">
            <img src="<?=learning_home_html(rtrim((string)$baseUrl, '/') . '/' . ltrim((string)($continue_course['course_image'] ?? ''), '/'))?>" alt="<?=learning_home_html($continue_course['course_title'] ?? 'Course')?>">
            <div><strong><?=learning_home_html($continue_course['course_title'] ?? 'Course')?></strong><p><?=learning_home_html(($continue_course['item_title'] ?? '') ?: (($continue_course['section_title'] ?? '') ?: 'Start your next lesson'))?></p><small>Last activity: <?=learning_home_relative_time($continue_course['created_at'] ?? '')?></small></div>
            <a class="learning-home-btn primary" href="<?=learning_home_html($continueHref)?>">Continue</a>
        </article>
    <?php endif; ?>
</section>

<section class="learning-home-section two-column-priority">
    <article>
        <div class="learning-home-section-heading"><span>Today’s Live Session</span><h2>Live learning</h2></div>
        <?php if ($live_priority): ?><div class="learning-home-priority-card"><strong><?=learning_home_html($live_priority['course_title'])?></strong><p><?=learning_home_html($live_priority['schedule_title'] ?: 'Live session')?> · <?=learning_home_html(mmh_live_display_time($live_priority))?></p><a class="learning-home-btn primary" href="<?=learning_home_html($live_join_base . $live_priority['occurrence_id'])?>"><?=learning_home_html($live_priority['_join_label'] ?? 'Join Session')?></a></div><?php else: ?><div class="learning-home-empty compact"><span class="fas fa-video"></span><p>No live session needs action right now.</p></div><?php endif; ?>
    </article>
    <article>
        <div class="learning-home-section-heading"><span>Homework Due</span><h2>Needs attention</h2></div>
        <?php if (!empty($homework_items)): ?><div class="learning-home-mini-list"><?php foreach ($homework_items as $item): $homeworkCourseId = (string) ($item['courseId'] ?? ''); $homeworkItemId = (string) ($item['item_id'] ?? ''); $homeworkHref = $homeworkItemId !== '' ? mmh_student_resource_url($baseUrl, $homeworkCourseId, $homeworkItemId) : rtrim((string) $baseUrl, '/') . '/user/course/' . rawurlencode($homeworkCourseId); ?><a href="<?=learning_home_html($homeworkHref)?>"><strong><?=learning_home_html($item['assignment_title'])?></strong><small><?=learning_home_html($item['course_title'])?> · <?=learning_home_date_label($item['due_date'])?></small></a><?php endforeach; ?></div><?php else: ?><div class="learning-home-empty compact"><span class="fas fa-check-circle"></span><p>No urgent homework right now.</p></div><?php endif; ?>
    </article>
</section>

<section class="learning-home-section">
    <div class="learning-home-section-heading"><span>Announcements</span><h2>What changed recently?</h2></div>
    <?php if (!empty($announcements)): ?><div class="learning-home-mini-list"><?php foreach ($announcements as $item): ?><article><strong><?=learning_home_html($item['title'])?></strong><small><?=learning_home_html($item['message'])?> · <?=learning_home_relative_time($item['created_at'], '')?></small></article><?php endforeach; ?></div><?php else: ?><div class="learning-home-empty compact"><span class="fas fa-bell"></span><p>No announcements yet.</p></div><?php endif; ?>
</section>

<section class="learning-home-section">
    <div class="learning-home-section-heading split"><div><span>My Courses</span><h2>Your enrolled learning spaces.</h2></div><a class="learning-home-btn secondary" href="<?=learning_home_html(rtrim((string)$baseUrl, '/'))?>/user/my-courses">Open My Courses</a></div>
    <div class="learning-home-course-grid compact">
        <?php foreach ($enrolled_courses as $course): ?><article class="learning-home-course-card"><img src="<?=learning_home_html(rtrim((string)$baseUrl, '/') . '/' . ltrim((string)$course['course_image'], '/'))?>" alt="<?=learning_home_html($course['course_title'])?>"><div><small><?=learning_home_html($course['category_title'] ?: 'Course')?> · <?=learning_home_html($course['item_count'])?> lessons</small><h3><?=learning_home_html($course['course_title'])?></h3><p><?=learning_home_html($course['homework_count'])?> homework activities</p><a class="learning-home-btn ghost" href="<?=learning_home_html(rtrim((string)$baseUrl, '/'))?>/user/course/<?=learning_home_html($course['courseId'])?>">Open Course</a></div></article><?php endforeach; ?>
    </div>
</section>

<?php include 'views/user/partials/free-learning-home.php'; ?>

<section class="learning-home-section">
    <div class="learning-home-section-heading split"><div><span>Past Papers</span><h2>Practise after learning the lesson.</h2></div><a class="learning-home-btn secondary" href="<?=learning_home_html(rtrim((string)$baseUrl, '/'))?>/past-papers">Open Past Papers</a></div>
    <?php if (!empty($free_past_papers)): ?><div class="learning-home-mini-list"><?php foreach ($free_past_papers as $paper): ?><a href="<?=learning_home_html(rtrim((string)$baseUrl, '/'))?>/past-papers?syllabus_id=<?=learning_home_html(rawurlencode((string)$paper['syllabus_id']))?>"><strong><?=learning_home_html($paper['syllabus_title'])?></strong><small><?=learning_home_html($paper['exam_session'])?> <?=learning_home_html($paper['year'])?> · <?=learning_home_html($paper['paper_number'])?> <?=learning_home_html($paper['variant'])?></small></a><?php endforeach; ?></div><?php else: ?><div class="learning-home-empty compact"><span class="fas fa-folder-open"></span><p>Published Past Papers will appear here.</p></div><?php endif; ?>
</section>
