<?php

include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");

//aqui voy a obtener todos los datos que envia 2CO por POST para saber como ir procesando las ventas para comparar y poder saber a que factura corresponde el cobro

if($_REQUEST){
	$all = json_encode($_REQUEST);

	//206482087157
	$mType 	= $_POST['message_type'];
	$amount = $_POST['item_usd_amount_1'];
	$saleId = $_POST['sale_id'];

	if(!$saleId){dai();}

	$result = $db->Execute('SELECT companyId FROM cpayments WHERE cpaymentsOrder = ? ORDER BY cpaymentsId DESC LIMIT 1 ',array($saleId));
	if(!$result->RecordCount()){dai();}
	$companyId = $result->fields['companyId'];

	if($mType === 'RECURRING_INSTALLMENT_SUCCESS'){
		acceptCompanyPayment($amount,$companyId);
	}else if($mType === 'RECURRING_INSTALLMENT_FAILED' || $mType === 'RECURRING_STOPPED' || $mType === 'RECURRING_COMPLETE'){
		$db->Execute('UPDATE company SET planExpired = 1 WHERE companyId = ?',array($companyId));
	}

	$compName = getValue('setting', 'settingName', 'WHERE companyId = '.$companyId);

	sendEmail('drahgster@gmail.com', $compName.' '.$mType, $compName.': '.$mType.' '.$all, $compName.' '.$mType);

	$record = array();
	$record['taxonomyExtra']	= $all;
	$record['taxonomyType'] 	= '2COrecurring';
	$record['taxonomyName'] 	= '2CheckoutRecurring';

	$insert = $db->AutoExecute('taxonomy', $record, 'INSERT'); 

}else{
	$record = array();
	$record['taxonomyExtra']	= 'no data';
	$record['taxonomyType'] 	= '2COrecurring';
	$record['taxonomyName'] 	= '2CheckoutRecurring';

	$insert = $db->AutoExecute('taxonomy', $record, 'INSERT'); 

	if($insert === true){
		echo 'Thanks!';
	}else{
		echo 'error';
	}

	dai('No data');
}
?>