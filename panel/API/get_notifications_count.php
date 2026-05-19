<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$type 			= validateHttp('type','post');
$type 			= ($type == 'notes') ? '1' : '0';

$register 		= $db->Prepare(validateHttp('register','post'));
$outlet 		= $db->Prepare(dec(validateHttp('outlet','post')));
$user 			= $db->Prepare(dec(validateHttp('user','post')));
$lastSeen 		= $db->Prepare(validateHttp('lastSeen','post'));
$reg 			= '';
$out 			= '';

if(!validateHttp('user','post')){
	header('Content-Type: application/json; charset=utf-8;'); 
	$jsonResult['error'] = 1;
	$jsonResult['message'] = 'User ID is required';
	dai(json_encodes($jsonResult));
}

$lastS 		= ncmExecute('SELECT contactLastNotificationSeen FROM contact WHERE contactId = ?',[$user]);
$lastSeen 	= iftn($lastS['contactLastNotificationSeen'],'2019-01-01 00:00:00');

if(validateHttp('register','post')){
	$reg = ' AND notifyRegister = 1';
}

// Parametrizar con `?` en vez de concatenar — evita doble-quoting de UUIDs
// (Prepare() ahora no quotea UUIDs, ver panel/includes/lib/DB.php).
$params = [$type];

if (validateHttp('outlet','post')) {
	$out = " AND (outletId = ? OR outletId IS NULL)";
	$params[] = $outlet;
}

$params[] = $lastSeen;
$params[] = COMPANY_ID;

$sql 			= 	"SELECT COUNT(notifyId) as count
					FROM notify
					WHERE notifyMode = ?
					AND notifyStatus = 1
					" . $reg . "
					" . $out . "
					AND notifyDate > ?
					AND (companyId = ? OR companyId IS NULL)
					ORDER BY notifyDate
					DESC LIMIT 100";

$result 		= ncmExecute($sql, $params);
$jsonResult 	= [];

if($result){
	$jsonResult['count'] 	= $result['count'];
	$jsonResult['lastSeen'] = $lastSeen;
}

header('Content-Type: application/json; charset=utf-8;'); 
dai(json_encodes($jsonResult));
?>