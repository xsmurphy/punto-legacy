<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$type 			= validateHttp('type','post');
$type 			= ($type == 'notes') ? '1' : '0';

$register 		= $db->Prepare(validateHttp('register','post'));
$outlet 		= $db->Prepare(dec(validateHttp('outlet','post')));
$user 			= $db->Prepare(dec(validateHttp('user','post')));
$reg 			= '';
$out 			= '';

if(validateHttp('register','post')){
	$reg = ' AND notifyRegister = 1';
}

if(validateHttp('outlet','post')){
	$out = " AND (outletId = '" . $outlet . "' OR outletId IS NULL)";
}

if(!validateHttp('user','post')){
	header('Content-Type: application/json; charset=utf-8;'); 
	$jsonResult['error'] = 1;
	$jsonResult['message'] = 'User ID is required';
	dai(json_encodes($jsonResult));
}

$lastS 		= ncmExecute('SELECT contactLastNotificationSeen FROM contact WHERE contactId = ?',[$user]);
$lastSeen 	= iftn($lastS['contactLastNotificationSeen'],'2019-01-01 00:00:00');

$sql 			= 	"SELECT * 
					FROM notify 
					WHERE notifyStatus = 1
					" . $reg . "
					" . $out . "
					AND notifyDate > '" . $lastSeen . "'
					AND (companyId = ? OR companyId IS NULL)
					ORDER BY notifyDate 
					DESC LIMIT 100";


//echo $sql;

$result 		= ncmExecute($sql,[COMPANY_ID],false,true);
$jsonResult 	= [];

if($result){
	while (!$result->EOF) {
		$jsonResult[] = 	[
								"title" 	=> $result->fields['notifyTitle'],
								"message" 	=> $result->fields['notifyMessage'],
								"mode" 		=> $result->fields['notifyMode'],
								"date" 		=> $result->fields['notifyDate'],
								"timeago"	=> timeago($result->fields['notifyDate']),
								"link" 		=> $result->fields['notifyLink'],
								"type" 		=> $result->fields['notifyType']
							];
		$result->MoveNext();
	}
}

//actualizo the last time checked
$db->AutoExecute('contact', ['contactLastNotificationSeen' => date('Y-m-d H:i:s')], 'UPDATE', 'contactId = ' . $user);

header('Content-Type: application/json; charset=utf-8;'); 
dai(json_encodes($jsonResult));
?>