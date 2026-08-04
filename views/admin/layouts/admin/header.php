    
<script>
    document.documentElement.lang = 'en';
    document.documentElement.dir = 'ltr';
</script>
<?php
/* Prompt 23: resolve the dashboard theme and load design tokens first so the
   correct colors are established before any other stylesheet paints, avoiding
   a flash of incorrect theme/layout right after the post-login redirect. */
require_once 'connection/config.php';
$adminCsrfToken = mmh_admin_csrf_token();
$adminBrandSettings = isset($site_settings) && is_array($site_settings) ? $site_settings : getSiteSettings();
$adminFaviconUrl = mmh_site_settings_asset_url($adminBrandSettings, 'website_icon', 'resources/images/default/favicon.png');
$isDarkMode = 0;
$themeStmt = $conn->prepare("SELECT value FROM settings WHERE `key` = ? LIMIT 1");
if ($themeStmt) {
    $themeKey = 'dashboard_dark_mode';
    $themeStmt->bind_param('s', $themeKey);
    $themeStmt->execute();
    $themeRow = $themeStmt->get_result()->fetch_assoc();
    $isDarkMode = (int) ($themeRow['value'] ?? 0);
    $themeStmt->close();
}
?>
<script>
    document.documentElement.setAttribute('data-bs-theme', '<?=$isDarkMode == 1 ? 'dark' : 'light'?>');
    document.documentElement.classList.toggle('dark', <?=$isDarkMode == 1 ? 'true' : 'false'?>);
</script>
<link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/design-system.css')?>" data-design-system="mathhub" />
<?php if ($isDarkMode == 1) { ?>
<style type="text/css">
    body.dash {
        color-scheme: dark;
        --bg-primary: #0f1718;
        --bg-secondary: #121f21;
        --surface: #172426;
        --surface-elevated: #1d2d30;
        --surface-hover: #243a3d;
        --surface-muted: #142022;
        --surface-inset: #0b1314;
        --text-primary: #f8f5ef;
        --text-secondary: #d8dedc;
        --text-muted: #9eaaa8;
        --border: rgba(216, 222, 220, .13);
        --border-strong: rgba(216, 222, 220, .22);
        --divider: color-mix(in srgb, var(--text-muted) 28%, transparent);
    }
</style>
<?php } ?>
<?=$metatags."\n"?>
    <?=$keywords."\n"?>

    <?=$openGraph?>

    <?=$schema?> 
<link rel="icon" type="image/png" href="<?=$adminFaviconUrl?>" />
    <link rel="icon" type="image/png" sizes="512x512" href="<?=$adminFaviconUrl?>" />
    <meta name="theme-color" content="#F15A22">
    <meta name="mobile-web-app-capable" content="no">
    <meta name="application-name" content="<?=$site_name;?>">
    <meta name="csrf-token" content="<?=htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8')?>">

    <link href="<?=$adminFaviconUrl?>"
        media="(device-width: 320px) and (device-height: 568px) and (-webkit-device-pixel-ratio: 2)"
        rel="apple-touch-startup-image" />

    <link rel="preload" as="style" href="<?=mmh_site_public_url('resources/build/assets/dashboard-1fcbed15.css')?>" />
    <link rel="stylesheet" href="<?=mmh_site_public_url('resources/build/assets/dashboard-1fcbed15.css')?>" data-navigate-track="reload" />
    <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/bootstrap-5.2.3.min.css')?>" data-navigate-track="reload" />
    <?php $adminDashboardCssVersion = (string) (@filemtime(__DIR__ . '/../../../../resources/css/main-dashboard.css') ?: 1); ?>
    <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/main-dashboard.css')?>?v=<?=rawurlencode($adminDashboardCssVersion)?>" data-navigate-track="reload" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" data-navigate-track="reload" />

    <style type="text/css">
        html,
        body,
        .dash {
            direction: ltr;
            text-align: start;
        }

    </style>

    <script type="text/javascript" src="<?=mmh_site_public_url('resources/js/sweetalert2.min.js')?>"></script>

    <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/sweetalert2.min.css')?>" />

    <script src="https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/nprogress.min.js" integrity="sha512-bUg5gaqBVaXIJNuebamJ6uex//mjxPk8kljQTdM1SwkNrQD7pjS+PerntUSD+QRWPNJ0tq54/x4zRV8bLrLhZg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/nprogress/0.2.0/nprogress.min.css" integrity="sha512-42kB9yDlYiCEfx2xVwq0q7hT4uf26FUgSIZBK8uiaEnTdShXjwr8Ip1V4xGJMg3mHkUt9nNuTDxunHF0/EgxLQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <script type="text/javascript" src="<?=mmh_site_public_url('resources/js/jquery-3.6.1.min.js')?>"></script>
    <script>
    (function () {
        var token = <?=json_encode($adminCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
        function addToken(form) {
            if (!form || (form.method || 'get').toLowerCase() !== 'post') return;
            var field = form.querySelector('input[name="mmh_csrf_token"]');
            if (!field) {
                field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'mmh_csrf_token';
                form.appendChild(field);
            }
            field.value = token;
        }
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('form').forEach(addToken);
        });
        document.addEventListener('submit', function (event) { addToken(event.target); }, true);
        if (window.jQuery) {
            window.jQuery.ajaxPrefilter(function (options, originalOptions, jqXHR) {
                if ((options.type || options.method || 'GET').toUpperCase() !== 'GET') {
                    jqXHR.setRequestHeader('X-CSRF-Token', token);
                }
            });
        }
    }());
    </script>
<?php $adminShellJsVersion = (string) (@filemtime(__DIR__ . '/../../../../resources/js/admin-shell.js') ?: 1); ?>
<script defer src="<?=mmh_site_public_url('resources/js/admin-shell.js')?>?v=<?=rawurlencode($adminShellJsVersion)?>"></script>

<link href="https://cdn.datatables.net/v/bs5/jszip-3.10.1/dt-1.13.6/af-2.6.0/b-2.4.2/b-colvis-2.4.2/b-html5-2.4.2/b-print-2.4.2/cr-1.7.0/date-1.5.1/fc-4.3.0/fh-3.4.0/kt-2.10.0/r-2.5.0/rg-1.4.1/rr-1.4.1/sc-2.2.0/sb-1.6.0/sp-2.2.0/sl-1.7.0/sr-1.3.0/datatables.min.css" rel="stylesheet">
 
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/v/bs5/jszip-3.10.1/dt-1.13.6/af-2.6.0/b-2.4.2/b-colvis-2.4.2/b-html5-2.4.2/b-print-2.4.2/cr-1.7.0/date-1.5.1/fc-4.3.0/fh-3.4.0/kt-2.10.0/r-2.5.0/rg-1.4.1/rr-1.4.1/sc-2.2.0/sb-1.6.0/sp-2.2.0/sl-1.7.0/sr-1.3.0/datatables.min.js"></script>
<link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/fontawsome5.min.css')?>"  referrerpolicy="no-referrer" />



    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js" integrity="sha384-IQsoLXl5PILFhosVNubq5LC7Qb9DXgDA9i+tQ8Zj3iwWAwPtgFTxbJ8NT4GN1R8p" crossorigin="anonymous"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.min.js" integrity="sha384-cVKIPhGWiC2Al4u+LWgxfKTRIcfu0JTxR+EQDz/bgldoEyl4H0zUF0QKbrJ0EcQF" crossorigin="anonymous"></script>

    <!-- <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js"></script> -->

    <script type="text/javascript" src="<?=mmh_site_public_url('resources/js/tinymce/tinymce.min.js')?>"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js" integrity="sha512-2ImtlRlf2VVmiGZsjm9bEyhjGW4dU7B6TNwh/hx/iSByxNENtj3WVE6o/9Lj4TJeVXPi4bnOIMXFIJJAeufa0A==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" integrity="sha512-nMNlpuaDPrqlEls3IX/Q56H36qvBASwb3ipuo3MxeWbsQB1881ox0cRv7UPTgBlriqoynt35KjEwgGUeUXIPnw==" crossorigin="anonymous" referrerpolicy="no-referrer" />

<!-- include summernote css/js -->
<!-- <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote.min.js"></script> -->
