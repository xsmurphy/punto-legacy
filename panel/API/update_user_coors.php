<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

if(!validateHttp('id') || !validateHttp('lat') || !validateHttp('lng')){
	apiOk(['error'=>'missing data'], 400);
}

$id 			= dec(validateHttp('id'));
$lat			= validateHttp('lat');
$lng			= validateHttp('lng');
$array 			= [];

$record['contactLatLng'] = strip_tags( $lat . ',' . $lng );

$update = $db->AutoExecute('contact', $record, 'UPDATE','contactId = ' . $db->Prepare( $id ) . ' AND type = 0 AND companyId = ' . COMPANY_ID );
if($update !== false){
	apiOk(['success'=>'true']);
}else{
	apiOk(['error'=>'could not update'], 500);
}
?>