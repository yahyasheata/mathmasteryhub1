<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$pageName = "notifications";
$username = $_SESSION['username'];
$user_id = getUserInfo($username)->user_id;
// $user_data = getUserInfo($username);
$conn = db();


?>
<!doctype html>
<html lang="en" dir="ltr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="0PjjLlpM6FmXxBStqiehsWgYBO2MSNJkr7ZTKvPk">
    <title><?=$site_name;?> Notifications</title>
    <!---
وَما نَيلُ المَطالِبِ بِالتَمَنّي وَلَكِن تُؤخَذُ الدُنيا غِلاباوَ
ما اِستَعصى عَلى قَومٍ مَن الٌإِذا الإِقدامُ كانَ لَهُم رِكابا
أحمد شوقي
--->

    <?php include "layouts/user/header.php"; ?>

</head>

<body class='body ds-bg-primary' style="margin-top: 65px">
    <style type="text/css">
        #toast-container>div {
            opacity: 1;
        }
    </style>
    <div id="app">

        <div id="body-overlay"
            onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');">
        </div>
        <form id="logout-form" action="<?=$baseUrl?>/resources/logout" method="POST" class="d-none">
            <input type="hidden" name="_token" value="0PjjLlpM6FmXxBStqiehsWgYBO2MSNJkr7ZTKvPk">
        </form>

        <?php include "layouts/user/aside.php"; ?>

        <main class="p-0 font-2">
            <div class='col-12 ds-bg-primary' style="min-height: 100vh">
                <div class='col-12 p-0 ds-surface'>
                    <div class="container">
                        <div class="col-12 p-0 d-flex align-items-center justify-content-center"
                            style="min-height: 40vh">
                            <div style="width: 700px"
                                class="mx-auto py-8 d-flex align-items-center justify-content-center">
                                <div class="text-center col-12 p-0 mx-auto">
                                    <div class="col-12 px-0 row d-flex justify-content-between">
                                        <div class='col-12 py-5 rounded-2 text-center ds-surface' style="text-align: center; margin-top: -5px"
                                           >
                                            <div class="col-12" style="display: flex; justify-content: center">
                                                <img src="../<?=$user_data['avatar']?>"
                                                    style="width:130px;height: 130px;border-radius: 50%;">
                                            </div>
                                            <div class="col-12 p-2 text-center" style="overflow: auto">
                                                <?=$user_full_name?>

                                                <br>
                                                <!-- <button class="btn btn-primary mx-auto js-push-btn" style="margin-top: 25px; display: none">تفعيل الأشعارات</button> -->

                                                <span class="font-1"></span>


                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <div class="col-12 p-0 border-lg-top">
                        <div class="container p-0">
                            <div class="col-12 row user-menu">
                                <nav class='navbar navbar-expand-lg navbar-light ds-surface-muted'>
                                    <div class="container-fluid p-0">
                                        <div
                                            class="col-12 px-0 row d-flex m-0 py-3 py-lg-0 justify-content-between align-items-center d-lg-none">


                                            <div class='navbar-brand navbar-toggler font-2 px-3 col-auto ds-text-secondary'
                                                data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent"
                                                aria-controls="navbarSupportedContent" aria-expanded="false"
                                                aria-label="Toggle navigation">Dashboard</div>
                                            <button class='navbar-toggler d-flex col-auto ds-shadow-sm'
                                                type="button" data-bs-toggle="collapse"
                                                data-bs-target="#navbarSupportedContent"
                                                aria-controls="navbarSupportedContent" aria-expanded="false"
                                                aria-label="Toggle navigation">
                                                <span class="fas fa-bars"></span>
                                            </button>
                                        </div>

                                        <?php include "layouts/user/main-nav.php"; ?>

                                    </div>
                                </nav>
                            </div>
                        </div>
                    </div>



                </div>
                <div class="col-12">
                    <div class="container py-2 px-2">
                        <div class="col-12 p-0 row d-flex">
                            <!-- <button class="btn btn-outline-primary mx-auto js-push-btn" style="margin-top: 25px; display: none">Enable Notifications</button> -->

                            <div class='col-12 p-4 mb-3 row d-flex align-items-center justify-content-center ds-surface' style="border-radius: 8px; min-height: 40vh">

                                <?=$notification?>
                                
                            
                    
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </main>

        <?php include "layouts/user/footer.php"; ?>


        <div class='col-12 ds-border' style="background-image: linear-gradient(to right, var(--surface), var(--surface)); display: flex; align-items: center; justify-content: center; direction: ltr"
           >
            <div class="container">
                <div class="col-12 row d-flex justify-content-between p-0">
                    <div class="col-12 text-center mt-1 mb-2 pt-3 pb-2">
                        <p style="font-size: 14px; line-height: 1.8; margin: 0px" class="my-0 kufi text-center"><span class="d-inline-block kufi"> All rights reserved © <?=$site_name;?> 2023 </span> <span class="d-inline-block kufi"> All rights reserved</span></p>
                        

<div class="developer" style="text-align: center; direction: ltr; font-size: 16px; font-weight: bold; cursor: pointer">
  <span>
    <span> <</span>
    <span>Developed With ❤ By </span>
    <span>></span>
  </span>
  <span class="text-primary">ENG/ Abdulrahman Mohamed Eid</span>
</div>

    <script>
          $(document).ready(function() {
    $('.developer').click(function() {
Swal.fire({
  title: `<h5 class='modal-title' style='font-size: 35px; text-align: center; margin-bottom: 16px'><span class='fas fa-phone mx-1'></span> Contact the Developer</h5>`,
  html: `<div class='col-lg-4 d-flex' style='width: 100%; flex-direction: column; justify-content: center; align-items: center'>
          <div class='d-flex flex-row' style='justify-content: space-between; width: 100%; flex-direction: row'>
            <div class='col-lg-6 col-md-6 col-sm-12 col-xs-12'>
              <div>
                <div class='icon text-primary fs-28 me-4 mt-n1'> <i class='fas fa-phone'></i> </div>
              </div>
              <div>
                <h5 class='mb-1' style='margin-right: 10px'>Phone</h5>
                <p style='fon-size: 17px'>01080842899 <br>01011626776</p>
              </div>
            </div>

            <div class='col-lg-6 col-md-6 col-sm-12 col-xs-12'>
              <div class='icon text-info fs-28 me-4 mt-n1'> <i class='fa fa-globe'></i> </div>
              <div>
                <h5 class='mb-1' style='margin-right: 10px'>Social Media</h5>
                <p class='mb-0'><a href='https://wa.me/+201080842899' target='_blank' class='btn btn-outline-success btn-sm'>WhatsApp<i class='fab fa-whatsapp' style='margin-right: 5px; font-size: 22px'></i> </a></p>
                <p class='mb-0'><a href='https://www.facebook.com/abdo0m/' target='_blank' class='btn btn-outline-primary btn-sm'>Facebook<i class='fab fa-facebook' style='margin-right: 5px; font-size: 22px'></i> </a></p>
              </div>
            </div>
          </div>
        </div>`,
  // icon: "info",
  showCancelButton: true,
  confirmButtonColor: "var(--primary)",
  cancelButtonColor: "var(--danger)",
  confirmButtonText: "!",
  cancelButtonText: "Cancel",
  showConfirmButton: false,
});

    });
  });

</script>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js" />
    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/main-07febffb.js" />
    <script type="module" src="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js"
        data-navigate-track="reload"></script> <!-- Livewire Scripts -->
        <script src="../notification/main.js"></script>

</body>

</html>