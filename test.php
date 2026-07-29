<?php 

function setActive($name='home'){
    $pageName = 'home';

    if (isset($pageName) && $pageName == $name) {
        echo "active";
    }

}

echo  setActive();
// echo $pageName ;

?>