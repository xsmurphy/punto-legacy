<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$result 		= ncmExecute('SELECT *
						FROM contact
						WHERE type = 0
						AND companyId = ' . COMPANY_ID,[],false,true);

$arrays 		= [];
if($result){
	while (!$result->EOF) {
		$fields 					= $result->fields;
		$array 						= [];
		$id 						= enc($fields['contactId']);

		$array["ID"] 				= $id;
		$array["name"] 				= toUTF8($fields['contactName']);
		$array["TIN"] 				= $fields['lockpass'];
		$array['tin']				= $fields['contactTIN'];
		$array['ci']				= $fields['contactCI'];
		$array["phone"] 			= $fields['contactPhone'];
		$array["address"] 			= $fields['contactAddress'];
		$array["email"] 			= $fields['contactEmail'];
		$array["note"] 				= $fields['contactNote'];
		$array["status"] 			= $fields['contactStatus'];
		$array["roleID"] 			= $fields['role'];
		$array["role"] 				= getRoleName($fields['role']);
		$array["outletID"]			= $fields['outletId'] ? enc($fields['outletId']) : '';
		$array["lockPass"] 			= $fields['lockPass'] ? base64_encode( $fields['lockPass'] ) : '';

		array_push($arrays, $array);

		$result->MoveNext();
	}
}

apiOk($arrays);
