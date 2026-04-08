<?php
include_once './cronHead.php';

$allowedPlans 	= '1,2,4';
$in 			= [];
$companyData 	= [];
$fullData 		= [];

$dateDecided 	= $get['d'];

$company = ncmExecute("SELECT
							companyId as id,
							smsCredit,
							config->>'settingName'    as name,
							config->>'settingCountry' as country
						FROM company
						WHERE status = 'active'
						AND plan IN (" . $allowedPlans . ")
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

$field 		= '';

if($dateDecided == 'yesterday'){
	$rangeDate 	= date('Y-m-d',strtotime("-1 days"));
	$field 		= 'salesSummaryDaily';
}else if($dateDecided == 'week'){
	$rangeDate 	= date('Y-m-d', strtotime("-1 week"));
}

//veo si tioene habilitado el envio de reportes

$_modules = ncmExecute("SELECT * FROM company WHERE (config->>'" . $field . "')::int > 0 AND companyId IN(" . $in . ') LIMIT 10000',[],false,true);

$dia    = $dias[date('w',strtotime($rangeDate))] . ' ' . date('d',strtotime($rangeDate));
$mes    = $meses[date('n',strtotime($rangeDate)) - 1];
$ano    = date('Y',strtotime($rangeDate));
$literalDate = $dia . ' de ' . $mes . ', ' . $ano;

if($_modules){
	while (!$_modules->EOF) {
		$module = $_modules->fields;

		$contacts = ncmExecute('SELECT * FROM contact WHERE type = 0 AND role = 1 AND companyId = ? LIMIT 50',[$module['companyId']],false,true);

		if($contacts){
			//La notificación se envia solo una vez por que el loop pasa una vez por contacto
			while (!$contacts->EOF) {
				$fields 	= $contacts->fields;

				$name 		= $fields['contactName'];
				$url    	= '/screens/statusSummary?s=' . base64_encode(enc($module['companyId']) . ',' . $rangeDate);

				$compName 	= $companyData[$module['companyId']]['name'];
				$compLogo 	= '/assets/150-150/0/' . enc($module['companyId']) . '.jpg';

				$subject 	= 	'Resumen del ' . $literalDate;
				$body 	 	= 	'Puede acceder al resumen del ' . $literalDate . ' en: <br> ' . 
								'<br> <a href="' . $url . '">Ver resumen</a>';

			    $meta['subject'] = $subject;
				$meta['to']      = $fields['contactEmail'];
				$meta['fromName']= APP_NAME;
				$meta['data']    = [
				                    "message"     => $body,
				                    "companyname" => $compName,
				                    "companylogo" => $compLogo
				                	];

				sendEmails($meta);

				$contacts->MoveNext();
			}
		}
		$_modules->MoveNext();
	}
}

dai();
?>