<?php
include_once('api_head.php');

if(!validateHttp('id','post')){
	header('Content-Type: application/json; charset=utf-8;'); 
	$jsonResult['error'] 	= 1;
	$jsonResult['message'] 	= 'Transaction ID is required';
	dai(json_encodes($jsonResult));
}

$ID 			= dec(validateHttp('id','post'));
$transID		= dec(validateHttp('transID','post'));
$type 			= validateHttp('type','post');
$jsonResult 	= [];

if(!$type){
	jsonDieResult(['error' => 'Missing type'],404);
}

if($type == 'comission'){
	$exists 		= ncmExecute('SELECT comissionId FROM comission WHERE comissionId = ? AND companyId = ? LIMIT 1',[$ID,COMPANY_ID]);

	if($exists){
		$result 	= ncmExecute('DELETE FROM comission WHERE comissionId = ? AND companyId = ? LIMIT 1',[$ID,COMPANY_ID]);
	}
}else if($type == 'item'){

	//obtengo transactionId
	$exists 		= ncmExecute('SELECT itemSoldId, transactionId FROM itemSold WHERE itemSoldId = ? LIMIT 1',[$ID]);

	if(!$exists){
		jsonDieResult(['error' => 'Invalid item'],500);
	}

	//obtengo companyId
	$existsT 		= ncmExecute('SELECT transactionId, companyId FROM transaction WHERE transactionId = ? LIMIT 1',[$exists['transactionId']]);

	if($existsT['companyId'] != COMPANY_ID){
		jsonDieResult(['error' => 'Invalid company'],500);
	}

	$result 		= ncmUpdate(['records' => ['itemSoldComission' => 0], 'table' => 'itemSold', 'where' => 'itemSoldId = ' . $ID]);
	
}


if($result !== false){
	$jsonResult = ['success' => true];
}else{
	$jsonResult = ['success' => false];
}

header('Content-Type: application/json; charset=utf-8;'); 
dai(json_encodes($jsonResult));
?>