<?php
include_once('api_head.php');

$record 	= [];
$maxLoop 	= 520;
$i 			= 0;
$success 	= 0;
$fail 		= 0;
$failArray 	= [];
$value 		= validateHttp('data','post') ;

if(is_string($value)){
	$value = stripslashes($value);
}

if(isJson($value)){
	$value = json_decode($value,true);
}

if(validity($value,'array')){

	if(!$value['fiscalName'] && !$value['name']){
		jsonDieResult(['error'=>'Nombre y apellido o Razón social son obligatorios'],403);
	}

	if($value['tin']){
		$record['contactTIN'] 		= strip_tags($value['tin']);
	}

	if($value['ci']){
		$record['contactCI'] 		= $value['ci'];
	}

	if($value['fiscalName']){
		$record['contactName'] 		= strip_tags($value['fiscalName']);
	}else{
		$record['contactName'] 		= strip_tags($value['name']);
	}

	if($value['name']){
		$record['contactSecondName'] = strip_tags($value['name']);
	}

	if($value['bday']){
		$record['contactBirthDay'] = $value['bday'];
	}

	if($value['note']){
		$record['contactNote'] 		= strip_tags($value['note']);
	}

	if($value['city']){
		$record['contactCity'] 		= strip_tags($value['city']);
	}

	if($value['location']){
		$record['contactLocation'] 	= strip_tags($value['location']);
	}

	if($value['country']){
		$record['contactCountry'] 	= strip_tags($value['country']);
	}

	if($value['address']){
		$record['contactAddress'] 	= strip_tags($value['address']);
	}

	if($value['city']){
		$eAddress['customerAddressCity'] = strip_tags($value['city']);
	}

	if($value['location']){
		$eAddress['customerAddressLocation'] = strip_tags($value['location']);
	}

	if($value['address']){
		$eAddress['customerAddressText'] = strip_tags($value['address']);
	}

	if($value['lat'] && $value['lng']){
		$eAddress['customerAddressLat'] = $value['lat'];
		$eAddress['customerAddressLng'] = $value['lng'];
	}	

	if($value['phone']){
		$record['contactPhone'] 	= $value['phone'];
	}

	if($value['phone2']){
		$record['contactPhone2'] 	= $value['phone2'];
	}

	if($value['email']){
		$record['contactEmail'] 	= strip_tags($value['email']);
	}

	if($value['status']){ 
		$record['contactStatus'] 		= $value['status'];
	}

	if($value['storeCredit']){
		$record['contactStoreCredit'] 	= $value['storeCredit'];
	}

	if($value['loyalty']){
		$record['contactLoyalty'] 		= $value['loyalty'];
	}

	if($value['loyaltyAmount']){
		$record['contactLoyaltyAmount'] = $value['loyaltyAmount'];
	}

	if($value['lat'] && $value['lng']){
		$record['contactLatLng'] 		= strip_tags($value['lat'] . ',' . $value['lng']);
	}

	$newUID = generateUID();

	$record['contactUID'] 		= $newUID;
	$record['contactDate'] 		= TODAY;
	$record['contactStatus'] 	= 1;
	$record['type'] 			= 1;
	$record['companyId'] 		= COMPANY_ID;
	$record['updated_at']      	= TODAY;

	$insert = $db->AutoExecute('contact', $record, 'INSERT');
	$contactId = $db->Insert_ID();
	$eAddress['customerAddressDefault'] = 1;
	$eAddress['customerId'] 			= $newUID;
	$eAddress['companyId'] 				= COMPANY_ID;

	if($insert === false){
		jsonDieResult(['error' => 'No se pudo crear el cliente'],403);
	}else{
		updateLastTimeEdit(COMPANY_ID,'customer');

		$addressUpdt['records'] = $eAddress;
		$addressUpdt['table'] 	= 'customerAddress';

		$idCreated = ncmInsert($addressUpdt);
		jsonDieResult(['success' => 'Cliente creado','UID' => enc($newUID),'id' => enc($contactId)],200);
	}
}else{
	jsonDieResult(['error'=>'No se encontraron datos','failed'=>$value],404);
}

?>