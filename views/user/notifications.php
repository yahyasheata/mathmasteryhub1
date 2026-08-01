<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

$pageName = 'notifications';
$username = (string) ($_SESSION['username'] ?? '');
$userInfo = $username !== '' ? getUserInfo($username) : false;
$userData = is_object($userInfo) ? get_object_vars($userInfo) : [];
$userId = (int) ($userData['user_id'] ?? 0);
$userFullName = trim((string) ($userData['full_name'] ?? ''));
$conn = db();
$notifications = [];

if ($userId > 0) {
    $stmt = $conn->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
}

function student_notifications_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function student_notifications_date(array $notification)
{
    foreach (['created_at', 'sent_at', 'updated_at'] as $field) {
        if (!empty($notification[$field])) {
            $timestamp = strtotime((string) $notification[$field]);
            if ($timestamp !== false) {
                return date('j M Y, g:i A', $timestamp);
            }
        }
    }
    return '';
}
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications | <?= student_notifications_html($site_name); ?></title>
    <?php include 'layouts/user/header.php'; ?>
    <style>
        .student-notifications-shell { padding: var(--space-8) .75rem var(--space-12); }
        .student-notifications-list { display: grid; gap: var(--space-3); margin: 0 auto; max-width: 860px; }
        .student-notification-card { align-items: flex-start; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-xs); display: flex; gap: var(--space-4); padding: var(--space-5); }
        .student-notification-card.is-unread { border-color: color-mix(in srgb, var(--primary) 40%, var(--border)); box-shadow: var(--shadow-sm); }
        .student-notification-icon { align-items: center; background: var(--primary-soft); border-radius: var(--radius-md); color: var(--primary); display: inline-flex; flex: 0 0 2.7rem; height: 2.7rem; justify-content: center; }
        .student-notification-content { min-width: 0; }
        .student-notification-content h2 { color: var(--text-primary); font-size: 1rem; margin: 0; }
        .student-notification-content p { color: var(--text-secondary); line-height: 1.6; margin: .4rem 0 0; white-space: pre-line; }
        .student-notification-meta { color: var(--text-muted); display: block; font-size: .76rem; margin-top: .65rem; }
        .student-notifications-empty { margin: 0 auto; max-width: 860px; min-height: 300px; }
        .student-notifications-empty > span { color: var(--secondary); font-size: 2rem; }
        @media (max-width: 575.98px) {
            .student-notifications-shell { padding-left: .75rem; padding-right: .75rem; }
            .student-notification-card { gap: var(--space-3); padding: var(--space-4); }
        }
    </style>
</head>
<body class="body ds-bg-primary student-dashboard-page notifications-page" style="margin-top: 65px">
<div id="app">
    <div id="body-overlay" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></div>
    <?php include 'layouts/user/aside.php'; ?>
    <main class="font-2">
        <section class="student-dashboard-top unified-private-hero" aria-label="Notifications introduction">
            <div class="container">
                <section class="student-dashboard-welcome">
                    <div>
                        <span class="student-dashboard-eyebrow">Stay up to date</span>
                        <h1>Notifications</h1>
                        <p>Important updates about your learning journey, all in one place.</p>
                    </div>
                </section>
            </div>
            <div class="student-dashboard-nav-wrap">
                <div class="container p-0"><div class="col-12 row user-menu"><nav class="navbar navbar-expand-lg navbar-light ds-surface-muted"><div class="container-fluid p-0"><?php include 'layouts/user/main-nav.php'; ?></div></nav></div></div>
            </div>
        </section>
        <section class="container student-notifications-shell" aria-label="Your notifications">
            <?php if (empty($notifications)): ?>
                <div class="student-dashboard-empty student-notifications-empty">
                    <span class="fas fa-bell" aria-hidden="true"></span>
                    <h2>No notifications yet</h2>
                    <p>We'll notify you about homework, live sessions, grades and announcements.</p>
                </div>
            <?php else: ?>
                <div class="student-notifications-list">
                    <?php foreach ($notifications as $notification): ?>
                        <?php
                        $notificationTitle = trim((string) ($notification['title'] ?? '')) ?: 'Notification';
                        $notificationMessage = trim((string) ($notification['message'] ?? ''));
                        $notificationDate = student_notifications_date($notification);
                        $isUnread = (string) ($notification['status'] ?? '1') === '0';
                        ?>
                        <article class="student-notification-card<?= $isUnread ? ' is-unread' : ''; ?>">
                            <span class="student-notification-icon fas fa-bell" aria-hidden="true"></span>
                            <div class="student-notification-content">
                                <h2><?= student_notifications_html($notificationTitle); ?></h2>
                                <?php if ($notificationMessage !== ''): ?><p><?= student_notifications_html($notificationMessage); ?></p><?php endif; ?>
                                <?php if ($notificationDate !== ''): ?><time class="student-notification-meta" datetime="<?= student_notifications_html((string) ($notification['created_at'] ?? $notification['sent_at'] ?? $notification['updated_at'] ?? '')); ?>"><?= student_notifications_html($notificationDate); ?></time><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php include 'layouts/user/footer.php'; ?>
</div>
</body>
</html>
