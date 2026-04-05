<?php

include_once('api_head.php');

$encoded 	= validateHttp('encoded');
$array 		= getAllTags($encoded);

header('Content-Type: application/json');
http_response_code(200);
echo json_encode($array);

dai();
?>