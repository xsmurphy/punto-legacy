<?php
include_once './cronHead.php';


die();

$meta['subject'] = '[' . APP_NAME . ']';
$meta['to']      = 'drahgster@gmail.com';
$meta['fromName']= APP_NAME;
$meta['data']    = [
                    "message"     => 'Hellooo wusup',
                    "companyname" => APP_NAME,
                    "companylogo" => '/assets/150-150/0/J9.jpg'
                	];

sendEmails($meta);

mail('drahgster@gmail.com','Hello Chris','This is a follow up man? whatsup');
die('Email enviado a drahgster@gmail.com');

//empresas que su fecha de vencimiento sea igual o menor que hoy, si el dia de prueba no exitosa es igual a la fecha de vencimiento + 5 dias, rebajoj al free, anulo la venta

$result = ncmExecute("SELECT * FROM transaction WHERE transactionDueDate <= ? AND transactionType = 3 AND transactionComplete = 0 AND companyId = ?", [date('Y-m-d 23:00:00'),ENCOM_COMPANY_ID], false, true);

$expires 	= [];
$email 		= [];
$c 			= 0;
$e 			= 0;

$allCompanies 		= getAllPayingCompaniesData();
$allowedCompanies 	= ncmExecute('SELECT STRING_AGG(companyId::text, \',\') as ids FROM company WHERE plan IN(5,1,2) GROUP BY status');

//$db->Execute('UPDATE company SET planExpired = NULL WHERE companyId IN(' . $allowedCompanies['ids'] . ')'); //reseteo todos los expired warnings y luego vuelvo a poner si es que no pago
  
while (!$result->EOF) {
	$fields 	= $result->fields;
	$id 		= $fields['customerId'];

	$db->Execute("UPDATE company SET planExpired = 1 WHERE config->>'encomSettingId' = ?",[$id]); //reseteo todos los expired warnings y luego vuelvo a poner si es que no pago

	//ACA VER DE HACER QUE VERIFIQUE SI TIENE CREDITO INTERNO Y USE ESO PARA PAGAR

	/*
	$balance	= $allCompanies[$id]['balance'];
	$discount	= $allCompanies[$id]['discount'];
	$expires	= $allCompanies[$id]['expires'];
	$where 		.= $id.',';
	
	$total 		= $fields['transactionTotal']-$fields['transactionDiscount'];

	$newExpiring = date('Y-m-d 00:00:00', strtotime($expires.' +1 month'));

	if($total > 0){
		if($balance >= $total){
			//descuento el balance y set la nueva fecha de vencimiento
			$db->Execute('UPDATE company SET balance = balance-'.$total.', expiresAt = "'.$newExpiring.'" WHERE companyId = ?',array($id));

			//inserto pago total a venta a credito
			$record['transactionTotal']       = $total; //total sale amount
		    $record['transactionType']        = '5'; //pago credito
		    $record['transactionComplete']    = 1;
		    $record['transactionParentId']    = $fields['transactionId'];

		    $record['customerId']       = $id;
		    $record['userId']           = INCOME_USER_ID;
		    $record['outletId']         = INCOME_OUTLET_ID;
		    $record['registerId']       = INCOME_REGISTER_ID;
		    $record['companyId']        = INCOME_COMPANY_ID;
		    
		    $db->AutoExecute('transaction', $record, 'INSERT');

		    //set la venta a credito como finalizada
		    $db->Execute('UPDATE transaction SET transactionComplete = 1 WHERE transactionId = ?',array($fields['transactionId']));
		    //set flag a 0 para que no muestre alertas de vencimiento
		    $db->Execute('UPDATE company SET planExpired = 0 WHERE companyId = ?',array($id));

			//success email
			$subject 	= 'Plan Renovado';
			$sub 		= array(":total"=>array($total));
			$template 	= "9f8ce200-803d-46c4-847b-5f9c162db288";				
		}else{
			$after5Days = date('Y-m-d 00:00:00', strtotime($fields['transactionDueDate'].' +5 day'));
			if(time() >= strtotime($after5Days)){ //si pasaron 5 dias sin pagar
				//account downgraded si tenía descuento dejo en 0
				$db->Execute('UPDATE company SET plan = 0, discount = 0 WHERE companyId = ?',array($id));

				//Anulo la venta a credito
				$db->Execute('UPDATE transaction SET transactionType = 7 WHERE transactionId = ?',array($fields['transactionId']));
				//set flag a 0 para que no muestre alertas de vencimiento
				$db->Execute('UPDATE company SET planExpired = 0 WHERE companyId = ?',array($id));

				$subject 	= 'Su plan fue cancelado';
				$sub 		= array(":total"=>array($total));
				$template 	= "40e81a89-bea0-4eaf-a0a6-d31cc84a0862";

			}else{ //si aun no pasaron 5 dias
				//activo este flag para que muestre en el panel alertas de que vencerá el plan
				$db->Execute('UPDATE company SET planExpired = 1 WHERE companyId = ?',array($id));
				//failed email 
				$subject 	= 'Alerta, no pudimos renovar su plan';
				$sub 		= array(":duedate"=>array(nicedate($fields['transactionDueDate'])),":total"=>array($total),":balance"=>array($balance));
				$template 	= "0c10ba01-933f-4ca3-ba29-a218640ca03d";
			}
		}
	}else{
		//set la nueva fecha de vencimiento
		$db->Execute('UPDATE company SET expiresAt = "'.$newExpiring.'" WHERE companyId = ?',array($id));

		//inserto pago total a venta a credito
		$record['transactionTotal']       = $total; //total sale amount
	    $record['transactionType']        = '5'; //pago credito
	    $record['transactionComplete']    = 1;
	    $record['transactionParentId']    = $fields['transactionId'];

	    $record['customerId']       = $id;
	    $record['userId']           = INCOME_USER_ID;
	    $record['outletId']         = INCOME_OUTLET_ID;
	    $record['companyId']        = INCOME_COMPANY_ID;
	    
	    $db->AutoExecute('transaction', $record, 'INSERT');

	    //set la venta a credito como finalizada
	    $db->Execute('UPDATE transaction SET transactionComplete = 1 WHERE transactionId = ?',array($fields['transactionId']));

		//success email
		$subject 	= 'Plan Renovado';
		$sub 		= array(":total"=>array($total));
		$template 	= "9f8ce200-803d-46c4-847b-5f9c162db288";
	}

	$email[$id]['sub'] 		= $sub;
	$email[$id]['template'] = $template;
	$email[$id]['subject'] 	= $subject;
	
	$c++;*/
	$result->MoveNext(); 
}

/*
if($result->RecordCount() > 0){
	
	$user = $db->Execute("SELECT contactEmail, companyId FROM contact WHERE role = 1 AND type = 0 AND companyId IN('.rtrim($where,',').')");
	while (!$user->EOF) {
		$compId 	= $user->fields['companyId'];
		
		$options 	= json_encode(array(
				              "to"=> array($user->fields['contactEmail']),
				              "sub"=> $email[$compId]['sub'],
				              "filters"=> array(
				                        "templates"=>array(
				                                  "settings"=>array("enable"=>1,"template_id"=>$email[$compId]['template'])
				                                )
				                      )
				              ));
	   	sendSMTPEmail($options,$user->fields['contactEmail'],$email[$compId]['subject'],'Income','Income');
	   	$e++;
	   $user->MoveNext(); 
	}

	$user->Close();
}
*/

dai();
?>