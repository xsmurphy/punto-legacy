<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$tId = validateHttp('id','post');

if(!$tId){
	apiError('No se encontraron registros', 401);
}

$tId = dec($tId);

$sql = "SELECT * 
		FROM transaction 
		WHERE transactionType IN (0,3,5,6,7)
		AND transactionId = ?
		AND companyId = ?
		LIMIT 1";

$fields 	= ncmExecute($sql,[$tId,COMPANY_ID]);
$array 		= [];
if($fields){
	if($fields['customerId']){
		$aCustomer = getCustomerData($fields['customerId'],'uid');
	}

	if($fields['userId']){
		$userName = getValue('contact','contactName','WHERE contactId = ' . $fields['userId'] . ' AND companyId = ' . COMPANY_ID);
	}

	if($fields['registerId']){
		$allRegisters 	= getAllRegisters();
		$register 		= $allRegisters[$fields['registerId']];
	}

	if($fields['outletId']){
		$outletName = getValue('outlet','outletName','WHERE outletId = ' . $fields['outletId']);
	}

	$date 			= $fields['transactionDate'];
	$duedate 		= iftn($fields['transactionDueDate'],$date);
	$invoiceNo 		= $fields['invoiceNo'];
	$invoicePrefix 	= $register['invoicePrefix'];
	$registerName	= $register['name'];
	$invoiceAuth	= $register['invoiceAuthNo'];
	$invoiceAuthExp	= $register['invoiceAuthExpiration'];
	$customerName	= $aCustomer['name'];
	$customerFName	= $aCustomer['secondName'];
	$customerTin	= $aCustomer['ruc'];
	$customerPhone	= $aCustomer['phone'];
	$customerAddress= $aCustomer['address'];
	$customerEmail 	= $aCustomer['email'];

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

	$array = [	
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

				"invoice_no" 		=> $invoicePrefix.$invoiceNo,
				"invoice_auth_no" 	=> $invoiceAuth,
				"invoice_auth_expiration"=> $invoiceAuthExp,

				"user_name" 		=> $userName,
				"user_id" 			=> enc($fields['userId']),

				"customer_name" 	=> $customerName,
				"customer_full_name"=> $customerFName,
				"customer_tin" 		=> $customerTin,
				"customer_phone" 	=> $customerPhone,
				"customer_address" 	=> $customerAddress,
				"customer_email" 	=> $customerEmail,

				"sale_total" 		=> $fields['transactionTotal'],
				"sale_discount" 	=> $fields['transactionDiscount'],
				"sale_tax" 			=> $fields['transactionTax'],
				"sale_details" 		=> json_decode(toUTF8($fields['transactionDetails'])),
				"sale_payments" 	=> json_decode(toUTF8($fields['transactionPaymentType'])),
				"sale_note" 		=> toUTF8($fields['transactionNote'])
			];
}else{
	apiError('No se encontraron registros', 401);
}

header('Content-Type: application/json');
http_response_code(200);
echo json_encode($array, JSON_PRETTY_PRINT);

dai();
?>