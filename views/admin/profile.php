<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
$username = $_SESSION['admin'];

$pageName = "profile";

$user_data = getUserData($username);
$full_name = $user_data['full_name'];



?>
<!DOCTYPE html>
<html lang="en">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Profile |
        <?=$site_name;?>
    </title>
    <meta name="title" content="Profile | <?=$site_name;?>">
    <!---
وَما نَيلُ المَطالِبِ بِالتَمَنّي وَلَكِن تُؤخَذُ الدُنيا غِلاباوَ
ما اِستَعصى عَلى قَومٍ مَن الٌإِذا الإِقدامُ كانَ لَهُم رِكابا
أحمد شوقي
--->


    <?php include "layouts/admin/header.php"; ?>


</head>

<body class='dash ds-bg-primary'>
    <style type="text/css">
        #toast-container>div {
            opacity: 1;
        }

        .phpdebugbar * {
            direction: ltr !important
        }
    </style>
    <div class="col-12 justify-content-end d-flex">
    </div>
    <form method="POST" action="<?=$baseUrl?>/admin/logout" id="logout-form" class="d-none"><input type="hidden" name="mmh_csrf_token" value="<?=htmlspecialchars(mmh_admin_csrf_token(), ENT_QUOTES, 'UTF-8')?>"></form>
    <div class="col-12 d-flex">

        <?php include "layouts/admin/aside.php"; ?>


        <div class="main-content in-active" style="overflow: hidden">

            <?php include "layouts/admin/top-nav.php"; ?>


            <div class="col-12 px-0" style="margin-top: 55px; position: relative">
                <div
                    id="loading-image-container" class='ds-surface' style="position: fixed; display: flex; align-items: center; justify-content: center; height: 100vh; z-index: 10; margin-top: -15px">
                    <img src="<?=$baseUrl?>/resources//images/loading.gif"
                        style="position:fixed;width: 120px;max-width: 80%;margin-top: -60px;" id="loading-image">
                </div>
<div class="col-12 py-3">
	<div class="container">
		<div class="d-flex row m-0">
			<div class="col-12 col-lg-6 my-2">
				<form method="POST" action="" class="updateSettings" enctype="multipart/form-data">
					<input type="hidden" name="_token" value="RmvgJtwhQjaTDIX6sSYWraTn7PXXzq2m8II8Pi3A">					<input type="hidden" name="_method" value="PUT">					<div class="col-12 p-0 main-box shadow">
						<div class="col-12 px-0">
							<div class="col-12 px-3 py-3">
							 	<span class="fas fa-info-circle"></span>	Basic Information
							</div>
							<div class="col-12 divider" style="min-height: 2px"></div>
						</div>
						<div class="col-12 p-3">
							<div class="col-12 py-2 px-0 d-flex justify-content-center">
									<img src="../<?=$user_data['avatar']?>" style="width:150px;max-width: 100%;border-radius: 50%;" id="getUserAvatar">
							</div>
                            <div class="col-12 p-2">
                                <div class="col-12">
                                    Profile Picture
                                </div>
                                <div class="col-12 pt-3">
                                    <input type="file" name="avatar" class="form-control"  id="avatar-image">
                            </div>
                            </div>
							<div class="col-12 p-2">
								<div class="col-12">
									Full Name
								<span class='ds-text-danger' style="font-size: 16px">*</span></div>
								<div class="col-12 pt-3">
									<input type="text" name="full_name" required="" min="3" max="190" class="form-control" value="<?=$full_name?>" accept="image/*">
								</div>
							</div>
							
              <input type='hidden' name='update_main_info' value="1" />

							<div class="col-12 p-2">
								<div class="col-12 pt-3">
									<button class="btn btn-primary">Save Information</button>
									
								</div>
							</div>


						</div>
					</div>
				</form>
			</div>

            <div class="col-12 col-lg-6 my-2">
                <form method="POST" action="" class="updateSettings">
                    <input type="hidden" name="_token" value="RmvgJtwhQjaTDIX6sSYWraTn7PXXzq2m8II8Pi3A">                    <input type="hidden" name="_method" value="PUT">                    <div class="col-12 p-0 main-box shadow">
                        <div class="col-12 px-0">
                            <div class="col-12 px-3 py-3">
                                <span class="fas fa-key"></span>  Password
                            </div>
                            <div class="col-12 divider" style="min-height: 2px"></div>
                        </div>
                        <div class="col-12 p-3">
                            <div class="col-12 p-2">
                                <div class="col-12 pt-3">
                                    <div class="alert alert-warning">
                                        It is recommended to use a password with letters, numbers, and special characters such as (% $ # @).
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 p-2">
                                <div class="col-12">
                                    Current Password
                                <span class='ds-text-danger' style="font-size: 16px">*</span></div>
                                <div class="col-12 pt-3">
                                    <input type="password" name="old_password" class="form-control" required="" minlength="3" maxlength="190">
                                </div>
                            </div>

                            <div class="col-12 p-2">
                                <div class="col-12">
                                    New Password
                                <span class='ds-text-danger' style="font-size: 16px">*</span></div>
                                <div class="col-12 pt-3">
                                    <input type="password" name="password" class="form-control" required="" minlength="6" maxlength="190">
                                </div>
                            </div>
                            <div class="col-12 p-2">
                                <div class="col-12">
                                    Confirm New Password
                                <span class='ds-text-danger' style="font-size: 16px">*</span></div>
                                <div class="col-12 pt-3">
                                    <input type="password" name="password_confirmation" class="form-control" required="" minlength="6" maxlength="190">
                                </div>
                            </div>

                            <input type='hidden' name='update_password' value='1' />


                            <div class="col-12 p-2">
                                <div class="col-12 pt-3">
                                    <button class="btn btn-primary">Change Password</button>
                                    
                                </div>
                            </div>


                        </div>
                    </div>
                </form>
            </div>

		</div>
	</div>
</div>

            </div>
        </div>
    </div>


    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/dashboard-d03a2b4e.js" />
    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/main-07febffb.js" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/js/bootstrap.min.js"
        integrity="sha512-1/RvZTcCDEUjY/CypiMz+iqqtaoQfAITmNSJY17Myp4Ms5mdxPS5UV7iOfdZoxcGhzFbOm6sntTKJppjvuhg4g=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script type="module" src="<?=$baseUrl?>/resources/build/assets/dashboard-d03a2b4e.js"
        data-navigate-track="reload"></script>





        <script type="text/javascript">
$(document).ready(function() {

    var Toast = Swal.mixin({
      toast: true,
      position: 'top-end',
      showConfirmButton: false,
      timer: 10000
      });

    //*** Send Edit Request
    $(".updateSettings").on("submit", function (e) {
        e.preventDefault();
        $.ajax({
        type: "POST",
        url: "requests/settings/profile",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
            $(".submitBtn").attr("disabled", "disabled");
            $(".updateSettings").css("opacity", ".5");
        },
        success: function (response) {
            $(".response-msg").html("");
            if (response.status == 1) {
            $(".updateSettings")[0].reset();
            $(".response-msg").html(
                Swal.fire({
                icon: "success",
                title: response.message,
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true,
                }).then(function (isConfirm) {
                if (isConfirm) {
                    location.reload();
                } else {
                    //if no clicked => do something else
                }
                })
            );
            } else {
            $(".response-msg").html(
                Swal.fire({
                icon: "error",
                title: response.message,
                text: response.reason,
                showConfirmButton: true,
                timer: 10000,
                timerProgressBarColor: "var(--primary)",
                timerProgressBar: true,
                }).then(function (isConfirm) {
                if (isConfirm) {
                    // location.reload();
                } else {
                    //if no clicked => do something else
                }
                })
            );
            }
            $(".updateSettings").css("opacity", "");
            $(".submitBtn").removeAttr("disabled");
        },
        });
    });





  });
</script>







</body>

</html>
