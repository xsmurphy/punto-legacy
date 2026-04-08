<?php

require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$encoded 	= validateHttp('encoded');
$array 		= getAllTags($encoded);

header('Content-Type: application/json');
http_response_code(200);
echo json_encode($array);

dai();
?>