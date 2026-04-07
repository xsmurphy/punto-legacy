<?php
include_once('includes/top_includes.php');
topHook();

if (!allowUser('sales', 'edit', true) && !allowUser('sales', 'delete', true)) {
	allowUser('sales', 'view');
}

$MAX_DAYS_RANGE = 62;

$baseUrl = '/' . basename(__FILE__, '.php');

list($calendar, $startDate, $endDate, $lessDays) = datesForGraphs(7);

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate, $endDate, $MAX_DAYS_RANGE);
if (!$maxDate) {
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

$__modules = json_decode($_modules['moduleData'], true);

$_cmpSettings   = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [COMPANY_ID], false);

if (COMPANY_ID == '3245' && $_GET['doit'] == 'beibe') {
	//MADERITO
	dai();
	$no = 48921;
	$result = ncmExecute("SELECT transactionId, transactionDate, invoiceNo, transactionTotal FROM transaction WHERE companyId = 3245 and registerId = 3706 and invoiceNo > 48920 AND transactionDate > '2021-05-10 09:40:27'");
	while (!$result->EOF) {
		$fields = $result->fields;

		$updated = ncmUpdate(
			[
				'records' 	=> ['invoiceNo' => $no],
				'table' 	=> 'transaction',
				'where' 	=> 'transactionId = ' . $fields['transactionId'] . ' AND companyId = 3245'
			]
		);

		echo $fields['invoiceNo'] . ' = ' . $no;
		if (!$updated['error']) {
			echo ' <span style="font-color:green;">GOOD</span><br>';
		} else {
			echo ' <span style="font-color:red;">BAD</span><br>';
		}

		$no++;
		$result->MoveNext();
	}

	$result->Close();

	updateLastTimeEdit();

	dai();
}

$roc 				= getROC(1);

$maxItemsInGraph 	= 20;
$itemsArray 		= [];
$itemsArrayDetail 	= [];
$isdata 			= false;
$limitDetail		= 100;
$offsetDetail		= 0;
$limitCobros		= 100;
$offsetCobros		= 0;

$jsonResult 		= [];
$getAllTaxNames 	= getAllTax();

if (validateHttp('action') == 'detailTable') {

	/*
	0 = Venta al contado  	    
	1 = Compra al contado 	    
	2 = Guardada
	3 = Venta a crédito
	4 = Compra a crédito
	5 = Pago de ventas a crédito 	    
	6 = Devolución 	    
	7 = Venta anulada 	    
	8 = Venta recursiva
	*/

	ini_set('memory_limit', '256M');
	$limits = getTableLimits($limitDetail, $offsetDetail);
	if (validateHttp('singleRow')) {
		$singleRow = ' AND transactionId = ' . dec(validateHttp('singleRow'));
	}

	$transactionFields = 'transactionId, transactionDate, transactionDiscount, transactionTax, transactionTotal, transactionUnitsSold, transactionPaymentType, transactionType, transactionName, transactionNote, transactionParentId, transactionComplete, transactionLocation, transactionDueDate, transactionStatus, transactionUID, invoiceNo, invoicePrefix, tags, timestamp, customerId, registerId, userId, responsibleId, outletId, companyId';

	if (validateHttp('src')) {
		$search = ' AND';
		$parend = '';
		$word 	= db_prepare(validateHttp('src'));

		//primero obtengo posible fuente
		$sData = ncmExecute('SELECT STRING_AGG(contactId::text, \',\') as uids FROM contact WHERE type = 1 AND (contactName LIKE \'%\' . $word . \'%\' OR contactTIN LIKE \'%\' . $word . \'%\' OR contactSecondName LIKE \'%\' . $word . \'%\') AND companyId = ? LIMIT 200', [COMPANY_ID]);

		if (is_numeric($word)) {
			$search .= ' ( invoiceNo = "' . $word . '" OR';
			$parend = ')';
		}

		$search .= ' customerId IN(' . iftn($sData['uids'], '1') . ')' . $parend;


		$sql = "SELECT " . $transactionFields . "
			FROM transaction 
			WHERE transactionType IN (0,3,6,7,8)
			" . $singleRow . "
			" . $roc . "
			" . $search . "
			ORDER BY transactionDate 
			DESC
			";

		$saleDay = ncmExecute($sql, [], false, true, true);
	} else {
		if (validateHttp('cusId')) {
			$contactId = dec(validateHttp('cusId'));

			$sql = "SELECT " . $transactionFields . "
				FROM transaction 
				WHERE transactionType IN (0,3,6,7,8)
				" . $roc . "
				AND customerId = ?
				ORDER BY transactionDate 
				DESC";

			if (!empty($_GET['test'])) {
				dai($sql . ' - ' . $contactId);
			}

			$saleDay = ncmExecute($sql, [$contactId], false, true, true);
		} else {
			if (!isset($singleRow)) {
				$singleRow = "";
			}
			$isSameDay 		= false;
			$startDay 		= explodes(' ', $startDate, false, 0);
			$endDay 		= explodes(' ', $endDate, false, 0);

			if ($startDay == $endDay) {
				$isSameDay = true;
			}

			$startH = explodes(' ', $startDate, false, 1);
			$endH 	= explodes(' ', $endDate, false, 1);

			$sql = "SELECT *
				FROM transaction USE INDEX(transactionDate,transactionType)
				WHERE transactionType IN (0,3,6,7,8)
				AND transactionDate
				BETWEEN ? 
				AND ?
				" . ($singleRow ?? "") . "
				" . $roc . "
				ORDER BY transactionDate 
				DESC
				" . $limits;

			$saleDay = ncmExecute($sql, [$startDate, $endDate], false, true, true);

			if (!empty($_GET['test'])) {
				echo '<pre>';
				print_r($saleDay);
				echo '</pre>';
				die();
			}

			/*if($isSameDay){
				$sql = "SELECT *
				FROM transaction 
				WHERE transactionType IN (0,3,6,7,8)
				AND transactionDate
				BETWEEN ? 
				AND ?
				" . $singleRow . "
				" . $roc . "
				ORDER BY transactionDate 
				DESC
				" . $limits;

				$saleDay = ncmExecute($sql,[$startDate,$endDate],false,true,true);
			}else{
				
				$sql = "SELECT STRING_AGG(transactionId::text, ',') as ids
				FROM transaction 
				WHERE transactionType IN (0,3,6,7,8)
				AND transactionDate
				BETWEEN ? 
				AND ?
				" . $singleRow . "
				" . $roc . "
				ORDER BY transactionDate 
				DESC
				" . $limits;

				$preSales = ncmExecute($sql,[$startDate,$endDate]);

				
				if($preSales){
					$sql2 = "SELECT transactionId, transactionId, transactionTotal, customerId
							FROM transaction 
							WHERE transactionId IN (?)
							
							ORDER BY transactionDate 
							DESC";

					$saleDay = ncmExecute($sql2,[ rtrim($preSales['ids'], ',') ], false, true, true);
				}

				if($_GET['test']){
					echo '<pre>';

					print_r($saleDay);
					
					echo  "SELECT *
							FROM transaction 
							WHERE transactionId IN (" . rtrim($preSales['ids'], ',') . ")
							AND HOUR(transactionDate) 
							BETWEEN '" . $startH . "'
							AND '" . $endH . "'
							ORDER BY transactionDate 
							DESC";

							echo '</pre>';
					
					dai();
				}

				
			}*/
		}
	}

	$head = 	'<thead class="text-u-c pointer">' .
		'	<tr>' .
		'		<th>ID</th>' .
		'		<th class="text-center ignored"># Autorización</th>' .
		'		<th class="text-center"># Documento</th>' .
		'		<th>Fecha</th>' .
		'		<th>Hora</th>' .
		'		<th class="no-search">Vencimiento</th>' .
		'		<th>Cliente</th>' .
		'		<th>' . TIN_NAME . '</th>' .
		'		<th>Usuario</th>' .
		'		<th class="ignored">Sucursal</th>' .
		'		<th>Caja</th>' .
		'		<th>M. de Pago</th>' .
		'		<th class="ignored">Nota</th>' .
		'		<th>Etiquetas</th>' .
		'		<th>Tipo</th>' .
		'		<th class="text-center no-search">Descuento</th>' .
		'		<th class="text-center ignored">Subtotal</th>' .
		'		<th class="text-center ignored">' . TAX_NAME . '</th>' .
		'		<th class="text-center ignored">Total Gravado</th>' .
		'		<th class="text-center no-search">Total</th>' .
		'	</tr>' .
		'</thead>' .
		'<tbody>';

	$table 						= '';
	if ($saleDay) {
		//getAllcustomersIds
		$customersArr 	= [];
		$transArr 		= [];
		foreach ($saleDay as $key => $customer) {
			$customersArr[] = $customer['customerId'];
			$transArr[] 	= $customer['transactionId'];
		}

		if (validity($transArr)) {
			$custIn 				= ' AND transactionParentId IN(' . implodes(',', $transArr) . ')';
			$allToPayTransactions 	= getAllToPayTransactions(false, $custIn);
		}
		//

		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		$userCache 				= [];
		$custCache 				= [];
		// print_r($fields);
		foreach ($saleDay as $key => $fields) {
			$customer 			= [];
			$itemId 			= enc($fields['transactionId']);
			$tTotal 			= $fields['transactionTotal'];
			$netTotal 			= $tTotal - $fields['transactionDiscount'];

			if ($fields['customerId']) {
				if (array_key_exists($fields['customerId'], $custCache)) {
					$customer 	= $custCache[$fields['customerId']];
				} else {
					$customer 	= getCustomerData($fields['customerId'], 'uid', true);
					$custCache[$fields['customerId']] = $customer;
				}
			}

			if (validity($fields['userId'])) {
				if (array_key_exists($fields['userId'], $userCache) && $userCache[$fields['userId']]) {
					$user 		= $userCache[$fields['userId']];
				} else {
					$user 		= getCustomerData($fields['userId'], false, true);
					$userCache[$fields['userId']] = $user;
				}

				$userName 		= toUTF8($user['name']);
			} else {
				$userName 		= '-';
			}

			if ($fields['transactionType'] == '6') {
				// $totalReturned++;
			}

			$transactionType 	= '<span class="label bg-light text-u-c">Contado</span>';
			$typeFilter 		= 'tipo:contado';

			//status
			$statusOut = '';
			if ($fields['transactionComplete'] < 1 && $fields['transactionType'] == '3') {
				if (strtotime($fields['transactionDueDate'] ?? "") < strtotime('now')) {
					$statusOut = '<span class="badge badge-sm bg-danger up">!</i>';
				}
			}

			//

			//payment methods
			$paymentType 	= getPaymentMethodsInArray($fields['transactionPaymentType']);
			$pMethods 		= [];

			if (validity($paymentType)) {
				foreach ($paymentType as $pType) {
					if ($pType['total'] > 0) {
						$pMethods[] = iftn(getPaymentMethodName($pType['type']), $pType['name']);
					}
				}
			}

			$pMethods = arrayToLabelsUI(['data' => $pMethods, 'bg' => 'bg-light']);
			//

			$totalContado = 0;
			$totalCobrado = 0;
			$totalPorcount = 0;
			$totalCredito = 0;
			if ($fields['transactionType'] == '3') {

				$tPayed = $allToPayTransactions[$fields['transactionId']] ?? 0;
				$topay 	= $netTotal - $tPayed;

				$totalCobrado += $tPayed;

				if ($topay > ($netTotal / 2)) {
					$typeText   	= 'bg-danger';
					$filterOp 	= '_sinpago';
				}
				if ($topay <= ($netTotal / 2)) {
					$typeText   	= 'bg-warning';
					$filterOp 	= '_conpago';
				}
				if ($topay <= 0 || $fields['transactionComplete'] == 1) {
					$typeText   	= 'bg-success text-white';
					$filterOp 	= '_pagado';
					//   $totalCobcount++;
				}
				if ($topay > 0) {
					$totalPorcount++;
				}

				$transactionType = '<span class="label ' . $typeText . ' lter text-u-c" data-toggle="tooltip" data-placement="top" title="Vencimiento (' . niceDate($fields['transactionDueDate']) . ')">Crédito</span>';
				$typeFilter 		= 'tipo:crédito' . $filterOp;

				$totalCredito += ($tTotal - $fields['transactionDiscount']);
				// $totalCcount++;

				$isCreditSale = true;
			} else if ($fields['transactionType'] == '0') {
				$totalContado += $netTotal;
				// $totalCocount++;
			} else if ($fields['transactionType'] == '6') {
				$totalContado 		+= $netTotal;
				$transactionType 	= '<span class="label bg-dark lter text-u-c">Devolución</span>';
				$typeFilter 		= 'tipo:devolución';
			} else if ($fields['transactionType'] == '7') {
				$netTotal  			= 0;
				$transactionType 	= '<span class="label bg-dark text-u-c">Anulado</span>';
				$typeFilter 		= 'tipo:anulado';
			}

			$fechUgly 			= $fields['transactionDate'];
			$fecha 				= timeago($fechUgly, false);

			if ($fields['transactionType'] == '7') {
				$calculableTotal 	= 0;
				$calculableSubTotal = 0;
				$calculableTax 		= 0;
				$calculableDiscount	= 0;
			} else {
				$calculableTotal 	= $netTotal;
				$calculableSubTotal = $fields['transactionTotal'];
				$calculableTax 		= $fields['transactionTax'];
				$calculableDiscount	= $fields['transactionDiscount'];
			}

			$customerTin		=  iftn($customer['ruc'] ?? "-", '-');

			$invoicePrefix 		= $fields['invoicePrefix'];

			$registerData 		= ncmExecute('SELECT * FROM register WHERE registerId = ? AND companyId = ?', [$fields['registerId'], COMPANY_ID], true);
			$jRegisterData  = json_decode($registerData['data'], true);
			$invoiceAuth 		= $registerData['registerInvoiceAuth'] ?? "";
			if (!$invoicePrefix) {
				$invoicePrefix  = $registerData['registerInvoicePrefix'] ?? "";
			}

			if ($fields['transactionType'] == '6' && isset($jRegisterData["registerReturnPrefix"])) {
				$invoicePrefix = "";
				if (isset($jRegisterData["registerReturnPrefix"])) {
					$invoicePrefix = $jRegisterData["registerReturnPrefix"];
				}
			}

			$invoicePaddedNo = str_pad($fields['invoiceNo'] ?? "",  $registerData['registerDocsLeadingZeros'] ?? 0, "0", STR_PAD_LEFT);

			$dueDate 			= $fields['transactionDueDate'];
			if (!validity($dueDate)) {
				$dueDate 		= $fechUgly;
			}

			$tagsAr 		= json_decode(!empty($fields['tags']) ? $fields['tags'] : "", true);
			$tags 		  	= printOutTags($tagsAr, 'bg-info');
			$isInternal 	= '';
			if (isset($_fullSettings['ignoreInternal']) && $_fullSettings['ignoreInternal']) {
				$isInternal 	= isInternalSale($tagsAr) ? 'internal' : '';
			}


			if (validity($tagsAr, 'array')) {
				if (in_array('166227', $tagsAr) || in_array(166227, $tagsAr)) {
					$invoicePrefix 		= '';
					$invoiceAuth 		= '';
					$invoicePaddedNo 	= $fields['invoiceNo'];
				}
			}

			if (!empty($customer['name']) || !empty($customer['secondName'])) {
				$custNameFltr 	= $customer['name'] . ' ' . $customer['secondName'] . ' con:cliente';
			} else {
				$custNameFltr 	= 'sin:cliente';
			}

			$table .= 	'<tr data-id="' . $itemId . '" class="clickrow ' . $isInternal . '">' .
				'	<td class="bg-light dk">' . $itemId . '</td>' .
				'	<td class="text-right bg-light dk">' . $invoiceAuth . '</td>' .
				'	<td class="text-right bg-light dk" data-order="' . $fields['invoiceNo'] . '">' . $invoicePrefix . $invoicePaddedNo . '</td>' .
				'	<td data-order="' . $fechUgly . '">' . niceDate($fechUgly) . '</td>' .
				'	<td> ' . date('H:i', strtotime($fechUgly)) . ' </td>' .
				'	<td data-order="' . $dueDate . '">' . niceDate($dueDate) . ' </td>' .
				'	<td data-filter="' . $custNameFltr . '">' . toUTF8($customer['name'] ?? "") . '</td>' .
				'	<td>' . $customerTin . '</td>' .
				'	<td>' .	toUTF8($userName) . ' </td>' .
				'	<td>' . toUTF8($getAllOutlets[$fields['outletId']]['name'] ?? "") . '</td>' .
				'	<td>' . toUTF8($getAllRegisters[$fields['registerId']]['name'] ?? "") . '</td>' .
				'	<td>' . $pMethods . '</td>' .
				'	<td>' . toUTF8($fields['transactionNote']) . '</td>' .
				'	<td data-tags="' . $fields['tags'] . '"> ' . $tags . ' </td>' .
				'	<td data-filter="' . $typeFilter . '"> ' . $transactionType . $statusOut . ' </td>' .
				'	<td class="text-right bg-light lter" data-order="' . $calculableDiscount . '" data-format="money">' . formatCurrentNumber($calculableDiscount) . '</td>' .
				'	<td class="text-right bg-light lter" data-order="' . $calculableSubTotal . '" data-format="money">' . formatCurrentNumber($calculableSubTotal) . '</td>' .
				'	<td class="text-right bg-light lter" data-order="' . $calculableTax . '" data-format="money">' . formatCurrentNumber($calculableTax) . '</td>' .
				'	<td class="text-right bg-light lter" data-order="' . ($netTotal - $calculableTax) . '" data-format="money">' . formatCurrentNumber($netTotal - $calculableTax) . '</td>' .
				'	<td class="text-right bg-light lter" data-order="' . $calculableTotal . '" data-format="money">' . formatCurrentNumber($netTotal) . '</td>' .
				'</tr>';

			if (validateHttp('part') && !validateHttp('singleRow')) {
				$table .= '[@]';
			}

			$netTotal = $fields['transactionTotal'] - $fields['transactionDiscount'];

			if (empty($_fullSettings['taxPy']) && $fields['transactionDetails']) { //correccion del IVA mal calculado del 26 al 28
				// $newTTax = 0;
				// foreach(json_decode($fields['transactionDetails'],true) as $keyT => $valT){
				// 	if(validity($valT['itemId'])){

				// 		$newTTax += getTaxOfPrice($valT['tax'], (($valT['uniPrice'] - ($valT['totalDiscount'] ?? 0) ) * $valT['count']) );
				// 	}
				// }

				// $updTax = ncmUpdate( 
				// 						[
				// 							'records' 	=> ['transactionTax' => $newTTax], 
				// 							'table' 	=> 'transaction', 
				// 							'where' 	=> 'transactionId = ' . $fields['transactionId'] . ' AND companyId = ' . COMPANY_ID 
				// 						]
				// 					);


			}

			if ($fields['transactionType'] == '6') {
				$tTotal -= $netTotal;
			} else {
				$tTotal += $netTotal;
			}

			// $No--;
		}

		$url 	= $baseUrl . "?action=detailTable&sale=" . ($saletype ?? "");
	}

	$foot = 	'</tbody>' .
		'<tfoot>' .
		'    <tr>' .
		'   	<th colspan="15">TOTALES:</th>' .
		'    	<th class="text-right"></th>' .
		'    	<th class="text-right"></th>' .
		'       <th class="text-right"></th>' .
		'       <th class="text-right"></th>' .
		'       <th class="text-right"></th>' .
		'    </tr>' .
		'</tfoot>';

	if (validateHttp('part')) {
		dai($table);
	} else {
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		if (!empty($_GET['debug'])) {
			echo $fullTable;
			dai();
		}

		header('Content-Type: application/json');
		jsonDieResult($jsonResult);
		// dai(json_encode($jsonResult,true));
	}
}

if (validateHttp('action') == 'feTable') {

	$curl = curl_init();

	curl_setopt_array($curl, [
		CURLOPT_URL 			=> FACTURACION_ELECTRONICA_URL . '/api/companies/documents',
		CURLOPT_RETURNTRANSFER 	=> true,
		CURLOPT_ENCODING 		=> '',
		CURLOPT_MAXREDIRS 		=> 10,
		CURLOPT_TIMEOUT 		=> 30,
		CURLOPT_FOLLOWLOCATION 	=> true,
		CURLOPT_HTTP_VERSION 	=> CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST 	=> 'POST',

		CURLOPT_POSTFIELDS 		=> '{
		    "ruc": "' . $_cmpSettings['settingRUC'] . '",
		    "fecha_desde": "' . $startDate . '",
		    "fecha_hasta": "' . $endDate . '"
		}',

		CURLOPT_HTTPHEADER 		=> 	[
			'Authorization: Bearer 4|yZGDEhn2F1ZAM1S1DHU35lAr8Pk4SWSWBgQMqiMD',
			'Accept: application/json',
			'Content-Type: application/json'
		]
	]);

	$response 	= curl_exec($curl);
	curl_close($curl);
	$result 	= json_decode($response, true);
	$body 		= '';

	foreach ($result['documents'] ?? [] as $key => $value) {

		// print_r($value);
		// die();
		$statusColor 	= 'bg-success';
		$statusTxt 		= 'Aprobado';

		if ($value['status'] != 'aprobado') {
			$statusColor 	= 'bg-warning';
			$statusTxt 		= 'Pendiente';
		}

		$typeTxt = 'Contado';
		if ($value['type'] == 'FCR') {
			$typeTxt = 'Crédito';
		} else if ($value['type'] == 'NCR') {
			$typeTxt = 'Nota de crédito';
		}

		$body 	.=	'<tr>' .
			'	<td>' . $value['establecimiento'] . '-' . $value['puntoExpedicion'] . '-' . $value['documentoNro'] . '</td>' .
			'	<td>' . $typeTxt . '</td>' .
			'	<td>' . $value['date'] . '</td>' .
			'	<td>' . toUTF8($value['razonSocial']) . '</td>' .
			'	<td>' . ($value['docNro'] ? $value['docNro'] . '-' . $value['dv'] : '-') . '</td>' .
			'	<td> <span class="label ' . $statusColor . '">' . $statusTxt . '</span> </td>' .
			'	<td>' . formatCurrentNumber($value['totalComprobante']) . '</td>' .
			'	<td> <a href="' . $value['qr'] . '" class="' . ($value['qr'] ? '' : 'hidden') . '" target="_blank"><i class="material-icons text-info">open_in_new</i></a> </td>' .
			'</tr>';
	}

	$head 	=	'<thead class="text-u-c pointer">' .
		'	<tr>' .
		'		<th># Documento</th>' .
		'		<th>Tipo</th>' .
		'		<th>Fecha</th>' .
		'		<th>Cliente</th>' .
		'		<th>' . TIN_NAME . '</th>' .
		'		<th>Estado</th>' .
		'		<th>Total</th>' .
		'		<th>Acciones</th>' .
		'	</tr>' .
		'</thead>' .
		'<tbody>';

	$foot 	= 	'</tbody>' .
		'<tfoot>' .
		'    <tr>' .
		'   	<th colspan="8"></th>' .
		'    </tr>' .
		'</tfoot>';

	$fullTable 				= $head . $body . $foot;
	$jsonResult['table'] 	= $fullTable;

	header('Content-Type: application/json');
	dai(json_encodes($jsonResult));
}

if (validateHttp('action') == 'cobrosTable') {
	$limits = getTableLimits($limitCobros, $offsetCobros);
	$table 	= '';

	if (validateHttp('src')) {
		$word 	= $db->Prepare(validateHttp('src'));
		//primero obtengo posible fuente
		$sData = ncmExecute('SELECT STRING_AGG(contactId::text, \',\') as uids FROM contact WHERE type = 1 AND (contactName LIKE \'%\' . $word . \'%\' OR contactTIN LIKE \'%\' . $word . \'%\' OR contactSecondName LIKE \'%\' . $word . \'%\') AND companyId = ? LIMIT 100', [COMPANY_ID], true);

		$search = ' AND customerId IN(' . $sData['uids'] . ')';

		$sql = "SELECT *
			FROM transaction 
			WHERE transactionType IN (5)
			AND transactionDate 
		   " . $roc . "
		   " . $search . "
		  ORDER BY transactionDate DESC";

		$result = ncmExecute($sql, [], false, true, true);
	} else {

		if (validateHttp('cusId')) {
			$contactId = dec(validateHttp('cusId'));

			$sql = "SELECT *
				FROM transaction 
				WHERE transactionType IN (5)
				AND transactionDate 
			   " . $roc . "
			   AND customerId = ?
			  ORDER BY transactionDate DESC";

			$result = ncmExecute($sql, [$contactId], false, true, true);
		} else {
			$sql = "SELECT *
				FROM transaction 
				WHERE transactionType IN (5)
				AND transactionDate 
				BETWEEN ?
				AND ? 
			   " . $roc . "
			  ORDER BY transactionDate DESC" . $limits;

			$result = ncmExecute($sql, [$startDate, $endDate], false, true, true);

			if (!empty($_GET['debug'])) {
				print_r($result);
			}
		}
	}



	$head 	=	'<thead class="text-u-c pointer">' .
		'	<tr>' .
		'		<th class="text-center">Doc. Ref. #</th>' .
		'		<th class="text-center">Documento #</th>' .
		'		<th>Fecha</th>' .
		'		<th>Cliente</th>' .
		'		<th>Usuario</th>' .
		'		<th>Sucursal</th>' .
		'		<th>Caja</th>' .
		'		<th>M. de Pago</th>' .
		'		<th class="text-center">Cobrado</th>' .
		'		<th></th>' .
		'	</tr>' .
		'</thead>' .
		'<tbody>';

	if ($result) {
		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();

		foreach ($result as $key => $fields) {

			$parentIs = ncmExecute('SELECT transactionType, invoiceNo, invoicePrefix, registerId FROM transaction WHERE transactionId = ? AND transactionType IN (0,3) AND companyId = ? LIMIT 1', [$fields['transactionParentId'], COMPANY_ID], true); //valido si es un pago de una venta a credito

			if ($parentIs) {

				$transId 	= enc($fields['transactionParentId']);


				$customer 		= getCustomerData($fields['customerId'], 'uid');

				if (validity($fields['userId'])) {
					$user 				= getCustomerData($fields['userId']);
					$userName 			= ($user['name']);
				} else {
					$userName 			= '-';
				}


				//payment methods
				$paymentType 	= json_decode(iftn($fields['transactionPaymentType'], '[]'), true);
				$pMethods 		= [];
				if (validity($paymentType)) {
					foreach ($paymentType as $pType) {
						$pMethods[] = iftn(getPaymentMethodName($pType['type']), $pType['name']);
					}
				}
				$pMethods = arrayToLabelsUI(['data' => $pMethods, 'bg' => 'bg-light']);
				//

				//parent invoice
				$parentInvoice 		= $getAllRegisters[$parentIs['registerId']]['invoicePrefix'] . ' ' . $parentIs['invoiceNo'];
				$customer 			= ($customer['name']);

				$table .= '<tr data-id="' . $transId . '" class="clickrow" data-ro="true">' .
					'		<td class="text-right">' . $parentInvoice . '</td>' .
					'		<td class="text-right">' . $fields['invoiceNo'] . '</td>' .
					'		<td data-order="' . $fields['transactionDate'] . '" data-filter="' . $fields['transactionDate'] . '">' . niceDate($fields['transactionDate'], true) . '</td>' .
					'		<td>' . $customer . '</td>' .
					'		<td>' . $userName . '</td>' .
					'		<td>' . $getAllOutlets[$fields['outletId']]['name'] . '</td>' .
					'		<td>' . $getAllRegisters[$fields['registerId']]['name'] . '</td>' .
					'		<td>' . $pMethods . '</td>' .
					'		<td class="text-right bg-light lter" data-order="' . $fields['transactionTotal'] . '" data-format="money"> ' . formatCurrentNumber($fields['transactionTotal']) . '</td>' .
					'		<td class="text-center">' .
					'			<a href="' . $baseUrl . '?action=delete&id=' . enc($fields['transactionId']) . '&outlet=' . enc($fields['outletId']) . '&type=' . enc($fields['transactionType']) . '&parent=' . $transId . '" class="deletePayment">' .
					' 				<i class="material-icons text-danger">close</i>' .
					'			</a>' .
					'		</td>' .
					'	</tr>';

				if (validateHttp('part')) {
					$table .= '[@]';
				}
			}
		}
	}

	$foot 	= 		'</tbody>' .
		'<tfoot>' .
		'    <tr>' .
		'   	<th>TOTALES:</th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'       <th class="text-right"></th>' .
		'    	<th></th>' .
		'    </tr>' .
		'</tfoot>';

	if (validateHttp('part')) {
		dai($table);
	} else {
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		header('Content-Type: application/json');
		dai(json_encodes($jsonResult));
	}
}

if (validateHttp('action') == 'quotesTable') {
	$limits = getTableLimits($limitCobros, $offsetCobros);
	$table 	= '';

	if (validateHttp('src')) {
		$word 	= $db->Prepare(validateHttp('src'));
		//primero obtengo posible fuente
		$sData = ncmExecute('SELECT STRING_AGG(contactId::text, \',\') as uids FROM contact WHERE type = 1 AND (contactName LIKE \'%\' . $word . \'%\' OR contactTIN LIKE \'%\' . $word . \'%\' OR contactSecondName LIKE \'%\' . $word . \'%\') AND companyId = ? LIMIT 100', [COMPANY_ID], true);

		$search = ' AND customerId IN(' . $sData['uids'] . ')';

		$sql = "SELECT *
			FROM transaction 
			WHERE transactionType IN (9)
			AND transactionId > 1 
		   " . $roc . "
		   " . $search . "
		  ORDER BY transactionDate DESC";

		$result = ncmExecute($sql, [], false, true, true);
	} else {

		if (validateHttp('cusId')) {
			$contactId = dec(validateHttp('cusId'));

			$sql = "SELECT *
				FROM transaction 
				WHERE transactionType IN (9)
				AND transactionId > 1
			   " . $roc . "
			   AND customerId = ?
			  ORDER BY transactionDate DESC";

			$result = ncmExecute($sql, [$contactId], false, true, true);
		} else {
			$sql = "SELECT *
				FROM transaction 
				WHERE transactionType IN (9)
				AND transactionDate 
				BETWEEN ?
				AND ? 
			   " . $roc . "
			  ORDER BY transactionDate DESC" . $limits;

			if (!empty($_GET['debug'])) {
				//	die($sql . ' = ' . $startDate . ' - ' . $endDate);
			}

			$result = ncmExecute($sql, [$startDate, $endDate], false, true, true);
		}
	}



	$head 	=	'<thead class="text-u-c pointer">' .
		'	<tr>' .
		'		<th class="text-center">Documento #</th>' .
		'		<th>Fecha</th>' .
		'		<th>Estado</th>' .
		'		<th>Cliente</th>' .
		'		<th>' . TIN_NAME . '</th>' .
		'		<th>Usuario</th>' .
		'		<th>Sucursal</th>' .
		'		<th class="text-center">Valor</th>' .
		'		<th></th>' .
		'	</tr>' .
		'</thead>' .
		'<tbody>';

	if ($result) {
		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();

		foreach ($result as $key => $fields) {
			$parentIs = ncmExecute('SELECT transactionType, invoiceNo, invoicePrefix, registerId FROM transaction WHERE transactionId = ? AND transactionType IN (0,3) AND companyId = ? LIMIT 1', [$fields['transactionParentId'], COMPANY_ID], true); //valido si es un pago de una venta a credito

			$transId 	= enc($fields['transactionParentId']);
			$customer 	= getCustomerData($fields['customerId'], 'uid');

			if (validity($fields['userId'])) {
				$user 				= getCustomerData($fields['userId']);
				$userName 			= $user['name'];
			} else {
				$userName 			= '-';
			}

			//
			$status = '<span class="label bg-light">Pendiente</span>';
			if ($fields['transactionStatus'] == 2) {
				$status = '<span class="label bg-info lter">Aprobado</span>';
			} else if ($fields['transactionStatus'] == 4) {
				$status = '<span class="label bg-success lt">Finalizado</span>';
			} else if ($fields['transactionStatus'] == 5) {
				$status = '<span class="label bg-danger lter">Rechazado</span>';
			} else if ($fields['transactionStatus'] == 6) {
				$status = '<span class="label bg-dark lter">Otro</span>';
			}

			//parent invoice
			$parentInvoice 		= ($parentIs['invoicePrefix'] ?? "") . ($parentIs['invoiceNo'] ?? "");

			$name 				= getCustomerName($customer);
			$tin 				= $customer['ruc'];

			$table .= '<tr class="clickrow" data-id="' . enc($fields['transactionId']) . '" data-ro="true">' .
				'		<td class="text-right">' . $fields['invoiceNo'] . '</td>' .
				'		<td data-order="' . $fields['transactionDate'] . '" data-filter="' . $fields['transactionDate'] . '">' . niceDate($fields['transactionDate'], true) . '</td>' .
				'		<td>' . $status . '</td>' .
				'		<td>' . $name . '</td>' .
				'		<td>' . $tin . '</td>' .
				'		<td>' . $userName . '</td>' .
				'		<td>' . $getAllOutlets[$fields['outletId']]['name'] . '</td>' .
				'		<td class="text-right bg-light lter" data-order="' . $fields['transactionTotal'] . '" data-format="money"> ' . formatCurrentNumber($fields['transactionTotal']) . '</td>' .
				'		<td class="text-center">' .
				'			<a href="' . $baseUrl . '?action=delete&id=' . enc($fields['transactionId']) . '&outlet=' . enc($fields['outletId']) . '&type=' . enc($fields['transactionType']) . '" class="deleteQuote">' .
				' 				<i class="material-icons text-danger">close</i>' .
				'			</a>' .
				'		</td>' .
				'	</tr>';

			if (validateHttp('part')) {
				$table .= '[@]';
			}
		}
	}

	$foot 	= 		'</tbody>' .
		'<tfoot>' .
		'    <tr>' .
		'   	<th>TOTALES:</th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'    	<th></th>' .
		'       <th class="text-right"></th>' .
		'    	<th></th>' .
		'    </tr>' .
		'</tfoot>';

	if (validateHttp('part')) {
		dai($table);
	} else {
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		header('Content-Type: application/json');
		dai(json_encodes($jsonResult));
	}
}

if (validateHttp('action') == 'update' && validateHttp('id', 'post')) {
	if (!allowUser('sales', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	theErrorHandler('json');

	$id 		= validateHttp('id', 'post');
	$dId 		= dec($id);
	$record  	= [];

	//print_r($_POST);
	//dai();

	$record['transactionDate'] 			= validateHttp('date', 'post');

	if (validateHttp('duedate', 'post')) {
		$record['transactionDueDate']	= validateHttp('duedate', 'post');
	}

	$record['transactionNote'] 		= markupt2HTML(['text' => validateHttp('note', 'post')]);
	$record['customerId'] 			= dec(validateHttp('customer', 'post'));

	if (validateHttp('user', 'post')) {
		$record['userId'] 			= dec(validateHttp('user', 'post'));
	}

	if (validateHttp('responsible', 'post')) {
		$record['responsibleId']	= dec(validateHttp('responsible', 'post'));
	}

	if (validateHttp('outlet', 'post')) {
		$record['outletId'] 		= dec(validateHttp('outlet', 'post'));
	}

	if (validateHttp('invoiceNo', 'post')) {
		$record['invoiceNo'] 		= validateHttp('invoiceNo', 'post');
	}

	if (validateHttp('trTags', 'post')) {
		$tag = '';
		foreach (validateHttp('trTags', 'post') as $tid) {
			$tag .= dec($tid) . ',';
		}
		$record['tags'] 		= json_encode(explodes(',', $tag));
	} else {
		$record['tags'] 		= NULL;
	}

	$hasItems 	= validateHttp('itemTrsId', 'post');
	$qty  		= validateHttp('itemQty', 'post');
	$item 		= validateHttp('itemNew', 'post');
	$user 		= validateHttp('itemUser', 'post');
	$price 		= validateHttp('itemPrice', 'post');
	$trsTotal 	= 0;
	$trsQtyx 	= 0;
	$trsTax 	= 0;

	if (validity($hasItems, 'array')) {
		//print_r([$hasItems,$qty,$item,$price]);
		//dai();
		foreach ($hasItems as $itmSID) {

			$itm 						= $item[$itmSID];
			$itmQty 					= formatNumberToInsertDB($qty[$itmSID], true, 2);
			$itemPrice 					= formatNumberToInsertDB($price[$itmSID]);
			$itemTotal 					= formatNumberToInsertDB($itemPrice * $itmQty);
			$itemUser 					= dec($user[$itmSID]);

			$irecord 					= [];
			$itemData 					= ncmExecute('SELECT taxId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [dec($itm), COMPANY_ID]);
			$taxVal 					= getTaxOfPrice(getTaxValue($itemData['taxId']), $itemTotal);

			$irecord['itemId']			= dec($itm);

			$irecord['itemSoldUnits']	= $itmQty;
			$irecord['itemSoldTotal']	= $itemTotal;
			$irecord['itemSoldTax']		= $tax;
			$irecord['userId']			= $itemUser;

			$trsTotal 	+= $itemTotal;
			$trsQty 	+= $itmQty;
			$trsTax 	+= $taxVal;

			ncmUpdate([
				'records' 	=> $irecord,
				'table' 	=> 'itemSold',
				'where' 	=> 'itemSoldId = ' . dec($itmSID)
			]);

			$ops['itemId']    = dec($item);
			$ops['outletId']  = dec(validateHttp('outlet', 'post'));
			$ops['cogs']      = $itemPrice;
			$ops['count']     = 0;

			$manage 		  = manageStock($ops);
		}
	}

	//$record['transactionTotal'] 	= $trsTotal;
	//$record['transactionTax'] 		= $trsTax;
	//$record['transactionUnitsSold'] = $trsQty;	

	if (validateHttp('trtype', 'post')) {
		if (validateHttp('trtype', 'post') == 'contado') {
			$type = 0;
		} else {
			$type = 3;
			$record['transactionComplete'] 		= 0;
		}

		$record['transactionType'] 		= $type;
	}

	if (validateHttp('haspayments', 'post')) {
		$pmethod 	= [];
		$pMtype 	= validateHttp('pMtype', 'post');
		$pMamount 	= validateHttp('pMamount', 'post');

		if (validity($pMtype, 'array')) {
			foreach ($pMtype as $key => $value) {
				$ptype 		= $pMtype[$key];
				$pextra 	= $pMtype[$key];
				$pamount 	= formatNumberToInsertDB($pMamount[$key]);

				if ($ptype) {
					$pmethod[] = ['type' => $ptype, 'total' => $pamount, 'price' => $pamount, 'extra' => $pextra];
				}
			}
		}
		$record['transactionPaymentType'] = json_encode($pmethod);
	}

	//print_r(['transaction', $record, 'UPDATE', 'transactionId = '. $dId . ' AND companyId = ' . COMPANY_ID]);
	//dai();

	$update = $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ' . $dId . ' AND companyId = ' . COMPANY_ID);
	if ($update === false) {
		echo 'false';
	} else {
		$db->AutoExecute('itemSold', ['itemSoldDate' => validateHttp('date', 'post')], 'UPDATE', 'transactionId = ' . $dId);
		$db->AutoExecute('comission', ['comissionDate' => validateHttp('date', 'post')], 'UPDATE', 'transactionId = ' . $dId);

		echo 'true|0|' . $id;
	}
	dai();
}

if (validateHttp('action') == 'updateItem') {
	if (!allowUser('sales', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$out = 'false';
	if (validateHttp('id') && validateHttp('uid')) {
		$itemSoldId = dec(validateHttp('id'));
		$itemId 	= dec(validateHttp('uid'));

		$update 	= $db->AutoExecute('itemSold', ['itemId' => $itemId], 'UPDATE', 'itemSoldId = ' . $itemSoldId);

		if ($update !== false) {
			$out = 'true';
		} else {
			$out = 'false';
		}
	}
	dai($out);
}

if (validateHttp('action') == 'updateItemTotal') {
	if (!allowUser('sales', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$out = 'false';
	if (validateHttp('id') && validateHttp('uid')) {
		$itemSoldId = dec(validateHttp('id'));
		$total 		= formatNumberToInsertDB(validateHttp('total'));

		$update 	= $db->AutoExecute('itemSold', ['itemSoldTotal' => $total], 'UPDATE', 'itemSoldId = ' . $itemSoldId);

		if ($update !== false) {
			$out = 'true';
		} else {
			$out = 'false';
		}
	}
	dai($out);
}

if (validateHttp('action') == 'delete' && validateHttp('id')) {
	if (!allowUser('sales', 'delete', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$id 	= dec(validateHttp('id'));

	$data 	= [
		'api_key'     	=> API_KEY,
		'company_id'  	=> enc(COMPANY_ID),
		'id' 			=> validateHttp('id'),
		'parent' 		=> validateHttp('parent')
	];

	$result = curlContents(API_URL.'/delete_transaction', 'POST', $data);

	header('Content-Type: application/json');
	dai($result);
}

if (validateHttp('action') == 'addPayment' && validateHttp('id', 'post')) {
	if (!allowUser('sales', 'edit', true)) {
		jsonDieResult(['error' => 'No permissions']);
	}

	$id 		= $db->Prepare(dec(validateHttp('id', 'post')));
	$value 		= formatNumberToInsertDB(validateHttp('payAmount', 'post'));
	$method 	= validateHttp('method', 'post');

	//[{"type":"cash","name":"Efectivo","price":8000,"total":"8000.00","extra":""}]

	$record 	= [];
	$record['transactionPaymentType'] 	= $value;

	$insert 	= $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ' . $id);

	if ($insert !== false) {
		dai('true|0|' . enc($id));
	} else {
		dai('false');
	}
}

//FORMULARIO DE EDICION
if (validateHttp('action') == 'edit') {
	$id =  validateHttp('id') ? validateHttp('id') : validateHttp('uid');

	if (validateHttp('id')) {
		$field = 'transactionId';
	} else {
		$field = 'transactionUID';
	}

	$result 	= ncmExecute("SELECT * FROM transaction WHERE " . $field . " = ? LIMIT 1", [dec($id)]);

	if (!$result || (!validateHttp('id')) && !validateHttp('uid')) {
		dai(
			'<div class="modal-body no-padder clear r-24x bg-white">' .
				'	<h1 class="block text-center wrapper font-thin">La transacción ' . $id . ' no existe</h1>' .
				'</div>' .
				'<div class="hidden">' . 'SELECT * FROM transaction WHERE ' . $field . ' = ' . dec($id) . ' LIMIT 1' .
				'</div>'
		);
	}

	$tDate 		  	= $result['transactionDate'];
	$tDDate		  	= $result['transactionDueDate'];
	$tUser 		  	= $result['userId'];
	$tResponsible 	= $result['responsibleId'];
	$tCustomer 		= $result['customerId'];
	$tOutlet 	  	= $result['outletId'];
	$tRegister 	  	= $result['registerId'];
	$tPayment 		= $result['transactionPaymentType'];
	$tNote 		  	= toUTF8($result['transactionNote']);
	$tags 		  	= json_decode($result['tags'] ?? "", true);
	$tSaleType 		= $result['transactionType'];
	$tComplete 		= $result['transactionComplete'];
	$tLocation 		= $result['transactionLocation'];
	$tTimestamp 	= $result['timestamp'];

	$totalSale 		= $result['transactionTotal'] - $result['transactionDiscount'];
	$saleTypep 		= false;

	if ($tSaleType == '0') {
		$saleTypep = 'Contado';
		$saleType = '<select name="trtype" class="form-control no-border b-b font-bold text-white"> <option value="contado" selected>CONTADO</option> <option value="credito">CRÉDITO</option> </select>';
	} else if ($tSaleType == '2') {
		$saleType = '<span class="font-bold text-u-c">Guardado</span>';
	} else if ($tSaleType == '3') {
		$saleTypep 		= 'Crédito';
		$creditStatus 	= ($tComplete) ? '<i class="material-icons m-r-xs text-white">check</i>' : '';
		$saleType 		= $creditStatus . '<span class="text-white font-bold text-u-c">Crédito</span>';
		//$saleType = '<select name="trtype" class="form-control no-border b-b font-bold"> <option value="contado">CONTADO</option> <option value="credito" selected>CRÉDITO</option> </select>';
	} else if ($tSaleType == '6') {
		$saleType 		= '<span class="font-bold text-u-c">Devolución / Nota de Crédito</span>';
		$totalSale 		= $result['transactionTotal'];
	} else if ($tSaleType == '7') {
		if ($result['responsibleId']) {
			$responsible 	= getValue('contact', 'contactName', 'WHERE type = 0 AND contactId = ' . $result['responsibleId'] . ' LIMIT 1');
			$responsibleText = '<div class="text-sm hidden-print">Por ' . $responsible . '</div>';
		}
		$saleType = '<span class="font-bold text-md text-u-c hidden-print">Anulado</span> ';
	} else if ($tSaleType == '9') {
		$saleType = '<span class="font-bold text-md text-u-c hidden-print">Cotización</span>';
	} else if ($tSaleType == '10') {
		$saleType = '<span class="font-bold text-md text-u-c hidden-print">Remisión</span>';
	} else if ($tSaleType == '12') {
		$saleType = '<span class="font-bold text-md text-u-c hidden-print">Orden</span>';
	} else if ($tSaleType == '13') {
		$saleType = '<span class="font-bold text-md text-u-c hidden-print">Agendamiento</span>';
	}

?>

	<div class="modal-body no-padder clear r-24x" id="factura">
		<?= headerPrint(['noOutlet' => true]); ?>
		<form action="<?= $baseUrl ?>?action=update" method="POST" id="editSale" name="editSale">
			<div class="row equal padder">
				<div class="col-md-3 col-md-offset-0 col-sm-10 col-sm-offset-1 col-xs-12 wrapper bg-info gradBgBlue hidden-print">
					<?php
					$userName 		= ncmExecute('SELECT contactName FROM contact WHERE contactId = ? LIMIT 1', [$tUser], true); //getContactData($tUser);
					$respName = [];
					if ($tResponsible) {
						$respName 		= ncmExecute('SELECT contactName FROM contact WHERE contactId = ? LIMIT 1', [$tResponsible], true);
					}
					$userName 		= toUTF8($userName['contactName']);
					$respName 		= toUTF8($respName['contactName'] ?? '');
					$customerDT 	= ncmExecute('SELECT contactName, contactTIN FROM contact WHERE contactId = ? LIMIT 1', [$tCustomer], true);
					$customerName 	= toUTF8($customerDT['contactName'] ?? "");
					$customerTIN 	= toUTF8($customerDT['contactTIN'] ?? "");

					$userName 		= iftn($userName, 'Sin Usuario');
					$respName 		= iftn($respName, 'Sin Responsable');
					$customerName 	= iftn($customerName, 'Sin cliente');
					?>
					<div class="col-md-12 col-sm-4 col-xs-12 no-padder m-b">
						<div class="font-bold text-u-c text-xs">Tipo</div>
						<div>
							<?= $saleType ?>
							<?php
							if ($result['transactionType'] == 9) {
							?>
								<a href="/screens/quoteView?s=<?= base64_encode(enc($result['transactionId']) . ',' . enc(COMPANY_ID)) ?>" class="hidden-print" target="_blank"> <i class="material-icons">launch</i></a>
							<?php
							}
							?>
						</div>

						<div class="font-bold text-xs text-u-c m-t">Vencimiento</div>
						<?php
						if (in_array($tSaleType, [3, 9])) {
						?>
							<div class="<?= isInvoiceEditable() ? 'visible-print' : ''; ?>"><?= niceDate($tDDate) ?></div>
							<div class="text-default <?= (isInvoiceEditable() ? 'hidden-print' : 'hidden') ?>">
								<input type="text" class="form-control datepicker no-border b-b no-bg text-center pointer text-white" name="duedate" value="<?= $tDDate ?>" autocomplete="off" />
							</div>
						<?php
						} else {
						?>
							<div><?= niceDate($tDate) ?></div>
						<?php
						}
						?>
					</div>

					<div class="col-md-12 col-sm-4 col-xs-12 no-padder m-b">
						<div class="text-u-c text-xs font-bold"># Documento</div>
						<div class="<?= isInvoiceEditable() ? 'visible-print' : ''; ?>"><?= $result['invoicePrefix'] ?><?= $result['invoiceNo'] ?></div>
						<div class="h3 font-bold <?= (isInvoiceEditable() ? 'hidden-print' : 'hidden') ?>">
							<input type="number" min="0" step="1" value="<?= $result['invoiceNo'] ?>" name="invoiceNo" class="form-control no-border no-bg b-b text-lg text-white text-right" autocomplete="off" />
						</div>
					</div>

					<div class="col-md-12 col-sm-4 col-xs-12 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Cliente</span>

						<div class="<?= isInvoiceEditable() ? 'visible-print' : ''; ?>"><?= $customerName ?> (<?= $customerTIN; ?>)</div>
						<div class="<?= (isInvoiceEditable() ? 'hidden-print' : 'hidden') ?>">
							<select name="customer" class="form-control selectCustomer text-white">
								<?php
								if (validity($result['customerId'])) {
								?>
									<option value="<?= enc($result['customerId']) ?>" selected><?= $customerName; ?></option>
								<?php
								}
								?>
							</select>
						</div>
					</div>

					<div class="col-md-12 col-sm-4 col-xs-12 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Fecha</span>
						<div class="<?= isInvoiceEditable() ? 'visible-print' : ''; ?>"><?= niceDate($tDate, true) ?></div>
						<div class="text-default <?= (isInvoiceEditable() ? 'hidden-print' : 'hidden') ?>">
							<input type="text" class="form-control datepicker no-border b-b no-bg text-center pointer text-white" name="date" value="<?= $tDate ?>" autocomplete="off" />
						</div>
					</div>

					<div class="col-md-12 col-sm-4 col-xs-12 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Sucursal</span>
						<div class="<?= isInvoiceEditable() ? 'visible-print' : ''; ?>"><?= getCurrentOutletName($tOutlet) ?></div>
						<div class="<?= (isInvoiceEditable() ? 'hidden-print' : 'hidden') ?>">
							<?php
							echo selectInputOutlet($tOutlet, false, 'no-border selectSimple b-b text-white ' . (isInvoiceEditable() ? 'hidden-print' : 'hidden'), 'outlet', true);
							?>
						</div>
					</div>

					<div class="col-md-12 col-sm-4 col-xs-12 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Usuario</span>
						<div class="<?= isInvoiceEditable() ? 'visible-print' : ''; ?>"><?= $userName ?></div>
						<div class="<?= (isInvoiceEditable() ? 'hidden-print' : 'hidden') ?>">
							<?php
							echo selectInputUser($tUser, false, 'userselect selectSimple no-border b-b text-white ' . (isInvoiceEditable() ? 'hidden-print' : 'hidden'), 'user', true);
							?>
						</div>
					</div>

					<div class="col-md-12 col-sm-4 col-xs-12 no-padder m-b <?= (!$tResponsible) ? 'hidden' : '' ?>">
						<span class="font-bold text-xs text-u-c">Responsable</span>
						<div class="<?= isInvoiceEditable() ? 'visible-print' : ''; ?>"><?= $respName ?></div>
						<div class="<?= (isInvoiceEditable() ? 'hidden-print' : 'hidden') ?>">
							<?php
							echo selectInputUser($tResponsible, false, 'userselect selectSimple no-border b-b text-white ' . (isInvoiceEditable() ? 'hidden-print' : 'hidden'), 'responsible', true);
							?>
						</div>
					</div>

					<div class="col-md-12 col-sm-8 col-xs-12 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Etiquetas</span>
						<?php
						$allTags = getAllTags();

						$tagsO = '';

						if (validity($tags, 'array')) {
							foreach ($tags as $tag) {
								$tagsO 	.= enc($tag) . ',';
							}
						}

						$tagsO = explodes(',', $tagsO);

						$allT = [];
						if (validity($allTags, 'array')) {
							foreach ($allTags as $key => $tag) {
								$allT[enc($key)] = $tag['name'];
							}
						}

						$tagsO = array_flatten($tagsO);

						echo '<div class="hidden-print ' . (isInvoiceEditable() ? '' : 'hidden') . '">' . selectInputGenerator($allT, ['match' => $tagsO, 'name' => 'trTags[]', 'class' => 'selectTags', 'data' => 'multiple="multiple"']) . '</div>';

						echo '<div class="' . (isInvoiceEditable() ? 'visible-print' : '') . '">' . printOutTags($tags) . '</div>';


						?>
					</div>

					<div class="col-md-12 col-sm-12 col-xs-12 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Nota</span>
						<p class="<?= (isInvoiceEditable() ? 'visible-print' : '') ?>">
							<?= $tNote ?>
						</p>
						<div class="<?= (isInvoiceEditable() ? 'hidden-print' : 'hidden') ?>">
							<textarea class="form-control no-bg text-white no-border b-b" name="note"><?= $tNote ?></textarea>
						</div>
					</div>

					<span class="badge hidden-print">ID: <?= $tTimestamp ?></span>
				</div>

				<div class="col-md-9 col-sm-12 col-xs-12 no-padder bg-white">
					<div class="col-xs-12 wrapper">
						<div class="col-xs-12 m-b-md visible-print">
							<div class="col-sm-4 no-padder">
								<label class="font-bold text-u-c text-xs">Tipo</label>
								<?php
								echo ($saleTypep) ? $saleTypep : strip_tags($saleType);
								?>
								<br>
								<label class="font-bold text-u-c text-xs"># Documento</label> <?= $result['invoicePrefix'] ?> <?= $result['invoiceNo'] ?>
								<br>
								<label class="font-bold text-u-c text-xs">Fecha</label> <?= niceDate($tDate, true) ?>
							</div>
							<div class="col-sm-4 no-padder">
								<label class="font-bold text-u-c text-xs">Vencimiento</label> <?= niceDate(iftn($tDDate, $tDate)) ?>
								<br>
								<label class="font-bold text-u-c text-xs">Cliente</label> <?= $customerName; ?> (<?= $customerTIN; ?>)
								<br>
								<label class="font-bold text-u-c text-xs">Sucursal</label> <?= getCurrentOutletName($tOutlet) ?>
							</div>
							<div class="col-sm-4 no-padder">
								<label class="font-bold text-u-c text-xs">Usuario</label>
								<?= $userName; ?>
								<br>
								<label class="font-bold text-u-c text-xs">Etiquetas</label>
								<?php
								echo printOutTags($tags, false, true);
								?>
								<br>
								<label class="font-bold text-u-c text-xs">Nota</label>
								<?= $tNote ?>
							</div>
						</div>
						<div class="col-xs-12 text-center m-b-md no-border panel bg-light lter r-24x table-responsive">
							<table class="table" id="modalItemsTable">
								<thead>
									<tr class="text-u-c">
										<th style="width:10%;">Cant.</th>
										<th class="text-left">Artículo</th>
										<th class="text-left hidden-print">Usuario</th>
										<th style="width:20%;" class="text-right">Precio Uni.</th>
										<th class="text-right">IVA</th>
										<th style="width:20%;" class="text-right">Total</th>
									</tr>
								</thead>
								<tbody>
									<?php
									if (in_array($tSaleType, ['2', '7', '9', '11', '12', '13'])) {
										foreach (json_decode($result['transactionDetails'], true) as $key => $val) {
											$isCustom = false;

											if ((!$val['itemId'] && $val['note'])) {
												$isCustom = true;
											}
											$tax = 0;
											if (validity($val['itemId']) || $isCustom) {
												$itemData 	= getItemData(dec($val['itemId']), true);

												$name 		= toUTF8($itemData['itemName'] ?? "");

												$units 		= formatQty($val['count']);
												$price 		= formatCurrentNumber($val['total'] / $val['count']);
												$discount	= formatCurrentNumber($val['discAmount'] ?? 0);

												$userItemName 	= ($val['user']) ? toUTF8(getValue('contact', 'contactName', 'WHERE type = 0 AND contactId = ' . dec($val['user']))) : '';

												$total 		= formatCurrentNumber($val['total'] - $discount);

												$taxName 	= toUTF8($val['tax']);
												$subtotal	= $val['price'];
												if ($taxName < 0) {
													$taxName 	= $taxes[$inventory[$val['itemId']]['tax']]['name'];
													$tax 		= formatCurrentNumber(getTaxOfPrice($taxName, $subtotal));
												} else {
													$tax 		= formatCurrentNumber(getTaxOfPrice($taxName, $subtotal));
												}

												$subtotal	= formatCurrentNumber($val['price']);
												if ((!empty($itemData['itemType']) && $itemData['itemType'] == 'dynamic') || $isCustom) {
													$name = iftn($val['note'], $name);
												}

									?>
												<tr>
													<td><?= $units ?></td>
													<td class="text-left">
														<?php
														if ($val['note']) {
														?>
															<a href="#" title="<?= $val['note'] ?>">
																<?= $name ?>
															</a>
														<?php
														} else {
														?>
															<?= $name ?>
														<?php
														}
														?>
													</td>
													<td class="text-left hidden-print"><?= $userItemName; ?></td>
													<td class="text-right"><?= $price ?></td>
													<td class="text-right"><?= $tax ?></td>
													<td class="text-right"><?= $total ?></td>
												</tr>
												<?php
											}
										}
									} else {
										$inventory 	= getAllInventory();
										$taxes 		= getAllTax();
										$items 	= ncmExecute("SELECT * FROM itemSold WHERE transactionId = ?", [$result['transactionId']], false, true);
										if ($items) {
											while (!$items->EOF) {
												$item 		= $items->fields;
												$encISId 	= enc($item['itemSoldId']);
												$tax = 0;
												if (validity($item['itemId'])) {

													$itemData 	= getItemData($item['itemId']);
													$itemName	= toUTF8($itemData['itemName'] ?? '');

													if (in_array($itemData['itemType'] ?? null, ['combo', 'precombo', 'comboAddons', 'service', 'product', 'production', 'direct_production', 'giftcard', 'discount', 'dynamic', NULL])) {

														if (in_array($tSaleType, [0, 3]) && isInvoiceEditable()) {
															$userSold 	= 	selectInputUser($item['userId'], false, 'no-border b-b selectUser ' . $encISId, 'itemUser[' . $encISId . ']', true, 'data-id="' . $encISId . '"');
														} else {
															$userItemName 	= toUTF8(getValue('contact', 'contactName', 'WHERE type = 0 AND contactId = ' . $item['userId']));
															$userSold 	= 	 $userItemName;
														}

														if (isInvoiceEditable()) {
															$name 		= 	'	<div class="hidden-print"><select name="itemNew[' . $encISId . ']" class="form-control selectItem" data-id="' . $encISId . '">' .
																'		<option value="' . enc($item['itemId']) . '" selected>' . $itemName . '</option>' .
																'	</select></div>' .
																'<div class="visible-print">' . $itemName . '</div>';
														} else {
															$name 		= iftn($itemName, 'El artículo no existe');
														}

														if ($item['itemSoldParent'] && !in_array($itemData['itemType'], ['combo', 'precombo', 'comboAddons'])) {
															$name 		= '↳ ' . $itemName;
															$price 		= '-';
															$subtotal 	= 0;
															$total 		= '-';
															$tax 		= '-';
															$discount	= '-';
														} else {
															$price 		= formatCurrentNumber($item['itemSoldTotal'] / $item['itemSoldUnits']);

															$subtotal	= formatCurrentNumber($item['itemSoldTotal'] + $item['itemSoldDiscount']);

															$total 		= CURRENCY . formatCurrentNumber($item['itemSoldTotal']);

															// if($item['itemSoldTax'] < 0){
															// 	$taxName 	= toUTF8($taxes[$inventory[$item['itemId']]['tax']]['name']);
															// 	$tax 		= CURRENCY . formatCurrentNumber(getTaxOfPrice($taxName,$subtotal));	
															// }else{
															// 	$tax 		= CURRENCY . formatCurrentNumber($item['itemSoldTax']);
															// }
															$tax 		= CURRENCY . formatCurrentNumber($item['itemSoldTax']);

															$discount	= CURRENCY . formatCurrentNumber($item['itemSoldDiscount']);
														}

														if (!empty($itemData['itemType']) && $itemData['itemType'] == 'discount') {
															$name 	= 'Descuento';
														}

														if (!empty($itemData['itemType']) && $itemData['itemType'] == 'dynamic') {
															$name = iftn($item['itemSoldDescription'], $name);
														}

														$units 		= formatCurrentNumber($item['itemSoldUnits'], 'yes');
													}
												?>
													<tr>
														<td>
															<input type="hidden" name="itemTrsId[]" value="<?= $encISId ?>">
															<input type="text" name="itemQty[<?= $encISId ?>]" class="form-control no-bg no-border b-b maskQty hidden-print" data-id="<?= $encISId ?>" value="<?= $units ?>">
															<div class="visible-print"><?= formatQty($item['itemSoldUnits']); ?></div>
														</td>
														<td class="text-left">
															<?php
															if (!empty($val['note'])) {
															?>
																<a href="#" title="<?= $val['note'] ?>">
																	<?= $name ?>
																</a>
															<?php
															} else {
															?>
																<?= $name ?>
															<?php
															}
															?>
														</td>
														<td class="text-left hidden-print"><?= $userSold ?></td>
														<td class="text-right">
															<div class="visible-print text-right"><?= $price ?></div>
															<input type="text" name="itemPrice[<?= $encISId ?>]" class="form-control no-bg no-border b-b maskCurrency changeItem text-right hidden-print" data-id="<?= $encISId ?>" value="<?= formatCurrentNumber($item['itemSoldTotal'] / $item['itemSoldUnits']) ?>">
														</td>
														<td class="text-right"><?= $tax ?></td>
														<td>
															<div class="text-right"><?= formatCurrentNumber($item['itemSoldTotal']); ?></div>
														</td>
													</tr>
									<?php
												}
												$items->MoveNext();
											}
										} else {
											if (isInvoiceEditable()) {
												$name 		= 	'	<select name="item[0]" class="form-control selectItem" data-id="">' .
													'		<option value="" selected></option>' .
													'	</select>';
											} else {
												$name 		= iftn($itemName, 'El artículo no existe');
											}
										}
									}
									?>
								</tbody>
								<tfoot class="font-bold text-right">
									<tr>
										<td class="hidden-print"></td>
										<td colspan="4">Descuentos</td>
										<td><?= formatCurrentNumber($result['transactionDiscount']) ?></td>
									</tr>
									<tr>
										<td class="hidden-print"></td>
										<td colspan="4">Subtotal (Base Imponible)</td>
										<td><?= formatCurrentNumber($totalSale - $result['transactionTax']) ?></td>
									</tr>
									<tr>
										<td class="hidden-print"></td>
										<td colspan="4"><?= TAX_NAME ?></td>
										<td><?= formatCurrentNumber($result['transactionTax']) ?></td>
									</tr>

									<tr class="text-lg">
										<td class="hidden-print"></td>
										<td colspan="4" class="text-u-c">Total</td>
										<td><?= formatCurrentNumber($totalSale) ?></td>
									</tr>

								</tfoot>
							</table>
						</div>

						<div class="col-sm-6 col-sm-offset-6 col-md-offset-0 col-md-12 col-xs-12 no-padder text-left">
							<div class="text-center m-b-md no-border panel bg-light lter r-24x col-xs-12 table-responsive">
								<?php if (in_array($tSaleType, [0, 5])) { ?>

									<table class="table text-left">
										<tr class="font-bold text-u-c">
											<td colspan="3" class="text-center">
												Métodos de pago
											</td>
										</tr>
										<?php
										$paymentType 	= getPaymentMethodsInArray($tPayment);
										$tPtypes 		= getAllPaymentMethodsArray();

										if (validity($paymentType, 'array')) {
											foreach ($paymentType as $payment) {
												$extra = toUTF8($payment['extra']);

												if ($payment['total'] < 0.001) {
													continue;
												}
										?>
												<tr class="">
													<td>
														<?php
														if (isInvoiceEditable()) {
															echo selectInputGenerator($tPtypes, ['match' => $payment['type'], 'class' => 'selectSimple b-light', 'name' => 'pMtype[]']);
														} else {
															echo toUTF8($payment['name']);
														}
														?>
													</td>
													<td>
														<?php
														if ($payment['type'] == 'check') {
															$extra = csvToBankData($payment['extra']);
															echo $extra;
														} else if ($payment['type'] == 'giftcard') {
															echo '<a href="/@#report_giftCards?i=' . $payment['extra'] . '" class="btn btn-default hidden-print font-bold rounded btn-block" target="_blank">' . $payment['extra'] . '</a><span class="visible-print">' . $payment['extra'] . '</span>';
														} else if (!isInvoiceEditable()) {
															echo $extra;
														} else {
														?>
															<input class="form-control no-border no-bg b-b b-light" value="<?= $extra; ?>" placeholder="Info. extra" name="pMextra[]" autocomplete="false">
														<?php
														}
														?>

													</td>
													<td class="text-right font-bold">
														<?php
														if (isInvoiceEditable()) {
														?>
															<input class="form-control text-right maskCurrency no-border no-bg b-b b-light" value="<?= formatCurrentNumber($payment['price']) ?>" placeholder="Monto" name="pMamount[]" autocomplete="false">
														<?php
														} else {
															echo formatCurrentNumber($payment['price']);
														}
														?>
													</td>
												</tr>
											<?php
											}
										}

										if (isInvoiceEditable()) {
											?>
											<tr class="hidden-print">
												<td> <?php echo selectInputGenerator($tPtypes, ['class' => 'selectSimple b-light', 'name' => 'pMtype[]', 'allowNone' => '1']); ?></td>
												<td><input class="form-control no-border b-b no-bg b-light" value="" placeholder="Info. extra" name="pMextra[]"></td>
												<td class="text-right" autocomplete="false">
													<input class="form-control text-right maskCurrency no-border no-bg b-b b-light" value="" placeholder="Monto" name="pMamount[]" autocomplete="false">
												</td>
											</tr>
										<?php
										}
										?>
									</table>
									<input type="hidden" name="haspayments" value="1">

								<?php } else if ($tSaleType == 9) { //cotizacion 
								?>

									<table class="table">
										<tr class="font-bold text-u-c">
											<td colspan="3" class="text-center">
												Facturas asociadas
											</td>
										</tr>
										<?php
										$cotiz = ncmExecute('SELECT transactionId, transactionTotal, transactionDate, invoiceNo, invoicePrefix FROM transaction WHERE transactionParentId = ? AND companyId = ?', [$result['transactionId'], COMPANY_ID], false, true);
										if ($cotiz) {
											while (!$cotiz->EOF) {
												$cotiza = $cotiz->fields;
										?>
												<tr class="clickrow" data-id="<?= enc($cotiza['transactionId']) ?>" data-ro="1">
													<td><?= $cotiza['invoicePrefix'] . $cotiza['invoiceNo'] ?></td>
													<td><?= niceDate($cotiza['transactionDate']) ?></td>
													<td class="text-right"><?= formatCurrentNumber($cotiza['transactionTotal']) ?></td>
												</tr>
										<?php
												$cotiz->MoveNext();
											}
											$cotiz->Close();
										}
										?>
									</table>

								<?php } else if ($tSaleType == 3) {

									$credit = ncmExecute('SELECT * FROM transaction WHERE transactionParentId = ? AND transactionType IN(5,6) AND ' . $SQLcompanyId, [dec($id)], false, true);
									$totalPaid = 0;
								?>

									<table class="table">
										<tr class="font-bold text-u-c">
											<td colspan="5" class="text-center">
												Pagos
											</td>
										</tr>
										<?php
										if ($credit) {
											while (!$credit->EOF) {
												$crediDate 	= explodes(' ', $credit->fields['transactionDate']);
												$crediDate 	= $crediDate[0];
												$trsTotal 	= abs($credit->fields['transactionTotal']);
												$totalPaid += $trsTotal;

												//payment methods
												$paymentType 	= getPaymentMethodsInArray($credit->fields['transactionPaymentType']);
												$pMethods 		= '';

												if (validity($paymentType, 'array')) {
													foreach ($paymentType as $pType) {
														$pMethods .= '<span class="label bg-light m-r-xs" data-toggle="tooltip" data-placement="top" title="' . formatCurrentNumber($pType['price']) . '">' . iftn(getPaymentMethodName($pType['type']), $pType['name']) . '</span>';
													}
												}

												if ($credit->fields['transactionType'] == 6) { //es nota de credito
													$pMethods = '<span class="label bg-light m-r-xs" data-toggle="tooltip" data-placement="top" title="' . formatCurrentNumber($trsTotal) . '">Nota de Crédito / Devolución</span>';
												}
												//
										?>
												<tr>
													<td class="text-left">
														<?= niceDate($credit->fields['transactionDate']) ?>
													</td>
													<td class="text-left">
														<?= $pMethods ?>
													</td>
													<td class="text-right">
														<?= CURRENCY . formatCurrentNumber($trsTotal) ?>
													</td>
													<td class="text-right">

														<a href="/screens/receipt?s=<?= base64_encode(enc($credit->fields['transactionId']) . ',' . enc(COMPANY_ID)) ?>" class="hidden-print" target="_blank"><i class="material-icons text-info">receipt</i></a>

													</td>
													<td class="text-right">
														<?php
														if (isInvoiceEditable()) {
														?>
															<a href="<?= $baseUrl ?>?action=delete&outlet=<?= enc($tOutlet) ?>&id=<?= enc($credit->fields['transactionId']); ?>&type=<?= enc($credit->fields['transactionType']) ?>&parent=<?= $id ?>" class="hidden-print deleteTransaction" data-payment="<?= enc($credit->fields['transactionId']); ?>"><i class="material-icons text-danger">close</i></a>
														<?php
														}
														?>
													</td>
												</tr>
										<?php
												$credit->MoveNext();
											}
											$credit->Close();
										} else {
											echo '<tr><td class="text-center">No hay pagos asociados</td></tr>';
										}
										?>
									</table>

									<?php
									$deudaTotal = $totalSale - $totalPaid;
									?>

									<div class="col-xs-12 no-padder text-center m-b m-t">
										<div class="col-sm-4 no-padder b-r b-light">
											<div class="h4 font-bold">
												<?= formatCurrentNumber($totalSale); ?>
											</div>
											Total
										</div>
										<div class="col-sm-4 no-padder">
											<div class="h4 font-bold">
												<?= formatCurrentNumber($totalPaid); ?>
											</div>
											Pagado
										</div>
										<div class="col-sm-4 no-padder b-l b-light">
											<div class="h4 font-bold">
												<?= formatCurrentNumber($deudaTotal); ?>
											</div>
											Deuda
										</div>
									</div>

								<?php
									if ($deudaTotal < 0.01 && $tComplete < 1) { //si el total pagado es mayor a la cuenta total cierro la factura a credito
										$db->AutoExecute('transaction', ['transactionComplete' => '1'], 'UPDATE', 'transactionId = ' . dec($id));
									}
								}
								?>
							</div>

							<?php
							$returns = ncmExecute('SELECT * FROM transaction WHERE transactionType = 6 AND transactionParentId = ? AND companyId = ?', [$result['transactionId'], COMPANY_ID], false, true);
							if ($returns) {
							?>
								<div class="text-center m-b-md no-border panel bg-light lter r-24x col-xs-12 table-responsive">
									<table class="table text-left">
										<tr class="font-bold text-u-c">
											<td colspan="3" class="text-center">
												Notas de Crédito
											</td>
										</tr>
										<?php
										while (!$returns->EOF) {
										?>

											<tr class="subModal pointer" data-id="<?= enc($returns->fields['transactionId']) ?>">
												<td>#<?= formatQty($returns->fields['invoiceNo']) ?></td>
												<td><?= niceDate($returns->fields['transactionDate']) ?></td>
												<td class="text-right font-bold"><?= formatCurrentNumber($returns->fields['transactionTotal']) ?></td>
											</tr>

										<?php
											$returns->MoveNext();
										}
										?>
									</table>
								</div>

							<?php
							}
							?>

							<?php
							$returns = ncmExecute('SELECT * FROM toTransaction WHERE transactionId = ?', [$result['transactionId']], false, true);

							if ($returns) {
							?>
								<div class="text-center m-b-md no-border panel bg-light lter r-24x col-xs-12 table-responsive">
									<table class="table text-left">
										<tr class="font-bold text-u-c">
											<td colspan="4" class="text-center">
												Transacciones asociadas
											</td>
										</tr>
										<?php
										while (!$returns->EOF) {
											$retur = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$returns->fields['parentId'], COMPANY_ID]);
											if ($retur) {
										?>

												<tr class="subModal pointer" data-id="<?= enc($retur['transactionId']) ?>">
													<td><?= getTransactionTypeName($retur['transactionType']) ?></td>
													<td>#<?= formatQty($retur['invoiceNo']) ?></td>
													<td><?= niceDate($retur['transactionDate']) ?></td>
													<td class="text-right font-bold"><?= formatCurrentNumber($retur['transactionTotal'] - $retur['transactionDiscount'] ) ?></td>
												</tr>

										<?php
											}
											$returns->MoveNext();
										}
										?>
									</table>
								</div>

							<?php
							}
							?>

							<?php
							$returns = ncmExecute('SELECT * FROM transaction WHERE transactionType = 13 AND transactionParentId = ? AND companyId = ?', [$result['transactionId'], COMPANY_ID], false, true);
							if ($returns) {
							?>
								<div class="text-center m-b-md no-border panel bg-light lter r-24x col-xs-12 table-responsive">
									<table class="table text-left">
										<tr class="font-bold text-u-c">
											<td colspan="3" class="text-center">
												Agendamientos
											</td>
										</tr>
										<?php
										while (!$returns->EOF) {
										?>

											<tr class="subModal pointer" data-id="<?= enc($returns->fields['transactionId']) ?>">
												<td>#<?= formatQty($returns->fields['invoiceNo']) ?></td>
												<td><?= niceDate($returns->fields['transactionDate']) ?></td>
												<td class="text-right font-bold"><?= formatCurrentNumber($returns->fields['transactionTotal']) ?></td>
											</tr>

										<?php
											$returns->MoveNext();
										}
										?>
									</table>
								</div>
							<?php
							}
							?>

							<?php
							if ($_modules['dropbox']) {
							?>
								<div class="text-center m-b-md no-border panel r-24x col-xs-12 table-responsive" id="DBFiles">

								</div>

								<script type="text/javascript">
									$(document).ready(function() {
										var opts = {
											"loadEl": '#DBfileInput,#DBFiles',
											"listEl": '#DBFiles',
											"token": '<?= $_modules['dropboxToken'] ?>',
											'folder': '/transactions/<?= enc($result['transactionId']) ?>'
										};

										ncmDropbox(opts);
									});
								</script>

							<?php
							}
							?>

							<!--TUS FACTURAS-->
							<?php
							if ($_modules['tusfacturas'] && isInvoiceEditable(['blockOnRO' => true])) {
							?>
								<div class="col-xs-12 no-padder hidden-print" id="holaFacturaForm">
									<div class="col-xs-12 text-center">
										<img src="https://vousys.nyc3.digitaloceanspaces.com/tusfacturas/web/images/logo-tus-facturas-electronicas-color.png" height="60">
									</div>

									<?php
									$TFisPDF = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyType = \'tusFacturas\' AND sourceId = ? AND companyId = ? LIMIT 1', [$result['transactionId'], COMPANY_ID]);

									if ($TFisPDF) {
									?>
										<div class="col-xs-12 m-b-lg">
											<div class="col-xs-12 wrapper gradBgYellow b r-24x m-t text-center">
												<div class="text-u-c font-bold m-b text-default">Esta transacción ya fue enviada a TusFacturas</div>
												<a href="<?= $TFisPDF['taxonomyName'] ?>" class="btn btn-lg rounded btn-info font-bold text-u-c" target="_blank">Ver PDF</a>
											</div>
										</div>
										<script>
											$.get("<?= $baseUrl ?>?action=tusFacturas&url=<?= $TFisPDF['taxonomyName'] ?>&id=<?= enc($result['transactionId']) ?>");
										</script>
									<?php
									} else {
									?>

										<div class="col-xs-6">
											<!--Tipo de Impuesto-->
											<?php
											//echo selectInputGenerator(['0' => 'IVA RG 3337','1' => 'Impuesto Nacional','2' => 'Percepción de IIBB','3' => 'Impuesto Municipal','4' => 'Impuesto Interno','99' => 'Otro Impuesto'],['name'=>'IdTipoImpuesto','class'=>'m-b']);
											?>
											<label class="font-bold text-u-c text-xs">Tipo de IVA</label>
											<?php
											echo selectInputGenerator(['CF' => 'Consumidor Final', 'E' => 'Exento', 'M' => 'Responsable Monotributo', 'RI' => 'Responsable Inscripto', 'CDEX' => 'Cliente del Exterior'], ['name' => 'IdTipoIVA', 'class' => 'm-b no-bg no-border b-b']);
											?>

											<label class="font-bold text-u-c text-xs">Fecha</label>
											<input type="text" class="form-control datepicker no-border b-b m-b text-center pointer" name="tfdate" value="<?= $tDate ?>" autocomplete="off" />

											<label class="font-bold text-u-c text-xs">Nro. Factura</label>
											<input type="text" name="tfinvoiceno" value="<?= $result['invoiceNo'] ?>" class="form-control text-right no-bg no-border b-b">

										</div>
										<div class="col-xs-6">
											<label class="font-bold text-u-c text-xs">Tipo de Comprobante</label>
											<?php
											echo selectInputGenerator(['FACTURA B' => 'FACTURA B', 'FACTURA A' => 'FACTURA A', 'NOTA DE DÉBITO A' => 'NOTA DE DÉBITO A', 'NOTA DE CRÉDITO A' => 'NOTA DE CRÉDITO A', 'RECIBO A' => 'RECIBO A', 'NOTA DE DÉBITO B' => 'NOTA DE DÉBITO B', 'NOTA DE CRÉDITO B' => 'NOTA DE CRÉDITO B', 'RECIBO B' => 'RECIBO B', 'FACTURA C' => 'FACTURA C', 'NOTA DE DÉBITO C' => 'NOTA DE DÉBITO C', 'NOTA DE CRÉDITO C' => 'NOTA DE CRÉDITO C', 'RECIBO C' => 'RECIBO C', 'FACTURA M' => 'FACTURA M', 'NOTA DE DÉBITO M' => 'NOTA DE DÉBITO M', 'NOTA DE CRÉDITO M' => 'NOTA DE CRÉDITO M', 'RECIBO M' => 'RECIBO M'], ['name' => 'IdTipoComprobante', 'class' => 'm-b no-bg no-border b-b']);
											?>
											<!--Concepto-->
											<?php
											//echo selectInputGenerator(['1' => 'BIENES','2' => 'SERVICIOS','3' => 'BIENES Y SERVICIOS'],['name'=>'IdConcepto','class'=>'m-b']);
											?>

											<!--Condición de venta-->
											<?php
											//echo selectInputGenerator(["1"=>"Contado", "2"=>"Tarjeta de Crédito", "3"=>"Tarjeta de Débito", "4"=>"Cuenta Corriente", "5"=>"Cheque", "6"=>"Ticket", "7"=>"Otro", "8"=>"Transferencia"],['name'=>'IdCondicionVenta','class'=>'m-b']);
											?>

											<label class="font-bold text-u-c text-xs">Tipo de Documento (Cliente)</label>
											<?php
											echo selectInputGenerator(['DNI' => 'DNI', 'CUIL' => 'CUIL', 'CUIT' => 'CUIT', 'CDI' => 'CDI', 'LE' => 'LE', 'LC' => 'LC', 'CI Extranjera' => 'CI Extranjera', 'Pasaporte' => 'Pasaporte', 'Doc. (Otro)' => 'Doc. (Otro)'], ['name' => 'IdTipoDocumento', 'class' => 'm-b no-bg no-border b-b']);
											?>

											<label class="font-bold text-u-c text-xs">Provincia</label>
											<?php
											echo selectInputGenerator(['19' => 'SAN LUIS', '26' => '-', '2' => 'BUENOS AIRES', '3' => 'CATAMARCA', '4' => 'CHACO', '5' => 'CHUBUT', '1' => 'CIUDAD AUTONOMA DE BUENOS AIRES', '6' => 'CORDOBA', '7' => 'CORRIENTES', '8' => 'ENTRE RIOS', '9' => 'FORMOSA', '10' => 'JUJUY', '11' => 'LA PAMPA', '12' => 'LA RIOJA', '13' => 'MENDOZA', '14' => 'MISIONES', '15' => 'NEUQUEN', '25' => 'Otro', '16' => 'RIO NEGRO', '17' => 'SALTA', '18' => 'SAN JUAN', '20' => 'SANTA CRUZ', '21' => 'SANTA FE', '22' => 'SANTIAGO DEL ESTERO', '23' => 'TIERRA DEL FUEGO', '24' => 'TUCUMAN'], ['name' => 'tfprovincia', 'class' => 'm-b no-bg no-border b-b']);
											?>
										</div>
										<div class="text-center col-xs-12 m-b-md m-t-md">
											<a href="#" class="btn btn-primary btn-lg text-u-c btn-rounded font-bold" data-url="/thirdparty/tusfacturas/tusfacturas?s=<?= base64_encode(enc(COMPANY_ID) . ',' . $id) ?>" id="sendToHolaFactura" data-id="<?= $id; ?>">Enviar a TusFacturas</a>
										</div>

										<div id="TFerrorbox" style="display: none;" class="col-xs-12 wrapper bg-light b r-24x m-t"></div>
									<?php
									}
									?>
								</div>
							<?php
							}
							?>
							<!--TUS FACTURAS-->
						</div>
						<?php
						if ($tLocation) {
						?>
							<div class="col-xs-12 wrapper text-center">
								<img width="100%" src="https://staticmap.openstreetmap.de/staticmap.php?center=<?= urlencode($tLocation) ?>&zoom=16&size=400x180&markers=<?= urlencode($tLocation) ?>,red-pushpin" alt="Mapa" />
								<br><br>
								<a href="https://www.openstreetmap.org/?mlat=<?= urlencode($tLocation) ?>&zoom=16" class="text-info" target="_blank">Mapa completo</a>
							</div>
						<?php
						}
						?>

					</div>

					<?= footerPrint(['signatures' => 2]); ?>

					<?php
					if (isInvoiceEditable()) {
					?>
						<div class="col-xs-12 hidden-print wrapper bg-light lter" style="margin-top:110px;">
							<button class="btn btn-info btn-lg btn-rounded font-bold text-u-c pull-right">Guardar</button>
							<a href="#" class="m-t cancelItemView m-r-lg pull-right">Cerrar</a>
							<a href="#" class="m-t print m-r-lg pull-right hidden-xs" data-element="#factura">Imprimir</a>

							<?php
							if (in_array($result['transactionType'], [0, 1, 2, 3, 4, 5, 6, 8])) {
							?>
								<a href="<?= $baseUrl ?>?action=delete&outlet=<?= enc($tOutlet) ?>&id=<?= $id; ?>&type=<?= enc($result['transactionType']) ?>" class="m-t m-r pull-left deleteTransaction"><span class="text-danger">Eliminar</span></a>
							<?php
							}
							?>


							<input type="hidden" name="id" value="<?= $id; ?>">
							<input type="hidden" name="type" value="<?= enc($tSaleType); ?>">
						</div>
					<?php
					} else {
					?>
						<div class="col-xs-12 hidden-print wrapper bg-light lter" style="margin-top:110px;">
							<a href="#" class="print pull-right hidden-xs" data-element="#factura">Imprimir</a>
						</div>
					<?php
					}
					?>
				</div>


			</div>
		</form>
	</div>

<?php
	dai();
}

if (validateHttp('action') == 'paymentForm') {
	if (!validateHttp('id')) {
		dai('false');
	}

	$id 		= validateBool('id');

	$result 	= ncmExecute("SELECT * FROM transaction WHERE transactionId = ? LIMIT 1", [dec($id)]);

	$deuda 		= $result['transactionTotal'];
	$payments 	= ncmExecute('SELECT * FROM transaction WHERE transactionParentId = ? AND companyId = ?', [$result['transactionId'], COMPANY_ID], false, true, true);

	if (validity($payments, 'array')) {
		$totalPaid = 0;
		foreach ($payments as $key => $paymnt) {
			$totalPaid += $paymnt['transactionTotal'];
		}
		$deuda = $result['transactionTotal'] - $totalPaid;
	}

?>

	<div class="col-xs-12 no-padder bg-white r-24x clear">
		<form action="/a_report_purchases?action=addPayment" method="POST" id="addPaymentForm" name="addPaymentForm">
			<div class="col-xs-12 text-center m-t">
				<div class="">Deuda Total</div>
				<div class="font-bold h2"><?= CURRENCY . formatCurrentNumber($deuda) ?></div>
			</div>

			<div class="col-xs-12 m-t m-b">
				<label class="text-u-c font-bold">Monto a abonar</label>
				<input type="text" class="maskCurrency form-control input-lg" name="payAmount" value="<?= formatCurrentNumber($deuda) ?>" id="payAmountField">

				<!--<label class="font-bold m-t text-u-c">Método de Pago</label>
	            <?php $pM = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = \'paymentMethod\' AND ' . $SQLcompanyId . ' ORDER BY taxonomyName ASC', [], false, true); ?>
	            <select id="paymentMethod" name="paymentMethod" tabindex="1" data-placeholder="Seleccione" class="form-control search" autocomplete="off">
	               <option value="cash">Efectivo</option>
	               <option value="creditcard">T. Crédito</option>
	               <option value="debitcard">T. Débito</option>
	               <option value="check">Cheque</option>
	              <?php
					if ($pM) {
						while (!$pM->EOF) {
							$pMId = enc($pM->fields['taxonomyId']);
					?>
	                    <option value="<?= $pMId; ?>">
	                      <?= $pM->fields['taxonomyName']; ?>
	                    </option>
	              <?php
							$pM->MoveNext();
						}
						$pM->Close();
					}

					?>
	            </select>-->
			</div>



			<?php
			if (isInvoiceEditable()) {
			?>

				<div class="col-xs-12 wrapper bg-light lter text-center">
					<button class="btn btn-info btn-lg btn-rounded text-u-c font-bold">Pagar</button>
					<input type="hidden" name="id" value="<?= $id; ?>">
					<input type="hidden" name="debt" value="<?= $deuda; ?>">
				</div>

			<?php
			}
			?>
		</form>
	</div>

<?php
	dai();
}

if (validateHttp('action') == 'download-report') {
	ini_set('memory_limit', '256M');
	/*
	0 = Venta al contado  	    
	1 = Compra al contado 	    
	2 = Guardada  	    
	3 = Venta a crédito 	    
	4 = Compra a crédito 	    
	5 = Pago de ventas a crédito 	    
	6 = Devolución 	    
	7 = Venta anulada 	    
	8 = Venta recursiva
	*/
	$customer = '';
	if (validateHttp('cusId')) {
		$cid = dec(validateHttp('cusId'));
		$customer = ' AND customerId = ' . $cid;
	}

	$saleDay 	= ncmExecute("	SELECT * 
								FROM transaction 
								WHERE transactionType IN(0,3)
								AND transactionDate 
								BETWEEN ? 
								AND ? 								
								" . $roc . $customer . "
								ORDER BY transactionDate 
								DESC", [$startDate, $endDate], true, true);

	$var 		= [];
	$array 		= [];

	$isCreditSale 		= false;
	$No 				= validateResultFromDB($saleDay, true);

	if ($saleDay) {

		$excellRow[]  = [
			'ID',
			'# DOCUMENTO',
			'FECHA',
			'CLIENTE',
			'CELULAR',
			'USUARIO',
			'SUCURSAL',
			'CAJA',
			'M. DE PAGO',
			'NOTA',
			'ETIQUETAS',
			'TIPO',
			'DESCUENTO',
			'SUBTOTAL',
			TAX_NAME,
			'TOTAL'
		];

		//$allToPayTransactions 	= getAllToPayTransactions();
		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		//$payment 				= getAllTransactionPayments();
		$cachedContact 			= [];
		$cachedUser 			= [];

		while (!$saleDay->EOF) {
			$fields 				= $saleDay->fields;
			$tTotal 				= ($fields['transactionTotal'] <= 0) ? 0 : $fields['transactionTotal'];

			if (!$cachedContact[$fields['customerId']]) {
				$customer 				= getContactData($fields['customerId'], 'uid', true);
				$cachedContact[$fields['customerId']] = $customer;
			} else {
				$customer = $cachedContact[$fields['customerId']];
			}

			$custName 				= $customer['name'];
			$user 					= getContactData($fields['userId'], false, true);
			$paymentType 			= json_decode($fields['transactionPaymentType'], true);
			$pMethods 				= '';

			if (validity($paymentType)) {
				foreach ($paymentType as $value) {
					$pMethods .= $value['name'] . ': ' . formatCurrentNumber($value['price']) . ' | ';
				}
			}

			$excellRow[]  = [
				enc($fields['transactionId']),
				$fields['invoicePrefix'] . $fields['invoiceNo'],
				$fields['transactionDate'],
				($customer == '0') ? '' : $custName,
				$customer['phone'],
				$user['name'],
				$getAllOutlets[$fields['outletId']]['name'],
				$getAllRegisters[$fields['registerId']]['name'],
				$pMethods,
				$fields['transactionNote'],
				printOutTags(json_decode($fields['tags']), '', true),
				($fields['transactionType'] == '3') ? 'Credito' : 'Contado',
				formatCurrentNumber($fields['transactionDiscount']),
				formatCurrentNumber($tTotal),
				formatCurrentNumber($fields['transactionTax']),
				formatCurrentNumber($tTotal - $fields['transactionDiscount'])
			];

			$netTotal = $tTotal - $fields['transactionDiscount'];

			if (!validateHttp('noItems')) {

				//Products
				$excellRow[] = [
					'CANTIDAD',
					'ARTICULO',
					'PRECIO',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					''
				];

				$arr 		= json_decode($fields['transactionDetails'], true);

				foreach ($arr as $value) {
					$excellRow[] = [
						$value['count'],
						$value['name'],
						$value['price'] * $value['count'],
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						'',
						''
					];
				}
				//Products

				if ($fields['transactionType'] == '3') {
					$excellRow[] = ['FECHA DE COBRO', 'USUARIO', 'METODOS DE PAGO', 'MONTO COBRADO', '', '', '', '', '', '', '', '', '', '', ''];

					$pay = $payment[$fields['transactionId']];

					if ($pay) {
						$totalPaid = 0;

						foreach ($pay as $key => $paymnt) {


							if (!$cachedContact[$fields['customerId']]) {
								$userIs 		= getContactData($paymnt['userid'], false, true);
								$cachedUser[$paymnt['userid']] = $userIs;
							} else {
								$userIs = $cachedUser[$paymnt['userid']];
							}

							$userNameIs 	= $userIs['name'];
							$totalPaid 		+= $paymnt['total'];
							$pMethods  		= '';
							$paymentType 	= json_decode($paymnt['methods'], true);

							if (validity($paymentType)) {
								foreach ($paymentType as $value) {
									$pMethods .= $value['name'] . ': ' . formatCurrentNumber($value['price']) . ' | ';
								}
							}

							$excellRow[] = [
								$paymnt['date'],
								$userNameIs,
								$pMethods,
								formatCurrentNumber($paymnt['total'])
							];
						}

						$excellRow[] = [
							'TOTAL COBRADO',
							formatCurrentNumber($totalPaid),
							'SALDO POR COBRAR',
							formatCurrentNumber($netTotal - $totalPaid), '', '', '', '', '', '', '', '', '', '', ''
						];
					} else {
						$excellRow[] = ['SIN PAGOS AUN', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''];
					}
				}

				$excellRow[] = [
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					''
				];
			}

			$saleDay->MoveNext();
		}

		if (!$_GET['test']) {
			generateXLSfromArray($excellRow, 'full_sales_reports_' . date("d-m-Y"));
		} else {
			echo '<pre>';
			print_r($excellRow);
			echo '</pre>';
		}

		$saleDay->Close();
	}

	dai();
}

if (validateHttp('action') == 'rg90') {
	ini_set('memory_limit', '256M');
	/*
	0 = Venta al contado  	    
	1 = Compra al contado 	    
	2 = Guardada  	    
	3 = Venta a crédito 	    
	4 = Compra a crédito 	    
	5 = Pago de ventas a crédito 	    
	6 = Devolución 	    
	7 = Venta anulada 	    
	8 = Venta recursiva
	*/

	$saleDay 	= ncmExecute("	SELECT * 
								FROM transaction 
								WHERE transactionType IN(0,3)
								AND transactionDate 
								BETWEEN ? 
								AND ? 								
								" . $roc . "
								ORDER BY invoiceNo 
								DESC", [$startDate, $endDate], true, true);
																									
	$var 			= [];
	$array 			= [];
	$excellRow  	= [];

	$isCreditSale 	= false;
	$No 			= validateResultFromDB($saleDay, true);

	if ($saleDay) {

		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		$cachedContact 			= [];
		$cachedUser 			= [];

		$excellRow[]			= 	[
			'CODIGO TIPO DE REGISTRO',
			'CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR',
			'NUMERO DE IDENTIFICACION DEL COMPRADOR',
			'NOMBRE O RAZON SOCIAL DEL COMPRADOR',
			'CODIGO TIPO DE COMPROBANTE',
			'FECHA DE EMISION DEL COMPROBANTE',
			'NUMERO DE TIMBRADO',
			'NUMERO DEL COMPROBANTE',
			'MONTO GRAVADO AL 10%',
			'MONTO GRAVADO AL 5%',
			'MONTO NO GRAVADO O EXENTO',
			'MONTO TOTAL DEL COMPROBANTE',
			'CODIGO CONDICION DE VENTA',
			'OPERACION EN MONEDA EXTRANJERA',
			'IMPUTA AL IVA',
			'IMPUTA AL IRE',
			'IMPUTA AL IRP-RSP',
			'NO IMPUTA',
			'NUMERO DEL COMPROBANTE DE VENTA ASOCIADO',
			'TIMBRADO DEL COMPROBANTE DE VENTA ASOCIADO'
		];

		while (!$saleDay->EOF) {
			$fields 				= $saleDay->fields;

			$tagsAr 				= json_decode($fields['tags'], true);
			$isInternal 			= isInternalSale($tagsAr, true);
			if ($isInternal) {
				$saleDay->MoveNext();
				continue;
			}

			$tTotal 				= ($fields['transactionTotal'] <= 0) ? 0 : $fields['transactionTotal']; //- $fields['transactionDiscount'];
			$grav5 					= 0;
			$grav10					= 0;
			$exentas				= 0;
			$totalTaxes 			= getTaxTotalsBySaleItems(json_decode($fields['transactionDetails'], true));

			if (!$cachedContact[$fields['customerId']]) {
				$customer 				= getContactData($fields['customerId'], 'uid', false);
				$cachedContact[$fields['customerId']] = $customer;
			} else {
				$customer = $cachedContact[$fields['customerId']];
			}

			if (isset($_GET['test'])) {
				echo json_encode($totalTaxes) . '<br>';
			}

			$indx = 0;
			foreach ($getAllTaxNames as $tnK => $tnV) {

				if ($_fullSettings['taxPy'] && $tnV['name'] == '10' && $tTotal > 0) {
					$fullTax 	= $tTotal / 21;
					$fullNoTax 	= $tTotal - $fullTax;
					$exentValue = $fullNoTax / 2;

					if ($tnV['name'] == '10') {
						$grav10 	+= $fullTax;
					}

					//if($tnV['name'] == '0'){
					$exentas 	+= $exentValue;
					//}		
				} else if (!$_fullSettings['taxPy']) {

					if (isset($_GET['test'])) {
						echo $indx . ': ' . $tnV['name'] . ' = ' . $totalTaxes['total'][$tnV['name']] . '<br>';
					}

					if ($tnV['name'] == '10') {
						$grav10 	+= $totalTaxes['total']['10'];
					} else {
						$grav10 	+= 0;
					}

					if ($tnV['name'] == '5') {
						$grav5 		+= $totalTaxes['total']['5'];
					} else {
						$grav5 		+= 0;
					}

					if ($tnV['name'] == '0') {
						$exentas 	+= $totalTaxes['total']['0'];
					} else {
						$exentas 	+= 0;
					}
				}

				$indx++;
			}

			$transType = 109;

			if ($fields['transactionType'] == '6') {
				$transType = 110;
			}


			//CI = 12
			//RUC = 11
			//Sin nombre  = 15
			$TINType 				= 11;
			$customer['ruc'] 		= trim(is_null($customer['ruc']) ? "" : $customer['ruc']);

			if (!$customer['ruc'] || strlen($customer['ruc']) < 5) {
				$customer['ruc'] 	= 'X';
				$customer['name'] 	= 'SIN NOMBRE';
				$TINType 			= 15;
			} else {
				if (strpos($customer['ruc'], '-') !== false) {
					$TINType 			= 11;
					$ruc 				= explode('-', $customer['ruc']);
					$customer['ruc'] 	= $ruc[0];
				} else {
					$TINType 			= 12;
				}
			}

			// Limpia la variable $customer['name'] eliminando comillas
			$customerName = html_entity_decode($customer['name'] ?? "");
			$excellRow[]  = [
				'1', 				//VENTA
				$TINType, 			//RUC, CI
				$customer['ruc'], 	//customer TIN
				$customerName, 	//customer name
				$transType, 		//FACTURA o Nota de crédito
				date('d/m/Y', strtotime($fields['transactionDate'])), 		//FECHA
				$getAllRegisters[$fields['registerId']]['invoiceAuthNo'], 	//NRO TIMBRADO
				$getAllRegisters[$fields['registerId']]['invoicePrefix'] . leadingZeros($fields['invoiceNo'], 7), //NRO FACTURA
				formatCurrentNumber($grav10), 	//GRAVADO 10%
				formatCurrentNumber($grav5), 	//GRAVADO 5%
				formatCurrentNumber($exentas), 	//EXENTAS
				formatCurrentNumber($tTotal - $fields['transactionDiscount']), 	//TOTAL FACTURA
				($fields['transactionType'] == '3') ? '2' : '1', 				//CONTADO = 1, CREDITO = 2
				'N', //MONEDA EXTRANJERA
				'S', //IMPUTA IVA
				'N', //IMPUTA IRE
				'N', //IMPUTA AL IRP-RSP
				'N', //no imputa
				'', //nro de nota de credito asociada
				'' //timbrado de nota de credito
			];

			$saleDay->MoveNext();
		}

		if (!$_GET['test']) {
			generateXLSfromArray($excellRow, 'RG90-' . date("d-m-Y"));
		} else {
			echo '<pre>';
			print_r($excellRow);
			echo '</pre>';
		}

		$saleDay->Close();
	}

	dai();
}
if (validateHttp('action') == 'libro-ventas') {
	ini_set('memory_limit', '256M');
	/*
	0 = Venta al contado  	    
	1 = Compra al contado 	    
	2 = Guardada  	    
	3 = Venta a crédito 	    
	4 = Compra a crédito 	    
	5 = Pago de ventas a crédito 	    
	6 = Devolución 	    
	7 = Venta anulada 	    
	8 = Venta recursiva
	*/

	$saleDay 	= ncmExecute("	SELECT * 
								FROM transaction 
								WHERE transactionType IN(0,3)
								AND transactionDate 
								BETWEEN ? 
								AND ? 								
								" . $roc . "
								ORDER BY invoiceNo 
								DESC", [$startDate, $endDate], true, true);

	$var 			= [];
	$array 			= [];
	$excellRow  	= [];

	$isCreditSale 	= false;
	$No 			= validateResultFromDB($saleDay, true);

	if ($saleDay) {

		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		$cachedContact 			= [];
		$cachedUser 			= [];

		$excellRow[]			= 	[
			// 'CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR',
			// 'CODIGO TIPO DE COMPROBANTE',
			'FECHA DE EMISION',
			'FACT.',
			'NOMBRE O RAZON SOCIAL',
			'R.U.C N.',
			'TIMBRADO',
			'GRAV. 10%',
			'IVA 10%',
			'GRAV. 5%',
			'IVA 5%',
			'EXENTA',
			'TOTAL',
			'10%',
			'5%',
			'EXENTO',
			// 'CONDICION DE VENTA',
		];

		while (!$saleDay->EOF) {
			$fields 				= $saleDay->fields;

			$tagsAr 				= json_decode($fields['tags'], true);
			$isInternal 			= isInternalSale($tagsAr, true);
			if ($isInternal) {
				$saleDay->MoveNext();
				continue;
			}

			$tTotal 				= ($fields['transactionTotal'] <= 0) ? 0 : $fields['transactionTotal']; //- $fields['transactionDiscount'];
			$grav5 					= 0;
			$grav10					= 0;
			$exentas				= 0;
			$tax10					= 0;
			$tax5					= 0;
			$tax0					= 0;
			$total10				= 0;
			$total5					= 0;
			$total0					= 0;
			$totalTaxes 			= getTaxTotalsBySaleItems(json_decode($fields['transactionDetails'], true));

			if (!$cachedContact[$fields['customerId']]) {
				$customer 				= getContactData($fields['customerId'], 'uid', true);
				$cachedContact[$fields['customerId']] = $customer;
			} else {
				$customer = $cachedContact[$fields['customerId']];
			}

			if (isset($_GET['test'])) {
				echo json_encode($totalTaxes) . '<br>';
			}

			$indx = 0;
			foreach ($getAllTaxNames as $tnK => $tnV) {

				if ($_fullSettings['taxPy'] && $tnV['name'] == '10' && $tTotal > 0) {
					$fullTax 	= $tTotal / 21;
					$fullNoTax 	= $tTotal - $fullTax;
					$exentValue = $fullNoTax / 2;

					if ($tnV['name'] == '10') {
						$grav10 	+= $fullTax;
					}

					//if($tnV['name'] == '0'){
					$exentas 	+= $exentValue;
					//}		
				} else if (!$_fullSettings['taxPy']) {

					if (isset($_GET['test'])) {
						echo $indx . ': ' . $tnV['name'] . ' = ' . $totalTaxes['total'][$tnV['name']] . '<br>';
					}

					if ($tnV['name'] == '10') {
						$grav10 	+= $totalTaxes['grav']['10'];
						$tax10 += $totalTaxes['tax']['10'];
						$total10 += $totalTaxes['total']['10'];
					} else {
						$grav10 	+= 0;
						$tax10 += 0;
						$total10 += 0;
					}

					if ($tnV['name'] == '5') {
						$grav5 		+= $totalTaxes['tax']['5'];
						$tax5 		+= $totalTaxes['grav']['5'];
						$total5 += $totalTaxes['total']['5'];
					} else {
						$grav5 		+= 0;
						$tax5 += 0;
						$total5 += 0;
					}

					if ($tnV['name'] == '0') {
						$exentas 	+= $totalTaxes['grav']['0'];
						$tax0 		+= $totalTaxes['tax']['0'];
						$total0 += $totalTaxes['total']['0'];
					} else {
						$exentas 	+= 0;
						$tax0 		+= 0;
						$total0 += 0;
					}
				}

				$indx++;
			}

			$transType = 109;

			if ($fields['transactionType'] == '6') {
				$transType = 110;
			}


			//CI = 12
			//RUC = 11
			//Sin nombre  = 15
			$TINType 				= 11;
			$customer['ruc'] 		= trim(is_null($customer['ruc']) ? "" : $customer['ruc']);

			if (!$customer['ruc'] || strlen($customer['ruc']) < 5) {
				$customer['ruc'] 	= 'X';
				$customer['name'] 	= 'SIN NOMBRE';
				$TINType 			= 15;
			} else {
				if (strpos($customer['ruc'], '-') !== false) {
					// $TINType 			= 11;
					// $ruc 				= explode('-', $customer['ruc']);
					// $customer['ruc'] 	= $ruc[0];
				} else {
					$TINType 			= 12;
				}
			}

			$excellRow[]  = [
				date('d/m/Y', strtotime($fields['transactionDate'])), 		//FECHA
				// $TINType, 			//RUC, CI
				$getAllRegisters[$fields['registerId']]['invoicePrefix'] . leadingZeros($fields['invoiceNo'], 7), //NRO FACTURA
				$customer['name'], 	//customer name
				$customer['ruc'], 	//customer TIN
				// $transType, 		//FACTURA o Nota de crédito
				$getAllRegisters[$fields['registerId']]['invoiceAuthNo'], 	//NRO TIMBRADO
				round($grav10), 	//GRAVADO 10%
				round($tax10),
				round($grav5), 	//GRAVADO 5%
				round($tax5),
				round($exentas), 	//EXENTAS
				($tTotal - $fields['transactionDiscount']), 	//TOTAL FACTURA
				round($total10),
				round($total5),
				round($total0)
				// ($fields['transactionType'] == '3') ? 'CREDITO' : 'CONTADO', 				//CONTADO = 1, CREDITO = 2
			];

			$saleDay->MoveNext();
		}

		if (!$_GET['test']) {
			generateXLSfromArray($excellRow, 'VENTAS-' . date("d-m-Y"));
		} else {
			echo '<pre>';
			print_r($excellRow);
			echo '</pre>';
		}

		$saleDay->Close();
	}

	dai();
}
// if(validateHttp('action') == 'libro-compras'){
// 	ini_set('memory_limit', '256M');
// 	/*
// 	0 = Venta al contado  	    
// 	1 = Compra al contado 	    
// 	2 = Guardada  	    
// 	3 = Venta a crédito 	    
// 	4 = Compra a crédito 	    
// 	5 = Pago de ventas a crédito 	    
// 	6 = Devolución 	    
// 	7 = Venta anulada 	    
// 	8 = Venta recursiva
// 	*/

// 	$saleDay 	= ncmExecute("	SELECT * 
// 								FROM transaction 
// 								WHERE transactionType IN(1,4)
// 								AND transactionDate 
// 								BETWEEN ? 
// 								AND ? 								
// 								" . $roc . "
// 								ORDER BY invoiceNo 
// 								DESC", [$startDate,$endDate],true,true);

// 	$taxItems = ncmExecute(" SELECT * FROM toTaxObj where transactionId in (
// 		SELECT transactionId 
// 		FROM transaction 
// 		WHERE transactionType IN(1,4)
// 		AND transactionDate 
// 		BETWEEN ? 
// 		AND ? 								
// 		" . $roc . "
// 	)
// 	",[$startDate,$endDate],true,true);
// 	$taxItemsArray = [];
// 	foreach($taxItems->_array as $taxItem){
// 		$taxItemsArray[$taxItem['transactionId']] = $taxItem;
// 	}
// 	$var 			= [];
// 	$array 			= [];
// 	$excellRow  	= [];

// 	$isCreditSale 	= false;
// 	$No 			= validateResultFromDB($saleDay,true);

// 	if($saleDay){

// 		$getAllOutlets 			= getAllOutlets();
// 		$getAllRegisters 		= getAllRegisters();
// 		$cachedContact 			= [];
// 		$cachedUser 			= [];

// 		$excellRow[]			= 	[
// 										// 'CODIGO TIPO DE IDENTIFICACION DEL COMPRADOR',
// 										// 'CODIGO TIPO DE COMPROBANTE',
// 										'FECHA DE EMISION',
// 										'NUMERO DE COMPROBANTE',
// 										'NOMBRE O RAZON SOCIAL',
// 										'R.U.C N.',
// 										'TIMBRADO',
// 										'GRAV. 10%',
// 										'IVA 10%',
// 										'GRAV. 5%',
// 										'IVA 5%',
// 										'EXENTA',
// 										'TOTAL',
// 										'10%',
// 										'5%',
// 										'EXENTO',
// 										// 'CONDICION DE VENTA',
// 									];

// 		while (!$saleDay->EOF) {
// 			$fields 				= $saleDay->fields;

// 			$tagsAr 				= json_decode(is_null($fields['tags']) ? "" : $fields['tags'],true);
// 			$isInternal 			= isInternalSale($tagsAr, true);
// 			if($isInternal){
// 				$saleDay->MoveNext();
// 				continue;
// 			}

// 			$tTotal 				= ($fields['transactionTotal'] <= 0) ? 0 : $fields['transactionTotal']; //- $fields['transactionDiscount'];
// 			$grav5 					= 0;
// 			$grav10					= 0;
// 			$exentas				= 0;
// 			$tax10					= 0;
// 			$tax5					= 0;
// 			$tax0					= 0;
// 			$total10				= 0;
// 			$total5					= 0;
// 			$total0					= 0;

// 			$taxCurrentItems = $taxItemsArray[$fields['transactionId']];
// 			$taxItem = json_decode($taxCurrentItems['toTaxObjText'], true);
// 			foreach($taxItem as $value){
// 				if($value['name'] == "10"){
// 					$grav10 	+= $tTotal - $value['val'];
// 					$tax10 += $value['val'];
// 					$total10 += $tTotal;
// 				}else{
// 					$grav10 	+= 0;
// 					$tax10 += 0;
// 					$total10 += 0;
// 				}

// 				if($value['name'] == "5"){
// 					$grav5 	+= $tTotal - $value['val'];
// 					$tax5 += $value['val'];
// 					$total5 += $tTotal;
// 				}else{
// 					$grav5 		+= 0;
// 					$tax5 += 0;
// 					$total5 += 0;
// 				}

// 				if($value['name'] == "0"){
// 					$exentas 	+= $tTotal - $value['val'];
// 					$tax0 += $value['val'];
// 					$total0 += $tTotal;
// 				}else{
// 					$exentas 	+= 0;
// 					$tax0 		+= 0;
// 					$total0 += 0;
// 				}
// 			}

// 			if(!$cachedContact[$fields['customerId']]){
// 				$customer 				= getContactData($fields['customerId'],'uid',true);
// 				$cachedContact[$fields['customerId']] = $customer;
// 			}else{
// 				$customer = $cachedContact[$fields['customerId']];
// 			}

// 			if(isset($_GET['test'])){
// 				echo json_encode($totalTaxes) . '<br>';
// 			}

// 			$indx = 0;


// 			$transType = 109;

// 			if($fields['transactionType'] == '6'){
// 				$transType = 110;
// 			}


// 			//CI = 12
// 			//RUC = 11
// 			//Sin nombre  = 15
// 			$TINType 				= 11;
// 			$customer['ruc'] 		= trim(is_null($customer['ruc']) ? "" : $customer['ruc']);

// 			if(!$customer['ruc'] || strlen($customer['ruc']) < 5){
// 				$customer['ruc'] 	= 'X';
// 				$customer['name'] 	= 'SIN NOMBRE';
// 				$TINType 			= 15;
// 			}else{
// 				if (strpos($customer['ruc'], '-') !== false){
// 					// $TINType 			= 11;
// 					// $ruc 				= explode('-', $customer['ruc']);
// 					// $customer['ruc'] 	= $ruc[0];
// 				}else{
// 					$TINType 			= 12;
// 				}
// 			}

// 			$excellRow[]  = [
// 	                            date('d/m/Y', strtotime($fields['transactionDate'])), 		//FECHA
// 	                            // $TINType, 			//RUC, CI
// 	                            $getAllRegisters[$fields['registerId']]['invoicePrefix'] . leadingZeros($fields['invoiceNo'],7), //NRO FACTURA
// 	                            $customer['name'], 	//customer name
// 	                            $customer['ruc'], 	//customer TIN
// 	                            // $transType, 		//FACTURA o Nota de crédito
// 	                            $getAllRegisters[$fields['registerId']]['invoiceAuthNo'], 	//NRO TIMBRADO
// 	                            round($grav10), 	//GRAVADO 10%
// 								round($tax10),
// 	                            round($grav5), 	//GRAVADO 5%
// 								round($tax5),
// 	                            round($exentas), 	//EXENTAS
// 	                            ($tTotal - $fields['transactionDiscount']), 	//TOTAL FACTURA
// 								round($total10),
// 								round($total5),
// 								round($total0)
// 	                            // ($fields['transactionType'] == '3') ? 'CREDITO' : 'CONTADO', 				//CONTADO = 1, CREDITO = 2
// 	                        ];

// 			$saleDay->MoveNext();
// 		}

// 		if(!$_GET['test']){
// 			generateXLSfromArray($excellRow,'COMPRAS-' . date("d-m-Y"));
// 		}else{
// 			echo '<pre>';
// 			print_r($excellRow);
// 			echo '</pre>';
// 		}

// 		$saleDay->Close();
// 	}

// 	dai();
// }

if (validateHttp('action') == 'tusFacturas') {

	if (validateHttp('url') && validateHttp('id')) {
		$trsId = dec(validateHttp('id'));
		$record['taxonomyName'] = validateHttp('url');
		$record['taxonomyType'] = 'tusFacturas';
		$record['sourceId'] 	= $trsId;
		$record['companyId'] 	= COMPANY_ID;
		$record['outletId'] 	= OUTLET_ID;

		$insert 	= $db->AutoExecute('taxonomy', $record, 'INSERT');

		//obtengo el tag
		$tag = ncmExecute('SELECT tags FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$trsId, COMPANY_ID]);

		if ($tag) {
			$record 			= [];
			$tags 				= json_decode($tag['tags'], true);
			$tags 				= validity($tags, 'array') ? $tags : [];

			if (!in_array(172176, $tags)) {
				$tags[]  			= 172176;

				$tagsJ 				= json_encode($tags);
				$record['tags'] 	= $tagsJ;
				$update 			= $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ' . $trsId);
			}
		}

		if ($update !== false) {
			echo 'true';
		}
	}

	dai();
}
?>

<?= menuReports('', false); ?>

<?php
if (validateHttp('ci')) {
	$cId 	= $db->Prepare(dec(validateHttp('ci')));
	$cData 	= getContactData($cId, 'uid');
	$name 	= getCustomerName($cData);

	$reportsTitle = [
		'title' 		=> '<div class="text-md text-right font-default">Historial de</div> ' . $name,
		'maxDays' 		=> $MAX_DAYS_RANGE,
		'hideChart' 	=> true
	];
} else {

	$reportsTitle = [
		'title' 		=> '<div class="text-md text-right font-default">Pagos y</div> Transacciones',
		'maxDays' 		=> $MAX_DAYS_RANGE,
		'tutorial' 		=> '/panel-de-control/reportes/reporte-de-ventas-1/transacciones',
		'hideChart' 	=> true
	];
}

echo reportsDayAndTitle($reportsTitle);
?>

<div class="col-xs-12 no-padder m-b-lg push-chat-down">
	<section class="col-xs-12 no-padder">
		<ul class="nav nav-tabs padder hidden-print wrap-l-md">
			<li class="active">
				<a href="#tab1" data-toggle="tab">
					<span class="hidden-xs">Transacciones</span>
					<span class="material-icons visible-xs">receipt_long</span>
				</a>
			</li>
			<li class="" id="cobrosTab">
				<a href="#tab2" data-toggle="tab">
					<span class="hidden-xs">Pagos recibidos</span>
					<span class="material-icons visible-xs">payment</span>
				</a>
			</li>
			<li class="" id="quotesTab">
				<a href="#tab3" data-toggle="tab">
					<span class="hidden-xs">Cotizaciones</span>
					<span class="material-icons visible-xs">request_quote</span>
				</a>
			</li>
			<?php if (!empty($__modules["electronicInvoice"]) && ($__modules["electronicInvoice"]["status"] != 'false' && $__modules["electronicInvoice"]["status"] != 0)) : ?>
				<li class="" id="feTab">
					<a href="#tab4" data-toggle="tab">
						<span class="hidden-xs">Factura electrónica</span>
						<span class="material-icons visible-xs">receipt_long</span>
					</a>
				</li>
			<?php endif; ?>
		</ul>

		<section class="panel r-24x">
			<div class="panel-body table-responsive">

				<div class="tab-content m-b-lg">
					<div class="tab-pane overflow-auto active" id="tab1">
						<div id="detailTable">
							<table class="table m-t table1 table-hover hover" id="tableTransactions">
								<?= placeHolderLoader('table') ?>
							</table>
						</div>
					</div>

					<div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab2">
						<div id="cobrosTable">
							<table class="table table2 table-hover hover col-xs-12 no-padder" id="tablePayments">
								<?= placeHolderLoader('table') ?>
							</table>
						</div>
					</div>

					<div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab3">
						<div id="quotesTable">
							<table class="table table3 table-hover hover col-xs-12 no-padder" id="tableQuotes">
								<?= placeHolderLoader('table') ?>
							</table>
						</div>
					</div>

					<div class="tab-pane overflow-auto col-xs-12 no-padder <?= (COUNTRY != 'PY' ? 'hidden' : '') ?>" id="tab4">
						<div id="feTable">
							<table class="table table-hover hover col-xs-12 no-padder" id="tableFE">
								<?= placeHolderLoader('table') ?>
							</table>
						</div>
					</div>
				</div>

			</div>
			<?= footerPrint(); ?>
		</section>
	</section>
</div>

<script>
	var baseUrl = '<?= $baseUrl ?>',
		startDate = '<?= $startDate ?>',
		endDate = '<?= $endDate ?>',
		ci = '<?= validateHttp('ci') ?>',
		currency = '<?= CURRENCY ?>',
		offset = <?= $offsetDetail ?>,
		limit = <?= $limitDetail ?>,
		noMoreBtn = false,
		hiddenSearch = '',
		tinName = '<?= TIN_NAME ?>',
		taxName = '<?= TAX_NAME ?>',
		country = '<?= COUNTRY ?>',
		eCompanyID = '<?= enc(COMPANY_ID) ?>',
		eOutletID = '<?= enc(OUTLET_ID) ?>',
		libroVentas = '',
		rg90 = '',
		mcal = '';
	isMcal = '<?= ($__modules['mcal']['status'] && $__modules['mcal']['contract']) ?>';

	if (ci) {
		noMoreBtn = true;
		hiddenSearch = 'hidden';
	}

	$(document).ready(() => {

		dateRangePickerForReports(startDate, endDate, false, true);

		if (country == 'PY') {
			rg90 = '<a class="btn r-3x b b-light font-bold" id="rg90" href="#" data-toggle="tooltip" data-placement="bottom" title="RG90">RG90</a>';
			libroVentas = '<a class="btn r-3x b b-light font-bold" id="libro-ventas" href="#" data-toggle="tooltip" data-placement="bottom" title="Libro Ventas">Libro Ventas</a>';
			if (isMcal) {
				mcal = '<a class="btn r-3x b b-light font-bold" id="mcal" href="#" data-toggle="tooltip" data-placement="right" title="Enviar ventas al Mariscal / Mariano Shopping"><i class="material-icons">file_upload</i></a>';
			}
		}

		var theClickRow = function(event, tis) {
			var ro = iftn(tis.data('ro'), '', '&ro=1');
			var load = baseUrl + '?action=edit&id=' + tis.data('id') + ro;

			loadForm(load, '#modalXLarge .modal-content', () => {
				$('#modalXLarge').modal('show');
			});
		};

		var rawUrl = baseUrl + "?action=detailTable";
		var loadUrl = rawUrl + "&cusId=" + ci;

		var xhr = ncmHelpers.load({
			url: loadUrl,
			httpType: 'GET',
			hideLoader: true,
			type: 'json',
			success: (result) => {

				window.info2 = {
					"container": "#detailTable",
					"url": loadUrl,
					"rawUrl": rawUrl,
					"table": ".table1",
					"iniData": result.table,
					"sort": 2,
					"footerSumCol": [15, 16, 17, 18, 19],
					"currency": currency,
					"decimal": decimal,
					"thousand": thousandSeparator,
					"offset": offset,
					"limit": limit,
					"nolimit": true,
					"noMoreBtn": noMoreBtn,
					"tableName": 'tableTransactions',
					"fileTitle": 'Transacciones',
					"ncmTools": {
						left: mcal + rg90 + libroVentas + '<a class="btn r-3x b b-light font-bold" id="reportDownload" href="#" data-toggle="tooltip" data-placement="bottom" title="Reporte detallado en CSV"><i class="material-icons">file_download</i></a>',
						right: '<input type="text" class="form-control rounded ' + hiddenSearch + '" placeholder="Buscar por Nombre, ' + tinName + ' o # Doc." id="detailTableSearch" data-url="' + rawUrl + '&qry=">'
					},
					"colsFilter": {
						name: 'reportTransactions11',
						menu: [{
								"index": 0,
								"name": 'ID',
								"visible": false
							},
							{
								"index": 1,
								"name": 'Nro. Autorización',
								"visible": false
							},
							{
								"index": 2,
								"name": 'Nro. Documento',
								"visible": false
							},
							{
								"index": 3,
								"name": 'Fecha',
								"visible": true
							},
							{
								"index": 4,
								"name": 'Hora',
								"visible": false
							},
							{
								"index": 5,
								"name": 'Vencimiento',
								"visible": false
							},
							{
								"index": 6,
								"name": 'Cliente',
								"visible": true
							},
							{
								"index": 7,
								"name": tinName,
								"visible": false
							},
							{
								"index": 8,
								"name": 'Usuario',
								"visible": false
							},
							{
								"index": 9,
								"name": 'Sucursal',
								"visible": false
							},
							{
								"index": 10,
								"name": 'Caja',
								"visible": false
							},
							{
								"index": 11,
								"name": 'M. de Pagos',
								"visible": false
							},
							{
								"index": 12,
								"name": 'Nota',
								"visible": false
							},
							{
								"index": 13,
								"name": 'Etiquetas',
								"visible": false
							},
							{
								"index": 14,
								"name": 'Tipo',
								"visible": true
							},
							{
								"index": 15,
								"name": 'Descuento',
								"visible": false
							},
							{
								"index": 16,
								"name": 'Subtotal',
								"visible": false
							},
							{
								"index": 17,
								"name": taxName,
								"visible": false
							},
							{
								"index": 18,
								"name": 'Total Gravado',
								"visible": false
							},
							{
								"index": 19,
								"name": 'Total',
								"visible": true
							}
						]
					},
					"clickCB": function(event, tis) {
						return theClickRow(event, tis);
					}
				};

				ncmDataTables(window.info2, function(oTable, _scope) {
					loadTheTable(window.info2, oTable, _scope);
				});

				onClickWrap('.cancelItemView', function(event, tis) {
					$('#modalXLarge').modal('hide');
				});

				onClickWrap('#reportDownloadGeneral', function(event, tis) {
					var url = baseUrl + '?action=generalTable&download-report=true&from=' + startDate + '&to=' + endDate + '&cusId=' + ci;
					window.open(url);
				});

				onClickWrap('#rg90', function(event, tis) {
					var url = baseUrl + '?action=rg90&from=' + startDate + '&to=' + endDate;
					console.log(url);
					window.open(url);
				});
				onClickWrap('#libro-ventas', function(event, tis) {
					var url = baseUrl + '?action=libro-ventas&from=' + startDate + '&to=' + endDate;
					console.log(url);
					window.open(url);
				});

				onClickWrap('#mcal', function(event, tis) {
					var url = '/thirdparty/mcal/mcalSendSales?s=' + btoa(eCompanyID + ',' + eOutletID + ',' + endDate);
					$.get(url, (result) => {
						if (ncmHelpers.validInObj(result, 'mensaje')) {
							message('Enviado', 'success');
						}
					});
				});

				onClickWrap('.switchToInput', function(event, tis) {
					var switchit = tis.data('switch');
					$(switchit).show().removeClass('hidden');
					tis.hide().addClass('hidden');
				});

				onClickWrap('.filterTypes', function(event, tis) {
					var filter = tis.data('filter');
					var $input = $('#detailTable .dataTables_filter input');
					autoFilterInputTable($input, filter);
				});

			}
		});

		window.xhrs.push(xhr);

		var loadTheTable = function(tableOps, oTable, _scope) {
			var filterDetails = "<?= validateBool('detail') ? $_GET['detail'] : 'false' ?>";

			if (filterDetails != 'false') {
				var e = jQuery.Event("keyup");
				e.which = 50; // # Some key code value
				$('#DataTables_Table_1_filter input').val(filterDetails).trigger(e);
			}

			onClickWrap('.subModal', function(event, tis) {

				var ro = iftn(tis.data('ro'), '', '&ro=1');
				var load = baseUrl + '?action=edit&id=' + tis.data('id') + ro;

				$('#modalXLarge').modal('hide').one('hidden.bs.modal', function() {
					loadForm(load, '#modalXLarge .modal-content', function() {
						$('#modalXLarge').modal('show');
					});
				});

			});

			<?php
			if ($_fullSettings['ignoreInternal']) {
			?>

				/*ncmDTHideRows(oTable.rows( '.internal' ).nodes().to$(),function(){
					oTable.draw();
				});*/

			<?php
			}
			?>

			onClickWrap('#reportDownload', function(event, tis) {
				var url = baseUrl + '?action=download-report&from=<?= $startDate ?>&to=<?= $endDate ?>';
				window.open(url);
			});

			onClickWrap('.deleteTransaction', function(event, tis) {
				var url = tis.attr('href');
				var payment = tis.data('payment');
				var $row = $('.editting');

				ncmDialogs.confirm('¿Desea eliminar la transacción?', 'Esta acción no se puede revertir.', 'warning', function(conf) {
					if (conf) {
						oTable.row($row).remove().draw();
						$('#modalXLarge').modal('hide');

						$.get(url, function(data) {
							if (data.success) {
								message('Eliminado', 'success');
								if (payment) {
									$.get(baseUrl + '?action=detailTable&part=1&singleRow=' + $row.data('id'), function(data) {
										oTable.row($row).remove();
										if (data) {
											oTable.row.add($(data));
										}
										oTable.draw();
									});
								}
							} else {
								message('No se pudo eliminar', 'danger');
							}
						});
					}
				});

			});

			onClickWrap('.voidTransaction', function(event, tis) {
				var url = tis.attr('href');
				var $row = $('.editting');

				ncmDialogs.confirm('Desea anular la transacción?', '', 'warning', function(conf) {
					if (conf) {
						oTable.row($row).remove().draw();
						$('#modalXLarge').modal('hide');

						$.get(url, function(data) {
							if (data == 'true') {
								message('Anulado', 'success');
								if (payment) {
									$.get(baseUrl + '?action=detailTable&part=1&singleRow=' + $row.data('id'), function(data) {

										oTable.row($row).remove();
										if (data) {
											oTable.row.add($(data));
										}
										oTable.draw();

									});
								}
							} else {
								message('No se pudo anular', 'danger');
							}
						});
					}
				});

			});

			onClickWrap('#sendToHolaFactura', function(event, tis) {
				tis.addClass('disabled').text('Enviando...');

				var formData = {
					'IdTipoImpuesto': $('select[name=IdTipoImpuesto]').val(),
					'IdTipoIVA': $('select[name=IdTipoIVA]').val(),
					'IdTipoComprobante': $('select[name=IdTipoComprobante]').val(),
					'IdConcepto': $('select[name=IdConcepto]').val(),
					'IdCondicionVenta': $('select[name=IdCondicionVenta]').val(),
					'IdTipoDocumento': $('select[name=IdTipoDocumento]').val(),
					'provincia': $('select[name=tfprovincia]').val(),
					'PuntoDeVenta': $('input[name=tfpointofsale]').val(),
					'NroFactura': $('input[name=tfinvoiceno]').val(),
					'Fecha': $('input[name=tfdate]').val()
				};

				$('#TFerrorbox').hide();

				var id = tis.data('id');
				var url = tis.data('url');
				//var tis = $('#holaFacturaForm');

				console.log('enviando a', url);
				$.ajax({
					type: 'POST',
					url: url, // the url where we want to POST
					data: formData, // our data object
					//dataType    : 'json'
				}).done(function(data) {
					console.log(data);
					var out = '';
					if (data.error == 'S') {
						$.each(data.errores, function(k, v) {
							out += '- ' + v + '<br>';
						});

						$('#TFerrorbox').html(out).show();
					} else {
						if (data.comprobante_pdf_url) {
							$('#TFerrorbox').html('<a href="' + data.comprobante_pdf_url + '" target="_blank">Facturada enviada</a>').show();
							$.get(baseUrl + '?action=tusFacturas&url=' + data.comprobante_pdf_url + '&id=' + id);
						} else {
							$('#TFerrorbox').html('No se pudo generar el PDF').show();
						}
						//
					}

					tis.removeClass('disabled').text('Enviar a tusfacturas');
				});
			});

			$('#modalXLarge').off('shown.bs.modal').on('shown.bs.modal', function() {
				var where = '.loadCustomerInput';
				var match = $(where).data('match');
				select2Ajax({
					element: '.selectCustomer',
					url: '/a_contacts?action=searchCustomerInputJson',
					type: 'contact'
				});
				select2Ajax({
					element: '.selectItem',
					url: '/a_items?action=searchItemInputJson',
					type: 'item',
					onChange: function(tis, data) {
						return false;
						var id = tis.data('id');
						var uid = tis.val();
						if (id) {
							var url = baseUrl + '?action=updateItem&id=' + id + '&uid=' + uid;
							$.get(url, function(result) {
								if (result) {
									message('Actualizado', 'success');
								} else {
									message('Error al actualizar', 'danger');
								}
							});
						}
					}
				});

				$('.datepicker').datetimepicker({
					format: 'YYYY-MM-DD HH:mm:ss',
					showClear: true,
					ignoreReadonly: true
				});

				select2Simple('.selectSimple', $('#modalXLarge'));
				select2Simple('.selectUser', $('#modalXLarge'), function(tis, data) {
					return false;
					var id = tis.data('id');
					var uid = tis.val();
					if (id) {
						var url = baseUrl + '?action=updateItemUser&id=' + id + '&uid=' + uid;
						$.get(url, function(result) {
							if (result) {
								message('Actualizado', 'success');
							} else {
								message('Error al actualizar', 'danger');
							}
						});
					}
				});

				select2Simple($('select.selectTags'), $('#modalXLarge'));

				masksCurrency($('.maskCurrency'), thousandSeparator, decimal);
				masksCurrency($('.maskQty'), thousandSeparator, 'yes', false, '2');

				$('.changeItem').off('change').on('change', function() {
					return false

					var tis = $(this);
					var id = tis.data('id');
					var uid = tis.val();
					if (id) {
						var url = baseUrl + '?action=updateItemUser&id=' + id + '&uid=' + uid;
						$.get(url, function(result) {
							if (result) {
								message('Actualizado', 'success');
							} else {
								message('Error al actualizar', 'danger');
							}
						});
					}
				});

				submitForm('#editSale', function(tis, result) {
					if (result) {
						$('#modalXLarge').modal('hide');
						var $row = $('.editting');
						$.get(baseUrl + '?action=detailTable&part=1&singleRow=' + result, function(data) {
							oTable.row($row).remove();
							if (data) {
								oTable.row.add($(data));
							}
							oTable.draw();
						});
					}
				});
			});

			var srcValCache = '';
			$('#detailTableSearch').off('keyup').on('keyup', function(e) {
				var $tis = $(this);
				var value = $tis.val();
				var tmout = 800;
				var code = e.keyCode || e.which;

				if (code == 13) { //Enter keycode
					if (value.length > 3) {
						if (!$.trim(value) || srcValCache == value) {
							return false;
						}

						spinner(tableOps.container, 'show');
						$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1', function(result) {
							oTable.rows().remove();
							if (result) {
								var line = explodes('[@]', result);
								$.each(line, function(i, data) {
									if (data) {
										oTable.row.add($(data));
									}
								});
							}

							oTable.draw();

							$('.lodMoreBtnHolder').addClass('hidden');
							spinner(tableOps.container, 'hide');
						});


						srcValCache = value;

					} else if (value.length < 1 || !value) {
						srcValCache = '';
						ncmDataTables(window.info2, function(oTable, _scope) {
							loadTheTable(window.info2, oTable, _scope);
						});
					}
				}
			});
		};

		//Tabla de cobros
		window.cobrosTableOpen = false;
		$('#cobrosTab').on('shown.bs.tab', function(e) {
			if (!window.cobrosTableOpen) {
				var rawUrl2 = baseUrl + "?action=cobrosTable";
				var loadUrl2 = rawUrl2 + "&sale=<?= $saletype ?>&cusId=<?= validateHttp('ci') ?>";

				var xhr = ncmHelpers.load({
					url: loadUrl2,
					httpType: 'GET',
					hideLoader: true,
					type: 'json',
					success: function(result) {

						window.info3 = {
							"container": "#cobrosTable",
							"url": loadUrl2,
							"rawUrl": rawUrl2,
							"table": ".table2",
							"iniData": result.table,
							"sort": 0,
							"footerSumCol": [8],
							"currency": "<?= CURRENCY ?>",
							"decimal": decimal,
							"thousand": thousandSeparator,
							"offset": <?= $offsetCobros ?>,
							"limit": <?= $limitCobros ?>,
							"nolimit": true,
							"noMoreBtn": <?= validateHttp('ci') ? 'true' : 'false' ?>,
							"tableName": 'tablePayments',
							"fileTitle": 'Pagos',
							"ncmTools": {
								left: '',
								right: '<input type="text" class="form-control rounded no-border bg-light lter <?= validateHttp('ci') ? 'hidden' : ''; ?>" placeholder="Buscar por Nombre o <?= TIN_NAME ?>" id="paymentTableSearch" data-url="' + rawUrl2 + '&qry=">'
							},
							"clickCB": function(event, tis) {
								return theClickRow(event, tis);
							}
						};

						ncmDataTables(window.info3, function(oTable, _scope) {
							loadTheTable2(window.info3, oTable, _scope);
						});
					}
				});

				window.xhrs.push(xhr);

				var loadTheTable2 = function(tableOps, oTable, _scope) {
					onClickWrap('.deletePayment', function(event, tis) {
						var url = tis.attr('href');
						var $row = tis.closest('tr');

						ncmDialogs.confirm('¿Desea eliminar este pago?', 'Esta acción no se puede revertir.', 'warning', function(conf) {
							if (conf) {
								$.get(url, function(data) {
									if (data.success) {
										message('Pago eliminado.', 'success');
										oTable.row($row).remove().draw();
									} else {
										message('No se pudo eliminar', 'danger');
									}
								});
							}
						});

					});

					var timout = false;
					var srcValCache = '';
					$('#paymentTableSearch').on('keyup', function(e) {
						var $tis = $(this);
						var value = $tis.val();
						var code = e.keyCode || e.which;

						if (code == 13) { //Enter keycode
							if (value.length > 3) {
								if (!$.trim(value) || srcValCache == value) {
									return false;
								}

								spinner(tableOps.container, 'show');
								$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1', function(result) {
									oTable.rows().remove();
									if (result) {
										var line = explodes('[@]', result);
										$.each(line, function(i, data) {
											if (data) {
												oTable.row.add($(data));
											}
										});
									}

									oTable.draw()

									$('.lodMoreBtnHolder').addClass('hidden');
									spinner(tableOps.container, 'hide');
								});


								srcValCache = value;

							} else if (value.length < 1 || !value) {
								srcValCache = '';
								ncmDataTables(tableOps, function(oTable) {
									loadTheTable2(tableOps, oTable);
								});
							}
						}
					});
				};

				window.cobrosTableOpen = true;
			}
		});

		window.quotesTableOpen = false;
		$('#quotesTab').on('shown.bs.tab', function(e) {
			if (!window.quotesTableOpen) {
				var rawUrl3 = baseUrl + "?action=quotesTable";
				var loadUrl3 = rawUrl3 + "&sale=<?= $saletype ?>&cusId=<?= validateHttp('ci') ?>";
				$.get(loadUrl3, function(result) {
					window.info4 = {
						"container": "#quotesTable",
						"url": loadUrl3,
						"rawUrl": rawUrl3,
						"table": ".table3",
						"iniData": result.table,
						"sort": 0,
						"footerSumCol": [7],
						"currency": "<?= CURRENCY ?>",
						"decimal": decimal,
						"thousand": thousandSeparator,
						"offset": <?= $offsetCobros ?>,
						"limit": <?= $limitCobros ?>,
						"nolimit": true,
						"noMoreBtn": <?= validateHttp('ci') ? 'true' : 'false' ?>,
						"tableName": 'tableQuotes',
						"fileTitle": 'Cotizaciones',
						"ncmTools": {
							left: '',
							right: '<input type="text" class="form-control rounded no-border bg-light lter <?= validateHttp('ci') ? 'hidden' : ''; ?>" placeholder="Buscar por Nombre o <?= TIN_NAME ?>" id="quotesTableSearch" data-url="' + rawUrl3 + '&qry=">'
						},
						"clickCB": function(event, tis) {
							//var url = tis.data('load');
							//window.open(url,'_blank');
							return theClickRow(event, tis);
						}
					};

					ncmDataTables(window.info4, function(oTable) {
						loadTheTable3(window.info4, oTable);
					});
				});

				var loadTheTable3 = function(tableOps, oTable) {
					onClickWrap('.deleteQuote', function(event, tis) {
						var url = tis.attr('href');
						var $row = tis.closest('tr');

						ncmDialogs.confirm('¿Desea eliminar esta cotización?', 'Esta acción no se puede revertir.', 'warning', function(conf) {
							if (conf) {
								$.get(url, function(data) {
									if (data == 'true') {
										message('Cotización eliminada.', 'success');
										oTable.row($row).remove().draw();
									} else {
										message('No se pudo eliminar', 'danger');
									}
								});
							}
						});

					});

					var timout = false;
					var srcValCache = '';
					$('#quotesTableSearch').on('keyup', function(e) {
						var $tis = $(this);
						var value = $tis.val();
						var code = e.keyCode || e.which;

						if (code == 13) { //Enter keycode
							if (value.length > 3) {
								if (!$.trim(value) || srcValCache == value) {
									return false;
								}

								spinner(tableOps.container, 'show');
								$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1', function(result) {
									oTable.rows().remove();
									if (result) {
										var line = explodes('[@]', result);
										$.each(line, function(i, data) {
											if (data) {
												oTable.row.add($(data));
											}
										});
									}

									oTable.draw()

									$('.lodMoreBtnHolder').addClass('hidden');
									spinner(tableOps.container, 'hide');
								});


								srcValCache = value;

							} else if (value.length < 1 || !value) {
								srcValCache = '';
								ncmDataTables(tableOps, function(oTable) {
									loadTheTable2(tableOps, oTable);
								});
							}
						}
					});
				};

				window.quotesTableOpen = true;
			}
		});

		window.feTableOpen = false;
		$('#feTab').on('shown.bs.tab', function(e) {
			if (!window.feTableOpen) {
				var feURL = baseUrl + "?action=feTable";
				$.get(feURL, function(result) {
					ncmDataTables({
						"container": "#feTable",
						"url": feURL,
						"rawUrl": feURL,
						"table": "#tableFE",
						"iniData": result.table,
						"sort": 0,
						"currency": "<?= CURRENCY ?>",
						"decimal": decimal,
						"thousand": thousandSeparator,
						"nolimit": true,
						"tableName": 'tableFE',
						"fileTitle": 'Facturas Electrónicas',
						"ncmTools": {
							left: '',
							right: ''
						},
						"clickCB": function(event, tis) {}
					});
				});

				window.feTableOpen = true;
			}
		});

	});
</script>

<?php
include_once('includes/compression_end.php');
dai();
?>