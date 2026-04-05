<?php
require_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
topHook();
allowUser('sales','view');

$baseUrl = '/' . basename(__FILE__,'.php');

$startPageLoad 	= startPageLoadTimeCalculator();

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc = str_replace(['registerId','outletId','companyId'],['b.registerId','b.outletId','b.companyId'],getROC(1));

$maxItemsInGraph 	= 30;
$limitDetail		= 100;
$offsetDetail		= 0;
$itemsArray 		= [];
$itemsArrayDetail 	= [];
$productionItem 	= [];
$isdata 			= false;
$isDetail 			= false;

function buildTableList($itm,$values){
		global $isDetail,$category,$baseUrl;	
		$COGS 		= $values['cogs'];
		$date 		= $values['date'];
		$units 		= $values['units'];
		$outlet 	= getCurrentOutletName($values['outlet']);
		$user 		= getContactData($values['user'])['name'];
		$wasteValue	= $values['wasteValue'];
		$type		= $values['type'];
		$id 		= enc($values['id']);

		$typeIs 	= ($type == 'direct_production') ? 'Directa' : 'Previa';

		if($type == 'direct_production'){
			$typeIs = 'Directa';
		}else if($type == '1'){
			$typeIs = 'Previa';
		}else if($type == '2'){
			$typeIs = 'Orden';
		}

		$name		= $itm['itemName'];
		$sku		= $itm['itemSKU'];
		$categoryName = iftn($itm['categoryId'],'',$category[$itm['categoryId']]['name']);
		$price		= $itm['itemPrice'];
		$tax 		= addTax($itm['itemTax'],$price);
		$comission	= getComissionValue($itm['itemComission'],$price);
		$average 	= divider($COGS,$units,true);
		$utility 	= ( ($price - $average) - $comission) - $tax;

		$fUnits 	= strpos($units, '.') === false ? formatCurrentNumber($units) : formatCurrentNumber($units,'yes');
		
		$table .= 	'<tr id="' . $id . '" class="' . (($isDetail) ? 'clickrow' : '' ) . '" data-load="' . $baseUrl . '?action=recipe&id=' . enc($itm['itemId']) . '&cant=' . $units . '&date=' . niceDate($date,true) . '&outlet=' . $outlet . '&user=' . $user . '">';

					if($isDetail){
					 	$table .= 	'<td data-order="'.$date.'">' . niceDate($date,true) . '</td>' .
					 				'<td>' . $user . '</td>';
					}

		$table .=	'	<td class="'.enc($itm).'"> '.$name.' </td>' .
					'	<td>'.$sku.'</td>' .
					'	<td> '.$categoryName.' </td>' .
					'	<td> '.$outlet.' </td>' .
					'	<td> <span class="badge">'.$typeIs.'</span> </td>' .
					'	<td class="text-right bg-light lter" data-order="'.$units.'">' . 
							$fUnits . 
					' 	</td>' .
					'	<td class="text-right bg-light lter" data-order="'.$average.'" data-format="money">' . 
							(($type == '2' && $isDetail) ? '-' : '~' . formatCurrentNumber($average)) . 
					' 	</td>' .
					'	<td class="text-right bg-light lter" data-order="'.$COGS.'" data-format="money">' . 
							(($type == '2' && $isDetail) ? '-' : formatCurrentNumber($COGS)) . 
					' 	</td>' .
					'	<td class="text-right bg-light lter" data-order="'.$wasteValue.'" data-format="money">' . 
							(($type == '2' && $isDetail) ? '-' : formatCurrentNumber($wasteValue)) . 
					' 	</td>' .
					'	<td class="text-right bg-light lter" data-order="' . ($utility * $units) . '" data-format="money">' . 
							(($type == '2' && $isDetail) ? '-' : formatCurrentNumber(($utility * $units))) . 
					' 	</td>';
					if($isDetail){
					 	$table .= 	'<td class="text-center">';
					 	if($id){
					 		$table .= 	'	<a href="#" class="delete" data-id="' . $id . '">' .
						 				'		<i class="material-icons text-danger">close</i>' .
						 				' 	</a>';
						}
					 	$table .= 	'</td>';
					}
		$table  .= '</tr>';

		return ['table'=>$table,'units'=>$units,'cogs'=>$COGS,'utility'=>$utility];
}

if(validateHttp('action') == 'compoundTable'){

	theErrorHandler('json');

	$compoundsArr 		= [];

	$roc 		= str_replace(['outletId','companyId'],['b.outletId','b.companyId'],getROC(1));

	$sqlItems   = 'SELECT itemId, itemName, itemSKU FROM item WHERE itemCanSale < 1 AND ' . $SQLcompanyId;
    $allItems   = $db->GetAssoc($sqlItems);
    //
    $con = [];
    foreach($allItems as $val){
        $con[] = $val['itemId'];
    }
    //
    $itemsIds   = implodes(',',$con);
	$allOutlets = getAllOutlets();
	$table 		= '';

	$head 		= 	'<thead class="text-u-c">'.
					'	<tr>'.
					'		<th>Compuesto</th>'.
					'		<th>Código/SKU</th>'.
					'		<th class="text-center">Cantidad</th>'.
					'		<th class="text-center">Costo</th>'.
					'	</tr>'.
					'</thead>'.
					'<tbody>';

	//Para obtener compuestos de producción previa
	$sql2 		= "SELECT * FROM production WHERE productionDate BETWEEN ? AND ? AND productionType = 1 " . getROC(1);
	$result2   	= ncmExecute($sql2,[$startDate,$endDate],false,true);

	if($result2){
		while (!$result2->EOF) {
			$fields = $result2->fields;
			$data 	= json_decode($fields['productionRecipe'],true);
			$date 	= date('Y-m-d',strtotime($fields['productionDate']));

			foreach ($data as $id => $value) {
				$compoundsArr[dec($id)] 	= 	[
													'date' 	=> $date,
													'count' => $value['units'],
													'cogs' 	=> $value['cogs'],
													'type' 	=> 'prev'
												];
			}

			$result2->MoveNext();
		}
	}

	//Nuevo metodo para producción directa
	$sql 	= "SELECT SUM(stockCount) as count, stockDate, stockCOGS, itemId FROM stock WHERE itemId IN(" . $itemsIds . ") AND stockSource = 'production' AND stockDate BETWEEN ? AND ? " . getROC(1) . " GROUP BY itemId";

	$result3   	= ncmExecute($sql,[$startDate,$endDate],false,true);

	if($result3){
		while (!$result3->EOF) {
			$fields = $result3->fields;
			$date 	= date('Y-m-d',strtotime($fields['stockDate']));

			$compoundsArr[$fields['itemId']][] 	= 	[
														'date' 	=> $date,
														'count' => abs($fields['count']),
														'cogs' 	=> $fields['stockCOGS'],
														'type' 	=> 'direct'
													];
			

			$result3->MoveNext();
		}
	}

	
	if(validity($compoundsArr)){
		foreach ($compoundsArr as $itemId => $dates) {
			$item 	= ncmExecute('SELECT * FROM item WHERE itemId = ? LIMIT 1',[$itemId]);
			$name 	= $item['itemName'];
			$sku 	= $item['itemSKU'];

			foreach ($dates as $fields) {
				if(validity($fields,'array')){
					
					if($fields['type'] == 'prev'){
						$cost 	= $fields['cogs'];
					}else{
						$cost 	= $fields['count'] * $fields['cogs'];
					}
					
					$count 	= formatCurrentNumber($fields['count'],'si',false);

					$table .= 	'<tr>' .
								'	<td>' . $name . '</td>' .
								'	<td>' . $sku . '</td>' .
								'	<td class="text-right bg-light lter" data-order="' . $fields['count'] . '" data-format="int">' . $count . '</td>' .
								'	<td class="text-right bg-light lter" data-order="' . $cost . '" data-format="currency">' . formatCurrentNumber($cost) . '</td>' .
								'</tr>';

					$csv[] 	= [$fields['date'],enc($fields['item']),$name,$sku,$count,formatCurrentNumber($cost)];
				}
			}
		}
	}


	$foot 	= 		'</tbody>'.
					'<tfoot>'.
					'	<tr>'.
					'		<th>TOTALES</th>'.
					'		<th></th>'.
					'		<th class="text-right"></th>'.
					'		<th class="text-right"></th>'.
					'	</tr>'.
					'</tfoot>';

	$fullTable 				= $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;
	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));
}

if(validateHttp('action') == 'compoundTableDay'){

	theErrorHandler('json');

	$compoundsArr 		= [];

	$roc 		= str_replace(['outletId','companyId'],['b.outletId','b.companyId'],getROC(1));

	$sqlItems   = 'SELECT itemId, itemName, itemSKU FROM item WHERE itemCanSale < 1 AND ' . $SQLcompanyId;
    $allItems   = $db->GetAssoc($sqlItems);
    //
    $con = [];
    foreach($allItems as $val){
        $con[] = $val['itemId'];
    }
    //
    $itemsIds   = implodes(',',$con);
	$allOutlets = getAllOutlets();
	$table 		= '';

	$head 		= 	'<thead class="text-u-c">'.
					'	<tr>'.
					'		<th>Fecha</th>'.
					'		<th>Compuesto</th>'.
					'		<th>Código/SKU</th>'.
					'		<th class="text-center">Cantidad</th>'.
					'		<th class="text-center">Costo</th>'.
					'	</tr>'.
					'</thead>'.
					'<tbody>';

	//Para obtener compuestos de producción previa
	$sql2 		= "SELECT * FROM production WHERE productionDate BETWEEN ? AND ? AND productionType = 1 " . getROC(1) . " GROUP BY DATE(productionDate)";
	$result2   	= ncmExecute($sql2,[$startDate,$endDate],false,true);

	if($result2){
		while (!$result2->EOF) {
			$fields = $result2->fields;
			$data 	= json_decode($fields['productionRecipe'],true);
			$date 	= date('Y-m-d',strtotime($fields['productionDate']));

			foreach ($data as $id => $value) {
				$compoundsArr[dec($id)] 	= 	[
													'date' 	=> $date,
													'count' => $value['units'],
													'cogs' 	=> $value['cogs'],
													'type' 	=> 'prev'
												];
			}

			$result2->MoveNext();
		}
	}

	//Nuevo metodo para producción directa
	$sql 	= "SELECT SUM(stockCount) as count, stockDate, stockCOGS, itemId FROM stock WHERE itemId IN(" . $itemsIds . ") AND stockSource = 'production' AND stockDate BETWEEN ? AND ? " . getROC(1) . " GROUP BY DATE(stockDate), itemId";

	$result3   	= ncmExecute($sql,[$startDate,$endDate],false,true);

	if($result3){
		while (!$result3->EOF) {
			$fields = $result3->fields;
			$date 	= date('Y-m-d',strtotime($fields['stockDate']));

			$compoundsArr[$fields['itemId']][] 	= 	[
														'date' 	=> $date,
														'count' => abs($fields['count']),
														'cogs' 	=> $fields['stockCOGS'],
														'type' 	=> 'direct'
													];
			

			$result3->MoveNext();
		}
	}

	
	if(validity($compoundsArr)){
		foreach ($compoundsArr as $itemId => $dates) {
			$item 	= ncmExecute('SELECT * FROM item WHERE itemId = ? LIMIT 1',[$itemId]);
			$name 	= $item['itemName'];
			$sku 	= $item['itemSKU'];

			foreach ($dates as $fields) {
				if(validity($fields,'array')){
					
					if($fields['type'] == 'prev'){
						$cost 	= $fields['cogs'];
					}else{
						$cost 	= $fields['count'] * $fields['cogs'];
					}
					
					$count 	= formatCurrentNumber($fields['count'],'si',false);

					$table .= 	'<tr>' .
								'	<td>' . niceDate($fields['date']) . '</td>' .
								'	<td>' . $name . '</td>' .
								'	<td>' . $sku . '</td>' .
								'	<td class="text-right bg-light lter" data-order="' . $fields['count'] . '" data-format="int">' . $count . '</td>' .
								'	<td class="text-right bg-light lter" data-order="' . $cost . '" data-format="currency">' . formatCurrentNumber($cost) . '</td>' .
								'</tr>';

					$csv[] 	= [$fields['date'],enc($fields['item']),$name,$sku,$count,formatCurrentNumber($cost)];
				}
			}
		}
	}


	$foot 	= 		'</tbody>'.
					'<tfoot>'.
					'	<tr>'.
					'		<th>TOTALES</th>'.
					'		<th></th>'.
					'		<th></th>'.
					'		<th class="text-right"></th>'.
					'		<th class="text-right"></th>'.
					'	</tr>'.
					'</tfoot>';

	$fullTable 				= $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;
	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));
}

if(validateHttp('action') == 'export'){
	$sql = 'SELECT 
					a.itemSoldId,
					a.itemId,
					a.itemSoldUnits,
					a.itemSoldTotal, 
					a.itemSoldTax, 
					a.itemSoldDiscount,
					a.itemSoldDate,
					a.transactionId,
					a.userId as itemUser,
					b.customerId as customer,
					b.userId as trsUser,
					b.outletId as outletId,
					b.registerId,
					b.invoiceNo,
					b.transactionType,
					c.contactName as userName,
					d.itemName as itemName,
                    d.itemSKU as itemSKU,
			FROM itemSold a, transaction b, contact c, item d
			WHERE a.itemSoldDate
			BETWEEN ?
			AND ? 
			'.$roc.'
			AND b.transactionType IN(0,3)
			AND a.transactionId = b.transactionId
			AND a.itemId = d.itemId
			ORDER BY a.itemSoldDate DESC';

	$result 	= $db->Execute($sql, array($startDate,$endDate));
	$x 			= 0;
	$titles 	= array('Sucursal','Caja','Doc. No.','Usuario','Cliente','Fecha','Nombre','Código/SKU','Unidades','Comisión',TAX_NAME,'Descuento','Subtotal','Total');
	$var 		= array();

	if(validateResultFromDB($result)){
		include_once("libraries/parsecsv.lib.php");

		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		$allCustomersArray 		= getAllContactsRaw(1,0);
		$field 					= $result->fields;

		while (!$result->EOF) {
			$var[$x]['sucursal'] = $getAllOutlets[$field['outletId']];

			$x++;
			$result->MoveNext();
		}

	}

	dai();
}

if(validateHttp('action') == 'delete' && validateHttp('id')){
	$id 	= dec($db->Prepare(validateBool('id')));
	$delete = $db->Execute("DELETE FROM production WHERE productionId = " . $id . " AND " . $SQLcompanyId . " LIMIT 1");
	if($delete === false){
		dai('false');
	}else{
		dai('true');
	}
}

if(validateHttp('action') == 'recipe'){
	if(!validateHttp('id')){
		dai('<h1>No encontrado</h1>');
	}

	$id 	= dec($db->Prepare(validateBool('id')));
	$result = ncmExecute('SELECT itemId, itemProcedure FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[$id,COMPANY_ID]);
	if($result){
		$compounds 	= getCompoundsArray($result['itemId']);
		$cant 		= (validateBool('cant')) ? validateBool('cant') : '1';
		$outlet		= validateBool('outlet');
		$date		= validateBool('date');
		$user		= validateBool('user');
		//[{"id":"GJO5","units":"2.00000"},{"id":"b37m","units":"1.00000"},{"id":"EeEL","units":"0.20000"}]
		?>
		<div class="col-xs-12 panel m-n wrapper" id="recipe">
			<div class="col-xs-12 wrapper h2 text-center font-bold">
				<?=getItemName($id)?> <span class="text-muted">x<?=formatCurrentNumber($cant,'yes')?></span>
				<div class="font-normal text-sm m-t-xs">
					<?=$user?> - <?=$outlet?> <br> <em><?=$date?></em>
				</div>
			</div>
			<table class="table">
				<tr>
					<th>
						Compuesto
					</th>
					<th class="text-center">
						Cant. Neta
					</th>
					<th class="text-center">
						% Merma
					</th>
					<th class="text-center">
						Cant. Bruta
					</th>
					<th>
						Medida
					</th>
				</tr>
				<?php
				foreach ($compounds as $comp) {
					$itmId 		= ($comp['compoundId']);
					$itmData 	= getItemData($itmId);
					$itmName 	= $itmData['itemName'];
					$itmUOM 	= $itmData['itemUOM'];
					$itmWaste 	= ($itmData['itemWaste']) ? $itmData['itemWaste'] : "0";
					$itmUnitsR 	= $comp['toCompoundQty'] * $cant;
					$itmTotal 	= $itmUnitsR;

					if(validity($itmWaste)){
						$wasteAmount 	= $itmUnitsR / $itmWaste;
						$itmTotal 		= $itmUnitsR + $wasteAmount;
					}

					$itmUnits 	= formatCurrentNumber($itmUnitsR,'si',false,'4');
					$itmTotal 	= formatCurrentNumber($itmTotal,'si',false,'4');

					?>
					<tr>
						<td>
							<?=$itmName?>
						</td>
						<td class="text-right">
							<?=$itmUnits?>
						</td>
						<td class="text-right">
							<?=$itmWaste?>%
						</td>
						<td class="text-right">
							<?=$itmTotal?>
						</td>
						<td>
							<span class="label bg-light"><?=$itmUOM?></span>
						</td>
					</tr>
					<?php

					if($_GET['doit']){
						$row['stockSource']   	= 'Production';
						$row['stockDate']		= $date;
						$row['stockCount']   	= '-' . $itmUnitsR;
						$row['stockCOGS']   	= 0;
						$row['stockOnHand']   	= 0;
						$row['stockOnHandCOGS'] = 0;
						$row['itemId'] 			= $itmId;
						$row['outletId'] 		= OUTLET_ID;
						$row['companyId']		= COMPANY_ID;
					    
					    $insert = $db->AutoExecute('stock', $row, 'INSERT');
					}

				}
				?>
			</table>
			<div class="col-xs-12 wrapper m-t bg-light lter r-24x">
				<strong>Procedimiento: </strong>
				
				<?=($result['itemProcedure']) ? $result['itemProcedure'] : '<em>Sin información</em>'?>
			</div>
			<div class="text-center col-xs-12 m-t">
				<a href="#" class="print btn btn-default font-bold text-u-c rounded hidden-print" data-id="#recipe">Imprimir</a>
				<div class="visible-print font-bold b-t m-t-md col-xs-4">Firma</div>
			</div>
		</div>
		<?
	}

	dai();
}

if(validateHttp('action') == 'detailTable'){
	$isDetail = true;

	$sql = "SELECT * FROM production WHERE productionDate BETWEEN ? AND ? " . getROC(1) . " AND productionType = 1 ORDER BY productionDate DESC";

	$result   		= ncmExecute($sql,[$startDate,$endDate],true,true);

	if($result){
		while (!$result->EOF) {
			$fields 		= $result->fields;
			$wasteValue 	= 0;

			$productionItem[$fields['itemId']][] 	= 	[
															'units' 	=> $fields['productionCount'],
															'wasteValue'=> $fields['productionWasteValue'],
															'cogs' 		=> $fields['productionCOGS'],
															'type' 		=> $fields['productionType'],
															'recipe' 	=> $fields['recipe'],
															'date' 		=> $fields['productionDate'],
															'outlet' 	=> $fields['outletId'],
															'user' 		=> $fields['userId']
														];
			
			$result->MoveNext();
		}	
	}



	$sql = "SELECT 	a.itemId 		as id, 
			a.itemSoldUnits 		as usold, 
			a.itemSoldCOGS 			as cogs,
			a.userId 				as user,
			a.itemSoldDate 			as date,
			c.itemWaste 			as waste,
			b.outletId 				as outlet
		FROM 
			itemSold a, transaction b, item c
		WHERE 
			b.transactionType IN (0,3)
		AND b.transactionDate BETWEEN ? AND ? 
		" . $roc . "
		AND a.transactionId 		= b.transactionId
		AND a.itemId 				= c.itemId
		AND c.itemType 				= 'direct_production' 
		ORDER BY usold DESC";

	$result   		= ncmExecute($sql,[$startDate,$endDate],true,true);

	if($result){
		while (!$result->EOF) {
			$fields = $result->fields;
			$wasteValue 	= 0;

			$productionItem[$fields['id']][] 	= [
													'units' 	=> $fields['usold'],
													'waste' 	=> $fields['waste'],
													'wasteValue'=> $wasteValue,
													'cogs' 		=> $fields['cogs'],
													'type' 		=> 'direct_production',
													'recipe' 	=> $fields['recipe'],
													'date' 		=> $fields['date'],
													'outlet' 	=> $fields['outlet'],
													'user' 		=> $fields['user']
													];			
			
			$result->MoveNext();
		}	
	}

	$table = '';

	$head = 	'<thead class="text-u-c">'.
				'	<tr>' .
				'		<th>Fecha</th>' .
				'		<th>Usuario</th>' .
				'		<th>Producto</th>'.
				'		<th>Código/SKU</th>'.
				'		<th>Categoría</th>'.
				'		<th>Sucursal</th>'.
				'		<th>Tipo</th>'.
				'		<th class="text-center">Unidades</th>' .
				'		<th class="text-center">Costo Unitario</th>'.
				'		<th class="text-center">Costo Total</th>'.
				'		<th class="text-center">Valor de Merma</th>' .
				'		<th class="text-center">Utilidad</th>' .
				' 		<th></th>' .
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	if(validity($productionItem)){
		$taxes 		= getAllTax();
		$category 	= getAllItemCategories();
		$x 			= 0;

		foreach($productionItem as $itemId => $values){
			$itm 		= getItemData($itemId);
			if($itm){
				foreach ($values as $key => $value) {
					$builded 	= buildTableList($itm,$value);
					$table 		.= $builded['table'];
					$tUsold 	+= $builded['units'];
					$tCOGS 		+= $builded['cogs'];
					$tUtility	+= $builded['utility'];
				}
				$x++;
			}
		}
	}

	$foot .= 	'</tbody>'.
			 	'<tfoot>' .
				'	<tr>'.
				'		<th colspan="7">TOTALES:</th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th></th>' .
				'	</tr>' .
				'</tfoot>';

	$fullTable 				= $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;
	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));
}

if(validateHttp('action') == 'generalTable'){
	$isDetail = false;
	$sql = "SELECT * FROM production WHERE productionDate BETWEEN ? AND ? " . getROC(1) . " AND productionType = 1 ORDER BY productionDate DESC";

	$result   		= ncmExecute($sql,[$startDate,$endDate],true,true);

	if($result){
		while (!$result->EOF) {
			$fields 		= $result->fields;
			$wasteValue 	= 0;

			if($productionItem[$fields['itemId']]){
				$productionItem[$fields['itemId']]['units'] 		+= $fields['productionCount'];
				$productionItem[$fields['itemId']]['wasteValue'] 	+= $wasteValue;
				$productionItem[$fields['itemId']]['cogs'] 			+= $fields['productionCOGS'];
			}else{
				$productionItem[$fields['itemId']] 	= 	[
															'units' 	=> $fields['productionCount'],
															'wasteValue'=> $wasteValue,
															'cogs' 		=> $fields['productionCOGS'],
															'type' 		=> $fields['productionType']
														];
			}		
			
			$result->MoveNext();
		}	
	}

	$sql = "SELECT 	a.itemId 		as id, 
			SUM(a.itemSoldUnits) 	as usold, 
			SUM(a.itemSoldCOGS) 	as cogs,
			a.userId 				as user,
			a.itemSoldDate 			as date,
			c.itemWaste 			as waste,
			b.outletId 				as outlet
		FROM 
			itemSold a, transaction b, item c
		WHERE 
			b.transactionType IN (0,3)
		AND b.transactionDate BETWEEN ? AND ? 
		" . $roc . "
		AND a.transactionId 		= b.transactionId
		AND a.itemId 				= c.itemId
		AND c.itemType 				= 'direct_production' 
		GROUP BY id 
		ORDER BY usold DESC";

	$result   		= ncmExecute($sql,[$startDate,$endDate],false,true);

	if($result){
		while (!$result->EOF) {
			$fields = $result->fields;

			//$unWastedCOGS 	= getProductionCOGS($fields['id'],false) * $fields['usold'];
			$wasteValue 	= 0;//($fields['cogs'] - $unWastedCOGS);

			$productionItem[$fields['id']] 	= [
												'outlet' 	=> $fields['outlet'],
												'units' 	=> $fields['usold'],
												'waste' 	=> $fields['waste'],
												'wasteValue'=> $wasteValue,
												'cogs' 		=> $fields['cogs'],
												'type' 		=> 'direct_production',
												'recipe' 	=> $fields['recipe'],
												'date' 		=> $fields['date'],
												'outlet' 	=> $fields['outlet'],
												'user' 		=> $fields['user']
												];
			
			$result->MoveNext();
		}	
	}

	$table = '';

	$head = 	'<thead class="text-u-c">'.
				'	<tr>' .
				'		<th>Producto</th>'.
				'		<th>Código/SKU</th>'.
				'		<th>Categoría</th>'.
				'		<th>Sucursal</th>'.
				'		<th>Tipo</th>'.
				'		<th class="text-center">Unidades</th>' .
				'		<th class="text-center">Costo Unitario</th>'.
				'		<th class="text-center">Costo Total</th>'.
				'		<th class="text-center">Valor de Merma</th>' .
				'		<th class="text-center">Utilidad</th>' .
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	if(validity($productionItem)){
		$taxes 		= getAllTax();
		$category 	= getAllItemCategories();
		$x 			= 0;

		foreach($productionItem as $itemId => $values){
			$itm 		= getItemData($itemId);
			if($itm){
				$builded 	= buildTableList($itm,$values);
				$table 		.= $builded['table'];
				$tUsold 	+= $builded['units'];
				$tCOGS 		+= $builded['cogs'];
				$tUtility	+= $builded['utility'];
				$x++;
			}
		}
	}

	$foot .= 	'</tbody>'.
			 	'<tfoot>' .
				'	<tr>'.
				'		<th colspan="5">TOTALES:</th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'	</tr>' .
				'</tfoot>';

	$fullTable 				= $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;
	$jsonResult['data'] 	= ['qty' => formatCurrentNumber($tUsold),'cogs' => formatCurrentNumber($tCOGS),'utility' => formatCurrentNumber($tUtility)];
	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));
}
?>
<?=menuReports('',false);?>
<?=reportsTitle('Reporte de Producción',true);?>

<div class="col-xs-12 m-b-lg no-padder text-center">
	<section class="col-md-1 col-sm-12"></section>

	<section class="col-md-3 col-sm-6">
		<div class="b-b text-center wrapper-md">
			<div class="h1 m-t total font-bold globalQty"><?=placeHolderLoader()?></div>
			Cantidad
		</div>
	</section>

	<section class="col-md-4 col-sm-12">
		<div class="b-b text-center wrapper-md">
			<div class="h1 m-t total font-bold globalCogs"><?=placeHolderLoader()?></div>
			Costo Total
		</div>
	</section>

	<section class="col-md-3 col-sm-6">
		<div class="b-b text-center wrapper-md">
			<div class="h1 m-t total font-bold globalUtility"><?=placeHolderLoader()?></div>
			Utilidad
		</div>
	</section>

	<section class="col-md-1 col-sm-12"></section>
</div>

<div class="col-xs-12 no-padder">
	<section class="col-sm-12 no-padder push-chat-down" id="reportsTablesAndTabs">
	    <ul class="nav nav-tabs wrap-l-md wrap-r-md">
	        <li class="active">
	            <a href="#tab1" data-toggle="tab">General</a>
	        </li>
	        <li class="" id="detailTabLink">
	            <a href="#tab2" data-toggle="tab">Detallado</a>
	        </li>
	        <li class="" id="compoundTabLink">
	            <a href="#tab3" data-toggle="tab">Compuestos</a>
	        </li>
	    </ul>
	    <section class="panel table-responsive r-24x">
	        <div class="panel-body">
	            <div class="tab-content m-b-lg">
	                <div class="tab-pane overflow-auto active" id="tab1" style="min-height:500px">
	                	<div id="generalTable">   
				        	<table class="table table1 col-xs-12 no-padder" id="table1"><?=placeHolderLoader('table')?></table>
					    </div>
					</div>
					<div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab2" style="min-height:500px">
	                	<div id="detailTable">
	                    	<table class="table table2 hover col-xs-12 no-padder" id="table2"><?=placeHolderLoader('table')?></table>
	                    </div>
	                </div>
	                <div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab3" style="min-height:500px">
	                	<div id="compoundTable">
	                    	<table class="table table3 col-xs-12 no-padder" id="table3"><?=placeHolderLoader('table')?></table>
	                    </div>
	                </div>
	                <div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab4" style="min-height:500px">
	                	<div id="compoundTableDays">
	                    	<table class="table table4 col-xs-12 no-padder" id="table4"><?=placeHolderLoader('table')?></table>
	                    </div>
	                </div>
				</div>
			</div>
		</section>
	</section>
</div>

<script>
var baseUrl = '<?=$baseUrl?>';
$(document).ready(function(){
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>",false,true);

	var rawUrl 	= baseUrl + "?action=generalTable";
	var url 	= rawUrl;
	$.get(url,function(result){
		var options = {
						"container" 	: "#generalTable",
						"url" 			: url,
						"rawUrl" 		: rawUrl,
						"iniData" 		: result.table,
						"table" 		: ".table1",
						"sort" 			: 5,
						"footerSumCol" 	: [5,6,7,8,9],
						"currency" 		: "<?=CURRENCY?>",
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 		: <?=$offsetDetail?>,
						"limit" 		: <?=$limitDetail?>,
						"nolimit" 		: true,
						"ncmTools"		: {
											left 	: '<a href="#" class="btn btn-default exportTable" data-table="table1" data-name="reporte_de_produccion_general">Exportar Listado</a>',
											right 	: ''
										  },
						"colsFilter"	: {
											name 	: 'production',
											menu 	:  [
															{"index":0,"name":"Producto","visible":true},
															{"index":1,"name":"Código/SKU","visible":false},
															{"index":2,"name":'Categoría',"visible":true},
															{"index":3,"name":'Sucursal',"visible":false},
															{"index":4,"name":'Tipo',"visible":true},
															{"index":5,"name":'Unidades',"visible":true},
															{"index":6,"name":'Costo Unitario',"visible":false},
															{"index":7,"name":'Costo Total',"visible":true},
															{"index":8,"name":'Valor de Merma',"visible":false},
															{"index":9,"name":'Utilidad',"visible":false}
														]
										  }
		};

		manageTableLoad(options);

		$('.globalQty').text(result.data.qty);
		$('.globalCogs').text(result.data.cogs);
		$('.globalUtility').text(result.data.utility);

	});

	var detailTableOpen = false;
	$('#detailTabLink').on('shown.bs.tab', function (e) {
	    if(!detailTableOpen){

	    	var rawUrl 	= baseUrl + "?action=detailTable";
			var url 	= rawUrl;
			$.get(url,function(result){
				var options = {
								"container" 	: "#detailTable",
								"url" 			: url,
								"rawUrl" 		: rawUrl,
								"iniData" 		: result.table,
								"table" 		: ".table2",
								"sort" 			: 7,
								"footerSumCol" 	: [7,8,9,10,11],
								"currency" 		: "<?=CURRENCY?>",
								"decimal" 		: decimal,
								"thousand" 		: thousandSeparator,
								"offset" 		: <?=$offsetDetail?>,
								"limit" 		: <?=$limitDetail?>,
								"nolimit" 		: true,
								"ncmTools"		: {
													left 	: '<a href="#" class="btn btn-default exportTable" data-table="table2" data-name="reporte_de_produccion_detallado">Exportar Listado</a>',
													right 	: ''
												  },
								"colsFilter"	: {
													name 	: 'productionDetail',
													menu 	:  [
																	{"index":0,"name":"Fecha","visible":false},
																	{"index":1,"name":"Usuario","visible":false},
																	{"index":2,"name":"Producto","visible":true},
																	{"index":3,"name":"Código/SKU","visible":false},
																	{"index":4,"name":'Categoría',"visible":true},
																	{"index":5,"name":'Sucursal',"visible":false},
																	{"index":6,"name":'Tipo',"visible":true},
																	{"index":7,"name":'Unidades',"visible":true},
																	{"index":8,"name":'Costo Unitario',"visible":false},
																	{"index":9,"name":'Costo Total',"visible":true},
																	{"index":10,"name":'Valor de Merma',"visible":false},
																	{"index":11,"name":'Utilidad',"visible":false},
																	{"index":12,"name":'Acciones',"visible":false}
																]
												  }
				};

				manageTableLoad(options,function(){
					$('[data-toggle="tooltip"]').tooltip();
					onClickWrap('.clickrow',function(event,tis){
						var load = tis.data('load');
						loadForm(load,'#modalSmall .modal-content',function(){
							$('#modalSmall').modal('show');
						});
					},false,true);
				});
			});

			detailTableOpen = true;
		}
	});
	
	window.compoundTableOpen = false;
	$('#compoundTabLink').on('shown.bs.tab', function (e) {
	    if(!window.compoundTableOpen){

	    	var rawUrl 	= baseUrl + "?action=compoundTable";
			var url 	= rawUrl;
			$.get(url,function(result){
				var options = {
								"container" 	: "#compoundTable",
								"url" 			: url,
								"rawUrl" 		: rawUrl,
								"iniData" 		: result.table,
								"table" 		: ".table3",
								"sort" 			: 2,
								"footerSumCol" 	: [2,3],
								"currency" 		: "<?=CURRENCY?>",
								"decimal" 		: decimal,
								"thousand" 		: thousandSeparator,
								"offset" 		: <?=$offsetDetail?>,
								"limit" 		: <?=$limitDetail?>,
								"nolimit" 		: true,
								"ncmTools"		: {
													left 	: '<a href="#" class="btn btn-default exportTable" data-table="table3" data-name="compuestos_usados">Exportar Listado</a><a href="#" id="compundsByDay" class="btn btn-default">Por día</a>',
													right 	: ''
												  },
								"colsFilter"	: {
													name 	: 'compounds',
													menu 	:  [
																	{"index":0,"name":"Producto","visible":true},
																	{"index":1,"name":"Código/SKU","visible":false},
																	{"index":2,"name":"Cantidad","visible":true},
																	{"index":3,"name":"Costo","visible":true}
																]
												  }
				};

				manageTableLoad(options,function(){
					onClickWrap('#compundsByItem',function(event,tis){
						$('#tab4,#tab3').toggleClass('active');
					},false,true);

					window.compoundTableDayOpen = false;

					onClickWrap('#compundsByDay',function(event,tis){
						$('#tab4,#tab3').toggleClass('active');
						if(!window.compoundTableDayOpen){
							var rawUrl 	= baseUrl + "?action=compoundTableDay";
							var url 	= rawUrl;
							$.get(url,function(result){
								var options = {
												"container" 	: "#compoundTableDays",
												"url" 			: url,
												"rawUrl" 		: rawUrl,
												"iniData" 		: result.table,
												"table" 		: ".table4",
												"sort" 			: 0,
												"footerSumCol" 	: [3,4],
												"currency" 		: "<?=CURRENCY?>",
												"decimal" 		: decimal,
												"thousand" 		: thousandSeparator,
												"offset" 		: <?=$offsetDetail?>,
												"limit" 		: <?=$limitDetail?>,
												"nolimit" 		: true,
												"ncmTools"		: {
																	left 	: '<a href="#" class="btn btn-default exportTable" data-table="table4" data-name="compuestos_usados_por_dia">Exportar Listado</a><a href="#" id="compundsByItem" class="btn btn-default">Por Compuesto</a>',
																	right 	: ''
																  },
												"colsFilter"	: {
																	name 	: 'compoundsDay',
																	menu 	:  [
																					{"index":0,"name":"Fecha","visible":true},
																					{"index":1,"name":"Producto","visible":true},
																					{"index":2,"name":"Código/SKU","visible":false},
																					{"index":3,"name":"Cantidad","visible":true},
																					{"index":4,"name":"Costo","visible":true}
																				]
																  }
								};

								manageTableLoad(options);
								window.compoundTableDayOpen = true;
							});
						}
					},false,true);

				});
			});			

			window.compoundTableOpen = true;
		}
	});

	
	

	onClickWrap('.print',function(event,tis){
		var id = tis.data('id');
		$(id).print();
	},false,true);

	onClickWrap('.delete',function(event,tis){
		var id 		= tis.attr('data-id');
		var $row 	= $('#' + id);
		$.get(baseUrl + '?action=delete&id=' + id,function(result){
			if(result == 'true'){
				$row.remove();
				message('Eliminado','success');
			}else{
				message('No se pudo eliminar','danger');
			}
		});
	},false,true);

	onClickWrap('#reportDownload',function(event,tis){
		var url = baseUrl + '?action=detailTable&from=<?=$startDate?>&to=<?=$endDate?>&export=true';
		window.open(url);
	},false,true);

	onClickWrap('#reportCompoundDownloadByDay',function(event,tis){
		var url = baseUrl + '?action=compoundTable&export=true';
		window.open(url);
	},false,true);

});

</script>

<?php
dai();
?>