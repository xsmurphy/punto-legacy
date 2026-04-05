<?php
include_once './cronHead.php';

theErrorHandler('json');

$allowedPlans 	= '1,2,4';
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

if($_GET['debug']){
	$in = '10'; //PARA EL TEST
}

if($dateDecided == 'today'){
	$rangeDate 	= date('Y-m-d');
	$notice 	= 'Le recordamos que hoy vence su deuda.';
	$alert  	= '';
}else if($dateDecided == 'week'){
	$rangeDate 	= date('Y-m-d', strtotime("+1 week"));
	$notice 	= 'Le recordamos que tiene deudas por vencer.';
	$alert  	= '';
}else if($dateDecided == 'tomorrow'){
	$rangeDate 	= date('Y-m-d', strtotime("+1 day"));
	$notice 	= 'Le recordamos que mañana vence su deuda.';
	$alert  	= '';
}else if($dateDecided == 'twoDaysAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-2 days"));
	$notice 	= 'Le recordamos que posee deudas pendientes de pago.';
	$alert  	= ' aún posee deudas pendientes de pago.';
}else if($dateDecided == 'weekAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-1 week"));
	$notice 	= 'Le recordamos que posee deudas pendientes de pago.';
	$alert  	= ' aún posee deudas pendientes de pago.';
}else if($dateDecided == 'tenDaysAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-10 days"));
	$notice 	= 'Le recordamos que posee deudas pendientes de pago.';
	$alert  	= ' aún posee deudas pendientes de pago.';
}else if($dateDecided == 'twoWeeksAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-2 weeks"));
	$notice 	= 'Le recordamos que posee deudas pendientes de pago.';
	$alert  	= ' aún posee deudas pendientes de pago.';
}else if($dateDecided == 'monthAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-1 month"));
	$notice 	= 'Le recordamos que posee deudas pendientes de pago.';
	$alert  	= ' aún posee deudas pendientes de pago.';
}

$sql = 'SELECT 	companyId as company, 
				customerId as uid
		FROM transaction
		WHERE companyId IN(' . $in . ')
			AND transactionDueDate 
		BETWEEN "' . $rangeDate . ' 00:00:00" 
			AND "' . $rangeDate . ' 23:59:59" 
			AND transactionComplete < 1 
			AND transactionType = 3
			AND transactionTotal > 0
			AND customerId > 0 AND customerId IS NOT NULL 
		LIMIT 10000';

$tomorrowDue = ncmExecute($sql,[],false,true);

if($tomorrowDue){
	$inC = [];
	while (!$tomorrowDue->EOF) {
		$fields 	= $tomorrowDue->fields;
		if(!in_array($fields['uid'], $inC)){
			$inC[] 		= $fields['uid'];
		}
		$tomorrowDue->MoveNext();
	}

	if(validity($inC,'array')){
		$inC = implodes(',', $inC);

		$sql = 'SELECT contactUID as uid,
				contactEmail as email,
				contactPhone as phone,
				contactPhone2 as phone2,
				contactName as name,
				contactSecondName as secondName,
				companyId as company
			FROM contact
			WHERE contactUID IN(' . $inC . ')
			AND (
					(contactEmail != "" AND contactEmail IS NOT NULL)
					OR
					(contactPhone != "" AND contactPhone IS NOT NULL)
					OR
					(contactPhone2 != "" AND contactPhone2 IS NOT NULL)
				)
			LIMIT 10000';

		$contacts = ncmExecute($sql,[],false,true);

		$nicedate = niceDate($rangeDate);

		if($contacts){
			//La notificación se envia solo una vez por que el loop pasa una vez por contacto
			while (!$contacts->EOF) {
				$fields 	= $contacts->fields;

				$name 		= ucwords( strtolower(getCustomerName($fields)) );//si no pongo el segundo campo me trae nombre y apellido
				$phone     	= iftn($fields['phone'],$fields['phone2']);
				$url    	= getShortURL('https://panel.encom.app/standalone/customerAccountStatus?s=' . base64_encode(enc($fields['company']) . ',' . enc($fields['uid'])) );
				$compName 	= $companyData[$fields['company']]['name'];
				$compCountry= $companyData[$fields['company']]['country'];
				$smsCredit 	= ($companyData[$fields['company']]['sms'] > 1) ? $companyData[$fields['company']]['sms'] : 0;
				$compLogo 	= 'https://assets.encom.app/150-150/0/' . enc($fields['company']) . '.jpg';

				$subject 	= 	'[' . $compName . '] Vencimiento de pagos';
				$body 	 	= 	$name . 
								'. <br> ' . 
								$notice . 
								'<br> <a href="' . $url . '">Acceda a su estado de cuenta</a>' .
								'<br><br> Saludos cordiales.';

				$bodySMS 	= 	'[' . $compName . '] ' . 
								$name . '. ' . 
								$notice .
								'\n Su estado de cuenta: ' . $url;


			    $meta['subject'] = $subject;
				$meta['to']      = $fields['email'];
				$meta['fromName']= $compName;
				$meta['data']    = [
				                    "message"     => $body,
				                    "companyname" => $compName,
				                    "companylogo" => $compLogo
				                	];


				$dunning = ncmExecute('SELECT dunning FROM module WHERE companyId = ?',[$fields['company']]);

				if($dunning && $dunning['dunning']){
					sendEmails($meta);
					sendSMS($phone,$bodySMS,$compCountry,$smsCredit);
				}

				$ops = [
					"title" 	=> "Deudas vencidas de clientes",
					"message" 	=> $name . $alert . ' Clic para ver su estado de cuenta.',
					"type" 		=> 1,
					"link" 		=> $url,
					"company" 	=> $fields['company'],
					"email" 	=> 1
				];

				if($alert){
					insertNotifications($ops);
				}

				$contacts->MoveNext();
			}
		}
	
	}
}

dai();
?>