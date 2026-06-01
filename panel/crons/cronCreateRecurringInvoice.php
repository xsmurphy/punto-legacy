<?php
include_once './cronHead.php';
// JWT de servicio — necesario para que action.php (que requiere jwtAuthenticate)
// acepte la re-submisión de la venta recurrente. El cron no tiene sesión de
// usuario, por lo que mintea un JWT de corta vida (2min) por cada venta.
require_once __DIR__ . '/../../app/includes/jwt.php';

$allowedPlans 	= '1,2,4,8,9,10';
$in 			= [];
$companyData 	= [];
$fullData 		= [];
$dateDecided 	= $get['d'];

$company = ncmExecute("SELECT
							a.companyId as id,
							a.smsCredit,
							a.config->>'settingName' as name,
							a.config->>'settingCountry' as country
						FROM company a
						WHERE a.status = 'Active'
						AND	a.plan IN (" . $allowedPlans . ")
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

// PG: quote each UUID for IN clause; recurringSaleData vive en data JSONB → extraer via SQL alias.
$inQ = implode(',', array_map(fn($id) => "'" . $id . "'", explode(',', $in)));
$sql = "SELECT *, data->>'recurringSaleData' AS recurringSaleData
		FROM recurring
		WHERE companyId IN({$inQ})
			AND recurringNextDate = ?
			AND recurringEndDate != ?
			AND recurringStatus = 1
		LIMIT 50000";

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

		$url 		= '/action?l=' . base64_encode($fields['recurringTransactionData']);

		// action.php requiere JWT (migración de auth). El cron no tiene sesión,
		// así que mintea un JWT de servicio de corta vida (2min) por venta, usando
		// el contexto de la suscripción (companyId/outletId/registerId/userId de
		// recurringTransactionData). $_ENV['JWT_SECRET'] viene del mismo .env.
		$jwtHeaders = [];
		$_jwtSecret = $_ENV['JWT_SECRET'] ?? '';
		if ($_jwtSecret !== '') {
			$_serviceJwt = jwtEncode([
				'sub'  => $transData['userId']   ?? '',
				'cid'  => $companyId,
				'oid'  => $transData['outletId']  ?? '',
				'rid'  => $registerId,
				'role' => (int) ($transData['roleId'] ?? 1),
				'exp'  => time() + 120, // 2 min — suficiente para los dos POST
			], $_jwtSecret);
			$jwtHeaders = ['Cookie: _jwt=' . $_serviceJwt];
		}

		curlContents($url, 'POST', $data,        $jwtHeaders); //inserto venta
		curlContents($url, 'POST', $invoiceData, $jwtHeaders); //inserto invoice

		$status = 1;
		if($fields['recurringEndDate'] == $rangeDate){
			$status = 0;
		}

		// recurringSaleData vive en la columna data (jsonb) — no en una columna propia.
		$db->Execute(
			'UPDATE recurring SET recurringNextDate = ?, recurringStatus = ?, data = ? WHERE recurringId = ? AND companyId = ?',
			[
				getNextDatePeriod($frecuency, '1', $fields['recurringNextDate']),
				$status,
				json_encode(['recurringSaleData' => json_encode($sale)]),
				$fields['recurringId'],
				$companyId,
			]
		);

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
				"link" 		=> "/@#report_transactions",
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