<?php
include_once './cronHead.php';

$allowedPlans 	= '1,2,4';
$in 			= [];
$companyData 	= [];
$fullData 		= [];

$dateDecided 	= $get['d'];

function sendEmailToUsers($emails, $conf){
	
	$body 	= 	$conf['contactName'] . ' tiene cita ' . $conf['dateName'] . 
				' (' . $conf['niceDate'] . ').' .
				'<br> <a href="' . $conf['url'] . '">Ver detalles</a>';

    $meta['subject'] 	= '[' . $conf['companyName'] . '] Recordatorio';
	$meta['fromName']	= $conf['companyName'];
	$meta['data']    	= 	[
			                    "message"     => $body,
			                    "companyname" => $conf['companyName']
			                ];

	foreach ($emails as $key => $value) {
		$meta['to']      = $value;
		sendEmails($meta);	
	}
}

$company = ncmExecute("SELECT
							a.companyId as id,
							a.smsCredit,
							b.settingName as name,
							b.settingCountry as country
						FROM company a, setting b
						WHERE a.status = 'Active'
						AND	a.plan IN (" . $allowedPlans . ") 
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

//$in = '10';

if($dateDecided == 'today'){
	$dateName 	= 'hoy';
	$rangeDate 	= date('Y-m-d');
}else if($dateDecided == 'week'){
	$rangeDate 	= date('Y-m-d', strtotime("+1 week"));
	$dateName 	= 'el';
}else{
	$rangeDate 	= date('Y-m-d', strtotime("+1 day"));
	$dateName 	= 'mañana';
}

$sql = 'SELECT 	companyId as company, 
				customerId as uid,
				transactionId as id
		FROM transaction
		WHERE companyId IN(' . $in . ')
			AND fromDate 
		BETWEEN "' . $rangeDate . ' 00:00:00" 
			AND "' . $rangeDate . ' 23:59:59" 
			AND transactionStatus IN(0,1)
			AND transactionType = 13
			AND customerId > 0 AND customerId IS NOT NULL 
		LIMIT 10000';

$tomorrowDue = ncmExecute($sql,[],false,true);

if($tomorrowDue){
	$inC 	= [];
	$trsC 	= [];
	while (!$tomorrowDue->EOF) {
		$fields 	= $tomorrowDue->fields;
		if(!in_array($fields['uid'], $inC)){
			$inC[] 					= $fields['uid'];
			$trsC[$fields['uid']] 	= $fields['id'];
		}
		$tomorrowDue->MoveNext();
	}

	//print_r($trsC);
	//die();

	if(validity($inC,'array')){
		$inC = implodes(',', $inC);

		$sql = 'SELECT contactId as uid,
				contactEmail as email,
				contactPhone as phone,
				contactPhone2 as phone2,
				contactName as name,
				contactSecondName as secondName,
				companyId as company
			FROM contact
			WHERE contactId IN(' . $inC . ')
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

			$users = ncmExecute("SELECT STRING_AGG(contactEmail, ',') AS emails FROM contact WHERE companyId = ? AND type = 0 AND (contactEmail IS NOT NUll AND contactEmail != '')");

			while (!$contacts->EOF) {
				$fields 	= $contacts->fields;

				$name 		= ucwords( strtolower(getCustomerName($fields)) );//si no pongo el segundo campo me trae nombre y apellido
				$phone     	= iftn($fields['phone'],$fields['phone2']);
				$transID 	= $trsC[$fields['uid']];
				$url        = getShortURL('/screens/scheduleConfirm?s=' . base64_encode(enc($transID).','.enc($fields['company'])));
				$compName 	= $companyData[$fields['company']]['name'];
				$compCountry= $companyData[$fields['company']]['country'];
				$smsCredit 	= $companyData[$fields['company']]['sms'];
				$compLogo 	= '/assets/150-150/0/' . enc($fields['company']) . '.jpg';

				$subject= '[' . $compName . '] Recordatorio';
				$body 	= 	$name . 
							', <br> Le recordamos su asistencia ' . $dateName . 
							' (' . $nicedate . ').' .
							'<br> <a href="' . $url . '">Ver detalles</a>' .
							'<br><br> Saludos cordiales.';

				$bodySMS = 	'[' . $compName . '] ' . 
							$name . ',' . 
							' le recordamos su asistencia ' . $dateName . 
							' (' . $nicedate . ').' .
							' Detalles: \n' . $url;


			    $meta['subject'] = $subject;
				$meta['to']      = $fields['email'];
				$meta['fromName']= $compName;
				$meta['data']    = [
					                    "message"     => $body,
					                    "companyname" => $compName,
					                    "companylogo" => $compLogo
					                ];

				sendEmails($meta);
				sendSMS($phone,$bodySMS,$compCountry,$smsCredit);


				//send emails to users

				sendEmailToUsers( explodes($users), ['companyName' => $compName, 'dateName' => $dateName, 'niceDate' => $nicedate, 'contactName' => $name] );


				$transOutlet = ncmExecute('SELECT outletId FROM transaction WHERE transactionId = ?',[$transID]);
				$ops = [
							"title" 	=> "Recordatorio de citas",
							"message" 	=> $name . ' tiene cita hoy, clic para ver los detalles',
							"type" 		=> 0,
							"link" 		=> $url,
							"outletId"  => $transOutlet['outletId'],
							"company" 	=> $fields['company']
						];

				insertNotifications($ops);

				$contacts->MoveNext();
			}
		}
	
	}
}

dai();
?>