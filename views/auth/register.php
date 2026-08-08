<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/OAuth.php';

$site_settings = getSiteSettings();
$site_name = $site_settings["website_name"];
$site_description = $site_settings["website_bio"];
$site_icon = $site_settings["website_icon"];
$authRootUrl = mmh_site_public_base_path() ?: '/';
$authBrandLogoUrl = mmh_site_settings_asset_url($site_settings, 'website_logo', 'resources/images/default/wide-logo.png');
$authDarkLogoUrl = mmh_site_public_url('resources/images/branding/mathhub-logo-white.png');
$authFaviconUrl = mmh_site_settings_asset_url($site_settings, 'website_icon', 'resources/images/default/favicon.png');

$authCsrfToken = mmh_auth_csrf_token();
$authBaseUrl = rtrim(mmh_site_public_base_path(), '/') . '/auth';
$authFlashError = mmh_auth_flash('error');
$googleOAuthAvailable = mmh_oauth_provider_available('google');
$appleOAuthAvailable = mmh_oauth_provider_available('apple');

$governorates = [
    [ 'id' => '1', 'governorate_name_en' => 'Cairo' ],
    [ 'id' => '2', 'governorate_name_en' => 'Giza' ],
    [ 'id' => '3', 'governorate_name_en' => 'Alexandria' ],
    [ 'id' => '4', 'governorate_name_en' => 'Dakahlia' ],
    [ 'id' => '5', 'governorate_name_en' => 'Red Sea' ],
    [ 'id' => '6', 'governorate_name_en' => 'Beheira' ],
    [ 'id' => '7', 'governorate_name_en' => 'Fayoum' ],
    [ 'id' => '8', 'governorate_name_en' => 'Gharbiya' ],
    [ 'id' => '9', 'governorate_name_en' => 'Ismailia' ],
    [ 'id' => '10', 'governorate_name_en' => 'Menofia' ],
    [ 'id' => '11', 'governorate_name_en' => 'Minya' ],
    [ 'id' => '12', 'governorate_name_en' => 'Qaliubiya' ],
    [ 'id' => '13', 'governorate_name_en' => 'New Valley' ],
    [ 'id' => '14', 'governorate_name_en' => 'Suez' ],
    [ 'id' => '15', 'governorate_name_en' => 'Aswan' ],
    [ 'id' => '16', 'governorate_name_en' => 'Assiut' ],
    [ 'id' => '17', 'governorate_name_en' => 'Beni Suef' ],
    [ 'id' => '18', 'governorate_name_en' => 'Port Said' ],
    [ 'id' => '19', 'governorate_name_en' => 'Damietta' ],
    [ 'id' => '20', 'governorate_name_en' => 'Sharkia' ],
    [ 'id' => '21', 'governorate_name_en' => 'South Sinai' ],
    [ 'id' => '22', 'governorate_name_en' => 'Kafr Al sheikh' ],
    [ 'id' => '23', 'governorate_name_en' => 'Matrouh' ],
    [ 'id' => '24', 'governorate_name_en' => 'Luxor' ],
    [ 'id' => '25', 'governorate_name_en' => 'Qena' ],
    [ 'id' => '26', 'governorate_name_en' => 'North Sinai' ],
    [ 'id' => '27', 'governorate_name_en' => 'Sohag' ]
];
$governorates_options = '';
foreach ($governorates as $governorate ) {
    $governorates_options .= "<option value='{$governorate['id']}'>{$governorate['governorate_name_en']}</option>";
}

?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=$site_name;?> - Register</title>
    <?php include __DIR__ . '/../partials/favicon.php'; ?>
    <script>
      (function() {
        try {
          var savedTheme = localStorage.getItem('theme');
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
        .dark-transition { transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease; }
    </style>
    <link rel="stylesheet" href="<?=mmh_site_public_url('resources/css/design-system.css')?>" data-design-system="mathhub" />
</head>
<body class='font-tajawal ds-bg-primary ds-text-primary transition-colors duration-300'>
    <!-- Navigation -->
    <header class='sticky top-0 z-50 ds-surface shadow-sm transition-colors duration-300'>
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <a href="<?=$authRootUrl?>" class="flex items-center gap-2">
                  <img src="<?=$authBrandLogoUrl?>" style="width: 105px;" alt="<?=$site_name;?>" class="mathhub-logo mathhub-logo--light">
                  <img src="<?=$authDarkLogoUrl?>" style="width: 105px;" alt="<?=$site_name;?>" class="mathhub-logo mathhub-logo--dark">
                </a>
                <nav class="hidden md:flex items-center gap-6">
                    <a href="#home" class='ds-text-secondary hover:text-primary font-medium transition duration-200'>Home</a>
                    <button id="theme-toggle" class='p-2 rounded-lg ds-text-secondary hover:text-primary transition duration-200'>
                        <i class="fas fa-moon dark:hidden"></i>
                        <i class="fas fa-sun hidden dark:block"></i>
                    </button>
                    <a href="<?=$authBaseUrl?>/login" class='bg-primary ds-text-inverse px-5 py-2 rounded-lg hover:bg-primary/90 transition duration-200'>Login</a>
                </nav>
                <div class="flex items-center gap-4 md:hidden">
                    <button id="theme-toggle-mobile" class='p-2 rounded-lg ds-text-secondary hover:text-primary transition duration-200'>
                        <i class="fas fa-moon dark:hidden"></i>
                        <i class="fas fa-sun hidden dark:block"></i>
                    </button>
                    <button id="menu-toggle" class='flex items-center ds-text-secondary hover:text-primary focus:outline-none'>
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
        </div>
        <div id="mobile-menu" class="md:hidden hidden">
            <div class='container mx-auto px-4 py-4 ds-surface border-t transition-colors duration-300'>
                <a href="/" class='block py-2 ds-text-secondary hover:text-primary'>Home</a>
                <a href="<?=$authBaseUrl?>/login" class='block py-2 mt-2 bg-primary ds-text-inverse text-center rounded-lg hover:bg-primary/90'>Login</a>
            </div>
        </div>
    </header>
    <!-- Register Section -->
    <main class="flex flex-1 items-center justify-center py-20 min-h-[60vh]">
      <div class='w-full max-w-4xl mx-auto ds-surface rounded-xl shadow-lg flex flex-col md:flex-row overflow-hidden'>
        <!-- Register Form Side -->
        <div class="w-full md:w-1/2 flex flex-col justify-center p-8 md:p-12">
          <div class="flex justify-center mb-6">
            <img src='<?=$authBrandLogoUrl?>' alt='<?=$site_name;?> Logo' class="h-16 w-auto mathhub-logo mathhub-logo--light">
            <img src='<?=$authDarkLogoUrl?>' alt='<?=$site_name;?> Logo' class="h-16 w-auto mathhub-logo mathhub-logo--dark">
          </div>
          <h2 class="text-3xl font-extrabold mb-6 text-center text-primary">Create Account</h2>
          <?php if ($authFlashError !== ''): ?>
            <div class="mb-4 rounded-lg border ds-border ds-surface-muted px-3 py-2 text-sm ds-text-primary" role="alert"><?=htmlspecialchars($authFlashError, ENT_QUOTES, 'UTF-8')?></div>
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
          <form method="POST" action="<?=$authBaseUrl?>/register" class="space-y-5" id="registerForm" novalidate>
            <input id="auth_csrf_token" type="hidden" name="csrf_token" value="<?=htmlspecialchars($authCsrfToken, ENT_QUOTES, 'UTF-8')?>">
            <div id="registerFeedback" class="hidden rounded-lg border ds-border px-3 py-2 text-sm" role="alert" aria-live="polite"></div>
            <div>
              <label for="name" class='block text-sm font-medium ds-text-secondary mb-1'>Full name</label>
              <input id="name" name="name" type="text" required minlength="2" maxlength="250" autocomplete="name" class='block w-full px-4 py-3 rounded-lg border ds-border ds-bg-primary ds-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition' />
            </div>
            <div>
              <label for="phone_number" class='block text-sm font-medium ds-text-secondary mb-1'>Email address or phone number</label>
              <input id="phone_number" name="phone_number" type="text" required autocomplete="username" inputmode="email" aria-describedby="accountIdentifierHelp" class='block w-full px-4 py-3 rounded-lg border ds-border ds-bg-primary ds-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition' />
              <p id="accountIdentifierHelp" class="mt-1 text-xs ds-text-muted">Use the details you will use to sign in.</p>
            </div>
            <div>
              <label for="password" class='block text-sm font-medium ds-text-secondary mb-1'>Password</label>
              <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" aria-describedby="passwordHelp" class='block w-full px-4 py-3 rounded-lg border ds-border ds-bg-primary ds-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition' />
              <p id="passwordHelp" class="mt-1 text-xs ds-text-muted">Use at least 8 characters.</p>
            </div>
            <div>
              <label for="password_confirmation" class='block text-sm font-medium ds-text-secondary mb-1'>Confirm password</label>
              <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" class='block w-full px-4 py-3 rounded-lg border ds-border ds-bg-primary ds-text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition' />
            </div>
            <button type="submit" class='registerBtn w-full bg-secondary ds-text-inverse px-6 py-3 rounded-lg font-bold hover:bg-secondary/90 transition focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2' aria-live="polite"><span class="registerBtnLabel">Create account</span><span class="registerBtnSpinner hidden ml-2"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i></span></button>
            <p class="text-center text-sm ds-text-secondary">Already have an account? <a href="<?=$authBaseUrl?>/login" class="text-primary hover:underline font-medium">Log in</a></p>
          </form>
        </div>
        <!-- Checklist Side -->
        <div class='hidden md:flex w-1/2 flex-col justify-center items-center ds-bg-primary p-8 md:p-12 border-l ds-border'>
          <div class="flex flex-col items-center w-full max-w-xs">
            <ul class="space-y-5 w-full">
              <li class="flex items-start gap-3">
                <span class="mt-1 text-green-500"><i class="fas fa-check-circle"></i></span>
                <span class='font-semibold ds-text-primary'>Start learning instantly</span>
              </li>
              <li class="flex items-start gap-3">
                <span class="mt-1 text-green-500"><i class="fas fa-check-circle"></i></span>
                <span class='ds-text-secondary'>Access interactive courses and resources anytime, anywhere.</span>
              </li>
              <li class="flex items-start gap-3">
                <span class="mt-1 text-green-500"><i class="fas fa-check-circle"></i></span>
                <span class='font-semibold ds-text-primary'>Track your progress</span>
              </li>
              <li class="flex items-start gap-3">
                <span class="mt-1 text-green-500"><i class="fas fa-check-circle"></i></span>
                <span class='ds-text-secondary'>Monitor achievements, grades, and certificates in your dashboard.</span>
              </li>
              <li class="flex items-start gap-3">
                <span class="mt-1 text-green-500"><i class="fas fa-check-circle"></i></span>
                <span class='font-semibold ds-text-primary'>Join a vibrant community</span>
              </li>
              <li class="flex items-start gap-3">
                <span class="mt-1 text-green-500"><i class="fas fa-check-circle"></i></span>
                <span class='ds-text-secondary'>Connect with students and instructors, ask questions, and share knowledge.</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'].dirname($_SERVER['SCRIPT_NAME'])."/views/public/layouts/footer.php"; ?>
    <!-- JavaScript -->
    <script>
        // Mobile Menu Toggle
        const menuToggle = document.getElementById('menu-toggle');
        const mobileMenu = document.getElementById('mobile-menu');
        menuToggle.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });
        // Smooth Scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    window.scrollTo({
                        top: target.offsetTop - 80,
                        behavior: 'smooth'
                    });
                    if (!mobileMenu.classList.contains('hidden')) {
                        mobileMenu.classList.add('hidden');
                    }
                }
            });
        });
        // Theme Toggle
        const themeToggle = document.getElementById('theme-toggle');
        const themeToggleMobile = document.getElementById('theme-toggle-mobile');
        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
        }
        themeToggle.addEventListener('click', toggleTheme);
        themeToggleMobile.addEventListener('click', toggleTheme);
        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || 
                (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        });
    </script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    $(function() {
      $('.oauthProviderAction').on('click', function() { if ($(this).data('submitting')) { return false; } $(this).data('submitting', true).attr('aria-busy', 'true').addClass('pointer-events-none opacity-60'); });
      var $form = $('#registerForm');
      var $button = $form.find('.registerBtn');
      var $feedback = $('#registerFeedback');
      var submitting = false;

      function feedback(message) {
        $feedback.text(message).removeClass('hidden').addClass('ds-surface-muted ds-text-primary');
      }
      function setLoading(loading) {
        submitting = loading;
        $button.prop('disabled', loading).toggleClass('opacity-60 cursor-not-allowed', loading);
        $button.find('.registerBtnLabel').text(loading ? 'Creating your account…' : 'Create account');
        $button.find('.registerBtnSpinner').toggleClass('hidden', !loading);
      }
      function showTransition() {
        $('body').append('<div id="authTransition" class="fixed inset-0 z-[100] flex items-center justify-center ds-bg-primary" role="status" aria-live="polite"><div class="text-center"><i class="fas fa-spinner fa-spin text-3xl text-primary" aria-hidden="true"></i><p class="mt-4 ds-text-primary font-semibold">Setting up your learning space…</p></div></div>');
      }

      $form.on('submit', function(e) {
        e.preventDefault();
        if (submitting) return;
        if (!this.checkValidity()) { this.reportValidity(); return; }
        if ($('#password').val() !== $('#password_confirmation').val()) {
          feedback('Your passwords do not match.');
          $('#password_confirmation').trigger('focus');
          return;
        }
        $feedback.addClass('hidden').text('');
        setLoading(true);
        $.ajax({
          type: 'POST',
          url: $form.attr('action'),
          data: new FormData(this),
          dataType: 'json',
          contentType: false,
          processData: false
        }).done(function(response) {
          if (response && response.success) {
            showTransition();
            window.location.assign(response.redirect || '<?=$authRootUrl?>');
            return;
          }
          feedback((response && response.message) || 'We could not create your account. Please try again.');
          setLoading(false);
        }).fail(function(xhr) {
          var response = xhr.responseJSON || {};
          feedback(response.message || 'We could not create your account. Please try again.');
          setLoading(false);
        });
      });
    });
    </script>
</body>
</html>
