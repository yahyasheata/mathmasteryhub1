<?php 
require_once 'connection/config.php';
require_once '__init.php';
require_once 'inc/functions.php';

if($_SERVER['REQUEST_METHOD'] == "POST" ){
    if(isset($_POST['_method']) && $_POST['_method'] == 'GET' ){

        if ( isset($_POST['category_id']) && !empty($_POST['category_id']) ) {
        
            $category_id = $_POST['category_id'];

    
            $query = "SELECT * FROM categories WHERE category_id='$category_id' ";
            
            $result = mysqli_query(db(),$query);
            if($result){

                $category_data = mysqli_fetch_assoc($result);
                $category_id = $category_data["category_id"];
                $category_title = $category_data["category_title"];
                $category_link = $category_data["category_link"];
                $category_description = $category_data["category_description"];
                $category_image = $category_data["category_image"];
                
                $html_response = "
                
                <!-- Modal -->
                <div class='modal fade show' id='response-html-modal' tabindex='-1' aria-labelledby='exampleModalLabel' aria-hidden='true'>
                  <div class='modal-dialog modal-lg'>
                    <div class='modal-content'>
                      <div class='modal-header'>
                        <h5 class='modal-title' id='exampleModalLabel'>Add New Category</h5>
                        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                      </div>
                      <div class='modal-body'>
                        <form action='requests/add-category' method='POST' id='updateCategory' enctype='multipart/form-data'>
                            <fieldset class='form-fieldset api-mode'>
                                    <label
                                    class='ds-text-secondary' style='display: flex; justify-content: center; font-size: 18px'>Details
                                    Category</label>
                
                                    <div class='col-12 p-3 row'>
                
                                    <div class='col-12 col-lg-6 p-2'>
                                            <div class='col-12'>
                                            Title
                                            </div>
                                            <div class='col-12 pt-3'>
                                                <input type='text' name='category_title' required='' maxlength='190' class='form-control'  placeholder='Enter the category title' value='$category_title' >
                                            </div>
                                        </div>
                
                
                                        <div class='col-12 col-lg-6 p-2'>
                                            <div class='col-12'>
                                            Link
                                            </div>
                                            <div class='col-12 pt-3'>
                                                <input type='text' name='category_link' required='' maxlength='190' class='form-control'  placeholder='اكتب رابط Category' value='$category_link' >
                                            </div>
                                        </div>
                
                
                                        <div class='col-12 col-lg-6 p-2'>
                                            <div class='col-12'>
                                            Description
                                            </div>
                                            <div class='col-12 pt-3'>
                                                <textarea class='form-control' name='category_description' rows='3' placeholder='Enter the category description here - SEO' required>$category_description</textarea>
                                            </div>
                                        </div>
                
                                        <div class='col-12 col-lg-6 p-2'>
                                          <div class='col-12'>
                                            Category Image <small class='text-primary'>{Optional}</small>
                                          </div>
                                          <div class='col-12 pt-3'>
                                            <input type='file' name='category_image' class='form-control' accept='image/*'>
                                          </div>
                                          <div class='col-12 pt-3'>
                                            <img src='$baseUrl/$category_image' style='width: 100px'>
                                          </div>
                                        </div>


                
                                    </div>
                            </fieldset>
                            <div class='progress ds-text-inverse' style='margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px' id='updateProgress-div'><div class='updateProgress-bar bg-success' role='updateProgressbar' aria-valuenow='25' aria-valuemin='0' aria-valuemax='100' id='updateProgress-bar'></div></div>

                          <input type='hidden' name='category_id' value='$category_id' />
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
                    'reason' => 'There was a database connection error. Please try again.'
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


    if ( isset($_POST['category_title'],$_POST['category_link'],$_POST['category_description']) && !empty($_POST['category_title']) && !empty($_POST['category_link']) ) {
        $category_title = $_POST['category_title'];
        $category_link = $_POST['category_link'];
        $category_description = $_POST['category_description'];
        $category_id = $_POST['category_id'];

        $old_category_image = mysqli_fetch_assoc(mysqli_query(db(),"SELECT category_image FROM categories"))['category_image'];



        if(isset($_FILES['category_image']) && !$_FILES['category_image']['error'] ){
            $category_image = $_FILES['category_image'];
            $uploadImgResponse = json_decode(uploadImage($category_image,'uploads/static/courses/categories'));
            // echo $uploadImgResponse->status;
            if($uploadImgResponse->status === 1){
                removeFile($old_category_image);

                $category_image = $uploadImgResponse->file_path;

                $query = "UPDATE categories SET
                category_title = '$category_title',
                category_link = '$category_link',
                category_description = '$category_description',
                category_image = '$category_image'
                WHERE category_id = '$category_id';";


                $result = mysqli_query(db(),$query);
                if($result){
                    $response = array(
                        'status' => 1,
                        'message' => 'تم Edit Category بنجاح'
                    );
                    $response = json_encode($response);
                    echo $response;
                }else{
                    $response = array(
                        'status' => 0,
                        'message' => 'Error',
                        'reason' => 'There was a database connection error. Please try again.'
                    );
                    $response = json_encode($response);
                    echo $response;
                }
            }else{
                echo '{"status":0,"message":"Error !","reason":"There was an error uploading the image"}';
            }

        }else{
            $category_image = 'uploads/static/courses/categories/default.jpg';
            
            $query = "UPDATE categories SET
            category_title = '$category_title',
            category_link = '$category_link',
            category_description = '$category_description',
            category_image = '$category_image'
            WHERE category_id = '$category_id';";
            
            $result = mysqli_query(db(),$query);
            if($result){
                $response = array(
                    'status' => 1,
                    'message' => 'تم Edit Category بنجاح'
                );
                $response = json_encode($response);
                echo $response;
            }else{
                $response = array(
                    'status' => 0,
                    'message' => 'Error',
                    'reason' => 'There was a database connection error. Please try again.'
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