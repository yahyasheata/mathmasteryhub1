<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/LandingPage.php';
require_once 'inc/CourseVisibility.php';

$username = $_SESSION['username'] ?? null;
$site_settings = getSiteSettings();
$site_name = (string) ($site_settings['website_name'] ?? 'Math Mastery Hub');
$site_description = (string) ($site_settings['website_bio'] ?? '');

$homeHeroEnabled = mmh_site_setting_truthy($site_settings['home_hero_enabled'] ?? '1');
$configuredHeroTitle = trim((string) ($site_settings['home_hero_title'] ?? 'Welcome to {site_name}'));
$homeHeroTitle = trim(str_replace('{site_name}', $site_name, $configuredHeroTitle));
$homeHeroDescription = trim((string) ($site_settings['home_hero_description'] ?? ''));
$homePrimaryLabel = trim((string) ($site_settings['home_primary_label'] ?? 'Browse Courses')) ?: 'Browse Courses';
$homePrimaryUrl = trim((string) ($site_settings['home_primary_url'] ?? '/courses')) ?: '/courses';
$homeSecondaryLabel = trim((string) ($site_settings['home_secondary_label'] ?? 'Explore Free Resources')) ?: 'Explore Free Resources';
$homeSecondaryUrl = trim((string) ($site_settings['home_secondary_url'] ?? '/free-learning')) ?: '/free-learning';
$homeCoursesEnabled = mmh_site_setting_truthy($site_settings['home_courses_enabled'] ?? '1');
$homeCoursesHeading = trim((string) ($site_settings['home_courses_heading'] ?? 'Choose your course')) ?: 'Choose your course';
$homeCoursesDescription = trim((string) ($site_settings['home_courses_description'] ?? 'Focused course spaces for live lessons, recordings, homework and feedback.')) ?: 'Focused course spaces for live lessons, recordings, homework and feedback.';
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
$courses_result = mysqli_query($conn, "SELECT * FROM courses WHERE course_state = 'public' ORDER BY created_at DESC, id DESC");
if ($courses_result) {
    while ($row = mysqli_fetch_assoc($courses_result)) $homeCourses[] = $row;
}

$landingItems = mmh_landing_grouped_items($conn, true);
$landingStats = $landingItems['trust_stats'] ?? [];
$whyItems = $landingItems['why'] ?? [];
$experienceItems = $landingItems['features'] ?? [];
$testimonialItems = $landingItems['testimonials'] ?? [];
$faqItems = $landingItems['faq'] ?? [];

$statsTitle = mmh_landing_setting($site_settings, 'landing_stats_title');
$statsDescription = mmh_landing_setting($site_settings, 'landing_stats_description');
$whyTitle = mmh_landing_setting($site_settings, 'landing_why_title', 'A focused learning environment, not another noisy dashboard.');
$whyDescription = mmh_landing_setting($site_settings, 'landing_why_description', 'Everything is organized around what students actually need each week: the lesson, the recording, the homework and the feedback.');
$pathsTitle = mmh_landing_setting($site_settings, 'landing_paths_title', 'Browse by what you are studying.');
$pathsDescription = mmh_landing_setting($site_settings, 'landing_paths_description', 'Start with the path that matches your syllabus, level or current goal.');
$experienceTitle = mmh_landing_setting($site_settings, 'landing_experience_title', 'One place for lessons, practice and parent-friendly progress.');
$experienceDescription = mmh_landing_setting($site_settings, 'landing_experience_description', 'The platform supports the live course instead of replacing it. Students can review, submit and continue without hunting for links.');
$testimonialsTitle = mmh_landing_setting($site_settings, 'landing_testimonials_title', 'Designed to make the next step obvious.');
$testimonialsDescription = mmh_landing_setting($site_settings, 'landing_testimonials_description');
$faqTitle = mmh_landing_setting($site_settings, 'landing_faq_title', 'Simple answers before you begin.');
$faqDescription = mmh_landing_setting($site_settings, 'landing_faq_description');
$ctaTitle = mmh_landing_setting($site_settings, 'landing_cta_title', 'Start with a clear learning path.');
$ctaDescription = mmh_landing_setting($site_settings, 'landing_cta_description', 'Browse the course spaces and choose the one that matches your current mathematics goal.');
$ctaPrimaryLabel = mmh_landing_setting($site_settings, 'landing_cta_primary_label', 'Browse Courses');
$ctaPrimaryUrl = mmh_landing_setting($site_settings, 'landing_cta_primary_url', '/courses');
$ctaSecondaryLabel = mmh_landing_setting($site_settings, 'landing_cta_secondary_label');
$ctaSecondaryUrl = mmh_landing_setting($site_settings, 'landing_cta_secondary_url');

if ($homeHeroDescription === '') {
    $homeHeroDescription = $site_description !== ''
        ? $site_description
        : 'A calm, structured online learning space for IGCSE mathematics students, with live teaching, recordings, homework, feedback and progress reports in one place.';
}
if ($homeHeroTitle === '' || $configuredHeroTitle === 'Welcome to {site_name}') {
    $homeHeroTitle = 'Math learning that feels clear, structured, and supported.';
}
if ($homeHeroDescription === 'Discover structured mathematics courses and learning resources designed for confident progress.') {
    $homeHeroDescription = 'Live teaching, recorded lessons, homework feedback and weekly progress reports in one calm student workspace.';
}

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
  <link rel="icon" type="image/png" href="<?= $e($homeFaviconUrl); ?>">
  <link rel="apple-touch-icon" href="<?= $e($homeFaviconUrl); ?>">
  <link rel="stylesheet" href="<?= $e(mmh_site_public_url('resources/css/fontawsome5.min.css')); ?>">
  <link rel="stylesheet" href="<?= $e(mmh_site_public_url('resources/css/design-system.css')); ?>" data-design-system="mathhub">
  <link rel="stylesheet" href="<?= $e($landingCssUrl); ?>">
</head>
<body class="public-landing-body">
  <div class="public-landing">
    <?php include $_SERVER['DOCUMENT_ROOT'] . dirname($_SERVER['SCRIPT_NAME']) . '/views/public/layouts/aside.php'; ?>

    <?php if ($homeHeroEnabled): ?>
      <main id="home" class="landing-hero" aria-labelledby="landing-hero-title">
        <div class="landing-shell landing-hero-grid">
          <section class="landing-hero-copy">
            <p class="landing-eyebrow">Math Mastery Hub</p>
            <h1 id="landing-hero-title"><?= $e($homeHeroTitle); ?></h1>
            <p class="landing-hero-description"><?= $e($homeHeroDescription); ?></p>
            <div class="landing-actions" aria-label="Primary actions">
              <a class="landing-button landing-button-primary" href="<?= $e($homeUrl($homePrimaryUrl)); ?>">
                <i class="fas fa-book-open" aria-hidden="true"></i>
                <span><?= $e($homePrimaryLabel); ?></span>
              </a>
              <a class="landing-button landing-button-secondary" href="<?= $e($homeUrl($homeSecondaryUrl)); ?>">
                <i class="fas fa-file-alt" aria-hidden="true"></i>
                <span><?= $e($homeSecondaryLabel); ?></span>
              </a>
            </div>
          </section>

          <section class="landing-dashboard-preview" aria-label="Student dashboard preview">
            <div class="preview-window">
              <header>
                <div>
                  <span class="preview-dot"></span>
                  <span class="preview-dot"></span>
                  <span class="preview-dot"></span>
                </div>
                <strong>Student workspace</strong>
              </header>
              <div class="preview-body">
                <article class="preview-course">
                  <small>Continue learning</small>
                  <h2>Expanding Expressions</h2>
                  <div class="preview-progress"><span style="width: 68%"></span></div>
                </article>
                <div class="preview-grid">
                  <article>
                    <i class="fas fa-video" aria-hidden="true"></i>
                    <span>Live session</span>
                    <strong>Today</strong>
                  </article>
                  <article>
                    <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                    <span>Homework</span>
                    <strong>Due soon</strong>
                  </article>
                </div>
                <article class="preview-report">
                  <div>
                    <span>Weekly report</span>
                    <strong>Clear next steps</strong>
                  </div>
                  <i class="fas fa-chart-line" aria-hidden="true"></i>
                </article>
              </div>
            </div>
          </section>
        </div>
      </main>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'stats') && $landingStats): ?>
      <section class="landing-stats" aria-label="Trusted by students">
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
            <p class="landing-eyebrow">Why Math Mastery Hub</p>
            <h2 id="why-title"><?= $e($whyTitle); ?></h2>
            <?php if ($whyDescription !== ''): ?><p><?= $e($whyDescription); ?></p><?php endif; ?>
          </div>
          <div class="landing-card-grid three">
            <?php foreach ($whyItems as $item): ?>
              <article class="landing-info-card">
                <i class="<?= $e(mmh_landing_icon_class($item['icon'] ?? '', 'fas fa-layer-group')); ?>" aria-hidden="true"></i>
                <h3><?= $e($item['title'] ?? ''); ?></h3>
                <p><?= $e($item['description'] ?? ''); ?></p>
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
            <p class="landing-eyebrow">Learning paths</p>
            <h2 id="paths-title"><?= $e($pathsTitle); ?></h2>
            <?php if ($pathsDescription !== ''): ?><p><?= $e($pathsDescription); ?></p><?php endif; ?>
          </div>
          <div class="landing-path-list">
            <?php foreach ($homeCategories as $category): ?>
              <a class="landing-path-item" href="<?= $e($homeBasePath . '/category/' . ($category['category_link'] ?? '')); ?>">
                <span><?= $e($category['category_title'] ?? 'Learning path'); ?></span>
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'experience') && $experienceItems): ?>
      <section class="landing-section landing-experience" aria-labelledby="experience-title">
        <div class="landing-shell landing-experience-grid">
          <div class="landing-section-heading compact">
            <p class="landing-eyebrow">Learning experience</p>
            <h2 id="experience-title"><?= $e($experienceTitle); ?></h2>
            <?php if ($experienceDescription !== ''): ?><p><?= $e($experienceDescription); ?></p><?php endif; ?>
          </div>
          <div class="landing-feature-stack">
            <?php foreach ($experienceItems as $item): ?>
              <article>
                <i class="<?= $e(mmh_landing_icon_class($item['icon'] ?? '', 'fas fa-check-circle')); ?>" aria-hidden="true"></i>
                <div><h3><?= $e($item['title'] ?? ''); ?></h3><p><?= $e($item['description'] ?? ''); ?></p></div>
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
            <p class="landing-eyebrow">Student feedback</p>
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

    <?php if ($homeCoursesEnabled && $homeCourses): ?>
      <section id="courses" class="landing-section landing-pricing" aria-labelledby="courses-title">
        <div class="landing-shell">
          <div class="landing-section-heading">
            <p class="landing-eyebrow">Pricing</p>
            <h2 id="courses-title"><?= $e($homeCoursesHeading); ?></h2>
            <p><?= $e($homeCoursesDescription); ?></p>
          </div>
          <div class="landing-course-grid">
            <?php foreach (array_slice($homeCourses, 0, 6) as $course): ?>
              <?php
                $courseTitle = (string) ($course['course_title'] ?? 'Course');
                $courseDescription = $plain($course['course_description'] ?? '', 150);
                $courseUrl = $homeBasePath . '/course/' . rawurlencode((string) ($course['id'] ?? ''));
                $price = !empty($course['course_price']) ? $course['course_price'] . ' EGP' : 'Free';
                $oldPrice = (!empty($course['preDiscount_course_price']) && (float) $course['preDiscount_course_price'] > (float) ($course['course_price'] ?? 0))
                  ? $course['preDiscount_course_price'] . ' EGP'
                  : '';
              ?>
              <article class="landing-course-card">
                <div>
                  <span class="landing-course-label">Course</span>
                  <h3><?= $e($courseTitle); ?></h3>
                  <?php if ($courseDescription !== ''): ?><p><?= $e($courseDescription); ?></p><?php endif; ?>
                </div>
                <div class="landing-course-footer">
                  <div class="landing-price">
                    <?php if ($oldPrice !== ''): ?><small><?= $e($oldPrice); ?></small><?php endif; ?>
                    <strong><?= $e($price); ?></strong>
                  </div>
                  <a class="landing-button landing-button-secondary small" href="<?= $e($courseUrl); ?>">View course</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if (mmh_landing_section_enabled($site_settings, 'faq') && $faqItems): ?>
      <section class="landing-section landing-faq" aria-labelledby="faq-title">
        <div class="landing-shell landing-faq-grid">
          <div class="landing-section-heading compact">
            <p class="landing-eyebrow">FAQ</p>
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

    <?php if (mmh_landing_section_enabled($site_settings, 'cta') && ($ctaTitle !== '' || $ctaDescription !== '' || ($ctaPrimaryLabel !== '' && $ctaPrimaryUrl !== '') || ($ctaSecondaryLabel !== '' && $ctaSecondaryUrl !== ''))): ?>
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
