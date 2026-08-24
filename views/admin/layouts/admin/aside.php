<?php
/* The shell is shared by old and new admin entry points. Treat its inputs as
 * optional so compatibility pages cannot emit warnings before rendering. */
$adminUsername = trim((string) ($username ?? ($_SESSION['admin'] ?? '')));
$user_data = $adminUsername !== '' && function_exists('getUserData')
    ? (getUserData($adminUsername) ?: [])
    : [];
$admin_name = trim((string) (($user_data['full_name'] ?? '') ?: $adminUsername));
$admin_name = preg_split('/\s+/', $admin_name)[0] ?? $admin_name;
$admin_name = $admin_name !== '' ? $admin_name : 'Administrator';
$adminAvatarUrl = mmh_site_public_url(mmh_site_settings_valid_local_asset($user_data['avatar'] ?? '') ?? 'uploads/default/avatar.png');
$adminBase = rtrim((string) ($baseUrl ?? ''), '/');
$adminPageName = (string) ($pageName ?? '');
$adminSubPageName = (string) ($subPageName ?? '');
$isPage = static function (string $name) use ($adminPageName, $adminSubPageName): bool {
    return $adminPageName === $name || $adminSubPageName === $name;
};
$isGroup = static function (array $names) use ($adminPageName, $adminSubPageName): bool {
    return in_array($adminPageName, $names, true) || in_array($adminSubPageName, $names, true);
};
?>
<form id="admin-sidebar-logout-form" method="post" action="<?=$adminBase?>/admin/logout" class="d-none">
    <input type="hidden" name="mmh_csrf_token" value="<?=htmlspecialchars(mmh_admin_csrf_token(), ENT_QUOTES, 'UTF-8')?>">
</form>
<aside id="admin-sidebar" class="aside admin-sidebar ds-surface" aria-label="Administrator navigation">
    <div class="admin-sidebar-brand">
        <a href="dashboard" class="admin-sidebar-profile">
            <img src="<?=$adminAvatarUrl?>" alt="" class="admin-sidebar-avatar">
            <span><small>Administrator</small><strong><?=htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8')?></strong></span>
        </a>
        <button type="button" class="admin-sidebar-toggle admin-sidebar-close d-md-none" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Close sidebar">
            <span class="fas fa-times" aria-hidden="true"></span>
        </button>
    </div>

    <nav class="admin-sidebar-nav" aria-label="Admin sections">
        <a href="dashboard" class="admin-nav-link <?=$isPage('dashboard') ? 'active' : ''?>">
            <span class="fas fa-home" aria-hidden="true"></span><span>Dashboard</span>
        </a>

        <section class="admin-nav-group" data-admin-nav-group="course-management">
            <button type="button" class="admin-nav-group-toggle <?=$isGroup(['courses', 'course_content', 'categories']) ? 'active' : ''?>" data-admin-submenu-toggle="admin-course-management-menu" aria-controls="admin-course-management-menu" aria-expanded="<?=$isGroup(['courses', 'course_content', 'categories']) ? 'true' : 'false'?>">
                <span class="fas fa-book" aria-hidden="true"></span><span>Course Management</span><span class="fas fa-chevron-down admin-nav-chevron" aria-hidden="true"></span>
            </button>
            <ul id="admin-course-management-menu" class="admin-nav-submenu" data-admin-submenu data-route-active="<?=$isGroup(['courses', 'course_content', 'categories']) ? 'true' : 'false'?>" <?= $isGroup(['courses', 'course_content', 'categories']) ? '' : 'hidden' ?>>
                <li><a href="courses" class="<?=$isPage('courses') ? 'active' : ''?>"><span class="fas fa-book-open" aria-hidden="true"></span>Courses</a></li>
                <li><a href="categories" class="<?=$isPage('categories') ? 'active' : ''?>"><span class="fas fa-tags" aria-hidden="true"></span>Categories</a></li>
                <li><span class="admin-nav-note">Open Course Content from a course</span></li>
            </ul>
        </section>

        <section class="admin-nav-group" data-admin-nav-group="assessments">
            <button type="button" class="admin-nav-group-toggle <?=$isGroup(['exams', 'timed_exam_submissions', 'exam_submissions']) ? 'active' : ''?>" data-admin-submenu-toggle="admin-assessments-menu" aria-controls="admin-assessments-menu" aria-expanded="<?=$isGroup(['exams', 'timed_exam_submissions', 'exam_submissions']) ? 'true' : 'false'?>">
                <span class="fas fa-clipboard-check" aria-hidden="true"></span><span>Assessments</span><span class="fas fa-chevron-down admin-nav-chevron" aria-hidden="true"></span>
            </button>
            <ul id="admin-assessments-menu" class="admin-nav-submenu" data-admin-submenu data-route-active="<?=$isGroup(['exams', 'timed_exam_submissions', 'exam_submissions']) ? 'true' : 'false'?>" <?= $isGroup(['exams', 'timed_exam_submissions', 'exam_submissions']) ? '' : 'hidden' ?>>
                <li><a href="exams" class="<?=$isPage('exams') ? 'active' : ''?>"><span class="fas fa-file-alt" aria-hidden="true"></span>Legacy Exams <small>Compatibility</small></a></li>
                <li><span class="admin-nav-note"><span class="fas fa-stopwatch" aria-hidden="true"></span>Timed Exams are managed inside Course Content</span></li>
            </ul>
        </section>

        <a href="live-sessions" class="admin-nav-link <?=$isPage('live_sessions') ? 'active' : ''?>"><span class="fas fa-video" aria-hidden="true"></span><span>Live Sessions</span></a>

        <section class="admin-nav-group" data-admin-nav-group="students">
            <button type="button" class="admin-nav-group-toggle <?=$isGroup(['users', 'previous-progress']) ? 'active' : ''?>" data-admin-submenu-toggle="admin-students-menu" aria-controls="admin-students-menu" aria-expanded="<?=$isGroup(['users', 'previous-progress']) ? 'true' : 'false'?>">
                <span class="fas fa-users" aria-hidden="true"></span><span>Students</span><span class="fas fa-chevron-down admin-nav-chevron" aria-hidden="true"></span>
            </button>
            <ul id="admin-students-menu" class="admin-nav-submenu" data-admin-submenu data-route-active="<?=$isGroup(['users', 'previous-progress']) ? 'true' : 'false'?>" <?= $isGroup(['users', 'previous-progress']) ? '' : 'hidden' ?>>
                <li><a href="users" class="<?=$isPage('users') ? 'active' : ''?>"><span class="fas fa-user-graduate" aria-hidden="true"></span>Students</a></li>
                <li><span class="admin-nav-note"><span class="fas fa-user-plus" aria-hidden="true"></span>Enrollments are managed from student records</span></li>
                <li><a href="previous-progress" class="<?=$isPage('previous-progress') ? 'active' : ''?>"><span class="fas fa-route" aria-hidden="true"></span>Learning Journey</a></li>
            </ul>
        </section>

        <section class="admin-nav-group" data-admin-nav-group="support">
            <button type="button" class="admin-nav-group-toggle <?=$isGroup(['recovery_plan', 'recovery_plan_templates', 'recovery_plan_assignments', 'revision_plans', 'parent_reports']) ? 'active' : ''?>" data-admin-submenu-toggle="admin-support-menu" aria-controls="admin-support-menu" aria-expanded="<?=$isGroup(['recovery_plan', 'recovery_plan_templates', 'recovery_plan_assignments', 'revision_plans', 'parent_reports']) ? 'true' : 'false'?>">
                <span class="fas fa-life-ring" aria-hidden="true"></span><span>Student Support</span><span class="fas fa-chevron-down admin-nav-chevron" aria-hidden="true"></span>
            </button>
            <ul id="admin-support-menu" class="admin-nav-submenu" data-admin-submenu data-route-active="<?=$isGroup(['recovery_plan', 'recovery_plan_templates', 'recovery_plan_assignments', 'revision_plans', 'parent_reports']) ? 'true' : 'false'?>" <?= $isGroup(['recovery_plan', 'recovery_plan_templates', 'recovery_plan_assignments', 'revision_plans', 'parent_reports']) ? '' : 'hidden' ?>>
                <li><a href="recovery-plan" class="<?=$isPage('recovery_plan') ? 'active' : ''?>"><span class="fas fa-route" aria-hidden="true"></span>Recovery Plans</a></li>
                <li><a href="recovery-plan-templates" class="<?=$isPage('recovery_plan_templates') ? 'active' : ''?>"><span class="fas fa-layer-group" aria-hidden="true"></span>Templates</a></li>
                <li><a href="recovery-plan-assignments" class="<?=$isPage('recovery_plan_assignments') ? 'active' : ''?>"><span class="fas fa-user-check" aria-hidden="true"></span>Assignments</a></li>
                <li><a href="revision-plans" class="<?=$isPage('revision_plans') ? 'active' : ''?>"><span class="fas fa-calendar-check" aria-hidden="true"></span>Revision Plans</a></li>
                <li><a href="parent-reports" class="<?=$isPage('parent_reports') ? 'active' : ''?>"><span class="fas fa-file-alt" aria-hidden="true"></span>Parent Reports</a></li>
            </ul>
        </section>

        <section class="admin-nav-group" data-admin-nav-group="resources">
            <button type="button" class="admin-nav-group-toggle <?=$isGroup(['files', 'past_papers', 'free_learning']) ? 'active' : ''?>" data-admin-submenu-toggle="admin-resources-menu" aria-controls="admin-resources-menu" aria-expanded="<?=$isGroup(['files', 'past_papers', 'free_learning']) ? 'true' : 'false'?>">
                <span class="fas fa-folder-open" aria-hidden="true"></span><span>Resources</span><span class="fas fa-chevron-down admin-nav-chevron" aria-hidden="true"></span>
            </button>
            <ul id="admin-resources-menu" class="admin-nav-submenu" data-admin-submenu data-route-active="<?=$isGroup(['files', 'past_papers', 'free_learning']) ? 'true' : 'false'?>" <?= $isGroup(['files', 'past_papers', 'free_learning']) ? '' : 'hidden' ?>>
                <li><a href="files" class="<?=$isPage('files') ? 'active' : ''?>"><span class="fas fa-photo-video" aria-hidden="true"></span>Media Library</a></li>
                <li><a href="past-papers" class="<?=$isPage('past_papers') ? 'active' : ''?>"><span class="fas fa-file-alt" aria-hidden="true"></span>Past Papers</a></li>
                <li><a href="free-learning" class="<?=$isPage('free_learning') ? 'active' : ''?>"><span class="fas fa-book-open" aria-hidden="true"></span>Free Learning</a></li>
            </ul>
        </section>

        <section class="admin-nav-group" data-admin-nav-group="communication">
            <button type="button" class="admin-nav-group-toggle" data-admin-submenu-toggle="admin-communication-menu" aria-controls="admin-communication-menu" aria-expanded="false">
                <span class="fas fa-comments" aria-hidden="true"></span><span>Communication</span><span class="fas fa-chevron-down admin-nav-chevron" aria-hidden="true"></span>
            </button>
            <ul id="admin-communication-menu" class="admin-nav-submenu" data-admin-submenu data-route-active="false" hidden>
                <li><span class="admin-nav-note"><span class="fas fa-bell" aria-hidden="true"></span> Notifications are sent from student and course actions</span></li>
            </ul>
        </section>

        <section class="admin-nav-group" data-admin-nav-group="system">
            <button type="button" class="admin-nav-group-toggle <?=$isGroup(['settings', 'admin-management']) ? 'active' : ''?>" data-admin-submenu-toggle="admin-system-menu" aria-controls="admin-system-menu" aria-expanded="<?=$isGroup(['settings', 'admin-management']) ? 'true' : 'false'?>">
                <span class="fas fa-cog" aria-hidden="true"></span><span>System</span><span class="fas fa-chevron-down admin-nav-chevron" aria-hidden="true"></span>
            </button>
            <ul id="admin-system-menu" class="admin-nav-submenu" data-admin-submenu data-route-active="<?=$isGroup(['settings', 'admin-management']) ? 'true' : 'false'?>" <?= $isGroup(['settings', 'admin-management']) ? '' : 'hidden' ?>>
                <li><a href="settings" class="<?=$isPage('settings') ? 'active' : ''?>"><span class="fas fa-sliders-h" aria-hidden="true"></span>Site Settings</a></li>
                <li><a href="admin-management" class="<?=$isPage('admin-management') ? 'active' : ''?>"><span class="fas fa-user-shield" aria-hidden="true"></span>Admin Management</a></li>
                <li><span class="admin-nav-note">Payments, maintenance, authentication, and landing page settings are organized inside Site Settings</span></li>
            </ul>
        </section>
    </nav>

    <div class="admin-sidebar-footer">
        <a href="profile" class="admin-nav-link"><span class="fas fa-user" aria-hidden="true"></span><span>My Profile</span></a>
        <a href="<?=$adminBase?>/admin/logout" class="admin-nav-link admin-nav-logout" onclick="var f=document.getElementById('admin-sidebar-logout-form'); if(f){event.preventDefault(); f.submit();} return false;"><span class="fas fa-sign-out-alt" aria-hidden="true"></span><span>Logout</span></a>
    </div>
</aside>
<script>document.documentElement.lang='en';document.documentElement.dir='ltr';</script>
