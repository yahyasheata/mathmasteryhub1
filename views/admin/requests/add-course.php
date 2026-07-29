<?php 
require_once 'connection/config.php';
require_once 'inc/functions.php';
require_once 'inc/AcademicMetadata.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if($_SERVER['REQUEST_METHOD'] == "POST" ){
    mmh_ensure_learning_schema(db());

    if ( isset($_POST['course_category'],$_POST['course_title'],$_POST['course_title_en'],$_POST['course_price'],$_POST['course_description']) 
    && !empty($_POST['course_title']) && !empty($_POST['course_title_en']) && !empty($_POST['course_description']) ) {

        $course_category = $_POST['course_category'];
        $course_title = $_POST['course_title'];
        $course_title_en = $_POST['course_title_en'];
        $course_price = $_POST['course_price'];
        $course_description = $_POST['course_description'];
        $course_price = $_POST['course_price'];
        $preDiscount_course_price = $_POST['preDiscount_course_price'];
        $whatsapp_group = null; 
        if(isset($_POST['whatsapp_group']) && !empty($_POST['whatsapp_group']) ){
            $whatsapp_group = $_POST['whatsapp_group']; 
        }
        $sequential_learning = (isset($_POST['sequential_learning']) && (string)$_POST['sequential_learning'] === '1') ? 1 : 0;
        $default_homework_score_mode = mmh_academic_score_mode($_POST['default_homework_score_mode'] ?? 'disabled');

        $course_id = rand(99,9999);
        $username = $_SESSION['admin'];

        if(isset($_FILES['course_image']) && !$_FILES['course_image']['error'] ){
            $course_image = $_FILES['course_image'];
            $uploadImgResponse = json_decode(uploadImage($course_image,'uploads/static/courses',null));
            // echo $uploadImgResponse->status;
            if($uploadImgResponse->status === 1){
                $course_image = $uploadImgResponse->file_path;
                $query = "INSERT INTO courses (course_id,course_title,course_title_en,course_description,course_image,course_price,preDiscount_course_price,course_category,whatsapp_group,sequential_learning,default_homework_score_mode,username) VALUES('$course_id','$course_title','$course_title_en','$course_description','$course_image','$course_price','$preDiscount_course_price','$course_category','$whatsapp_group','$sequential_learning','$default_homework_score_mode','$username')";
                $result = mysqli_query(db(),$query);
                if($result){
                    $response = array(
                        'status' => 1,
                        'message' => 'Course added successfully'
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
            $course_image = 'uploads/static/courses/default.jpg';
            $query = "INSERT INTO courses (course_id,course_title,course_title_en,course_description,course_image,course_price,preDiscount_course_price,course_category,whatsapp_group,sequential_learning,default_homework_score_mode,username) VALUES('$course_id','$course_title','$course_title_en','$course_description','$course_image','$course_price','$preDiscount_course_price','$course_category','$whatsapp_group','$sequential_learning','$default_homework_score_mode','$username')";
            $result = mysqli_query(db(),$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'Course added successfully'
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





        // $query = "INSERT INTO categories (category_id,category_title,category_link,category_description) VALUES('$category_id','$category_title','$category_link','$category_description')";
        // $result = mysqli_query(db(),$query);
        // if($result){
        //     $response = array(
        //         'status' => 1,
        //         'message' => 'Category added successfully'
        //     );
        //     $response = json_encode($response);
        //     echo $response;

        // }else{
        //     $response = array(
        //         'status' => 0,
        //         'message' => 'Error',
        //         'reason' => 'هناك خطاً عن الاتصال بقاعدة الDetails , حاول مرة Other'
        //     );
        //     $response = json_encode($response);
        //     echo $response;
        // }

    }


}else{

}

?>
