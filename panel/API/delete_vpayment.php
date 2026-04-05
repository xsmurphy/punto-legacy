<?php
include_once('api_head.php');
 
$ID 			= validateHttp('ID','post');

if(!$ID){
	jsonDieMsg('Missing ID',401,'error');
}

$ID 			= dec($ID);

$result = ncmDelete('DELETE FROM vPayments WHERE ID = ? AND companyId = ? LIMIT 1', [$ID, COMPANY_ID]);

if($result === false){
	jsonDieMsg($db->ErrorMsg(),401,'error');
}else{
	jsonDieResult(['success' => 'Orden de pago eliminada']);
}
?>