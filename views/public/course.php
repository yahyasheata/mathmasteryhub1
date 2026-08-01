<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/learning_schema.php';
require_once 'inc/PastPapers.php';
require_once 'inc/PublicCourse.php';
require_once 'views/partials/past-papers-list.php';
$pageName = "courses";
$username = $_SESSION['username'] ?? '';

$site_settings = getSiteSettings();
$site_name = $site_settings["website_name"];

$conn = db();
$resolved_course = mmh_public_course_find($conn, $courseId ?? null);
if (!$resolved_course) {
  http_response_code(404);
  exit('Course not found.');
}
$courseId = (int) ($resolved_course['id'] ?? 0);
$canonicalCourseId = (string) ($resolved_course['course_id'] ?? '');
if ($courseId <= 0 || $canonicalCourseId === '') {
  http_response_code(404);
  exit('Course not found.');
}

$structured_item_columns = ['section_id', 'status', 'sort_order'];
$structured_section_columns = ['section_id', 'course_id', 'title', 'description', 'section_type', 'sort_order', 'status'];
$has_structured_preview = mmh_table_exists($conn, 'course_sections');

foreach ($structured_item_columns as $column) {
  $has_structured_preview = $has_structured_preview && mmh_column_exists($conn, 'course_items', $column);
}
foreach ($structured_section_columns as $column) {
  $has_structured_preview = $has_structured_preview && mmh_column_exists($conn, 'course_sections', $column);
}

if ($has_structured_preview) {
  $courses_query = "SELECT *,
      courses.id AS cid,
      courses.course_id AS public_course_id,
      course_items.id AS iid,
      categories.category_title AS preview_category_title,
      categories.category_link AS preview_category_link,
      course_sections.section_id AS preview_section_id,
      course_sections.title AS preview_section_title,
      course_sections.description AS preview_section_description,
      course_sections.section_type AS preview_section_type,
      course_sections.sort_order AS preview_section_sort_order
      FROM courses
      LEFT JOIN categories ON courses.course_category = categories.id
      LEFT JOIN course_items ON courses.course_id = course_items.course_id
          AND (course_items.status IS NULL OR course_items.status = '' OR course_items.status = 'published')
      LEFT JOIN course_sections ON course_items.section_id = course_sections.section_id
          AND course_sections.course_id = courses.course_id
          AND (course_sections.status IS NULL OR course_sections.status = '' OR course_sections.status = 'published')
      WHERE courses.course_id = ?
      ORDER BY
          CASE WHEN course_items.section_id IS NULL OR course_items.section_id = '' THEN 0 ELSE 1 END,
          course_sections.sort_order ASC,
          course_items.sort_order ASC,
          course_items.page_order ASC,
          course_items.id ASC";
} else {
  $courses_query = "SELECT *,
      courses.id AS cid,
      courses.course_id AS public_course_id,
      course_items.id AS iid,
      categories.category_title AS preview_category_title,
      categories.category_link AS preview_category_link,
      NULL AS preview_section_id,
      NULL AS preview_section_title,
      NULL AS preview_section_description,
      NULL AS preview_section_type,
      NULL AS preview_section_sort_order
      FROM courses
      LEFT JOIN categories ON courses.course_category = categories.id
      LEFT JOIN course_items ON courses.course_id = course_items.course_id
      WHERE courses.course_id = ?
      ORDER BY course_items.page_order ASC, course_items.id ASC";
}
$courses_stmt = $conn->prepare($courses_query);
if (!$courses_stmt) {
  http_response_code(500);
  exit('Course could not be loaded.');
}
$courses_stmt->bind_param('s', $canonicalCourseId);
$courses_stmt->execute();
$coures_result = $courses_stmt->get_result();

$course = null;
$course_sections = [];
$course_summary = [
  'lessons' => 0,
  'recordings' => 0,
  'assignments' => 0,
  'files' => 0,
  'duration_minutes' => 0,
];
$course_content = '';
$has_items = false;

function public_course_preview_lesson_meta(array $item): array {
  $template = strtolower(trim((string)($item['template_type'] ?? '')));
  $type = strtolower(trim((string)($item['item_type'] ?? '')));

  $map = [
    'recording' => ['Recording', 'fas fa-play-circle', 'recording'],
    'notes' => ['Notes', 'fas fa-file-alt', 'notes'],
    'classified_assignment' => ['Homework', 'fas fa-clipboard-check', 'assignment'],
    'assignment_model_answer' => ['Model Answer', 'fas fa-graduation-cap', 'model-answer'],
    'custom_lesson' => ['Lesson', 'fas fa-book-open', 'custom'],
    'video' => ['Recording', 'fas fa-play-circle', 'recording'],
    'file' => ['File', 'fas fa-file-pdf', 'file'],
    'quiz' => ['Assessment', 'fas fa-clipboard-list', 'assessment'],
  ];

  return $map[$template] ?? $map[$type] ?? ['Lesson', 'fas fa-lock', 'legacy'];
}

function public_course_preview_section_icon(string $type): string {
  $icons = [
    'week' => 'fas fa-calendar-alt',
    'unit' => 'fas fa-layer-group',
    'chapter' => 'fas fa-book-open',
    'module' => 'fas fa-cubes',
    'revision' => 'fas fa-undo',
    'practice' => 'fas fa-ruler-combined',
    'resources' => 'fas fa-folder-open',
    'live_session' => 'fas fa-video',
    'office_hours' => 'fas fa-users',
    'bonus' => 'fas fa-gift',
  ];

  return $icons[$type] ?? 'fas fa-layer-group';
}

function public_course_preview_duration(int $minutes): string {
  if ($minutes <= 0) {
    return 'Self-paced';
  }

  $hours = intdiv($minutes, 60);
  $remaining = $minutes % 60;
  if ($hours > 0 && $remaining > 0) {
    return $hours . 'h ' . $remaining . 'm';
  }

  return $hours > 0 ? $hours . 'h' : $remaining . 'm';
}

if(mysqli_num_rows($coures_result) > 0){
  while($courses_data = mysqli_fetch_assoc($coures_result)){
    if ($course === null) {
      $course = $courses_data;
    }

    if(!empty($courses_data['iid'])) {
      $has_items = true;
      [$item_label, $item_icon, $item_class] = public_course_preview_lesson_meta($courses_data);
      $section_key = !empty($courses_data['section_id']) ? (string)$courses_data['section_id'] : '__general__';
      $section_title = $section_key === '__general__' ? 'General' : trim((string)($courses_data['preview_section_title'] ?? ''));
      $section_type = strtolower(trim((string)($courses_data['preview_section_type'] ?? 'lecture')));

      if (!isset($course_sections[$section_key])) {
        $course_sections[$section_key] = [
          'title' => $section_title !== '' ? $section_title : 'Section',
          'description' => trim((string)($courses_data['preview_section_description'] ?? '')),
          'type' => $section_key === '__general__' ? 'general' : $section_type,
          'items' => [],
          'assignments' => 0,
          'duration_minutes' => 0,
        ];
      }

      $duration = max(0, (int)($courses_data['duration_minutes'] ?? 0));
      $course_sections[$section_key]['items'][] = [
        'title' => htmlspecialchars($courses_data['item_title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'label' => $item_label,
        'icon' => $item_icon,
        'class' => $item_class,
        'duration' => $duration,
      ];
      $course_sections[$section_key]['duration_minutes'] += $duration;
      $course_summary['lessons']++;
      $course_summary['duration_minutes'] += $duration;

      $template = strtolower(trim((string)($courses_data['template_type'] ?? '')));
      $item_type = strtolower(trim((string)($courses_data['item_type'] ?? '')));
      if ($template === 'recording' || $item_type === 'video') {
        $course_summary['recordings']++;
      }
      if ($template === 'classified_assignment' || $item_type === 'quiz') {
        $course_summary['assignments']++;
        $course_sections[$section_key]['assignments']++;
      }
      if ($item_type === 'file') {
        $course_summary['files']++;
      }
    }
  }
} else {
  $course_content = "<div class='public-preview-empty'><i class='fas fa-info-circle' aria-hidden='true'></i><p>No course details are available at the moment.</p><a href='/'>Back to home</a></div>";
}

if ($course !== null && $has_items) {
  foreach ($course_sections as $index => $section) {
    $item_count = count($section['items']);
    $assignment_text = $section['assignments'] === 1 ? '1 homework' : $section['assignments'] . ' homework';
    $duration_text = public_course_preview_duration($section['duration_minutes']);
    $section_icon = public_course_preview_section_icon($section['type']);
    $lesson_rows = '';

    foreach ($section['items'] as $item) {
      $duration = $item['duration'] > 0 ? '<span class="public-preview-lesson-duration"><i class="far fa-clock" aria-hidden="true"></i>' . public_course_preview_duration($item['duration']) . '</span>' : '';
      $lesson_rows .= "
        <article class='public-preview-lesson public-preview-lesson--{$item['class']}'>
          <span class='public-preview-lesson-icon' aria-hidden='true'><i class='{$item['icon']}'></i></span>
          <div class='public-preview-lesson-copy'>
            <span class='public-preview-lesson-label'>{$item['label']}</span>
            <h3>{$item['title']}</h3>
          </div>
          <div class='public-preview-lesson-state'>
            $duration
            <span class='public-preview-lock'><i class='fas fa-lock' aria-hidden='true'></i><span>Enrollment required</span></span>
          </div>
        </article>";
    }

    $is_open = $index === array_key_first($course_sections) ? ' open' : '';
    $section_description = $section['description'] !== '' ? '<p>' . htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8') . '</p>' : '';
    $course_content .= "
      <details class='public-preview-section'{$is_open}>
        <summary>
          <span class='public-preview-section-icon' aria-hidden='true'><i class='{$section_icon}'></i></span>
          <span class='public-preview-section-copy'>
            <span class='public-preview-section-eyebrow'>{$section['title']}</span>
            $section_description
          </span>
          <span class='public-preview-section-meta'>
            <span>{$item_count} " . ($item_count === 1 ? 'lesson' : 'lessons') . "</span>
            <span>{$assignment_text}</span>
            <span>{$duration_text}</span>
          </span>
          <span class='public-preview-section-chevron' aria-hidden='true'><i class='fas fa-chevron-down'></i></span>
        </summary>
        <div class='public-preview-section-progress' aria-hidden='true'><span></span></div>
        <div class='public-preview-lessons'>{$lesson_rows}</div>
      </details>";
  }
}

if ($course !== null && !$has_items) {
  $course_content = "<div class='public-preview-empty'><i class='fas fa-info-circle' aria-hidden='true'></i><p>Course content will be available soon.</p><a href='#enroll'>Check enrollment options</a></div>";
}

$course_past_papers = [];
$course_past_resources = [];
$course_public_id = $course['public_course_id'] ?? ($course['course_id'] ?? '');
if ($course !== null && !empty($course_public_id)) {
  mmh_past_ensure_schema($conn);
  $course_past_papers = mmh_past_course_preview_papers($conn, $course_public_id, 3);
  $course_past_resources = mmh_past_resources_for_papers($conn, array_column($course_past_papers, 'paper_id'), true);
}
$public_user_id = 0;
$public_course_enrolled = false;
if ($username !== '') {
  $public_user = getUserInfo($username);
  $public_user_id = (int) ($public_user->user_id ?? 0);
  $public_course_enrolled = mmh_public_course_enrolled($conn, $public_user_id, (string) ($course['course_id'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=$categorie_title ?? 'Course Details'?> | <?=$site_name;?></title>
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
        body { font-family: 'Tajawal', sans-serif; }
        .dark .dark-invert { filter: invert(1); }
        .dark-transition { transition: background-color 0.3s, color 0.3s, border-color 0.3s, box-shadow 0.3s; }
    </style>
    <link rel="stylesheet" href="<?=$baseUrl?>/resources/css/design-system.css" data-design-system="mathhub" />
    <link rel="stylesheet" href="<?=rtrim($baseUrl, '/')?>/resources/css/public-course-preview.css" />
    <link rel="stylesheet" href="<?=rtrim($baseUrl, '/')?>/resources/css/past-papers-frontend.css" />
</head>
<body class='font-tajawal ds-bg-primary ds-text-primary transition-colors duration-300'>
<?php include $_SERVER['DOCUMENT_ROOT'].dirname($_SERVER['SCRIPT_NAME'])."/views/public/layouts/aside.php"; ?>

<?php if ($course !== null):
  $course_title = htmlspecialchars($course['course_title'] ?? '', ENT_QUOTES, 'UTF-8');
  $course_description = htmlspecialchars($course['course_description'] ?? '', ENT_QUOTES, 'UTF-8');
  $raw_category_title = trim((string)($course['preview_category_title'] ?? ''));
  $raw_category_link = trim((string)($course['preview_category_link'] ?? ''));
  $course_category = htmlspecialchars($raw_category_title !== '' ? $raw_category_title : 'Course', ENT_QUOTES, 'UTF-8');
  $courses_index_url = rtrim($baseUrl, '/') . '/#courses';
  $category_url = $raw_category_link !== '' ? rtrim($baseUrl, '/') . '/category/' . rawurlencode($raw_category_link) : '';
  $breadcrumb_trail = '<a href="' . htmlspecialchars($courses_index_url, ENT_QUOTES, 'UTF-8') . '">Courses</a>';
  if ($raw_category_title !== '' && $category_url !== '') {
    $breadcrumb_trail .= '<i class="fas fa-chevron-right" aria-hidden="true"></i><a href="' . htmlspecialchars($category_url, ENT_QUOTES, 'UTF-8') . '">' . $course_category . '</a>';
  }
  if ($course_title !== '') {
    $breadcrumb_trail .= '<i class="fas fa-chevron-right" aria-hidden="true"></i><span>' . $course_title . '</span>';
  } elseif ($raw_category_title !== '' && $category_url === '') {
    $breadcrumb_trail .= '<i class="fas fa-chevron-right" aria-hidden="true"></i><span>' . $course_category . '</span>';
  }
  $instructor = trim((string)($course['course_teacher'] ?? $course['teacher_name'] ?? ''));
  $instructor = htmlspecialchars($instructor !== '' ? $instructor : 'Math Mastery Hub Instructor', ENT_QUOTES, 'UTF-8');
  $study_mode = trim((string)($course['course_type'] ?? $course['study_mode'] ?? ''));
  $study_mode = htmlspecialchars($study_mode !== '' ? $study_mode : 'Course Preview', ENT_QUOTES, 'UTF-8');
  $updated_source = $course['updated_at'] ?? $course['created_at'] ?? '';
  $updated = $updated_source !== '' ? date('M j, Y', strtotime($updated_source)) : 'Recently updated';
  $image_path = ltrim((string)($course['course_image'] ?? ''), '/');
  $image_url = htmlspecialchars(mmh_site_public_url($image_path), ENT_QUOTES, 'UTF-8');
  $price = htmlspecialchars($course['course_price'] ?? '', ENT_QUOTES, 'UTF-8');
  $pre_discount = !empty($course['preDiscount_course_price']) ? htmlspecialchars($course['preDiscount_course_price'], ENT_QUOTES, 'UTF-8') . ' EGP' : '';
  $lesson_count = $course_summary['lessons'];
  $study_time = public_course_preview_duration($course_summary['duration_minutes']);
  $course_checkout_url = htmlspecialchars(mmh_public_course_url($baseUrl, $course, '/checkout'), ENT_QUOTES, 'UTF-8');
  $continue_course_url = htmlspecialchars(rtrim((string) $baseUrl, '/') . '/user/course/' . rawurlencode((string) ($course['course_id'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<main class="public-course-preview">
  <section class="public-course-hero">
    <div class="public-course-shell public-course-hero-shell">
      <div class="public-course-hero-copy">
        <nav class="public-course-breadcrumb" aria-label="Breadcrumb">
          <?=$breadcrumb_trail?>
        </nav>
        <div class="public-course-badges">
          <span class="public-course-badge public-course-badge--category"><i class="fas fa-folder-open" aria-hidden="true"></i><?=$course_category?></span>
          <span class="public-course-badge public-course-badge--mode"><i class="fas fa-circle" aria-hidden="true"></i><?=$study_mode?></span>
        </div>
        <h1><?=$course_title?></h1>
        <p class="public-course-description"><?=$course_description?></p>
        <div class="public-course-instructor"><span class="public-course-instructor-avatar"><i class="fas fa-chalkboard-teacher" aria-hidden="true"></i></span><span><small>Instructor</small><strong><?=$instructor?></strong></span></div>
        <dl class="public-course-hero-stats">
          <div><dt><i class="fas fa-layer-group" aria-hidden="true"></i>Lessons</dt><dd><?=$lesson_count?></dd></div>
          <div><dt><i class="fas fa-clipboard-check" aria-hidden="true"></i>Homework</dt><dd><?=$course_summary['assignments']?></dd></div>
          <div><dt><i class="far fa-clock" aria-hidden="true"></i>Study time</dt><dd><?=$study_time?></dd></div>
          <div><dt><i class="fas fa-redo" aria-hidden="true"></i>Updated</dt><dd><?=$updated?></dd></div>
        </dl>
        <a href="#enroll" class="public-course-hero-cta"><i class="fas fa-arrow-right" aria-hidden="true"></i>Explore your learning space</a>
      </div>
    </div>
  </section>

  <section class="public-course-workspace">
    <div class="public-course-shell public-course-layout">
      <div class="public-course-content">
        <header class="public-course-content-header">
          <div><span class="public-course-kicker">Your course workspace</span><h2>Course content preview</h2><p>Browse the learning path. Enroll to unlock recordings, notes, homework, and feedback.</p></div>
          <span class="public-course-readonly"><i class="fas fa-lock" aria-hidden="true"></i>Read-only preview</span>
        </header>
        <div class="public-course-sections" id="course-accordion"><?=$course_content?></div>
        <?php if (!empty($course_past_papers)): ?>
          <section class="public-course-past-papers past-papers-section" aria-label="Linked Past Papers">
            <div class="past-papers-section-heading split">
              <div><span>Revision library</span><h2>Past Papers linked to this course</h2></div>
              <a class="past-papers-btn secondary" href="<?=pastpapers_html(rtrim((string)$baseUrl, '/'))?>/past-papers?<?=http_build_query(['course_id' => $course_public_id ?? ''])?>">View All Past Papers</a>
            </div>
            <div class="past-paper-grid">
              <?php foreach ($course_past_papers as $paper): ?>
                <?=pastpapers_paper_card($conn, $paper, $course_past_resources[$paper['paper_id']] ?? [], $baseUrl, false)?>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      </div>

      <aside class="public-course-sidebar" id="enroll">
        <div class="public-course-info-card">
          <div class="public-course-thumbnail"><img src="<?=$image_url?>" alt="<?=$course_title?>"></div>
          <div class="public-course-price-row">
            <span class="public-course-price"><?=$price?> <small>EGP</small></span>
            <?php if ($pre_discount !== ''): ?><span class="public-course-old-price"><?=$pre_discount?></span><?php endif; ?>
          </div>
          <p class="public-course-price-note"><?= $public_course_enrolled ? 'Your learning workspace is ready.' : 'Enroll to unlock the complete learning workspace.' ?></p>
          <?php if ($public_course_enrolled): ?>
            <a class="public-course-action-link" href="<?=$continue_course_url?>"><i class="fas fa-play" aria-hidden="true"></i>Continue Learning</a>
          <?php else: ?>
            <a class="public-course-action-link" href="<?=$course_checkout_url?>"><i class="fas fa-arrow-right" aria-hidden="true"></i>Enroll Now</a>
          <?php endif; ?>
          <ul class="public-course-includes">
            <li><i class="fas fa-layer-group" aria-hidden="true"></i><span><?=$lesson_count?> <?=$lesson_count === 1 ? 'lesson' : 'lessons'?> included</span></li>
            <li><i class="fas fa-play-circle" aria-hidden="true"></i><span><?=$course_summary['recordings']?> <?=$course_summary['recordings'] === 1 ? 'recording' : 'recordings'?></span></li>
            <li><i class="fas fa-file-pdf" aria-hidden="true"></i><span><?=$course_summary['files']?> <?=$course_summary['files'] === 1 ? 'learning file' : 'learning files'?></span></li>
            <li><i class="fas fa-clipboard-check" aria-hidden="true"></i><span><?=$course_summary['assignments']?> <?=$course_summary['assignments'] === 1 ? 'homework activity' : 'homework activities'?></span></li>
            <li><i class="fas fa-lock-open" aria-hidden="true"></i><span>Full access after enrollment</span></li>
          </ul>
        </div>
      </aside>
    </div>
  </section>
</main>
<?php else: ?>
<main class="public-course-preview">
  <div class="public-course-shell public-course-workspace">
    <?=$course_content?>
  </div>
</main>
<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'].dirname($_SERVER['SCRIPT_NAME'])."/views/public/layouts/footer.php"; ?>
</body>
</html>
