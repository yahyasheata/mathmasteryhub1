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
            <span class="mmh-theme-switch__knob" aria-hidden="true"><svg class="mmh-theme-switch__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g class="mmh-theme-icon-sun" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2.5v2.2M12 19.3v2.2M4.7 4.7l1.55 1.55m11.5 11.5 1.55 1.55M2.5 12h2.2m14.6 0h2.2M4.7 19.3l1.55-1.55m11.5-11.5L19.3 4.7"></path></g><svg class="mmh-theme-icon-moon" x="0" y="0" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true" focusable="false" fill="#193A34"><path d="M6 .278a.77.77 0 0 1 .08.858 7.2 7.2 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277q.792-.001 1.533-.16a.79.79 0 0 1 .81.316.73.73 0 0 1-.031.893A8.35 8.35 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.75.75 0 0 1 6 .278"/><path d="M10.794 3.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387a1.73 1.73 0 0 0-1.097 1.097l-.387 1.162a.217.217 0 0 1-.412 0l-.387-1.162A1.73 1.73 0 0 0 9.31 6.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387a1.73 1.73 0 0 0 1.097-1.097zM13.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.16 1.16 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.16 1.16 0 0 0-.732-.732l-.774-.258a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732z"/></svg></svg></span>
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
