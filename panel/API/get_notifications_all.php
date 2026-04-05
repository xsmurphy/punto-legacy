<?php
include_once('api_head.php');

$sql 			= 	'SELECT * 
					FROM notify 
					WHERE notifyStatus = 1
					AND (companyId = ? OR companyId IS NULL)
					ORDER BY notifyDate
					DESC LIMIT 5000';


//echo $sql;

$result 		= ncmExecute($sql,[COMPANY_ID],false,true);
$jsonResult 	= [];

if($result){
	while (!$result->EOF) {
		$jsonResult[] = 	[
								"id" 		=> enc($result->fields['notifyId']),
								"title" 	=> $result->fields['notifyTitle'],
								"message" 	=> $result->fields['notifyMessage'],
								"mode" 		=> $result->fields['notifyMode'],
								"date" 		=> $result->fields['notifyDate'],
								"outlet" 	=> enc($result->fields['outletId']),
								"register" 	=> $result->fields['notifyRegister'],
								"timeago"	=> timeago($result->fields['notifyDate']),
								"link" 		=> $result->fields['notifyLink'],
								"type" 		=> $result->fields['notifyType']
							];
		$result->MoveNext();
	}
	
}

header('Content-Type: application/json; charset=utf-8;'); 
dai(json_encodes($jsonResult));
?>