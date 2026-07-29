<?php 
require_once 'connection/config.php';
require_once 'inc/functions.php';


if($_SERVER['REQUEST_METHOD'] == "POST" ){
    if(isset($_POST['_method']) && $_POST['_method'] == 'update' ){



        if (isset($_POST['page_id_array']) && !empty($_POST['page_id_array'])) {


            for($i=0; $i<count($_POST["page_id_array"]); $i++)
            {
            $query = "
            UPDATE course_items 
            SET page_order = '".$i."' 
            WHERE id = '".$_POST["page_id_array"][$i]."'";
            mysqli_query($conn, $query);
            }
            $response = array(
                'status' => 1,
                'message' => 'Item order updated successfully'
            );
            echo json_encode($response);

        }else{
        echo $response = '{"status":0,"message":"Error !","reason":"!!!!!!!!!!!!!!"}';
        }



    }
    



}else{

}







