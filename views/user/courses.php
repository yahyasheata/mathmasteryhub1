<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$pageName = "courses";
$username = $_SESSION['username'];
$user_id = getUserInfo($username)->user_id;
$conn = db();
$courses_query = "SELECT * FROM courses WHERE course_status=1 AND archived_at IS NULL ";
$coures_result = mysqli_query($conn,$courses_query);
// echo ($courseId);
if( mysqli_num_rows($coures_result) > 0 ){

  $course = '';
  while( $courses_data = mysqli_fetch_assoc($coures_result) ){
    $date = date('Y-m-d', strtotime($courses_data['created_at']));
    $preDiscount_course_price_div = "";
    if(!empty($courses_data['preDiscount_course_price'])){
        $preDiscount_course_price_div = "
            <div class='post-footer d-flex justify-content-end'>
            Instead of
                <ul class='post-meta d-flex mb-0' style='text-decoration: line-through; font-size: 12px'>
                    <span class='badge bg-primary ds-text-secondary' style='/* background: turquoise; */font-size: 13px; margin-left: 5px; text-decoration: line-through'>{$courses_data['preDiscount_course_price']}</span> <strong>EGP</strong>
                </ul>    
            </div>
      ";
    }

    $course .= "
      <div class='col-12 col-lg-4 mb-4'>
        <article>
          <div class='card shadow-lg'>
            <figure class='card-img-top overlay overlay-1'><a href='$baseUrl/course/{$courses_data['id']}'> <img src='$baseUrl/{$courses_data['course_image']}' alt='' /></a>
              <figcaption>
                <h5 class='from-top mb-0 text-center'>View More</h5>
              </figcaption>
            </figure>
            <div class='card-body p-6'>
              <div class='post-header'>
                <h2 class='post-title h3 mt-1 mb-3'><a class='link-dark hover' href='{$baseUrl}/course/{$courses_data['id']}'>{$courses_data['course_title']}</a></h2>
                <div class='post-category'>
                  <p class='hover' rel='category'>{$courses_data['course_description']}</p>
                </div>
              </div>
              <div class='post-footer d-flex justify-content-between'>
    
                <ul class='post-meta d-flex mb-0'>
                  <li class='post-date'> <span>{$date}</span> <i class='fas fa-clock'></i> </li>
                  <!--<li class='post-comments'><a href='resources/#'>  4 <i class='fas fa-comment'></i> </a></li>-->
                </ul>
                <ul class='post-meta d-flex mb-0'>
                  <span class='badge bg-primary' style='background: turquoise; font-size: 13px; margin-left: 5px'>{$courses_data['course_price']}</span> <strong>EGP</strong>
                </ul>    
              </div>
              $preDiscount_course_price_div
              <div class='row'>
                <form action='' method='POST' class='purchaseForm'>
                    <input type='hidden' name='course_id' value='{$courses_data['course_id']}'>
                    <input type='hidden' name='course_title' value='{$courses_data['course_title']}'>
                    <input type='hidden' name='_method' value='POST'>
                    <button type='submit' class='btn btn-outline-success btn-sm mt-5'>Subscribe Now</button>
                </form>
                <!--<a href='' class='btn btn-outline-success btn-sm mt-5'>اشترك الان</a> -->
              
              </div>
            </div>
          </div>
        </article>
      </div>
    "; 
  }
}else{
    $course = "
    <div class='col-12 text-center'>
      <span class='fas fa-info-circle mx-2 font-12' style='opacity: 0.3'></span>
      <div class='col-12 text-center py-3'>
        No courses available now
      </div>
      <div class='col-12 p-2 text-center'>
        <a href='/' class='btn btn-outline-warning rounded-pill font-2 px-8'><span class='fas fa-home'></span> <span class='mx-2'>Go to Home</span> </a>
      </div>
    </div>
    ";
}

?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="0PjjLlpM6FmXxBStqiehsWgYBO2MSNJkr7ZTKvPk">  
    <!-- <title><?=$site_name;?></title>
<meta name="title" content="<?=$site_name;?>"> -->
<!---
Achievement is not attained by mere wishes, but by striving for it.
What is difficult for a people is only so if they lack determination.
Ahmed Shawqi
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
        
        <div id="body-overlay"onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></div>
        <form id="logout-form" action="<?=$baseUrl?>/resources/logout" method="POST" class="d-none">
    <input type="hidden" name="_token" value="0PjjLlpM6FmXxBStqiehsWgYBO2MSNJkr7ZTKvPk"></form>

    <?php include "layouts/user/aside.php"; ?>
    
<main class="p-0 font-2">
            <div class='col-12 ds-bg-primary' style="min-height: 100vh">
    <div class='col-12 p-0 ds-surface'>
        <div class="container">
            <div class="col-12 p-0 d-flex align-items-center justify-content-center" style="min-height: 40vh">
                <div style="width: 700px" class="mx-auto py-8 d-flex align-items-center justify-content-center">
                    <div class="text-center col-12 p-0 mx-auto">
                        <div class="col-12 px-0 row d-flex justify-content-between">
                            <div class='col-12 py-5 rounded-2 text-center ds-surface' style="text-align: center; margin-top: -5px">
                                <div class="col-12" style="display: flex; justify-content: center">
                                    <img src="../<?=$user_data['avatar']?>" style="width:130px;height: 130px;border-radius: 50%;">
                                </div>
                                <div class="col-12 p-2 text-center" style="overflow: auto">
                                   <?=$user_full_name?>  

                                   <br>
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
                            <div class="col-12 px-0 row d-flex m-0 py-3 py-lg-0 justify-content-between align-items-center d-lg-none">
                                
                            
                            <div class='navbar-brand navbar-toggler font-2 px-3 col-auto ds-text-secondary' data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">Dashboard</div>
                            <button class='navbar-toggler d-flex col-auto ds-shadow-sm' type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
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
                <div class='col-12 p-4 mb-3 row d-flex align-items-center justify-content-center ds-surface' style="border-radius: 8px; min-height: 40vh">
                    <?=$course?>
                    
                  
		
                </div>
            </div>
        </div>
    </div>
</div>

        </main>
        <?php include "layouts/user/footer.php"; ?>

    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js" /><link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/main-07febffb.js" /><script type="module" src="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js" data-navigate-track="reload"></script>    <!-- Livewire Scripts -->
    
    
    <script src="../notification/main.js"></script>




<script type="text/javascript">
// $(document).ready(function() {

//     var Toast = Swal.mixin({
//       toast: true,
//       position: 'top-end',
//       showConfirmButton: false,
//       timer: 10000
//       });

//     //*** Send Purchase Request
//     $(".purchaseForm").on("submit", function (e) {
//   e.preventDefault();
//     var course_title = $(this).find("[name='course_title']").val();
//     // console.log(course_title);
//   // Display the confirmation dialog using SweetAlert
//   Swal.fire({
//     title: `\n You are about to enjoy this unit and secure its grade, God willing. <strong class='text-info'>[${course_title}]</strong>`,
//     text: "Do you really want to purchase?",
//     icon: "warning",
//     showCancelButton: true,
//     confirmButtonColor: "var(--primary)",
//     cancelButtonColor: "var(--danger)",
//     confirmButtonText: "Yes, Purchase!",
//     cancelButtonText: "Cancel",
//   }).then((result) => {
//     if (result.isConfirmed) {
//       // User clicked "Yes," proceed with the request
//       $.ajax({
//         type: "POST",
//         url: "requests/course/purchase",
//         data: new FormData(this),
//         dataType: "json",
//         contentType: false,
//         cache: false,
//         processData: false,
//         beforeSend: function () {
//           // Code to execute before sending the request
//         },
//         success: function (response) {
//           $(".response-msg").html("");
//           if (response.status == 1) {
//             $(".response-msg").html(
//               Swal.fire({
//                 icon: "success",
//                 title: response.message,
//                 showConfirmButton: false,
//                 timer: 10000,
//                 timerProgressBar: true,
//               }).then(function (isConfirm) {
//                 if (isConfirm) {
//                 //   location.reload();
//                 window.location.href = 'my-courses';
//                 } else {
//                   // If "No" is clicked, do something else
//                 }
//               })
//             );
//           } else {
//             $(".response-msg").html(
//               Swal.fire({
//                 icon: "info",
//                 // title: response.message,
//                 html: response.message,
//                 // text: response.reason,
//                 showConfirmButton: true,
//                 // timer: 20000,
//                 timerProgressBarColor: "var(--primary)",
//                 timerProgressBar: true,
//                 confirmButtonColor: "var(--danger)",
//                 confirmButtonText: "Cancel",
//                 // html: true,
//               }).then(function (isConfirm) {
//                 if (isConfirm) {
//                 //   location.reload();
//                 } else {
//                   // If "No" is clicked, do something else
//                 }
//               })
//             );
//           }
//           // $("#addApi").css("opacity", "");
//           // $(".submitBtn").removeAttr("disabled");
//         },
//       });
//     } else {
//       // User clicked "Cancel," do something else or simply return
//     }
//   });
// });




//   });
</script>



<!--Payment-->
<script>
$(document).ready(function() {
    // Purchase form
    $(".purchaseForm").on("submit", function (e) {
      e.preventDefault();
      var course_title = $(this).find("[name='course_title']").val();
      Swal.fire({
        title: `You are about to subscribe to <strong class='text-info'>[${course_title}]</strong>`,
        text: "Do you want to proceed with the purchase?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--primary)",
        cancelButtonColor: "var(--danger)",
        confirmButtonText: "Yes, Subscribe!",
        cancelButtonText: "Cancel",
      }).then((result) => {
        if (result.isConfirmed) {
          $.ajax({
            type: "POST",
            url: "../requests/course/purchase",
            data: new FormData(this),
            dataType: "json",
            contentType: false,
            cache: false,
            processData: false,
            success: function (response) {
              if (response.status == 1 && response.payment_url) {
                window.location.href = response.payment_url;
              } else {
                Swal.fire({
                  icon: "error",
                  title: response.message,
                  text: response.reason || response.error || '',
                  showConfirmButton: true,
                  timer: 10000,
                  timerProgressBar: true,
                });
              }
            },
          });
        }
      });
    });
});
</script>


</body>
</html>
