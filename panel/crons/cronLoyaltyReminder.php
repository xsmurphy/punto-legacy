<?php
include_once './cronHead.php';

if($_GET['testsms']){
	define('API_KEY', 'cc58c3ead1b111d48f5c0d677765f362e2a55598');
	define('COMPANY_ID', '10');
	print_r(sendSMS('0991742353','ENCOM test','PY',100));
	die();
}

$allowedPlans 	= '1,2,4';
$in 			= [];
$companyData 	= [];

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

$in 		= '10'; //PARA EL TEST

$rangeDate 	= date('Y-m-d');

/*
##############
[Roquetas] Fulano, seguí acumulando puntos que luego podrás utilizar como medio de pago. Ver puntos: LINK

#############
*/


$sql 		= 'SELECT contactId as uid,
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
		$compLogo 	= '/assets/150-150/0/' . enc($fields['company']) . '.jpg';

		$subject	= '[' . $compName . '] Que los cumplas feliz!';

		$bodySMS 	= 	'[' . $compName . '] ' . 
						$name . ',' . 
						' Desde ' . $compName . 
						' te deseamos feliz cumpleaños y que tengas un maravilloso año!';

		$meta      = [
	                  ":message"      => [''],
	                  ":companyname"  => [$compName],
	                  ":customername" => [$name],
	                  ":companylogo"  => [$compLogo]
	                 ];

		sendSMS($phone,$bodySMS,$compCountry,$smsCredit);

		$contacts->MoveNext();
	}
}

dai();
?>