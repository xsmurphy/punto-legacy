<?php

require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$limitQ 		= 500;
$limit 			= validateHttp('limit','post');
$offset 		= validateHttp('offset','post') ? validateHttp('offset','post') : 0;
$from 			= validateHttp('from','post');
$to 			= validateHttp('to','post');
$scheduledAt	= validateHttp('scheduledat','post');
$scheduledTill	= validateHttp('scheduledtill','post');
$type 			= iftn(validateHttp('type','post'),12);
$order 			= iftn(validateHttp('order','post'),'DESC');//sorting
$orderD			= ($scheduledAt) ? 'ORDER BY fromDate ' . $order : 'ORDER BY transactionDate ' . $order;
$userId 		= validateHttp('user','post');
$dueDate 		= validateHttp('duedate','post');
$status 		= validateHttp('status','post');
$cache 			= validateHttp('cache','post') ? validateHttp('cache','post') : false;
$customerData 	= validateHttp('customerdata','post');
$outletId 		= dec(validateHttp('outlet','post'));
$registerId 	= dec(validateHttp('register','post'));
$customerId 	= validateHttp('customer','post');
$reverse 		= validateHttp('reverse','post');
$orderId 		= validateHttp('ID','post');
$invoiceNo 		= validateHttp('docNumber','post');

$orderStatusAr 	= ['0' => 'Pendiente','1' => 'Pendiente', '2' => 'En Espera', '3' => 'En Proceso', '4' => 'Finalizado', '5' => 'Enviado', '6' => 'Cancelado'];
$orderSColorAr 	= ['0' => 'light','1' => 'light', '2' => 'warning', '3' => 'info', '4' => 'success', '5' => 'dark', '6' => 'danger']; 

$sqlUser 		= '';
$sqlStatus 		= '';
$sqlDates 		= '';
$sqlOutlet 		= '';
$sqlInvoice 	= '';

$roc 				= getROC($registerId,$outletId,COMPANY_ID);

if($status){
	$sqlStatus 		= db_prepare(' AND transactionStatus IN (' . $status . ')');
}

if($userId){
	$sqlUser 		= db_prepare(' AND userId = ' . dec($userId));
}

if($customerId){
	$sqlCustomer	= db_prepare(' AND customerId = ' . dec($customerId));
}

if($orderId){
	$sqlID			= db_prepare(' AND transactionId = ' . dec($orderId));
	$limitQ 		= 1;
	$limit 			= 1;
	$offset 		= 0;
}

if($invoiceNo){
	$sqlInvoice	= db_prepare(' AND invoiceNo = ' . floatval($invoiceNo));
	$limitQ 		= 1;
	$limit 			= 1;
	$offset 		= 0;
}

if($scheduledAt && $scheduledTill){
	$sqlDates 	= db_prepare(" AND (fromDate >= '" . $scheduledAt . "' AND toDate <= '" . $scheduledTill . "')");
}else if($dueDate){
	$sqlDates 	= db_prepare(" AND (transactionDueDate BETWEEN '" . $from . "' AND '" . $to . "' OR transactionDueDate IS NULL)");
}else if($from && $to){
	$sqlDates 	= db_prepare(" AND transactionDate BETWEEN '" . $from . "' AND '" . $to . "'");
}else{
	$sqlDates 	= db_prepare(" AND transactionDate BETWEEN '" . TODAY_START . "' AND '" . TODAY_END . "'");
}

if(validity($limit) && $limit < $limitQ){
	$limitQ 	=  db_prepare($limit);
}

$limits 		= db_prepare(getTableLimits($limitQ,$offset));

$startDate 		= iftn($from,'2016-02-01 00:00:00');
$endDate 		= iftn($to,TODAY);

$sql = "SELECT * 
		FROM transaction 
		WHERE transactionType = ?
		" . $roc . "
		" . $sqlDates . "
		" . $sqlUser . "
		" . $sqlStatus . "
		" . $sqlCustomer . "
		" . $sqlInvoice . "
		" . $orderD . "
		" . $sqlID . 
		$limits;

$result 	= ncmExecute($sql,[$type],$cache,true);

if($_POST['test']){
	/*header('Content-Type: application/json');
	http_response_code(200);
	echo json_encode(['result' => $sql, 'type' => $type, $result]);
	dai();*/
}

$array 		= [];

if($result){
	$allRegisters 			= getAllRegisters();
	$allUsers 				= getAllContacts('0',false,true);
	$allOutlets 			= getAllOutlets();
	$allIcon 				= getTransactionTypeIcons($type);
	$allCustomers 			= [];
	$inCustomers 			= [];
	$inUsers 				= [];

	//prefetch data
	while (!$result->EOF) {
		$inCustomers[] 	= $result->fields['customerId'];
		$inUsers[] 		= $result->fields['userId'];
		$result->MoveNext();
	}

	$result->MoveFirst();

	if($customerData && $inCustomers){
		//$sql,$array=false,$cache=false,$forceObj=false,$getAssoc=false
		$allCustomers = ncmExecute(
										'	SELECT
											contactId, 
											contactId,
											contactRealId,
											contactName,
											contactSecondName,
											contactEmail,
											contactAddress,
											contactAddress2,
											contactPhone,
											contactPhone2,
											contactNote,
											contactCity,
											contactCountry,
											contactTIN,
											contactCI,
											contactDate
											FROM contact 
											WHERE type = 1 
											AND contactId 
											IN(' . implodes(',',$inCustomers) . ') 
											AND companyId = ?
											LIMIT ' . $limitQ . '
											',
										[COMPANY_ID],
										$cache,
										true,
										true
									);
	}

	while (!$result->EOF) {
		$fields 		= $result->fields;

		if($_POST['test']){
			//print_r($fields);
		}

		$register 		= $allRegisters[$fields['registerId']];
		$aCustomer 		= $allCustomers[$fields['customerId']];

		$date 			= strToDate(false,$fields['transactionDate']);
		$from 			= strToDate('Y-m-d',$fields['fromDate']);
		$to 			= strToDate('Y-m-d',$fields['toDate']);
		$fromH 			= strToDate('H:i',$fields['fromDate']);
		$toH 			= strToDate('H:i',$fields['toDate']);
		$duedate 		= iftn(strToDate(false,$fields['transactionDueDate']),$date);
		$invoiceNo 		= $fields['invoiceNo'];
		$invoicePrefix 	= $register['invoicePrefix'];
		$registerName	= $register['name'];
		$invoiceAuth	= $register['invoiceAuthNo'];
		$invoiceAuthExp	= $register['invoiceAuthExpiration'];
		$outletName 	= $allOutlets[$fields['outletId']]['name'];
		$customerName	= iftn($aCustomer['contactSecondName'],$aCustomer['contactName']);
		$customerTin	= $aCustomer['contactTIN'];
		$customerPhone	= $aCustomer['contactPhone'];
		$customerEmail 	= $aCustomer['contactEmail'];

		$cLocationName 		= false;
		$cLocationAddress 	= false;
		$cLocationLocation 	= false;
		$cLocationCity 		= false;
		$cLocationLat 		= false;
		$cLocationLng 		= false;

		$addressIdr   = ncmExecute('SELECT customerAddressId FROM toAddress WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);

		if($addressIdr){
			$addressD    = ncmExecute('SELECT * FROM customerAddress WHERE customerAddressId = ? AND companyId = ? LIMIT 1',[$addressIdr['customerAddressId'],COMPANY_ID]); 

			if($addressD){
				$cLocationName 		= $addressD['customerAddressName'];
				$cLocationAddress 	= $addressD['customerAddressText'];
				$cLocationLocation 	= $addressD['customerAddressLocation'];
				$cLocationCity 		= $addressD['customerAddressCity'];
				$cLocationLat 		= $addressD['customerAddressLat'];
				$cLocationLng 		= $addressD['customerAddressLng'];
			}
		}

		/*$cLocation 		= getDefaultCustomerAddress($aCustomer['contactId'],false,COMPANY_ID);
		$cLocationName 	= $cLocation['name'];
		$cLocationAddress= $cLocation['address'];
		$cLocationLat 	= $cLocation['lat'];
		$cLocationLng 	= $cLocation['lng'];*/

		$name 			= $fields['transactionName'];
		$tagsArr 		= json_decode($fields['tags'],true);

		$userName 		= iftn($allUsers[2][$fields['userId']]['name'],$allUsers[1][$fields['userId']]['name']);

		$order_details 	= getItemsFilledWithData( json_decode($fields['transactionDetails'],true) );

		$array[$date] = [	
							"UID" 				=> enc($fields['transactionUID']),
							"transaction_id" 	=> enc($fields['transactionId']),
							"date"				=> $date,
							"due_date"			=> $duedate,
							"from_date"			=> $from,
							"to_date"			=> $to,
							"from_hour"			=> $fromH,
							"to_hour"			=> $toH,
							"register"			=> $registerName,
							"register_id"		=> enc($fields['registerId']),
							"outlet"			=> $outletName,
							"outlet_id"			=> enc($fields['outletId']),
							"icon" 				=> $allIcon[$fields['transactionStatus']],

							"parent_sale_id" 	=> $fields['transactionParentId'] ? enc($fields['transactionParentId']) : null,

							"number_id" 		=> $invoiceNo,

							"user_name" 		=> $userName,
							"user_id" 			=> enc($fields['userId']),

							"customer_name" 	=> $customerName,
							"customer_tin" 		=> $customerTin,
							"customer_phone" 	=> $customerPhone,
							"customer_email" 	=> $customerEmail,
							"customer_address" 	=> $cLocationAddress,
							"customer_city" 	=> $cLocationCity,
							"customer_location"	=> $cLocationLocation,
							"customer_lat" 		=> $cLocationLat,
							"customer_lng" 		=> $cLocationLng,
							"customer_id" 		=> ($fields['customerId']) ? enc($fields['customerId']) : null,

							"order_total" 		=> $fields['transactionTotal'],
							"order_details" 	=> $order_details,
							"order_note" 		=> toUTF8( isBase64Decode($fields['transactionNote']) ),
							"order_status" 		=> $fields['transactionStatus'],
							"order_status_name"	=> $orderStatusAr[$fields['transactionStatus']],
							"order_status_color"=> $orderSColorAr[$fields['transactionStatus']],
							"order_name" 		=> $name,
							"order_tags" 		=> $tagsArr,
							"order_tags_name"	=> explodes(',',printOutTags($tagsArr,false,true))
						];

		$result->MoveNext();
	}

	if($reverse){
		$array = array_reverse($array);
	}
}else{
	$array = ['error'=>'No se encontraron registros'];
}

header('Content-Type: application/json');
http_response_code(200);
echo json_encode($array);

dai();
?>