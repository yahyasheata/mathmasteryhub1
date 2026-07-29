<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/StudentCourseCsrf.php';

$pageName = 'settings';
$username = (string) ($_SESSION['username'] ?? '');
$conn = db();
$userData = null;

if ($username !== '') {
    $statement = $conn->prepare('SELECT user_id, username, full_name, avatar FROM users WHERE username = ? LIMIT 1');
    if ($statement) {
        $statement->bind_param('s', $username);
        $statement->execute();
        $userData = $statement->get_result()->fetch_assoc() ?: null;
        $statement->close();
    }
}

if (!$userData) {
    http_response_code(404);
    exit('Account settings are unavailable.');
}

function student_settings_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$avatarPath = trim((string) ($userData['avatar'] ?? ''));
if (!preg_match('#^(?:uploads|resources)/[A-Za-z0-9._/-]+$#', $avatarPath) || str_contains($avatarPath, '..')) {
    $avatarPath = 'resources/images/default/avatar.png';
}
$avatarUrl = rtrim((string) $baseUrl, '/') . '/' . ltrim($avatarPath, '/');
$settingsCsrfToken = student_course_csrf_token();
$settingsEndpoint = rtrim((string) $baseUrl, '/') . '/user/requests/settings/update';
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings · <?= student_settings_escape($site_name ?? 'Math Mastery Hub'); ?></title>
    <?php include 'layouts/user/header.php'; ?>
</head>
<body class="body ds-bg-primary student-settings-page">
    <?php include 'layouts/user/aside.php'; ?>

    <main class="student-settings-shell" id="main-content">
        <header class="student-settings-intro">
            <div>
                <p class="student-settings-eyebrow">Student account</p>
                <h1>Settings</h1>
                <p>Keep your profile current and protect your account with a secure password.</p>
            </div>
        </header>

        <div class="student-settings-layout">
            <aside class="student-settings-summary" aria-label="Account summary">
                <span class="student-settings-avatar-wrap"><img src="<?= student_settings_escape($avatarUrl); ?>" alt="<?= student_settings_escape($userData['full_name'] ?: 'Student'); ?> profile photo" class="student-settings-avatar" data-settings-avatar></span>
                <h2><?= student_settings_escape($userData['full_name'] ?: 'Student'); ?></h2>
                <p class="student-settings-contact"><?= student_settings_escape($userData['username']); ?></p>
                <p class="student-settings-summary-note"><span class="fas fa-shield-alt" aria-hidden="true"></span> Your sign-in contact is shown for reference and is not changed from this page.</p>
            </aside>

            <div class="student-settings-stack">
                <section class="student-settings-card" aria-labelledby="profile-settings-heading">
                    <header class="student-settings-card-header">
                        <span class="fas fa-user" aria-hidden="true"></span>
                        <div><h2 id="profile-settings-heading">Profile information</h2><p>Update the name and photo displayed across your student account.</p></div>
                    </header>
                    <form class="student-settings-form" action="<?= student_settings_escape($settingsEndpoint); ?>" method="post" enctype="multipart/form-data" data-settings-form data-settings-kind="profile">
                        <input type="hidden" name="csrf_token" value="<?= student_settings_escape($settingsCsrfToken); ?>">
                        <input type="hidden" name="update_main_info" value="1">
                        <div class="student-settings-feedback" role="status" aria-live="polite" hidden></div>
                        <div class="student-settings-field">
                            <label for="settings-full-name">Full name</label>
                            <input id="settings-full-name" class="form-control" type="text" name="full_name" value="<?= student_settings_escape($userData['full_name']); ?>" minlength="3" maxlength="190" autocomplete="name" required>
                        </div>
                        <div class="student-settings-field">
                            <label>Mobile number / sign-in contact</label>
                            <div class="student-settings-readonly"><?= student_settings_escape($userData['username']); ?></div>
                            <small>This account identifier is managed separately to keep sign-in access stable.</small>
                        </div>
                        <div class="student-settings-field">
                            <label for="settings-avatar">Profile photo</label>
                            <div class="student-settings-file-row"><input id="settings-avatar" class="form-control" type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif"><small>JPG, PNG, WebP, or GIF. Maximum 5 MB.</small></div>
                        </div>
                        <div class="student-settings-actions"><button class="btn btn-primary" type="submit" data-settings-submit><span class="fas fa-save" aria-hidden="true"></span> <span data-settings-label>Save profile</span></button></div>
                    </form>
                </section>

                <section class="student-settings-card" aria-labelledby="password-settings-heading">
                    <header class="student-settings-card-header">
                        <span class="fas fa-lock" aria-hidden="true"></span>
                        <div><h2 id="password-settings-heading">Password &amp; security</h2><p>Use a unique password you do not reuse on another website.</p></div>
                    </header>
                    <form class="student-settings-form" action="<?= student_settings_escape($settingsEndpoint); ?>" method="post" data-settings-form data-settings-kind="password">
                        <input type="hidden" name="csrf_token" value="<?= student_settings_escape($settingsCsrfToken); ?>">
                        <input type="hidden" name="update_password" value="1">
                        <div class="student-settings-feedback" role="status" aria-live="polite" hidden></div>
                        <p class="student-settings-security-note"><span class="fas fa-key" aria-hidden="true"></span> Choose at least 8 characters and combine words, numbers, or symbols.</p>
                        <div class="student-settings-field">
                            <label for="settings-current-password">Current password</label>
                            <div class="student-settings-password"><input id="settings-current-password" class="form-control" type="password" name="old_password" minlength="6" maxlength="190" autocomplete="current-password" required><button type="button" data-password-toggle aria-label="Show current password"><span class="fas fa-eye" aria-hidden="true"></span></button></div>
                        </div>
                        <div class="student-settings-field">
                            <label for="settings-new-password">New password</label>
                            <div class="student-settings-password"><input id="settings-new-password" class="form-control" type="password" name="password" minlength="8" maxlength="190" autocomplete="new-password" required><button type="button" data-password-toggle aria-label="Show new password"><span class="fas fa-eye" aria-hidden="true"></span></button></div>
                        </div>
                        <div class="student-settings-field">
                            <label for="settings-confirm-password">Confirm new password</label>
                            <div class="student-settings-password"><input id="settings-confirm-password" class="form-control" type="password" name="password_confirmation" minlength="8" maxlength="190" autocomplete="new-password" required><button type="button" data-password-toggle aria-label="Show confirmation password"><span class="fas fa-eye" aria-hidden="true"></span></button></div>
                        </div>
                        <div class="student-settings-actions"><button class="btn btn-primary" type="submit" data-settings-submit><span class="fas fa-lock" aria-hidden="true"></span> <span data-settings-label>Update password</span></button></div>
                    </form>
                </section>
            </div>
        </div>
    </main>

    <?php include 'layouts/user/footer.php'; ?>
    <script>
    (function () {
        function feedback(form, message, isSuccess) {
            var box = form.querySelector('.student-settings-feedback');
            if (!box) return;
            box.textContent = message || 'We could not save your changes. Please try again.';
            box.classList.toggle('is-success', !!isSuccess);
            box.classList.toggle('is-error', !isSuccess);
            box.hidden = false;
        }
        document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                var input = toggle.closest('.student-settings-password').querySelector('input');
                var visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                toggle.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
                toggle.querySelector('span').className = visible ? 'fas fa-eye' : 'fas fa-eye-slash';
            });
        });
        document.querySelectorAll('[data-settings-form]').forEach(function (form) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                var submit = form.querySelector('[data-settings-submit]');
                var label = submit && submit.querySelector('[data-settings-label]');
                var originalLabel = label ? label.textContent : '';
                if (!submit || submit.disabled) return;
                submit.disabled = true;
                if (label) label.textContent = 'Saving…';
                try {
                    var response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    var payload = await response.json();
                    if (!response.ok || !payload || Number(payload.status) !== 1) {
                        throw new Error((payload && (payload.message || payload.reason)) || 'We could not save your changes.');
                    }
                    feedback(form, payload.message, true);
                    if (form.dataset.settingsKind === 'password') form.reset();
                    var file = form.querySelector('input[type="file"]');
                    if (file && file.files && file.files[0]) {
                        var avatar = document.querySelector('[data-settings-avatar]');
                        if (avatar) avatar.src = URL.createObjectURL(file.files[0]);
                    }
                } catch (error) {
                    feedback(form, error.message || 'We could not save your changes. Please try again.', false);
                } finally {
                    submit.disabled = false;
                    if (label) label.textContent = originalLabel;
                }
            });
        });
    }());
    </script>
</body>
</html>
