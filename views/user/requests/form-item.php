<?php 
require_once 'connection/config.php';
require_once 'inc/functions.php';


if($_SERVER['REQUEST_METHOD'] == "POST" ){
    if(isset($_POST['_method']) && $_POST['_method'] == 'GET' ){

        if ( isset($_POST['course_id']) && !empty($_POST['course_id']) ) {
            $conn = db();
            $course_id = $_POST['course_id'];
            $course_result = mysqli_query($conn,"SELECT * FROM courses WHERE course_id='$course_id' LIMIT 1 ");
            $course_data = mysqli_fetch_assoc($course_result);
            $course_title = $course_data['course_title'];
                $html_response = "
                
                <!-- Modal -->
                <div class='modal fade show' id='response-html-modal' tabindex='-1' aria-labelledby='exampleModalLabel' aria-hidden='true'>
                  <div class='modal-dialog modal-lg'>
                    <div class='modal-content'>
                      <div class='modal-header'>
                        <h5 class='modal-title' id='exampleModalLabel'>Add Item to $course_title</h5>
                        <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                      </div>
                      <div class='modal-body'>
                        <form action='' method='POST' class='addNewItem' id='addNewItem' enctype='multipart/form-data'>

                            <fieldset class='form-fieldset api-mode'>
                            <label
                            class='ds-text-secondary' style='display: flex; justify-content: center; font-size: 18px'>Details
                            Item</label>
        
                            <div class='col-12 p-3 row'>
        
            
                                <div class='col-12 col-lg-6 p-2'>
                                    <div class='col-12'>
                                    Title
                                    </div>
                                    <div class='col-12 pt-3'>
                                        <input type='text' name='item_title' required='' maxlength='190' class='form-control'  placeholder='Enter the item title'>
                                    </div>
                                </div>
            
                                <div class='col-12 col-lg-6 p-2'>
                                    <div class='col-12'>
                                    Type
                                    </div>
                                    <div class='col-12 pt-3'>
                                        
                                      <select class='form-control select2' id='' name='item_type' data-placeholder='Select type' style='width: 100%' required=''>
                                
                                        <option value='video'>Video</option>
                                    
                                        <option value='file'>File</option>
                                    
                                        <option value='quiz'>Exam</option>

                                      </select>

                                    </div>
                                </div>
        
                                <div class='col-12 col-lg-12 p-2'>
                                    <div class='col-12'>
                                    Content
                                    </div>
                                    <div class='col-12 pt-3'>
                                        <textarea class='form-control editor' name='item_description' rows='1' placeholder='اكتب وصف Item هنا - SEO' >$course_description</textarea>
                                    </div>
                                </div>
        
        
            
        
                            </div>
                    </fieldset>
    
                            <div class='progress ds-text-inverse' style='margin-top: 10px; text-align: center; height: 30px; font-weight: bold; font-size: 16px' id='updateProgress-div'><div class='updateProgress-bar bg-success' role='updateProgressbar' aria-valuenow='25' aria-valuemin='0' aria-valuemax='100' id='updateProgress-bar'></div></div>

                          <input type='hidden' name='course_id' value='$course_id' />
                          <input type='hidden' name='_method' value='GET' />

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
    



}else{

}







