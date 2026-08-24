<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/Auth.php';
require_once 'inc/AdminAccountManagement.php';

mmh_admin_require_admin();
$conn = db();
$pageName = 'admin-management';
$subPageName = 'admin-management';
$username = (string) ($_SESSION['admin'] ?? '');
$base = rtrim((string) ($baseUrl ?? mmh_current_request_base_url()), '/');
$search = trim((string) ($_GET['q'] ?? ''));
$admins = mmh_admin_management_list_admins($conn);
$matches = mmh_admin_management_search_users($conn, $search);
$flash = $_SESSION['mmh_admin_management_flash'] ?? null;
unset($_SESSION['mmh_admin_management_flash']);
$current = mmh_admin_management_current_admin($conn, $username);
$currentId = (int) ($current['user_id'] ?? 0);
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrf = mmh_admin_csrf_token();
$cssPath = __DIR__ . '/../../resources/css/admin-management.css';
$cssVersion = (string) (is_file($cssPath) ? (filemtime($cssPath) ?: 1) : 1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Management</title>
    <?php include 'layouts/admin/header.php'; ?>
    <link rel="stylesheet" href="<?= $escape(mmh_site_public_url('resources/css/admin-settings.css')) ?>">
    <link rel="stylesheet" href="<?= $escape(mmh_site_public_url('resources/css/admin-management.css')) ?>?v=<?= $escape($cssVersion) ?>">
</head>
<body class="dash ds-bg-primary admin-management-page">
<form method="POST" action="<?= $escape($base) ?>/admin/logout" class="d-none">
    <input type="hidden" name="mmh_csrf_token" value="<?= $escape($csrf) ?>">
</form>
<div class="col-12 d-flex">
    <?php include 'layouts/admin/aside.php'; ?>
    <div class="main-content in-active admin-settings-main">
        <?php include 'layouts/admin/top-nav.php'; ?>
        <main class="admin-settings-shell admin-management-shell" id="main-content">
            <header class="admin-settings-header">
                <div>
                    <p class="admin-settings-eyebrow">Security and access</p>
                    <h1>Admin Management</h1>
                    <p>Promote existing active users or remove administrator access without changing their account history.</p>
                </div>
            </header>

            <?php if (is_array($flash)): ?>
                <div class="admin-settings-notice <?= !empty($flash['ok']) ? 'is-success' : 'is-error' ?>" role="status">
                    <span class="fas <?= !empty($flash['ok']) ? 'fa-check-circle' : 'fa-exclamation-circle' ?>" aria-hidden="true"></span>
                    <?= $escape($flash['message'] ?? '') ?>
                </div>
            <?php endif; ?>

            <div class="admin-management-grid">
                <section class="admin-settings-card" aria-labelledby="current-admins-title">
                    <div class="admin-management-section-heading">
                        <div><h2 id="current-admins-title">Current Administrators</h2><p>Only active, non-archived administrators can manage access.</p></div>
                        <span class="admin-management-count"><?= count(array_filter($admins, static fn(array $row): bool => !empty($row['active_admin']))) ?> active</span>
                    </div>
                    <div class="admin-management-list">
                        <?php foreach ($admins as $admin):
                            $adminId = (int) ($admin['user_id'] ?? 0);
                            $active = !empty($admin['active_admin']);
                            $name = trim((string) ($admin['full_name'] ?? '')) ?: (string) ($admin['username'] ?? '');
                            $isSelf = $adminId > 0 && $adminId === $currentId;
                        ?>
                            <article class="admin-management-row">
                                <div class="admin-management-identity"><span class="fas fa-user-shield" aria-hidden="true"></span><div><strong><?= $escape($name) ?></strong><small><?= $escape($admin['username'] ?? '') ?></small></div></div>
                                <div class="admin-management-status"><span class="admin-management-badge <?= $active ? 'is-active' : 'is-inactive' ?>"><?= $active ? 'Active' : 'Unavailable' ?></span><?php if ($isSelf): ?><small>This is you</small><?php endif; ?></div>
                                <?php if ($active): ?>
                                    <form method="post" action="<?= $escape($base) ?>/admin/requests/admin-management/save" class="admin-management-action" onsubmit="return confirm('Remove administrator access from this account?');">
                                        <input type="hidden" name="mmh_csrf_token" value="<?= $escape($csrf) ?>">
                                        <input type="hidden" name="action" value="demote">
                                        <input type="hidden" name="target_user_id" value="<?= $adminId ?>">
                                        <label class="admin-management-password"><span class="visually-hidden">Current administrator password</span><input type="password" name="current_password" autocomplete="current-password" placeholder="Current password" required></label>
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><?= $isSelf ? 'Leave Admin' : 'Remove access' ?></button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$admins): ?><p class="admin-management-empty">No administrator accounts were found.</p><?php endif; ?>
                    </div>
                </section>

                <section class="admin-settings-card" aria-labelledby="add-admin-title">
                    <div class="admin-management-section-heading"><div><h2 id="add-admin-title">Add Administrator</h2><p>Search existing active users. Their password, enrollments, and learning history stay unchanged.</p></div></div>
                    <form method="get" action="<?= $escape($base) ?>/admin/admin-management" class="admin-management-search">
                        <label for="admin-user-search">Find a user</label>
                        <div class="admin-management-search-row"><input id="admin-user-search" type="search" name="q" value="<?= $escape($search) ?>" class="form-control" placeholder="Name or email" autocomplete="off"><button class="btn btn-outline-secondary" type="submit"><span class="fas fa-search" aria-hidden="true"></span> Search</button></div>
                    </form>
                    <?php if ($search === ''): ?><p class="admin-management-empty">Search for an existing active user to grant administrator access.</p><?php elseif (!$matches): ?><p class="admin-management-empty">No active, non-archived users matched “<?= $escape($search) ?>”.</p><?php else: ?>
                        <div class="admin-management-list">
                        <?php foreach ($matches as $user): $userId = (int) ($user['user_id'] ?? 0); $name = trim((string) ($user['full_name'] ?? '')) ?: (string) ($user['username'] ?? ''); ?>
                            <article class="admin-management-row admin-management-candidate"><div class="admin-management-identity"><span class="fas fa-user" aria-hidden="true"></span><div><strong><?= $escape($name) ?></strong><small><?= $escape($user['username'] ?? '') ?></small></div></div><form method="post" action="<?= $escape($base) ?>/admin/requests/admin-management/save" class="admin-management-action" onsubmit="return confirm('Make <?= $escape($name) ?> an administrator? This gives the account full access to the Admin Panel.');"><input type="hidden" name="mmh_csrf_token" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="promote"><input type="hidden" name="target_user_id" value="<?= $userId ?>"><label class="admin-management-password"><span class="visually-hidden">Current administrator password</span><input type="password" name="current_password" autocomplete="current-password" placeholder="Current password" required></label><button type="submit" class="btn btn-primary btn-sm">Make Admin</button></form></article>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
            <p class="admin-management-note"><span class="fas fa-lock" aria-hidden="true"></span> Every change requires your current password, an authenticated admin session, and a security token. Account changes are recorded without storing passwords or session details.</p>
        </main>
    </div>
</div>
</body>
</html>
