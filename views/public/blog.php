<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
$site_settings = getSiteSettings();
$site_name = $site_settings['website_name'] ?? 'Math Mastery Hub';
$publicResourcesCssVersion = @filemtime('resources/css/public-resources.css') ?: 1;
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<?php include __DIR__ . '/../partials/favicon.php'; ?>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8')?> · Blog</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <script src="<?=rtrim((string)$baseUrl, '/')?>/resources/js/theme-preference.js?v=<?=@filemtime('resources/js/theme-preference.js') ?: 1?>"></script>
  <link rel="stylesheet" href="<?=rtrim((string)$baseUrl, '/')?>/resources/css/design-system.css?v=<?=@filemtime('resources/css/design-system.css') ?: 1?>">
  <link rel="stylesheet" href="<?=rtrim((string)$baseUrl, '/')?>/resources/css/fontawsome5.min.css">
  <link rel="stylesheet" href="<?=rtrim((string)$baseUrl, '/')?>/resources/css/public-resources.css?v=<?=$publicResourcesCssVersion?>">
</head>
<body class="ds-bg-primary ds-text-primary public-static-page">
<?php include 'views/public/layouts/aside.php'; ?>
<main class="public-static-main">
  <section class="public-static-shell">
    <div class="public-static-card">
      <span class="public-static-eyebrow">Math Mastery Hub</span>
      <h1>Blog</h1>
      <p class="public-static-lead">Study guidance, announcements, and learning updates will appear here.</p>
      <p class="public-static-note">New articles will be published here as they become available.</p>
    </div>
  </section>
</main>
<?php include 'views/public/layouts/footer.php'; ?>
</body>
</html>
