<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$username = $_SESSION['admin'];
$pageName = "files";
$subPageName = "files";

$conn = db();

mysqli_set_charset($conn, 'utf8');

$query = "SELECT * FROM files ";
$result = mysqli_query($conn,$query);

  
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Files | <?=$site_name;?></title>
    <meta name="title" content="Courses | <?=$site_name;?>">
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
                    <img src="<?=$baseUrl?>/resources/images/loading.gif" style="position:fixed;width: 120px;max-width: 80%;margin-top: -60px;"
                        id="loading-image">
                </div>

                <div class="col-12 p-3">
                    <div class="col-12 col-lg-12 p-0 main-box">

                        <div class="col-12 px-0">
                            <div class="col-12 p-0 row">
                                <div class="col-12 col-lg-4 py-3 px-3">
                                    <span class="fas fa-tags"></span> Courses
                                </div>
                                <div class="col-12 col-lg-4 p-0">
                                </div>
                                <div class="col-12 col-lg-4 p-2 text-lg-end">
                               
                                <!-- Button trigger modal -->
<a href="file-upload" class="btn btn-primary btn-sm">
<span class="fas fa-plus"></span> Add New
</a>
                               

                                </div>
                            </div>
                            <div class="col-12 divider" style="min-height: 2px"></div>
                        </div>


                        <div class="col-12 p-3" style="overflow: auto">
                            <div class="col-12 p-0" style="min-width: 1100px">


                                <table class="table table-bordered table-hover text-start" id='coursesTable' dir="ltr">
                                    <thead>
                                        <tr class="text-start">
                                            <th class="text-start">#</th>
                                            <th class="text-start">Title</th>
                                            <th class="text-start">Path</th>
                                            <th class="text-start">Actions</th>
                                            <th class="text-start">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>


                                    <?php 
                                        $count = 1;
                                        while($files_data = mysqli_fetch_assoc($result)){
                                            $full_path = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME'])."/".$files_data['path'];
                                            $html_rows = "
                                                <tr>
                                                    <td>$count</td>
                                                    <td>{$files_data['title']}</td>
                                                    <td>{$files_data['path']}</td>
                                                    <td style='width: 180px'>
                                                        <button class='btn btn-outline-primary btn-sm font-1 mx-1 embedBtn' data-src='$full_path' data-title='{$files_data['title']}'>
                                                          <span class='fas fa-code'></span> Copy Code
                                                        </button>
                                                        <form method='POST' action=''
                                                            class='d-inline-block deleteFile'>
                                                            <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                            <input type='hidden' name='file_id' value='{$files_data['id']}'> 
                                                            <input type='hidden' name='_method' value='DELETE'> 
                                                            <button class='btn btn-outline-danger btn-sm font-1 mx-1'>
                                                                <span class='fas fa-trash'></span> Delete
                                                            </button>
                                                        </form>
                                                    </td>
                                                    <td>{$files_data['created_at']}</td>

                                                </tr> 
                                            
                                            ";
                                            echo $html_rows;

                                            $count++;
                                        }

                                    ?>



                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-12 p-3">

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<div class="ajax-response"></div>

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


    $(".deleteFile").on("submit", function (e) {
      e.preventDefault();

      // Display the confirmation dialog using SweetAlert
      Swal.fire({
        title: "Are you sure?",
        text: "You will not be able to undo this!",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "var(--primary)",
        cancelButtonColor: "var(--danger)",
        confirmButtonText: "Yes, delete it!",
        cancelButtonText: "Cancel",
      }).then((result) => {
        if (result.isConfirmed) {
          // User clicked "Yes," proceed with the request
          $.ajax({
            type: "POST",
            url: "requests/file/delete",
            data: new FormData(this),
            dataType: "json",
            contentType: false,
            cache: false,
            processData: false,
            beforeSend: function () {
              // Code to execute before sending the request
            },
            success: function (response) {
              $(".response-msg").html("");
              if (response.status == 1) {
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
                      // If "No" is clicked, do something else
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
                      location.reload();
                    } else {
                      // If "No" is clicked, do something else
                    }
                  })
                );
              }
              // $("#addApi").css("opacity", "");
              // $(".submitBtn").removeAttr("disabled");
            },
          });
        } else {
          // User clicked "Cancel," do something else or simply return
        }
      });
    });





  });
</script>

<script>
$(document).ready(function() {
    // Click event for the first button
    $(".embedBtn").click(function() {
        // Get the data-src attribute value
        var dataSrc = $(this).data("src");
        var dataTitle = $(this).data("title");

        // Generate the HTML code dynamically with the updated data-src attribute
        var videoButtonCode = '<button class="btn btn-sm show-video" data-src="' + dataSrc + '"><span class="fas fa-play"></span> ' + dataTitle + '</button>';

        // Copy the generated HTML code to clipboard
        copyToClipboard(videoButtonCode);

        // Optionally, you can provide some visual feedback to the user
        alert("Code copied successfully");
    });

    // Function to copy text to clipboard
    function copyToClipboard(text) {
        var tempInput = $("<input>");
        $("body").append(tempInput);
        tempInput.val(text).select();
        document.execCommand("copy");
        tempInput.remove();
    }
});

</script>


<script>
    $("input,textarea").on('keyup',function(){$(this).parent().find('.last_appended_counter').remove();$(this).parent().append('<div class="col-12 p-2 last_appended_counter"><span class="d-inline-block" style="font-size: 13px">Character count <span class="ds-text-secondary" style="font-weight: bolder; font-size: 15px">'+$(this).val().length+'</span> characters</span></div>');});

</script>


    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/dashboard-d03a2b4e.js" />
    <link rel="modulepreload" href="<?=$baseUrl?>/resources/build/assets/main-07febffb.js" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/js/bootstrap.min.js" integrity="sha512-1/RvZTcCDEUjY/CypiMz+iqqtaoQfAITmNSJY17Myp4Ms5mdxPS5UV7iOfdZoxcGhzFbOm6sntTKJppjvuhg4g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script type="module" src="<?=$baseUrl?>/resources/build/assets/dashboard-d03a2b4e.js" data-navigate-track="reload"></script> 


 <script>

// $("table").DataTable({
//   // "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, 100, "All"] ],
//       "responsive": true, "lengthChange": true, "autoWidth": true,
//     //   "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
//       "buttons": [
//         { 
//         extend: 'copy',
//         text: 'Copy',
//         exportOptions:{columns: ':visible'}
//         },{ 
//         extend: 'csv',
//         text: 'Excel (CSV)',
//         exportOptions:{columns: ':visible'}
//         },{ 
//         extend: 'excel',
//         text: 'Excel',
//         exportOptions:{columns: ':visible'}
//         }
//         // ,{ 
//         // extend: 'pdf',
//         // text: 'PDF',
//         // exportOptions:{columns: ':visible'}
//         // }
//         ,{ 
//         extend: 'print',
//         text: 'Print',
//         exportOptions:{columns: ':visible'}
//         },{ 
//         extend: 'colvis',
//         text: 'View'
//         },
//       ],
//       language: {
//         paginate: {
//           next: 'Next', // or '→'
//           previous: 'Previous' // or '←' 
//         },
//         "search": "Search:"
//        },
//        oLanguage: {
//                "sInfo" : "Showing _START_ to _END_ of _TOTAL_ entries",// text you want show for info section
//                "sLengthMenu": "Show _MENU_ rows",

//         },
//     });



    $.noConflict();
jQuery( document ).ready(function( $ ) {
    $('table').DataTable({
        // "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, 100, "All"] ],
      "responsive": true, "lengthChange": true, "autoWidth": true,
    //   "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
      "buttons": [
        { 
        extend: 'copy',
        text: 'Copy',
        exportOptions:{columns: ':visible'}
        },{ 
        extend: 'csv',
        text: 'Excel (CSV)',
        exportOptions:{columns: ':visible'}
        },{ 
        extend: 'excel',
        text: 'Excel',
        exportOptions:{columns: ':visible'}
        }
        // ,{ 
        // extend: 'pdf',
        // text: 'PDF',
        // exportOptions:{columns: ':visible'}
        // }
        ,{ 
        extend: 'print',
        text: 'Print',
        exportOptions:{columns: ':visible'}
        },{ 
        extend: 'colvis',
        text: 'View'
        },
      ],
      language: {
        paginate: {
          next: 'Next', // or '→'
          previous: 'Previous' // or '←' 
        },
        "search": "Search:"
       },
       oLanguage: {
               "sInfo" : "Showing _START_ to _END_ of _TOTAL_ entries",// text you want show for info section
               "sLengthMenu": "Show _MENU_ rows",

        },

    });
});    

// $('#coursesTable').dataTable( {
//     "drawCallback": function( settings ) {
//         alert( 'DataTables has redrawn the table' );
//     }
// } );
</script>




<script>

$(document).ready(function() {
    setInterval(function() {
    $.ajax({
        url: 'requests/alive/keep',
        method: 'POST',
        // other settings
    });
}, 300000); // 5 minutes in milliseconds
  $('.select2').each(function() { 
    $(this).select2({ dropdownParent: $(this).parent()});
})
});

</script>

<script>

</script>

</body>

</html>
