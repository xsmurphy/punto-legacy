<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$result 		= ncmExecute('SELECT *
						FROM contact
						WHERE type = 1
						AND companyId = ' . COMPANY_ID . ' ORDER BY contactId DESC LIMIT 1000',[],false,true);

$arrays 		= [];
if($result){
	while (!$result->EOF) {
		$array 						= [];
		$id 						= enc($result->fields['contactId']);

		$address 					= getDefaultCustomerAddress($id);

		$array["id"] 				= $id;
		$array["UID"] 				= enc($result->fields['contactId']);
		$array["name"] 				= toUTF8($result->fields['contactName']);
		$array["tin"] 				= $result->fields['contactTIN'];
		$array["CI"] 				= $result->fields['contactCI'];
		$array["phone"] 			= $result->fields['contactPhone'];
		$array["address"] 			= $address['address'];
		$array["email"] 			= $result->fields['contactEmail'];
		$array["note"] 				= $result->fields['contactNote'];
		$array["status"] 			= $result->fields['contactStatus'];

		array_push($arrays, $array);

		$result->MoveNext();
	}
}

apiOk($arrays);
