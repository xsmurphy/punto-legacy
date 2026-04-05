<?php
include_once './cronHead.php';

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

if($get['debug']){
	$in = '10'; //PARA EL TEST
}

if($dateDecided == 'today'){
	$rangeDate 	= date('Y-m-d');
	$alert  	= 'Hoy vencen facturas pendientes de pago';
}else if($dateDecided == 'week'){
	$rangeDate 	= date('Y-m-d', strtotime("+1 week"));
	$alert  	= 'Hay deudas que vencen en 1 semana';
}else if($dateDecided == 'tomorrow'){
	$rangeDate 	= date('Y-m-d', strtotime("+1 day"));
	$alert  	= 'Hay deudas que vencen mañana';
}else if($dateDecided == 'twoDaysAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-2 days"));
	$alert  	= 'Hay deudas que vencieron hace 2 días';
}else if($dateDecided == 'weekAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-1 week"));
	$alert  	= 'Hay deudas que vencieron hace 1 semana';
}else if($dateDecided == 'tenDaysAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-10 days"));
	$alert  	= 'Hay deudas que vencieron hace 10 días';
}else if($dateDecided == 'twoWeeksAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-2 weeks"));
	$alert  	= 'Hay deudas que vencieron hace 2 semanas';
}else if($dateDecided == 'monthAgo'){
	$rangeDate 	= date('Y-m-d', strtotime("-1 month"));
	$alert  	= 'Hay deudas que vencieron hace 1 mes';
}

$sql = 'SELECT 	companyId as company
		FROM transaction
		WHERE companyId IN(' . $in . ')
			AND transactionDueDate 
		BETWEEN "' . $rangeDate . ' 00:00:00" 
			AND "' . $rangeDate . ' 23:59:59" 
			AND transactionComplete < 1 
			AND transactionType = 4
		GROUP BY companyId
		LIMIT 20000';

$tomorrowDue = ncmExecute($sql,[],false,true);

if($tomorrowDue){

	while (!$tomorrowDue->EOF) {
		$fields 	= $tomorrowDue->fields;

		$ops = [
				"title" 	=> "Cuentas por pagar",
				"message" 	=> $alert . ' clic para ver el listado',
				"type" 		=> 1,
				"link" 		=> 'https://panel.encom.app/@#report_open_invoices?state=outcome&fltr=' . niceDate($rangeDate),
				"company" 	=> $fields['company'],
				"email" 	=> true
			];

			insertNotifications($ops);
		$tomorrowDue->MoveNext();
	}
}

dai();
?>