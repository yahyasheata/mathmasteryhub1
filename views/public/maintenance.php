<?php
$maintenanceTitle = trim((string) ($siteSettings['maintenance_title'] ?? '')) ?: 'We are improving Math Mastery Hub';
$maintenanceMessage = trim((string) ($siteSettings['maintenance_message'] ?? '')) ?: 'The site is briefly unavailable while scheduled maintenance is completed.';
$maintenanceReopen = trim((string) ($siteSettings['maintenance_reopen_at'] ?? ''));
?>
<!doctype html>
<html lang="en" dir="ltr" data-bs-theme="dark">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=htmlspecialchars($maintenanceTitle, ENT_QUOTES, 'UTF-8')?></title>
  <?php include __DIR__ . '/../partials/favicon.php'; ?>
  <link rel="stylesheet" href="<?=rtrim((string) $baseUrl, '/')?>/resources/css/design-system.css">
  <link rel="stylesheet" href="<?=rtrim((string) $baseUrl, '/')?>/resources/css/fontawsome5.min.css">
  <style>
    body{min-height:100vh;margin:0;display:grid;place-items:center;padding:24px;background:var(--bg-primary);color:var(--text-primary);font-family:var(--font-sans,system-ui,sans-serif)}
    .maintenance-card{width:min(560px,100%);padding:clamp(1.5rem,5vw,3rem);border:1px solid var(--border);border-radius:var(--radius-xl);background:var(--surface);box-shadow:var(--shadow-lg);text-align:center}
    .maintenance-icon{width:56px;height:56px;display:grid;place-items:center;margin:0 auto 1rem;border-radius:50%;background:var(--primary-soft);color:var(--primary);font-size:1.4rem}.maintenance-card h1{margin:0;color:var(--text-primary);font-size:clamp(1.45rem,4vw,2rem)}.maintenance-card p{margin:1rem 0 0;color:var(--text-secondary);line-height:1.65}.maintenance-card small{display:block;margin-top:1rem;color:var(--text-muted)}
  </style>
</head>
<body><main class="maintenance-card"><span class="maintenance-icon fas fa-tools" aria-hidden="true"></span><h1><?=htmlspecialchars($maintenanceTitle, ENT_QUOTES, 'UTF-8')?></h1><p><?=nl2br(htmlspecialchars($maintenanceMessage, ENT_QUOTES, 'UTF-8'))?></p><?php if ($maintenanceReopen !== ''): ?><small>Estimated reopening: <?=htmlspecialchars(date('M j, Y · g:i A', strtotime($maintenanceReopen)), ENT_QUOTES, 'UTF-8')?></small><?php endif; ?></main></body>
</html>
