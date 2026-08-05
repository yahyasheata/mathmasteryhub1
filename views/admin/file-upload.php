<?php
require_once 'connection/config.php';
require_once 'inc/functions.php';
$username = $_SESSION['admin'];

$pageName = "files";

$user_data = getUserData($username);
$full_name = $user_data['full_name'];



?>
<!DOCTYPE html>
<html lang="en">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Upload File |
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
input[type=file], #fileList {
padding: 10px;
border: 1px solid var(--border);
background: var(--surface);
}
#list div { padding: 3px 0; }

#fileList {
    width: 80%;
    margin: 0 auto;
    text-align: center;
    font-size: 22px !IMPORTANT;
    /* display: block; */
    display: flex;
    flex-direction: column;
    align-items: center;
}

div[id^="o_"] {
    font-size: 15px;
    font-weight: bold;
    padding: 5px;
    background: var(--success-soft);
    border-radius: 5px;
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
			<div class="col-12 col-lg-12 my-2">
				<form method="POST" action="" id="fileUpload" enctype="multipart/form-data">
					<input type="hidden" name="_token" value="RmvgJtwhQjaTDIX6sSYWraTn7PXXzq2m8II8Pi3A">					<input type="hidden" name="_method" value="PUT">					<div class="col-12 p-0 main-box shadow">
						<div class="col-12 px-0">
							<div class="col-12 px-3 py-3">
							 	<span class="fas fa-info-circle"></span>	Basic Information
							</div>
							<div class="col-12 divider" style="min-height: 2px"></div>
						</div>

                            <div class="col-12 p-2">
								<!-- <div class="col-12">
									File Title
								<span class='ds-text-danger' style="font-size: 16px">*</span></div>
								<div class="col-12 pt-3">
									<input type="text" name="file_title" required="" min="3" max="190" class="form-control" value="" >
								</div>
							</div>
                             -->
							<!--<div class="col-12 p-2">-->
							<!--	<div class="col-12">-->
							<!--		اختار File-->
							<!--	<span class='ds-text-danger' style="font-size: 16px">*</span></div>-->
							<!--	<div class="col-12 pt-3">-->
       <!--                             <input type="file" id="fileInput" name="file" class="form-control" accept="video/*" required>-->
       <!--                         </div>-->
							<!--</div>-->

<div class="mb-3" style="margin: 0px 9px 0px 8px">
    <div class='progress ds-text-inverse' style="direction: ltr; margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px">
        <div class="progress-bar" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="progressBar"></div>
    </div>
</div>                            
							
                            <input type='hidden' name='file_upload' value="1" />

							<div class="col-12 p-2">
								<div class="col-12 pt-3">
									<button class="btn btn-primary uploadBtn" id="pick" type="submit"><i class="fas fa-cloud-upload-alt"></i> Choose Files</button>
									
								</div>
							</div>
                            
                            <div id='fileList'></div>

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




<script type="text/javascript">
  $(document).ready(function (e) {
        
  jQuery( document ).ajaxStart(function() {
    NProgress.start();
  });

  jQuery( document ).ajaxStop(function() {
    NProgress.done();
  });

  });

</script>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/plupload/3.1.5/plupload.full.min.js"></script>


<script>
// (C) INITIALIZE UPLOADER
window.onload = () => {
function randomNum(min, max) { 
    var n = []; 
    for(var i=0;i<3;i++){ 
    n.push(Math.floor(Math.random() * max) + min); 
    } 
    return n; 
} 
const randomId = randomNum(10, 1000)[0];
    // (C1) GET HTML FILE LIST
    var list = document.getElementById("fileList");
    var progressBar = document.getElementById("progressBar");

    // (C2) INIT PLUPLOAD
    var uploader = new plupload.Uploader({
        runtimes: "html5",
        browse_button: "pick",
        url: "/admin/requests/file/upload",
        chunk_size: "2mb",
        totalUploaded: 0, // Track the total uploaded files
        init: {
            PostInit: () => list.innerHTML = "<div>Ready</div>",
            FilesAdded: (up, files) => {
                plupload.each(files, file => {
                    // Rename the file before uploading
                    file.name = "newPrefix_" + file.name;

                    // Set custom parameters for each file
                    up.setOption({
                        multipart_params: {
                            // Add any additional parameters here
                            name: file.name,
                            randomId: randomId  // Add any other custom parameters if needed
                        }
                    });

                    let row = document.createElement("div");
                    row.id = file.id;
                    row.innerHTML = `${file.name} (${plupload.formatSize(file.size)}) <strong></strong>`;
                    list.appendChild(row);
                });
                uploader.start();
            },
            UploadProgress: (up, file) => {
                document.querySelector(`#${file.id} strong`).innerHTML = `${file.percent}%`;
                progressBar.style.width = `${up.total.percent}%`;
            },
            Error: (up, err) => console.error(err),
            FileUploaded: (up, file, res) => {
                // File uploaded successfully, you can handle the response here
                console.log(res.response);

                // Increment the totalUploaded count
                uploader.totalUploaded++;

                // Check if all files are uploaded
                if (uploader.totalUploaded === uploader.files.length) {
                    // Redirect to files.html when all files are uploaded
                    window.location.href = "files";
                }
            }
        }
    });
    uploader.init();
};
</script>



<script type="text/javascript">
  $(document).ready(function (e) {
        
  jQuery( document ).ajaxStart(function() {
    NProgress.start();
  });

  jQuery( document ).ajaxStop(function() {
    NProgress.done();
  });

  });

</script>

<script>
  $(document).ready(function (e) {
    setInterval(function() {
    $.ajax({
        url: 'requests/alive/keep',
        method: 'POST',
        // other settings
    });
    }, 300000); // 5 minutes in milliseconds
  });

</script>




</body>

</html>
