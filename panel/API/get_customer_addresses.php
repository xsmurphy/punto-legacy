<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$settings 			= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$modules 			= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

if(!validateHttp('uid','post')){
	apiOk(['error'=>'No se encontraron registros'], 404);
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
		apiOk($array);
	}else{
		apiOk(['error'=>'No se encontraron registros'], 404);
	}
}else{
	apiOk(['error'=>'No se encontraron registros'], 404);
}

dai();
?>