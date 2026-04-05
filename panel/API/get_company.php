<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$_company		= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$_settings		= ncmExecute('SELECT * FROM setting WHERE companyId = ? LIMIT 1',[COMPANY_ID], true);
$_modules		= ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$_outlets		= ncmExecute('SELECT * FROM outlet WHERE companyId = ? AND outletStatus = 1 LIMIT 100',[COMPANY_ID],false,true);
$_countries 	= $countries;
$jsonResult 	= [];
$upsellArray  	= [];
$templatesArray = [];
$outletsArray 	= [];
 
if($_company){

	//upsell
	$upsell       = ncmExecute('SELECT * FROM upsell WHERE companyId = ?',[COMPANY_ID],false,true);
	if($upsell){
	  while (!$upsell->EOF) {
	    $ups      = $upsell->fields;
	    $child    = enc($ups['upsellChildId']);
	    $parent   = enc($ups['upsellParentId']);

	    $upsellArray[$child][] = $parent;

	    $upsell->MoveNext();
	  }

	  $upsell->Close();
	}

	//print templates
	$templates = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'printTemplate' AND (companyId = ? OR companyId = ?)",[COMPANY_ID, 1],false,true);

	if($templates){
		while (!$templates->EOF) {
	        $templatesArray[] = [
		        					'id' 	=> enc($templates->fields['taxonomyId']),
		        					'name' 	=> $templates->fields['taxonomyName'],
		        					'data' 	=> $templates->fields['taxonomyExtra']
	        					];

	        $templates->MoveNext(); 
	    }
	    $templates->Close();
	}

    if($_outlets){
    	while (!$_outlets->EOF) {

    		//registers
    		$registersArray = [];
    		$_registers		= ncmExecute('SELECT * FROM register WHERE companyId = ? AND outletId = ? AND registerStatus = 1 LIMIT 100',[COMPANY_ID, $_outlets->fields['outletId']],false,true);

    		if($_registers){
    			while (!$_registers->EOF) {

    				$registersArray[] = [
    										'ID' 				=> enc($_registers->fields['registerId']),
    										'name' 				=> $_registers->fields['registerName'],
    										'createdAt' 		=> $_registers->fields['registerCreationDate'],
    										'invoiceAuth' 		=> $_registers->fields['registerInvoiceAuth'],
    										'invoiceAuthExp'	=> $_registers->fields['registerInvoiceAuthExpiration'],
    										'prefix' 			=> $_registers->fields['registerInvoicePrefix'],
    										'sufix' 			=> $_registers->fields['registerInvoiceSufix'],
    										'invoiceNo' 		=> $_registers->fields['registerInvoiceNumber'],
    										'sentNo' 			=> $_registers->fields['registerRemitoNumber'],
    										'quoteNo' 			=> $_registers->fields['registerQuoteNumber'],
    										'returnNo' 			=> $_registers->fields['registerReturnNumber'],
    										'ticketNo' 			=> $_registers->fields['registerTicketNumber'],
    										'orderNo' 			=> $_registers->fields['registerOrderNumber'],
    										'scheduleNo' 		=> $_registers->fields['registerScheduleNumber'],
    										'docsZeros' 		=> $_registers->fields['registerDocsLeadingZeros'],
    										'hotkeys' 			=> json_decode( $_registers->fields['registerHotkeys'], true ),
    										'printers' 			=> json_decode( $_registers->fields['registerPrinters'], true ),
    										'outletId'	 		=> enc($_registers->fields['outletId'])
    									];

    				$_registers->MoveNext(); 
			    }
			    $_registers->Close();
			}
			//registers END


    		$latLng = explode(',', $_outlets->fields['outletLatLng']);
    		$lat 	= $latLng[0];
    		$lng 	= $latLng[1];

	        $outletsArray[] = [
		        					'ID' 				=> enc($_outlets->fields['outletId']),
		        					'name' 				=> $_outlets->fields['outletName'],
		        					'address' 			=> $_outlets->fields['outletAddress'],
		        					'phone' 			=> $_outlets->fields['outletPhone'],
		        					'whatsapp' 			=> $_outlets->fields['outletWhatsApp'],
		        					'email' 			=> $_outlets->fields['outletEmail'],
		        					'billingName' 		=> $_outlets->fields['outletBillingName'],
		        					'tin' 				=> $_outlets->fields['outletRUC'],
		        					'createdAt'			=> $_outlets->fields['outletCreationDate'],
		        					'lat'				=> $lat,
		        					'lng'				=> $lng,
		        					'description'		=> $_outlets->fields['outletDescription'],
		        					'businessHours'		=> json_decode( $_outlets->fields['outletBusinessHours'], true ),
		        					'purchaseOrderNo'	=> $_outlets->fields['outletPurchaseOrderNo'],
		        					'transferOrderNo'	=> $_outlets->fields['outletOrderTransferNo'],
		        					'ecom'				=> $_outlets->fields['outletEcom'],
		        					'taxId'				=> $_outlets->fields['taxId'],
		        					'data' 				=> json_decode( $_outlets->fields['data'], true ),
		        					'registers' 		=> $registersArray
	        					];

	        $_outlets->MoveNext(); 
	    }
	    $_outlets->Close();
    }




	$jsonResult = 	[

						'company' 	=> [
											'ID'         		=> enc(COMPANY_ID),
											'name'				=> toUTF8($_settings['settingName']),
											'billingName' 		=> toUTF8($_settings['settingBillingName']),
											'tin'               => iftn($_settings['settingTIN'],'I.D.'),
											'address'    		=> toUTF8($_settings['settingAddress']),
											'city' 				=> toUTF8($_settings['settingCity']),
											'country' 			=> $_countries[$_settings['settingCountry']],
											'logo' 				=> 'https://assets.encom.app/250-250/0/' . enc(COMPANY_ID) . '.jpg',
											'email' 			=> $_settings['settingEmail'],
											'phone' 			=> $_settings['settingPhone'],
											'website'    		=> $_settings['settingWebSite'],
											'plan' 				=> getAllPlans($_company['companyPlan']),
											'category'  		=> $_settings['settingCompanyCategoryId'],
											'status' 			=> $_company['companyStatus'],
											'created' 			=> $_company['companyDate'],
											'sms' 				=> $_company['companySMSCredit'],
											"blocked" 			=> $_settings['settingBlocked'],
											'accountBlockingAlert'  => [
																		'is' 	=> $_settings['settingPlanExpired'], 
																		'txt' 	=> 'Le recordamos que posee facturas vencidas en su cuenta ENCOM.'
																		],
											'outlets' 			=> $outletsArray
										],

						'config' 	=> 	[
											'currency' 			=> $_settings['settingCurrency'],
											'language' 			=> $_settings['settingLanguage'],
											'timeZone' 			=> $_settings['settingTimeZone'],
											'taxName' 			=> $_settings['settingTaxName'],
											'decimal' 			=> $_settings['settingDecimal'],
											'thousand'			=> $_settings['settingThousandSeparator'],
											'sellSoldOut'		=> $_settings['settingSellSoldOut'],
											'openFrom'			=> $_settings['settingOpenFrom'],
											'openTo'			=> $_settings['settingOpenTo'],
											'social'			=> json_decode($_settings['settingSocialMedia'], true),
											'tinName'			=> $_settings['settingTIN'],
											'removeTax'         => $_settings['settingRemoveTaxes'],
											'customTemplates'   => $templatesArray,
											'tags'              => getTaxonomyArray('tag',COMPANY_ID,true),
											'tagsSys'           => getTagsDefaults(true),
											'paymentMethods'    => getTaxonomyArray('paymentMethod',COMPANY_ID),
											'bankNames'         => getTaxonomyArray('bankName',COMPANY_ID),
											'sellSoldOut'       => $_settings['settingSellSoldOut'],
											'blindDrawer'       => $_settings['settingDrawerBlind'],
											'paymentMethodId'   => $_settings['settingPaymentMethodId'],
											'itemSerialized'    => $_settings['settingItemSerialized'],
											'lockScreen'        => $_settings['settingLockScreen'],
											'itemsSaleLimit'    => $_settings['settingItemsSaleLimit'],
											'countryISO'        => $_countries[$_settings['settingCountry']]['iso'],
											'storeCredit'       => (int)$_settings['settingStoreCredit'],
											'itemsCategories'   => getAllItemCategories(COMPANY_ID),
											'hideCombo'         => $_settings['settingHideComboItems'],
											'forceCreditLine'   => (int)$_settings['settingForceCreditLine'],
											'webAppVersion'     => $APP_VERSION,
											'upsellList'        => $upsellArray,
											'mandatoryContact'  => $_settings['settingMandatoryContactFields'],
											'supportLock'       => '0990',
											'fullSettings'      => json_decode($_settings['settingObj'],true)
											
										],

						'modules' 	=> [

											'epos'              => [
																		'status' 	=> $_modules['epos'],
																		'ecomData' 	=> json_decode($_modules['eposData'], true)
																	],
											'ecom' 				=> [
																		'status' 	=> $_modules['ecom'],
																		'ecomData' 	=> json_decode( stripslashes($_modules['ecom_data']), true)
																	],

											'dropbox' 			=> [
																		'status' 	=> $_modules['dropbox'],
																		'token'  	=> $_modules['dropboxToken']
																	],
											'spotify' 			=> [
																		'status' 	=> $_modules['spotify'],
																		'url' 		=> $_modules['spotifyUrl']
																	],
											'loyalty' 			=> [
																		'status' 	=> $_modules['loyalty']
																	],
											'feedback' 			=> [
																		'status' 		=> $_modules['feedback']
																	],
											'ordersPanel'       => [
																		'status' 			=> $_modules['ordersPanel'],
																		'orderAverageTime'  => ($_modules['orderAverageTime'] ? $_modules['orderAverageTime'] : 60),
																	],
											'calendar' 			=> [
																		'status' 		=> $_modules['calendar']
																	],
											'spaces'           	=> [
																		'status' 		=> $_modules['tables'], 
																		'tablesCount' 	=> $_modules['tablesCount']
																	],
											'production' 		=> [
																		'status' 		=> $_modules['production']
																	],
											'kds' 				=> [
																		'status' 		=> $_modules['kds']
																	],
											'recurring' 		=> [
																		'status' 		=> $_modules['recurring']
																	],
											'dunning' 			=> [
																		'status' 		=> $_modules['dunning']
																	],
											'digitalInvoice' 	=> [
																		'status' 		=> $_modules['digitalInvoice']
																	],
											'tusFacturas' 		=> [
																		'status' 		=> $_modules['tusFacturas'],
																		'data' 			=> $_modules['tusFacturasData']
																	],
											'salesSummaryDaily' => [
																		'status' 		=> $_modules['salesSummaryDaily']
																	]

										]
						];	
}

apiOk($jsonResult);
