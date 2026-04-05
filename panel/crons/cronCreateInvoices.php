<?php
die();
if(!$_GET['a'] || $_GET['a'] != NCM_SECRET){
	die('I died');
}

die();

include_once("../libraries/adodb/adodb.inc.php");
$db = ADONewConnection('mysqli');
//$db->debug = true;
$db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_905');

include_once('../includes/simple.config.php');
include_once("../libraries/hashid.php");
include_once("../includes/config.php");
include_once("../languages/".LANGUAGE.".php");
include_once("../includes/functions.php");
require_once('../libraries/phpmailer/PHPMailerAutoload.php');

//selecciono todas las empresas en que su fecha de expiración es dentro de 5 días

$result = $db->Execute("SELECT * FROM company WHERE companyExpiringDate = '".date('Y-m-d 00:00:00', strtotime('+5 day'))."' AND companyPlan IN (1,2,5,7)");

$expires = array();
$c = 0;
$e = 0;
while (!$result->EOF) {
	$id 			= $result->fields['companyId'];
	$cdiscount		= $result->fields['companyDiscount'];
	$cexpires		= $result->fields['companyExpiringDate'];
	$planId 		= $result->fields['companyPlan'];
	$where 			.= $id.',';
	$expires[$id] 	= $cexpires;

	//genero la venta en la db

	//estos ids son los ids de los productos de Income
	if($planId == 1){//company
    	$itemId = PLAN_COMPANY_ID;
    }else if($planId == 2){
    	$itemId = PLAN_FULL_ID;
    }else if($planId == 5){
    	$itemId = PLAN_STARTER_ID;
    }else if($planId == 7){
    	$itemId = PLAN_MICRO_ID;
    }

    if($planId == 1 || $planId == 2){
		//obtengo el plan de la empresa
		$outlets 	= $db->Execute("SELECT COUNT(outletId) as count FROM outlet WHERE companyId = ?",array($id));
		$oCount 	= $outlets->fields['count'];
		$outlets->Close();
	}else{
		$oCount 	= 1;
	}

	$plan 		= $plansValues[$planId]['price'];
	$total 		= ($plan*$oCount);

	$detail = '[{"itemId":"'.enc($itemId).'","uId":"","name":"'.getItemName($itemId).'","uniPrice":'.$plan.',"count":'.$oCount.',"discount":0,"discAmount":0,"totalDiscount":0,"price":'.$plan.',"tax":10,"note":"","type":"product","total":'.$total.'}]';

    $record['transactionTotal']       = $total; //total sale amount
    $record['transactionUnitsSold']   = $oCount;
    $record['transactionDiscount']    = $cdiscount;
    $record['transactionTax']    	  = addTax(10,$total);
    $record['transactionDueDate']     = $cexpires;
    $record['transactionType']        = '3'; //credito
    $record['transactionComplete']    = 0;
    $record['transactionDetails']     = $detail;

    $record['customerId']       = $id;
    $record['userId']           = INCOME_USER_ID;
    $record['outletId']         = INCOME_OUTLET_ID;
    $record['companyId']        = INCOME_COMPANY_ID;

    // print_r($record);
    
    $db->AutoExecute('transaction', $record, 'INSERT');
    $transID                    = $db->Insert_ID();

    //item sold
	$records['itemSoldTotal']     = $plan;
	$records['itemSoldTax']       = addTax(10,$total);
	$records['itemSoldUnits']     = $oCount;
	$records['itemId']            = $itemId;
	$records['transactionId']     = $transID;

	$db->AutoExecute('itemSold', $records, 'INSERT');

	// print_r($records);

	//
	$c++;
	$result->MoveNext(); 
}

if(validateResultFromDB($result)){
	
	$user = $db->Execute("SELECT contactEmail, companyId FROM contact WHERE role = 1 AND type = 0 AND companyId IN (".rtrim($where,',').")");
	while (!$user->EOF) {
		$mail 		= $user->fields['contactEmail'];
		$compId 	= $user->fields['companyId'];
		$expDate 	= niceDate($expires[$compId]);

	   	$options = json_encode(array(
	              "to"=> array($mail),
	              "sub"=> array(
              		":email"=>array($mail),
              		":duedate"=>array($expDate)
              	   ),
	              "filters"=> array(
	                        "templates"=>array(
	                                  "settings"=>array("enable"=>1,"template_id"=>"98dd9a94-1c09-4f10-9da1-643e124aa74d")
	                                )
	                      )
	              ));
	   	// print_r($options);
		sendSMTPEmail($options,$user->fields['contactEmail'],'Factura generada','Income','Income');
	   	
	   //	echo $options;
		$e++;
		$user->MoveNext(); 
	}

	$user->Close();
}

$file = basename(__FILE__, '.php');
$sub = 'Cron  '.$file.' processed successfully';
$nueva = $sub.' \n '.$c.' empresas procesadas y '.$e.' emails enviados';
sendEmail('drahgster+ncm.cronjob@gmail.com',$sub,$nueva,$nueva,'info@incomepos.com',false);


dai();
?>