<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$customerId 	= dec(validateHttp('id','post'));
$phone 			= validateHttp('phone','post');
$phoneSearch 	= validateHttp('phoneSearch','post');
$result 		= false;

$settings 		= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$modules 		= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

if($phone){

	$gphone = getValidPhone($phone,$settings['settingCountry'],'national');
	$phone 	= $gphone['phone'];
	//$phone 	= str_replace(' ', '', $phone);
	

	/*$sql = 'SELECT *
			FROM contact
			WHERE type = 1
			AND companyId = ? 
			AND FIND_IN_SET(?, REPLACE(contactPhone, " ", "")) LIMIT 1';*/

	$sql = 'SELECT *
			FROM contact
			WHERE type = 1
			AND companyId = ? 
			AND contactPhone LIKE ? LIMIT 1';

	$result 			= ncmExecute($sql,[COMPANY_ID,$phone]);

}else{
	if(!validateHttp('uid','post') && !validateHttp('id','post')){
		apiOk(['error'=>'No se encontraron registros'], 404);
	}

	$sqlId = 'AND contactId = ?';

	if(validateHttp('uid','post')){
		$customerId 	= dec(validateHttp('uid','post'));
		$sqlId 			= 'AND contactId = ?';	
	}

	$result 			= ncmExecute('SELECT *
							FROM contact
							WHERE type = 1
							AND companyId = ? ' . $sqlId . ' LIMIT 1',[COMPANY_ID,$customerId]);
}

if($result){
	$array 						= [];
	$id 						= enc($result['contactId']);

	$address 					= getDefaultCustomerAddress($result['contactId']);

	$array["id"] 				= $id;//
	$array["UID"] 				= enc($result['contactId']);//
	$array["name"] 				= toUTF8($result['contactName']);//
	$array["date"] 				= $result['contactDate'];//
	$array["fullname"] 			= toUTF8($result['contactSecondName']);//
	$array["tin"] 				= unXss($result['contactTIN']);//
	$array["ci"] 				= $result['contactCI'];//
	$array["date"] 				= $result['contactBirthDay'];//
	$array["phone"] 			= $result['contactPhone'];//
	$array["phone2"] 			= $result['contactPhone2'];//
	$array["addressId"]			= $address['id'];
	$array["address"] 			= $address['address'];//
	$array["email"] 			= unXss($result['contactEmail']);//
	$array["note"] 				= unXss($result['contactNote']);//
	$array["status"] 			= $result['contactStatus'];//
	$array["city"] 				= $address['city'];//
	$array["location"] 			= $address['location'];//
	$array["country"] 			= unXss($result['contactCountry']);//

	$array["hasLoyalty"]		= ($modules['loyalty']) ? $result['contactLoyalty'] : 0;//
	$array["loyalty"] 			= ($array["hasLoyalty"]) ? $result['contactLoyaltyAmount'] : 0;//

	$array["storeCredit"]		= $result['contactStoreCredit'];//
	$array["lat"]				= $address['lat'];
	$array["lng"]				= $address['lng'];

	if(validateHttp('returnArray','post')){//zapier requiere que se devuelva un array
		$array = [$array];
	}
	
	if(validity($array,'array')){
		apiOk($array);
	}else{
		apiOk(['error'=>'No se encontraron registros'], 404);
	}
}else{
	apiOk(['error' => 'No se encontraron registros ' . $phone], 404);
}

dai();
?>