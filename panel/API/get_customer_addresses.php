<?php
include_once('api_head.php');

$settings 			= ncmExecute('SELECT * FROM setting WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$modules 			= ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

if(!validateHttp('uid','post')){
	jsonDieResult(['error'=>'No se encontraron registros'],404);
}

$customerId 		= dec(validateHttp('uid','post'));
$array 				= [];
$result 			= ncmExecute("SELECT * FROM customerAddress WHERE customerId = ? AND companyId = ? LIMIT 20",[$customerId,COMPANY_ID],false,true);

if($result){
	while (!$result->EOF) {
		$cAfields = $result->fields;

        $latLng   = false;
        if($cAfields['customerAddressLat'] && $cAfields['customerAddressLng']){
          $latLng = $cAfields['customerAddressLat'] . ',' . $cAfields['customerAddressLng'];
        }

        $array[] = [
        					'ID' 		=> enc($cAfields['customerAddressId']),
        					'name' 		=> $cAfields['customerAddressName'],
        					'date' 		=> $cAfields['customerAddressDate'],
	                        'address'   => $cAfields['customerAddressText'],
	                        'lat'    	=> $cAfields['customerAddressLat'],
	                        'lng'    	=> $cAfields['customerAddressLng'],
	                        'latLng'   	=> $latLng,
	                        'location'  => $cAfields['customerAddressLocation'],
	                        'city'      => $cAfields['customerAddressCity'],
	                        'default' 	=> $cAfields['customerAddressDefault']
                        ];

		$result->MoveNext();
	}
	$result->Close();
	
	if(validity($array,'array')){
		jsonDieResult($array,200);
	}else{
		jsonDieResult(['error'=>'No se encontraron registros'],404);
	}
}else{
	jsonDieResult(['error'=>'No se encontraron registros'],404);
}

dai();
?>