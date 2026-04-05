<?php
include_once('api_head.php');

$customerId 	= dec($_POST['id']);
$phone 			= $_POST['phone'];
$phoneSearch 	= $_POST['phoneSearch'];
$result 		= false;

if(validateHttp('phoneSearch','post')){
	if(!validateHttp('phone','post')){
		jsonDieResult(['error'=>'No se encontraron registros'],404);
	}

	$settings = ncmExecute('SELECT * FROM setting WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

	$phone 	= json_decode(getFileContent('https://public.encom.app/phonevalidator.php?phone=' . $phone . '&country=' . $settings['settingCountry']),true);

	$sqlId = 'AND contactPhone = ?';

	$result 			= ncmExecute('SELECT *
							FROM contact
							WHERE type = 1
							AND companyId = ? ' . $sqlId . ' LIMIT 1',[COMPANY_ID,$phone]);

}else{
	if(!validateHttp('uid','post') && !validateHttp('id','post')){
		jsonDieResult(['error'=>'No se encontraron registros'],404);
	}

	$sqlId = 'AND contactId = ?';

	if(validateHttp('uid','post')){
		$customerId 	= dec($_POST['uid']);
		$sqlId 			= 'AND contactUID = ?';	
	}

	$result 			= ncmExecute('SELECT *
							FROM contact
							WHERE type = 1
							AND companyId = ? ' . $sqlId . ' LIMIT 1',[COMPANY_ID,$customerId]);

}

if($result){
		$array 						= [];
		$id 						= enc($result['contactId']);

		$array["id"] 				= $id;
		$array["UID"] 				= enc($result['contactUID']);
		$array["name"] 				= toUTF8($result['contactName']);
		$array["date"] 				= $result['contactDate'];
		$array["fullname"] 			= toUTF8($result['contactSecondName']);
		$array["tin"] 				= $result['contactTIN'];
		$array["ci"] 				= $result['contactCI'];
		$array["date"] 				= $result['contactBirthDay'];
		$array["phone"] 			= $result['contactPhone'];
		$array["address"] 			= $result['contactAddress'];
		$array["address2"] 			= $result['contactAddress2'];
		$array["email"] 			= $result['contactEmail'];
		$array["note"] 				= $result['contactNote'];
		$array["status"] 			= $result['contactStatus'];
		$array["city"] 				= $result['contactCity'];
		$array["location"] 			= $result['contactLocation'];
		$array["loyalty"] 			= $result['contactLoyaltyAmount'];
		$array["storeCredit"]		= $result['contactStoreCredit'];

		jsonDieResult($array,200);
}else{
	jsonDieResult(['error'=>'No se encontraron registros'],404);
}

dai();
?>