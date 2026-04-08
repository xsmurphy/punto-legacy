<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
 
$ID 							= validateHttp('id','post');
$status 						= validateHttp('status','post');
$amount 						= validateHttp('amount','post');
$date 							= validateHttp('date','post');
$order 							= validateHttp('order','post');
$transaction 					= validateHttp('transaction','post');
$outlet 						= validateHttp('outlet','post');
$authCode 						= validateHttp('authCode','post');
$data 							= validateHttp('data','post');
$user							= dec(validateHttp("user","post"));
$customer						= validateHttp("customer","post");
$operationNo					= validateHttp('operationNo','post');
$UID							= validateHttp('UID','post');


$record     					= [];

if($ID){
	$ID 						= dec($ID);
	$do 						= 'ID = ' . $ID;
/*}else if($order){
	$order 						= intval( $order );
	$do 						= 'orderNo = ' . $order;*/
}else if($UID){
	$UID 						= intval( $UID );
	$do 						= 'UID = ' . $UID;
}else{
	apiError('Missing ID', 401);
}

if($date){
	$record['date'] 				= $date;
}

if($amount){
	$record['amount'] = $amount;
}

if(!empty($user)){
	$record['userId'] = $user;
}

if($order){
	$record['orderNo'] 				= $order;
}

if($status){
	$record['status']             	= $status;
	if($status == 'APPROVED'){
		$record['paymentDate']      = TODAY;
	}
}

if($transaction){
	$record['transactionId']       	= $transaction;
}

if($outlet){
	$record['outletId']           	= $outlet;
}

if($authCode){
	$record['authCode']           	= $authCode;
}

if($operationNo){
	$record['operationNo']          = $operationNo;
}
if(!empty($customer)){
	$record['customerId']			= dec($customer);
}

if($data){
	$data 							= json_decode($data, true);
	$aData['bin'] 					= $data['bin'];
	$aData['idCommerce']			= $data['idCommerce'];
	$aData['purchaseOperationNumber']= $data['purchaseOperationNumber'];
	$aData['purchaseVerification'] 	= $data['purchaseVerification'];
	$aData['IDTransaction'] 		= $data['IDTransaction'];
	$aData['paymentReferenceCode']	= $data['paymentReferenceCode'];
	$aData['brand']					= $data['brand'];
	$aData['txDateTime']			= stripslashes($data['txDateTime']);
	$aData['errorCode']				= $data['errorCode'];
	$aData['authorizationResult']	= $data['authorizationResult'];
	$aData['errorMessage']			= $data['errorMessage'];

	$record['data']           		= json_encode($aData);
}

$record['updated_at'] 				= TODAY;

$update 							= ncmUpdate(['records' => $record, 'table' => 'vPayments', 'where' => $do . ' AND companyId = ' . COMPANY_ID]);

if($update['error']){
	apiError($db->ErrorMsg(), 401);
}else{
	//verifico si existe la factura y si es una factura a credito
	$result = ncmExecute('SELECT * FROM transaction WHERE transactionUID = ? AND companyId = ? LIMIT 1', [$UID, COMPANY_ID]);
	if($result['transactionType'] == 3){//es factura a credito
		$tPay 								= [];
		$tPay['transactionDate'] 			= TODAY;
		$tPay['transactionTotal'] 			= $result['transactionTotal'];
		$tPay['transactionType'] 			= 5;
		$tPay['transactionParentId']		= $result['transactionId'];
		$tPay['transactionComplete']		= 1;
		$tPay['transactionStatus']			= 1;
		$tPay['transactionPaymentType']		= json_encode( [['type' => 'epos', 'name' => 'ePOS', 'total' => $result['transactionTotal']]] );
		$tPay['transactionUID']				= generateUID();
		$tPay['invoiceNo']					= getNextDocNumber(0,5,$result['registerId']);
		$tPay['timestamp']					= time();
		$tPay['customerId']					= $result['customerId'];
		$tPay['registerId']					= $result['registerId'];
		$tPay['userId']						= $result['userId'];
		$tPay['responsibleId']				= $result['responsibleId'];
		$tPay['outletId']					= $result['outletId'];
		$tPay['companyId']					= $result['companyId'];

		$result 							= ncmInsert(['records' => $tPay, 'table' => 'transaction']);

		if($result){
			ncmUpdate(['records' => ['transactionComplete' => 1], 'table' => 'transaction', 'where' => 'transactionId = ' . $result['transactionId'] . ' AND companyId = ' . COMPANY_ID]);
		}
	}


	apiOk(['success' => 'Orden modificada', 'data' => $record, 'authCode' => $authCode]);
}
?>