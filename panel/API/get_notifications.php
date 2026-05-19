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

// Parametrizar con `?` en vez de concatenar — evita doble-quoting de UUIDs.
$params = [];

if (validateHttp('outlet','post')) {
	$out = " AND (outletId = ? OR outletId IS NULL)";
	$params[] = $outlet;
}

if(!validateHttp('user','post')){
	header('Content-Type: application/json; charset=utf-8;');
	$jsonResult['error'] = 1;
	$jsonResult['message'] = 'User ID is required';
	dai(json_encodes($jsonResult));
}

$lastS 		= ncmExecute('SELECT contactLastNotificationSeen FROM contact WHERE contactId = ?',[$user]);
$lastSeen 	= iftn($lastS['contactLastNotificationSeen'],'2019-01-01 00:00:00');

$params[] = $lastSeen;
$params[] = COMPANY_ID;

$sql 			= 	"SELECT *
					FROM notify
					WHERE notifyStatus = 1
					" . $reg . "
					" . $out . "
					AND notifyDate > ?
					AND (companyId = ? OR companyId IS NULL)
					ORDER BY notifyDate
					DESC LIMIT 100";


$result 		= ncmExecute($sql, $params, false, true);
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