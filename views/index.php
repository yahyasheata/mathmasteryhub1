<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
if (isset($_SESSION['username'])) {
  $username = $_SESSION['username'];
} else {
  $username = null;
}

$site_settings = getSiteSettings();
$site_name = $site_settings["website_name"];
$site_description = $site_settings["website_bio"];
$site_icon = $site_settings["website_icon"];
$homeHeroEnabled = mmh_site_setting_truthy($site_settings['home_hero_enabled'] ?? '1');
$homeHeroTitle = str_replace('{site_name}', $site_name, (string) ($site_settings['home_hero_title'] ?? 'Welcome to {site_name}'));
$homeHeroDescription = (string) ($site_settings['home_hero_description'] ?? '');
$homePrimaryLabel = (string) ($site_settings['home_primary_label'] ?? 'Browse Courses');
$homePrimaryUrl = (string) ($site_settings['home_primary_url'] ?? '/courses');
$homeSecondaryLabel = (string) ($site_settings['home_secondary_label'] ?? 'Join the Community');
$homeSecondaryUrl = (string) ($site_settings['home_secondary_url'] ?? '/register');
$homeCoursesEnabled = mmh_site_setting_truthy($site_settings['home_courses_enabled'] ?? '1');
$homeCoursesHeading = (string) ($site_settings['home_courses_heading'] ?? 'Explore Our Courses');
$homeCoursesDescription = (string) ($site_settings['home_courses_description'] ?? 'Browse our available courses and start learning today.');
$homeBasePath = rtrim(mmh_site_public_base_path(), '/');
$homeUrl = static function (string $url) use ($homeBasePath): string {
  return str_starts_with($url, '/') ? $homeBasePath . $url : $url;
};
$homeFaviconUrl = mmh_site_settings_asset_url($site_settings, 'website_icon', 'resources/images/default/favicon.png');

$conn = db();
$categories_query = "SELECT * FROM categories";
$categories_result =  mysqli_query($conn, $categories_query);

if (mysqli_num_rows($categories_result) > 0) {

  $categorie = '';
  while ($categories_data = mysqli_fetch_assoc($categories_result)) {
    $date = date('Y-m-d', strtotime($categories_data['created_at']));
    $categoryImageUrl = htmlspecialchars(mmh_site_public_url((string) ($categories_data['category_image'] ?? '')), ENT_QUOTES, 'UTF-8');
    $categorie .= "

      
<!-- Card 1: Programming with Image -->
<div class='landing-category-card ds-bg-primary rounded-xl overflow-hidden transition duration-300 hover:shadow-xl dark:border'>
  <a href='category/{$categories_data['category_link']}'>
    <div class='landing-category-media h-48 overflow-hidden'>
      <img src='{$categoryImageUrl}' alt='{$categories_data['category_title']}' class='landing-category-image w-full h-full object-cover transition-transform duration-300 hover:scale-105'>
    </div>
  </a>
  <div class='landing-category-panel p-6'>
    <a href='category/{$categories_data['category_link']}'>
      <h3 class='landing-category-title text-xl font-bold mb-4 ds-text-primary'>{$categories_data['category_title']}</h3>
    </a>
    <p class='landing-category-description ds-text-secondary mb-4'>
      {$categories_data['category_description']}
    </p>
    <div class='landing-category-meta flex items-center justify-between'>
      <a href='category/{$categories_data['category_link']}' class='landing-category-link text-secondary hover:text-secondary/80 font-medium flex items-center'>
        Explore
        <i class='fas fa-arrow-right ml-2'></i>
      </a>
      <span class='landing-category-badge bg-secondary/10 text-secondary text-xs px-3 py-1 rounded-full'>
        New
      </span>
    </div>
  </div>
</div>





    ";
  }
}

// Fetch courses
$courses_query = "SELECT * FROM courses WHERE course_status = '1'";
$courses_result = mysqli_query($conn, $courses_query);

$courses = '';
if (mysqli_num_rows($courses_result) > 0) {
  while ($course = mysqli_fetch_assoc($courses_result)) {
    $courseImageUrl = htmlspecialchars(mmh_site_public_url((string) ($course['course_image'] ?? '')), ENT_QUOTES, 'UTF-8');
    $price = $course['course_price'] ? $course['course_price'] . " EGP" : "Free";
    $old_price = ($course['preDiscount_course_price'] && $course['preDiscount_course_price'] > $course['course_price'])
      ? "<span class='line-through ds-text-muted text-sm ml-2'>{$course['preDiscount_course_price']} EGP</span>"
      : "";
    $courses .= "\n      <div class='swiper-slide'>\n        <article class='landing-course-card ds-bg-primary rounded-xl overflow-hidden transition duration-300 hover:shadow-xl dark:border'>\n          <a href='{$homeBasePath}/course/{$course['id']}' class='landing-course-media-link'>\n            <div class='landing-course-media h-48 overflow-hidden'>\n              <img src='{$courseImageUrl}' alt='{$course['course_title']}' class='landing-course-image w-full h-full object-cover transition-transform duration-300 hover:scale-105'>\n            </div>\n          </a>\n          <div class='landing-course-panel p-6'>\n            <a href='{$homeBasePath}/course/{$course['id']}'><h3 class='landing-course-title text-xl font-bold mb-4 ds-text-primary'>{$course['course_title']}</h3> </a>\n            <p class='landing-course-description ds-text-secondary mb-4'>\n              {$course['course_description']}\n            </p>\n            <div class='landing-course-meta flex items-center justify-between'>\n              <div class='landing-course-price'>\n                $old_price\n                <span class='text-secondary font-bold'>{$price}</span>\n              </div>\n              <a href='course/{$course['id']}' class='landing-course-link text-secondary hover:text-secondary/80 font-medium flex items-center'>\n                View Course\n                <i class='fas fa-arrow-right ml-2'></i>\n              </a>\n            </div>\n          </div>\n        </article>\n      </div>\n    ";
  }
}

trackTraffic();




?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- إعدادات SEO الأساسية -->
  <title><?= $site_name; ?></title>

  <?= $metatags . "\n" ?>
  <?= $keywords . "\n" ?>

  <?= $openGraph ?>

  <?= $schema ?>


  <!-- Favicon -->
  <link rel="icon" type="image/png" href="<?=$homeFaviconUrl?>">
  <link rel="apple-touch-icon" href="<?=$homeFaviconUrl?>">

  <!-- TailwindCSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&display=swap" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/fontawsome5.min.css')?>">

  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            primary: 'var(--primary)',
            secondary: 'var(--secondary)',
            dark: 'var(--bg-primary)',
            light: 'var(--surface)'
          },
          fontFamily: {
            tajawal: ['Tajawal', 'sans-serif']
          }
        }
      }
    }
  </script>
  <style>
    body {
      font-family: 'Tajawal', sans-serif;
    }

    .dark .dark-invert {
      filter: invert(1);
    }

    /* تحسين الانتقالات للوضع الداكن */
    .dark-transition {
      transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    }
  </style>
    <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/design-system.css')?>" data-design-system="mathhub" />
  <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/public-landing.css')?>" />
</head>

<body class='font-tajawal ds-bg-primary ds-text-primary transition-colors duration-300'>

  <div class="public-landing">
    <!-- Navigation -->
    <?php include $_SERVER['DOCUMENT_ROOT'] . dirname($_SERVER['SCRIPT_NAME']) . "/views/public/layouts/aside.php"; ?>

  <!-- Hero Section -->
  <?php if ($homeHeroEnabled): ?>
  <section id="home" class='landing-hero relative ds-surface py-20 lg:py-32 overflow-hidden transition-colors duration-300'>
    <div class="landing-hero-backdrop absolute inset-0 overflow-hidden" aria-hidden="true">
      <div class="absolute left-0 bottom-0 w-full h-1/2 bg-gradient-to-t from-[var(--surface)] to-transparent dark:from-[var(--bg-primary)] dark:to-transparent"></div>
      <div class="absolute -left-64 -bottom-32 w-96 h-96 bg-secondary/20 blur-3xl rounded-full"></div>
      <div class="absolute -right-64 -top-32 w-96 h-96 bg-primary/20 blur-3xl rounded-full"></div>
      <span class="landing-hero-orbit landing-hero-orbit--one"></span>
      <span class="landing-hero-orbit landing-hero-orbit--two"></span>
      <span class="landing-hero-curve"></span>
    </div>

    <div class="landing-hero-shell container mx-auto px-4 relative">
      <div class="landing-hero-copy max-w-3xl mx-auto text-center mb-12">
        <h1 class='landing-hero-title text-4xl md:text-5xl lg:text-6xl font-extrabold mb-6 ds-text-primary'>
          <?= htmlspecialchars($homeHeroTitle, ENT_QUOTES, 'UTF-8'); ?>
        </h1>
        <p class='landing-hero-description text-xl ds-text-secondary mb-8 leading-relaxed'>
          <?= htmlspecialchars($homeHeroDescription, ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div class="landing-hero-actions flex flex-col sm:flex-row justify-center gap-4">
          <a href="<?= htmlspecialchars($homeUrl($homePrimaryUrl), ENT_QUOTES, 'UTF-8'); ?>" class='landing-hero-primary-action bg-primary ds-text-inverse px-8 py-3 rounded-lg hover:bg-primary/90 transition duration-200 font-bold'>
            <i class="fas fa-graduation-cap ml-2"></i>
            <?= htmlspecialchars($homePrimaryLabel, ENT_QUOTES, 'UTF-8'); ?>
          </a>
          <a href="<?= htmlspecialchars($homeUrl($homeSecondaryUrl), ENT_QUOTES, 'UTF-8'); ?>" class='landing-hero-secondary-action ds-surface-muted ds-text-primary px-8 py-3 rounded-lg hover:bg-[var(--surface-hover)] transition duration-200 font-bold'>
            <i class="fas fa-users ml-2"></i>
            <?= htmlspecialchars($homeSecondaryLabel, ENT_QUOTES, 'UTF-8'); ?>
          </a>
        </div>
      </div>

      <div class="landing-hero-highlights grid grid-cols-1 md:grid-cols-3 gap-6 mt-16">
        <div class='landing-highlight-card ds-surface border ds-border rounded-xl p-6 shadow-lg shadow-primary/5 hover:shadow-primary/10 transition duration-300 dark:shadow-primary/5'>
          <div class="flex items-center mb-4">
            <div class="landing-highlight-icon w-12 h-12 bg-blue-100 text-primary flex items-center justify-center rounded-full">
              <i class="fas fa-chalkboard-teacher text-xl"></i>
            </div>
            <div class="landing-highlight-copy mr-4">
              <h3 class="font-bold text-xl">Interactive Courses</h3>
              <p class='ds-text-muted'>Hands-on Learning</p>
            </div>
          </div>
          <p class='ds-text-secondary'>
            Interactive educational content covering various disciplines and fields, with quizzes and practical exercises.
          </p>
        </div>

        <div class='landing-highlight-card ds-surface border ds-border rounded-xl p-6 shadow-lg shadow-secondary/5 hover:shadow-secondary/10 transition duration-300 dark:shadow-secondary/5'>
          <div class="flex items-center mb-4">
            <div class="landing-highlight-icon w-12 h-12 bg-green-100 text-secondary flex items-center justify-center rounded-full">
              <i class="fas fa-certificate text-xl"></i>
            </div>
            <div class="landing-highlight-copy mr-4">
              <h3 class="font-bold text-xl">Accredited Certificates</h3>
              <p class='ds-text-muted'>For All Courses</p>
            </div>
          </div>
          <p class='ds-text-secondary'>
            Obtain accredited certificates after successfully completing courses to enhance your CV and career opportunities.
          </p>
        </div>

        <div class='landing-highlight-card ds-surface border ds-border rounded-xl p-6 shadow-lg shadow-purple-500/5 hover:shadow-purple-500/10 transition duration-300 dark:shadow-purple-500/5'>
          <div class="flex items-center mb-4">
            <div class="landing-highlight-icon w-12 h-12 bg-purple-100 text-purple-500 flex items-center justify-center rounded-full">
              <i class="fas fa-users text-xl"></i>
            </div>
            <div class="landing-highlight-copy mr-4">
              <h3 class="font-bold text-xl">Active Educational Community</h3>
              <p class='ds-text-muted'>Connect & Support</p>
            </div>
          </div>
          <p class='ds-text-secondary'>
            Join our active community to exchange knowledge, ask questions, and participate in educational discussions.
          </p>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Courses Section -->
  <?php if ($homeCoursesEnabled): ?>
  <section id="courses" class='landing-section landing-courses py-20 ds-surface transition-colors duration-300' dir="ltr">
    <div class="container mx-auto px-4">
      <div class="landing-section-heading max-w-3xl mx-auto text-center mb-16">
        <span class="landing-section-eyebrow inline-block text-primary font-semibold mb-2">Courses</span>
        <h2 class='landing-section-title text-3xl md:text-4xl font-bold ds-text-primary mb-4'><?= htmlspecialchars($homeCoursesHeading, ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class='landing-section-description ds-text-secondary'><?= htmlspecialchars($homeCoursesDescription, ENT_QUOTES, 'UTF-8'); ?></p>
      </div>
      <div class="swiper courses-swiper">
        <div class="swiper-wrapper">
          <?= $courses ?>
        </div>
        <!-- Add Arrows -->
        <div class="swiper-button-next"></div>
        <div class="swiper-button-prev"></div>
        <!-- Add Pagination -->
        <div class="swiper-pagination"></div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Categories Section -->
  <section id="categories" class='landing-section landing-categories py-20 ds-surface transition-colors duration-300' dir="ltr">
    <div class="container mx-auto px-4">
      <div class="landing-section-heading max-w-3xl mx-auto text-center mb-16">
        <span class="landing-section-eyebrow inline-block text-secondary font-semibold mb-2">Course Categories</span>
        <h2 class='landing-section-title text-3xl md:text-4xl font-bold ds-text-primary mb-4'>Start Your <span>Learning Journey</span></h2>
        <p class='landing-section-description ds-text-secondary'>
          Explore our available categories and choose what matches your interests, from programming to AI and design.
        </p>
      </div>
      <div class="landing-category-grid grid grid-cols-1 md:grid-cols-3 gap-8">
        <?= $categorie ?>
      </div>
    </div>
  </section>

  <!-- Swiper.js CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      if (!document.querySelector('.courses-swiper')) return;
      new Swiper('.courses-swiper', {
        slidesPerView: 1,
        spaceBetween: 24,
        loop: true,
        navigation: {
          nextEl: '.swiper-button-next',
          prevEl: '.swiper-button-prev',
        },
        pagination: {
          el: '.swiper-pagination',
          clickable: true,
        },
        autoplay: {
          delay: 3500,
          disableOnInteraction: false,
        },
        breakpoints: {
          640: {
            slidesPerView: 1
          },
          768: {
            slidesPerView: 2
          },
          1024: {
            slidesPerView: 3
          }
        }
      });
    });
  </script>

  <!-- LMS Features Section -->
  <section id="features" class='landing-section landing-features py-24 ds-bg-primary transition-colors duration-300' dir="ltr">
    <div class="container mx-auto px-4">
      <div class="landing-section-heading max-w-4xl mx-auto text-center mb-16">
        <h2 class='landing-section-title text-4xl font-extrabold ds-text-primary mb-4'>Powerful <span>LMS Features</span></h2>
        <p class='landing-section-description ds-text-secondary text-lg'>
          Everything you need to manage, deliver, and enhance your e-learning experience.
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-10">

        <!-- Feature 1 -->
        <div class='landing-feature-card ds-surface rounded-xl p-6 shadow-md hover:shadow-xl transition'>
          <div class="landing-feature-icon text-primary text-3xl mb-4">
            <i class="fas fa-book-open"></i>
          </div>
          <h3 class='text-xl font-semibold ds-text-primary mb-2'>Interactive Courses</h3>
          <p class='ds-text-secondary'>
            Deliver structured lessons with videos, documents, and downloadable content.
          </p>
        </div>

        <!-- Feature 2 -->
        <div class='landing-feature-card ds-surface rounded-xl p-6 shadow-md hover:shadow-xl transition'>
          <div class="landing-feature-icon text-green-500 text-3xl mb-4">
            <i class="fas fa-question-circle"></i>
          </div>
          <h3 class='text-xl font-semibold ds-text-primary mb-2'>Quizzes & Exams</h3>
          <p class='ds-text-secondary'>
            Add timed quizzes and assessments to test student understanding.
          </p>
        </div>

        <!-- Feature 3 -->
        <div class='landing-feature-card ds-surface rounded-xl p-6 shadow-md hover:shadow-xl transition'>
          <div class="landing-feature-icon text-yellow-500 text-3xl mb-4">
            <i class="fas fa-certificate"></i>
          </div>
          <h3 class='text-xl font-semibold ds-text-primary mb-2'>Certificates</h3>
          <p class='ds-text-secondary'>
            Award completion certificates automatically to students.
          </p>
        </div>

        <!-- Feature 4 -->
        <div class='landing-feature-card ds-surface rounded-xl p-6 shadow-md hover:shadow-xl transition'>
          <div class="landing-feature-icon text-indigo-500 text-3xl mb-4">
            <i class="fas fa-chart-line"></i>
          </div>
          <h3 class='text-xl font-semibold ds-text-primary mb-2'>Progress Tracking</h3>
          <p class='ds-text-secondary'>
            Visual progress bars and dashboards for students and admins.
          </p>
        </div>

        <!-- Feature 5 -->
        <div class='landing-feature-card ds-surface rounded-xl p-6 shadow-md hover:shadow-xl transition'>
          <div class="landing-feature-icon text-red-500 text-3xl mb-4">
            <i class="fas fa-comments"></i>
          </div>
          <h3 class='text-xl font-semibold ds-text-primary mb-2'>Discussion Forums</h3>
          <p class='ds-text-secondary'>
            Built-in student community and Q&A system for engagement.
          </p>
        </div>

        <!-- Feature 6 -->
        <div class='landing-feature-card ds-surface rounded-xl p-6 shadow-md hover:shadow-xl transition'>
          <div class="landing-feature-icon text-pink-500 text-3xl mb-4">
            <i class="fas fa-user-shield"></i>
          </div>
          <h3 class='text-xl font-semibold ds-text-primary mb-2'>Admin & Instructor Panel</h3>
          <p class='ds-text-secondary'>
            Manage courses, students, reports, and content from a single dashboard.
          </p>
        </div>

      </div>
    </div>
  </section>

  <!-- API Services Section -->


    <?php include $_SERVER['DOCUMENT_ROOT'] . dirname($_SERVER['SCRIPT_NAME']) . "/views/public/layouts/footer.php"; ?>
  </div>




</body>

</html>
