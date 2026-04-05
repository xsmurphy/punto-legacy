<?php
include_once('api_head.php');

$outlet				= validateHttp('outletId','post');
$ecom				= validateHttp('ecom','post');
$cache 				= validateHttp('cache','post') ? validateHttp('cache','post') : false;
$jsonResult 		= [];
if($outlet){

	$extraWhere = '';
	if($ecom){
		$extraWhere = 'AND outletEcom = 1';
	}

	if($outlet == 'all'){
		$result 			= ncmExecute('SELECT * FROM outlet WHERE companyId = ? ' . $extraWhere . ' LIMIT 50',[COMPANY_ID],$cache,true);
	}else{
		$result 			= ncmExecute('SELECT * FROM outlet WHERE companyId = ? AND outletId = ? ' . $extraWhere . ' LIMIT 1',[COMPANY_ID,dec($outlet)],$cache,true);
	}	

	if($result){

		while (!$result->EOF) {
			$field 			= $result->fields;
			$register 		= ncmExecute('SELECT registerId FROM register WHERE outletId = ? AND companyId = ? ORDER BY registerId ASC LIMIT 1',[$field['outletId'],COMPANY_ID],$cache);

			$jsonResult[] 	= 	[
									'ID' 			=> enc($field['outletId']),
									'name' 			=> $field['outletName'],
									'address'		=> $field['outletAddress'],
									'phone'			=> $field['outletPhone'],
									'whatsapp'		=> $field['outletWhatsApp'],
									'email'			=> $field['outletEmail'],
									'billingName'	=> $field['outletBillingName'],
									'businessHours' => json_decode( stripslashes($field['outletBusinessHours']),true),
									'tin'			=> $field['outletRUC'],
									'status'		=> $field['outletStatus'],
									'created'		=> $field['outletCreationDate'],
									'description'	=> $field['outletDescription'],
									'lat'			=> $field['outletLatLng'] ? explode(',',$field['outletLatLng'])[0] : 0,
									'lng'			=> $field['outletLatLng'] ? explode(',',$field['outletLatLng'])[1] : 0,
									'mainRegisterID'=> enc($register['registerId'])
								];

			$result->MoveNext(); 
	    }

	    $result->Close();

	    if($outlet != 'all'){
	    	$jsonResult = $jsonResult[0];
	    }

		jsonDieResult($jsonResult,200);
	}else{
		jsonDieResult(['error' => 'Not found'],404);
	}
}else{
	jsonDieResult(['error' => 'No ID provided'],404);
}


?>