<?php
include_once('api_head.php');

$field 			= ncmExecute('SELECT * FROM setting WHERE companyId = ? LIMIT 1',[COMPANY_ID], true);
$comp 			= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$_modules		= ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$jsonResult 	= [];
 
if($field){
	$jsonResult = 	[
							"name" 				=> toUTF8($field['settingName']),
							"logo" 				=> 'https://assets.encom.app/250-250/0/' . enc(COMPANY_ID) . '.jpg',
							"email" 			=> $field['settingEmail'],
							"address" 			=> toUTF8($field['settingAddress']),
							"phone" 			=> $field['settingPhone'],
							"billingName"		=> toUTF8($field['settingBillingName']),
							"tin" 				=> $field['settingRUC'],
							"city" 				=> toUTF8($field['settingCity']),
							"country" 			=> $field['settingCountry'],
							"currency" 			=> $field['settingCurrency'],
							"language" 			=> $field['settingLanguage'],
							"timeZone" 			=> $field['settingTimeZone'],
							"taxName" 			=> $field['settingTaxName'],
							"decimal" 			=> $field['settingDecimal'],
							"thousandS"			=> $field['settingThousandSeparator'],
							"sellSoldOut"		=> $field['settingSellSoldOut'],
							"openFrom"			=> $field['settingOpenFrom'],
							"openTo"			=> $field['settingOpenTo'],
							"social"			=> json_decode($field['settingSocialMedia'], true),
							"tinName"			=> $field['settingTIN'],

							"plan" 				=> $comp['companyPlan'],
							"status" 			=> $comp['companyStatus'],
							"created" 			=> $comp['companyDate'],
							"sms" 				=> $comp['companySMSCredit'],
							"blocked" 			=> $field['settingBlocked'],

							"modules" 			=> [

														"vpos" 				=> $_modules['vpos'],
														"ecom" 				=> $_modules['ecom'],
														"dropbox" 			=> $_modules['dropbox'],
														"spotify" 			=> $_modules['spotify'],
														"loyalty" 			=> $_modules['loyalty'],
														"feedback" 			=> $_modules['feedback'],
														"calendar" 			=> $_modules['calendar'],
														"spaces" 			=> $_modules['tables'],
														"production" 		=> $_modules['production'],
														"kds" 				=> $_modules['kds'],
														"ordersPanel" 		=> $_modules['ordersPanel'],
														"recurring" 		=> $_modules['recurring'],
														"dunning" 			=> $_modules['dunning'],
														"digitalInvoice" 	=> $_modules['digitalInvoice'],
														"tusFacturas" 		=> $_modules['tusfacturas'],
														"salesSummaryDaily" => $_modules['salesSummaryDaily']

													]
						];	
}

jsonDieResult($jsonResult,200);
?>