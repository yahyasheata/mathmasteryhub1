<?php
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
$username = $_SESSION['admin'];
$pageName = "users";
$subPageName = "users";

$conn = db();

mysqli_set_charset($conn, 'utf8');

$query = "SELECT *,CASE
WHEN gender = 'male' THEN 'ذكر'
WHEN gender = 'female' THEN 'انثى'
END AS translated_gender FROM users WHERE role='user' ";
$result = mysqli_query($conn,$query);




$governorates = [
  [
      'id' => '1',
      'governorate_name_ar' => 'القاهرة',
      'governorate_name_en' => 'Cairo'
  ],
  [
      'id' => '2',
      'governorate_name_ar' => 'الجيزة',
      'governorate_name_en' => 'Giza'
  ],
  [
      'id' => '3',
      'governorate_name_ar' => 'الأسكندرية',
      'governorate_name_en' => 'Alexandria'
  ],
  [
      'id' => '4',
      'governorate_name_ar' => 'الدقهلية',
      'governorate_name_en' => 'Dakahlia'
  ],
  [
      'id' => '5',
      'governorate_name_ar' => 'البحر الأحمر',
      'governorate_name_en' => 'Red Sea'
  ],
  [
      'id' => '6',
      'governorate_name_ar' => 'البحيرة',
      'governorate_name_en' => 'Beheira'
  ],
  [
      'id' => '7',
      'governorate_name_ar' => 'الفيوم',
      'governorate_name_en' => 'Fayoum'
  ],
  [
      'id' => '8',
      'governorate_name_ar' => 'الغربية',
      'governorate_name_en' => 'Gharbiya'
  ],
  [
      'id' => '9',
      'governorate_name_ar' => 'الإسماعلية',
      'governorate_name_en' => 'Ismailia'
  ],
  [
      'id' => '10',
      'governorate_name_ar' => 'المنوفية',
      'governorate_name_en' => 'Menofia'
  ],
  [
      'id' => '11',
      'governorate_name_ar' => 'المنيا',
      'governorate_name_en' => 'Minya'
  ],
  [
      'id' => '12',
      'governorate_name_ar' => 'القليوبية',
      'governorate_name_en' => 'Qaliubiya'
  ],
  [
      'id' => '13',
      'governorate_name_ar' => 'الوادي الجديد',
      'governorate_name_en' => 'New Valley'
  ],
  [
      'id' => '14',
      'governorate_name_ar' => 'السويس',
      'governorate_name_en' => 'Suez'
  ],
  [
      'id' => '15',
      'governorate_name_ar' => 'اسوان',
      'governorate_name_en' => 'Aswan'
  ],
  [
      'id' => '16',
      'governorate_name_ar' => 'اسيوط',
      'governorate_name_en' => 'Assiut'
  ],
  [
      'id' => '17',
      'governorate_name_ar' => 'بني سويف',
      'governorate_name_en' => 'Beni Suef'
  ],
  [
      'id' => '18',
      'governorate_name_ar' => 'بورسعيد',
      'governorate_name_en' => 'Port Said'
  ],
  [
      'id' => '19',
      'governorate_name_ar' => 'دمياط',
      'governorate_name_en' => 'Damietta'
  ],
  [
      'id' => '20',
      'governorate_name_ar' => 'الشرقية',
      'governorate_name_en' => 'Sharkia'
  ],
  [
      'id' => '21',
      'governorate_name_ar' => 'جنوب سيناء',
      'governorate_name_en' => 'South Sinai'
  ],
  [
      'id' => '22',
      'governorate_name_ar' => 'كفر الشيخ',
      'governorate_name_en' => 'Kafr Al sheikh'
  ],
  [
      'id' => '23',
      'governorate_name_ar' => 'مطروح',
      'governorate_name_en' => 'Matrouh'
  ],
  [
      'id' => '24',
      'governorate_name_ar' => 'الأقصر',
      'governorate_name_en' => 'Luxor'
  ],
  [
      'id' => '25',
      'governorate_name_ar' => 'قنا',
      'governorate_name_en' => 'Qena'
  ],
  [
      'id' => '26',
      'governorate_name_ar' => 'شمال سيناء',
      'governorate_name_en' => 'North Sinai'
  ],
  [
      'id' => '27',
      'governorate_name_ar' => 'سوهاج',
      'governorate_name_en' => 'Sohag'
  ]

];

  $governorates_options = '';
  foreach ($governorates as $governorate ) {
      // echo "ID: " . $governorate['id'] . "\n";
      // echo "Governorate (Arabic): " . $governorate['governorate_name_ar'] . "\n";
      // echo "Governorate (English): " . $governorate['governorate_name_en'] . "\n";
      // echo "<br>";
      $governorates_options .= "<option value='{$governorate['id']}'>{$governorate['governorate_name_ar']}</option>";
  }


  
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Users | <?=$site_name;?></title>
    <meta name="title" content="Users | <?=$site_name;?>">
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
                                    <span class="fas fa-tags"></span> Users
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
        <h5 class="modal-title" id="exampleModalLabel">Add New Student</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form action="" method="POST" id="addUser" enctype="multipart/form-data">
            <fieldset class="form-fieldset api-mode">
                    <label
                    class='ds-text-secondary' style="display: flex; justify-content: center; font-size: 18px">Details
                    الطالب</label>

                    <div class="col-12 p-3 row">

                    


                    <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Full Name
                            </div>
                            <div class="col-12 pt-3">
                                <input type="text" name="name" required="" maxlength="" minlength="10" class="form-control"  placeholder="Student full name" required>
                            </div>
                        </div>


                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Student mobile number
                            </div>
                            <div class="col-12 pt-3">
                                <input type="number" name="phone_number" required="" maxlength="11" minlength="11" class="form-control"  placeholder="Student mobile number" required>
                            </div>
                        </div>



                        <div class="col-12 col-lg-6 p-2">
                              <div class="col-12">
                                Type
                              </div>
                              <div class="col-12 pt-3">
                              <select class="form-control select2" id="" name="gender"  data-placeholder="Select type" style="width: 100%"  required>
                                  <option disabled="" selected="" hidden="">Select type</option>
                                  <option value='male'>ذكر</option>
                                  <option value='female'>انثي</option>
                              </select>
                              </div>
                        </div>


                        <div class="col-12 col-lg-6 p-2">
                              <div class="col-12">
                                Governorate
                              </div>
                              <div class="col-12 pt-3">
                                <select class="form-control select2" id="" name="governorate"  data-placeholder="Select type" style="width: 100%"  required>
                                  <option disabled="" selected="" hidden="">اختار Governorate</option>
                                  <?=$governorates_options;?>
                                </select>
                              </div>
                        </div>

                        <div class="col-12 col-lg-6 p-2">
                            <div class="col-12">
                            Password
                            </div>
                            <div class="col-12 pt-3">
                                <input type="password" name="password" required="" minlength="6" class="form-control"  placeholder="Student password" required>
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
                                            <th class="text-start">Guardian Number</th>
                                            <th class="text-start">Type</th>
                                            <th class="text-start">Governorate</th>
                                            <th class="text-start">Balance</th>
                                            <th class="text-start">Status</th>
                                            <th class="text-start">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>


                                    <?php 
                                        $count = 1;
                                        while($users_data = mysqli_fetch_assoc($result)){
                                            
                                          $student_status = $users_data['status'];
                                          $checked = '';
                                          if ($student_status == 1) {
                                            $checked = 'checked';
                                            $student_status = 2;
                                          }else{
                                            $student_status = 1;
                                          }
                                          $governorateName = governoratesInfo($users_data['governorate'])['governorate_name_ar'];
                                            $html_rows = "
                                                <tr>
                                                    <td>$count</td>
                                                    <td>{$users_data['full_name']}</td>
                                                    <td>{$users_data['username']}</td>
                                                    <td>{$users_data['guardian_number']}</td>
                                                    <td>{$users_data['translated_gender']}</td>
                                                    <td>$governorateName</td>
                                                    <td>{$users_data['balance']} EGP</td>
                                                    <td>
                                                      <form action='' method='POST' class='update-user-status'>
                                                        <div class='form-check form-switch'>
                                                        <input class='form-check-input' type='checkbox' name='user_status' role='switch' id='user-status-{$users_data['user_id']}' $checked value='$student_status'>
                                                        <input type='hidden' name='user_id' value='{$users_data['user_id']}'>
                                                        <input type='hidden' name='update-status' value='1'>
                                                        <label class='form-check-label' for='flexSwitchCheckDefault'></label>
                                                        </div>
                                                      </form>
                                                    </td>

                                                    <!--<td style='width: 250px'> -->
                                                    <td style='width: 265px'>

                                                        <form method='POST' action=''
                                                            class='d-inline-block editUser'>
                                                            <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                            <input type='hidden' name='user_id' value='{$users_data['user_id']}'> 
                                                            <input type='hidden' name='_method' value='GET'> 
                                                            <button class='btn btn-outline-success btn-sm font-small mx-1'>
                                                                <span class='fas fa-wrench'></span> Actions
                                                            </button>
                                                        </form>

                                                        <form method='POST' action=''
                                                            class='d-inline-block notificationForm'>
                                                            <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                            <input type='hidden' name='user_id' value='{$users_data['user_id']}'> 
                                                            <input type='hidden' name='_method' value='GET'> 
                                                            <button class='btn btn-outline-primary font-small mx-1'>
                                                                <span class='far fa-bell'></span>
                                                            </button>
                                                        </form>

                                                            <button class='btn btn-outline-primary btn-sm font-small mx-1' data-bs-toggle='modal' data-bs-target='#addBalance{$users_data['user_id']}'>
                                                                <span class='fas fa-dollar-sign'></span> Add Balance
                                                            </button>
                                                            <!-- Modal -->
                                                            <div class='modal fade' id='addBalance{$users_data['user_id']}'  aria-labelledby='exampleModalLabel' aria-hidden='true'>
                                                              <div class='modal-dialog modal-lg'>
                                                                <div class='modal-content'>
                                                                  <div class='modal-header'>
                                                                    <h5 class='modal-title' id='exampleModalLabel'>Add Balance</h5>
                                                                    <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                                                                  </div>
                                                                  <div class='modal-body'>
                                                                    <form action='' method='POST' class='addBalance' enctype='multipart/form-data'>
                                                                        <fieldset class='form-fieldset api-mode'>
                                                            
                                                                        <label class='ds-text-secondary' style='display: flex; justify-content: center; font-size: 18px'>Add balance for user: <strong>{$users_data['full_name']}</strong></label>
                                                                                <div class='col-12 p-3 row'>
                                                            
                                                            
                                                                                  <div class='col-12 col-lg-6 p-2'>
                                                                                        <div class='col-12'>
                                                                                        Balance
                                                                                        </div>
                                                                                        <div class='col-12 pt-3'>
                                                                                            <input type='number' name='amountToAdd' required='' min='0' class='form-control'  placeholder='Enter the balance amount' required>
                                                                                        </div>
                                                                                    </div>
                                                            
                                                                                </div>
                                                                        </fieldset>
                                                                        
                                                                        <div class='progress ds-text-inverse' style='margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px' id='progress-div'><div class='progress-bar bg-success' role='progressbar' aria-valuenow='25' aria-valuemin='0' aria-valuemax='100' id='progress-bar'></div></div>
                                                                    
                                                                          <input type='hidden' name='user_id' value='{$users_data['user_id']}' />
                                                                        <!-- </form> -->
                                                                  </div>
                                                                  <div class='modal-footer p-2'>
                                                                    <button type='button' class='btn btn-outline-danger' data-bs-dismiss='modal'>Close</button>
                                                                    <button type='submit' class='btn btn-outline-primary submitBtn'>Save</button>
                                                                  </div>
                                                                  </form>
                                                            
                                                                </div>
                                                              </div>
                                                            </div>


                                                        <form method='POST' action=''
                                                            class='d-inline-block deleteUsers'>
                                                            <input type='hidden' name='_token' value='XyH8RETZBTH4eYgzreRbeaCLbveyMAOqK8WgNiiH'> 
                                                            <input type='hidden' name='user_id' value='{$users_data['user_id']}'> 
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
    $("#addUser").on("submit", function (e) {
        
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
        url: "requests/user/add",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          $(".submitBtn").attr("disabled", "disabled");
          $("#addUser").css("opacity", ".5");
          
          $("#progress-bar").width('0%');
          $('#loader-icon').show();
        },
        success: function (response) {
          $(".response-msg").html("");
          if (response.status == 1) {
            $("#addUser")[0].reset();
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
          $("#addUser").css("opacity", "");
          $(".submitBtn").removeAttr("disabled");
        },
      });
    });
    //End script Of Add New Teacher


   // Edit a Teacher
   $(".editUser").on("submit", function (e) {
      e.preventDefault();
      $.ajax({
        type: "POST",
        url: "requests/user/edit",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          $(".submitBtn").attr("disabled", "disabled");
          $("#editUser").css("opacity", ".5");
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
            $("#updateUser").on("submit", function (e) {
              e.preventDefault();
              $.ajax({
                type: "POST",
                url: "requests/user/edit",
                data: new FormData(this),
                dataType: "json",
                contentType: false,
                cache: false,
                processData: false,
                beforeSend: function () {
                  $(".submitBtn").attr("disabled", "disabled");
                  $("#updateUser").css("opacity", ".5");
                },
                success: function (response) {
                  $(".response-msg").html("");
                  if (response.status == 1) {
                    $("#updateUser")[0].reset();
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
                  $("#updateUser").css("opacity", "");
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
          $("#editUser").css("opacity", "");
          $(".submitBtn").removeAttr("disabled");
        },
      });
    });
    //End script Of Edit Teacher






    // start of delete teacher
    $(".deleteUsers").on("submit", function (e) {
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
        url: "requests/user/delete",
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
$(".update-user-status").change(function () {
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
        url: "requests/user/status",
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




//add balance
$(".addBalance").on("submit", function (e) {
        
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
          url: "requests/user/addBalance",
          data: new FormData(this),
          dataType: "json",
          contentType: false,
          cache: false,
          processData: false,
          beforeSend: function () {
            $(".submitBtn").attr("disabled", "disabled");
            $("#addUser").css("opacity", ".5");
            
            $("#progress-bar").width('0%');
            $('#loader-icon').show();
          },
          success: function (response) {
            $(".response-msg").html("");
            if (response.status == 1) {
              $("#addUser")[0].reset();
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
            $("#addUser").css("opacity", "");
            $(".submitBtn").removeAttr("disabled");
          },
        });
      });
      //End add balance




   // Edit a Teacher
   $(".notificationForm").on("submit", function (e) {
      e.preventDefault();
      $.ajax({
        type: "POST",
        url: "requests/user/notification",
        data: new FormData(this),
        dataType: "json",
        contentType: false,
        cache: false,
        processData: false,
        beforeSend: function () {
          $(".submitBtn").attr("disabled", "disabled");
          $(".notificationForm").css("opacity", ".5");
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
            $("#sendNotification").on("submit", function (e) {
              e.preventDefault();
              $.ajax({
                type: "POST",
                url: "requests/user/notification",
                data: new FormData(this),
                dataType: "json",
                contentType: false,
                cache: false,
                processData: false,
                beforeSend: function () {
                  $(".submitBtn").attr("disabled", "disabled");
                  $("#sendNotification").css("opacity", ".5");
                },
                success: function (response) {
                  $(".response-msg").html("");
                  if (response.status == 1) {
                    $("#sendNotification")[0].reset();
                    $(".response-msg").html(
                      Swal.fire({
                        icon: "success",
                        title: response.message,
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true,
                      }).then(function (isConfirm) {
                        if (isConfirm) {
                        //   location.reload();
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
                  $("#sendNotification").css("opacity", "");
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
          $(".notificationForm").css("opacity", "");
          $(".submitBtn").removeAttr("disabled");
        },
      });
    });
    //End script Of Edit Teacher





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