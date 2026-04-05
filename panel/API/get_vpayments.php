<?php
include_once('api_head.php');

$ID			= validateHttp('ID', 'post');
$UID 		= validateHttp('UID', 'post');
$orderNo 		= validateHttp('orderNo', 'post');
$authCode	= validateHttp('authCode', 'post');
$from 		= validateHttp('from', 'post');
$to 		= validateHttp('to', 'post');
$array 		= [];
$statuses 	= "'APPROVED', 'PENDING', 'RECEIVED', 'REVIEW'";
$endResult 	= ['error' => 'not_found'];
$contacts = [];
$contactsResult 		= ncmExecute('SELECT *
						FROM contact
						WHERE companyId = ?', [COMPANY_ID], false, true);
if (!empty($contactsResult)) {
	while (!$contactsResult->EOF) {
		$contacts[$contactsResult->fields['contactId']] = $contactsResult->fields['contactName'];
		$contactsResult->MoveNext();
	}
}
if ($UID) {
	$result = ncmExecute('SELECT * FROM vPayments WHERE UID = ? AND companyId = ? LIMIT 1', [$UID, COMPANY_ID], false, true);
} else if ($authCode) {
	$result = ncmExecute('SELECT * FROM vPayments WHERE authCode = ? AND companyId = ? LIMIT 1', [$authCode, COMPANY_ID], false, true);
} else if ($ID) {
	$result = ncmExecute('SELECT * FROM vPayments WHERE ID = ? AND companyId = ? AND status IN(?) LIMIT 1', [$ID, COMPANY_ID, $statuses], false, true);
} else if ($orderNo) {
	$result = ncmExecute('SELECT * FROM vPayments WHERE orderNo = ? AND companyId = ? LIMIT 1', [$orderNo, COMPANY_ID], false, true);
} else {
	$dates 			= '';

	if ($from && $to) {
		$dates 		= " AND date BETWEEN '" . $from . "' AND '" . $to . "'";
	}

	$result 		= ncmExecute('SELECT * FROM vPayments WHERE companyId = ?' . $dates . ' ORDER BY date DESC LIMIT 20000', [COMPANY_ID], false, true);
}

if ($result) {
	$outlets 	= getAllOutlets(COMPANY_ID);

	while (!$result->EOF) {
		$fields 					= $result->fields;
		$ID 						= enc($fields['ID']);
		$array[] 		=  [
			'ID' 			=> $ID,
			'date' 			=> $fields['date'],
			'payoutDate' 	=> $fields['payoutDate'],
			'payoutAmount' 	=> $fields['payoutAmount'],
			'depositedDate' => $fields['depositedDate'],
			'comission' 	=> $fields['comission'],
			'tax' 			=> $fields['tax'],
			'orderNo' 		=> $fields['orderNo'],
			'operationNo' 	=> $fields['operationNo'],
			'authCode' 		=> $fields['authCode'],
			'status' 		=> $fields['status'],
			'amount' 		=> $fields['amount'],
			'source' 		=> $fields['source'],
			'customer'		=> !empty($fields['customerId']) ? $contacts[$fields['customerId']]  : "",
			'customerId'		=> !empty($fields['customerId']) ? enc($fields['customerId'])  : "",
			'data' 			=> json_decode($fields['data'], true),
			'deposited' 	=> $fields['deposited'],
			'userName' 		=> !empty($fields['userId']) ? $contacts[$fields['userId']] : "",
			'UID' 			=> $fields['UID'],
			'eUID' 			=> enc($fields['UID']),
			'outlet' 		=> enc($fields['outletId']),
			'outletName' 	=> $outlets[$fields['outletId']]['name'],
			'company' 		=> enc($fields['companyId'])
		];

		$result->MoveNext();
	}

	$result->MoveFirst();

	$endResult = ['success' => $array];
}

jsonDieResult($endResult);
