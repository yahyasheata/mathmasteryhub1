<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/LandingPage.php';
require_once 'inc/CourseVisibility.php';

$username = $_SESSION['username'] ?? null;
$site_settings = getSiteSettings();
$site_name = (string) ($site_settings['website_name'] ?? 'Math Mastery Hub');
$homeHeroEnabled = mmh_site_setting_truthy($site_settings['home_hero_enabled'] ?? '1');
$configuredHeroTitle = trim((string) ($site_settings['home_hero_title'] ?? 'Welcome to {site_name}'));
$homeHeroTitle = trim(str_replace('{site_name}', $site_name, $configuredHeroTitle));
$homeHeroDescription = trim((string) ($site_settings['home_hero_description'] ?? ''));
$homePrimaryLabel = trim((string) ($site_settings['home_primary_label'] ?? 'Explore Courses')) ?: 'Explore Courses';
$homePrimaryUrl = trim((string) ($site_settings['home_primary_url'] ?? '/courses')) ?: '/courses';
$homeCoursesEnabled = mmh_site_setting_truthy($site_settings['home_courses_enabled'] ?? '1');
$homeCoursesHeading = trim((string) ($site_settings['home_courses_heading'] ?? 'Courses')) ?: 'Courses';
$homeCoursesDescription = trim((string) ($site_settings['home_courses_description'] ?? ''));
$homeBasePath = rtrim(mmh_site_public_base_path(), '/');
$homeFaviconUrl = mmh_site_settings_asset_url($site_settings, 'website_icon', 'resources/images/default/favicon.png');
$landingCssPath = __DIR__ . '/../resources/css/public-landing.css';
$landingCssVersion = file_exists($landingCssPath) ? (string) filemtime($landingCssPath) : '1';
$landingCssUrl = mmh_site_public_url('resources/css/public-landing.css') . '?v=' . rawurlencode($landingCssVersion);

$homeUrl = static function (string $url) use ($homeBasePath): string {
    $url = trim($url);
    if ($url === '') return $homeBasePath . '/';
    return str_starts_with($url, '/') ? $homeBasePath . $url : $url;
};
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$plain = static function ($value, int $limit = 160): string {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?: '');
    if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($text) > $limit) {
        return rtrim(mb_substr($text, 0, $limit - 1)) . '...';
    }
    return strlen($text) > $limit ? rtrim(substr($text, 0, $limit - 1)) . '...' : $text;
};

$conn = db();
mmh_landing_ensure_schema($conn);
$homeCategories = [];
$categories_result = mysqli_query($conn, 'SELECT * FROM categories ORDER BY created_at DESC, id DESC LIMIT 6');
if ($categories_result) {
    while ($row = mysqli_fetch_assoc($categories_result)) $homeCategories[] = $row;
}

$homeCourses = [];
$courses_result = mysqli_query($conn, "SELECT courses.*, categories.category_title AS preview_category_title FROM courses LEFT JOIN categories ON courses.course_category = categories.id WHERE courses.course_state = 'public' ORDER BY courses.created_at DESC, courses.id DESC");
if ($courses_result) {
    while ($row = mysqli_fetch_assoc($courses_result)) $homeCourses[] = $row;
}

$heroCourse = null;
if ($homeCoursesEnabled) {
    foreach ($homeCourses as $candidateCourse) {
        $candidateImage = mmh_site_settings_valid_local_asset($candidateCourse['course_image'] ?? '');
        if ($candidateImage !== null) {
            $heroCourse = $candidateCourse;
            $heroCourse['course_image_url'] = mmh_site_public_url($candidateImage);
            break;
        }
    }
}

$landingItems = mmh_landing_grouped_items($conn, true);
$landingStats = $landingItems['trust_stats'] ?? [];
$whyItems = $landingItems['why'] ?? [];
$experienceItems = $landingItems['features'] ?? [];
$testimonialItems = $landingItems['testimonials'] ?? [];
$faqItems = $landingItems['faq'] ?? [];

$statsTitle = mmh_landing_setting($site_settings, 'landing_stats_title');
$statsDescription = mmh_landing_setting($site_settings, 'landing_stats_description');
$whyTitle = mmh_landing_setting($site_settings, 'landing_why_title', 'Teaching and practice');
$whyDescription = mmh_landing_setting($site_settings, 'landing_why_description');
$pathsTitle = mmh_landing_setting($site_settings, 'landing_paths_title', 'Browse by what you are studying.');
$pathsDescription = mmh_landing_setting($site_settings, 'landing_paths_description', 'Start with the path that matches your syllabus, level or current goal.');
$experienceTitle = mmh_landing_setting($site_settings, 'landing_experience_title', 'Lessons, homework and feedback');
$experienceDescription = mmh_landing_setting($site_settings, 'landing_experience_description');
$testimonialsTitle = mmh_landing_setting($site_settings, 'landing_testimonials_title', 'Student feedback');
$testimonialsDescription = mmh_landing_setting($site_settings, 'landing_testimonials_description');
$faqTitle = mmh_landing_setting($site_settings, 'landing_faq_title', 'Frequently asked questions');
$faqDescription = mmh_landing_setting($site_settings, 'landing_faq_description');
$ctaTitle = mmh_landing_setting($site_settings, 'landing_cta_title');
$ctaDescription = mmh_landing_setting($site_settings, 'landing_cta_description');
$ctaPrimaryLabel = mmh_landing_setting($site_settings, 'landing_cta_primary_label', 'Browse Courses');
$ctaPrimaryUrl = mmh_landing_setting($site_settings, 'landing_cta_primary_url', '/courses');
$ctaSecondaryLabel = mmh_landing_setting($site_settings, 'landing_cta_secondary_label');
$ctaSecondaryUrl = mmh_landing_setting($site_settings, 'landing_cta_secondary_url');

$genericHeroTitles = ['Welcome to {site_name}', 'Math learning that feels clear, structured, and supported.'];
$genericHeroDescriptions = [
    'Discover structured mathematics courses and learning resources designed for confident progress.',
    'A calm, structured online learning space for IGCSE mathematics students, with live teaching, recordings, homework, feedback and progress reports in one place.',
    'Live teaching, recorded lessons, homework feedback and weekly progress reports in one calm student workspace.',
];
if ($homeHeroTitle === '' || in_array($configuredHeroTitle, $genericHeroTitles, true) || in_array($homeHeroTitle, $genericHeroTitles, true)) {
    $homeHeroTitle = 'IGCSE & A-Level Mathematics';
}
if ($homeHeroDescription === '' || in_array($homeHeroDescription, $genericHeroDescriptions, true)) {
    $homeHeroDescription = 'Live teaching, recorded lessons, Notes, homework and feedback for exam preparation.';
}
if ($homePrimaryLabel === 'Browse Courses') $homePrimaryLabel = 'Explore Courses';
if (in_array($homeCoursesHeading, ['Choose your course', 'Explore Our Courses'], true)) $homeCoursesHeading = 'Courses';
if (in_array($homeCoursesDescription, ['Focused course spaces for live lessons, recordings, homework and feedback.', 'Browse available courses and start learning today.'], true)) $homeCoursesDescription = '';
if ($whyTitle === 'A focused learning environment, not another noisy dashboard.') $whyTitle = 'Teaching and practice';
if ($whyDescription === 'Everything is organized around what students actually need each week: the lesson, the recording, the homework and the feedback.') $whyDescription = '';
if ($experienceTitle === 'One place for lessons, practice and parent-friendly progress.') $experienceTitle = 'Lessons, homework and feedback';
if ($experienceDescription === 'The platform supports the live course instead of replacing it. Students can review, submit and continue without hunting for links.') $experienceDescription = '';
if ($testimonialsTitle === 'Designed to make the next step obvious.') $testimonialsTitle = 'Student feedback';
if ($faqTitle === 'Simple answers before you begin.') $faqTitle = 'Frequently asked questions';
if ($ctaTitle === 'Start with a clear learning path.') $ctaTitle = '';
if ($ctaDescription === 'Browse the course spaces and choose the one that matches your current mathematics goal.') $ctaDescription = '';
$heroTargetUrl = $homeUrl($homePrimaryUrl);
$ctaTargetUrl = $homeUrl($ctaPrimaryUrl);
$hasDistinctFinalCta = ($ctaSecondaryLabel !== '' && $ctaSecondaryUrl !== '') || ($ctaPrimaryLabel !== '' && $ctaPrimaryUrl !== '' && $ctaTargetUrl !== $heroTargetUrl);

trackTraffic();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $e($site_name); ?></title>
  <?= ($metatags ?? '') . "\n" ?>
  <?= ($keywords ?? '') . "\n" ?>
  <?= $openGraph ?? '' ?>
  <?= $schema ?? '' ?>
  <?php include __DIR__ . '/partials/favicon.php'; ?>
  <link rel="stylesheet" href="<?= $e(mmh_site_public_url('resources/css/fontawsome5.min.css')); ?>">
  <link rel="stylesheet" href="<?= $e(mmh_site_public_url('resources/css/design-system.css')); ?>" data-design-system="mathhub">
  <link rel="stylesheet" href="<?= $e($landingCssUrl); ?>">
</head>
<body class="public-landing-body">
  <div class="public-landing">
    <?php include $_SERVER['DOCUMENT_ROOT'] . dirname($_SERVER['SCRIPT_NAME']) . '/views/public/layouts/aside.php'; ?>

    <?php if ($homeHeroEnabled): ?>
      <main id="home" class="landing-hero" aria-labelledby="landing-hero-title">
        <div class="landing-shell landing-hero-grid<?= $heroCourse ? ' has-course-art' : ' no-course-art' ?>">
          <section class="landing-hero-copy">
            <h1 id="landing-hero-title"><?= $e($homeHeroTitle); ?></h1>
            <p class="landing-hero-description"><?= $e($homeHeroDescription); ?></p>
            <div class="landing-actions" aria-label="Primary actions">
              <a class="landing-button landing-button-primary" href="<?= $e($homeUrl($homePrimaryUrl)); ?>">
                <span><?= $e($homePrimaryLabel); ?></span>
              </a>
            </div>
          </section>

          <?php if ($heroCourse): ?>
            <figure class="landing-hero-art">
              <img src="<?= $e($heroCourse['course_image_url']); ?>" alt="" fetchpriority="high">
              <figcaption><?= $e($heroCourse['course_title'] ?? ''); ?></figcaption>
            </figure>
          <?php endif; ?>
        </div>
      </main>
    <?php endif; ?>

    <?php if ($homeCoursesEnabled && $homeCourses): ?>
      <section id="courses" class="landing-section landing-pricing" aria-labelledby="courses-title">
        <div class="landing-shell">
          <div class="landing-section-heading">
            <h2 id="courses-title"><?= $e($homeCoursesHeading); ?></h2>
            <?php if ($homeCoursesDescription !== ''): ?><p><?= $e($homeCoursesDescription); ?></p><?php endif; ?>
          </div>
          <div class="landing-course-grid">
            <?php foreach (array_slice($homeCourses, 0, 6) as $course): ?>
              <?php
                $courseTitle = (string) ($course['course_title'] ?? 'Course');
                $courseDescription = $plain($course['course_description'] ?? '', 110);
                $courseUrl = $homeBasePath . '/course/' . rawurlencode((string) ($course['id'] ?? ''));
                $courseImage = mmh_site_settings_valid_local_asset($course['course_image'] ?? '');
                $courseImageUrl = $courseImage !== null ? mmh_site_public_url($courseImage) : '';
                $courseCategory = trim((string) ($course['preview_category_title'] ?? ''));
                $price = !empty($course['course_price']) ? $course['course_price'] . ' EGP' : 'Free';
                $oldPrice = (!empty($course['preDiscount_course_price']) && (float) $course['preDiscount_course_price'] > (float) ($course['course_price'] ?? 0))
                  ? $course['preDiscount_course_price'] . ' EGP'
                  : '';
              ?>
              <article class="landing-course-card">
                <?php if ($courseImageUrl !== ''): ?>
                  <div class="landing-course-image">
                    <img src="<?= $e($courseImageUrl); ?>" alt="" loading="lazy">
                  </div>
                <?php endif; ?>
                <div class="landing-course-copy">
                  <?php if ($courseCategory !== ''): ?><p class="landing-course-category"><?= $e($courseCategory); ?></p><?php endif; ?>
                  <h3><?= $e($courseTitle); ?></h3>
                  <?php if ($courseDescription !== ''): ?><p class="landing-course-description"><?= $e($courseDescription); ?></p><?php endif; ?>
                </div>
                <div class="landing-course-footer">
                  <div class="landing-price">
                    <?php if ($oldPrice !== ''): ?><small><?= $e($oldPrice); ?></small><?php endif; ?>
                    <strong><?= $e($price); ?></strong>
                  </div>
                  <a class="landing-button landing-button-secondary small" href="<?= $e($courseUrl); ?>">View Course</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'paths') && $homeCategories): ?>
      <section class="landing-section landing-paths" aria-labelledby="paths-title">
        <div class="landing-shell landing-split">
          <div class="landing-section-heading compact">
            <h2 id="paths-title"><?= $e($pathsTitle); ?></h2>
            <?php if ($pathsDescription !== ''): ?><p><?= $e($pathsDescription); ?></p><?php endif; ?>
          </div>
          <nav class="landing-path-list" aria-label="Browse by subject">
            <?php foreach ($homeCategories as $category): ?>
              <a class="landing-path-item" href="<?= $e($homeBasePath . '/category/' . ($category['category_link'] ?? '')); ?>">
                <span><?= $e($category['category_title'] ?? 'Learning path'); ?></span>
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
              </a>
            <?php endforeach; ?>
          </nav>
        </div>
      </section>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'stats') && $landingStats): ?>
      <section class="landing-stats" aria-label="Platform statistics">
        <div class="landing-shell">
          <?php if ($statsTitle !== '' || $statsDescription !== ''): ?>
            <div class="landing-stats-heading">
              <?php if ($statsTitle !== ''): ?><h2><?= $e($statsTitle); ?></h2><?php endif; ?>
              <?php if ($statsDescription !== ''): ?><p><?= $e($statsDescription); ?></p><?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="landing-stats-grid">
            <?php foreach ($landingStats as $stat): ?>
              <article>
                <strong><?= $e($stat['value'] ?? ''); ?></strong>
                <span><?= $e($stat['label'] ?? ''); ?></span>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'why') && $whyItems): ?>
      <section class="landing-section landing-why" aria-labelledby="why-title">
        <div class="landing-shell">
          <div class="landing-section-heading">
            <h2 id="why-title"><?= $e($whyTitle); ?></h2>
            <?php if ($whyDescription !== ''): ?><p><?= $e($whyDescription); ?></p><?php endif; ?>
          </div>
          <div class="landing-proof-list">
            <?php foreach ($whyItems as $item): ?>
              <article class="landing-proof-row">
                <h3><?= $e($item['title'] ?? ''); ?></h3>
                <p><?= $e($item['description'] ?? ''); ?></p>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'experience') && $experienceItems): ?>
      <section class="landing-section landing-experience" aria-labelledby="experience-title">
        <div class="landing-shell landing-experience-grid">
          <div class="landing-section-heading compact">
            <h2 id="experience-title"><?= $e($experienceTitle); ?></h2>
            <?php if ($experienceDescription !== ''): ?><p><?= $e($experienceDescription); ?></p><?php endif; ?>
          </div>
          <div class="landing-proof-list">
            <?php foreach ($experienceItems as $item): ?>
              <article class="landing-proof-row">
                <h3><?= $e($item['title'] ?? ''); ?></h3>
                <p><?= $e($item['description'] ?? ''); ?></p>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'testimonials') && $testimonialItems): ?>
      <section class="landing-section landing-testimonials" aria-labelledby="testimonials-title">
        <div class="landing-shell">
          <div class="landing-section-heading">
            <h2 id="testimonials-title"><?= $e($testimonialsTitle); ?></h2>
            <?php if ($testimonialsDescription !== ''): ?><p><?= $e($testimonialsDescription); ?></p><?php endif; ?>
          </div>
          <div class="landing-card-grid three">
            <?php foreach ($testimonialItems as $item): ?>
              <blockquote class="landing-quote-card">
                <?php $photoUrl = mmh_landing_photo_url($item['photo_path'] ?? ''); ?>
                <?php if ($photoUrl !== ''): ?><img src="<?= $e($photoUrl); ?>" alt="" class="landing-quote-photo"><?php endif; ?>
                <p>“<?= $e($item['quote'] ?? ''); ?>”</p>
                <footer>
                  <?= $e($item['student_name'] ?? ''); ?>
                  <?php $meta = array_filter([trim((string)($item['grade'] ?? '')), trim((string)($item['exam_board'] ?? ''))]); ?>
                  <?php if ($meta): ?><span><?= $e(implode(' · ', $meta)); ?></span><?php endif; ?>
                </footer>
              </blockquote>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'faq') && $faqItems): ?>
      <section class="landing-section landing-faq" aria-labelledby="faq-title">
        <div class="landing-shell landing-faq-grid">
          <div class="landing-section-heading compact">
            <h2 id="faq-title"><?= $e($faqTitle); ?></h2>
            <?php if ($faqDescription !== ''): ?><p><?= $e($faqDescription); ?></p><?php endif; ?>
          </div>
          <div class="landing-faq-list">
            <?php foreach ($faqItems as $item): ?>
              <details>
                <summary><?= $e($item['question'] ?? ''); ?></summary>
                <p><?= $e($item['answer'] ?? ''); ?></p>
              </details>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'cta') && $hasDistinctFinalCta && ($ctaTitle !== '' || $ctaDescription !== '' || ($ctaPrimaryLabel !== '' && $ctaPrimaryUrl !== '') || ($ctaSecondaryLabel !== '' && $ctaSecondaryUrl !== ''))): ?>
      <section class="landing-final-cta" aria-labelledby="final-cta-title">
        <div class="landing-shell">
          <?php if ($ctaTitle !== ''): ?><h2 id="final-cta-title"><?= $e($ctaTitle); ?></h2><?php endif; ?>
          <?php if ($ctaDescription !== ''): ?><p><?= $e($ctaDescription); ?></p><?php endif; ?>
          <div class="landing-actions center">
            <?php if ($ctaPrimaryLabel !== '' && $ctaPrimaryUrl !== ''): ?>
              <a class="landing-button landing-button-primary" href="<?= $e($homeUrl($ctaPrimaryUrl)); ?>">
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                <span><?= $e($ctaPrimaryLabel); ?></span>
              </a>
            <?php endif; ?>
            <?php if ($ctaSecondaryLabel !== '' && $ctaSecondaryUrl !== ''): ?>
              <a class="landing-button landing-button-secondary" href="<?= $e($homeUrl($ctaSecondaryUrl)); ?>">
                <span><?= $e($ctaSecondaryLabel); ?></span>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php include $_SERVER['DOCUMENT_ROOT'] . dirname($_SERVER['SCRIPT_NAME']) . '/views/public/layouts/footer.php'; ?>
  </div>
</body>
</html>
