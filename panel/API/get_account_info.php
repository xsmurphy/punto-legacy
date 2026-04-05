<?php

include_once('api_head.php');

$limitQ 		= 500;
$limit 			= validateHttp('limit','post');
$from 			= validateHttp('from','post');
$to 			= validateHttp('to','post');


header('Content-Type: application/json');
http_response_code(200);
echo json_encode($array);

dai();
?>