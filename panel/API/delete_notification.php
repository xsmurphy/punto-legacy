<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

if(!validateHttp('id','post')){
	header('Content-Type: application/json; charset=utf-8;'); 
	$jsonResult['error'] = 1;
	$jsonResult['message'] = 'Notification ID is required';
	dai(json_encodes($jsonResult));
}

$id 			= dec(validateHttp('id','post'));
$jsonResult 	= [];

$sql 			= 'DELETE FROM notify WHERE notifyId = ? AND companyId = ? LIMIT 1';
$result 		= $db->Execute($sql,[$id,COMPANY_ID]);

if($result){
	$jsonResult = ['success'=>1];
}else{
	$jsonResult = ['success'=>0];
}

header('Content-Type: application/json; charset=utf-8;'); 
dai(json_encodes($jsonResult));
?>