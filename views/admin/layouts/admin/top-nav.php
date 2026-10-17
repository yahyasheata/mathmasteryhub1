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
    <div class="col-12 px-0 d-flex justify-content-end align-items-center" style="height: 55px; gap: 12px">

        <button type="button" class="mmh-theme-switch admin-theme-switch" data-mmh-theme-toggle aria-label="Switch theme" aria-pressed="false">
            <span class="mmh-theme-switch__knob" aria-hidden="true"><svg class="mmh-theme-switch__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g class="mmh-theme-icon-sun" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2.5v2.2M12 19.3v2.2M4.7 4.7l1.55 1.55m11.5 11.5 1.55 1.55M2.5 12h2.2m14.6 0h2.2M4.7 19.3l1.55-1.55m11.5-11.5L19.3 4.7"></path></g><path class="mmh-theme-icon-moon" d="M19.3 14.2A7.8 7.8 0 0 1 9.8 4.7a7.9 7.9 0 1 0 9.5 9.5Z" fill="currentColor"></path></svg></span>
        </button>




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
