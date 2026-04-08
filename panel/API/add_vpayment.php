<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$outlet 			= dec(validateHttp('outlet', 'post'));
$user 				= validateHttp('user', 'post') ? dec(validateHttp('user', 'post')) : NULL;
$UID				= validateHttp('UID', 'post');
$amount				= validateHttp('amount', 'post');
$saleAmount			= validateHttp('saleAmount', 'post');
$comission			= validateHttp('comission', 'post');
$tax				= validateHttp('tax', 'post');
$parentId			= validateHttp('parentId', 'post');
$clientPayCommission = validateHttp('clientPayCommission', 'post');
$dParentId 			= dec($parentId);
$order 				= validateHttp('order', 'post');
$authCode 			= trim(validateHttp('authCode', 'post'));
$operationNo		= validateHttp('operationNo', 'post');
$data 				= validateHttp('data', 'post');
$source				= validateHttp('source', 'post');
$status 			= validateHttp('status', 'post') ? validateHttp('status', 'post') : 'PENDING';
$date 				= validateHttp('date', 'post') ? validateHttp('date', 'post') : TODAY;
$customer			= validateHttp('customer', 'post');
$msg 				= 'Orden de pago generada';
$payNow 			= false;

if (empty($authCode) && (!empty($source) && $source !== 'PIX')) {
	apiError("El codigo de autorizacion no debe ser vacio", 401);
}
if (($outlet < 2 || !$outlet)) {
	apiError('Debe incluir sucursal y ID de transaccion', 401);
}

$result 		= ncmExecute('SELECT * FROM vPayments WHERE authCode = ? AND date(date) BETWEEN ? AND ? AND companyId = ? LIMIT 1', [$authCode, date('Y-m-d', strtotime("-1 month", strtotime($date))), date('Y-m-d', strtotime($date)), COMPANY_ID]);
$_modules 		= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [COMPANY_ID]);

$eposData 		= json_decode($_modules['eposData'], true);
$processorData 	= json_decode($data, true);

if (!$result) {

	$record     					= [];
	$isOnlinePayment 				= false;
	$rate 							= $eposData['rate']; //credito
	$tax 							= $eposData['tax'];
	$depositDays 					= 0;

	if (in_array($source, ['bancardQROnline', 'dinelcoVPOS', 'bancardAutoDebit', 'bancardVPOS'])) { //si es pago online
		$isOnlinePayment 			= true;
		$rate 						= $eposData['rateOnline'];
	}

	if ($isOnlinePayment) {
		if ($source == 'bancardQROnline') {
			if ($processorData['account_type'] == 'TC') { //TC t credito

			} else {										//DC t debito

			}
		} else if ($source == 'dinelcoVPOS') {
		}
	} else {
		if (in_array($source, ['bancardPOS', 'dinelcoPOS', 'bancardQR', 'PIX'])) {
			if (in_array($processorData['account_type'], ['DC', 'TD'])) { //DC debito de cuenta, TD tarjeta de debito
				$rate 						= $eposData['rateDebit'];
			} else if (in_array($processorData['account_type'], ['TC'])) { //TC tarjeta de credito
				$rate 						= $eposData['rate'];
			} else {
				$rate 						= 0;
			}
		}
	}
	$comission       = round(($rate / 100) * $saleAmount); //875
	$comTax          = round(($tax / 100) * $comission);
	if (!empty($clientPayCommission)) {
		$comissionTmp       = round(($saleAmount / (($rate + ($rate / $tax)) + 100)), 2); //875
		$comission 		= round(($comissionTmp * $rate), 2);
		$comTax          = round((($tax / 100) * $comission), 2);
	}

	if (date('D') == 'Sat') {
		if (date('H') > 11) {
			$paymentDate 			= date('Y-m-d 00:00:00', strtotime(TODAY . ' +' . $depositDays . ' weekdays'));
		} else {
			$depositDays 			= $depositDays + 2;
			$paymentDate 			= date('Y-m-d 00:00:00', strtotime(TODAY . ' +' . $depositDays . ' days'));
		}
	} else {

		if (date('H') > 17) {
			$addDays = '+1 weekdays';

			if (date('D') == 'Fri') {
				$addDays = '+1 days';
			}

			$paymentDate 					= date('Y-m-d 00:00:00', strtotime(TODAY . ' ' . $addDays));
		} else {
			$paymentDate 					= date('Y-m-d 00:00:00', strtotime(TODAY . ' +' . $depositDays . ' weekdays'));
		}
	}

	if ($eposData['customerPays']) {
		$payoutAmount 				= $saleAmount;
	} else {
		$payoutAmount 				= $saleAmount - ($comission + $comTax);
	}

	// if($status == 'REVIEW'){
	// 	$payoutAmount 				= 0;
	// }

	if ($authCode) {
		$record['authCode']         = $authCode;
	}

	if ($operationNo) {
		$record['operationNo']      = $operationNo;
	}

	if ($data) {
		$record['data']      		= $data;
	}

	if ($source) {
		$record['source']      		= $source;
	}

	if (!empty($customer)) {
		$record['customerId'] 		= dec($customer);
	}

	if ($depositDays < 1) {
		//$status      				= 'RECEIVED';
		$payNow 					= true;
	}

	$record['date'] 				= $date;
	$record['payoutDate']			= $paymentDate;
	$record['amount']             	= $saleAmount;
	$record['payoutAmount']			= $payoutAmount;
	$record['comission']			= $comission;
	$record['tax']					= $comTax;
	$record['orderNo'] 				= $order;
	$record['status']             	= $status;
	$record['UID']       			= $UID;
	$record['userId']           	= $user;
	$record['outletId']           	= $outlet;
	$record['companyId']          	= COMPANY_ID;

	if ($parentId) {
		$record['transactionId']    = $dParentId;
	}

	$insert                     	= $db->AutoExecute('vPayments', $record, 'INSERT');
	$invId                      	= $db->Insert_ID();

	$result = ncmExecute('SELECT * FROM transaction WHERE transactionUID = ? AND companyId = ? LIMIT 1', [$UID, COMPANY_ID]);
	if ($result['transactionType'] == 3 && $result['transactionComplete'] != 1) { //es factura a credito
		$tPay 								= [];
		$tPay['transactionDate'] 			= TODAY;
		$tPay['transactionTotal'] 			= $saleAmount;
		$tPay['transactionType'] 			= 5;
		$tPay['transactionParentId']		= $result['transactionId'];
		$tPay['transactionComplete']		= 1;
		$tPay['transactionStatus']			= 1;
		$tPay['transactionPaymentType']		= json_encode([['type' => 'epos', 'name' => 'ePOS', 'total' => $saleAmount]]);
		$tPay['transactionUID']				= generateUID();
		$tPay['invoiceNo']					= getNextDocNumber(0, 5, $result['registerId']);
		$tPay['timestamp']					= time();
		$tPay['customerId']					= $result['customerId'];
		$tPay['registerId']					= $result['registerId'];
		$tPay['userId']						= $result['userId'];
		$tPay['responsibleId']				= $result['responsibleId'];
		$tPay['outletId']					= $result['outletId'];
		$tPay['companyId']					= $result['companyId'];

		$insert 							= ncmInsert(['records' => $tPay, 'table' => 'transaction']);

		if ($insert) {
			ncmUpdate(['records' => ['transactionComplete' => 1], 'table' => 'transaction', 'where' => 'transactionId = ' . $result['transactionId'] . ' AND companyId = ' . COMPANY_ID]);
		}

		if (COMPANY_ID === 4456) { // TODO: replace integer 4456 with the company UUID once data is migrated
			try {
				$customerRUC = ncmExecute("SELECT contactTIN FROM contact WHERE companyId = 4456 AND contactId = ? LIMIT 1", [$result['customerId']]);
				$company = ncmExecute("SELECT companyId FROM company WHERE config->>'settingRUC' = ? LIMIT 1", [$customerRUC['contactTIN']]);
				$recordUpdate['blocked'] 	= 0;
				$recordUpdate['planExpired'] = 0;
				$update = $db->AutoExecute('company', $recordUpdate, 'UPDATE', 'companyId = ' . $db->qstr($company['companyId']));
			} catch (\Throwable $th) {
			}
		}
	}
} else {
	$insert = false;
	$invId 	= $result['ID'];
	$msg 	= 'order_exists';
}

if ($insert === false) {
	apiOk(['error' => 'already_exists', 'success' => false]);
} else {
	apiOk(['success' => $msg, 'UID' => $UID, 'ID' => $invId, 'authCode' => $authCode, 'operationNo' => $operationNo, 'payNow' => $payNow]);
}
