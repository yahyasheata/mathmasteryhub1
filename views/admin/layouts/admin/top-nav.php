<?php
$adminUsername = trim((string) ($username ?? ($_SESSION['admin'] ?? '')));
$user_data = $adminUsername !== '' && function_exists('getUserData')
    ? (getUserData($adminUsername) ?: [])
    : [];
$user_full_name = trim((string) ($user_data['full_name'] ?? ''));
$user_full_name = $user_full_name !== '' ? (string) (preg_split('/\s+/', $user_full_name)[0] ?? $user_full_name) : 'Administrator';
$adminTopAvatarUrl = mmh_site_public_url(mmh_site_settings_valid_local_asset($user_data['avatar'] ?? '') ?? 'uploads/default/avatar.png');
?>
<div class='col-12 px-0 d-flex justify-content-between top-nav admin-top-nav ds-surface ds-border' style="height: 55px;"
   >
    <button type="button" class="col-12 px-0 d-flex justify-content-center align-items-center btn admin-sidebar-toggle"
        style="width: 55px; height: 55px" aria-controls="admin-sidebar" aria-expanded="true" aria-label="Collapse sidebar">
        <span class="fas fa-bars font-4" aria-hidden="true"></span>
    </button>
    <div class="col-12 px-0 d-flex justify-content-end" style="height: 60px">




        <div class="col-12 px-0 d-flex justify-content-center align-items-center dropdown"
            style="width: 55px; height: 55px">
            <div style="width: 55px; height: 55px; cursor: pointer" data-bs-toggle="dropdown" aria-expanded="false"
                class="d-flex justify-content-center align-items-center cursor-pointer">
                <img src="<?=$adminTopAvatarUrl?>"
                    style="padding: 10px;border-radius: 50%;width: 55px;height: 55px;">
            </div>
            <ul class="dropdown-menu shadow border-0" aria-labelledby="dropdownMenuButton1" style="top: -3px">
                <li><a class="dropdown-item font-1" href="/" target="_blank"><span class="fas fa-desktop font-1"></span>
                        View Website</a></li>
                <li><a class="dropdown-item font-1" href="profile"><span class="fas fa-user font-1" aria-hidden="true"></span> My Profile</a></li>
            </ul>

        </div>

    </div>
</div>
