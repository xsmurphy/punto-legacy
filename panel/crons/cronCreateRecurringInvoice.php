<?php
include_once './cronHead.php';

$allowedPlans 	= '1,2,4,8,9,10';
$in 			= [];
$companyData 	= [];
$fullData 		= [];
$dateDecided 	= $get['d'];

$company = ncmExecute("SELECT
							a.companyId as id,
							a.companySMSCredit as smsCredit,
							b.settingName as name,
							b.settingCountry as country
						FROM company a, setting b
						WHERE a.companyStatus = 'Active'
						AND	a.companyPlan IN (" . $allowedPlans . ") 
						AND a.companyId = b.companyId
						LIMIT 10000");
if($company){
	while (!$company->EOF) {
		$fields = $company->fields;
	   	$in[] 	= $fields['id'];
	   	$companyData[$fields['id']] = 	[	
	   									'name' 		=> $fields['name'],
	   									'country' 	=> $fields['country'],
	   									'sms'		=> $fields['smsCredit']
	   									];

	   	$company->MoveNext(); 
	}
	$company->Close();
	$in = implodes(',', $in);
}else{
	dai();
}

//$in = '15'; //PARA EL TEST

$rangeDate 	= ($dateDecided) ? date('Y-m-d 00:00:00',strtotime($dateDecided)) : TODAY_START;

$sql = 'SELECT 	*
		FROM recurring
		WHERE companyId IN(' . $in . ')
			AND recurringNextDate = ?
			AND recurringEndDate != ?
			AND recurringStatus = 1
		LIMIT 50000';

$result = ncmExecute($sql,[$rangeDate,$rangeDate],false,true);

if($result){
	$createdInCompanies = [];
	while (!$result->EOF) {
		$fields 			= $result->fields;
		$frecuency 			= $fields['recurringFrecuency'];
		$sale 				= json_decode($fields['recurringSaleData'],true);//paso la venta a array
		$transData 			= json_decode($fields['recurringTransactionData'],true);
		$registerId 		= dec($transData['registerId']);
		$companyId 			= dec($transData['companyId']);

		$sale['dueDate'] 	= getNextDatePeriod($frecuency,'1',$sale['dueDate']);
		$sale['date'] 		= $rangeDate;
		$sale['uid'] 		= rand(10000000000000,1000000000000000000) * (19 - 14) + 14;//random number between 14 and 19 length
		$sale['timestamp']	= time();
		$sale['repeat']		= false;
		$sale['repeatF']	= '';
		$sale['repeatT']	= 0;

		$nextInvoice 		= ncmExecute('SELECT registerInvoiceNumber as no FROM register WHERE registerId = ? AND companyId = ? ORDER BY no DESC LIMIT 1',[$registerId,$companyId]);
		$sale['invoiceno']	= iftn($nextInvoice['no'],0) + 1;

		$invoiceData 		= http_build_query( [ 'data' => [
																json_encode( [
																				"updateDocNumber" => [
																										"number" 	=> $sale['invoiceno'], 
																										"type" 		=> "invoice"
																									]
																			] )
															]
												] );


		$data 				= http_build_query( [ 'data'=>[
															json_encode( [
																				"transaction" => $sale
																			] )
														]
												] );

		$url 		= 'https://app.encom.app/action?l=' . base64_encode($fields['recurringTransactionData']);

		curlContents($url, 'POST', $data); //inserto venta
		curlContents($url, 'POST', $invoiceData); //inserto invoice

		$status = 1;
		if($fields['recurringEndDate'] == $rangeDate){
			$status = 0;
		}

		$recurring                        = [];
		$recurring['recurringNextDate']   = getNextDatePeriod($frecuency,'1',$fields['recurringNextDate']);
		$recurring['recurringStatus']     = $status;
		$recurring['recurringSaleData']   = json_encode($sale);

		$db->AutoExecute('recurring', $recurring, 'UPDATE', 'recurringId = ' . $fields['recurringId'] . ' AND companyId = ' . $companyId);

		if (!in_array($companyId, $createdInCompanies)){
		    $createdInCompanies[] = $companyId; 
		}

		$result->MoveNext();
	}

	foreach ($createdInCompanies as $key => $companyId) {
		$ops = [
				"title" 	=> "Facturas recurrentes",
				"message" 	=> "Se han generado una o varias facturas recurrentes, clic para ver el listado.",
				"type" 		=> 0,
				"email" 	=> 1,
				"link" 		=> "https://panel.encom.app/@#report_transactions",
				"company" 	=> $companyId,
				"date" 		=> $rangeDate
				];

		insertNotifications($ops);
	}
	
}else{
	echo 'nothing found';
}

dai();

?>