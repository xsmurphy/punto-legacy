<?php
include_once('api_head.php');
 
$outlet 			= validateHttp('outlet','post');
$name 				= validateHttp('name','post');
$date 				= validateHttp('date','post');
$user 				= validateHttp('user','post');

if(($outlet < 2 || !$outlet) || !$user){
	jsonDieMsg('Debe incluir sucursal y usuario',401,'error');
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
	jsonDieMsg($db->ErrorMsg(),401,'error');
}else{
	jsonDieResult(['success' => 'Conteo generado','id' => enc($invId)]);
}
?>