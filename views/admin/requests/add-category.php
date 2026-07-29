<?php 
require_once 'connection/config.php';
require_once 'inc/functions.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){

    if ( isset($_POST['category_title'],$_POST['category_link'],$_POST['category_description']) && !empty($_POST['category_title']) && !empty($_POST['category_link']) ) {
        $category_title = $_POST['category_title'];
        $category_link = $_POST['category_link'];
        $category_description = $_POST['category_description'];
        $category_id = rand(99,9999);

        $username = $_SESSION['admin'];

        if(isset($_FILES['category_image']) && !$_FILES['category_image']['error'] ){
            $category_image = $_FILES['category_image'];
            $uploadImgResponse = json_decode(uploadImage($category_image,'uploads/static/courses/categories'));
            // echo $uploadImgResponse->status;
            if($uploadImgResponse->status === 1){
                $category_image = $uploadImgResponse->file_path;
                $query = "INSERT INTO categories (category_id,category_title,category_link,category_description,category_image,username) 
                VALUES('$category_id','$category_title','$category_link','$category_description','$category_image','$username')";
                $result = mysqli_query(db(),$query);
                if($result){
                    $response = array(
                        'status' => 1,
                        'message' => 'Category added successfully'
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
            $category_image = 'uploads/static/courses/categories/default.jpg';
            $query = "INSERT INTO categories (category_id,category_title,category_link,category_description,category_image) VALUES('$category_id','$category_title','$category_link','$category_description','$category_image')";
            $result = mysqli_query(db(),$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'Category added successfully'
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