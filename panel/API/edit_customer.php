<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$modules 		= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

$record 	= [];
$maxLoop 	= 520;
$i 			= 0;
$success 	= 0;
$fail 		= 0;
$failArray 	= [];
$eAddress 	= [];
$addressUpdt = [];
$value 		= validateHttp('data','post');

//echo 'type = ' . gettype ( $_POST['data'] );
//echo $value;
//die();

if(isJson($value)){
	$value = json_decode($value,true);
}

if(validity($value,'array')){

	if($value['contactId']){
		$idQuery = 'contactId = ' . $db->Prepare(dec($value['contactId']));
		$idIt 	= $value['contactId'];
	}else if($value['uid']){
		$idQuery = 'contactId = ' . $db->Prepare(dec($value['uid']));
		$idIt 	= $value['uid'];
	}else if($value['ci']){
		$idQuery = 'contactCI = ' . $db->Prepare($value['ci']);
		$idIt 	= $value['ci'];
	}

	$thisCustomer = ncmExecute('SELECT contactId FROM contact WHERE ' . $idQuery . ' AND companyId = ?',[COMPANY_ID]);

	if(!$thisCustomer){
		apiOk(['error' => 'Not found'], 404);
	}

	$idQuery = 'contactId = ' . $thisCustomer['contactId'];

	if($value['tin']){
		$record['contactTIN'] 		= $value['tin'];
	}

	if($value['ci']){
		$record['contactTIN'] 		= $value['ci'];
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
		$record['contactPhone'] 		= $value['phone'];
	}

	if($value['phone2']){
		$record['contactPhone2'] 		= $value['phone2'];
	}

	if($value['email']){
		$record['contactEmail'] 		= $value['email'];
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

	$record['updated_at']      			= TODAY;

	$update = $db->AutoExecute('contact', $record, 'UPDATE', $idQuery . ' AND companyId = ' . $db->Prepare(COMPANY_ID) );
	if($update === false){
		apiOk(['error' => $db->ErrorMsg()], 403);
	}else{
		updateLastTimeEdit(COMPANY_ID,'customer');

		$hasAddress = ncmExecute('SELECT * FROM customerAddress WHERE customerId = ? AND companyId = ? AND customerAddressDefault = 1 LIMIT 1',[$thisCustomer['contactId'],COMPANY_ID]);
		if(!$hasAddress){
			$eAddress['customerAddressDefault'] = 1;
			$eAddress['customerId'] 			= $thisCustomer['contactId'];
			$eAddress['companyId'] 				= COMPANY_ID;

			$addressUpdt['records'] = $eAddress;
			$addressUpdt['table'] 	= 'customerAddress';

			ncmInsert($addressUpdt);
		}else{
			$addressUpdt['records'] = $eAddress;
			$addressUpdt['table'] 	= 'customerAddress';
			$addressUpdt['where'] 	= 'customerAddressDefault = 1 AND customerId = ' . $thisCustomer['contactId'] . ' AND companyId = ' . COMPANY_ID;
			ncmUpdate($addressUpdt);
		}
		
		apiOk(['success' => 'Cliente actualizado']);
	}
}else{
	apiOk(['error'=>'No se recibieron datos','failed' => validateHttp('data','post')], 404);
}

?>