<?php
include_once('includes/top_includes.php');

topHook();
allowUser('sales','view');

$baseUrl = '/' . basename(__FILE__,'.php');
$MAX_DAYS_RANGE = 31;
if(SAAS_ADM){
	//$MAX_DAYS_RANGE = 31 * 12;
}

$startPageLoad 	= startPageLoadTimeCalculator();

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc = str_replace(['registerId','outletId','companyId'],['b.registerId','b.outletId','b.companyId'],getROC(1));
 
$maxItemsInGraph 	= 20;
$itemsArray 		= [];
$itemsArrayDetail 	= [];
$isdata 			= false;
$limitDetail		= 100;
$offsetDetail		= 0;

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

if(validateHttp('action') == 'detailTable'){
	//theErrorHandler('json');
	//error_reporting(E_ALL);
	//ini_set('display_errors', 'On');
	ini_set('memory_limit', '256M');

	$roc 		= str_replace(['outletId','registerId','companyId'],['a.outletId','a.registerId','a.companyId'],getROC(1));
	$table 		= '';
	$limits 	= getTableLimits($limitDetail,$offsetDetail);

	if(validateHttp('src')){
		$word 	= db_prepare(validateHttp('src'));
		//primero obtengo posible fuente
		$sData 	= ncmExecute('SELECT STRING_AGG(itemId::text, \',\') as ids FROM item WHERE (itemName LIKE \'%\' . $word . \'%\' OR itemSKU LIKE \'%\' . $word . \'%\') AND companyId = ? AND itemStatus = 1 LIMIT 100',[COMPANY_ID],true);
		
		$search = ' AND b.itemId IN(' . $sData['ids'] . ')';

		$sql = 'SELECT 
					a.customerId as customer,
					a.userId as trsUser,
					a.outletId,
					a.registerId,
					a.invoiceNo,
					a.invoicePrefix,
					a.transactionType,
					b.itemSoldId,
					b.itemId,
					b.itemSoldUnits,
					b.itemSoldTotal, 
					b.itemSoldTax, 
					b.itemSoldDiscount,
					b.itemSoldDate,
					b.itemSoldDescription,
					ABS(b.itemSoldCOGS) as itemSoldCOGS,
					b.itemSoldComission,
					b.itemSoldParent,
					b.transactionId,
					b.userId as itemUser
				FROM transaction a USE INDEX(transactionDate,transactionType), itemSold b
				WHERE a.transactionDate
				BETWEEN ?
				AND ? 
				' . $roc . '
				AND a.transactionType IN(0,3,6)
				AND a.transactionId = b.transactionId
				' . $search . '
				ORDER BY a.transactionDate DESC';


		$result 	= ncmExecute($sql,[$startDate,$endDate],true,true);
	}else{

		if(validateHttp('cusId')){
			$contactId = dec(validateHttp('cusId'));


			$sql = 'SELECT 
						a.customerId as customer,
						a.userId as trsUser,
						a.outletId,
						a.registerId,
						a.invoiceNo,
						a.invoicePrefix,
						a.transactionType,
						b.itemSoldId,
						b.itemId,
						b.itemSoldUnits,
						b.itemSoldTotal, 
						b.itemSoldTax, 
						b.itemSoldDiscount,
						b.itemSoldDate,
						b.itemSoldDescription,
						ABS(b.itemSoldCOGS) as itemSoldCOGS,
						b.itemSoldComission,
						b.itemSoldParent,
						b.transactionId,
						b.userId as itemUser
					FROM transaction a USE INDEX(transactionDate,transactionType), itemSold b
					WHERE a.transactionDate
					BETWEEN ?
					AND ?
					AND a.transactionType IN(0,3,6)
					AND a.customerId = ?
					' . $roc . '
					AND a.transactionId = b.transactionId
					ORDER BY a.transactionDate DESC' . $limits;

			$result 	= ncmExecute($sql,[COMPANY_DATE,TODAY,$contactId],true,true);
		}else if(validateHttp('usrId')){
			$contactID = dec(validateHttp('usrId'));

			$sql = 'SELECT 
						a.customerId as customer,
						a.userId as trsUser,
						a.outletId,
						a.registerId,
						a.invoiceNo,
						a.invoicePrefix,
						a.transactionType,
						b.itemSoldId,
						b.itemId,
						b.itemSoldUnits,
						b.itemSoldTotal, 
						b.itemSoldTax, 
						b.itemSoldDiscount,
						b.itemSoldDate,
						b.itemSoldDescription,
						ABS(b.itemSoldCOGS) as itemSoldCOGS,
						b.itemSoldComission,
						b.itemSoldParent,
						b.transactionId,
						b.userId as itemUser
					FROM transaction a USE INDEX(transactionType), itemSold b
					WHERE a.transactionDate
					BETWEEN ?
					AND ?
					AND a.transactionType IN(0,3,6)
					' . $roc . '
					AND a.transactionId = b.transactionId
					AND b.userId = ?
					ORDER BY a.transactionDate DESC' . $limits;

			$result 	= ncmExecute($sql,[$startDate,$endDate,$contactID],true,true);
		}else if(validateHttp('itmId')){
			$itemId = dec(validateHttp('itmId'));

			$sql = 'SELECT 
						a.customerId as customer,
						a.userId as trsUser,
						a.outletId,
						a.registerId,
						a.invoiceNo,
						a.invoicePrefix,
						a.transactionType,
						b.itemSoldId,
						b.itemId,
						b.itemSoldUnits,
						b.itemSoldTotal, 
						b.itemSoldTax, 
						b.itemSoldDiscount,
						b.itemSoldDate,
						b.itemSoldDescription,
						ABS(b.itemSoldCOGS) as itemSoldCOGS,
						b.itemSoldComission,
						b.itemSoldParent,
						b.transactionId,
						b.userId as itemUser
					FROM transaction a USE INDEX(transactionType), itemSold b
					WHERE a.transactionType IN(0,3,6)
					AND b.itemId = ?
					' . $roc . '
					AND a.transactionId = b.transactionId
					ORDER BY a.transactionDate DESC LIMIT 2000';

			$result 	= ncmExecute($sql,[$itemId],true,true);
		}else{

			$sql = 'SELECT 
						a.customerId as customer,
						a.userId as trsUser,
						a.outletId,
						a.registerId,
						a.invoiceNo,
						a.invoicePrefix,
						a.transactionType,
						a.tags,
						b.itemSoldId,
						b.itemId,
						b.itemSoldUnits,
						b.itemSoldTotal, 
						b.itemSoldTax, 
						b.itemSoldDiscount,
						b.itemSoldDate,
						b.itemSoldDescription,
						ABS(b.itemSoldCOGS) as itemSoldCOGS,
						b.itemSoldComission,
						b.itemSoldParent,
						b.transactionId,
						b.userId as itemUser
					FROM transaction a USE INDEX(transactionDate,transactionType), itemSold b
					WHERE a.transactionDate
					BETWEEN ?
					AND ? 
					' . $roc . '
					AND a.transactionType IN(0,3,6)
					AND a.transactionId = b.transactionId
					ORDER BY a.transactionDate DESC' . $limits;

			$result 	= ncmExecute($sql,[$startDate,$endDate],true,true);
		}

	} 

	$head 	= 	'<thead class="text-u-c">'.
				'	<tr>'.
				'		<th class="ignored">Sucursal</th>' .
				'		<th>Caja</th>' .
				'		<th># Documento</th>' .
				'		<th>Usuario</th>' .
				'		<th>Cliente</th>' .
				'		<th class="no-search">Fecha</th>' .
				'		<th>Nombre</th>' .
				'		<th>Código/SKU</th>' .
				'		<th>Marca</th>' .
				'		<th>Categoría</th>' .
				'		<th class="text-center no-search">Cantidad</th>' .
				'		<th class="text-center no-search">Comisión</th>'.
				'		<th class="text-center no-search">Costo</th>'.
				'		<th class="text-center no-search">' . TAX_NAME . '</th>'.
				'		<th class="text-center no-search">Descuentos</th>'.
				'		<th class="text-center no-search">Utilidad</th>' .
				'		<th class="text-center no-search">Total</th>'.
					'</tr>'.
				'</thead>'.
				'<tbody>';

	//export data

	if($result){
		$itemsIDsArr 			= getAllByIDBuild($result,'itemId');
		$allItems 	 			= getAllItems(false, true, implode(',', $itemsIDsArr), true);

		$itemsIDsArr 			= getAllByIDBuild($result, 'customer');
		$allContacts 			= getAllContacts(1, true, 'contactId', implode(',', $itemsIDsArr), true);
		$allContacts 			= $allContacts[0];

		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		$allUsersArray 			= getAllContactsRaw('0');
		$allTaxes 				= getAllTax();
		$cachedItemCOGS 		= [];
	
		while (!$result->EOF) {
			$fields 	= $result->fields;
			$id 		= $fields['itemId'];
			$tId 		= $fields['transactionId'];
			$itm 		= $allItems[$fields['itemId']];
			$itmCOGS 	= $fields['itemSoldCOGS'];
			$status 	= $itm['itemStatus'];

			//itemCOGS Reparo los que no se les asignó COGS
			//if(!$itmCOGS && $itmCOGS !== 0){

			/*if(!$itmCOGS || $itmCOGS < 0.0001){
				
				if(validity($cachedItemCOGS[$id])){
					$itmCOGS = $cachedItemCOGS[$id];
				}else{
					if($itm['itemType'] == 'direct_production'){
						$itmCOGS = getProductionCOGS($id);
					}else if(in_array($itm['itemType'], ['precombo','combo'])){
						$itmCOGS = getComboCOGS($id);
					}else{
						//obtengo el ultimo COGS que no sea Cero
						$itmCOGS = getItemLastStockOnHand($id);
					}
				}
				
				if($itmCOGS){
					$cachedItemCOGS[$id] = $itmCOGS;
					ncmUpdate([
								'records' 	=> ['itemSoldCOGS' => $itmCOGS], 
								'table' 	=> 'itemSold', 
								'where' 	=> 'itemSoldId = ' . $fields['itemSoldId'] . ' LIMIT 1'
							]);//records (arr), table (str), where (str)
				}
			}*/

			if($itm){
				$taxName 	= $allTaxes[$itm['taxId']]['name'];
				$name		= toUTF8(iftn($itm['itemName'],$fields['itemSoldDescription']));
				$sku		= $itm['itemSKU'];
				$category 	= getTaxonomyName($itm['categoryId'],false,false,true);
				$brand 		= getTaxonomyName($itm['brandId'],false,false,true);
			}else{
				$taxName 	= 0;
				if(!$fields['itemId'] && $fields['itemSoldDescription']){
					$name		= $fields['itemSoldDescription'];
					$status 	= 1;
				}else{
					$name		= '<i class="text-muted">Artículo Eliminado</i>';
				}
				
				$sku		= '';
				$category 	= '';
				$brand 		= '';
			}

			$uSold 		= (float) $fields['itemSoldUnits'];
			$discount	= (float) $fields['itemSoldDiscount'] * $uSold;
			$total 		= (float) $fields['itemSoldTotal'];
			$cogs 		= (float) $itmCOGS * $uSold;
			$comission 	= (float) $fields['itemSoldComission'];
			$tax 		= (float) $fields['itemSoldTax'];//getTaxOfPrice( getTaxValue($itm['taxId']), $total ); //$fields['itemSoldTax'];

			if($fields['itemSoldTax'] >= $fields['itemSoldTotal']){
				$tax 		= getTaxOfPrice( getTaxValue($itm['taxId']), $total );
				ncmUpdate([
								'records' 	=> ['itemSoldTax' => $tax], 
								'table' 	=> 'itemSold', 
								'where' 	=> 'itemSoldId = ' . $fields['itemSoldId'] . ' LIMIT 1'
							]);
			}
		    
		    $invoiceno 	= $fields['invoicePrefix'] . $fields['invoiceNo'];
		    $outlet 	= $getAllOutlets[$fields['outletId']]['name'];
		    $register 	= $getAllRegisters[$fields['registerId']]['name'];
		    $user 		= $allUsersArray[iftn($fields['itemUser'],$fields['trsUser'])]['contactName'] ?? '';

		    //customer data
		    $customer 	= $allContacts[$fields['customer']] ?? false;//getContactData($fields['customer'], 'uid',true);
			$ago 		= timeago($fields['itemSoldDate'],false);
			$fecha 		= niceDate($fields['itemSoldDate']);
			$type  		= $fields['transactionType'];

			$earned = $comission;
			// if($comission > 0){
			// 	$earned = $comission;
			// }else{	
			// 	$commission	= @$itm['comission'];
			// 	$earned 	= 0;

			// 	if($commission > 0){
			// 		$earned = divider(($commission * $total),100);
			// 	}
			// }

			$utility 	= ( $total - $cogs ) - $earned;
			//$utility 	= ($utility < 0) ? 0 : $utility;

			$discountf 	= formatCurrentNumber($discount);
			$earnedf 	= formatCurrentNumber($earned);
			$taxf 		= formatCurrentNumber($tax) . ' <span class="hidden">;' . $taxName . '%</span>';
			$totalf 	= formatCurrentNumber($total);
			$cogsf 		= formatCurrentNumber($cogs);
			$utilityf 	= formatCurrentNumber($utility);
			$typeName 	= '';
			$nameFilter = '';

			if(in_array($itm['itemType'], ['precombo','combo'])){//dinámico y predefinido
				$discountf 	= '-';
				$earnedf 	= '-';
				$taxf 		= '-';
				//$totalf 	= '-';
				$utilityf 	= '-';
				$cogsf 		= '-';

				$discount 	= 0;
				$earned 	= 0;				
				$utility 	= 0;
				$cogs 		= 0;
				//$total 		= 0;
				$tax 		= 0;

				$typeName 	= ' <span class="text-muted text-xs">(Kit/Combo)</span>';
			}else if($itm['itemType'] == 'dynamic'){
				$name 		= iftn($fields['itemSoldDescription'],$name);
				//$typeName 	= ' <span class="text-muted text-xs">(Servicio Dinámico)</span>';
			}else if($itm['itemType'] == 'comboAddons'){
				$typeName 	= ' <span class="text-muted text-xs">(Combo/Add-on)</span>';
			}else if($fields['itemSoldParent']){
				$name = '↳ ' . $name;
				$utility 	= 0;
				$total 		= 0;

				$totalf 	= 0;
				$utilityf 	= 0;

				$nameFilter = 'in_combo: ' . $name;
			}

			$customerDatas 	= isset($customer) ? join(' › ', array_filter([$customer['contactPhone'],$customer['contactEmail']])) : '';
			$customerDatas 	= iftn($customerDatas,$customerDatas,' › ' . $customerDatas);
			$cusName 		= toUTF8(iftn($customer['contactName'],$customer['contactSecondName']));

			$tagsAr 		= json_decode($fields['tags'] ?? '',true);
			$isInternal 	= isInternalSale($tagsAr) ? 'internal' : '';

			$table .= 	'<tr data-id="' . enc($tId) . '" class="clickrow pointer ' . $isInternal . '">' .
						'	<td>' . $outlet . '</td>' .
						'	<td>' . $register . '</td>' .
						'	<td class="text-right">' . $invoiceno . '</td>' .
						'	<td>' . $user . '</td>' .
						'	<td data-filter="' . $cusName . '">' . $cusName . '<div class="text-xs">' . $customerDatas . '</div>' . '</td>' .
						'	<td data-order="' . $fields['itemSoldDate'] . '"><span data-toggle="tooltip" data-placement="top" title="Hace ' . $ago . '">' . $fecha . '</span></td>' .
						'	<td data-filter="' . $nameFilter . '" class="' . (($status < 1) ? 'text-l-t' : '') . '"> ' . $name . $typeName . ' </td>' .
						'	<td> ' . $sku . ' </td>' .
						'	<td> ' . $brand . ' </td>' .
						'	<td> ' . $category . ' </td>' .
						'	<td class="tdNumeric" data-order="' . $uSold . '"> ' . formatQty($uSold) . ' </td>' .
						'	<td class="tdNumeric" data-order="' . $earned . '" data-format="money"> ' . $earnedf . ' </td>' .
						'	<td class="tdNumeric" data-order="' . $cogs . '" data-format="money"> ' . $cogsf . ' </td>' .
						'	<td class="tdNumeric" data-order="' . $tax . '" data-format="money"> ' . 
								$taxf .
						'	</td>' .
						'	<td class="tdNumeric" data-order="' . $discount . '" data-format="money"> ' . $discountf . ' </td>' .
						'	<td class="tdNumeric" data-order="' . $utility . '" data-format="money"> ' . $utilityf . ' </td>' .
						'	<td class="tdNumeric" data-order="' . $total . '" data-format="money"> ' . $totalf . ' </td>' .
						'</tr>';

			if(validateHttp('part')){
	        	$table .= '[@]';
	        }

			$result->MoveNext();
		}
		$result->Close();
	}

	$foot 	= 	'	</tbody>' .
				'	<tfoot>' .
				'		<tr>' .
				'			<th colspan="10">TOTALES</th>' .
				'			<th class="text-right"></th>' .
				'			<th class="text-right"></th>' .
				'			<th class="text-right"></th>' .
				'			<th class="text-right"></th>' .
				'			<th class="text-right"></th>' .
				'			<th class="text-right"></th>' .
				'			<th class="text-right"></th>' .
				'		</tr>' .
				'	</tfoot>';


	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable 				= $head . $table . $foot;
		$jsonResult['table'] 	= $fullTable;

		header('Content-Type: application/json');
		dai(json_encode($jsonResult));
	}
}

$jsonResult = [];

if(validateHttp('action') == 'generalTable'){

	$resultMonth 	= false;
	$resultB 		= false;

	if(validateHttp('cusId')){
		$contactId = dec(validateHttp('cusId'));

		$sql = "SELECT 	a.itemId as id, 
					SUM(a.itemSoldUnits) as usold, 
					SUM(a.itemSoldTotal) as total, 
					SUM(a.itemSoldTax) as tax,
					SUM(ABS(a.itemSoldCOGS)) as cogs,
					SUM(a.itemSoldComission) as comission,
					SUM(a.itemSoldDiscount) as discount
				FROM itemSold a, transaction b
				WHERE b.transactionType IN (0,3,6)
				AND b.customerId = ?
				" . $roc . "
				AND b.transactionId = a.transactionId
				GROUP BY id ORDER BY usold DESC";

		if($_GET['test']){
			echo $sql . ' - ' . $contactId;
			dai();
		}

		$result 	= ncmExecute($sql,[$contactId],true,true);

	}else if(validateHttp('usrId')){
		$contactID = dec(validateHttp('usrId'));

		$sql = "SELECT 	a.itemId as id, 
					SUM(a.itemSoldUnits) as usold, 
					SUM(a.itemSoldTotal) as total, 
					SUM(a.itemSoldTax) as tax,
					SUM(ABS(a.itemSoldCOGS)) as cogs,
					SUM(a.itemSoldComission) as comission,
					SUM(a.itemSoldDiscount) as discount
				FROM itemSold a, transaction b
				WHERE b.transactionType IN (0,3,6)
				AND b.transactionDate BETWEEN ? AND ? 
				AND b.userId = ?
				" . $roc . "
				AND a.transactionId = b.transactionId
				GROUP BY id ORDER BY usold DESC";

		$result 	= ncmExecute($sql,[$startDate,$endDate,$contactID],true,true);

	}else if(validateHttp('itmId')){
		$itemId = dec(validateHttp('itmId'));

		if(!validateHttp('month')){
			$sql = "SELECT 	a.itemId as id, 
						SUM(a.itemSoldUnits) as usold, 
						SUM(a.itemSoldTotal) as total, 
						SUM(a.itemSoldTax) as tax,
						SUM(ABS(a.itemSoldCOGS) * a.itemSoldUnits) as cogs,
						SUM(a.itemSoldComission) as comission,
						SUM(a.itemSoldDiscount * a.itemSoldUnits) as discount
					FROM itemSold a, transaction b
					WHERE b.transactionType IN (0,3,6)
					" . $roc . "
					AND a.itemId = ?
					AND a.transactionId = b.transactionId
					GROUP BY id ORDER BY usold DESC";

		
			$result 	= ncmExecute($sql,[$itemId],true,true);
		}else{

			$year           = iftn(validateHttp('year'),date('Y'));

			$sql = "SELECT 	a.itemId as id, 
						MONTH(a.itemSoldDate) as smonth,
						SUM(a.itemSoldUnits) as usold, 
						SUM(a.itemSoldTotal) as total, 
						SUM(a.itemSoldTax) as tax,
						SUM(ABS(a.itemSoldCOGS) * a.itemSoldUnits) as cogs,
						SUM(a.itemSoldComission) as comission,
						SUM(a.itemSoldDiscount * a.itemSoldUnits) as discount
					FROM itemSold a, transaction b
					WHERE b.transactionType IN (0,3,6)
					" . $roc . "
					AND a.transactionId = b.transactionId
					AND YEAR(a.itemSoldDate) = ?
					AND a.itemId = ?
					GROUP BY smonth, id ORDER BY smonth ASC";	
			
			$result 	= ncmExecute($sql,[$year,$itemId],true,true);
		}
		
	}else{

		$sql = "SELECT 	a.itemId as id, 
					SUM(a.itemSoldUnits) as usold, 
					SUM(a.itemSoldTotal) as total, 
					SUM(a.itemSoldTax) as tax,
					SUM(ABS(a.itemSoldCOGS) * a.itemSoldUnits) as cogs,
					SUM(a.itemSoldComission) as comission,
					SUM(a.itemSoldDiscount * a.itemSoldUnits) as discount
				FROM itemSold a, transaction b
				WHERE b.transactionType IN (0,3,6)
				AND b.transactionDate BETWEEN ? AND ? 
				" . $roc . "
				AND a.transactionId = b.transactionId
				GROUP BY id ORDER BY usold DESC";

		$result 	= ncmExecute($sql,[$startDate,$endDate],true,true);

		list($startDateBack,$endDateBack) = getPreviousPeriod($startDate,$endDate);
		$resultB 	= ncmExecute($sql,[$startDateBack,$endDateBack],true,true);
	}

	if($result){
		$itemsIDsArr 		= getAllByIDBuild($result, 'id');
		$allItems 	 		= getAllItems(false, true, implode(',', $itemsIDsArr), true);
	}

	$bTotal 			= 0;
	$bCOGS 				= 0;
	$bUsold 			= 0;
	$bTax 				= 0;
	$bDiscount 			= 0;
	$bUtility 			= 0;
	$bComission 		= 0;
	if($resultB){
		$soldBack 			= [];
		$compoundsDiscount 	= getAllCombosCompoundsDiscount($roc,$startDateBack,$endDateBack);
		//$internals 			= lessInternalTotals($roc,$startDateBack,$endDateBack);

		while (!$resultB->EOF) {
			$fields 	= $resultB->fields;
			$itm 		= $allItems[$fields['id']] ?? [];
			$lessComp 	= $compoundsDiscount[$fields['id'] ] ?? [];
			
			$uSold 		= (float) $fields['usold'] - ($lessComp['itemSoldUnits'] ?? 0);
			$discount	= (float) ($fields['discount'] * $uSold) - ($lessComp['itemSoldDiscount'] ?? 0);
			$total 		= (float) $fields['total'] - ($lessComp['itemSoldTotal'] ?? 0);
			$comission 	= (float) $fields['comission'] - ($lessComp['itemSoldComission'] ?? 0);
			$tax 		= (float) $fields['tax'] - ($lessComp['itemSoldTax'] ?? 0);
			$cogs 		= (float) $fields['cogs'] - ($lessComp['itemSoldCOGS'] ?? 0);

			$utility 	= ( ( $total - $tax ) - $cogs ) - $comission;
			//$utility 	= ($utility < 0) ? 0 : $utility;

			if(isset($itm['itemType']) && in_array($itm['itemType'], ['precombo','combo'])){//dinámico y predefinido
				$discount 	= 0;
				$comission 	= 0;				
				$utility 	= 0;
				$cogs 		= 0;
				//$total 		= 0;
				$tax 		= 0;
			}else if(isset($fields['soldParent'])){
				//if($cogs > $total){
					$utility 	= 0;
					$total 		= 0;

					$totalf 	= 0;
					$utilityf 	= 0;
				//}
			}

			$discount 	= $discount;
			$bUsold 	+= $uSold;
			$bTax 		+= $tax;
			$bDiscount 	+= $discount;
			$bTotal 	+= $total;
			$bComission += $comission;
			$bCOGS 		+= $cogs;
			$bUtility 	+= $utility;

			if($fields['total'] > 0){
				$soldBack[$fields['id']] = $fields['usold'];
			}

			$resultB->MoveNext();
		}
	}

	$table = '';

	$head = 	 '<thead class="text-u-c">'.
				 '		<tr>'.
				 '			<th class="bg-white">Nombre</th>'.
				 '			<th>Código/SKU</th>'.
				 '			<th>Marca</th>'.
				 '			<th>Categoría</th>'.
				 '			<th class="text-center">Precio Uni.</th>' .
				 '			<th class="text-center">Cantidad</th>' .
				 '			<th class="text-center">Comisión</th>' .
				 '			<th class="text-center">Costo</th>' .
				 '			<th class="text-center">' . TAX_NAME . '</th>'.
				 '			<th class="text-center">Descuentos</th>'.
				 '			<th class="text-center">Utilidad</th>' .
				 '			<th class="text-center">Total</th>'.
				 '		</tr>'.
				 '</thead>'.
				 '<tbody>';

	$barLabel 	= [];
	$barData 	= [];
	$barDataB 	= [];
	$label 		= '';
	$data 		= '';

	$tUsold 	= 0;
	$tTax 		= 0;
	$tDiscount 	= 0;
	$tTotal 	= 0;
	$tUtility 	= 0;
	$tComission = 0;
	$tCOGS 		= 0;
	$cachedItemCOGS = [];
	if($result){
		$taxes 		= getAllTax();
		$brand 		= getAllItemBrands();
		$category 	= getAllItemCategories();

		$compoundsDiscount 	= getAllCombosCompoundsDiscount($roc,$startDate,$endDate);

		$x 			= 0;
		while (!$result->EOF) {
			$fields 	= $result->fields;
			$id 		= $fields['id'];
			$itm 		= $allItems[$fields['id']];
			$lessComp 	= $compoundsDiscount[$fields['id']] ?? [];

			if(!validateHttp('month')){
				$nameSort = '';

				if($itm){
					$name			= toUTF8(iftn($itm['itemName'] ?? false ,$fields['description'] ?? ''));
					$taxName 		= $taxes[$itm['taxId']]['name'];
					$sku			= $itm['itemSKU'];
					$type 			= $itm['itemType'];
					$price 			= $itm['itemPrice'];
					$brandName 		= iftn($itm['brandId'] ?? false,'',$brand[$itm['brandId']]['name'] ?? '');
					$categoryName 	= iftn($itm['categoryId'],'' ,$category[$itm['categoryId']]['name'] ?? '');
					$dataUrl 		= '/@#report_products?ii=' . enc($id);
				}else{
					$name			= '<i class="text-muted">Artículo Eliminado</i>';
					$taxName 		= '';
					$sku			= '';
					$type 			= '';
					$price 			= 0;
					$brandName 		= '';
					$categoryName 	= ''; 
					$dataUrl 		= '';
				}
			}else{
				$month 			= $fields['smonth'] - 1;
				$name			= $meses[$month];
				$gname			= $name . ' Cant. ' . formatQty($fields['usold']) . ' ' . toUTF8($itm['itemOUM'] ?? '');
				$nameSort 		= $month;

				$taxName 		= $taxes[$itm['taxId']]['name'];
				$sku			= $itm['itemSKU'];
				$type 			= $itm['itemType'];
				$price 			= $itm['itemPrice'];
				$brandName 		= iftn($itm['brandId'],'',$brand[$itm['brandId']]['name']);
				$categoryName 	= iftn($itm['categoryId'],'',$category[$itm['categoryId']]['name'] ?? '');
				$dataUrl 		= '';
			}

			$uSold 		= (float) $fields['usold'];// - $lessComp['itemSoldUnits'];
			$discount	= (float) $fields['discount'];// - $lessComp['itemSoldDiscount'];
			$total 		= (float) $fields['total'];// - $lessComp['itemSoldTotal'];
			$comission 	= (float) $fields['comission'];// - $lessComp['itemSoldComission'];
			$tax 		= (float) $fields['tax'];// - $lessComp['itemSoldTax'];
			$COGS 		= (float) $fields['cogs'];// - $lessComp['itemSoldCOGS'];

			//itemCOGS Reparo los que no se les asignó COGS
			/*if(!$COGS && $COGS !== 0){
				
				if(validity($cachedItemCOGS[$id])){
					$COGS = $cachedItemCOGS[$id];
				}else{
					if($type == 'direct_production'){
						$COGS = getProductionCOGS($id);
					}else if(in_array($type, ['precombo','combo'])){
						$COGS = getComboCOGS($id);
					}else{
						$COGS = getItemCOGS($id);
					}
				}
		
				$cachedItemCOGS[$id] = $COGS;
			}*/

			$utility 	= ( $total - $COGS ) - $comission;
			//$utility 	= ($utility < 0) ? 0 : $utility;			

			$ftotal 	= formatCurrentNumber($total);
			$fdiscount	= formatCurrentNumber($discount);
			$futility 	= formatCurrentNumber($utility);
			$fcomission = formatCurrentNumber($comission);
			$ftax 		= formatCurrentNumber($tax);
			$fCOGS 		= formatCurrentNumber($COGS);

			$tUsold 	+= $uSold;
			$tTax 		+= 0;//$tax;
			$tDiscount 	+= $discount;
			$tCOGS 		+= $COGS;
			$tComission += $comission;
			$tTotal 	+= $total;
			$tUtility 	+= $utility;

			if(validateHttp('month')){
				$id = $month;
				$soldBack[$month] = $COGS;
			}

			$table .= 	'<tr class="' . ($parentId ?? '') . ' clickrow pointer" data-url="' . $dataUrl . '">' .
						'	<td data-sort="' . $nameSort . '">' . $name . '</td>' .
						'	<td>' . $sku . '</td>' .
						'	<td> ' . $brandName . ' </td>' .
						'	<td> ' . $categoryName . ' </td>' .
						'	<td class="tdNumeric" data-order="' . $price . '"> '.formatCurrentNumber($price).' </td>' .
						'	<td class="tdNumeric" data-order="' . $uSold . '">' . 
								formatQty($uSold) . 
						' 	</td>' .
						'	<td class="tdNumeric" data-order="' . $comission . '" data-format="money">' . 
								$fcomission . 
						'	</td>' .
						'	<td class="tdNumeric" data-order="' . $COGS . '" data-format="money">' . 
								$fCOGS . 
						'	</td>' .
						'	<td class="tdNumeric" data-order="'.$tax.'" data-format="money">' .
						'		<span data-toggle="tooltip" data-placement="top" title="'.$taxName.'%">'.$ftax.'</span>'.
						' 		<span class="hidden">'.$taxName.'%</span>' .
						'	</td>' .
						'	<td class="tdNumeric" data-order="'.$discount.'" data-format="money">' . 
								$fdiscount . 
						'	</td>' .
						'	<td class="tdNumeric" data-order="'.$utility.'" data-format="money"> ' . 
								$futility . 
						' 	</td>' .
						'	<td class="tdNumeric" data-order="'.$total.'" data-format="money">' .
								$ftotal .
						' 	</td>' . 
						'</tr>';
			
			if(validateHttp('part')){
	        	$table .= '[@]';
	        }
			
			if($x < $maxItemsInGraph && $total > 0){

				if(validateHttp('month')){
					$barLabel[]	= $gname;
					$barData[] 	= $total ? $total : "0";
				}else{
					$barLabel[]	= strip_tags($name);
					$barData[] 	= $uSold ? $uSold : "0";
				}
				
				$barDataB[] = isset($soldBack[$id]) ? $soldBack[$id] : "0";

				$x++;
			}

			$result->MoveNext();
		}

		$internals 			= lessInternalTotals(getROC(1),$startDate,$endDate);
		if($internals['total']){
			$table .= 	'<tr>' .
						'	<td class="font-bold">INTERNAS</td>' .
						'	<td></td>' .
						'	<td></td>' .
						'	<td></td>' .
						'	<td>-</td>' .
						'	<td class="tdNumeric" data-order="-' . $internals['qty'] . '">' . 
								'-' . formatQty($internals['qty']) . 
						' 	</td>' .
						'	<td class="tdNumeric" data-format="money">0</td>' .
						'	<td class="tdNumeric" data-format="money">0</td>' .
						'	<td class="tdNumeric" data-order="-' . $internals['tax'] . '" data-format="money">' .
								'-' . formatCurrentNumber($internals['tax']) .
						'	</td>' .
						'	<td class="tdNumeric" data-order="-' . $internals['discount'] . '" data-format="money">' . 
								'-' . formatCurrentNumber($internals['discount']) . 
						'	</td>' .
						'	<td class="tdNumeric" data-format="money">0</td>' .
						'	<td class="tdNumeric" data-order="-' . $internals['total'] . '" data-format="money">' .
								'-' . formatCurrentNumber($internals['total']) .
						' 	</td>' . 
						'</tr>';

			$tUsold 	-= $internals['qty'];
			$tTax 		-= 0;//$internals['tax'];
			$tDiscount 	-= $internals['discount'];
			$tTotal 	-= $internals['total'];
		}
	}

	$foot = 	'</tbody>' .
			 	'<tfoot>' .
				'	<tr>' .
				'		<th colspan="4">TOTALES</th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'	</tr>' .
				'</tfoot>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		
		$jsonResult['chart'] 	= [
									'barLabel' 	=> $barLabel,
									'barData' 	=> $barData,
									'barDataB' 	=> $barDataB
									];

		$jsonResult['table'] 		= $fullTable;

		$jsonResult['subtotal'] 	= formatCurrentNumber($tTotal);
		$jsonResult['subtotalB'] 	= comparePeriodsArrowsPercent($tTotal,$bTotal,formatCurrentNumber($bTotal));

		$jsonResult['cogs'] 		= formatCurrentNumber($tCOGS);
		$jsonResult['cogsB'] 		= comparePeriodsArrowsPercent($tCOGS,$bCOGS,formatCurrentNumber($bCOGS),true);

		$jsonResult['discount'] 	= formatCurrentNumber(($tDiscount + $tComission) + $tTax);
		$jsonResult['discountB'] 	= comparePeriodsArrowsPercent(($tDiscount + $tComission) + $tTax,($bDiscount + $bComission) + $bTax,formatCurrentNumber(($bDiscount + $bComission) + $bTax),true);

		$jsonResult['utility'] 		= formatCurrentNumber($tUtility);
		$jsonResult['utilityB']		= comparePeriodsArrowsPercent($tUtility,$bUtility,formatCurrentNumber($bUtility));

		header('Content-Type: application/json');
		dai(json_encode($jsonResult));
	}
}

if(validateHttp('action') == 'combosTable'){

	$resultMonth 	= false;
	$resultB 		= false;

	if(validateHttp('cusId')){
		$contactId = dec(validateHttp('cusId'));

		$sql = "SELECT 	a.itemId as id, 
					SUM(a.itemSoldUnits) as usold, 
					SUM(a.itemSoldTotal) as total, 
					SUM(a.itemSoldTax) as tax,
					SUM(a.itemSoldCOGS) as cogs,
					SUM(a.itemSoldComission) as comission,
					SUM(a.itemSoldDiscount) as discount
				FROM itemSold a, transaction b
				WHERE b.transactionType IN (0,3,6)
				AND b.customerId = ?
				" . $roc . "
				AND b.transactionId = a.transactionId
				GROUP BY id ORDER BY usold DESC";

		if($_GET['test']){
			echo $sql . ' - ' . $contactId;
			dai();
		}

		$result 	= ncmExecute($sql,[$contactId],true,true);

	}else if(validateHttp('usrId')){
		$contactID = dec(validateHttp('usrId'));

		$sql = "SELECT 	a.itemId as id, 
					SUM(a.itemSoldUnits) as usold, 
					SUM(a.itemSoldTotal) as total, 
					SUM(a.itemSoldTax) as tax,
					SUM(a.itemSoldCOGS) as cogs,
					SUM(a.itemSoldComission) as comission,
					SUM(a.itemSoldDiscount) as discount
				FROM itemSold a, transaction b
				WHERE b.transactionType IN (0,3,6)
				AND b.transactionDate BETWEEN ? AND ? 
				AND b.userId = ?
				" . $roc . "
				AND a.transactionId = b.transactionId
				GROUP BY id ORDER BY usold DESC";

		$result 	= ncmExecute($sql,[$startDate,$endDate,$contactID],true,true);

	}else if(validateHttp('itmId')){
		$itemId = dec(validateHttp('itmId'));

		if(!validateHttp('month')){
			$sql = "SELECT 	a.itemId as id, 
						SUM(a.itemSoldUnits) as usold, 
						SUM(a.itemSoldTotal) as total, 
						SUM(a.itemSoldTax) as tax,
						SUM(a.itemSoldCOGS * a.itemSoldUnits) as cogs,
						SUM(a.itemSoldComission) as comission,
						SUM(a.itemSoldDiscount * a.itemSoldUnits) as discount
					FROM itemSold a, transaction b
					WHERE b.transactionType IN (0,3,6)
					" . $roc . "
					AND a.itemId = ?
					AND a.transactionId = b.transactionId
					GROUP BY id ORDER BY usold DESC";

		
			$result 	= ncmExecute($sql,[$itemId],true,true);
		}else{

			$year           = iftn(validateHttp('year'),date('Y'));

			$sql = "SELECT 	a.itemId as id, 
						MONTH(a.itemSoldDate) as smonth,
						SUM(a.itemSoldUnits) as usold, 
						SUM(a.itemSoldTotal) as total, 
						SUM(a.itemSoldTax) as tax,
						SUM(a.itemSoldCOGS * a.itemSoldUnits) as cogs,
						SUM(a.itemSoldComission) as comission,
						SUM(a.itemSoldDiscount * a.itemSoldUnits) as discount
					FROM itemSold a, transaction b
					WHERE b.transactionType IN (0,3,6)
					" . $roc . "
					AND a.transactionId = b.transactionId
					AND YEAR(a.itemSoldDate) = ?
					AND a.itemId = ?
					GROUP BY smonth, id ORDER BY smonth ASC";	
			
			$result 	= ncmExecute($sql,[$year,$itemId],true,true);
		}
		
	}else{

		$sql = "SELECT 	a.itemId as id, 
					SUM(a.itemSoldUnits) as usold, 
					SUM(a.itemSoldTotal) as total, 
					SUM(a.itemSoldTax) as tax,
					SUM(a.itemSoldCOGS * a.itemSoldUnits) as cogs,
					SUM(a.itemSoldComission) as comission,
					SUM(a.itemSoldDiscount * a.itemSoldUnits) as discount
				FROM itemSold a, transaction b
				WHERE b.transactionType IN (0,3,6)
				AND b.transactionDate BETWEEN ? AND ? 
				" . $roc . "
				AND a.transactionId = b.transactionId
				GROUP BY id ORDER BY usold DESC";

		$result 	= ncmExecute($sql,[$startDate,$endDate],true,true);
	}

	if($result){
		$itemsIDsArr 		= getAllByIDBuild($result, 'id');
		$allItems 	 		= getAllItems(false, true, implode(',', $itemsIDsArr), true);
	}

	$table = '';

	$head = 	 '<thead class="text-u-c">'.
				 '		<tr>'.
				 '			<th class="bg-white">Nombre</th>'.
				 '			<th>Código/SKU</th>'.
				 '			<th>Marca</th>'.
				 '			<th>Categoría</th>'.
				 '			<th class="text-center">Precio Uni.</th>' .
				 '			<th class="text-center">Cantidad</th>' .
				 '			<th class="text-center">Comisión</th>' .
				 '			<th class="text-center">Costo</th>' .
				 '			<th class="text-center">' . TAX_NAME . '</th>'.
				 '			<th class="text-center">Descuentos</th>'.
				 '			<th class="text-center">Utilidad</th>' .
				 '			<th class="text-center">Total</th>'.
				 '		</tr>'.
				 '</thead>'.
				 '<tbody>';

	if($result){
		$taxes 		= getAllTax();
		$brand 		= getAllItemBrands();
		$category 	= getAllItemCategories();

		$label 		= '';
		$data 		= '';

		$tUsold 	= 0;
		$tTax 		= 0;
		$tDiscount 	= 0;
		$tTotal 	= 0;
		$tUtility 	= 0;
		$tComission = 0;
		$tCOGS 		= 0;
		$barLabel 	= [];
		$barData 	= [];
		$barDataB 	= [];
		$cachedItemCOGS = [];

		$x 			= 0;
		while (!$result->EOF) {
			$fields 	= $result->fields;
			$id 		= $fields['id'];
			$itm 		= $allItems[$fields['id']];
			$lessComp 	= $compoundsDiscount[$fields['id']];

			if( in_array($itm['itemType'], ['combo','precombo','comboAddons']) ){

				if(!validateHttp('month')){
					$nameSort = '';

					if($itm){
						$name			= toUTF8(iftn($itm['itemName'],$fields['description']));
						$taxName 		= $taxes[$itm['taxId']]['name'];
						$sku			= $itm['itemSKU'];
						$type 			= $itm['itemType'];
						$price 			= $itm['itemPrice'];
						$brandName 		= iftn($itm['brandId'],'',$brand[$itm['brandId']]['name']);
						$categoryName 	= iftn($itm['categoryId'],'',$category[$itm['categoryId']]['name']);
						$dataUrl 		= '/@#report_products?ii=' . enc($id);
					}else{
						$name			= '<i class="text-muted">Artículo Eliminado</i>';
						$taxName 		= '';
						$sku			= '';
						$type 			= '';
						$price 			= 0;
						$brandName 		= '';
						$categoryName 	= ''; 
						$dataUrl 		= '';
					}
				}else{
					$month 			= $fields['smonth'] - 1;
					$name			= $meses[$month];
					$gname			= $name . ' Cant. ' . formatQty($fields['usold']) . ' ' . toUTF8($itm['itemOUM']);
					$nameSort 		= $month;

					$taxName 		= $taxes[$itm['taxId']]['name'];
					$sku			= $itm['itemSKU'];
					$type 			= $itm['itemType'];
					$price 			= $itm['itemPrice'];
					$brandName 		= iftn($itm['brandId'],'',$brand[$itm['brandId']]['name']);
					$categoryName 	= iftn($itm['categoryId'],'',$category[$itm['categoryId']]['name']);
					$dataUrl 		= '';
				}

				$uSold 		= (float) $fields['usold'];
				$discount	= (float) $fields['discount'];
				$total 		= (float) $fields['total'];
				$comission 	= (float) $fields['comission'];
				$tax 		= (float) $fields['tax'];
				$COGS 		= (float) $fields['cogs'];

				$utility 	= ( ( $total - $tax ) - $COGS ) - $comission;
				//$utility 	= ($utility < 0) ? 0 : $utility;			

				$ftotal 	= formatCurrentNumber($total);
				$fdiscount	= formatCurrentNumber($discount);
				$futility 	= formatCurrentNumber($utility);
				$fcomission = formatCurrentNumber($comission);
				$ftax 		= formatCurrentNumber($tax);
				$fCOGS 		= formatCurrentNumber($COGS);

				$tUsold 	+= $uSold;
				$tTax 		+= $tax;
				$tDiscount 	+= $discount;
				$tCOGS 		+= $COGS;
				$tComission += $comission;
				$tTotal 	+= $total;
				$tUtility 	+= $utility;

				if(validateHttp('month')){
					$id = $month;
					$soldBack[$month] = $COGS;
				}

				$table .= 	'<tr class="' . $parentId . ' clickrow pointer" data-url="' . $dataUrl . '">' .
							'	<td data-sort="' . $nameSort . '">' . $name . '</td>' .
							'	<td>' . $sku . '</td>' .
							'	<td> ' . $brandName . ' </td>' .
							'	<td> ' . $categoryName . ' </td>' .
							'	<td class="tdNumeric" data-order="' . $price . '"> '.formatCurrentNumber($price).' </td>' .
							'	<td class="tdNumeric" data-order="' . $uSold . '">' . 
									formatQty($uSold) . 
							' 	</td>' .
							'	<td class="tdNumeric" data-order="' . $comission . '" data-format="money">' . 
									$fcomission . 
							'	</td>' .
							'	<td class="tdNumeric" data-order="' . $COGS . '" data-format="money">' . 
									$fCOGS . 
							'	</td>' .
							'	<td class="tdNumeric" data-order="'.$tax.'" data-format="money">' .
							'		<span data-toggle="tooltip" data-placement="top" title="'.$taxName.'%">'.$ftax.'</span>'.
							' 		<span class="hidden">'.$taxName.'%</span>' .
							'	</td>' .
							'	<td class="tdNumeric" data-order="'.$discount.'" data-format="money">' . 
									$fdiscount . 
							'	</td>' .
							'	<td class="tdNumeric" data-order="'.$utility.'" data-format="money"> ' . 
									$futility . 
							' 	</td>' .
							'	<td class="tdNumeric" data-order="'.$total.'" data-format="money">' .
									$ftotal .
							' 	</td>' . 
							'</tr>';
				
				if(validateHttp('part')){
		        	$table .= '[@]';
		        }
				
				if($x < $maxItemsInGraph && $total > 0){

					if(validateHttp('month')){
						$barLabel[]	= $gname;
						$barData[] 	= $total ? $total : "0";
					}else{
						$barLabel[]	= strip_tags($name);
						$barData[] 	= $uSold ? $uSold : "0";
					}
					
					$barDataB[] = $soldBack[$id] ? $soldBack[$id] : "0";

					$x++;
				}

				$lessComp = NULL;

			}

			$result->MoveNext();
		}
	}

	$foot .= 	'</tbody>' .
			 	'<tfoot>' .
				'	<tr>' .
				'		<th colspan="4">TOTALES</th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'	</tr>' .
				'</tfoot>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		
		$jsonResult['chart'] 	= [
									'barLabel' 	=> $barLabel,
									'barData' 	=> $barData,
									'barDataB' 	=> $barDataB
									];

		$jsonResult['table'] 		= $fullTable;

		$jsonResult['subtotal'] 	= formatCurrentNumber($tTotal);
		$jsonResult['subtotalB'] 	= comparePeriodsArrowsPercent($tTotal,$bTotal,formatCurrentNumber($bTotal));

		$jsonResult['cogs'] 		= formatCurrentNumber($tCOGS);
		$jsonResult['cogsB'] 		= comparePeriodsArrowsPercent($tCOGS,$bCOGS,formatCurrentNumber($bCOGS),true);

		$jsonResult['discount'] 	= formatCurrentNumber(($tDiscount + $tComission) + $tTax);
		$jsonResult['discountB'] 	= comparePeriodsArrowsPercent(($tDiscount + $tComission) + $tTax,($bDiscount + $bComission) + $bTax,formatCurrentNumber(($bDiscount + $bComission) + $bTax),true);

		$jsonResult['utility'] 		= formatCurrentNumber($tUtility);
		$jsonResult['utilityB']		= comparePeriodsArrowsPercent($tUtility,$bUtility,formatCurrentNumber($bUtility));

		header('Content-Type: application/json');
		dai(json_encode($jsonResult));
	}
}

echo menuReports('',false);

if(validateHttp('ci')){
	$cId 	= db_prepare(dec(validateHttp('ci')));
	$cData 	= getContactData($cId, 'uid');
	$name 	= getCustomerName($cData);

	$reportsTitle = [
						'title' 		=> '<div class="text-md text-right font-default">Artículos adquiridos por</div> ' . $name,
						'hideChart' 	=> true,
						'maxDays' 		=> $MAX_DAYS_RANGE,
						'hideDate' 		=> true
					];
}else if(validateHttp('ii')){
	$cId 	= db_prepare(dec(validateHttp('ii')));
	$cData 	= getItemData($cId);

	$reportsTitle = [
						'title' 		=> '<div class="text-md text-right font-default">Ventas de</div> ' . $cData['itemName'],
						'hideChart' 	=> validateHttp('m') ? false : true,
						'maxDays' 		=> $MAX_DAYS_RANGE,
						'hideDate' 		=> true
					];

	if(validateHttp('m') ){
		$company  = ncmExecute('SELECT createdAt FROM company WHERE companyId = ?',[COMPANY_ID]);
		$yCreated = date('Y', strtotime($company['createdAt']));
		$yNow     = date('Y');

		if($yNow > $yCreated){

			$pickerReplace =  '<span class="btn-group">' .
			                  ' <span class="dropdown" title="Año" data-placement="right">' .
			                  '   <a href="#" class="btn dropdown-toggle b b-light bg-white font-bold r-3x disabled" data-toggle="dropdown" aria-expanded="false" id="yearPickerBtn">' .
			                  '     <span class="material-icons m-r-xs">insert_chart_outlined</span>' . (validateHttp('y') ? validateHttp('y') : date('Y')) .
			                  '   </a>' .
			                  '   <ul class="dropdown-menu animated fadeIn speed-4x" role="menu">';

			                  while($yNow > $yCreated){
			                    $pickerReplace .=  '     <li>' .
			                                      '       <a class="text-default" href="/@#report_products?ii=' . validateHttp('ii') . '&m=' . validateHttp('m') . '&y=' . $yNow . '">' .
			                                            $yNow .
			                                      '       </a>' .
			                                      '     </li>';
			                    $yNow--;
			                  }


			$pickerReplace .=  '   </ul>' .
			                  ' </span>' .
			                  '</span>';
		}

		$reportsTitle['pickerReplace'] = $pickerReplace;
	}else{
		$reportsTitle['pickerReplace'] = '<a href="/@#report_products?ii=' . validateHttp('ii') . '&m=1" class="btn btn-rounded btn-default text-u-c font-bold"><span class="material-icons m-r-xs">insert_chart_outlined</span> Por Mes</a>';
	}
}else{

	$reportsTitle = [
						'title' 		=> '<div class="text-md text-right font-default">Ventas por</div> Productos y Servicios',
						'maxDays' 		=> $MAX_DAYS_RANGE
					];
}

echo reportsDayAndTitle($reportsTitle);
?>

<div class="col-xs-12 m-b-lg no-padder text-center hidden-print">

	<section class="col-md-3 col-sm-6">
        <div class="b-b text-center wrapper-md">
			<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalSubtotal"><?=placeHolderLoader()?></span></div>
			Total <span class="text-xs text-muted">(Bruto)</span> <span id="globalSubtotalB">...</span>
		</div>
    </section>

    <section class="col-md-3 col-sm-6">
        <div class="b-b text-center wrapper-md">
			<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalCogs"><?=placeHolderLoader()?></span></div>
			Costo <span class="text-xs text-muted">(PC)</span> <span id="globalCogsB">...</span>
		</div>
    </section>    

    <section class="col-md-3 col-sm-6">
        <div class="b-b text-center wrapper-md">
			<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalDiscount"><?=placeHolderLoader()?></span></div>
			Otros costos <span id="globalDiscountB">...</span>
		</div>
    </section>

		<section class="col-md-3 col-sm-6">
        <div class="b-b text-center wrapper-md">
			<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalUtility"><?=placeHolderLoader()?></span></div>
			Utilidad  <span id="globalUtilityB">...</span>
		</div>
    </section>

</div>

<div class="col-xs-12 no-padder m-t m-b-lg push-chat-down">
    <section class="col-sm-12 no-padder" id="reportsTablesAndTabs">
        <ul class="nav nav-tabs padder hidden-print <?=validateHttp('m') ? 'hidden' : ''?>">
            <li class="active">
                <a href="#tab1" data-toggle="tab">Resumen</a>
            </li>
            <li class="" id="detailTabLink">
                <a href="#tab2" data-toggle="tab">Detallado</a>
            </li>
            <li class="<?=validateHttp('ii') ? 'hidden' : ''?>" id="combosTabLink">
                <a href="#tab3" data-toggle="tab">Combos</a>
            </li>
        </ul>
        <section class="panel r-24x">
            <div class="panel-body">
                <div class="tab-content m-b-lg table-responsive">
                    <div class="tab-pane overflow-auto active" id="tab1" style="min-height:500px">
                    	<div id="generalTable">                             	
                        	<table class="table table1 table-hover col-xs-12 no-padder" id="tableSummary"><?=placeHolderLoader('table')?></table>
                        </div>
                    </div>

                    <div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab2" style="min-height:500px">
                    	<div id="detailTable">
                        	<table class="table table2 table-hover col-xs-12 no-padder" id="tableDetail"><?=placeHolderLoader('table')?></table>
                        </div>
                    </div>

                    <div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab3" style="min-height:500px">
                    	<div id="combosTable">
                        	<table class="table table3 table-hover col-xs-12 no-padder" id="tableCombos"><?=placeHolderLoader('table')?></table>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </section>
</div>

<script>
$(document).ready(function(){
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>",false,true);

	window.baseUrl 		= '<?=$baseUrl;?>';

	let rawUrl 			= baseUrl + "?action=generalTable";
	let loadUrl 		= rawUrl + "&itmId=<?=validateHttp('ii')?>&month=<?=validateHttp('m')?>&year=<?=validateHttp('y')?>&cusId=<?=validateHttp('ci')?>&usrId=<?=validateHttp('ui')?>";

	let rawUrlDetail 	= baseUrl + "?action=detailTable";
	let rawUrlCombo 	= baseUrl + "?action=combosTable";
	let loadUrlDetail	= rawUrlDetail + "&itmId=<?=validateHttp('ii')?>&cusId=<?=validateHttp('ci')?>&usrId=<?=validateHttp('ui')?>";
	let loadUrlCombo	= rawUrlCombo + "&itmId=<?=validateHttp('ii')?>&cusId=<?=validateHttp('ci')?>&usrId=<?=validateHttp('ui')?>";
	let offSet 			= <?=$offsetDetail?>;
	let limit 			= <?=$limitDetail?>;

	let xhr = ncmHelpers.load({
			url 		: loadUrl,
			httpType 	: 'GET',
			hideLoader 	: true,
			type 		: 'json',
			success 	: (result) => {

				var options = {
							"container" 	: "#generalTable",
							"url" 			: loadUrl,
							"iniData" 		: result.table,
							"table" 		: ".table1",
							"sort" 			: 0,
							"footerSumCol" 	: [4,5,6,7,8,9,10,11],
							"currency" 		: "<?=CURRENCY?>",
							"decimal" 		: decimal,
							"thousand" 		: thousandSeparator,
							"nolimit" 		: false,
							"noMoreBtn" 	: true,
							"tableName" 	: 'tableSummary',
							"fileTitle" 	: 'Reporte de Articulos Resumen',
							"ncmTools"		: {
													left 	: '',
													right 	: ''
												  },
							"colsFilter"	: {
													name 		: 'reportItemSalesGeneral1',
													menu 		:  [
																	{"index":0,"name":'Nombre',"visible":true},
																	{"index":1,"name":'Código',"visible":false},
																	{"index":2,"name":'Marca',"visible":false},
																	{"index":3,"name":'Categoría',"visible":false},
																	{"index":4,"name":'Precio Uni.',"visible":false},
																	{"index":5,"name":'Cantidad',"visible":true},
																	{"index":6,"name":'Comisión',"visible":false},
																	{"index":7,"name":'Costo',"visible":false},
																	{"index":8,"name":'<?=TAX_NAME?>',"visible":false},
																	{"index":9,"name":'Descuentos',"visible":false},
																	{"index":10,"name":'Utilidad',"visible":false},
																	{"index":11,"name":'Total',"visible":true}
																	]
												  },
							"clickCB" 		: function(event,tis){
								var load = tis.data('url');
								if(load){
									window.location.href = load;
								}
							}
				};

				ncmDataTables(options);

				$('#globalSubtotal').html(result.subtotal);
				$('#globalSubtotalB').html(result.subtotalB);

				$('#globalCogs').html(result.cogs);
				$('#globalCogsB').html(result.cogsB);

				$('#globalDiscount').html(result.discount);
				$('#globalDiscountB').html(result.discountB);

				$('#globalUtility').html(result.utility);
				$('#globalUtilityB').html(result.utilityB);

				$('#myChart').removeClass('hidden');
				$('#loadingChart').addClass('hidden');

				drawChart(result);
				
				$('[data-toggle="tooltip"]').tooltip();
				$('#yearPickerBtn').removeClass('disabled');
			},
			fail 		: function(){
				$('#yearPickerBtn').removeClass('disabled');
			}
	});
	
	window.detailTableOpen = false;
	$('#detailTabLink').on('shown.bs.tab', (e) => {
	    if(!detailTableOpen){

			var xhr = ncmHelpers.load({
				url 		: loadUrlDetail,
				httpType 	: 'GET',
				hideLoader 	: true,
				type 		: 'json',
				success 	: (result) => {

					var options = {
								"container" 	: "#detailTable",
								"url" 			: loadUrlDetail,
								"rawUrl" 		: rawUrlDetail,
								"iniData" 		: result.table,
								"table" 		: ".table2",
								"sort" 			: 2,
								"footerSumCol" 	: [10,11,12,13,14,15,16],
								"currency" 		: "<?=CURRENCY?>",
								"decimal" 		: decimal,
								"thousand" 		: thousandSeparator,
								"offset" 		: offSet,
								"limit" 		: limit,
								"noMoreBtn" 	: <?=( validateHttp('ci') || validateHttp('ii') ) ? 'true' : 'false'?>,
								"nolimit" 		: true,
								"tableName" 	: 'tableDetail',
								"fileTitle" 	: 'Reporte de Articulos Detallado',
								"ncmTools"		: {
														left 	: '',
														right 	: '<input type="text" class="form-control rounded no-border bg-light lter <?=( (validateHttp('ci') || validateHttp('ii')) ? 'hidden' : '' );?>" placeholder="Buscar por Artículo o Código" id="detailTableSearch" data-url="' + rawUrl + '&qry=">'
													  },
								"colsFilter"	: {
														name 		: 'reportItemSalesDetail1',
														menu 		:  [
																			{"index":0,"name":'Sucursal',"visible":false},
																			{"index":1,"name":'Caja',"visible":false},
																			{"index":2,"name":'# Documento',"visible":true},
																			{"index":3,"name":'Usuario',"visible":false},
																			{"index":4,"name":'Cliente',"visible":false},
																			{"index":5,"name":'Fecha',"visible":true},
																			{"index":6,"name":'Artículo',"visible":true},
																			{"index":7,"name":'Código/SKU',"visible":false},
																			{"index":8,"name":'Marca',"visible":false},
																			{"index":9,"name":'Categoría',"visible":false},
																			{"index":10,"name":'Cantidad',"visible":true},
																			{"index":11,"name":'Comisión',"visible":false},
																			{"index":12,"name":'Costo',"visible":false},
																			{"index":13,"name":'<?=TAX_NAME?>',"visible":false},
																			{"index":14,"name":'Descuentos',"visible":false},
																			{"index":15,"name":'Utilidad',"visible":false},
																			{"index":16,"name":'Total',"visible":true}
																		]
													  },
								"clickCB" 		: function(event,tis){
									var id 		= tis.data('id');
									var load 	= '/a_report_transactions?action=edit&id=' + id + '&ro=1';
									loadForm(load,'#modalLarge .modal-content',function(){
										$('#modalLarge').modal('show');
									});
								}
					};

					ncmDataTables(options,function(oTable,_scope){
						loadTheTable(options,oTable,_scope);
					});
					
					window.detailTableOpen = true;
				}
			});
		}
	});

	window.comboTableOpen = false;
	$('#combosTabLink').on('shown.bs.tab', (e) => {
	    if(!comboTableOpen){

			var xhr = ncmHelpers.load({
				url 		: loadUrlCombo,
				httpType 	: 'GET',
				hideLoader 	: true,
				type 		: 'json',
				success 	: (result) => {

					var options = {
								"container" 	: "#combosTable",
								"url" 			: loadUrlCombo,
								"rawUrl" 		: rawUrlCombo,
								"iniData" 		: result.table,
								"table" 		: ".table3",
								"sort" 			: 0,
								"footerSumCol" 	: [4,5,6,7,8,9,10,11],
								"currency" 		: "<?=CURRENCY?>",
								"decimal" 		: decimal,
								"thousand" 		: thousandSeparator,
								"offset" 		: offSet,
								"limit" 		: limit,
								"nolimit" 		: false,
								"noMoreBtn" 	: true,
								"tableName" 	: 'tableCombos',
								"fileTitle" 	: 'Reporte de Combos',
								"ncmTools"		: {
														left 	: '',
														right 	: '<input type="text" class="form-control rounded no-border bg-light lter <?=( (validateHttp('ci') || validateHttp('ii')) ? 'hidden' : '' );?>" placeholder="Buscar por Artículo o Código" id="detailTableSearch" data-url="' + rawUrl + '&qry=">'
													  },
								"colsFilter"	: {
														name 		: 'reportItemSalesCombo',
														menu 		:  [
																			{"index":0,"name":'Nombre',"visible":true},
																			{"index":1,"name":'Código',"visible":false},
																			{"index":2,"name":'Marca',"visible":false},
																			{"index":3,"name":'Categoría',"visible":false},
																			{"index":4,"name":'Precio Uni.',"visible":false},
																			{"index":5,"name":'Cantidad',"visible":true},
																			{"index":6,"name":'Comisión',"visible":false},
																			{"index":7,"name":'Costo',"visible":false},
																			{"index":8,"name":'<?=TAX_NAME?>',"visible":false},
																			{"index":9,"name":'Descuentos',"visible":false},
																			{"index":10,"name":'Utilidad',"visible":false},
																			{"index":11,"name":'Total',"visible":true}
																		]
													  },
								"clickCB" 		: function(event,tis){
									var load = tis.data('url');
									if(load){
										window.location.href = load;
									}
								}
					};

					ncmDataTables(options);
					
					window.comboTableOpen = true;
				}
			});
		}
	});

	var loadTheTable = function(tableOps,oTable,_scope){
		var srcValCache = '';
	    $('#detailTableSearch').on('keyup',function(e){
	    	var $tis 	= $(this);
	    	var value 	= $tis.val();
	    	var code 	= e.keyCode || e.which;

	    	if(code == 13) { //Enter keycode
		    	if(value.length > 3){
		    		if(!$.trim(value) || srcValCache == value){
		    			return false;
		    		}

	    			spinner(tableOps.container, 'show');
	    			$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1',function(result){
	    				oTable.rows().remove().draw();
	    				if(result){
	    					var line 	= explodes('[@]',result);
	    					$.each(line,function(i,data){
	    						if(data){
	                    			oTable.row.add($(data)).draw();
	                    		}
	    					});
	    				}

	    				//$('.lodMoreBtnHolder').addClass('hidden');
	    				spinner(tableOps.container, 'hide');
		    		});
		    		

		    		srcValCache = value;

		    	}else if(value.length < 1 || !value){
		    		srcValCache = '';
	    			manageTableLoad(tableOps,function(oTable){
						loadTheTable(tableOps,oTable);
					});
		    	}
		    }
	    });

	    $('#modalLarge').on('shown.bs.modal', function() {
			var where = '.loadCustomerInput';
			var match = $(where).data('match');
			$.get('/a_report_transactions?action=getSelectCustomer&match=' + match,function(result){
				$(where).html(result);
				select2Simple('.search');
			});
		});

	};

});

var drawChart = function(result){
	var chart = result.chart;

	if(!ncmHelpers.validity(chart.barData)){
		return false;
	}

	Chart.defaults.global.responsive 			= true;
	Chart.defaults.global.maintainAspectRatio 	= false;
	Chart.defaults.global.legend.display 		= false;

	var myChart = document.getElementById('myChart').getContext("2d");

	var gradientStroke = myChart.createLinearGradient(300, 0, 100, 0);
	gradientStroke.addColorStop(0, "#4cb6cb");
	gradientStroke.addColorStop(1, "#54cfc7");

	var barData = chart.barData.map(function(item) {
	    return parseInt(item);
	});

	var barDataB = chart.barDataB.map(function(item) {
	    return parseInt(item);
	});

	var dataD = {
	    labels 		: chart.barLabel,
	    datasets 	: [
	        <?php
	        $primaryGraphName 	= 'Cantidad';
	        $secondGraphName 	= 'Cant. Anterior';
	        if(validateHttp('m')){
	        	$primaryGraphName 	= 'Total';
	        	$secondGraphName 	= 'Costo';
	        	$graphType 			= 'line';
	        }
	        ?>

	        {
	            label 					  	: "<?=$secondGraphName?>",
	            data 						: barDataB,
	            type                      	: 'line',
	            order 						: 1,
	            borderColor 				: chartSecondColor,
	            pointColor 					: chartSecondColor,
	            pointHoverRadius 			: 8,
	            pointHoverBorderColor 		: "#fff",
	            pointHoverBackgroundColor 	: chartSecondColor,
	            pointBorderColor 			: chartSecondColor,
	            pointBackgroundColor 		: chartSecondColor,
	            pointRadius 				: 3,
	            pointHoverBorderWidth 		: 3,
	            pointBorderWidth 			: 3,
	            pointHitRadius 				: 20,
	            borderDash 					: [10,5],
	            borderWidth 				: 3,
	            fill 						: false
	        },

	        {
	        	label 			: "<?=$primaryGraphName?>",
	            data 			: barData,
	            backgroundColor : gradientStroke,
	            order 			: 2
	        }
	        ]
	};

	var methods = new Chart(myChart, {
	    type 		: 'bar',
	    data 		: dataD,
	    animation 	: true,
	    options 	: chartBarStackedGraphOptions
	});
};


</script>

</body>
</html>
<?php

include_once('includes/compression_end.php');
dai();
?>