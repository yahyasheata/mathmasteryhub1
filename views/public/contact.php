<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
$site_settings = getSiteSettings();
$site_name = $site_settings['website_name'] ?? 'Math Mastery Hub';
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8')?> · Contact Us</title>
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
      <h1>Contact Us</h1>
      <p class="public-static-lead">Need help with your learning? Use the contact details below and our team will be happy to help.</p>
      <div class="public-static-contact-panel">
        <p class="public-static-contact-label">Email</p><p class="public-static-contact-value"><?=htmlspecialchars((string)($site_settings['contact_email'] ?? $site_settings['email'] ?? $site_settings['website_email'] ?? 'Support details will be available soon.'), ENT_QUOTES, 'UTF-8')?></p>
        <p class="public-static-contact-label">Phone</p><p class="public-static-contact-value"><?=htmlspecialchars((string)($site_settings['phone'] ?? $site_settings['phone_number'] ?? 'Support details will be available soon.'), ENT_QUOTES, 'UTF-8')?></p>
      </div>
    </div>
  </section>
</main>
<?php include 'views/public/layouts/footer.php'; ?>
</body>
</html>
