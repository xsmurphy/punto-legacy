<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
 
$code 		= validateHttp('code','post');

$result 	= ncmExecute('SELECT authCode FROM vPayments WHERE authCode = ? AND companyId = ? AND status = \'APPROVED\' LIMIT 1', [$code, COMPANY_ID]);

if($result){
	apiOk(['success' => $result['authCode']]);
}else{
	apiError($db->ErrorMsg(), 401);
}

?>