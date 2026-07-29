<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';
require_once 'inc/AcademicMetadata.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){
    if(isset($_POST['_method']) && $_POST['_method'] == 'GET' ){

        if ( isset($_POST['course_id']) && !empty($_POST['course_id']) ) {
            $conn = db();
            mmh_ensure_learning_schema($conn);
            $course_id = $_POST['course_id'];

    
            // $query = "SELECT * FROM courses WHERE course_id='$course_id' ";
            $query = "SELECT * FROM courses WHERE course_id='$course_id' ";
            
            $result = mysqli_query($conn,$query);
            if($result){

                $course_data = mysqli_fetch_assoc($result);
                $course_id = $course_data["course_id"];
                $course_title = $course_data["course_title"];
                // $course_link = $course_data["course_link"];
                $course_description = $course_data["course_description"];
                $course_title_en = $course_data["course_title_en"];
                $course_image = $course_data["course_image"];
                $course_price = $course_data["course_price"];
                $preDiscount_course_price = $course_data["preDiscount_course_price"];
                $c_course_category = $course_data["course_category"];
                $whatsapp_group = $course_data["whatsapp_group"];
                $sequential_learning = isset($course_data["sequential_learning"]) ? (int) $course_data["sequential_learning"] : 0;
                $sequential_off_selected = $sequential_learning === 1 ? '' : 'selected';
                $sequential_on_selected = $sequential_learning === 1 ? 'selected' : '';
                $default_homework_score_mode = mmh_academic_score_mode($course_data['default_homework_score_mode'] ?? 'disabled');
                $score_disabled_selected = $default_homework_score_mode === 'disabled' ? 'selected' : '';
                $score_auto_selected = $default_homework_score_mode === 'accept_automatically' ? 'selected' : '';
                $score_verify_selected = $default_homework_score_mode === 'require_teacher_verification' ? 'selected' : '';
                
                $categories_result = mysqli_query($conn,"SELECT id,category_id,category_title from categories");
                $categorie_options = "";
                while( $categories_data = mysqli_fetch_assoc($categories_result)){
                    if ($categories_data['id'] == $c_course_category ) {
                        $option_select =  "selected";
                        $categorie_options .= "<option value='{$categories_data['id']}' $option_select>{$categories_data['category_title']}</option>";

                    }else{
                        $categorie_options .= "<option value='{$categories_data['id']}' >{$categories_data['category_title']}</option>";

                    }
                }

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
                        <form action='requests/add-course' method='POST' id='updateCourse' enctype='multipart/form-data'>

                            <fieldset class='form-fieldset api-mode'>
                            <label
                            class='ds-text-secondary' style='display: flex; justify-content: center; font-size: 18px'>Details
                            Course</label>
        
                            <div class='col-12 p-3 row'>
        
                            
        
                            <div class='col-12 col-lg-12 p-2'>
                                <div class='col-12'>
                                    Category
                                </div>
                                <div class='col-12 pt-3'>
                                <select class='form-control select2' id='' name='course_category'  data-placeholder='Select category' style='width: 100%'  required>
                                    $categorie_options
                                </select>
                                </div>
                            </div>
        
        
                            <div class='col-12 col-lg-6 p-2'>
                                    <div class='col-12'>
                                    Title
                                    </div>
                                    <div class='col-12 pt-3'>
                                        <input type='text' name='course_title' required='' maxlength='190' class='form-control' value='$course_title'  placeholder='اكتب عنوان Course'>
                                    </div>
                                </div>
        
        
                                <div class='col-12 col-lg-6 p-2'>
                                    <div class='col-12'>
                                    English Title
                                    </div>
                                    <div class='col-12 pt-3'>
                                        <input type='text' name='course_title_en' required='' maxlength='190' class='form-control' value='$course_title_en' placeholder='اكتب عنوان Course بالأنجلش'>
                                    </div>
                                </div>

                                <div class='col-12 col-lg-6 p-2'>
                                    <div class='col-12'>Default Homework Score Mode</div>
                                    <div class='col-12 pt-3'>
                                        <select name='default_homework_score_mode' class='form-control'>
                                            <option value='disabled' $score_disabled_selected>Disabled</option>
                                            <option value='accept_automatically' $score_auto_selected>Accept Automatically</option>
                                            <option value='require_teacher_verification' $score_verify_selected>Require Teacher Verification</option>
                                        </select>
                                    </div>
                                </div>
        
        
                                <div class='col-12 col-lg-6 p-2'>
                                    <div class='col-12'>
                                    Price الفعلي
                                    </div>
                                    <div class='col-12 pt-3'>
                                        <input type='number' name='course_price' required='' min='0' maxlength='190' class='form-control' value='$course_price' placeholder='سعر Course باللغة الانجليزية'>
                                </div>
                                </div>        
        
                                <div class='col-12 col-lg-6 p-2'>
                                    <div class='col-12'>
                                    Price قبل التخفيض
                                    </div>
                                    <div class='col-12 pt-3'>
                                        <input type='number' name='preDiscount_course_price' required='' min='0' maxlength='190' class='form-control' value='$preDiscount_course_price' placeholder='سعر Course باللغة الانجليزية'>
                                </div>
                                </div>
        
        
                                <div class='col-12 col-lg-12 p-2'>
                                    <div class='col-12'>
                                    Description
                                    </div>
                                    <div class='col-12 pt-3'>
                                        <textarea class='form-control' name='course_description' rows='2' placeholder='اكتب وصف Course هنا - SEO' required>$course_description</textarea>
                                    </div>
                                </div>
        
        

                                <div class='col-12 col-lg-12 p-2'>
                                    <div class='col-12'>
                                    WhatsApp Group Link
                                    </div>
                                    <div class='col-12 pt-3'>
                                        <input type='url' name='whatsapp_group' class='form-control'  placeholder='WhatsApp Group Link' value='$whatsapp_group'>
                                    </div>
                                </div>

                                <div class='col-12 col-lg-6 p-2'>
                                    <div class='col-12'>
                                    Sequential Learning
                                    </div>
                                    <div class='col-12 pt-3'>
                                        <select name='sequential_learning' class='form-control'>
                                            <option value='0' $sequential_off_selected>OFF — all sections available</option>
                                            <option value='1' $sequential_on_selected>ON — apply section learning rules</option>
                                        </select>
                                    </div>
                                </div>
        
                                <div class='col-12 col-lg-12 p-2'>
                                <div class='col-12'>
                                    صورة Course <small class='text-primary'>{Optional}</small>
                                </div>
                                <div class='col-12 pt-3'>
                                    <input type='file' name='course_image' class='form-control' accept='image/*'>
                                </div>
                                <div class='col-12 pt-3'>
                                    <img src='$baseUrl/$course_image' style='width: 100px'>
                                </div>
                                </div>
        
        
                            </div>
                    </fieldset>
    
                            <div class='progress ds-text-inverse' style='margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px' id='updateProgress-div'><div class='updateProgress-bar bg-success' role='updateProgressbar' aria-valuenow='25' aria-valuemin='0' aria-valuemax='100' id='updateProgress-bar'></div></div>

                          <input type='hidden' name='course_id' value='$course_id' />
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

    mmh_ensure_learning_schema(db());


    if ( isset($_POST['course_id'],$_POST['course_category'],$_POST['course_title'],$_POST['course_title_en'],$_POST['course_price'],$_POST['course_description']) 
    && !empty($_POST['course_title']) && !empty($_POST['course_title_en']) && !empty($_POST['course_description']) ) {

        $course_category = $_POST['course_category'];
        $course_title = $_POST['course_title'];
        $course_title_en = $_POST['course_title_en'];
        $course_price = $_POST['course_price'];
        $course_description = $_POST['course_description'];
        $course_price = $_POST['course_price'];
        $preDiscount_course_price = $_POST['preDiscount_course_price'];
        $course_id = $_POST['course_id'];
        $whatsapp_group = null; 
        if(isset($_POST['whatsapp_group']) && !empty($_POST['whatsapp_group']) ){
            $whatsapp_group = $_POST['whatsapp_group']; 
        }else{
            $whatsapp_group = null; 
            
        }

        $sequential_learning = (isset($_POST['sequential_learning']) && (string)$_POST['sequential_learning'] === '1') ? 1 : 0;
        $default_homework_score_mode = mmh_academic_score_mode($_POST['default_homework_score_mode'] ?? 'disabled');

        $old_course_image = mysqli_fetch_assoc(mysqli_query(db(),"SELECT course_image FROM courses WHERE course_id='$course_id' "))['course_image'];



        if(isset($_FILES['course_image']) && !$_FILES['course_image']['error'] ){
            $course_image = $_FILES['course_image'];
            $uploadImgResponse = json_decode(uploadImage($course_image,'uploads/static/courses'));
            // echo $uploadImgResponse->status;
            if($uploadImgResponse->status === 1){
                removeFile($old_course_image);

                $course_image = $uploadImgResponse->file_path;

                $query = "UPDATE courses SET
                course_title = '$course_title',
                course_title_en = '$course_title_en',
                course_description = '$course_description',
                course_image = '$course_image',
                course_price = '$course_price',
                preDiscount_course_price = '$preDiscount_course_price',
                course_category = '$course_category',
                whatsapp_group = '$whatsapp_group',
                sequential_learning = '$sequential_learning',
                default_homework_score_mode = '$default_homework_score_mode'
                WHERE course_id = '$course_id';";


                $result = mysqli_query(db(),$query);
                if($result){
                    $response = array(
                        'status' => 1,
                        'message' => 'تم Edit Course Details بنجاح'
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
            }else{
                echo '{"status":0,"message":"Error !","reason":"There was an error uploading the image"}';
            }

        }else{
            // $course_image = 'uploads/static/courses/categories/default.jpg';
            
            $query = "UPDATE courses SET
            course_title = '$course_title',
            course_title_en = '$course_title_en',
            course_description = '$course_description',
            course_price = '$course_price',
            preDiscount_course_price = '$preDiscount_course_price',
            course_category = '$course_category',
            whatsapp_group = '$whatsapp_group',
            sequential_learning = '$sequential_learning',
            default_homework_score_mode = '$default_homework_score_mode'
            WHERE course_id = '$course_id';";
            
            $result = mysqli_query(db(),$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'تم Edit Course Details بنجاح'
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
