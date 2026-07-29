<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$username = $_SESSION['admin'];
$pageName = "users";
$subPageName = "users";

$conn = db();
$query = "SELECT * FROM users WHERE role='user' ";
$result = mysqli_query($conn,$query);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Courses | <?=$site_name;?></title>
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
    <form method="POST" action="<?=$baseUrl?>/resources/logout" id="logout-form" class="d-none"><input type="hidden"
            name="_token" value="XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH"></form>
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
<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#exampleModal">
<span class="fas fa-plus"></span> Add New
</button>
                               
                                    <!-- Modal -->
<div class="modal fade" id="exampleModal"  aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="exampleModalLabel">Add New Course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form action="requests/add-category" method="POST" id="addCategory" enctype="multipart/form-data">
            <fieldset class="form-fieldset api-mode">
                    <label
                    class='ds-text-secondary' style="display: flex; justify-content: center; font-size: 18px">Details
                    Course</label>

                    <div class="col-12 p-3 row">

                    

                    <div class="col-12 col-lg-12 p-2">
                          <div class="col-12">
                            Category
                          </div>
                          <div class="col-12 pt-3">
                          <select class="form-control select2" id="" name="course_category"  data-placeholder="أختار Category" style="width: 100%"  required>
                              <?php
                                  
                              
                                  $categories_result = mysqli_query(db(),'SELECT * FROM categories');
                                  if( mysqli_num_rows($categories_result) > 0 ){
                                      
                                      while($category_data = mysqli_fetch_array($categories_result) ){
                                          $category_title = $category_data['category_title'];
                                          $category_id = $category_data['category_id'];
                                          echo "
                                              <option value='$category_id'>$category_title</option>
                                          ";
                                      }
                                  }
                              
                              ?>
                          </select>
                          </div>
                    </div>


                    <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Title
                            </div>
                            <div class="col-12 pt-3">
                                <input type="text" name="course_title" required="" maxlength="190" class="form-control"  placeholder="اكتب عنوان Course">
                            </div>
                        </div>


                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            English Title
                            </div>
                            <div class="col-12 pt-3">
                                <input type="text" name="course_title_en" required="" maxlength="190" class="form-control"  placeholder="اكتب عنوان Course بالأنجلش">
                            </div>
                        </div>


                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Price
                            </div>
                            <div class="col-12 pt-3">
                                <input type="number" name="course_price" required="" min="0" maxlength="190" class="form-control"  placeholder="سعر Course باللغة الانجليزية">
                          </div>
                        </div>


                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Description
                            </div>
                            <div class="col-12 pt-3">
                                <textarea class="form-control" name="course_description" rows="2" placeholder="اكتب وصف Course هنا - SEO" required></textarea>
                            </div>
                        </div>



                        <div class="col-12 col-lg-12 p-2">
                          <div class="col-12">
                            صورة Course <small class='text-primary'>{Optional}</small>
                          </div>
                          <div class="col-12 pt-3">
                            <input type="file" name="course_image" class="form-control" accept="image/*">
                          </div>
                        </div>


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


                                <table class="table table-bordered table-hover text-start" id='coursesTable' dir="ltr">
                                    <thead>
                                        <tr class="text-start">
                                            <th class="text-start">#</th>
                                            <th class="text-start">Name</th>
                                            <th class="text-start">Username</th>
                                            <th class="text-start">Price</th>
                                            <th class="text-start">Status</th>
                                            <th class="text-start">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>


                                    <?php 
                                        $count = 1;
                                        while($users_data = mysqli_fetch_assoc($result)){
                                            
                                          $student_status = $courses_data['student_status'];
                                          $checked = '';
                                          if ($student_status == 1) {
                                            $checked = 'checked';
                                            $student_status = 2;
                                          }else{
                                            $student_status = 1;
                                          }
                                            $html_rows = "
                                                <tr>
                                                    <td>$count</td>
                                                    <td>{$users_data['full_name']}</td>
                                                    <td>{$users_data['username']}</td>
                                                    <td>{$users_data['username']} EGP</td>
                                                    <td>
                                                      <form action='' method='POST' class='update-course-status'>
                                                        <div class='form-check form-switch'>
                                                        <input class='form-check-input' type='checkbox' name='course_status' role='switch' id='course-status-{$courses_data['course_id']}' $checked value='$course_status'>
                                                        <input type='hidden' name='course_id' value='{$users_data['id']}'>
                                                        <input type='hidden' name='update-status' value='1'>
                                                        <label class='form-check-label' for='flexSwitchCheckDefault'></label>
                                                        </div>
                                                      </form>
                                                    </td>

                                                    <td style='width: 250px'>

                                                        <form method='POST' action=''
                                                            class='d-inline-block editCourse'>
                                                            <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                            <input type='hidden' name='course_id' value='{$courses_data['course_id']}'> 
                                                            <input type='hidden' name='_method' value='GET'> 
                                                            <button class='btn btn-outline-success btn-sm font-small mx-1'>
                                                                <span class='fas fa-wrench'></span> Actions
                                                            </button>
                                                        </form>

                                                        <div class='dropdown d-inline-block'>
                                                        <button class='py-1 px-2 btn btn-outline-primary font-small' type='button' id='dropdownMenuButton1' data-bs-toggle='dropdown' aria-expanded='false'>
                                                        <span class='fas fa-bars'></span> Items
                                                        </button>
                                                          <ul class='dropdown-menu' aria-labelledby='dropdownMenuButton1'>
                                                            <li>
                                                              <form method='POST' action=''
                                                                class='d-inline-block itemForm'>
                                                                <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                                <input type='hidden' name='course_id' value='{$courses_data['course_id']}'> 
                                                                <input type='hidden' name='_method' value='GET'> 
                                                                <button class='dropdown-item font-1'>
                                                                <span class='fas fa-plus'></span> اضافة عنصر
                                                                </button>
                                                              </form>
                                                            </li>

                                                            <li>
                                                              <form method='POST' action=''
                                                                class='d-inline-block allItems'>
                                                                <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                                <input type='hidden' name='category_id' value='{$categories_data['category_id']}'> 
                                                                <input type='hidden' name='_method' value='GET'> 
                                                                <button class='dropdown-item font-1'>
                                                                <span class='fas fa-bars'></span> جميع Items <span class='badge bg-danger'>811</span>
                                                                </button>
                                                              </form>
                                                            </li>
                                            
                            
                                                          </ul>
                                                        </div>
                                                        <!--
                                                        <form method='POST' action=''
                                                            class='d-inline-block editCourse'>
                                                            <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                            <input type='hidden' name='category_id' value='{$categories_data['category_id']}'> 
                                                            <input type='hidden' name='_method' value='GET'> 
                                                            <button class='btn btn-outline-primary btn-sm font-small mx-1'>
                                                                <span class='fas fa-bars'></span> Items
                                                            </button>
                                                        </form>
                                                        -->

                                                        <form method='POST' action=''
                                                            class='d-inline-block deleteCourse'>
                                                            <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                            <input type='hidden' name='course_id' value='{$courses_data['course_id']}'> 
                                                            <input type='hidden' name='_method' value='DELETE'> 
                                                            <button type='submit' class='btn btn-outline-danger btn-sm font-small mx-1'>
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
    $("#addCategory").on("submit", function (e) {
        
      const bar = $('.bar');
      const percent = $('.percent');
      const status = $('#status');

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
        url: "requests/course/add",
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
   $(".editCourse").on("submit", function (e) {
      e.preventDefault();
      $.ajax({
        type: "POST",
        url: "requests/course/edit",
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
            $("#updateCourse").on("submit", function (e) {
              e.preventDefault();
              $.ajax({
                type: "POST",
                url: "requests/course/edit",
                data: new FormData(this),
                dataType: "json",
                contentType: false,
                cache: false,
                processData: false,
                beforeSend: function () {
                  $(".submitBtn").attr("disabled", "disabled");
                  $("#updateCourse").css("opacity", ".5");
                },
                success: function (response) {
                  $(".response-msg").html("");
                  if (response.status == 1) {
                    $("#updateCourse")[0].reset();
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
                  $("#updateCourse").css("opacity", "");
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
    $(".deleteCourse").on("submit", function (e) {
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
        url: "requests/course/delete",
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
$(".update-course-status").change(function () {
      // var teacher_status = $(this).val();
      // var teacher_status = $(this).val();
      var Toast = Swal.mixin({
      toast: true,
      position: 'top-end',
      showConfirmButton: false,
      timer: 10000
      });

      var update_status = 1;
      $.ajax({
        type: "POST",
        url: "requests/course/status",
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






//Items Part



   // Edit a Teacher
   $(".itemForm").on("submit", function (e) {
      e.preventDefault();
      $.ajax({
        type: "POST",
        url: "requests/item/form",
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
            $("#updateCourse").on("submit", function (e) {
              e.preventDefault();
              $.ajax({
                type: "POST",
                url: "requests/course/edit",
                data: new FormData(this),
                dataType: "json",
                contentType: false,
                cache: false,
                processData: false,
                beforeSend: function () {
                  $(".submitBtn").attr("disabled", "disabled");
                  $("#updateCourse").css("opacity", ".5");
                },
                success: function (response) {
                  $(".response-msg").html("");
                  if (response.status == 1) {
                    $("#updateCourse")[0].reset();
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
                  $("#updateCourse").css("opacity", "");
                  $(".submitBtn").removeAttr("disabled");
                },
              });
            });
            //*** Send Edit Request




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

          var temp_file_selector = document.getElementById('temp_file_selector') !== null?document.getElementById('temp_file_selector').value:null;

tinymce.init({
    selector: '.editor,#editor',
    plugins: ' advlist image media autolink code codesample directionality table wordcount quickbars link lists',
    images_upload_url:"http://127.0.0.1:8000/admin/upload/image?_token=wGM0umw3mLc1xx9M3qecEnorMyeeI1w5CKHLiag3&temp_file_selector="+temp_file_selector,
    file_picker_types: 'file image media',
    image_caption: true,
    image_dimensions:true,
    directionality : 'ltr',
    language:'en',
    quickbars_selection_toolbar: 'bold italic |h1 h2 h3 h4 h5 h6| formatselect | quicklink blockquote | numlist bullist',
    entity_encoding : "raw",
    verify_html : false ,
    object_resizing : 'img',
});

        },
      });
    });






//End Items Part






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
  $('.select2').each(function() { 
    $(this).select2({ dropdownParent: $(this).parent()});
})
});

</script>

<script>

</script>

</body>

</html>