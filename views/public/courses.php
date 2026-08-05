<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/CourseVisibility.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Vary: Cookie');
$conn = db();
$site_settings = getSiteSettings();
$site_name = $site_settings['website_name'] ?? 'Math Mastery Hub';
$publicBaseUrl = rtrim((string)$baseUrl, '/');
$publicResourcesCssVersion = (string) (@filemtime('resources/css/public-resources.css') ?: 1);
function public_courses_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$courses = [];
$sql = "SELECT c.id, c.course_id, c.course_title, c.course_image, c.course_description, c.course_price, c.preDiscount_course_price, cat.category_title
        FROM courses AS c
        LEFT JOIN categories AS cat ON cat.id = c.course_category
        WHERE c.course_state = 'public'
        ORDER BY c.id DESC
        LIMIT 60";
$result = mysqli_query($conn, $sql);
if ($result instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($result)) { $courses[] = $row; }
}
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Courses | <?=public_courses_html($site_name)?></title>
    <link rel="stylesheet" href="<?=public_courses_html($publicBaseUrl)?>/resources/css/design-system.css" data-design-system="mathhub">
    <link rel="stylesheet" href="<?=public_courses_html($publicBaseUrl)?>/resources/build/assets/app-38448552.css">
    <link rel="stylesheet" href="<?=public_courses_html($publicBaseUrl)?>/resources/css/fontawsome5.min.css">
    <link rel="stylesheet" href="<?=public_courses_html($publicBaseUrl)?>/resources/css/public-resources.css?v=<?=rawurlencode($publicResourcesCssVersion)?>">
</head>
<body class="ds-bg-primary ds-text-primary public-resource-page public-courses-page">
<?php include 'views/public/layouts/aside.php'; ?>
<main class="public-resource-main">
    <section class="public-resource-hero">
        <div class="public-resource-shell public-resource-hero-grid">
            <div>
                <span class="public-resource-kicker">Premium learning spaces</span>
                <h1>Courses</h1>
                <p>Browse the available Math Mastery Hub courses. Visitors can explore public previews, while enrolled students continue learning from their private course workspace.</p>
            </div>
            <aside class="public-resource-preview-card">
                <span class="fas fa-graduation-cap" aria-hidden="true"></span>
                <strong>One connected platform</strong>
                <p>Course previews, enrolled lessons, Past Papers and free resources now live under one consistent public website.</p>
            </aside>
        </div>
    </section>
    <section class="public-resource-shell public-resource-section">
        <div class="public-resource-section-heading"><span>Available courses</span><h2>Choose your learning path</h2></div>
        <?php if ($courses): ?>
            <div class="public-course-grid">
                <?php foreach ($courses as $course): ?>
                    <?php $courseImageUrl = mmh_site_public_url(ltrim((string) ($course['course_image'] ?? ''), '/')); ?>
                    <a class="public-course-card" href="<?=public_courses_html($publicBaseUrl)?>/course/<?=public_courses_html($course['id'])?>">
                        <img src="<?=public_courses_html($courseImageUrl)?>" alt="<?=public_courses_html($course['course_title'])?>">
                        <div class="public-course-card-body">
                            <small><?=public_courses_html($course['category_title'] ?: 'Course')?></small>
                            <h3><?=public_courses_html($course['course_title'])?></h3>
                            <p><?=public_courses_html(strip_tags((string)$course['course_description']))?></p>
                            <span class="public-resource-btn secondary">View Course</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="public-resource-empty"><div><span class="fas fa-folder-open" aria-hidden="true"></span><h3>Courses are being prepared</h3><p>New premium courses will appear here once published.</p></div></div>
        <?php endif; ?>
    </section>
</main>
<?php include 'views/public/layouts/footer.php'; ?>
</body>
</html>
