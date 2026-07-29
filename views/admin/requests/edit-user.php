<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){
    if(isset($_POST['_method']) && $_POST['_method'] == 'GET' ){

        if ( isset($_POST['user_id']) && !empty($_POST['user_id']) ) {
            $conn = db();
            $user_id = $_POST['user_id'];

    
            // $query = "SELECT * FROM courses WHERE course_id='$course_id' ";
            $query = "SELECT * FROM users WHERE user_id='$user_id' ";
            
            $result = mysqli_query($conn,$query);
            if($result){

                $user_data = mysqli_fetch_assoc($result);
                $user_id = $user_data["user_id"];
                $name = $user_data["full_name"];
                $username = $user_data["username"];
                $password = $user_data["password"];
                $user_governorate = $user_data["governorate"];
                $user_gender = $user_data["gender"];
                

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

                        if ($governorate['id'] == $user_governorate ) {
                            $option_select =  "selected";
                            $governorates_options .= "<option value='{$governorate['id']}' $option_select>{$governorate['governorate_name_ar']}</option>";
    
                        }else{
                            $governorates_options .= "<option value='{$governorate['id']}' >{$governorate['governorate_name_ar']}</option>";
    
                        }

                    }

                    // $gendersArray = [
                    //     'male' => 'ذكر',
                    //     'female' => 'انثي'
                    // ];
                    // $gender_options = '';
                    // foreach ($gendersArray as $gender ) {

                    //     if ($gender['male'] == $user_gender ) {
                    //         $option_select =  "selected";
                    //         $gender_options .= "<option value='{$gender['male']}' $option_select>{$gender[1]}</option>";
    
                    //     }else{
                    //         $gender_options .= "<option value='{$gender[0]}' >{$gender[1]}</option>";
    
                    //     }

                    // }

                  

                $html_response = "
                
                <!-- Modal -->
                <div class='modal fade show' id='response-html-modal' tabindex='-1' aria-labelledby='exampleModalLabel' aria-hidden='true'>
                  <div class='modal-dialog modal-lg'>
                    <div class='modal-content'>
                      <div class='modal-header'>
                        <h5 class='modal-title' id='exampleModalLabel'>Edit Course</h5>
                        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                      </div>
                      <div class='modal-body'>
                            <form action='' method='POST' id='updateUser' enctype='multipart/form-data'>
                                <fieldset class='form-fieldset api-mode'>
                                    <label
                                    class='ds-text-secondary' style='display: flex; justify-content: center; font-size: 18px'>Details
                                    الطالب</label>
                
                                    <div class='col-12 p-3 row'>
                
                                    
                
                
                                        <div class='col-12 col-lg-6 p-2'>
                                            <div class='col-12'>
                                            Full Name
                                            </div>
                                            <div class='col-12 pt-3'>
                                                <input type='text' name='name' required='' maxlength='' minlength='10' class='form-control'  placeholder='Student Name ثلاثي' value='$name' required>
                                            </div>
                                        </div>
                
                
                                        <div class='col-12 col-lg-6 p-2'>
                                            <div class='col-12'>
                                            Student mobile number
                                            </div>
                                            <div class='col-12 pt-3'>
                                                <input type='number' name='phone_number' required='' maxlength='11' minlength='11' class='form-control'  placeholder='Student mobile number' value='$username' required>
                                            </div>
                                        </div>
                
                
                
                                        <div class='col-12 col-lg-6 p-2'>
                                                <div class='col-12'>
                                                Type
                                                </div>
                                                <div class='col-12 pt-3'>
                                                <select class='form-control select2' id='' name='gender'  data-placeholder='أختار Type' style='width: 100%'  required=''>
                                                    <option disabled='' selected='' hidden=''>اختار Type</option>
                                                    <option value='male'>ذكر</option>
                                                    <option value='female'>انثي</option>
                                                </select>
                                                </div>
                                        </div>
                
                
                                        <div class='col-12 col-lg-6 p-2'>
                                                <div class='col-12'>
                                                Governorate
                                                </div>
                                                <div class='col-12 pt-3'>
                                                <select class='form-control select2' id='' name='governorate'  data-placeholder='أختار Type' style='width: 100%'  required=''>
                                                    <option disabled='' selected='' hidden=''>اختار Governorate</option>
                                                    $governorates_options
                                                </select>
                                                </div>
                                        </div>
                
                                        <div class='col-12 col-lg-6 p-2'>
                                            <div class='col-12'>
                                            Password
                                            </div>
                                            <div class='col-12 pt-3'>
                                                <input type='password' name='password' required='' minlength='6' class='form-control'  placeholder='Student password' value='$password' required>
                                            </div>
                                        </div>
                
                
                                    </div>
                            </fieldset>
                            
                            <div class='progress ds-text-inverse' style='margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px' id='progress-div'><div class='progress-bar bg-success' role='progressbar' aria-valuenow='25' aria-valuemin='0' aria-valuemax='100' id='progress-bar'></div></div>

                            <!-- </form> -->
                                                    
                            <input type='hidden' name='user_id' value='$user_id' />
                            <input type='hidden' name='_method' value='UPDATE' />

                            <div class='modal-footer p-2'>
                            <button type='button' class='btn btn-outline-danger' data-bs-dismiss='modal'>Close</button>
                            <button type='submit' class='btn btn-outline-primary submitBtn'>Save</button>
                            </div>
                            </form>
                                  
                      </div>

                
                    </div>
                  </div>
                </div>
                
                ";
                
                $response = array(
                    'status' => 1,
                    'html' => $html_response
                );
                $response = json_encode($response);
                echo $response;

            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'هناك خطاً عن الاتصال بقاعدة الDetails , حاول مرة Other'
                );
                $response = json_encode($response);
                echo $response;
            }
        }
    }



}else{

}








if($_SERVER['REQUEST_METHOD'] == "POST" ){
  if(isset($_POST['_method']) && $_POST['_method'] == 'UPDATE' ){


    if( isset($_POST['name'],$_POST['phone_number'],$_POST['gender'],$_POST['governorate'],$_POST['password']) 
    && !empty($_POST['name']) && !empty($_POST['phone_number']) && !empty($_POST['gender']) && !empty($_POST['governorate']) && !empty($_POST['password']) ){

        $full_name = $_POST['name'];
        $username = $_POST['phone_number'];
        $gender = $_POST['gender'];
        $governorate = $_POST['governorate'];
        $password = $_POST['password'];
        $user_id = $_POST['user_id'];

        $conn = db();


            $query = "UPDATE users SET
            full_name = '$full_name',
            username = '$username',
            password = '$password',
            governorate = '$governorate',
            gender = '$gender'
            WHERE user_id = '$user_id';";
    
    
            $result = mysqli_query($conn,$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'تم Edit Details المستخدم بنجاح'
                );
                $response = json_encode($response);
                echo $response;
            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'هناك خطاً عن الاتصال بقاعدة الDetails , حاول مرة Other'
                );
                $response = json_encode($response);
                echo $response;
            }
    
    

        


        

    }else {
        $response = array(
            'status' => 0,
            'message' => 'Error',
            'reason' => 'All required fields must be completed'
        );
        $response = json_encode($response);
        echo $response;
    }

  }



}else{

}






?>