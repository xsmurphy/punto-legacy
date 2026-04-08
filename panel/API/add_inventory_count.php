<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
 
$outlet 			= validateHttp('outlet','post');
$name 				= validateHttp('name','post');
$date 				= validateHttp('date','post');
$user 				= validateHttp('user','post');

if(($outlet < 2 || !$outlet) || !$user){
	apiError('Debe incluir sucursal y usuario', 401);
}

$date 				= iftn($date,TODAY);
$name 				= iftn($name,'Nuevo Conteo');

$record     					= [];
$record['inventoryCountName'] 	= $name;
$record['inventoryCountDate'] 	= $date;
$record['userId']             	= $user;
$record['outletId']           	= $outlet;
$record['companyId']          	= COMPANY_ID;

$insert                     = $db->AutoExecute('inventoryCount', $record, 'INSERT'); 
$invId                      = $db->Insert_ID();
if($insert === false){
	apiError($db->ErrorMsg(), 401);
}else{
	apiOk(['success' => 'Conteo generado','id' => enc($invId)]);
}
?>