<?php 
$user_data = getUserData($username);
$user_full_name = explode(' ',$user_data['full_name'])[0];
$user_balance = $user_data['balance'];
$adminTopAvatarUrl = mmh_site_public_url(mmh_site_settings_valid_local_asset($user_data['avatar'] ?? '') ?? 'uploads/default/avatar.png');
?>
<div class='col-12 px-0 d-flex justify-content-between top-nav admin-top-nav ds-surface ds-border' style="height: 55px;"
   >
    <button type="button" class="col-12 px-0 d-flex justify-content-center align-items-center btn admin-sidebar-toggle"
        style="width: 55px; height: 55px" aria-controls="admin-sidebar" aria-expanded="true" aria-label="Collapse sidebar">
        <span class="fas fa-bars font-4" aria-hidden="true"></span>
    </button>
    <div class="col-12 px-0 d-flex justify-content-end" style="height: 60px">




        <div class="btn-group" id="notificationDropdown" style="display: none">

            <div class="col-12 px-0 d-flex justify-content-center align-items-center btn"
                style="width: 55px; height: 55px" data-bs-toggle="dropdown" aria-expanded="false"
                id="dropdown-notifications">
                <span class='fas fa-bell font-3 d-inline-block ds-text-secondary' style="transform: rotate(15deg)"
                   ></span>
                <span
                   
                    class='text-center ds-bg-danger ds-text-inverse' style="position: absolute; min-width: 25px; min-height: 25px; display: none; right: 0px; top: 0px; border-radius: 20px; font-size: 14px" id="dropdown-notifications-icon">0</span>

            </div>
            <div class="dropdown-menu py-0 rounded-0 border-0 shadow"
                style="cursor: auto!important; z-index: 20000; width: 350px; height: 450px; top: -3px!important">
                <div class="col-12 notifications-container" style="height: 406px; overflow: auto">
                    <div class='col-12 justify-content-center text-ceter d-flex my-auto align-items-center ds-text-secondary' style="height: 100%"
                       >
                        <div class="col-12 px-0 text-center">
                            <span class='fas fa-bell font-4 ds-text-secondary'></span>
                            <div class="col-12 px-0 text-center mt-2">
                                No notifications yet
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 d-flex border-top">
                    <a href="<?=$baseUrl?>/resources//admin/notifications" class="d-block py-2 px-3">
                        <div class="col-12 align-items-center">
                            <span class="fas fa-bell"></span> View All Notifications
                        </div>
                    </a>
                </div>
            </div>
        </div>
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
                <li><a class="dropdown-item font-1" href="profile"><span
                            class="fas fa-user font-1"></span> My Profile</a></li>

                <li><a class="dropdown-item font-1" href="profile"><span
                            class="fas fa-edit font-1"></span> Edit Profile</a></li>


<!-- 

                <li><a class="dropdown-item font-1" href="<?=$baseUrl?>/resources//admin/files"><span
                            class="fas fa-file font-1"></span> Media Library</a></li>


                <li><a class="dropdown-item font-1" href="<?=$baseUrl?>/resources//admin/traffics"><span
                            class="fas fa-traffic-light font-1"></span> Traffic</a></li>

                <li><a class="dropdown-item font-1" href="<?=$baseUrl?>/resources//admin/error-reports"><span
                            class="fas fa-bug font-1"></span> Error Reports</a></li> -->




                <li>
                    <hr style="height: 1px; margin: 10px 0px 5px">
                </li>
                <li><a class="dropdown-item font-1" href="<?=rtrim((string) ($baseUrl ?? ''), '/')?>/admin/logout" onclick="var f=document.getElementById('admin-sidebar-logout-form') || document.getElementById('logout-form'); if(f){event.preventDefault(); f.submit();} return false;"><span
                            class="fas fa-sign-out-alt font-1"></span> Logout</a></li>
            </ul>

        </div>

        <div class='dropdown ds-gradient-primary' style="width: 55px; height: 55px">
            <span class="d-inline-block fas fa-user"></span>
        </div>

    </div>
</div>
