<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
if(isset($_SESSION['username'])){
  $username = $_SESSION['username'];

}else{
  $username = null;
}
$conn = db();
$categories_query = "SELECT * FROM categories";
$categories_result =  mysqli_query($conn,$categories_query);

if( mysqli_num_rows($categories_result) > 0 ){

  $categorie = '';
  while( $categories_data = mysqli_fetch_assoc($categories_result) ){
    $date = date('Y-m-d', strtotime($categories_data['created_at']));
    $categorie .= "
      <div class='col-12 col-lg-6 mb-4'>
        <article>
          <div class='card shadow-lg'>
            <figure class='card-img-top overlay overlay-1'><a href='category/{$categories_data['category_link']}'> <img src='{$categories_data['category_image']}' alt='' /></a>
              <figcaption>
                <h5 class='from-top mb-0 text-center'>عرض المزيد</h5>
              </figcaption>
            </figure>
            <div class='card-body p-6'>
              <div class='post-header'>
                <h2 class='post-title h3 mt-1 mb-3'><a class='link-dark hover' href='category/{$categories_data['category_link']}'>{$categories_data['category_title']}</a></h2>
                <div class='post-category'>
                  <p class='hover' rel='category'>{$categories_data['category_description']}</p>
                </div>
              </div>
              <div class='post-footer'>
                <ul class='post-meta d-flex mb-0'>
                  <li class='post-date'> <span>{$date}</span> <i class='fal fa-clock'></i> </li>
                  <!--<li class='post-comments'><a href='resources/#'>  4 <i class='fal fa-comment'></i> </a></li>-->
                </ul>
              </div>
            </div>
          </div>
        </article>
      </div>
    "; 
  }


}

trackTraffic();




?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="jYbsTZjfZPUMcMclAyiBtbD2bKdogoeHIv61pRrv"> 
    <!-- 
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="jYbsTZjfZPUMcMclAyiBtbD2bKdogoeHIv61pRrv">  
    <title><?=$site_name;?></title>
<meta name="title" content="<?=$site_name;?>"> -->
    <?=$metatags."\n"?>
    <?=$keywords."\n"?>

    <?=$openGraph?>

    <?=$schema?> 
<!---
وَما نَيلُ المَطالِبِ بِالتَمَنّي وَلَكِن تُؤخَذُ الدُنيا غِلاباوَ
ما اِستَعصى عَلى قَومٍ مَن الٌإِذا الإِقدامُ كانَ لَهُم رِكابا
أحمد شوقي
---> 

<?php include "public/layouts/header.php"; ?>


</head>
<body style="background:#eef4f5;margin-top: 65px;" class="body ">
    <style type="text/css">
        #toast-container>div {
            opacity: 1;
        }
    </style>
        <div id="app">
        
        <div id="body-overlay"onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></div>
        <form id="logout-form" action="logout" method="POST" class="d-none">
    <input type="hidden" name="_token" value="jYbsTZjfZPUMcMclAyiBtbD2bKdogoeHIv61pRrv"></form>


    
<?php include "public/layouts/aside.php"; ?>



<main class="p-0 font-2">
            <section class="wrapper bg-light" >
  <style type="text/css">
    .features-list i{
      width: 50px;
    }
  </style>
  <div class="container  ">
    <div class="row gx-lg-8 gx-xl-12 gy-10 align-items-center">
      <div class="col-lg-5 position-relative order-lg-2 px-0">
        <div class="shape bg-dot primary rellax w-16 h-20" data-rellax-speed="1" style="top: 3rem; left: 5.5rem"></div>
        <div class="overlap-grid overlap-grid-2">
          <div class="item">
            <figure class="rounded shadow"><img src="resources/images/home/heroPic.png"  alt=""></figure>
          </div>
          <div class="item">
            <!-- <figure class="rounded shadow"><img src="resources/images/screenshots/1.png" alt=""></figure> -->
          </div>
        </div>
      </div>
      <!--<div class="col-lg-12" style="text-align:center;">-->
      <!--  <button class='btn btn-outline-danger tutorialVideo' style="font-size:20px;"><i class="fal fa-play me-2 ms-0 font-4 p-2" style="color:#dc3545"></i> شرح طريقة الاستخدام </button>-->
      <!--</div>-->

      <div class="col-lg-7  py-md-16">
      <div class="row">
        <div class="col-xl-7 col-xxl-6 mx-auto text-center">
          <h2 class="display-4 text-center mt-2 mb-10">المرحلة الدراسية</h2>
        </div>
        <!--/column -->
      </div>


      <div class="col-12 row p-0">
        <?=$categorie?>

          </div>

        </div>
      </div>

        </div>
        <!--/.row -->
      </div>
      <!--/column -->
    </div>
    <!--/.row -->
  </div>
  <!-- /.container -->


</section>



<!-- /section --><section class="wrapper bg-light">
  <style type="text/css">
    .swiper-navigation{
      direction: ltr;
    }
  </style>
  <div class="overflow-hidden">

  <!-- شششششششششششششششششششششششش -->
  <div class="container">
      <!--/column -->
      
      <div class="col-lg-12">
        

        <h2 class="display-4 mb-3">
          
          <span class="far fa-info-circle" style="color:#0194fe"></span>

          <?=$site_name;?> ؟</h2>
        <p class="lead fs-lg"  data-delay="1000">منصة عربية لشرح المناهج التعليمية لجميع المراحل وكورسات اخرى </p>
        <span class="typer text-primary" 
          data-delay="80" 
          data-words="تم تطوير لوحة التحكم لمساعدتك على زيادة انتاجيتك وتسهيل الأمور المعقدة التي غالباً تتكرر أمامك كثيراً">
        </span>




        <div class="row gy-3 gx-xl-8">

          
 

          <div class="col-xl-6 px-0">
          	<ul style="list-style:none" class="p-0 features-list">
          		<li>
          			<i class="fal fa-check-circle me-2 ms-0 font-4 p-2" style="color:#7cb798"></i>
          			<span>متخصصون في تبسيط العلوم لجميع طلبة الجمهورية</span>
                </li>
                <li>
          			<i class="fal fa-check-circle me-2 ms-0 font-4 p-2" style="color:#7cb798"></i>
          			<span>الفيديوهات مدعمة بأشكال توضيحية وروسومات لتوصيل المعلومات أسرع (علي عكس فيديوهات اليوتيوب)</span>
                </li>
                <li>
          			<i class="fal fa-check-circle me-2 ms-0 font-4 p-2" style="color:#7cb798"></i>
          			<span>امتحانات علي كل جزء في المنهج</span>
                </li>


          	</ul>
          </div>
          <div class="col-xl-6 px-0">
          	<ul style="list-style:none" class="p-0 features-list">
          		<li>
          			<i class="fal fa-check-circle me-2 ms-0 font-4 p-2" style="color:#7cb798"></i>
          			<span>تكريم اوائل الأمتحانات الشاملة بشهادات تقديرية مميزة</span> </span>
                </li>
                <li>
          			<i class="fal fa-check-circle me-2 ms-0 font-4 p-2" style="color:#7cb798"></i>
          			<span>متابعة مع الطلبة للرد علي جميع اسئلة المنهج (علي جروب الواتس)</span>
                </li>
                <li>
          			<i class="fal fa-check-circle me-2 ms-0 font-4 p-2" style="color:#7cb798"></i>
          			<span>متابعة مع ولي الأمر بعد كل امتحان شامل</span>
                </li>
                <li>
          			<i class="fal fa-check-circle me-2 ms-0 font-4 p-2" style="color:#7cb798"></i>
          			<span>متجاوبة بالكامل</span>
                </li>



          	</ul>
          </div> 
          <div class="col-xl-12 px-0">
          	<ul style="list-style:none;text-align:center;font-size:25px;font-weight:bold;" class="p-0 features-list">
          			<i class="fal fa-check-double me-2 ms-0 font-4 p-2" style="color:#7cb798"></i>
          			<span>منصة مكارم مش هتبخل عليك😉</span>
                </li> 
          	</ul>
          </div>                          
          <!--/column -->


    </div>

    </section>



<!-- /section -->        
</main>
        <footer class=" pt-5" style="background:#fff;border-top:1px solid #f1f1f1;">
 
  <div class="container pb-12 text-center pt-12">
    <div class="row mt-n10 mt-lg-0">
      <div class="col-xl-10 mx-auto">
        <div class="row mb-3 d-flex">
          <div class="col-md-6 mb-3">
            <div class="widget">
                <img src="<?=$baseUrl?>/<?=$website_logo?>" style="width:160px;max-width:100%" class="mb-3">
              <div style="text-align:justify;"><?=$site_description?></div>
            </div>
            <!-- /.widget -->
          </div>
   
          <div class="col-md-3 mb-3">
            <div class="widget">
              <div class="widget-title display-6 mb-5" >روابط</div>

              
                                              <div><a href="<?=$baseUrl?>" class="link-body"><span class="fal fa-home font-1 d-none" style="color: #0194fe;width: 15px"></span> الرئيسية</a></div>
                                <!-- <div><a href="resources/blog" class="link-body"><span class="fal fa-pen-alt font-1 d-none" style="color: #0194fe;width: 15px"></span> المدونة</a></div>
                                <div><a href="resources/page/terms" class="link-body"><span class="fal fa-lock font-1 d-none" style="color: #0194fe;width: 15px"></span> شروط الاستخدام</a></div> -->
                                <!-- <div><a href="#" class="link-body"><span class="fal fa-phone font-1 d-none" style="color: #0194fe;width: 15px"></span> تواصل معنا</a></div> -->
                                        
 
            </div>
            <!-- /.widget -->
          </div>

          <div class="col-md-3 mb-3">
            <div class="widget">
              <div class="widget-title display-6 mb-5" >تابعنا</div>

                  <nav class="nav social">
                      <a href="<?=$youtube_link?>" target="_blank"><i class="fab fa-youtube"></i></a>
                      <a href="<?=$facebook_link?>" target="_blank"><i class="fab fa-facebook-f"></i></a>
                      <a href="<?=$whatsapp_link?>" target="_blank"><i class="fab fa-whatsapp"></i></a>
                      <a href="<?=$instagram_link?>" target="_blank"><i class="fab fa-instagram"></i></a>
                      <a href="<?=$twitter_link?>" target="_blank"><i class="fab fa-twitter"></i></a>
                  </nav>
          
 
            </div>
            <!-- /.widget -->
          </div>

          <!--/column -->
        </div>
        <!--/.row -->
        
        
        <!-- /.social -->
      </div>
      <!-- /column -->
    </div>
    <!-- /.row -->
  </div>
  <!-- /.container -->
</footer>

<div class="col-12" style="background-image: linear-gradient(to right, rgba(0,0,0,0.01) , rgba(0,0,0,0.01) );border-top:1px solid rgb(145 145 145 / 3%);display: flex; align-items: center;justify-content: center;direction: rtl;"> <div class="container "> <div class="col-12 row d-flex justify-content-between p-0"> <div class="col-12 text-center mt-1 mb-2 pt-3 pb-2 "> <p style="font-size: 14px;line-height: 1.8;margin:0px" class="my-0  kufi text-center"><span class="d-inline-block kufi"> جميع الحقوق محفوظة © <?=$site_name;?> 2023 </span> <span class="d-inline-block kufi"> All rights reserved</span></p> 

    <div class="developer" style="text-align:center;direction:ltr;font-size:16px;font-weight:bold;cursor:pointer;">
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
      title: `<h5 class='modal-title' style='font-size:35px;text-align:center;margin-bottom:16px;'><span class='fal fa-phone mx-1'></span> تواصل مع المُبرمج</h5>`,
      html: `<div class='col-lg-4 d-flex' style='width:100%;flex-direction:column;justify-content:center;align-items: center;'>
              <div class='d-flex flex-row' style='justify-content:space-between;width:100%;flex-direction:row;'>
                <div class='col-lg-6 col-md-6 col-sm-12 col-xs-12'>
                  <div>
                    <div class='icon text-primary fs-28 me-4 mt-n1'> <i class='fal fa-phone'></i> </div>
                  </div>
                  <div>
                    <h5 class='mb-1' style='margin-right:10px;'>الهاتف</h5>
                    <p style='fon-size:17px;'>01080842899 <br>01011626776</p>
                  </div>
                </div>
    
                <div class='col-lg-6 col-md-6 col-sm-12 col-xs-12'>
                  <div class='icon text-info fs-28 me-4 mt-n1'> <i class='fa fa-globe'></i> </div>
                  <div>
                    <h5 class='mb-1' style='margin-right:10px;'>مواقع التواصل</h5>
                    <p class='mb-0'><a href='https://wa.me/+201080842899' target='_blank' class='btn btn-outline-success btn-sm'>واتساب<i class='fab fa-whatsapp' style='margin-right:5px;font-size:22px;'></i> </a></p>
                    <p class='mb-0'><a href='https://www.facebook.com/abdo0m/' target='_blank' class='btn btn-outline-primary btn-sm'>فيسبوك<i class='fab fa-facebook' style='margin-right:5px;font-size:22px;'></i> </a></p>
                  </div>
                </div>
              </div>
            </div>`,
      // icon: "info",
      showCancelButton: true,
      confirmButtonColor: "#3085d6",
      cancelButtonColor: "#d33",
      confirmButtonText: "!",
      cancelButtonText: "الغاء",
      showConfirmButton: false,
    });
    
        });
      });
    
    </script>
    </div> </div> </div> </div>    </div>


    <link rel="modulepreload" href="resources/build/assets/app-e4352ad6.js" /><link rel="modulepreload" href="resources/build/assets/main-07febffb.js" /><script type="module" src="resources/build/assets/app-e4352ad6.js" data-navigate-track="reload"></script>    <!-- Livewire Scripts -->
<script src="resources//livewire/livewire.js?id=3605227a"  data-csrf="jYbsTZjfZPUMcMclAyiBtbD2bKdogoeHIv61pRrv" data-uri="/livewire/update" data-navigate-once="true"></script>
    <script>
 
/* Guest Js */



</script>
<script type="module">
toastr.options={"positionClass": "toast-top-left"};
</script>    





<script>
  $(document).ready(function() {
    $('.tutorialVideo').click(function() {
      // Open the SweetAlert2 modal
      Swal.fire({
        title: `<h5 class='modal-title' style='font-size:35px;text-align:center;margin-bottom:16px;'><span class='fal fa-youtube mx-1'></span> فيديو شرح الاستخدام</h5>`,
        html: `<div class='col-lg-4 d-flex' style='width:100%;'>
          <iframe width="560" height="315" src="https://drive.google.com/file/d/1b8PmehF19a-qE4h0X6_KfyopANvLj8ld/preview" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
        </div>`,
        icon: "info",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "نعم , شراء !",
        cancelButtonText: "الغاء",
        showConfirmButton: false,
      });
    });
  });
</script>


</body>
</html>
