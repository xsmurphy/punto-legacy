<?php
include_once('api_head.php');
$order 			= stripslashes(validateHttp('order','post'));

if(isJson($order)){
	$order = json_decode($order,true);
}

$name			= strip_tags($order['billingName']);
$tin 			= $order['tin'];
$phone 			= $order['phone'];
$address 		= strip_tags($order['address']);
$addressId 		= $order['addressId'] ? strip_tags($order['addressId']) : false;
$birthDay 		= $order['birthDay'];
$status 		= iftn($order['orderStatus'],'1');

$note 			= strip_tags($order['note']);
$items 			= $order['items'];
$source 		= iftn($order['source'],$order['tableNo']);
$outletId 		= dec($order['outlet']);
$userId 		= dec($order['user']);
$dueDate 		= $order['dueDate'];
$registerId 	= dec($order['register']);
$customerUID 	= $order['customerUID'];
$tags 			= $order['tags'];
$saleDetail 	= [];
$trsTotal 		= 0;
$trsUsold 		= 0;
$payment		= isset($order['payment']) ? $order['payment'] : false;


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
			$discount           = 0;
			if ($itemData['itemDiscount'] > 0) {
				$discount           = abs($itemData['itemPrice'] * ($itemData['itemDiscount'] / 100));
			}
			$itemPrice = $itemData['itemPrice'] - $discount;

			$total 			= $itemPrice * $item['qty'];
			$parent 		= $item['isParent'] ? $item['isParent'] : $item['parent'];

			if(in_array($item['type'], ['product','combo','comboAddons','precombo','dynamic','inCombo','inComboAddons'])){
				$type 			= $item['type'];
			}else{
				$type 			= $itemData['itemType'];
			}

			if($type == 'inCombo'){
				$total = 0;
			}

			if($type == 'comboAddons'){
				$type = 'combo';
			}

			if($item['parent'] && !$item['isParent']){
				//$type = 'inComboAddons';
			}
			
			$priceRule = false;
			$catMax = 0;
			if($type == 'inComboAddons'){
				foreach ($items as $item2) {
					if (isset($item2['isParent']) && $item2['isParent'] === $parent) {
						$parentData = getPriceRule($item2['id']);
						if(!empty($parentData) && !empty($parentData['priceRule'])){
							$priceRule = $parentData['priceRule'];
							
							$compound 	= displayableCompounds(dec($item2['id']));
							
							if(!empty($compound) && !empty($compound[0]['units'])){
								$catMax = floatval($compound[0]['units']);
							}

							break; 
						}

					}
				}
			} 

			$saleDetail[] 	= 	[
									'itemId' 	=> enc($itemData['itemId']),
									'count' 	=> strip_tags($item['qty']),
									'name'		=> $itemData['itemName'],
									'uniPrice'	=> (int)$itemData['itemPrice'],
									'price' 	=> $itemPrice,
									'total' 	=> $total,
									'tax' 		=> $allTax[$itemData['taxId']]['name'],
									'discount'	=> $discount,
									'user' 		=> iftn( strip_tags($item['user']), strip_tags($order['user']) ),
									'type' 		=> $type,
									'note' 		=> strip_tags($item['note']),
									'parent' 	=> strip_tags($parent),
									'reinsert' 	=> $item['reinsert'] ? true : false,
									'sku' 		=> $itemData['itemSKU'],
									'itemPriceRule' => $priceRule,
									'catMax'	=> $catMax
								];

			$trsTotal 		+= $total;
			$trsUsold 		+= strip_tags( $item['qty'] );
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

	if($addressId){
		ncmInsert(['records' => ['customerAddressId' => dec($addressId)], 'table' => 'toAddress']);
	}

	foreach ($saleDetail as $key => &$item) {
		if(isset($item['itemPriceRule']) && $item['itemPriceRule'] !== false && isset($item['parent'])){
			$pricesOfParents = getPricesOfParents($saleDetail, $item['parent']);
			if(!empty($pricesOfParents )){ 
				// $count = round($item['count'] / $item['catMax'], 2);
				$count = $item['count'] / $item['catMax'];

				// if ($key === 1 && (int)$item['catMax'] % 2 !== 0 && $item['count'] == 1) {
				// 	$diferencia = (($item['count'] / $item['catMax']) * $item['catMax']) - ($count *  $item['catMax']);
				// 	$count = round(($count + $diferencia), 2);
				// }

				$price = $item['price'];
				$newTotal = $item['total'];
				$total 			= $itemPrice * $item['qty'];

				if($item['itemPriceRule'] == 'topPrice'){
					$price = (count($pricesOfParents) > 1) ? max(...$pricesOfParents) : $pricesOfParents[0]; 
					$item['uniPrice'] = $price;
					$item['price'] = $price;
				}else if($item['itemPriceRule'] == 'lowPrice'){
					$price = (count($pricesOfParents) > 1) ? min(...$pricesOfParents) : $pricesOfParents[0]; 
					$item['uniPrice'] = $price;
					$item['price'] = $price;
				}else if($item['itemPriceRule'] == 'average'){
					$price = $item['price'];
				}
					$trsTotal 		-= $item['total'];

					$item['oQty']  = $count;
					$newTotal = $price * $item['oQty'];
					$item['total'] = round($newTotal, 2);
					$item['count']  = $count;
					$item['discount']  = 0;
					$trsTotal 		+= $newTotal;
			}
			
		}
		unset($item);
	}	




	$docNo 							  = getNextDocNumber(0,'12',$registerId);

	$record 						  = [];
	$record['transactionTotal']       = $trsTotal; //total sale amount
    $record['transactionUnitsSold']   = $trsUsold;

    $record['transactionDetails']     = json_encode($saleDetail);
    
    $record['transactionType']        = 12;
    $record['transactionComplete']    = 0;

    $record['transactionDate']        = TODAY;
    $record['transactionDueDate']     = date('Y-m-d H:i:s',strtotime($dueDate));
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

	if($payment){
		$record['transactionPaymentType'] = json_encode($payment);
	}

    $insertTransaction                = $db->AutoExecute('transaction', $record, 'INSERT');

    $insertedId 					  = $db->Insert_ID();

    if($insertTransaction !== false){
    	updateLastTimeEdit(COMPANY_ID,'order');
    	
		$ops = [
			"title"     => 'Nueva Orden',
			"message"   => 'Orden # ' . $docNo . ' fue recibida y espera ser aceptada',
			"type"      => 1,
			"date"      => TODAY,
			"userId" 	=> enc($outletId),
			"register"  => 1,
			"email"  	=> 1,
			"push"      => [
                        "tags" => [[
                                        "key"   => "outletId",
                                        "value" => enc($outletId)
                                    ],
                                    [
                                        "key"   => "isResource",
                                        "value" => "false"
                                    ]],
                        "where"     => 'caja',
                        "edata" 		=> [[
                        					"type" 		=> "order",
                        					"source" 	=> "api"
                        				]]
                        ],
			"company"   => COMPANY_ID
		];

		insertNotifications($ops);

    	jsonDieResult([ 'success' => 'Orden generada', 'nro' => $docNo, 'ID' => enc($insertedId) ]);
    }else{
    	jsonDieMsg('No se pudo generar la orden',401,'error');
    }
}else{
	jsonDieMsg('Debe incluir productos o servicios',401,'error');
}
?>