<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

if(!validateHttp('id','post')){
	header('Content-Type: application/json; charset=utf-8;'); 
	$jsonResult['error'] 	= 1;
	$jsonResult['message'] 	= 'Transaction ID is required';
	dai(json_encodes($jsonResult));
}

$id 			= dec(validateHttp('id','post'));
$jsonResult 	= [];

$parent         = dec(validateHttp('parent','post'));
$result 		= false;
//$type 			= ncmExecute('SELECT transactionType FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[$id,COMPANY_ID], false, true);
$type			= getValue('transaction', 'transactionType', 'WHERE transactionId = ' . $id . ' AND companyId = ' . COMPANY_ID);
$restock 		= in_array($type['transactionType'], [0,1,3,4]);

$exists 		= ncmExecute('SELECT transactionId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[$id,COMPANY_ID]);

if($exists){
	if($type == 5){//si no es un pago
		//cambio completed a 0 si es que borro un pago
		if(validateHttp('parent','POST')){
		  $db->AutoExecute('transaction', ['transactionComplete' => 0], 'UPDATE', 'transactionId = ' . $parent); 
		}
	}
	$result = deleteTransaction($id,$restock);
}

if($result !== false){
	$jsonResult = ['success' => true];
}else{
	$jsonResult = ['success' => false];
}

header('Content-Type: application/json; charset=utf-8;'); 
dai(json_encodes($jsonResult));
?>