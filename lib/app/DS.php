<?php

namespace App;

class DS
{
    //debug
    function v($data){
        echo '<script>console.log('.json_encode($data).')</script>';
    }

}