<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

$username = $_SESSION['username'] ?? null;
$site_settings = getSiteSettings();
$site_name = (string) ($site_settings['website_name'] ?? 'Math Mastery Hub');
$site_description = (string) ($site_settings['website_bio'] ?? '');

$homeHeroEnabled = mmh_site_setting_truthy($site_settings['home_hero_enabled'] ?? '1');
$homeHeroTitle = trim(str_replace('{site_name}', $site_name, (string) ($site_settings['home_hero_title'] ?? 'Welcome to {site_name}')));
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
$homeCategories = [];
$categories_result = mysqli_query($conn, 'SELECT * FROM categories ORDER BY created_at DESC, id DESC LIMIT 6');
if ($categories_result) {
    while ($row = mysqli_fetch_assoc($categories_result)) $homeCategories[] = $row;
}

$homeCourses = [];
$courses_result = mysqli_query($conn, "SELECT * FROM courses WHERE course_status = '1' ORDER BY created_at DESC, id DESC");
if ($courses_result) {
    while ($row = mysqli_fetch_assoc($courses_result)) $homeCourses[] = $row;
}

$landingStats = [
    ['value' => max(1, count($homeCourses)), 'label' => 'Premium learning spaces'],
    ['value' => max(1, count($homeCategories)), 'label' => 'Focused learning paths'],
    ['value' => 'Weekly', 'label' => 'homework and progress review'],
];

if ($homeHeroDescription === '') {
    $homeHeroDescription = $site_description !== ''
        ? $site_description
        : 'A calm, structured online learning space for IGCSE mathematics students, with live teaching, recordings, homework, feedback and progress reports in one place.';
}
if ($homeHeroTitle === '') $homeHeroTitle = 'Math learning, organized around real teaching.';

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
  <link rel="stylesheet" href="<?= $e(mmh_site_public_url('resources/css/public-landing.css')); ?>">
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

    <section class="landing-stats" aria-label="Trusted by students">
      <div class="landing-shell landing-stats-grid">
        <?php foreach ($landingStats as $stat): ?>
          <article>
            <strong><?= $e($stat['value']); ?></strong>
            <span><?= $e($stat['label']); ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="landing-section landing-why" aria-labelledby="why-title">
      <div class="landing-shell">
        <div class="landing-section-heading">
          <p class="landing-eyebrow">Why Math Mastery Hub</p>
          <h2 id="why-title">A focused learning environment, not another noisy dashboard.</h2>
          <p>Everything is organized around what students actually need each week: the lesson, the recording, the homework and the feedback.</p>
        </div>
        <div class="landing-card-grid three">
          <article class="landing-info-card">
            <i class="fas fa-layer-group" aria-hidden="true"></i>
            <h3>Structured by teaching week</h3>
            <p>Course sections follow the real class sequence, so students always know where to continue.</p>
          </article>
          <article class="landing-info-card">
            <i class="fas fa-chalkboard-teacher" aria-hidden="true"></i>
            <h3>Built for live learning</h3>
            <p>Live sessions, recordings and resources stay connected to the same course experience.</p>
          </article>
          <article class="landing-info-card">
            <i class="fas fa-user-graduate" aria-hidden="true"></i>
            <h3>Simple for students</h3>
            <p>Clear actions, calm layouts and accessible resources help students focus on mathematics.</p>
          </article>
        </div>
      </div>
    </section>

    <?php if ($homeCategories): ?>
      <section class="landing-section landing-paths" aria-labelledby="paths-title">
        <div class="landing-shell landing-split">
          <div class="landing-section-heading compact">
            <p class="landing-eyebrow">Learning paths</p>
            <h2 id="paths-title">Browse by what you are studying.</h2>
            <p>Start with the path that matches your syllabus, level or current goal.</p>
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

    <section class="landing-section landing-experience" aria-labelledby="experience-title">
      <div class="landing-shell landing-experience-grid">
        <div class="landing-section-heading compact">
          <p class="landing-eyebrow">Learning experience</p>
          <h2 id="experience-title">One place for lessons, practice and parent-friendly progress.</h2>
          <p>The platform supports the live course instead of replacing it. Students can review, submit and continue without hunting for links.</p>
        </div>
        <div class="landing-feature-stack">
          <article>
            <i class="fas fa-clipboard-list" aria-hidden="true"></i>
            <div><h3>Homework</h3><p>Upload work, receive feedback and keep every submission connected to the correct lesson.</p></div>
          </article>
          <article>
            <i class="fas fa-play-circle" aria-hidden="true"></i>
            <div><h3>Recorded lessons</h3><p>Open recordings inside the LMS viewer when supported, with protected access.</p></div>
          </article>
          <article>
            <i class="fas fa-broadcast-tower" aria-hidden="true"></i>
            <div><h3>Live sessions</h3><p>Keep live teaching connected to weekly sections and student attendance evidence.</p></div>
          </article>
          <article>
            <i class="fas fa-chart-bar" aria-hidden="true"></i>
            <div><h3>Progress reports</h3><p>Show attendance, resources opened, homework status and grades in a readable format.</p></div>
          </article>
        </div>
      </div>
    </section>

    <section class="landing-section landing-testimonials" aria-labelledby="testimonials-title">
      <div class="landing-shell">
        <div class="landing-section-heading">
          <p class="landing-eyebrow">Student feedback</p>
          <h2 id="testimonials-title">Designed to make the next step obvious.</h2>
        </div>
        <div class="landing-card-grid three">
          <blockquote class="landing-quote-card">
            <p>“I can find the lesson recording and homework without asking where the links are.”</p>
            <footer>Grade 10 student</footer>
          </blockquote>
          <blockquote class="landing-quote-card">
            <p>“The course page feels organized by the way we study every week.”</p>
            <footer>IGCSE mathematics student</footer>
          </blockquote>
          <blockquote class="landing-quote-card">
            <p>“The progress report makes it clear what is done and what still needs attention.”</p>
            <footer>Parent feedback</footer>
          </blockquote>
        </div>
      </div>
    </section>

    <?php if ($homeCoursesEnabled): ?>
      <section id="courses" class="landing-section landing-pricing" aria-labelledby="courses-title">
        <div class="landing-shell">
          <div class="landing-section-heading">
            <p class="landing-eyebrow">Pricing</p>
            <h2 id="courses-title"><?= $e($homeCoursesHeading); ?></h2>
            <p><?= $e($homeCoursesDescription); ?></p>
          </div>
          <?php if ($homeCourses): ?>
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
                    <p><?= $e($courseDescription !== '' ? $courseDescription : 'A structured Math Mastery Hub learning space.'); ?></p>
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
          <?php else: ?>
            <div class="landing-empty-state">
              <i class="fas fa-book" aria-hidden="true"></i>
              <strong>Courses will appear here soon.</strong>
              <p>Published courses are displayed automatically once available.</p>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="landing-section landing-faq" aria-labelledby="faq-title">
      <div class="landing-shell landing-faq-grid">
        <div class="landing-section-heading compact">
          <p class="landing-eyebrow">FAQ</p>
          <h2 id="faq-title">Simple answers before you begin.</h2>
        </div>
        <div class="landing-faq-list">
          <details>
            <summary>Can I still access free resources after enrolling?</summary>
            <p>Yes. Free learning resources remain available, and enrolled courses add premium course spaces on top.</p>
          </details>
          <details>
            <summary>Are recordings and homework kept together?</summary>
            <p>Yes. Course sections are organized so lessons, recordings and homework stay connected to the same week.</p>
          </details>
          <details>
            <summary>Can parents understand progress quickly?</summary>
            <p>Weekly reports are designed to show attendance, homework and grades in a clean format.</p>
          </details>
        </div>
      </div>
    </section>

    <section class="landing-final-cta" aria-labelledby="final-cta-title">
      <div class="landing-shell">
        <h2 id="final-cta-title">Start with a clear learning path.</h2>
        <p>Browse the course spaces and choose the one that matches your current mathematics goal.</p>
        <a class="landing-button landing-button-primary" href="<?= $e($homeBasePath . '/courses'); ?>">
          <i class="fas fa-arrow-right" aria-hidden="true"></i>
          <span>Browse Courses</span>
        </a>
      </div>
    </section>

    <?php include $_SERVER['DOCUMENT_ROOT'] . dirname($_SERVER['SCRIPT_NAME']) . '/views/public/layouts/footer.php'; ?>
  </div>
</body>
</html>
