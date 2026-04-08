<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$type 			= validateHttp('type','post');

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

apiOk($array);
?>