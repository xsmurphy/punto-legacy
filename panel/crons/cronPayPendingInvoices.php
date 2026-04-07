<?php
include_once './cronHead.php';

$allowedPlans 	= '1,2,4';
$in 			= [];
$companyData 	= [];
$fullData 		= [];

$dateDecided 	= $get['d'];

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

$in = '10'; //PARA EL TEST

$rangeDate 	= date('Y-m-d');

$sql = 'SELECT *
			FROM contact
			WHERE contactStoreCredit > 0
			AND companyId IN(' . $in . ')';

$contacts 	= ncmExecute($sql,[],false,true);

if($contacts){
	$inC = [];
	while (!$contacts->EOF) {
		$fields 	= $contacts->fields;
		if(!in_array($fields['contactId'], $inC)){
			$inC[] 	= $fields['contactId'];
		}
		$contacts->MoveNext();
	}
}

$sql = 'SELECT a.contactId as uid, a.contactStoreCredit as credit, b.transactionTotal as debt
		FROM contact a, transaction b
		WHERE a.companyId IN(' . $in . ')
		AND a.contactStoreCredit > 0
		AND a.contactId = b.customerId
		AND b.transactionComplete < 1 
		AND b.transactionType = 3
		LIMIT 1000';

$transactions = ncmExecute($sql,[],false,true);

if($transactions){
	
}



if($tomorrowDue){
	$inC = [];
	while (!$tomorrowDue->EOF) {
		$fields 	= $tomorrowDue->fields;
		if(!in_array($fields['customerId'], $inC)){
			$inC[] 	= $fields['customerId'];
		}
		$tomorrowDue->MoveNext();
	}

	if(validity($inC,'array')){
		$inC = implodes(',', $inC);

		$sql = 'SELECT contactId as uid,
				contactEmail as email,
				contactPhone as phone,
				contactPhone2 as phone2,
				contactName as name,
				contactSecondName as secondName,
				companyId as company,
				contactStoreCredit as credit
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
			//La notificación se envia solo una vez por que el loop pasa una vez por contacto
			while (!$contacts->EOF) {
				$fields 	= $contacts->fields;

				$name 		= ucwords( strtolower(getCustomerName($fields)) );//si no pongo el segundo campo me trae nombre y apellido
				$phone     	= iftn($fields['phone'],$fields['phone2']);
				$url    	= getShortURL('/screens/customerAccountStatus?s=' . base64_encode(enc($fields['company']) . ',' . enc($fields['uid'])) );
				$compName 	= $companyData[$fields['company']]['name'];
				$compCountry= $companyData[$fields['company']]['country'];
				$smsCredit 	= ($companyData[$fields['company']]['sms'] > 1) ? $companyData[$fields['company']]['sms'] : 0;
				$compLogo 	= '/assets/150-150/0/' . enc($fields['company']) . '.jpg';

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

				sendEmails($meta);

				sendSMS($phone,$bodySMS,$compCountry,$smsCredit);

				$contacts->MoveNext();
			}
		}
	
	}
}

dai();

$file = basename(__FILE__, '.php');
$sub = 'Cron  '.$file.' processed successfully';
$nueva = $sub.' \n '.$c.' empresas procesadas y '.$e.' emails enviados';
sendEmail('drahgster+ncm.cronjob@gmail.com',$sub,$nueva,$nueva,'info@incomepos.com',false);

dai();
?>