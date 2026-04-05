<?php
include_once('api_head.php');

if (!validateHttp('id', 'post') || !validateHttp('status', 'post')) {
	jsonDieResult(['error' => 'missing data'], 400);
}

$id 			= dec(validateHttp('id', 'post'));
$status			= validateHttp('status', 'post');
$date			= validateHttp('date', 'post');
$array 			= [];

$record['transactionStatus'] = $status;
if ($status == 3) {
	$record['fromDate'] = $date;
	$statusName = 'En proceso';
} else if ($status == 5) {
	$record['toDate'] 	= $date;
	$statusName = 'Enviado';
}

$sql 			= "SELECT * FROM transaction WHERE transactionId = " . $db->Prepare($id) . " AND companyId = " . COMPANY_ID . " LIMIT 1;";

$result 	= ncmExecute($sql,[],false,true);

if ($result) {
	while (!$result->EOF) {		
		$fields 		= $result->fields;
		// Verifico que si la orden ya fue finalizada, que no cambie el estado
		if($fields['transactionStatus'] === '4'){ 
			jsonDieResult(['completed' => 'true'], 200);
		}
		$result->MoveNext();
	}
}

$update = $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ' . $db->Prepare($id) . ' AND companyId = ' . COMPANY_ID);

if ($update !== false) {
	updateLastTimeEdit(COMPANY_ID, 'order');

	/*$result = ncmExecute('SELECT outletId, registerId, invoiceNo, companyId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[$id, COMPANY_ID]);

	if($result){
		$outletId 	= enc($result['outletId']);
		$registerId	= enc($result['registerId']);
		$orderNo 	= $result['invoiceNo'];

		$pushed = sendPush([
					"ids" 		=> $companyId,
					"message" 	=> "Cambió al estado (" . $statusName . ")", 
					"title" 	=> "# " . $orderNo,
					"companyId" => $companyId,
					"filters" 	=> [
										[
                                        	"key"   => "outletId",
                                        	"value" => $outletId
                                      	],
									    [
                                        	"key"   => "isResource",
                                        	"value" => "false"
                                      	]
									]
				]);
	}*/

	jsonDieResult(['success' => 'true'], 200);
} else {
	jsonDieResult(['error' => 'could not update'], 500);
}
