<?php 
    $site_settings = getSiteSettings();



?>
<div class="col-12 fixed-top main-nav shadow ds-surface" style="padding: 3px 0px; min-height: 65px;">
    <div class="container px-1 my-auto">
        <div class="col-12 row p-0">
            <div class="col-auto p-3 d-flex align-items-center hover-main-color-flexable" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');" style="cursor: pointer;">
                <span class="far fa-bars font-3 px-0"></span>
            </div>
            <div class="col-auto d-flex align-items-center px-1 py-2">
                <a href="/">
                    <img src="<?=rtrim((string)$baseUrl, '/')?>/<?=$site_settings['website_logo']?>" style="width: 105px;" alt="<?=$site_name;?>" class="site-logo mathhub-logo mathhub-logo--light" >
                    <img src="<?=rtrim((string)$baseUrl, '/')?>/resources/images/branding/mathhub-logo-white.png" style="width: 105px;" alt="<?=$site_name;?>" class="site-logo mathhub-logo mathhub-logo--dark">
                </a>
            </div>
            <div class="col me-auto p-0 row justify-content-between d-flex">
                <div class="col row m-0 px-2">

                                                                                <div class="col-auto  d-none d-lg-flex align-items-center p-0 mx-1 " >
                        <a href="<?=$baseUrl?>" class="d-flex align-items-center py-2 px-3 top-navbar-link rounded" style="color: inherit;">
                            <span class="fas fa-play-circle mx-1"></span> My Courses
                        </a>
                    </div>
                                        <!-- <div class="col-auto  d-none d-lg-flex align-items-center p-0 mx-1 " >
                        <a href="<?=$baseUrl?>/resources/blog" class="d-flex align-items-center py-2 px-3 top-navbar-link rounded" style="color: inherit;">
                            <span class="fas fa-pen-alt mx-1"></span> Blog
                        </a>
                    </div>
                                        <div class="col-auto  d-none d-lg-flex align-items-center p-0 mx-1 " >
                        <a href="<?=$baseUrl?>/resources/page/terms" class="d-flex align-items-center py-2 px-3 top-navbar-link rounded" style="color: inherit;">
                            <span class="fas fa-lock mx-1"></span> Terms of Use
                        </a>
                    </div> -->
                                        <div class="col-auto  d-none d-lg-flex align-items-center p-0 mx-1 " >
                        <a href="<?=$baseUrl?>/resources/contact" class="d-flex align-items-center py-2 px-3 top-navbar-link rounded" style="color: inherit;">
                            <span class="fas fa-phone mx-1"></span> Contact Us
                        </a>
                    </div>
                                                        </div>
                <div class="col-auto  d-flex align-items-center px-1 ">
                    

                                                                                    <div class="btn-group" id="notificationDropdown">

                        <div class="col-12 px-0 d-flex justify-content-center align-items-center " style="width: 55px;height: 55px;cursor: pointer" data-bs-toggle="dropdown" aria-expanded="false" id="dropdown-notifications">
                            <span class="fas fa-bell font-3 d-inline-block" style="color: var(--color-2);transform: rotate(15deg);"></span>
                            <span style="position: absolute;min-width: 25px;min-height: 25px;
                                                        display: none;
                                                        right: 0px;top: 0px;border-radius: 20px;font-size: 14px;" class="text-center ds-bg-danger" id="dropdown-notifications-icon">0</span>

                        </div>
                        <div class="dropdown-menu dropdown-menu-end py-0 rounded-0 border-0 shadow " style="cursor: auto!important;z-index: 20000;width: 350px;height: 450px;">
                            <div class="col-12 notifications-container" style="height:406px;overflow: auto;">
                                <div class="col-12 justify-content-center text-ceter d-flex my-auto align-items-center" style="color: var(--bg-color-0);height: 100%;">
    <div class="col-12 px-0 text-center"> 
        <span class="fas fa-bell font-4" style="color:var(--bg-color-4);"></span>
        <div class="col-12 px-0 text-center mt-2">
            No notifications yet
        </div>
    </div>
</div>
                            </div>
                            <div class="col-12 d-flex border-top ds-border-top"> 
                                <a href="<?=$baseUrl?>/resources/dashboard/notifications" class="d-block py-2 px-3 ">
                                    <div class="col-12 align-items-center">
                                      <span class="fas fa-bell"></span> View All Notifications
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 px-0 d-flex justify-content-center align-items-center  dropdown"  style="width: 55px;height: 55px;" >
                        <div style="width: 55px;height: 55px;cursor: pointer;" data-bs-toggle="dropdown" aria-expanded="false" class="d-flex justify-content-center align-items-center cursor-pointer">
                            <img src="../<?=$user_data['avatar']?>" style="padding: 10px;border-radius: 50%;width: 55px;height: 55px;" alt="User">
                        </div>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" aria-labelledby="dropdownMenuButton1" style="top: -3px;">
                                <li><a class="dropdown-item font-1" href="<?=$baseUrl?>/resources/dashboard" ><span class="fas fa-sliders-h font-1" style="width: 20px;"></span> Dashboard</a></li>
                                <li><a class="dropdown-item font-1" href="<?=$baseUrl?>/resources/dashboard/support"><span class="fas fa-comments font-1" style="width: 20px;"></span> Support</a></li>

                        

                                <li><a class="dropdown-item font-1" href="<?=$baseUrl?>/resources/dashboard/profile/settings"><span class="fas fa-wrench font-1" style="width: 20px;"></span> Settings</a></li>

                                <li><a class="dropdown-item font-1" href="<?=$baseUrl?>/resources/dashboard/notifications"><span class="fas fa-bell font-1" style="width: 20px;"></span> Notifications</a></li> 
                           
                                <li><hr style="height: 1px;margin: 10px 0px 5px;"></li>
                                <li><a class="dropdown-item font-1"  onclick="document.getElementById('logout-form').submit();" style="cursor:pointer;"><span class="fas fa-sign-out-alt font-1" style="width: 20px;"></span> Logout</a></li>
                        </ul>

                    </div>
                                    </div>
            </div>
        </div>
    </div>
</div>
<div id="aside-menu" class=" shadow">
    <div class="col-12 d-flex justify-content-between  align-items-center p-0 shadow" style="height:65px">
        <span class="px-3 font-1 kufi">

            <img src="<?=rtrim((string)$baseUrl, '/')?>/<?=$site_settings['website_logo']?>" style="width: 105px;" alt="<?=$site_name;?>" class="site-logo mathhub-logo mathhub-logo--light">
            <img src="<?=rtrim((string)$baseUrl, '/')?>/resources/images/branding/mathhub-logo-white.png" style="width: 105px;" alt="<?=$site_name;?>" class="site-logo mathhub-logo mathhub-logo--dark">

        </span>
        <span class="d-flex">
            <span class="font-1"><span class="far fa-times font-3 px-4 py-3" style="cursor: pointer;" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></span></span>
        </span>
    </div>
    <div class="col-12 p-0">
        <div class="col-12 p-0 aside-scroll pt-2" style="height: calc(100vh - 186px);overflow: auto;position: relative;">

                                                <div class="nav-item ">
                <a href="<?=$baseUrl?>" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer;" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fas fa-play-circle mx-1"></span> My Courses
                        </div>
                    </div>
                </div>
                </a>
            </div>
                        <!-- <div class="nav-item ">
                <a href="<?=$baseUrl?>/resources/blog" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer;" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fas fa-pen-alt mx-1"></span> Blog
                        </div>
                    </div>
                </div>
                </a>
            </div>
                        <div class="nav-item ">
                <a href="<?=$baseUrl?>/resources/page/about" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer;" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fas fa-info mx-1"></span> About Us
                        </div>
                    </div>
                </div>
                </a>
            </div>
                        <div class="nav-item ">
                <a href="<?=$baseUrl?>/resources/page/terms" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer;" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fas fa-lock mx-1"></span> Terms of Use
                        </div>
                    </div>
                </div>
                </a>
            </div>
                        <div class="nav-item ">
                <a href="<?=$baseUrl?>/resources/page/privacy" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer;" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fas fa-info mx-1"></span> Privacy Policy
                        </div>
                    </div>
                </div>
                </a>
            </div> -->
                        <div class="nav-item ">
                <a href="<?=$baseUrl?>/resources/contact" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer;" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fas fa-phone mx-1"></span> Contact Us
                        </div>
                    </div>
                </div>
                </a>
            </div>
                         
        
        </div>
        <div class="col-12 px-0 py-2" style="position:absolute;width: 100%;">
            <div class="col-12  p-0">
                <div class="col-12 p-0">
                    <ul style=";padding: 0px;list-style: none;min-height: 48px;" class="d-flex align-items-center justify-content-center row my-2">
                                                <a href="<?=$baseUrl?>/resources/admin/settings" class="d-inline-block p-1" style="width:48px">
                            <span class="fab fa-facebook-f d-inline-block border rounded-circle text-info" style="width: 40px;@height: 40px;padding: 11px 14px ;cursor: pointer;"></span>
                        </a>
                                                                        <a href="<?=$baseUrl?>/resources/admin/settings" class="d-inline-block p-1" style="width:48px">
                            <span class="fab fa-twitter d-inline-block border rounded-circle text-info" style="width: 40px;height: 40px;padding: 11px 11px ;cursor: pointer;"></span>
                        </a>
                                                                        <a href="<?=$baseUrl?>/resources/admin/settings" class="d-inline-block p-1" style="width:48px">
                            <span class="fab fa-youtube d-inline-block border rounded-circle text-info" style="width: 40px;height: 40px;padding: 11px 10px ;cursor: pointer;"></span>
                        </a>
                                                                                                <a href="<?=$baseUrl?>/resources/admin/settings" class="d-inline-block p-1" style="width:48px">
                            <span class="fab fa-telegram-plane d-inline-block border rounded-circle text-info" style="width: 40px;height: 40px;padding: 11px 12px ;cursor: pointer;"></span>
                        </a>
                                            </ul>
                </div>
                <div class="col-12 p-0 text-center" style="font-size: 12px;color: var(--font-1);">
                    All rights reserved © <?=$site_name;?> 2023 </div>
            </div>
        </div>
    </div>
</div>        
