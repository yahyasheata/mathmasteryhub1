<?php
$user_data = getUserData($username);
$admin_name = explode(' ',$user_data['full_name'])[0];
$avatar = $user_data['avatar'];
$adminAvatarUrl = mmh_site_public_url(mmh_site_settings_valid_local_asset($avatar) ?? 'uploads/default/avatar.png');
// $pageName = 'xxx';
function setActive($name = 'home')
{
    global $pageName;
    if (isset($pageName) && $pageName == $name) {
        echo "active";
    }

}
function subSetActive($name = 'home')
{
    global $subPageName;
    if (isset($subPageName) && $subPageName == $name) {
        echo "active";
    }

}
function openMenu($name = 'home')
{
    global $pageName;
    if (isset($pageName) && $pageName == $name) {
        echo "menu-open";
    }

}

function isActive($page)
{
    global $pageName;
    return (basename($_SERVER['PHP_SELF']) == $page) ? 'active' : '';
}

// echo setActive('teachers');
?>
<aside id="admin-sidebar" class='aside active ds-surface' style="width: 260px; min-height: 100vh; position: fixed; z-index: 900">
    <div class="col-12 px-0 d-flex" style="height: 55px">
        <div class='col-12 p-1 ds-text-secondary'>
            <div class="col-12 p-0 row">
                <div class="col-3 py-1 px-1">

                </div>
                <div class="col-9">

                    <button type="button"
                        style="width: 55px; height: 55px; position: absolute; left: 0px; top: 0px; align-items: center; justify-content: center; cursor: pointer"
                        class="admin-sidebar-toggle d-flex d-md-none rounded-0" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Close sidebar">
                        <span class="fas fa-bars font-4" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 px-0 pb-4 text-center justify-content-center align-items-center">
        <a href="profile">

            <img src="<?=$adminAvatarUrl?>"
                style="width: 80px;height: 80px;color: var(--background-1);border-radius: 50%" class="d-inline-block">
        </a>
        <div class='col-12 px-0 mt-2 text-center ds-text-primary'>
            Welcome, <?=$admin_name?>
        </div>
    </div>
    <div class="col-12 px-0">

        <div class="col-12 px-3 aside-menu" style="height: calc(100vh - 260px); overflow: auto">

            <a href="dashboard" class="col-12 px-0 <?= ($pageName == 'dashboard') ? 'active' : ''; ?>">
                <div class="col-12 item-container px-0 d-flex <?= ($pageName == 'dashboard') ? 'active' : ''; ?>">
                    <div style="width: 50px" class="px-3 text-center">
                        <span class="fas fa-home font-2 ds-nav-icon" aria-hidden="true"> </span>
                    </div>
                    <div style="width: calc(100% - 50px)" class="px-2 item-container-title">
                        Dashboard
                    </div>
                </div>
            </a>

            <div class="col-12 px-0 admin-nav-group" data-admin-nav-group="courses">
                <div class="col-12 item px-0 d-flex row">
                    <button type="button" class="col-12 d-flex px-0 item-container admin-nav-submenu-toggle <?= ($pageName == 'courses') ? 'active' : ''; ?>"
                        data-admin-submenu-toggle="admin-courses-submenu"
                        aria-controls="admin-courses-submenu"
                        aria-expanded="<?= ($pageName == 'courses') ? 'true' : 'false'; ?>">
                        <div style="width: 50px" class="px-3 text-center">
                            <span class="fas fa-play-circle font-2 ds-nav-icon" aria-hidden="true"> </span>
                        </div>
                        <div style="width: calc(100% - 50px)" class="px-2 item-container-title has-sub-menu">
                            Courses
                        </div>
                    </button>
                    <div class="col-12 px-0">
                        <ul id="admin-courses-submenu" class="sub-item font-1 <?= ($pageName == 'courses') ? 'active is-open' : ''; ?>"
                            data-admin-submenu data-route-active="<?= ($pageName == 'courses') ? 'true' : 'false'; ?>"
                            <?= ($pageName == 'courses') ? '' : 'hidden'; ?>
                            style="list-style:none;">
                            
                            <li>
                                <a href="categories" style="font-size: 16px; " class="<?= ($subPageName == 'categories') ? 'active': ''; ?>">
                                    <span class="fas fa-tag px-2 ds-nav-icon" aria-hidden="true" style="width: 28px; font-size: 15px"></span>
                                    Categories
                                </a>
                            </li>
                            
                            <li>
                                <a href="courses" style="font-size: 16px; " class="<?= ($subPageName == 'courses') ? 'active': ''; ?>">
                                    <span class="fas fa-play-circle px-2 ds-nav-icon" aria-hidden="true" style="width: 28px; font-size: 15px"></span>
                                    Courses
                                </a>
                            </li>

                            <li>
                                <a href="live-sessions" style="font-size: 16px; " class="<?= ($subPageName == 'live_sessions') ? 'active': ''; ?>">
                                    <span class="fas fa-video px-2 ds-nav-icon" aria-hidden="true" style="width: 28px; font-size: 15px"></span>
                                    Live Sessions
                                </a>
                            </li>
                            
                            <li class="admin-nav-subheading"><span class="fas fa-clipboard-check ds-nav-icon" aria-hidden="true"></span> Assessments</li>
                            <li>
                                <a href="assignments" style="font-size: 16px; " class="<?= ($subPageName == 'assignments') ? 'active': ''; ?>">
                                    <span class="fas fa-tasks px-2 ds-nav-icon" aria-hidden="true" style="width: 28px; font-size: 15px"></span>
                                    Assignments
                                </a>
                            </li>
                            <li>
                                <a href="exams" style="font-size: 16px; " class="<?= ($subPageName == 'exams') ? 'active': ''; ?>">
                                    <span class="fas fa-file-alt px-2 ds-nav-icon" aria-hidden="true" style="width: 28px; font-size: 15px"></span>
                                    Exams &amp; Quizzes
                                </a>
                            </li>


                        </ul>
                    </div>
                </div>
            </div>

            <a href="files" class="col-12 px-0 <?= ($pageName == 'files') ? 'active' : ''; ?>">
                <div class="col-12 item-container px-0 d-flex <?= ($pageName == 'files') ? 'active' : ''; ?>">
                    <div style="width: 50px" class="px-3 text-center">
                        <span class="fas fa-folder-open font-2 ds-nav-icon" aria-hidden="true"> </span>
                    </div>
                    <div style="width: calc(100% - 50px)" class="px-2 item-container-title">
                        Media Library
                    </div>
                </div>
            </a>

            <a href="past-papers" class="col-12 px-0 <?= ($pageName == 'past_papers') ? 'active' : ''; ?>">
                <div class="col-12 item-container px-0 d-flex <?= ($pageName == 'past_papers') ? 'active' : ''; ?>">
                    <div style="width: 50px" class="px-3 text-center">
                        <span class="fas fa-file-alt font-2 ds-nav-icon" aria-hidden="true"> </span>
                    </div>
                    <div style="width: calc(100% - 50px)" class="px-2 item-container-title">
                        Past Papers
                    </div>
                </div>
            </a>


            <a href="free-learning" class="col-12 px-0 <?= ($pageName == 'free_learning') ? 'active' : ''; ?>">
                <div class="col-12 item-container px-0 d-flex <?= ($pageName == 'free_learning') ? 'active' : ''; ?>">
                    <div style="width: 50px" class="px-3 text-center">
                        <span class="fas fa-book-open font-2 ds-nav-icon" aria-hidden="true"> </span>
                    </div>
                    <div style="width: calc(100% - 50px)" class="px-2 item-container-title">
                        Free Learning
                    </div>
                </div>
            </a>

            <a href="users" class="col-12 px-0 <?= ($pageName == 'users') ? 'active' : ''; ?>">
                <div class="col-12 item-container px-0 d-flex <?= ($pageName == 'users') ? 'active' : ''; ?>">
                    <div style="width: 50px" class="px-3 text-center">
                        <span class="fas fa-users font-2 ds-nav-icon" aria-hidden="true"> </span>
                    </div>
                    <div style="width: calc(100% - 50px)" class="px-2 item-container-title">
                        Users
                    </div>
                </div>
            </a>

            <a href="parent-reports" class="col-12 px-0 <?= ($pageName == 'parent_reports') ? 'active' : ''; ?>">
                <div class="col-12 item-container px-0 d-flex <?= ($pageName == 'parent_reports') ? 'active' : ''; ?>">
                    <div style="width: 50px" class="px-3 text-center"><span class="fas fa-file-alt font-2 ds-nav-icon" aria-hidden="true"></span></div>
                    <div style="width: calc(100% - 50px)" class="px-2 item-container-title">Parent Reports</div>
                </div>
            </a>

            <a href="settings" class="col-12 px-0 <?= ($pageName == 'settings') ? 'active' : ''; ?>">
                <div class="col-12 item-container px-0 d-flex <?= ($pageName == 'settings') ? 'active' : ''; ?>">
                    <div style="width: 50px" class="px-3 text-center">
                        <span class="fas fa-wrench font-2 ds-nav-icon" aria-hidden="true"> </span>
                    </div>
                    <div style="width: calc(100% - 50px)" class="px-2 item-container-title">
                        Settings
                    </div>
                </div>
            </a>

            <a href="logout" class="col-12 px-0" onclick="document.getElementById('logout-form').submit();">
                <div class="col-12 item-container px-0 d-flex">
                    <div style="width: 50px" class="px-3 text-center">
                        <span class="fas fa-sign-out-alt font-2 ds-nav-icon" aria-hidden="true"> </span>
                    </div>
                    <div style="width: calc(100% - 50px)" class="px-2 item-container-title">
                        Logout
                    </div>
                </div>
            </a>
        </div>
    </div>

</div>
