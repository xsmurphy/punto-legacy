<?php
include_once('api_head.php');

$offset 	= validateHttp('offset','post') ? validateHttp('offset','post') : 0;
$limits 	= (validateHttp('limit','post') && validateHttp('limit','post') < 1000) ? validateHttp('limit','post') : 1000;
$limit 		= ' LIMIT ' . $offset . ', ' . $limits;
$status 	= validateHttp('archived','post') ? ' AND itemStatus = 0' :  ' AND itemStatus = 1';
$forsale 	= validateHttp('internal','post') ? ' AND itemCanSale = 0' :  ' AND itemCanSale = 1';
$ecom 		= validateHttp('ecom','post') ? ' AND itemEcom = 1' :  '';
$outlet 	= validateHttp('outlet','post') ? ' AND (outletId = 0 OR outletId IS NULL OR outletId = ' . dec(validateHttp('outlet','post')) . ')' :  '';
$children 	= validateHttp('children','post');
$noLimit 	= validateHttp('nolimit','post');
$order 		= validateHttp('order','post');
$sort 		= validateHttp('sort','post') ? validateHttp('sort','post') : 'ASC';
$cache 		= validateHttp('cache','post') ? validateHttp('cache','post') : false;

$orderBy = array();
$orderBy[] = "itemSort ASC";
if($order == 'lastUpdated'){
	$orderBy[] = 'updated_at DESC';
}else{
	$orderBy[] = 'itemDate ' . $sort;
}

if($noLimit){
	$limit = ' LIMIT 5000';
}

//si pido solo parents y solos
$childrenSql 		= ' (itemIsParent > 0 OR (itemParentId IS NULL OR itemParentId = 0))';

if($children){
	if($children == 'all'){//si pido parents y children y solos
		$childrenSql 	= ' itemId > 0'; //condicion solo por que las siguientes tienen AND y causa error si esta en blanco
	}else{//si pido children y solos
		$childrenSql 	= ' (itemIsParent < 1 OR itemIsParent IS NULL)';
	}
}

$sql 	= '	SELECT *
			FROM item
			WHERE
			' . $childrenSql . '
			' . $status . '
			' . $forsale . '
			' . $ecom . '
			' . $roc . '
			' . $outlet . '
			ORDER BY ' . ( implode(",",$orderBy) ) . $limit;

$result = ncmExecute($sql,[],$cache,true);

$arrays 		= [];

if($result){
	$allTax 	= getAllTax();
	$allCats 	= getAllItemCategories();
	$allBrands 	= getAllItemBrands();
	
	while (!$result->EOF) {
		$fields 					= $result->fields;
		$array 						= [];
		$itemId 					= enc($fields['itemId']);
		$discPercent 				= 0;

		if($fields['itemTrackInventory']){
			$stock 					= getItemStock($fields['itemId']);
		}
		
		$imageUrl 					= '';

		$array["ID"] 				= $itemId;
		$array["name"] 				= toUTF8($fields['itemName']);
		$array["sku"] 				= $fields['itemSKU'];
		$array["price"] 			= $fields['itemPrice'];
		if($fields['itemTrackInventory']){
			$array["cost"] 				= $stock['stockOnHandCOGS'];
			$array["stock"] 			= $stock['stockOnHand'];
		}

		// Se excluye esta linea ya que el descuento ya esta dentro del precio del combo que el cliente asigne al combo
		// if(in_array($fields['itemType'], ['precombo','combo'])){
		// 	$realPrice 	= getComboCOGS($fields['itemId']);
		// 	if($realPrice > $fields['itemPrice']){
		// 		$difference 	= $realPrice - $fields['itemPrice'];
		// 		$discPercent 	= ( $difference * 100 ) / $realPrice;
		// 		if($discPercent > 0){
		// 			$fields['itemDiscount'] = intval($discPercent);
		// 		}
		// 	}
		// }

		$compound = false;
		$compsList = [];
		if(in_array($fields['itemType'], ['combo','precombo','comboAddons'])){
			$compound 	= displayableCompounds($fields['itemId']);

			//$compsList[] = $compound;

			if( in_array($fields['itemType'], ['combo','comboAddons']) ){
				$i = 0;
				foreach ($compound as $key => $value) {
					$catId 		= dec($value['id']);

					$catName 	= ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND companyId = ? LIMIT 1',[$catId,COMPANY_ID],true);

					$compsList[$i] = [
													'catId' 	=> $value['id'], 
													'catName' 	=> toUTF8($catName['taxonomyName']), 
													'catType' 	=> 'category',
													'catMax' 	=> $value['units'],
													'catItems' => []
												];

					$catItems 	= ncmExecute('SELECT itemName, itemPrice, itemId, itemImage, itemDiscount FROM item WHERE categoryId = ? AND itemStatus > 0 AND companyId = ?',[$catId,COMPANY_ID],$cache,true);

					if($catItems){
						while (!$catItems->EOF) {
							$cfields 		= $catItems->fields;
							$cPrice 		= $cfields['itemPrice'];
							$cType 			= 'inCombo';

							$cDiscount = 0;
							if ($cfields['itemDiscount'] > 0) {
								$cDiscount           = abs($cPrice * ($cfields['itemDiscount'] / 100));
							}
							$cPrice = $cPrice - $cDiscount;

							if( $fields['itemType'] == 'combo' ){
								$cPrice 	= 0;
							}

							if( $fields['itemType'] == 'comboAddons' ){
								$cType 		= 'inComboAddons';
							}

							$catItemsImageUrl = '';

							if($cfields['itemImage'] === 'true'){
								$catItemsImageUrl 				= ASSETS_URL . '180-180/0/' . enc(COMPANY_ID) . '_' . enc($cfields['itemId']) . '.jpg';
							}

							$compsList[$i]['catItems'][] = [
																'itemId' 	=> enc($cfields['itemId']), 
																'itemName' 	=> toUTF8($cfields['itemName']), 
																'itemPrice' => $cPrice,
																'itemType' 	=> $cType,
																'img_url'	=> $catItemsImageUrl
															];

							$catItems->MoveNext();
						}
					}
					$i++;
				}
			}

			//$compsList = $compound;
		}

		$iData 						= json_decode($fields['data'],true);
		
		$array["discount"] 			= ($fields['itemDiscount'] > 0) ? $fields['itemDiscount'] : 0;
		$array["created"] 			= $fields['itemDate'];
		$array["tax"]				= $allTax[$fields['taxId']]['name'];
		$array["taxID"]				= enc($fields['taxId']);
		$array["categoryID"]		= ($fields['categoryId']) ? enc($fields['categoryId']) : '';
		$array["brandID"]			= ($fields['brandId']) ? enc($fields['brandId']) : '';
		$array["status"]			= ($fields['itemStatus'] > 0) ? 'Active' : 'Disabled';
		$array["type"]				= $fields['itemType'];
		$array["isParent"]			= $fields['itemIsParent'];
		$array["parent"]			= ($fields['itemParentId']) ? enc($fields['itemParentId']) : '';
		$array["UOM"]				= $fields['itemUOM'];
		$array["description"]		= toUTF8($fields['itemDescription']);
		$array["featured"]			= $fields['itemFeatured'];
		$array["online"]			= $fields['itemEcom'];
		$array["outletID"]			= $fields['outletId'] ? enc($fields['outletId']) : null;
		$array["priceRule"]			= $iData['priceRule'];
		$array["comboAddOns"]		= $compsList;
		$array["daysNHours"]		= json_decode( stripslashes($fields['itemDateHour']),true );
		
		$array["lastUpdated"]		= $fields['updated_at'];

		$array["category"]		= $allCats[$fields['categoryId']]['name'];
		$array["brand"]			= $allBrands[$fields['brandId']]['name'];

		if($fields['itemImage'] === 'true'){
			$imageUrl 				= ASSETS_URL . '180-180/0/' . enc(COMPANY_ID) . '_' . $itemId . '.jpg';
		}

		$array["img_url"] 			= $imageUrl;
		$arrays[] 					= $array;

		$result->MoveNext();
	}
	
	jsonDieResult($arrays,200);
	
}else{
	jsonDieMsg('No se encontraron registros',404,'error');
}


?>