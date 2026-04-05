<?php
include_once('api_head.php');

if(!validateHttp('id') || !validateHttp('lat') || !validateHttp('lng')){
	jsonDieResult(['error'=>'missing data'],400);
}

$id 			= dec(validateHttp('id'));
$lat			= validateHttp('lat');
$lng			= validateHttp('lng');
$array 			= [];

$record['contactLatLng'] = strip_tags( $lat . ',' . $lng );

$update = $db->AutoExecute('contact', $record, 'UPDATE','contactId = ' . $db->Prepare( $id ) . ' AND type = 0 AND companyId = ' . COMPANY_ID );
if($update !== false){
	jsonDieResult(['success'=>'true'],200);
}else{
	jsonDieResult(['error'=>'could not update'],500);
}
?>