<?php
include_once('api_head.php');

$sql 			= 'SELECT * FROM company WHERE companyId = ? LIMIT 1';
$result 		= ncmExecute($sql,[COMPANY_ID]);

$array = 	[
			'company' 	=> $result['companyLastUpdate'],
			'orders' 	=> $result['orderLastUpdate'],
			'calendar' 	=> $result['calendarLastUpdate'],
			'inventory' => $result['inventoryLastUpdate'],
			'items' 	=> $result['itemsLastUpdate'],
			'customers' => $result['customersLastUpdate']
		];

jsonDieResult($array,200);
?>