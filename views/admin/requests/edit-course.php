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
            $course_id = filter_var($_POST['course_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($course_id === false) { exit(json_encode(['status' => 0, 'message' => 'Invalid course.'])); }

    
            $courseStmt = $conn->prepare('SELECT * FROM courses WHERE course_id = ? LIMIT 1');
            $courseStmt->bind_param('i', $course_id);
            $courseStmt->execute();
            $course_data = $courseStmt->get_result()->fetch_assoc();
            $courseStmt->close();
            if($course_data){
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
                
                $categories_result = $conn->query("SELECT id, category_id, category_title FROM categories");
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
                        <form action='requests/course/edit' method='POST' id='updateCourse' enctype='multipart/form-data'>

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








if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['_method'] ?? '') === 'UPDATE') {
    $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $category = filter_var($_POST['course_category'] ?? null, FILTER_VALIDATE_INT);
    $title = trim((string) ($_POST['course_title'] ?? ''));
    $titleEn = trim((string) ($_POST['course_title_en'] ?? ''));
    $description = trim((string) ($_POST['course_description'] ?? ''));
    $price = is_numeric($_POST['course_price'] ?? null) ? (float) $_POST['course_price'] : 0.0;
    $preDiscount = is_numeric($_POST['preDiscount_course_price'] ?? null) ? (float) $_POST['preDiscount_course_price'] : 0.0;
    $whatsapp = trim((string) ($_POST['whatsapp_group'] ?? ''));
    $whatsapp = $whatsapp === '' ? null : $whatsapp;
    $sequential = (string) ($_POST['sequential_learning'] ?? '') === '1' ? 1 : 0;
    $scoreMode = mmh_academic_score_mode($_POST['default_homework_score_mode'] ?? 'disabled');
    if ($courseId === false || $title === '' || $titleEn === '' || $description === '') {
        exit(json_encode(['status' => 0, 'message' => 'All required fields must be completed']));
    }

    $imagePath = null;
    if (isset($_FILES['course_image']) && (int) ($_FILES['course_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $upload = json_decode(uploadImage($_FILES['course_image'], 'uploads/static/courses'), true);
        if (!is_array($upload) || (int) ($upload['status'] ?? 0) !== 1) {
            exit(json_encode(['status' => 0, 'message' => 'There was an error uploading the image']));
        }
        $imagePath = (string) ($upload['file_path'] ?? '');
    }
    $conn = db();
    if ($imagePath !== null && $imagePath !== '') {
        $stmt = $conn->prepare('UPDATE courses SET course_title = ?, course_title_en = ?, course_description = ?, course_image = ?, course_price = ?, preDiscount_course_price = ?, course_category = ?, whatsapp_group = ?, sequential_learning = ?, default_homework_score_mode = ? WHERE course_id = ?');
        $stmt->bind_param('ssssddisisi', $title, $titleEn, $description, $imagePath, $price, $preDiscount, $category, $whatsapp, $sequential, $scoreMode, $courseId);
    } else {
        $stmt = $conn->prepare('UPDATE courses SET course_title = ?, course_title_en = ?, course_description = ?, course_price = ?, preDiscount_course_price = ?, course_category = ?, whatsapp_group = ?, sequential_learning = ?, default_homework_score_mode = ? WHERE course_id = ?');
        $stmt->bind_param('sssddisisi', $title, $titleEn, $description, $price, $preDiscount, $category, $whatsapp, $sequential, $scoreMode, $courseId);
    }
    $ok = $stmt && $stmt->execute();
    if ($stmt) { $stmt->close(); }
    echo json_encode(['status' => $ok ? 1 : 0, 'message' => $ok ? 'Course updated successfully' : 'Database connection error']);
}






?>
