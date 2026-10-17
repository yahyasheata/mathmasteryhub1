<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/OAuth.php';
if(isset($_SESSION['username'])){
  $username = $_SESSION['username'];

}else{
  $username = null;
}

        $site_settings = getSiteSettings();
        $site_name = $site_settings["website_name"];
        $site_description = $site_settings["website_bio"];
        $site_icon = $site_settings["website_icon"];
$authRootUrl = mmh_site_public_base_path() ?: '/';
$authFaviconUrl = mmh_site_settings_asset_url($site_settings, 'website_icon', 'resources/images/default/favicon.png');

$authCsrfToken = mmh_auth_csrf_token();
$authBaseUrl = rtrim(mmh_site_public_base_path(), '/') . '/auth';
$authFlashError = mmh_auth_flash('error');
$authFlashSuccess = mmh_auth_flash('password_reset_success');
$googleOAuthAvailable = mmh_oauth_provider_available('google');
$appleOAuthAvailable = mmh_oauth_provider_available('apple');
        

$conn = db();
$categories_query = "SELECT * FROM categories";
$categories_result =  mysqli_query($conn,$categories_query);

if( mysqli_num_rows($categories_result) > 0 ){

  $categorie = '';
  while( $categories_data = mysqli_fetch_assoc($categories_result) ){
    $date = date('Y-m-d', strtotime($categories_data['created_at']));
    $categorie .= "

      
<!-- Card 1: Programming with Image -->
<div class='ds-bg-primary rounded-xl overflow-hidden transition duration-300 hover:shadow-xl dark:border'>
  <a href='category/{$categories_data['category_link']}'>
    <div class='h-48 overflow-hidden'>
      <img src='{$categories_data['category_image']}' alt='{$categories_data['category_title']}' class='w-full h-full object-cover transition-transform duration-300 hover:scale-105'>
    </div>
  </a>
  <div class='p-6'>
    <h3 class='text-xl font-bold mb-4 ds-text-primary'>{$categories_data['category_title']}</h3>
    <p class='ds-text-secondary mb-4'>
      {$categories_data['category_description']}
    </p>
    <div class='flex items-center justify-between'>
      <a href='category/{$categories_data['category_link']}' class='text-secondary hover:text-secondary/80 font-medium flex items-center'>
        Explore
        <i class='fas fa-arrow-right ml-2'></i>
      </a>
      <span class='bg-secondary/10 text-secondary text-xs px-3 py-1 rounded-full'>
        New
      </span>
    </div>
  </div>
</div>





    "; 
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
    <title><?=$site_name;?></title>

    <?=$metatags."\n"?>
    <?=$keywords."\n"?>

    <?=$openGraph?>

    <?=$schema?> 


    <?php include __DIR__ . '/../partials/favicon.php'; ?>
    <script>
      (function() {
        try {
          var savedTheme = localStorage.getItem('theme') || localStorage.getItem('math-mastery-student-theme');
          var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
          document.documentElement.classList.toggle('dark', savedTheme === 'dark' || (!savedTheme && prefersDark));
        } catch (error) {}
      })();
    </script>
    
    <!-- TailwindCSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
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
                    fontFamily: { sans: ['DM Sans', 'sans-serif'], serif: ['DM Serif Display', 'serif'], mono: ['IBM Plex Mono', 'monospace'] }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'DM Sans', sans-serif; }
        .dark .dark-invert {
            filter: invert(1);
        }
        /* تحسين الانتقالات للوضع الداكن */
        .dark-transition {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
    </style>
    <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/design-system.css')?>?v=<?=@filemtime('resources/css/design-system.css') ?: 1?>" data-design-system="mathhub" />
    <script src="<?=mmh_site_public_url('resources/js/theme-preference.js')?>?v=<?=@filemtime('resources/js/theme-preference.js') ?: 1?>"></script>
    <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/auth-pages.css')?>?v=<?=@filemtime('resources/css/auth-pages.css') ?: 1?>">
</head>
<body class='font-sans mmh-auth-page mmh-auth-page--public-header ds-bg-primary ds-text-primary transition-colors duration-300'>
    <?php include __DIR__ . '/../public/layouts/aside.php'; ?>

    <!-- Login Section -->
    <main class="flex flex-1 items-center justify-center py-20 min-h-[60vh]">
      <div class='w-full max-w-4xl mx-auto ds-surface rounded-xl shadow-lg flex flex-col md:flex-row overflow-hidden'>
        <!-- Login Form Side -->
        <div class="w-full md:w-1/2 flex flex-col justify-center p-8 md:p-12">
          <h2 class="mb-6 text-center text-primary">Login</h2>
          <?php if ($authFlashError !== ''): ?>
            <div class="mb-4 rounded-lg border ds-border ds-surface-muted px-3 py-2 text-sm ds-text-primary" role="alert"><?=htmlspecialchars($authFlashError, ENT_QUOTES, 'UTF-8')?></div>
          <?php endif; ?>
          <?php if ($authFlashSuccess !== ''): ?>
            <div class="mb-4 rounded-lg border border-green-500/40 bg-green-500/10 px-3 py-2 text-sm ds-text-primary" role="status"><?=htmlspecialchars($authFlashSuccess, ENT_QUOTES, 'UTF-8')?></div>
          <?php endif; ?>
          <div class="space-y-3" aria-label="Social sign-in options">
            <?php if ($googleOAuthAvailable): ?>
              <a href="<?=$authBaseUrl?>/google/start" class="oauthProviderAction flex w-full items-center justify-center gap-3 rounded-lg border ds-border ds-bg-primary px-4 py-3 font-semibold ds-text-primary transition hover:ds-surface-muted focus:outline-none focus:ring-2 focus:ring-primary" aria-label="Continue with Google"><i class="fab fa-google" aria-hidden="true"></i>Continue with Google</a>
            <?php else: ?>
              <button type="button" disabled class="flex w-full items-center justify-center gap-3 rounded-lg border ds-border ds-surface-muted px-4 py-3 font-semibold ds-text-muted cursor-not-allowed" aria-disabled="true" title="Google sign-in is not available in this environment"><i class="fab fa-google" aria-hidden="true"></i>Google sign-in unavailable</button>
            <?php endif; ?>
            <?php if ($appleOAuthAvailable): ?>
              <a href="<?=$authBaseUrl?>/apple/start" class="oauthProviderAction flex w-full items-center justify-center gap-3 rounded-lg border ds-border ds-bg-primary px-4 py-3 font-semibold ds-text-primary transition hover:ds-surface-muted focus:outline-none focus:ring-2 focus:ring-primary" aria-label="Continue with Apple"><i class="fab fa-apple" aria-hidden="true"></i>Continue with Apple</a>
            <?php else: ?>
              <button type="button" disabled class="flex w-full items-center justify-center gap-3 rounded-lg border ds-border ds-surface-muted px-4 py-3 font-semibold ds-text-muted cursor-not-allowed" aria-disabled="true" title="Apple sign-in is not available in this environment"><i class="fab fa-apple" aria-hidden="true"></i>Apple sign-in unavailable</button>
            <?php endif; ?>
          </div>
          <div class="my-6 flex items-center gap-3" aria-hidden="true"><span class="h-px flex-1 ds-border"></span><span class="text-xs font-semibold uppercase tracking-wide ds-text-muted">or continue with email</span><span class="h-px flex-1 ds-border"></span></div>
          <form method="POST" action="<?=$authBaseUrl?>/login" class="space-y-6" id="loginForm" novalidate>
            <input id="auth_csrf_token" type="hidden" name="csrf_token" value="<?=htmlspecialchars($authCsrfToken, ENT_QUOTES, 'UTF-8')?>">
            <div id="loginFeedback" class="hidden rounded-lg border ds-border px-3 py-2 text-sm" role="alert" aria-live="polite"></div>
            <div>
              <label for="username" class='block text-sm font-medium ds-text-secondary mb-1'>Phone Number / Email</label>
              <input id="username" name="username" type="text" required autocomplete="username" autofocus class='block w-full px-4 py-3 rounded-lg border ds-border ds-bg-primary ds-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition' />
            </div>
            <div>
              <label for="password" class='block text-sm font-medium ds-text-secondary mb-1'>Password</label>
              <input id="password" name="password" type="password" required autocomplete="current-password" minlength="8" class='block w-full px-4 py-3 rounded-lg border ds-border ds-bg-primary ds-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition' />
              <div class="mt-2 text-right"><a href="<?=$authBaseUrl?>/forgot-password" class="text-sm text-primary hover:underline">Forgot password?</a></div>
            </div>
            <div class="flex items-center justify-between">
              <div class="flex items-center">
                <input id="remember" name="remember" type="checkbox" checked class='h-4 w-4 text-primary focus:ring-primary ds-border rounded'>
                <label for="remember" class='ml-2 block text-sm ds-text-secondary'>Remember me</label>
              </div>
              <button type="submit" class='public-nav-button primary loginBtn' aria-live="polite"><span class="loginBtnLabel">Login</span><span class="loginBtnSpinner hidden ml-2"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i></span></button>
            </div>
            <div class="text-center mt-4">
              <a href="<?=$authBaseUrl?>/register" class="text-primary hover:underline font-medium"><i class="fas fa-circle ds-icon ds-icon-xs mr-1" aria-hidden="true"></i> Don't have an account?</a>
            </div>
            <div class="text-center mt-2">
              <button type="button" class='helpButton text-sm ds-text-muted hover:text-primary transition'>Need help?</button>
            </div>
          </form>
        </div>
        <!-- Checklist Side -->
        <div class='hidden md:flex w-1/2 flex-col justify-center items-center ds-bg-primary p-8 md:p-12 border-l ds-border'>
          <div class="flex flex-col items-center w-full max-w-xs">
            <!-- <img src="https://flowbite.com/docs/images/logo.svg" alt="Flowbite Logo" class="h-12 mb-6"> -->
            <ul class="space-y-5 w-full">
              <li class="ds-icon-text items-center gap-3">
                <span class="ds-icon ds-icon-lg ds-icon-secondary" aria-hidden="true"><i class="far fa-play-circle"></i></span>
                <span class='font-semibold ds-text-primary'>Interactive Courses</span>
              </li>
              <li class="ds-icon-text items-center gap-3">
                <span class="ds-icon ds-icon-lg ds-icon-primary" aria-hidden="true"><i class="far fa-clipboard"></i></span>
                <span class='font-semibold ds-text-primary'>Homework and Feedback</span>
              </li>
              <li class="ds-icon-text items-center gap-3">
                <span class="ds-icon ds-icon-lg ds-icon-secondary" aria-hidden="true"><i class="far fa-chart-bar"></i></span>
                <span class='font-semibold ds-text-primary'>Track Your Progress</span>
              </li>
              <li class="ds-icon-text items-center gap-3">
                <span class="ds-icon ds-icon-lg ds-icon-primary" aria-hidden="true"><i class="far fa-lightbulb"></i></span>
                <span class='font-semibold ds-text-primary'>Learn Anywhere</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </main>
    
        <?php include $_SERVER['DOCUMENT_ROOT'].dirname($_SERVER['SCRIPT_NAME'])."/views/public/layouts/footer.php"; ?>

    <!-- Shared public header owns mobile-menu behavior. -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    $(function() {
      $('.oauthProviderAction').on('click', function() { if ($(this).data('submitting')) { return false; } $(this).data('submitting', true).attr('aria-busy', 'true').addClass('pointer-events-none opacity-60'); });
      var $form = $('#loginForm');
      var $button = $form.find('.loginBtn');
      var $feedback = $('#loginFeedback');
      var submitting = false;

      function feedback(message) {
        $feedback.text(message).removeClass('hidden').addClass('ds-surface-muted ds-text-primary');
      }
      function setLoading(loading) {
        submitting = loading;
        $button.prop('disabled', loading).toggleClass('opacity-60 cursor-not-allowed', loading);
        $button.find('.loginBtnLabel').text(loading ? 'Signing in…' : 'Login');
        $button.find('.loginBtnSpinner').toggleClass('hidden', !loading);
      }
      function showTransition() {
        $('body').append('<div id="authTransition" class="fixed inset-0 z-[100] flex items-center justify-center ds-bg-primary" role="status" aria-live="polite"><div class="text-center"><i class="fas fa-spinner fa-spin text-3xl text-primary" aria-hidden="true"></i><p class="mt-4 ds-text-primary font-semibold">Opening your learning space…</p></div></div>');
      }

      $form.on('submit', function(e) {
        e.preventDefault();
        if (submitting) return;
        if (!this.checkValidity()) { this.reportValidity(); return; }
        $feedback.addClass('hidden').text('');
        setLoading(true);
        $.ajax({
          type: 'POST',
          url: $form.attr('action'),
          data: {
            username: $('#username').val().trim(),
            password: $('#password').val(),
            csrf_token: $('#auth_csrf_token').val()
          },
          dataType: 'json'
        }).done(function(response) {
          if (response && response.success) {
            showTransition();
            window.location.assign(response.redirect || '<?=$authRootUrl?>');
            return;
          }
          feedback((response && response.message) || 'We could not sign you in. Please try again.');
          setLoading(false);
        }).fail(function(xhr) {
          var response = xhr.responseJSON || {};
          feedback(response.message || 'We could not sign you in. Please try again.');
          setLoading(false);
        });
      });
    });
    </script>
    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "API Club",
      "url": "https://apiclub.site/",
      "potentialAction": {
        "@type": "SearchAction",
        "target": "https://apiclub.site/search?q={search_term_string}",
        "query-input": "required name=search_term_string"
      },
      "description": "منصة API Club العربية توفر توثيقًا شاملًا للغات البرمجة وأطر العمل وخدمات API متكاملة للمطورين العرب",
      "inLanguage": "ar"
    }
    </script>

    <!-- Organization Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "API Club",
      "url": "https://apiclub.site/",
      "logo": "https://apiclub.site/assets/images/logo.png",
      "sameAs": [
        "https://twitter.com/apiclub",
        "https://github.com/apiclub",
        "https://linkedin.com/company/apiclub"
      ]
    }
    </script>
</body>
</html>
