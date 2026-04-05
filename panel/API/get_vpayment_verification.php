<?php
include_once('api_head.php');
 
$code 		= validateHttp('code','post');

$result 	= ncmExecute('SELECT authCode FROM vPayments WHERE authCode = ? AND companyId = ? AND status = "APPROVED" LIMIT 1', [$code, COMPANY_ID]);

if($result){
	jsonDieResult(['success' => $result['authCode']]);
}else{
	jsonDieMsg($db->ErrorMsg(),401,'error');
}

?>