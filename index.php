<?php
// Check if login.php exists
if(file_exists('login.php')){
    header("Location: login.php");
    exit();
} else {
    echo "login.php file එක upload කරලා නෑ";
}
?>