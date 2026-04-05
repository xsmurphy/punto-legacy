<?php
include_once './cronHead.php';

//delete all notifications older than 15 days
ncmExecute('DELETE FROM notify WHERE notifyDate < CURRENT_DATE() - INTERVAL 15 DAY');

$allowedPlans 	= '1,2,4,8,9,10';
$in 			= [];
$companyData 	= [];

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

//$in 		= '10'; //PARA EL TEST

$rangeDate 	= date('Y-m-d');

$sql 		= 'SELECT contactUID as uid,
					contactEmail as email,
					contactPhone as phone,
					contactPhone2 as phone2,
					contactName as name,
					contactSecondName as secondName,
					companyId as company
				FROM contact
				WHERE MONTH(contactBirthDay) = MONTH(NOW()) AND DAY(contactBirthDay) = DAY(NOW())
				AND companyId IN(' . $in . ')
				AND (
						(contactEmail != "" AND contactEmail IS NOT NULL)
						OR
						(contactPhone != "" AND contactPhone IS NOT NULL)
						OR
						(contactPhone2 != "" AND contactPhone2 IS NOT NULL)
					)
				LIMIT 10000';

$contacts = ncmExecute($sql,[],false,true);

if($contacts){
	while (!$contacts->EOF) {
		$fields 	= $contacts->fields;

		$name 		= ucwords( strtolower(getCustomerName($fields, 'first')) );//si no pongo el segundo campo me trae nombre y apellido
		$phone     	= iftn($fields['phone'],$fields['phone2']);
		$compName 	= $companyData[$fields['company']]['name'];
		$compCountry= $companyData[$fields['company']]['country'];
		$smsCredit 	= $companyData[$fields['company']]['sms'];
		$compLogo 	= 'https://assets.encom.app/150-150/0/' . enc($fields['company']) . '.jpg';

		$subject	= '[' . $compName . '] Que los cumplas feliz!';

		$bodySMS 	= 	$name . ',' . 
						' Desde ' . $compName . 
						' te deseamos feliz cumpleaños y que tengas un maravilloso año!';

		$htmlBody 	= 	'<div style="color:#3e4249; margin:25px 0; font-size:32px;">' .
						'	<span style="color:#4d5e6e;">¡Feliz Cumpleaños!</span>' . 
						'</div>' .
						'<div style="font-weight:100;">' .
						'	<strong style="font-size:20px;">' . $name . '!</strong>,<br><br>' .
      					'	Desde <strong>' . $compName . '</strong> te deseamos un maravilloso año lleno de salud, felicidad y ¡éxitos! <br><br>'. 
      					'	Que pases genial este día.' .
      					'</div>';
 
	    $meta['subject'] = $subject;
		$meta['to']      = $fields['email'];
		$meta['fromName']= $compName;
		$meta['data']    = [
		                    "message"     => $htmlBody,
		                    "companyname" => $compName,
		                    "companylogo" => $compLogo
		                	];

		sendEmails($meta);
	    
		$sentSMS = sendSMS($phone,$bodySMS,$compCountry,$smsCredit,$fields['company']);
		$sentSMS = str_replace('+', '', $sentSMS);
		$sentSMS = getValidPhone($sentSMS[1],$compCountry);

		$ops = [
				"title" 	=> "Nuevo Cumpleaños!",
				"message" 	=> "Hoy es el cumpleaños de " . $name . "! clic aquí para felicitarle por WhatsApp",
				"type" 		=> 0,
				"email" 	=> 1,
				"link" 		=> "https://wa.me/" . $sentSMS['phone'] . "?text=" . $bodySMS,
				"company" 	=> $fields['company'],
				"push"      => [
                        "tags" => [[
                                        "key"   => "companyId",
                                        "value" => enc($fields['company'])
                                    ],
                                    [
                                        "key"   => "isResource",
                                        "value" => "false"
                                    ]],
                        "where"     => 'caja'
                        ]
				];

		insertNotifications($ops);

		$contacts->MoveNext();
	}
}

dai();
?>