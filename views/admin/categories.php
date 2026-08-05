<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$username = $_SESSION['admin'];
$pageName = "courses";
$subPageName = "categories";

$conn = db();
$query = "SELECT * FROM categories";
$result = mysqli_query($conn,$query);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Categories | <?=$site_name;?></title>
    <meta name="title" content="Categories | <?=$site_name;?>">

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
                                    <span class="fas fa-tags"></span> Categories
                                </div>
                                <div class="col-12 col-lg-4 p-0">
                                </div>
                                <div class="col-12 col-lg-4 p-2 text-lg-end">
                               
                                <!-- Button trigger modal -->
<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#exampleModal">
<span class="fas fa-plus"></span> Add New
</button>
                               
                                    <!-- Modal -->
<div class="modal fade" id="exampleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="exampleModalLabel">Add New Category</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">X</button>
      </div>
      <div class="modal-body">
        <form action="requests/add-category" method="POST" id="addCategory" enctype="multipart/form-data">
            <fieldset class="form-fieldset api-mode">
                    <label
                    class='ds-text-secondary' style="display: flex; justify-content: center; font-size: 18px">Details
                    Category</label>

                    <div class="col-12 p-3 row">

                    <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Title
                            </div>
                            <div class="col-12 pt-3">
                                <input type="text" name="category_title" required="" maxlength="190" class="form-control"  placeholder="Enter the category title">
                            </div>
                        </div>


                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Link
                            </div>
                            <div class="col-12 pt-3">
                                <input type="text" name="category_link" required="" maxlength="190" class="form-control"  placeholder="Enter the category title">
                            </div>
                        </div>


                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Description
                            </div>
                            <div class="col-12 pt-3">
                                <textarea class="form-control" name="category_description" rows="3" placeholder="Enter the category description here - SEO" required></textarea>
                            </div>
                        </div>

                        <div class="col-12 col-lg-6 p-2">
                          <div class="col-12">
                            Category Image <small class='text-primary'>{Optional}</small>
                          </div>
                          <div class="col-12 pt-3">
                            <input type="file" name="category_image" class="form-control" accept="image/*">
                          </div>
                        </div>

<!-- 

                    <div class="col-sm-12">
                        <div class="form-group">
                        <label class="p-2" for="exampleInputEmail1" >Title</label>
                        <input type="text" class="form-control form-control-border" id="student_name"
                            name="category_title" placeholder="Enter the category title" required>
                        </div>
                    </div>
                    <div class="col-sm-12">
                        <div class="form-group">
                        <label class="p-2" for="exampleInputEmail1">Link</label>
                        <input type="text" class="form-control form-control-border" id="student_name"
                            name="category_link" placeholder="English link, for example: 1secondary" required>
                        </div>
                    </div>

                    </div>

                    <div class="row">

                    <div class="col-sm-12">
                        <div class="form-group">
                        <label class="p-2" for="exampleInputEmail1">Description</label>
                        <textarea class="form-control" name="category_description" rows="3" placeholder="Enter the category description here - SEO" required></textarea>
                        </div>
                    </div>
 -->

                    </div>
            </fieldset>
            
            <div class='progress ds-text-inverse' style="margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px" id="progress-div"><div class="progress-bar bg-success" role="progressbar" aria-valuenow="25" aria-valuemin="0" aria-valuemax="100" id="progress-bar"></div></div>
        
        
            <!-- </form> -->
      </div>
      <div class="modal-footer p-2">
        <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-outline-primary submitBtn">Save</button>
      </div>
      </form>

    </div>
  </div>
</div>

                                </div>
                            </div>
                            <div class="col-12 divider" style="min-height: 2px"></div>
                        </div>


                        <div class="col-12 p-3" style="overflow: auto">
                            <div class="col-12 p-0" style="min-width: 1100px">


                                <table class="table table-bordered table-hover text-start" dir="ltr">
                                    <thead>
                                        <tr class="text-start">
                                            <th class="text-start">#</th>
                                            <th class="text-start">Title</th>
                                            <th class="text-start">Link</th>
                                            <th class="text-start">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>


                                    <?php 
                                        $count = 1;
                                        while($categories_data = mysqli_fetch_assoc($result)){
                                            
                                            $html_rows = "
                                                <tr>
                                                    <td>$count</td>
                                                    <td>{$categories_data['category_title']}</td>
                                                    <td>{$categories_data['category_link']}</td>

                                                    <td style='width: 180px'>

                                                        <form method='POST' action=''
                                                            class='d-inline-block editCategory'>
                                                            <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                            <input type='hidden' name='category_id' value='{$categories_data['category_id']}'> 
                                                            <input type='hidden' name='_method' value='GET'> 
                                                            <button class='btn btn-outline-success btn-sm font-1 mx-1'>
                                                                <span class='fas fa-wrench'></span> Actions
                                                            </button>
                                                        </form>

                                                        <form method='POST' action=''
                                                            class='d-inline-block deleteCategory'>
                                                            <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                            <input type='hidden' name='category_id' value='{$categories_data['category_id']}'> 
                                                            <input type='hidden' name='_method' value='DELETE'> 
                                                            <button class='btn btn-outline-danger btn-sm font-1 mx-1'>
                                                                <span class='fas fa-trash'></span> Delete
                                                            </button>
                                                        </form>
                                                    </td>
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
    // Add New Teacher
      const bar = $('.bar');
      const percent = $('.percent');
      const status = $('#status');
      
    $("#addCategory").on("submit", function (e) {
        


      e.preventDefault();
      $.ajax({
        xhr: function() {
          var xhr = new window.XMLHttpRequest();
          xhr.upload.addEventListener("progress", function(evt) {
          if (evt.lengthComputable) {
            var percentComplete = parseInt(((evt.loaded / evt.total) * 100));
            $("#progress-bar").width(percentComplete + '%');
            $("#progress-bar").html(percentComplete+'%');
          }
          }, false);
          return xhr;
        },
        type: "POST",
        url: "requests/category/add",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          $(".submitBtn").attr("disabled", "disabled");
          $("#addCategory").css("opacity", ".5");
          
          $("#progress-bar").width('0%');
          $('#loader-icon').show();
        },
        success: function (response) {
          $(".response-msg").html("");
          if (response.status == 1) {
            $("#addCategory")[0].reset();
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
                //   location.reload();
                } else {
                  //if no clicked => do something else
                }
              })
            );
          }
          $("#addCategory").css("opacity", "");
          $(".submitBtn").removeAttr("disabled");
        },
      });
    });
    //End script Of Add New Teacher


   // Edit a Teacher
   $(".editCategory").on("submit", function (e) {
      e.preventDefault();
      $.ajax({
        type: "POST",
        url: "requests/category/edit",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          $(".submitBtn").attr("disabled", "disabled");
          $("#addApi").css("opacity", ".5");
        },
        success: function (response) {
          $(".response-msg").html("");
          if (response.status == 1) {
            console.log("goodddddddddddddd");
            $(".ajax-response").html(response.html);
            //Initialize Select2 Elements
            // Initialize Select2 with tags enabled
            $('.select2-edit').select2({
              tags: true
            });

            // Get all options
            var options = $('.select2-edit option');

            // Set selected for each option
            options.each(function() {
              $(this).prop('selected', true);
            });

            // Trigger change event to update Select2
            $('.select2-edit').trigger('change');

            $('#response-html-modal').modal('show');
            

            //*** Send Edit Request
            $("#updateCategory").on("submit", function (e) {
              e.preventDefault();
              $.ajax({
                xhr: function() {
                  var xhr = new window.XMLHttpRequest();
                  xhr.upload.addEventListener("progress", function(evt) {
                  if (evt.lengthComputable) {
                    var percentComplete = parseInt(((evt.loaded / evt.total) * 100));
                    $("#updateProgress-bar").width(percentComplete + '%');
                    $("#updateProgress-bar").html(percentComplete+'%');
                  }
                  }, false);
                  return xhr;
                },
                type: "POST",
                url: "requests/category/edit",
                data: new FormData(this),
                dataType: "json",
                contentType: false,
                cache: false,
                processData: false,
                beforeSend: function () {
                  $(".submitBtn").attr("disabled", "disabled");
                  $("#updateCategory").css("opacity", ".5");
          
                  $("#updateProgress-bar").width('0%');
                  $('#loader-icon').show();
                },
                success: function (response) {
                  $(".response-msg").html("");
                  if (response.status == 1) {
                    $("#updateCategory")[0].reset();
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
                  $("#updateCategory").css("opacity", "");
                  $(".submitBtn").removeAttr("disabled");
                },
              });
            });
            //*** Send Edit Request









            // $("#addApi")[0].reset();
            // $(".response-msg").html(
            //   Swal.fire({
            //     icon: "success",
            //     title: response.message,
            //     showConfirmButton: false,
            //     timer: 2000,
            //     timerProgressBar: true,
            //   }).then(function (isConfirm) {
            //     if (isConfirm) {
            //       location.reload();
            //     } else {
            //       //if no clicked => do something else
            //     }
            //   })
            // );

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
                  //if no clicked => do something else
                }
              })
            );
          }
          $("#addApi").css("opacity", "");
          $(".submitBtn").removeAttr("disabled");
        },
      });
    });
    //End script Of Edit Teacher






    // start of delete teacher
    $(".deleteCategory").on("submit", function (e) {
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
        url: "requests/category/delete",
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


// Edit status
$(".update-teacher-status").change(function () {
      // var teacher_status = $(this).val();
      // var teacher_status = $(this).val();
      var Toast = Swal.mixin({
      toast: true,
      position: 'bottom-end',
      showConfirmButton: false,
      timer: 10000
      });

      var update_status = 1;
      $.ajax({
        type: "POST",
        url: "requests/teacher",
        // data: {teacher_status:teacher_status,_update_status:update_status},
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
        },
        success: function (response) {
          $(".response-msg").html("");
          if (response.status == 1) {
            $(".response-msg").html(
              Toast.fire({
                icon: 'success',
                title: response.message
              })
            );
          } else {
            $(".response-msg").html(
              Toast.fire({
                icon: 'error',
                title: response.message+', '+response.reason
              })
            );
          }
          // $("#addApi").css("opacity", "");
          // $(".submitBtn").removeAttr("disabled");
        },
      });

    });
//End Edit status

  });
</script>



<script>
    $("input,textarea").on('keyup',function(){$(this).parent().find('.last_appended_counter').remove();$(this).parent().append('<div class="col-12 p-2 last_appended_counter"><span class="d-inline-block" style="font-size: 13px">Character count <span class="ds-text-secondary" style="font-weight: bolder; font-size: 15px">'+$(this).val().length+'</span> characters</span></div>');});

</script>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/js/bootstrap.min.js" integrity="sha512-1/RvZTcCDEUjY/CypiMz+iqqtaoQfAITmNSJY17Myp4Ms5mdxPS5UV7iOfdZoxcGhzFbOm6sntTKJppjvuhg4g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<!-- 
     -->
    
     <!-- <link href="https://cdn.datatables.net/v/bs5/jszip-3.10.1/dt-1.13.6/af-2.6.0/b-2.4.2/b-colvis-2.4.2/b-html5-2.4.2/b-print-2.4.2/cr-1.7.0/date-1.5.1/fc-4.3.0/fh-3.4.0/kt-2.10.0/r-2.5.0/rg-1.4.1/rr-1.4.1/sc-2.2.0/sb-1.6.0/sp-2.2.0/sl-1.7.0/sr-1.3.0/datatables.min.css" rel="stylesheet">
 
 <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
 <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
 <script src="https://cdn.datatables.net/v/bs5/jszip-3.10.1/dt-1.13.6/af-2.6.0/b-2.4.2/b-colvis-2.4.2/b-html5-2.4.2/b-print-2.4.2/cr-1.7.0/date-1.5.1/fc-4.3.0/fh-3.4.0/kt-2.10.0/r-2.5.0/rg-1.4.1/rr-1.4.1/sc-2.2.0/sb-1.6.0/sp-2.2.0/sl-1.7.0/sr-1.3.0/datatables.min.js"></script>
 -->

 <script>

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


</body>

</html>
