<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
 
$ID 			= validateHttp('ID','post');

if(!$ID){
	apiError('Missing ID', 401);
}

$ID 			= dec($ID);

$result = ncmDelete('DELETE FROM vPayments WHERE ID = ? AND companyId = ? LIMIT 1', [$ID, COMPANY_ID]);

if($result === false){
	apiError($db->ErrorMsg(), 401);
}else{
	apiOk(['success' => 'Orden de pago eliminada']);
}
?>