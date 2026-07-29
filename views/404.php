<?php

require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

$site_settings = getSiteSettings();

?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="">  
    <title><?=$site_name;?></title>
<meta name="title" content="<?=$site_name;?>">
<!---
وَما نَيلُ المَطالِبِ بِالتَمَنّي وَلَكِن تُؤخَذُ الدُنيا غِلاباوَ
ما اِستَعصى عَلى قَومٍ مَن الٌإِذا الإِقدامُ كانَ لَهُم رِكابا
أحمد شوقي
---> 
<link rel="icon" type="image/png" href="<?=$baseUrl?>/resources/images/default/favicon.png" /> 
<link rel="icon" type="image/png" sizes="512x512" href="<?=$baseUrl?>/resources/images/default/favicon.png" />
<link rel="manifest" href="<?=$baseUrl?>/resources/manifest.json">
<meta name="theme-color" content="#F15A22">
<meta name="mobile-web-app-capable" content="no">
<meta name="application-name" content="<?=$site_name;?>">


<meta name="facebook-domain-verification" content="vymdke86bl9vdcyleijy0r173c6k7c" />
<meta name="apple-mobile-web-app-capable" content="no">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?=$site_name;?>">
<link rel="apple-touch-icon" href="<?=$baseUrl?>/resources/images/default/favicon.png?v=2">

<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 320px) and (device-height: 568px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image" />
<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image" />
<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 621px) and (device-height: 1104px) and (-webkit-device-pixel-ratio: 3)" rel="apple-touch-startup-image" />
<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3)" rel="apple-touch-startup-image" />
<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image" />
<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3)" rel="apple-touch-startup-image" />
<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image" />
<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 834px) and (device-height: 1112px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image" />
<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 834px) and (device-height: 1194px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image" />
<link href="<?=$baseUrl?>/resources/images/default/favicon.png" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image" /> 

<link rel='alternate' href="<?=$baseUrl?>/resources/dashboard/a" hreflang='x-default' />

<meta name="author" content="<?=$site_name;?>" />
<meta name="description" content="<?=$site_name;?>">
<link rel="canonical" href="<?=$baseUrl?>/resources/dashboard/a">


<meta name="msapplication-TileColor" content="#F15A22">
<meta name="msapplication-TileImage" content="<?=$baseUrl?>/resources/images/default/favicon.png">
<meta name="msapplication-square70x70logo" content="<?=$baseUrl?>/resources/images/default/cover.png" />
<meta name="msapplication-square150x150logo" content="<?=$baseUrl?>/resources/images/default/cover.png" />
<meta name="msapplication-wide310x150logo" content="<?=$baseUrl?>/resources/images/default/cover.png" />
<meta name="msapplication-square310x310logo" content="<?=$baseUrl?>/resources/images/default/cover.png" />
<link rel="apple-touch-icon-precomposed" href="<?=$baseUrl?>/resources/images/default/cover.png" />

<meta property="og:type"               content="website" />
<meta property="og:site_name"          content="<?=$site_name;?>" />
<meta property="og:locale" content="ar_AR"/>
<meta property="og:locale:alternate" content="ar_AR"/>
<meta property="og:url"                content="<?=$baseUrl?>/resources/dashboard/a" />
<meta property="og:title"              content="<?=$site_name;?>" />
<meta property="og:description"        content="<?=$site_name;?>" />
<meta property="og:image" content="<?=$baseUrl?>/resources/images/default/cover.png" />

<meta itemprop="name" content="<?=$site_name;?>" />
<meta itemprop="url" content="http://127.0.0.1:8000" />
<meta itemprop="author" content="<?=$site_name;?>" />
<meta itemprop="image" content="<?=$baseUrl?>/resources/images/default/cover.png" />
<meta itemprop="description" content="<?=$site_name;?>" />

<meta name="twitter:image" content="<?=$baseUrl?>/resources/images/default/cover.png" />
<meta name="twitter:card" content="summary" />
<meta name="twitter:site" content="@Nafezly" />
<meta name="twitter:creator" content="@Nafezly" />
<meta name="twitter:title" content="<?=$site_name;?>" />
<meta name="twitter:image:src" content="<?=$baseUrl?>/resources/images/default/cover.png" />
<meta name="twitter:description" content="<?=$site_name;?>" />


<link rel='help' title='FAQ' href='<?=$baseUrl?>/resources/faq'/>
<link rel="alternate" type="application/rss+xml" title="آخر الأخبار" href="<?=$baseUrl?>/resources/feed">
<script type="application/ld+json">
{
    "@context": "http://schema.org",
    "@type": "Organization",
    "name": "<?=$site_name;?>",
    "url": "http://127.0.0.1:8000",
    "logo": "<?=$baseUrl?>/resources/images/default/favicon.png",
            "sameAs": [
       
                                    "<?=$baseUrl?>/resources/admin/settings" 
                ,                                                "<?=$baseUrl?>/resources/admin/settings" 
                ,                                                "<?=$baseUrl?>/resources/admin/settings" 
                ,                                                "<?=$baseUrl?>/resources/admin/settings" 
                                        ],
        "contactPoint": [
                {
            "@type": "ContactPoint",
            "telephone": "1556456456456",
            "contactType": "customer support"
        },
        {
            "@type": "ContactPoint",
            "telephone": "1556456456456",
            "contactType": "technical support"
        }, {
            "@type": "ContactPoint",
            "telephone": "1556456456456",
            "contactType": "billing support"
        }
            ]
}
{
    "@context": "http://schema.org",
    "@type": "WebSite",
    "url": "http://127.0.0.1:8000",
    "potentialAction": {
        "@type": "SearchAction",
        "target": "<?=$baseUrl?>/resources/q?key={search_term_string}",
        "query-input": "required name=search_term_string"
    }
}
{
    "@context": "https://schema.org",
    "@type": "WebPage",
    "name": "<?=$site_name;?>",
    "description": "<?=$site_name;?>",
    "publisher": {
        "@type": "Organization",
        "name": "<?=$site_name;?>"
    }
}
</script>
<script type="text/javascript">
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/serviceworker.js', {
            scope: '.'
        }).then(function (registration) { 
            console.log('Laravel PWA: ServiceWorker registration successful with scope: ', registration.scope);
        }, function (err) { 
            console.log('Laravel PWA: ServiceWorker registration failed: ', err);
        });
    }
</script>    
    


    
    <!-- Livewire Styles --><style >[wire\:loading], [wire\:loading\.delay], [wire\:loading\.inline-block], [wire\:loading\.inline], [wire\:loading\.block], [wire\:loading\.flex], [wire\:loading\.table], [wire\:loading\.grid], [wire\:loading\.inline-flex] {display: none;}[wire\:loading\.delay\.shortest], [wire\:loading\.delay\.shorter], [wire\:loading\.delay\.short], [wire\:loading\.delay\.long], [wire\:loading\.delay\.longer], [wire\:loading\.delay\.longest] {display:none;}[wire\:offline] {display: none;}[wire\:dirty]:not(textarea):not(input):not(select) {display: none;}</style>
        <link rel="preload" as="style" href="<?=$baseUrl?>/resources/build/assets/app-38448552.css" /><link rel="stylesheet" href="<?=$baseUrl?>/resources/build/assets/app-38448552.css" data-navigate-track="reload" />    
    <style type="text/css">
        body {
            --bg-main: var(--surface);
            --bg-second: var(--bg-primary);
            --font-1: var(--text-primary);
            --font-2: var(--text-secondary);
            --border-color: var(--border);
            --main-color: var(--primary);
            --main-color-rgb: 241, 90, 34;
            --main-color-flexable: var(--primary);
            --scroll-bar-color: var(--border-strong);
        }
        body.night {
            --bg-main: var(--surface);
            --bg-second: var(--bg-primary);
            --font-1: var(--surface);
            --font-2: var(--text-secondary);
            --border-color: var(--border);
            --main-color: var(--primary);
            --main-color-rgb: 241, 90, 34;
            --main-color-flexable: var(--surface-muted);
            --scroll-bar-color: var(--border-strong);
        }
        
    </style>
        <link rel="stylesheet" href="<?=$baseUrl?>/resources/css/design-system.css" data-design-system="mathhub" />
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
    <input type="hidden" name="_token" value=""></form>

    <?php include "public/layouts/aside.php"; ?>
    
    
    <div id="aside-menu" class="shadow">
    <div class="col-12 d-flex justify-content-between align-items-center p-0 shadow" style="height: 65px">
        <span class="px-3 font-1 kufi">

            <img src="<?=rtrim((string)$baseUrl, '/')?>/<?=$site_settings['website_logo']?>" style="width: 105px;" alt="<?=$site_name;?>" class="mathhub-logo mathhub-logo--light">
            <img src="<?=rtrim((string)$baseUrl, '/')?>/resources/images/branding/mathhub-logo-white.png" style="width: 105px;" alt="<?=$site_name;?>" class="mathhub-logo mathhub-logo--dark">

        </span>
        <span class="d-flex">
            <span class="font-1"><span class="far fa-times font-3 px-4 py-3" style="cursor: pointer" onclick="document.getElementById('aside-menu').classList.toggle('active');document.getElementById('body-overlay').classList.toggle('active');"></span></span>
        </span>
    </div>
    <div class="col-12 p-0">
        <div class="col-12 p-0 aside-scroll pt-2" style="height: calc(100vh - 186px); overflow: auto; position: relative">

                                                <div class="nav-item">
                <a href="http://127.0.0.1:8000" class='d-block ds-text-secondary'>
                <div class="nav-link" style="cursor: pointer" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fal fa-home mx-1"></span> الرئيسية
                        </div>
                    </div>
                </div>
                </a>
            </div>
                        <div class="nav-item">
                <a href="<?=$baseUrl?>/resources/blog" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fal fa-pen-alt mx-1"></span> المدونة
                        </div>
                    </div>
                </div>
                </a>
            </div>
                        <div class="nav-item">
                <a href="<?=$baseUrl?>/resources/page/about" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fal fa-info mx-1"></span> معلومات عنا
                        </div>
                    </div>
                </div>
                </a>
            </div>
                        <div class="nav-item">
                <a href="<?=$baseUrl?>/resources/page/terms" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fal fa-lock mx-1"></span> شروط الاستخدام
                        </div>
                    </div>
                </div>
                </a>
            </div>
                        <div class="nav-item">
                <a href="<?=$baseUrl?>/resources/page/privacy" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fal fa-info mx-1"></span> سياسة الخصوصية
                        </div>
                    </div>
                </div>
                </a>
            </div>
                        <div class="nav-item">
                <a href="<?=$baseUrl?>/resources/contact" style="color: inherit;" class="d-block">
                <div class="nav-link" style="cursor: pointer" >
                    <div class="col-12 px-0 row">
                        <div class="col-12 px-0 kufi" >
                            <span class="fal fa-phone mx-1"></span> تواصل معنا
                        </div>
                    </div>
                </div>
                </a>
            </div>
                         
        
        </div>
        <div class="col-12 px-0 py-2" style="position: absolute; width: 100%">
            <div class="col-12 p-0">
                <div class="col-12 p-0">
                    <ul style="padding: 0px; list-style: none; min-height: 48px" class="d-flex align-items-center justify-content-center row my-2">
                                                <a href="<?=$baseUrl?>/resources/admin/settings" class="d-inline-block p-1" style="width:48px">
                            <span class='fab fa-facebook-f d-inline-block border rounded-circle ds-text-secondary' style="width: 40px; @height: 40px; padding: 11px 14px; cursor: pointer"></span>
                        </a>
                                                                        <a href="<?=$baseUrl?>/resources/admin/settings" class="d-inline-block p-1" style="width:48px">
                            <span class='fab fa-twitter d-inline-block border rounded-circle ds-text-secondary' style="width: 40px; height: 40px; padding: 11px 11px; cursor: pointer"></span>
                        </a>
                                                                        <a href="<?=$baseUrl?>/resources/admin/settings" class="d-inline-block p-1" style="width:48px">
                            <span class='fab fa-youtube d-inline-block border rounded-circle ds-text-danger' style="width: 40px; height: 40px; padding: 11px 10px; cursor: pointer"></span>
                        </a>
                                                                                                <a href="<?=$baseUrl?>/resources/admin/settings" class="d-inline-block p-1" style="width:48px">
                            <span class='fab fa-telegram-plane d-inline-block border rounded-circle ds-text-secondary' style="width: 40px; height: 40px; padding: 11px 12px; cursor: pointer"></span>
                        </a>
                                            </ul>
                </div>
                <div class='col-12 p-0 text-center ds-text-secondary' style="font-size: 12px">
                    جميع الحقوق محفوظة © <?=$site_name;?> 2023 </div>
            </div>
        </div>
    </div>
</div>        <main class="p-0 font-2">
            <div style="min-height: 95vh; overflow-x: hidden" class="col-12">
	<div class="container mt-5 pt-5 pt-md-0 mt-md-0">
		<div class="row col-12 pt-6 px-0" style="padding-top: 20px">
			<div class="row col-12 align-items-center" style="min-height: 80vh; margin: 0% 0px">
				<div class="row align-items-center py-5 main-nafez-box-styles" style="border-radius: 12px">
					<div class="col text-center py-5">
						<span class='fal fa-exclamation-triangle font-12 pb-4 ds-text-secondary'></span>
						<h4 class="text-center">404 | الصفحة المطلوبة غير متوفرة</h4>
						<br>
						<div class="col-12 text-center px-2" dir="ltr" style="padding-top: 8px">
						<a href="/" class="d-inline-block">
						<span class='btn btn-primary cairo px-5 ds-border' style="padding: 5px 10px 9px; cursor: pointer; border-radius: 90px"> <span class='fal fa-home font-1 ds-text-inverse'></span> الرئيسية </span>
						</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
        </main>
        <footer class='pt-5 ds-surface ds-border'>
 
  <div class="container pb-12 text-center pt-12">
    <div class="row mt-n10 mt-lg-0">
      <div class="col-xl-10 mx-auto">
        <div class="row mb-3 d-flex">
          <div class="col-md-6 mb-3">
            <div class="widget">
                <img src="<?=rtrim((string)$baseUrl, '/')?>/<?=$site_settings['website_logo']?>" style="width:160px;max-width:100%" class="mb-3 mathhub-logo mathhub-logo--light">
                <img src="<?=rtrim((string)$baseUrl, '/')?>/resources/images/branding/mathhub-logo-white.png" style="width:160px;max-width:100%" class="mb-3 mathhub-logo mathhub-logo--dark">
              <div style="text-align: justify">هذا النص هو مثال لنص يمكن أن يستبدل في نفس المساحة، لقد تم توليد هذا النص من مولد النص العربى، حيث يمكنك أن تولد مثل هذا النص أو العديد من النصوص الأخرى إضافة إلى زيادة عدد الحروف التى يولدها التطبيق</div>
            </div>
            <!-- /.widget -->
          </div>
   
          <div class="col-md-3 mb-3">
            <div class="widget">
              <div class="widget-title display-6 mb-5" >روابط</div>

              
                                              <div><a href="http://127.0.0.1:8000" class="link-body"><span class='fal fa-home font-1 d-none ds-text-info' style="width: 15px"></span> الرئيسية</a></div>
                                <div><a href="<?=$baseUrl?>/resources/blog" class="link-body"><span class='fal fa-pen-alt font-1 d-none ds-text-info' style="width: 15px"></span> المدونة</a></div>
                                <div><a href="<?=$baseUrl?>/resources/page/terms" class="link-body"><span class='fal fa-lock font-1 d-none ds-text-info' style="width: 15px"></span> شروط الاستخدام</a></div>
                                <div><a href="<?=$baseUrl?>/resources/contact" class="link-body"><span class='fal fa-phone font-1 d-none ds-text-info' style="width: 15px"></span> تواصل معنا</a></div>
                                        
 
            </div>
            <!-- /.widget -->
          </div>

          <div class="col-md-3 mb-3">
            <div class="widget">
              <div class="widget-title display-6 mb-5" >تابعنا</div>

              <nav class="nav social">
                            <a href="<?=$baseUrl?>/resources/admin/settings"><i class="fab fa-twitter"></i></a>
                                          <a href="<?=$baseUrl?>/resources/admin/settings"><i class="fab fa-facebook-f"></i></a>
                                          <a href="<?=$baseUrl?>/resources/admin/settings"><i class="fab fa-instagram"></i></a>
                                          <a href="<?=$baseUrl?>/resources/admin/settings"><i class="fab fa-youtube"></i></a>
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

<div class='col-12 ds-border' style="background-image: linear-gradient(to right, var(--surface), var(--surface)); display: flex; align-items: center; justify-content: center; direction: ltr"> <div class="container"> <div class="col-12 row d-flex justify-content-between p-0"> <div class="col-12 text-center mt-1 mb-2 pt-3 pb-2"> <p style="font-size: 14px; line-height: 1.8; margin: 0px" class="my-0 kufi text-center"><span class="d-inline-block kufi"> جميع الحقوق محفوظة © <?=$site_name;?> 2023 </span> <span class="d-inline-block kufi"> All rights reserved</span></p> 

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
      title: `<h5 class='modal-title' style='font-size: 35px; text-align: center; margin-bottom: 16px'><span class='fal fa-phone mx-1'></span> تواصل مع المُبرمج</h5>`,
      html: `<div class='col-lg-4 d-flex' style='width: 100%; flex-direction: column; justify-content: center; align-items: center'>
              <div class='d-flex flex-row' style='justify-content: space-between; width: 100%; flex-direction: row'>
                <div class='col-lg-6 col-md-6 col-sm-12 col-xs-12'>
                  <div>
                    <div class='icon text-primary fs-28 me-4 mt-n1'> <i class='fal fa-phone'></i> </div>
                  </div>
                  <div>
                    <h5 class='mb-1' style='margin-right: 10px'>الهاتف</h5>
                    <p style='fon-size: 17px'>01080842899 <br>01011626776</p>
                  </div>
                </div>
    
                <div class='col-lg-6 col-md-6 col-sm-12 col-xs-12'>
                  <div class='icon text-info fs-28 me-4 mt-n1'> <i class='fa fa-globe'></i> </div>
                  <div>
                    <h5 class='mb-1' style='margin-right: 10px'>مواقع التواصل</h5>
                    <p class='mb-0'><a href='https://wa.me/+201080842899' target='_blank' class='btn btn-outline-success btn-sm'>واتساب<i class='fab fa-whatsapp' style='margin-right: 5px; font-size: 22px'></i> </a></p>
                    <p class='mb-0'><a href='https://www.facebook.com/abdo0m/' target='_blank' class='btn btn-outline-primary btn-sm'>فيسبوك<i class='fab fa-facebook' style='margin-right: 5px; font-size: 22px'></i> </a></p>
                  </div>
                </div>
              </div>
            </div>`,
      // icon: "info",
      showCancelButton: true,
      confirmButtonColor: "var(--primary)",
      cancelButtonColor: "var(--danger)",
      confirmButtonText: "!",
      cancelButtonText: "الغاء",
      showConfirmButton: false,
    });
    
        });
      });
    
    </script>
    </div> </div> </div> </div>    </div>


    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js" /><link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/main-07febffb.js" /><script type="module" src="<?=$baseUrl?>/resources/build/assets/app-e4352ad6.js" data-navigate-track="reload"></script>    <!-- Livewire Scripts -->
<script src="/livewire/livewire.js?id=3605227a"  data-csrf="" data-uri="/livewire/update" data-navigate-once="true"></script>
    <script>
 
/* Guest Js */



</script>
<script type="module">
toastr.options={"positionClass": "toast-top-left"};
</script>            
</body>
</html>
