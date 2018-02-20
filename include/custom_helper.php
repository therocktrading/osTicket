<?php 

function is_robot() {
    $string_to_check = 'bot';
    return isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/'.$string_to_check.'/i', $_SERVER['HTTP_USER_AGENT']);
}
