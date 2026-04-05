<?php

require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$limitQ 		= 500;
$limit 			= validateHttp('limit','post');
$from 			= validateHttp('from','post');
$to 			= validateHttp('to','post');
$orderD			= 'ORDER BY transactionDate DESC';
$order 			= validateHttp('order','post');

if($order){
	$orderD		= $db->Prepare('ORDER BY transactionDate ' . $order);
}

if(validity($limit) && $limit < $limitQ){
	$limitQ 	=  $db->Prepare($limit);
}

$startDate 				= iftn($from,'2016-02-01 00:00:00');
$endDate 				= iftn($to,TODAY);

$sql = "SELECT * 
		FROM transaction 
		WHERE transactionType IN (0,3,5,6,7)
		AND transactionDate 
		BETWEEN ? 
		AND ? 					
		AND companyId = ?
		" . $orderD . "
		LIMIT " . $limitQ;

$result 	= ncmExecute($sql,[$startDate,$endDate,COMPANY_ID],false,true);
$array 		= [];
if($result){
	$allRegisters 			= getAllRegisters();
	$allUsers 				= getAllContacts('0',false,true);
	
	$allOutlets 			= getAllOutlets();

	$customersIn 			= [];
	while (!$result->EOF) {
		$customersIn[] = $result->fields['customerId'];
		$result->MoveNext();
	}

	$allCustomers 	 		= getAllContactsRaw(1,0,false,'contactName,contactSecondName,contactTIN,contactPhone,contactAddress,contactEmail,contactUID',' AND contactUID IN(' . implodes(',', $customersIn) . ')');

	$result->MoveFirst();

	while (!$result->EOF) {
		$fields 		= $result->fields;
		$register 		= $allRegisters[$fields['registerId']];
		$aCustomer 		= $allCustomers[$fields['customerId']];

		$date 			= $fields['transactionDate'];
		$duedate 		= iftn($fields['transactionDueDate'],$date);
		$invoiceNo 		= $fields['invoiceNo'];
		$invoicePrefix 	= $register['invoicePrefix'];
		$registerName	= $register['name'];
		$invoiceAuth	= $register['invoiceAuthNo'];
		$invoiceAuthExp	= $register['invoiceAuthExpiration'];
		$outletName 	= $allOutlets[$fields['outletId']]['name'];
		$customerName	= $aCustomer['contactName'];
		$customerFName	= $aCustomer['contactSecondName'];
		$customerTin	= $aCustomer['contactTIN'];
		$customerPhone	= $aCustomer['contactPhone'];
		$customerAddress= $aCustomer['contactAddress'];
		$customerEmail 	= $aCustomer['contactEmail'];

		if($fields['transactionType'] < 1){
			$saleType = 'cash_sale';
		}else if($fields['transactionType'] == 3){
			$saleType = 'credit_sale';
		}else if($fields['transactionType'] == 5){
			$saleType = 'credit_sale_payment';
		}else if($fields['transactionType'] == 6){
			$saleType = 'return_sale';
		}else if($fields['transactionType'] == 7){
			$saleType = 'void_sale';
		}

		$order_details 	= json_decode($fields['transactionDetails'],true);

		foreach ($order_details as &$itmLine) {
			$itmLine['note'] = isBase64Decode($itmLine['note']);
		}

		$userName 		= iftn($allUsers[2][$fields['userId']]['name'],$allUsers[1][$fields['userId']]['name']);

		$array[$date] = [	
							"UID" 				=> $fields['transactionUID'],
							"transaction_id" 	=> enc($fields['transactionId']),
							"date"				=> $date,
							"due_date"			=> $duedate,
							"type"				=> $saleType,
							"register"			=> $registerName,
							"register_id"		=> enc($fields['registerId']),
							"outlet"			=> $outletName,
							"outlet_id"			=> enc($fields['outletId']),

							"parent_sale_id" 	=> $fields['transactionParentId'] ? enc($fields['transactionParentId']) : null,

							"invoice_no" 		=>$invoicePrefix.$invoiceNo,
							"invoice_auth_no" 	=>$invoiceAuth,
							"invoice_auth_expiration"=>$invoiceAuthExp,

							"user_name" 		=>$userName,
							"user_id" 			=> enc($fields['userId']),

							"customer_name" 	=>$customerName,
							"customer_full_name"=>$customerFName,
							"customer_tin" 		=>$customerTin,
							"customer_phone" 	=>$customerPhone,
							"customer_address" 	=>$customerAddress,
							"customer_email" 	=>$customerEmail,

							"sale_total" 		=>$fields['transactionTotal'],
							"sale_discount" 	=>$fields['transactionDiscount'],
							"sale_tax" 			=>$fields['transactionTax'],
							"sale_details" 		=>$order_details,
							"sale_payments" 	=>json_decode(toUTF8($fields['transactionPaymentType'])),
							"sale_note" 		=> toUTF8(isBase64Decode($fields['transactionNote']))
						];
						//"sale_note" 		=>toUTF8($fields['transactionNote'])

		$result->MoveNext();
	}
}

apiOk($array);
