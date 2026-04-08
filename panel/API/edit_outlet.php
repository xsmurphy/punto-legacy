<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$outletID			= validateHttp('ID','post');
$data				= validateHttp('data','post');
$outletArray 		= [];
$legacyArray 		= [];

if($outletID){
	$outletID 		= dec($outletID);
	$_outlet 		= ncmExecute('SELECT * FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 50',[$outletID, COMPANY_ID]);

	if($_outlet){
		$outletArray 						= json_decode($_outlet['data'], true);

		if($data['name']){
			$outletArray['name'] 			= $data['name'];
			$legacyArray['outletName'] 		= $outletArray['name'];
		}

		if($data['address']){
			$outletArray['address'] 		= $data['address'];
			$legacyArray['outletAddress'] 	= $outletArray['address'];
		}

		if($data['phone']){
			$outletArray['phone'] 			= $data['phone'];
			$legacyArray['outletPhone'] 	= $outletArray['phone'];
		}

		if($data['whatsapp']){
			$outletArray['whatsapp'] 		= $data['whatsapp'];
			$legacyArray['outletWhatsApp'] 	= $outletArray['whatsapp'];
		}

		if($data['email']){
			$outletArray['email'] 			= $data['email'];
			$legacyArray['outletEmail'] 	= $outletArray['email'];
		}

		if($data['billingName']){
			$outletArray['billingName'] 		= $data['billingName'];
			$legacyArray['outletBillingName'] 	= $outletArray['billingName'];
		}

		if($data['businessHours']){
			$outletArray['businessHours'] 		= $data['businessHours'];
			$legacyArray['outletBusinessHours'] = $outletArray['businessHours'];
		}

		if($data['tin']){
			$outletArray['tin'] 			= $data['tin'];
			$legacyArray['outletRUC'] 		= $outletArray['tin'];
		}

		if($data['status']){
			$outletArray['status'] 			= $data['status'];
			$legacyArray['outletStatus'] 	= $outletArray['status'];
		}

		if($data['created']){
			$outletArray['status'] 				= $data['created'];
			$legacyArray['outletCreationDate'] 	= $outletArray['created'];
		}

		if($data['description']){
			$outletArray['description'] 		= $data['description'];
			$legacyArray['outletDescription'] 	= $outletArray['description'];
		}

		if($data['coordinates']){
			$outletArray['coordinates'] 		= $data['coordinates'];
			$legacyArray['outletLatLng'] 		= $outletArray['coordinates'];
		}

		if($data['online']){
			$outletArray['online'] 				= $data['online'];
			$legacyArray['outletEcom'] 			= $outletArray['online'];
		}

		if($data['taxID']){
			$outletArray['taxID'] 				= $data['taxID'];
			$legacyArray['taxId'] 				= $outletArray['taxID'];
		}

		if($data['purchaseOrderNo']){
			$outletArray['purchaseOrderNo']			= $data['purchaseOrderNo'];
			$legacyArray['outletPurchaseOrderNo']	= $outletArray['purchaseOrderNo'];
		}

		if(validity($data,'array')){
			$legacyArray['data'] 				= json_encode($outletArray);
		}
		
		$update = ncmUpdate([
								'records' 	=> $legacyArray, 
								'table' 	=> 'outlet', 
								'where' 	=> 'outletId = ' . $outletID 
							]);

		if($update !== false){
			apiOk(['error' => false, 'ID' => enc($outletID )]);
		}else{
			apiOk(['error' => 'Could not update'], 404);
		}

	}else{
		apiOk(['error' => 'Not found'], 404);
	}

}else{
	apiOk(['error' => 'No ID provided'], 404);
}


?>