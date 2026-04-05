<?php
include_once('api_head.php');
 
$postSale 		= validateHttp('sale','post');
$order 			= is_array($postSale) ? $postSale : stripslashes( $postSale );

if(isJson($order)){
	$order = json_decode($order,true);
}

$name			= strip_tags($order['billingName']);
$tin 			= $order['tin'];
$phone 			= $order['phone'];
$email 			= $order['email'];
$address 		= strip_tags($order['address']);
$birthDay 		= $order['birthDay'];
$status 		= iftn($order['orderStatus'],'1');

$note 			= markupt2HTML(['text' => $order['note'], 'type' => 'HtM']);
$items 			= $order['items'];
$source 		= iftn($order['source'],$order['tableNo']);
$outletId 		= dec($order['outlet']);
$userId 		= dec($order['user']);
$condition 		= $order['saleCondition'];

$registerId 	= dec($order['register']);
$customerUID 	= $order['customerUID'];
$tags 			= $order['tags'];
$paymentM		= $order['payments'];
$saleDetail 	= [];
$itmRecords 	= [];
$trsTotal 		= 0;
$trsUsold 		= 0;
$trsTax 		= 0;

$saleCondition 	= 0;
$saleComplete 	= 1;
$dueDate 		= NULL;
if($condition == 'credito'){
	$saleCondition 	= 3;
	$saleComplete 	= 0;
	$dueDate 		= date( 'Y-m-d H:i:s', strtotime($order['dueDate']) );
}

$isOutlet 	= ncmExecute('SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',[$outletId,COMPANY_ID]);
if(!$isOutlet){
	jsonDieMsg('Sucursal incorrecta = ' . $order,404,'error');
}

$isRegister = ncmExecute('SELECT registerId FROM register WHERE registerId = ? AND outletId = ? AND companyId = ? LIMIT 1',[$registerId,$outletId,COMPANY_ID]);
if(!$isRegister){
	jsonDieMsg('Caja incorrecta',404,'error');
}

$isUser 	= ncmExecute('SELECT contactId FROM contact WHERE contactId = ? AND type = 0 AND companyId = ? LIMIT 1',[$userId,COMPANY_ID]);
if(!$isUser){
	jsonDieMsg('Usuario incorrecto',404,'error');
}

//items
if(validity($items,'array')){	
	$allTax 	= getAllTax();

	foreach ($items as $key => $item) {
		//si no envio el ID busco por SKU
		$itemId = dec($item['id']);
		if($item['id']){
			$itemData = ncmExecute('SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[$itemId,COMPANY_ID]);
		}else if($item['sku']){
			$itemData = ncmExecute('SELECT * FROM item WHERE itemSKU = ? AND companyId = ? LIMIT 1',[$item['sku'],COMPANY_ID]);
		}

		if($itemData){
			$itemTax 		= $allTax[$itemData['tax']]['name'];
			$total 			= $itemData['itemPrice'] * $item['qty'];
			$itmNote 		= markupt2HTML(['text' => $item['note'], 'type' => 'HtM']);

			$saleDetail[] 	= 	[
									'itemId' 	=> enc($itemData['itemId']),
									'count' 	=> strip_tags($item['qty']),
									'price' 	=> $itemData['itemPrice'],
									'total' 	=> $total,
									'tax' 		=> $itemTax,
									'user' 		=> iftn( strip_tags($item['user']), strip_tags($order['user']) ),
									'type' 		=> $itemData['itemType'],
									'note' 		=> $itmNote,
									'parent' 	=> strip_tags($item['parent']),
									'reinsert' 	=> $item['reinsert'] ? true : false,
									'sku' 		=> $itemData['itemSKU']
								];

			//$taxVal 		= calculateTax( $itemData['itemPrice'], $itemTax );
			$tax 			= getTaxOfPrice( getTaxValue($itemData['taxId']), ($itemData['itemPrice'] * $item['qty']) );//$taxVal[1];

			$trsTax 		+= $tax;
			$trsTotal 		+= $total;
			$trsUsold 		+= strip_tags( $item['qty'] );

			$itmRecords[] = [
								'itemId' 			=> $itemData['itemId'],
								'itemSoldTotal' 	=> $total,
								'itemSoldTax' 		=> $tax,
								'itemSoldDiscount'	=> $item['discount'],
								'itemSoldUnits'	 	=> $item['qty'],
								'itemSoldDate' 		=> TODAY,
								'userId' 			=> $userId,
								'itemSoldComission' => NULL,
								'itemSoldCOGS' 		=> NULL,
								'itemSoldParent' 	=> NULL
							];
		}
	}
}

if(validity($saleDetail,'array')){

	if($customerUID){
		$customer = dec($customerUID);
	}else{
		if($tin){
			$getCustomer = ncmExecute('SELECT * FROM contact WHERE contactTIN = ? AND companyId = ? LIMIT 1',[$tin,COMPANY_ID]);
			if($getCustomer){
				$customer = $getCustomer['contactUID'];
			}else{
				if($name){
					$customer 					= generateUID();
					$cRecord['contactUID']      = $customer;
					$cRecord['contactName']    	= $name;
					$cRecord['contactTIN']     	= $tin;
					$cRecord['contactPhone']   	= $phone;
					$cRecord['contactEmail']   	= $email;
					$cRecord['contactAddress'] 	= $address;
					$cRecord['contactBirthDay'] = $birthDay;
					$cRecord['userId']         	= $userId;
					$cRecord['companyId']      	= COMPANY_ID;
				    $cRecord['updated_at']     	= TODAY;
				    $cRecord['type']           	= 1;
	    			$newCustomer		       	= ncmInsert(['records' => $cRecord, 'table' => 'contact']);
	    			updateLastTimeEdit(COMPANY_ID,'customer');
	    		}
			}
		}
	} 

	$payments[] = ['type' => 'cash', 'price' => $trsTotal];

	if(validity($paymentM)){
		$payments = [];
		foreach ($paymentM as $method => $total) {
			if(in_array($method, ['cash','creditcard','debitcard'])){
				$payments[] = ['type' => $method, 'name' => $method, 'price' => (float)$total ];
			}
		}
	}

	$docNo 							  = getNextDocNumber(0,$saleCondition,$registerId);

	$record 						  = [];
	$record['transactionTotal']       = $trsTotal; //total sale amount
    $record['transactionUnitsSold']   = $trsUsold;
    $record['transactionTax']         = $trsTax;
    $record['transactionPaymentType'] = json_encode($payments);

    $record['transactionDetails']     = json_encode($saleDetail);
    
    $record['transactionType']        = $saleCondition;
    $record['transactionComplete']    = $saleComplete;

    $record['transactionDate']        = TODAY;
    $record['transactionDueDate']     = $dueDate;
    $record['transactionNote']        = $note;
    $record['transactionName']        = $source;
    $record['invoiceNo']              = $docNo;
    $record['timestamp']              = strtotime(TODAY);
    $record['transactionUID']         = generateUID();
    $record['transactionStatus']      = $status;

    $record['tags']             	  = json_encode($tags);
    $record['customerId']             = $customer;
    $record['registerId']             = $registerId;
    $record['userId']                 = $userId;
    $record['outletId']               = $outletId;
    $record['companyId']              = COMPANY_ID;

    $insertTransaction 				  = ncmInsert(['records' => $record, 'table' => 'transaction']);
	$insertedId 					  = $insertTransaction;

    if($insertTransaction !== false){
    	updateLastTimeEdit(COMPANY_ID);

    	foreach ($itmRecords as $key => $records) {
    		$records['transactionId']     = $insertedId;
    		ncmInsert(['records' => $records, 'table' => 'itemSold']);
    	}
    	
		/*$ops = [
			"title"     => 'Nueva Orden',
			"message"   => 'Orden # ' . $docNo . ' fue recibida y espera ser aceptada',
			"type"      => 1,
			"date"      => TODAY,
			"outlet"    => $outletId,
			"register"  => 1,
			"email"  	=> 1,
			"company"   => COMPANY_ID
		];

		insertNotifications($ops);*/

		$pdf = 'https://public.encom.app/digitalInvoice?s=' . base64_encode( enc($insertedId) . ',' . enc(COMPANY_ID) ) . '&pdf=1';


		$setting 	= ncmExecute('SELECT * FROM setting WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

		if($order['sendEmail'] && $name && $email){
			$body     = 'Hola ' . $name . ',' .
                    '<p>Puede descargar la copia de su factura en el siguiente enlace.</p>' .
                    makeEmailActionBtn($pdf,'Descargar PDF');

			$meta['subject'] = '[' . $setting['settingName'] . '] Copia de su factura';
			$meta['to']      = $email;
			$meta['fromName']= $setting['settingName'];
			$meta['data']    = [
			                    "message"     => $body,
			                    "companyname" => $setting['settingName'],
			                    "companylogo" => 'https://assets.encom.app/150-150/0/' . enc(COMPANY_ID) . '.jpg'
			                	];

			sendEmails($meta);
		}		

    	jsonDieResult([ 'success' => 'Venta generada', 'nro' => $docNo, 'ID' => enc($insertedId), 'PDF' => $pdf ]);
    }else{
    	jsonDieMsg('No se pudo generar la orden',401,'error');
    }
}else{
	jsonDieMsg('Debe incluir productos o servicios',401,'error');
}
?>