<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
$site_settings = getSiteSettings();
$site_name = $site_settings['website_name'] ?? 'Math Mastery Hub';
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<?php include __DIR__ . '/../partials/favicon.php'; ?>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8')?> · Blog</title>
  <link rel="stylesheet" href="<?=rtrim((string)$baseUrl, '/')?>/resources/css/design-system.css">
  <link rel="stylesheet" href="<?=rtrim((string)$baseUrl, '/')?>/resources/css/fontawsome5.min.css">
  <link rel="stylesheet" href="<?=rtrim((string)$baseUrl, '/')?>/resources/css/public-resources.css">
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
