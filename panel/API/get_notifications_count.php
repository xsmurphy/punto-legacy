<?php
include_once('api_head.php');

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

if(validateHttp('outlet','post')){
	$out = " AND (outletId = '" . $outlet . "' OR outletId IS NULL)";
}

$sql 			= 	"SELECT COUNT(notifyId) as count 
					FROM notify 
					WHERE notifyMode = ? 
					AND notifyStatus = 1
					" . $reg . "
					" . $out . "
					AND notifyDate > '" . $lastSeen . "'
					AND (companyId = ? OR companyId IS NULL)
					ORDER BY notifyDate 
					DESC LIMIT 100";

$result 		= ncmExecute($sql,[$type,COMPANY_ID]);
$jsonResult 	= [];

if($result){
	$jsonResult['count'] 	= $result['count'];
	$jsonResult['lastSeen'] = $lastSeen;
}

header('Content-Type: application/json; charset=utf-8;'); 
dai(json_encodes($jsonResult));
?>