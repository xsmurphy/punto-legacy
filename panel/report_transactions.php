<?php
header('location:/a_report_transactions');


include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
theErrorHandler();//error handler

accessControl([0]);

$startPageLoad 	= startPageLoadTimeCalculator();
//limitReportAccess(); //impedir acceso a usuarios sin permisos

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc = getROC(1);

$maxItemsInGraph 	= 20;
$itemsArray 		= [];
$itemsArrayDetail 	= [];
$isdata 			= false;
$limitDetail		= 100;
$offsetDetail		= 0;
$limitCobros		= 100;
$offsetCobros		= 0;

$jsonResult = [];

if(validateHttp('action') == 'detailTable'){

	theErrorHandler('json');

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

	$limits = getTableLimits($limitDetail,$offsetDetail);
	if(validateHttp('singleRow')){
		$singleRow = ' AND transactionId = ' . $db->Prepare(dec(validateHttp('singleRow')));
	}

	if(validateHttp('src')){
		$search = ' AND';
		$parend = '';
		$word 	= $db->Prepare(validateHttp('src'));

		//primero obtengo posible fuente
		$sData = ncmExecute('SELECT STRING_AGG(contactId::text, \',\') as uids FROM contact WHERE type = 1 AND (contactName LIKE \'%\' . $word . \'%\' OR contactTIN LIKE \'%\' . $word . \'%\' OR contactSecondName LIKE \'%\' . $word . \'%\') AND companyId = ? LIMIT 200',[COMPANY_ID]);

		if(is_numeric($word)){
			$search .= ' ( invoiceNo = "' . $word . '" OR';
			$parend = ')';
		}

		$search .= ' customerId IN(' . iftn($sData['uids'],'1') . ')' . $parend;
	

		$sql = "SELECT *
			FROM transaction 
			WHERE transactionType IN (0,3,6,7,8)
			" . $singleRow . "
			" . $roc . "
			" . $search . "
			ORDER BY transactionDate 
			DESC
			";

			if(COMPANY_ID == 2829){ // TODO: replace integer 2829 with company UUID
			//	print_r($sData);
			//	echo $sql;
			//	dai();
			}

		$saleDay = ncmExecute($sql,[],false,true,true);
	}else{
		if(validateHttp('cusId')){
			$contactId = dec(validateHttp('cusId'));

			$sql = "SELECT *
				FROM transaction 
				WHERE transactionType IN (0,3,6,7,8)
				" . $roc . "
				AND customerId = ?
				ORDER BY transactionDate 
				DESC";

				if($_GET['test']){
					dai($sql . ' - ' . $contactId);
				}

			$saleDay = ncmExecute($sql,[$contactId],false,true,true);
		}else{
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
		}
	}
	

	$head = 	'<thead class="text-u-c pointer">'.
				'	<tr>'.
				'		<th>ID</th>'.
				'		<th class="text-center"># Autorización</th>'.
				'		<th class="text-center"># Documento</th>'.
				'		<th>Fecha</th>'.
				'		<th>Hora</th>'.
				'		<th>Vencimiento</th>'.
				'		<th>Cliente</th>'.
				'		<th>'.TIN_NAME.'</th>'.
				'		<th>Usuario</th>'.
				'		<th>Sucursal</th>'.
				'		<th>Caja</th>'.
				'		<th>M. de Pago</th>'.
				'		<th>Nota</th>'.
				'		<th>Etiquetas</th>'.
				'		<th>Tipo</th>'.
				'		<th class="text-center">Descuento</th>'.
				'		<th class="text-center">Subtotal</th>'.
				'		<th class="text-center">'.TAX_NAME.'</th>'.
				'		<th class="text-center">Total Gravado</th>'.
				'		<th class="text-center">Total</th>'.
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	$table 						= '';
	if($saleDay){
		//getAllcustomersIds
		$customersArr 	= [];
		$transArr 		= [];
		foreach ($saleDay as $key => $customer) {
			$customersArr[] = $customer['customerId'];
			$transArr[] 	= $customer['transactionId'];
		}

		if(validity($transArr)){
			$custIn 				= ' AND transactionParentId IN(' . implodes(',',$transArr) . ')';
			$allToPayTransactions 	= getAllToPayTransactions(false,$custIn);
		}
		//
		
		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();

		foreach ($saleDay as $key => $fields) {
			$itemId 			= enc($fields['transactionId']);
			$tTotal 			= $fields['transactionTotal'];
			$netTotal 			= $tTotal - $fields['transactionDiscount'];

			$customer 			= getCustomerData($fields['customerId'],'uid',true);

			if(validity($fields['userId'])){
				$user 				= getCustomerData($fields['userId'],false,true);
				$userName 			= $user['name'];
			}else{
				$userName 			= '-';
			}

			if($fields['transactionType'] == '6'){
				$totalReturned++;
			}

			$transactionType 	= '<span class="label bg-light text-u-c">Contado</span>';
			$typeFilter 		= 'tipo:contado';

			//status
			$statusOut = '';
			if($fields['transactionComplete'] < 1 && $fields['transactionType'] == '3'){
				if(strtotime($fields['transactionDueDate']) < strtotime('now')){
					$statusOut = '<span class="badge badge-sm bg-danger up">!</i>';
				}
			}

			//

			//payment methods
			$paymentType 	= getPaymentMethodsInArray($fields['transactionPaymentType']);
			$pMethods 		= '';

			if(validity($paymentType)){
				foreach($paymentType as $pType){		
					$pMethods .= '<span class="label bg-light m-r-xs" data-toggle="tooltip" data-placement="top" title="'.formatCurrentNumber($pType['price']).'">' . iftn(getPaymentMethodName($pType['type']),$pType['name']) . '</span>';
				}
			}
			//

			if($fields['transactionType'] == '3'){

				$tPayed = $allToPayTransactions[$fields['transactionId']];
				$topay 	= $netTotal-$tPayed;

				$totalCobrado += $tPayed;

				if($topay > ($netTotal/2)){
		          $typeText   	= 'bg-danger';
		          $filterOp 	= '_sinpago';
		        }
		        if($topay <= ($netTotal/2)){
		          $typeText   	= 'bg-warning';
		          $filterOp 	= '_conpago';
		        }
		        if($topay <= 0 || $fields['transactionComplete'] == 1){
		          $typeText   	= 'bg-success text-white';
		          $filterOp 	= '_pagado';
		          $totalCobcount++;
		        }
		        if($topay > 0){
		          $totalPorcount++;
		        }

		        $transactionType = '<span class="label '.$typeText.' lter text-u-c" data-toggle="tooltip" data-placement="top" title="Vencimiento ('.niceDate($fields['transactionDueDate']).')">Crédito</span>';
		        $typeFilter 		= 'tipo:crédito'.$filterOp;

				$totalCredito += ($tTotal-$fields['transactionDiscount']);
				$totalCcount++;

				$isCreditSale = true;
			}else if($fields['transactionType'] == '0'){
				$totalContado += $netTotal;
				$totalCocount++;
			}else if($fields['transactionType'] == '6'){
				$totalContado 		+= $netTotal;
				$transactionType 	= '<span class="label bg-dark lter text-u-c">Devolución</span>';
				$typeFilter 		= 'tipo:devolución';
			}else if($fields['transactionType'] == '7'){
				$netTotal  			= 0;
				$transactionType 	= '<span class="label bg-dark text-u-c">Anulado</span>';
				$typeFilter 		= 'tipo:anulado';
			}
			
			$paymentTypeName 	= '';
			if($paymentTypeName){
				foreach($paymentType as $pType){
					$paymentTypeName .= $pType->name."/";
				}
			}

			$fechUgly 			= $fields['transactionDate'];
			$fecha 				= timeago($fechUgly,false);

			if($fields['transactionType'] == '7'){
				$calculableTotal 	= 0;
				$calculableSubTotal = 0;
				$calculableTax 		= 0;
				$calculableDiscount	= 0;
			}else{
				$calculableTotal 	= $netTotal;
				$calculableSubTotal = $fields['transactionTotal'];
				$calculableTax 		= $fields['transactionTax'];
				$calculableDiscount	= $fields['transactionDiscount'];
			}
			
			$customerTin		=  iftn($customer['ruc'],'-');

			$invoicePrefix 		= $fields['invoicePrefix'];

			$registerData 		= ncmExecute('SELECT * FROM register WHERE registerId = ? AND companyId = ?',[$fields['registerId'],COMPANY_ID],true);
			$invoiceAuth 		= $registerData['registerInvoiceAuth'];
			if(!$invoicePrefix){
				$invoicePrefix = $registerData['registerInvoicePrefix'];
			}

			$dueDate 			= $fields['transactionDueDate'];
			if(!validity($dueDate)){
				$dueDate 			= $fechUgly;
			}

			$table .= 	'<tr data-id="'.$itemId.'">' .
						'	<td class="bg-light dk">'.$itemId.'</td>' .
						'	<td class="text-right bg-light dk">' . $invoiceAuth . '</td>' .
						'	<td class="text-right bg-light dk" data-order="' . $fields['invoiceNo'] . '">' . $invoicePrefix . $fields['invoiceNo'] . '</td>' .
						'	<td data-order="'.$fechUgly.'" data-filter="'.$fechUgly.'"> <span class="tip" data-toggle="tooltip" data-placement="top" title="'.$fechUgly.'">'.niceDate($fechUgly).'</span> </td>' .
						'	<td> ' . date('H:i',strtotime($fechUgly)) . ' </td>' .
						'	<td data-order="'.$dueDate.'" data-filter="'.$dueDate.'"> '.niceDate($dueDate).' </td>' .
						'	<td data-filter="' . $customer['name'] . ' ' . $customer['secondName'] . ' ' . (iftn($customer['name'],'','con:cliente')) .  '">' . $customer['name'] . '</td>' .
						'	<td>'.$customerTin.'</td>' .
						'	<td>'.$userName.' </td>' .
						'	<td>'.$getAllOutlets[$fields['outletId']]['name'].'</td>' .
						'	<td>'.$getAllRegisters[$fields['registerId']]['name'].'</td>' .
						'	<td>'.$pMethods.'</td>' .
						'	<td>' . ($fields['transactionNote']) . '</td>' .
						'	<td> ' . ( ($_GET['debug']) ? $fields['tags'] : printOutTags(json_decode($fields['tags']),'bg-info lter') ) . ' </td>' .
						'	<td data-filter="'.$typeFilter.'"> '.$transactionType.$statusOut.' </td>' .
						'	<td class="text-right bg-light lter" data-order="'.$calculableDiscount.'" data-format="money">'.formatCurrentNumber($fields['transactionDiscount']).'</td>' .
						'	<td class="text-right bg-light lter" data-order="'.$calculableSubTotal.'" data-format="money">'.formatCurrentNumber($fields['transactionTotal']).'</td>' .
						'	<td class="text-right bg-light lter" data-order="'.$calculableTax.'" data-format="money">'.formatCurrentNumber($fields['transactionTax']).'</td>' .
						'	<td class="text-right bg-light lter" data-order="'.($netTotal - $fields['transactionTax']).'" data-format="money">'.formatCurrentNumber($netTotal - $fields['transactionTax']).'</td>' .
						'	<td class="text-right bg-light lter" data-order="'.$calculableTotal.'" data-format="money">'.formatCurrentNumber($netTotal).'</td>' .
						'</tr>';

			if(validateHttp('part') && !validateHttp('singleRow')){
	        	$table .= '[@]';
	        }

			$netTotal = $fields['transactionTotal']-$fields['transactionDiscount'];

			if($fields['transactionType'] == '6'){
				$tTotal -= $netTotal;
			}else{
				$tTotal += $netTotal;
			}
			
			$No--;
		}

		$url 	= "?action=detailTable&from=".$startDate."&to=".$endDate."&sale=".$saletype;
	}

	$foot = 	'</tbody>'.
				'<tfoot>'.
		        '    <tr>'.
		        '   	<th colspan="15">TOTALES:</th>'.
		        '    	<th class="text-right"></th>'.
		        '    	<th class="text-right"></th>'.
		        '       <th class="text-right"></th>'.
		        '       <th class="text-right"></th>'.
		        '       <th class="text-right"></th>'.
		        '    </tr>'.
		        '</tfoot>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		if($_GET['debug']){
			echo $fullTable;
			dai();
		}

		header('Content-Type: application/json'); 
		dai(json_encodes($jsonResult,true));
	}
}

if(validateHttp('action') == 'cobrosTable'){
	$limits = getTableLimits($limitCobros,$offsetCobros);
	$table 	= '';

	if(validateHttp('src')){
		$word 	= $db->Prepare(validateHttp('src'));
		//primero obtengo posible fuente
		$sData = ncmExecute('SELECT STRING_AGG(contactId::text, \',\') as uids FROM contact WHERE type = 1 AND (contactName LIKE \'%\' . $word . \'%\' OR contactTIN LIKE \'%\' . $word . \'%\' OR contactSecondName LIKE \'%\' . $word . \'%\') AND companyId = ? LIMIT 100',[COMPANY_ID],true);
		
		$search = ' AND customerId IN(' . $sData['uids'] . ')';

		$sql = "SELECT *
			FROM transaction 
			WHERE transactionType IN (5)
			AND transactionDate 
		   " . $roc . "
		   " . $search . "
		  ORDER BY transactionDate DESC";

		$result = ncmExecute($sql,[],false,true,true);
	}else{

		if(validateHttp('cusId')){
			$contactId = dec(validateHttp('cusId'));

			$sql = "SELECT *
				FROM transaction 
				WHERE transactionType IN (5)
				AND transactionDate 
			   " . $roc . "
			   AND customerId = ?
			  ORDER BY transactionDate DESC";

			$result = ncmExecute($sql,[$contactId],false,true,true);
		}else{
			$sql = "SELECT *
				FROM transaction 
				WHERE transactionType IN (5)
				AND transactionDate 
				BETWEEN ?
				AND ? 
			   " . $roc . "
			  ORDER BY transactionDate DESC" . $limits;

			$result = ncmExecute($sql,[$startDate,$endDate],false,true,true);
		}
	}

	

	$head 	=	'<thead class="text-u-c pointer">'.
				'	<tr>'.
				'		<th class="text-center">Doc. Ref. #</th>'.
				'		<th class="text-center">Documento #</th>'.
				'		<th>Fecha</th>'.
				'		<th>Cliente</th>'.
				'		<th>Usuario</th>'.
				'		<th>Sucursal</th>'.
				'		<th>Caja</th>'.
				'		<th>M. de Pago</th>'.
				'		<th class="text-center">Cobrado</th>'.
				'		<th></th>'.
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	if($result){
		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();

		foreach ($result as $key => $fields) {

			$parentIs = ncmExecute('SELECT transactionType, invoiceNo, invoicePrefix FROM transaction WHERE transactionId = ? AND transactionType = 3 AND companyId = ? LIMIT 1',[$fields['transactionParentId'],COMPANY_ID],true); //valido si es un pago de una venta a credito

			if($parentIs){

				$transId 	= enc($fields['transactionParentId']);
				//payment methods
				$paymentType 	= json_decode(iftn($fields['transactionPaymentType'],'{}'),true);
				$pMethods 		= '';

				$customer 		= getCustomerData($fields['customerId'],'uid');

				if(validity($fields['userId'])){
					$user 				= getCustomerData($fields['userId']);
					$userName 			= ($user['name']);
				}else{
					$userName 			= '-';
				}

				if(validity($paymentType)){
					foreach($paymentType as $pType){		
						$pMethods .= '<span class="label bg-light m-r-xs" data-toggle="tooltip" data-placement="top" title="'.formatCurrentNumber($pType['price']).'">'.$pType['name'].'</span>';
					}
				}
				//

				//parent invoice
				$parentInvoice 		= $parentIs['invoicePrefix'] . $parentIs['invoiceNo'];

				$customer 			= ($customer['name']);

				$table .= '<tr data-id="'.$transId.'" data-ro="true">'.
						'		<td class="text-right">' . $parentInvoice . '</td>'.
						'		<td class="text-right">' . $fields['invoiceNo'] . '</td>'.
						'		<td data-order="' . $fields['transactionDate'] . '" data-filter="' . $fields['transactionDate'] . '">'.niceDate($fields['transactionDate'],true) . '</td>'.
						'		<td>'.$customer.'</td>'.
						'		<td>'.$userName.'</td>'.
						'		<td>'.$getAllOutlets[$fields['outletId']]['name'].'</td>'.
						'		<td>'.$getAllRegisters[$fields['registerId']]['name'].'</td>'.
						'		<td>'.$pMethods.'</td>'.
						'		<td class="text-right bg-light lter" data-order="'.$fields['transactionTotal'].'" data-format="money"> '.formatCurrentNumber($fields['transactionTotal']).'</td>'.
						'		<td class="text-center">' . 
						'			<a href="?action=delete&id=' . enc($fields['transactionId']) . '&outlet=' . enc($fields['outletId']) . '&type=' . enc($fields['transactionType']) . '&parent=' . $transId . '" class="deletePayment">' .
						' 				<i class="material-icons text-danger">close</i>' . 
						'			</a>' . 
						'		</td>' .
						'	</tr>';

				if(validateHttp('part')){
		        	$table .= '[@]';
		        }

		    }
		}

	}

	$foot 	= 		'</tbody>'.
					'<tfoot>'.
			        '    <tr>'.
			        '   	<th>TOTALES:</th>'.
			        '    	<th></th>'.
			        '    	<th></th>'.
			        '    	<th></th>'.
			        '    	<th></th>'.
			        '    	<th></th>'.
			        '    	<th></th>'.
			        '    	<th></th>'.
			        '       <th class="text-right"></th>'.
			        '    	<th></th>'.
			        '    </tr>'.
			        '</tfoot>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		header('Content-Type: application/json'); 
		dai(json_encodes($jsonResult));
	}
}

if(validateHttp('action') == 'update' && validateHttp('id','post')){
	theErrorHandler('json');
	$record = [];
	$id 	= $db->Prepare(dec($_POST['id']));

	$date = explodes('/',$_POST['date']);
  	$date = $date[2] . '-' . $date[0] . '-' . $date[1] . ' 00:00:01';

	$record['transactionDate'] 		= $_POST['date'];

	if(validateHttp('duedate','post')){
		$record['transactionDueDate']	= $_POST['duedate'];
	}

	$record['transactionNote'] 		= $_POST['note'];
	$record['customerId'] 			= dec($_POST['customer']);

	if(validateHttp('user','post')){
		$record['userId'] 			= dec($_POST['user']);
		$record['responsibleId']	= dec($_POST['user']);
	}

	if(validateHttp('outlet','post')){
		$record['outletId'] 		= dec($_POST['outlet']);
	}

	if(validateHttp('invoiceNo','post')){
		$record['invoiceNo'] 		= $_POST['invoiceNo'];
	}

	if(validateHttp('trTags','post')){
		$tag = '';
		foreach (validateHttp('trTags','post') as $id) {
			$tag .= dec($id) . ',';
		}
		$record['tags'] 		= json_encode(explodes(',',$tag));
	}else{
		$record['tags'] 		= NULL;
	}

	if(validateHttp('trtype','post')){
		if(validateHttp('trtype','post') == 'contado'){
			$type = 0;
		}else{
			$type = 3;
			$record['transactionComplete'] 		= 0;
		}

		$record['transactionType'] 		= $type;
	}

	//pMtype

	if(validateHttp('haspayments','post')){
		$pmethod = [];
		foreach ($_POST['pMtype'] as $key => $value) {
			$ptype 		= $_POST['pMtype'][$key];
			$pextra 	= $_POST['pMextra'][$key];
			$pamount 	= formatNumberToInsertDB($_POST['pMamount'][$key]);

			if($ptype){
				$pmethod[] = ['type'=>$ptype,'total'=>$pamount,'price'=>$pamount,'extra'=>$pextra];
			}
		}
		$record['transactionPaymentType'] = json_encode($pmethod);
	}

	$update = $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = '.$db->Prepare(dec($_POST['id']))); 
	if($update === false){
		echo 'false';
	}else{
		$db->AutoExecute('itemSold', ['itemSoldDate' => $_POST['date']], 'UPDATE', 'transactionId = ' . $id); 
		$db->AutoExecute('comission', ['comissionDate' => $_POST['date']], 'UPDATE', 'transactionId = ' . $id);

		echo 'true|0|'.$_POST['id'];
	}
	dai();
}

if(validateHttp('action') == 'updateItemUser'){
	$out = 'false';
	if(validateHttp('id') && validateHttp('uid')){
		$itemSoldId = dec(validateHttp('id'));
		$userId 	= dec(validateHttp('uid'));

		$update 	= $db->AutoExecute('itemSold', ['userId' => $userId], 'UPDATE', 'itemSoldId = ' . $itemSoldId);

		if($update !== false){
			$out = 'true';
		}else{
			$out = 'false';
		}
	}
	dai($out);
}

if(validateHttp('action') == 'updateItem'){
	$out = 'false';
	if(validateHttp('id') && validateHttp('uid')){
		$itemSoldId = dec(validateHttp('id'));
		$itemId 	= dec(validateHttp('uid'));

		$update 	= $db->AutoExecute('itemSold', ['itemId' => $itemId], 'UPDATE', 'itemSoldId = ' . $itemSoldId);

		if($update !== false){
			$out = 'true';
		}else{
			$out = 'false';
		}
	}
	dai($out);
}

if(validateHttp('action') == 'updateItemTotal'){
	$out = 'false';
	if(validateHttp('id') && validateHttp('uid')){
		$itemSoldId = dec(validateHttp('id'));
		$total 		= formatNumberToInsertDB(validateHttp('total'));

		$update 	= $db->AutoExecute('itemSold', ['itemSoldTotal' => $total], 'UPDATE', 'itemSoldId = ' . $itemSoldId);

		if($update !== false){
			$out = 'true';
		}else{
			$out = 'false';
		}
	}
	dai($out);
}

if(validateHttp('action') == 'delete' && validateHttp('id')){
	theErrorHandler('json');//error handler
	if(ROLE_ID == 7){
		dai('false');
	}

	$id 				  = dec($_GET['id']);
	$outlet 			  = dec($_GET['outlet']);
	$type 				  = dec($_GET['type']);
  	$parent         	  = $db->Prepare(dec($_GET['parent']));

	if($type != 5){//si no es un pago
		$delete = deleteTransaction($id);
	}else{
		//cambio completed a 0 si es que borro un pago
		if(validateBool('parent')){
		  $db->AutoExecute('transaction', ['transactionComplete'=>0], 'UPDATE', 'transactionId = ' . $parent); 
		}
	}
	$delete 	= $db->Execute('DELETE FROM transaction WHERE transactionId = ? LIMIT 1', array($id)); //venta
	
	if(!$delete){
		echo 'false';
	}else{
		echo 'true';
	}

	dai();
}

if(validateHttp('action') == 'addPayment' && validateHttp('id','post')){
	$id 		= $db->Prepare(dec(validateHttp('id','post')));
	$value 		= formatNumberToInsertDB(validateHttp('payAmount','post'));
	$method 	= validateHttp('method','post');

	//[{"type":"cash","name":"Efectivo","price":8000,"total":"8000.00","extra":""}]

	$record 	= [];
	$record['transactionPaymentType'] 	= $value;

	$insert 	= $db->AutoExecute('transaction', $record, 'UPDATE','transactionId = ' . $id); 

	if($insert !== false){
		dai('true|0|' . enc($id));
	}else{
		dai('false');
	}
}

//FORMULARIO DE EDICION
if(validateHttp('action') == 'edit'){
	$id =  validateHttp('id') ? validateHttp('id') : validateHttp('uid');

	if(validateHttp('id')){
		$field = 'transactionId';
	}else{
		$field = 'transactionUID';
	}
	
	$result 	= ncmExecute("SELECT * FROM transaction WHERE " . $field . " = ? LIMIT 1",[dec($id)]);

	if(!$result || (!validateHttp('id')) && !validateHttp('uid')){
		dai('<div class="modal-body no-padder clear r-3x bg-white"><h1 class="block text-center wrapper font-thin">La transacción '.$id.' no existe</h1></div>');
	}

	$tDate 		  	= $result['transactionDate'];
	$tDDate		  	= $result['transactionDueDate'];
	$tUser 		  	= iftn($result['responsibleId'],$result['userId']);
	$tCustomer 		= $result['customerId'];
	$tOutlet 	  	= $result['outletId'];
	$tPayment 		= $result['transactionPaymentType'];
	$tNote 		  	= $result['transactionNote'];
	$tags 		  	= json_decode($result['tags'],true);
	$tSaleType 		= $result['transactionType'];
	$tComplete 		= $result['transactionComplete'];
	$tLocation 		= $result['transactionLocation'];
	$tTimestamp 	= $result['timestamp'];

	$totalSale 		= $result['transactionTotal'] - $result['transactionDiscount'];

	if($tSaleType == '0'){
		$saleType = '<span class="font-bold text-u-c">Contado</span>';
		$saleType = '<select name="trtype" class="form-control no-border b-b font-bold text-white"> <option value="contado" selected>CONTADO</option> <option value="credito">CRÉDITO</option> </select>';
	}else if($tSaleType == '2'){
		$saleType = '<span class="font-bold text-u-c">Guardado</span>';
	}else if($tSaleType == '3'){
		$creditStatus = ($tComplete)?'<i class="material-icons m-r-xs text-white">check</i>':'';
		$saleType = $creditStatus . '<span class="text-white font-bold text-u-c">Crédito</span>';
		//$saleType = '<select name="trtype" class="form-control no-border b-b font-bold"> <option value="contado">CONTADO</option> <option value="credito" selected>CRÉDITO</option> </select>';
	}else if($tSaleType == '6'){
		$saleType = '<span class="font-bold text-u-c">Devolución / Nota de Crédito</span>';
		$totalSale 		= $result['transactionTotal'];
	}else if($tSaleType == '7'){
		if($result['responsibleId']){
			$responsible 	= getValue('contact','contactName','WHERE type = 0 AND contactId = ' . $result['responsibleId'] . ' LIMIT 1');
			$responsibleText = '<div class="text-sm">Por ' . $responsible . '</div>';
		}
		$saleType = '<span class="font-bold text-u-c">Anulado</span> ' . $responsibleText;
	}else if($tSaleType == '9'){
		$saleType = '<span class="font-bold text-u-c">Cotización</span>';
	}else if($tSaleType == '10'){
		$saleType = '<span class="font-bold text-u-c">Remisión</span>';
	}else if($tSaleType == '13'){
		$saleType = '<span class="font-bold text-u-c">Agendamiento</span>';
	}

    ?>
   
    <div class="modal-body no-padder clear r-3x" id="factura">
    	<?=headerPrint(['noOutlet'=>true]);?>
	    <form action="?action=update" method="POST" id="editSale" name="editSale">
	    	<div class="row equal padder">
		    	<div class="col-md-4 col-sm-12 col-xs-12 wrapper bg-info gradBgBlue">
		    		<?php
	    			$userName 		= getValue('contact','contactName','WHERE type = 0 AND contactId = ' . $tUser);
	    			$customerName 	= getValue('contact','contactName','WHERE type = 1 AND contactId = ' . $tCustomer);

	    			$userName 		= iftn($userName,'Asignar Usuario');
	    			$customerName 	= iftn($customerName,'Sin cliente');
	    			?>

	    			<div class="col-md-12 col-sm-4 col-xs-4 no-padder m-b">
						<div class="font-bold text-u-c text-xs">Tipo</div>
		    			<div class="text-u-c text-md"><?=$saleType?></div>
		    			<?php
		    			if(in_array($tSaleType, [3,9])){
		    			?>
			    			<div class="font-bold text-xs text-u-c m-t">Vencimiento</div>
							<?php
							if(!isInvoiceEditable()){
								echo '<div class="text-lg text-white">' . niceDate($tDDate) . '</div>';
							}else{
							?>
							<div class="text-default">
								<input type="text" class="form-control datepicker no-border b-b no-bg text-center pointer text-white" name="duedate" value="<?=$tDDate?>" autocomplete="off" />
							</div>
						<?php
							}
						}
						?>
					</div>

	    			<div class="col-md-12 col-sm-4 col-xs-4 no-padder m-b">
						<div class="text-u-c text-xs font-bold"># Documento</div> 
						<div class="h3 font-bold">
							<?php
							if(!isInvoiceEditable()){
								echo '<div class="text-lg text-white">' . $result['invoiceNo'] . '</div>';
							}else{
							?>
								<input type="number" min="0" step="1" value="<?=$result['invoiceNo']?>" name="invoiceNo" class="form-control no-border no-bg b-b text-lg text-white text-right" autocomplete="off" />
							<?php
							}
							?>
						</div>
					</div>
	    		
	    			<div class="col-md-12 col-sm-4 col-xs-4 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Cliente</span>
						<?php
						if(!isInvoiceEditable()){
							echo '<div class="text-lg text-white">'.$customerName.'</div>';
						}else{
						?>
			            	<span class="loadCustomerInput hidden" data-match="<?=enc($result['customerId'])?>">...</span>
							<select name="customer" class="form-control selectCustomer text-white">
								<?php
								if(validity($result['customerId'])){
								?>
									<option value="<?=enc($result['customerId'])?>" selected><?=$customerName;?></option>
								<?php
								}
								?>
							</select>
				        <?php
				        }
				        ?>
				    </div>

				    <div class="col-md-12 col-sm-4 col-xs-4 no-padder m-b">
		            	<span class="font-bold text-xs text-u-c">Fecha</span>
						<?php
						if(!isInvoiceEditable()){
							echo '<div class="text-lg text-white">' . niceDate($tDate,true) . '</div>';
						}else{
						?>
						<div class="text-default">
						  <input type="text" class="form-control datepicker no-border b-b no-bg text-center pointer text-white" name="date" value="<?=$tDate?>" autocomplete="off" />
						</div>
						<?php
						}
						?>
					</div>

					<div class="col-md-12 col-sm-4 col-xs-4 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Sucursal</span>
						<?php
						echo selectInputOutlet($tOutlet,false,'no-border selectSimple b-b text-white','outlet',true);
						?>
					</div>
				
					<div class="col-md-12 col-sm-4 col-xs-4 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Usuario</span>
						<?php 
			            echo selectInputUser($tUser,false,'userselect selectSimple no-border b-b text-white','user',true);
			            ?>
			        </div>

		            <?php
					if(isInvoiceEditable()){
					?>
					<div class="col-md-12 col-sm-4 col-xs-4 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Etiquetas</span>
						<?php
							$allTags = getAllTags();
							$tagsO = '';

							if (validity($tags,'array')) {
								foreach($tags as $tag){
	    							$tagsO 	.= enc($tag) . ',';
	    						}
							}

							$tagsO = explodes(',',$tagsO);

							$allT = [];
							if (validity($allTags,'array')) {
								foreach($allTags as $key => $tag){
									$allT[enc($key)] = $tag['name'];
								}
							}

							$tagsO = array_flatten($tagsO);

							echo selectInputGenerator($allT,['match'=>$tagsO,'name'=>'trTags[]','class'=>'selectTags','data'=>'multiple="multiple"']);
						?>
					</div>
					<?php
					}
					?>
					
					<div class="col-md-12 col-sm-4 col-xs-4 no-padder m-b">
						<span class="font-bold text-xs text-u-c">Nota</span>
						<p class="switchToInput pointer" data-switch="#noteInput"><?=iftn($tNote,'Clic para añadir una nota a la venta')?></p>
						<div style="display:none;" id="noteInput">
							<textarea class="form-control no-bg text-white no-border b-b" name="note"><?=$tNote?></textarea>
						</div>
					</div>
				
				
					<span class="badge hidden-print">ID: <?=$tTimestamp?></span>
		    	</div>
		    	
		    	<div class="col-md-8 col-sm-12 col-xs-12 no-padder bg-white">
		    		<div class="col-xs-12 wrapper">
		    			<div class="col-xs-12 text-center m-b-md no-border panel bg-light lter r-3x table-responsive">
		    				<table class="table" id="modalItemsTable">
				                <thead>
				                    <tr class="text-u-c">
				                        <th style="width:15%;">Cant.</th>
				                        <th style="width:35%;" class="text-left">Producto/Servicio</th>
				                        <th style="width:30%;" class="text-left">Usuario</th>
				                        <th style="width:20%;" class="text-right">Total</th>
				                    </tr>
				                </thead>
				                <tbody>
				                    <?php 
				                    	if(in_array($tSaleType,['2','7','9','11','12','13'])){
				                    		foreach(json_decode($result['transactionDetails'],true) as $key => $val){
				                    			if(validity($val['itemId'])){
					                    			$itemData 	= getItemData(dec($val['itemId']));

													$name 		= $itemData['itemName'];													

					                    			$units 		= formatQty($val['count']);
					                    			$price 		= formatCurrentNumber($val['price'] / $val['count']);
						                            $discount	= formatCurrentNumber($val['discAmount']);

						                            $userItemName 	= ($val['user']) ? getValue('contact','contactName','WHERE type = 0 AND contactId = ' . dec($val['user'])) : '';

						                            $subtotal	= formatCurrentNumber($val['price']);
						                            $total 		= formatCurrentNumber($val['price']-$discount);

						                            $taxName 	= $val['tax'];
													
													if($taxName < 0){
						                            	$taxName 	= $taxes[$inventory[$val['itemId']]['tax']]['name'];
														$tax 		= formatCurrentNumber(getTaxOfPrice($taxName,$subtotal));	
						                            }else{
						                            	$tax 		= formatCurrentNumber(getTaxOfPrice($taxName,$subtotal));
						                            }

													?>
								                        <tr>
								                            <td><?=$units?></td>
								                            <td class="text-left">
								                            	<?=$name?>
								                            </td>
								                            <td class="text-left"><?=$userItemName;?></td>
								                           <!-- <td class="text-right"><?=CURRENCY.$price?></td>
								                            <td class="text-right"><?=CURRENCY.$tax?></td>-->
								                            <td class="text-right"><?=CURRENCY.$total?></td>
								                        </tr>
								                    <?php
							                	}
				                    		}
				                    	}else{
					                    	//$inventory 	= getAllInventory();
											//$taxes 		= getAllTax();
					                        $items 	= ncmExecute("SELECT * FROM itemSold WHERE transactionId = ?",[$result['transactionId']],false,true);
					                        if($items){
					                        	while (!$items->EOF) {
					                        		$item 		= $items->fields;
					                        		$encISId 	= enc($item['itemSoldId']);

					                        		if(validity($item['itemId'])){

						                        		$itemData 	= getItemData($item['itemId']);

						                        		if(in_array($itemData['itemType'],['combo','precombo','comboAddons','product','production','direct_production','giftcard','discount'])){
						                        			
						                        			if(in_array($tSaleType, [0,3]) && isInvoiceEditable()){
						                        				$userSold 	= 	selectInputUser($item['userId'],true,'no-border b-b selectUser ' . $encISId,'itemUser[' . $encISId . ']',true,'data-id="' . $encISId . '"');
						                        			}else{
						                        				$userItemName 	= getValue('contact','contactName','WHERE type = 0 AND contactId = ' . $item['userId']);
						                        				$userSold 	= 	 $userItemName;
						                        			}
						                        			

						                        			if(isInvoiceEditable()){
							                        			$name 		= 	'	<select name="item[' . $encISId . ']" class="form-control selectItem" data-id="' . $encISId . '">' .
																				'		<option value="' . enc($item['itemId']) . '" selected>' . $itemData['itemName'] . '</option>' .
																				'	</select>';
																				
															}else{
																$name 		= iftn($itemData['itemName'],'El artículo no existe');
															}

						                        			if($item['itemSoldParent'] && !in_array($itemData['itemType'],['combo','precombo','comboAddons'])){
						                        				$name 		= '↳ ' . $itemData['itemName'];
						                        				$price 		= '-';
						                        				$subtotal 	= 0;
						                        				$total 		= '-';
						                        				$tax 		= '-';
						                        				$discount	= '-';
						                        			}else{
						                        				$price 		= CURRENCY . formatCurrentNumber($item['itemSoldTotal'] / $item['itemSoldUnits']);

						                        				$subtotal	= formatCurrentNumber($item['itemSoldTotal'] + $item['itemSoldDiscount']);
						                        				$total 		= CURRENCY . formatCurrentNumber($item['itemSoldTotal']);

						                        				if($item['itemSoldTax'] < 0){
									                            	$taxName 	= $taxes[$inventory[$item['itemId']]['tax']]['name'];
																	$tax 		= CURRENCY . formatCurrentNumber(getTaxOfPrice($taxName,$subtotal));	
									                            }else{
									                            	$tax 		= CURRENCY . formatCurrentNumber($item['itemSoldTax']);
									                            }

									                            $discount	= CURRENCY . formatCurrentNumber($item['itemSoldDiscount']);
						                        			}


						                        			if($itemData['itemType'] == 'discount'){
						                        				$name 	= 'Descuento';
						                        			}

						                        			$units 		= formatCurrentNumber($item['itemSoldUnits'],'yes');
						                        		}
							                    ?>
							                        <tr>
							                            <td>
							                            	<input type="hidden" name="itemId[]" value="<?=$encISId?>">
							                            	<input type="text" name="itemQty[<?=$encISId?>]" class="form-control no-bg no-border b-b maskQty" data-id="<?=$encISId?>" value="<?=$units?>">
							                            </td>
							                            <td class="text-left"><?=$name?></td>
							                            <td class="text-left"><?=$userSold?></td>
							                           <!-- <td class="text-right"><?=$price?></td>
							                            <td class="text-right"><?=$tax?></td>-->
							                            <td>
							                            	<input type="text" name="itemTotal[<?=$encISId?>]" class="form-control no-bg no-border b-b maskCurrency changeItem text-right" data-id="<?=$encISId?>" value="<?=formatCurrentNumber($item['itemSoldTotal'])?>">
							                            </td>
							                        </tr>
							                    <?php
							                    	}
							                        $items->MoveNext();
							                    }
					                    	}else{
					                    		if(isInvoiceEditable()){
				                        			$name 		= 	'	<select name="item[0]" class="form-control selectItem" data-id="">' .
																	'		<option value="" selected></option>' .
																	'	</select>';
																	
												}else{
													$name 		= iftn($itemData['itemName'],'El artículo no existe');
												}
					                    	}
					                    }
				                    ?>
				                </tbody>
				                <tfoot class="font-bold text-right">
				                	<tr>
				                		<td colspan="3">Descuentos</td>
				                		<td><?=formatCurrentNumber($result['transactionDiscount'])?></td>
				                	</tr>
				                	<tr>
				                		<td colspan="3">Subtotal</td>
				                		<td><?=formatCurrentNumber($result['transactionTotal'])?></td>
				                	</tr>
				                	<tr>
				                		<td colspan="3"><?=TAX_NAME?></td>
				                		<td><?=formatCurrentNumber($result['transactionTax'])?></td>
				                	</tr>

				                	<tr class="text-lg">
				                		<td colspan="3" class="text-u-c">Total</td>
				                		<td><?=formatCurrentNumber($totalSale)?></td>
				                	</tr>
				                	
				                </tfoot>
				            </table>
		    			</div>

		    			<div class="col-xs-12 no-padder text-left">
		    				<div class="text-center m-b-md no-border panel bg-light lter r-3x col-xs-12 table-responsive">
		    					<?php if(in_array($tSaleType, [0,5])){ ?>

			    					<table class="table text-left">
			    						<tr class="font-bold text-u-c">
					                        <td colspan="3" class="text-center">
					                          Métodos de pago
					                        </td>
					                    </tr>
			    					<?php
		    							$paymentType 	= getPaymentMethodsInArray($tPayment);
		    							$tPtypes 		= getAllPaymentMethodsArray();

		    							if(validity($paymentType,'array')){
			                  				foreach($paymentType as $payment){
			                  					$extra = $payment['extra'];
			    							?>
			    								<tr class="">
			    	    							<td> <?php 
			    	    								if(isInvoiceEditable()){
			    	    									echo selectInputGenerator($tPtypes,['match'=>$payment['type'],'class'=>'selectSimple b-light','name'=>'pMtype[]']);
			    	    								}else{
			    	    									echo $payment['name'];
			    	    								}
			    	    								?> 
			    	    							</td>
			    	    							<td>
			    	    								<?php 
			    	    									if($payment['type'] == 'check'){
			    	    										$extra = csvToBankData($payment['extra']);
			    	    										echo $extra;
			    	    									}else if($payment['type'] == 'giftcard'){
			    	    										echo '<a href="/report_giftCards?i=' . $payment['extra'] . '" class="btn btn-default font-bold rounded btn-block" target="_blank">' . $payment['extra'] . '</a>';
			    	    									}else if(!isInvoiceEditable()){
			    	    										echo $extra;
			    	    									}else{
			    	    									?>
				    	    									<input class="form-control no-border no-bg b-b b-light" value="<?=$extra;?>" placeholder="Info. extra" name="pMextra[]" autocomplete="false">
				    	    								<?php
			    	    									}
				    	    							?>
				    	    							
			    	    							</td>
			    	    							<td class="text-right font-bold">
			    	    								<?php
			    	    								if(isInvoiceEditable()){
			    	    								?>
			    	    									<input class="form-control text-right maskCurrency no-border no-bg b-b b-light" value="<?=formatCurrentNumber($payment['total'])?>"  placeholder="Monto" name="pMamount[]" autocomplete="false">
			    	    								<?php
			    	    								}else{
			    	    									echo formatCurrentNumber($payment['total']);
			    	    								}
			    	    								?>
			    	    							</td>
			    	    						</tr>
			    							<?php
			    							}
			    						}

			    						if(isInvoiceEditable()){
			    					?>
			    						<tr>
			    							<td> <?php echo selectInputGenerator($tPtypes,['class'=>'selectSimple b-light','name'=>'pMtype[]','allowNone'=>'1']);?></td>
			    							<td><input class="form-control no-border b-b no-bg b-light" value="" placeholder="Info. extra" name="pMextra[]"></td>
			    							<td class="text-right" autocomplete="false">
			    								<input class="form-control text-right maskCurrency no-border no-bg b-b b-light" value=""  placeholder="Monto" name="pMamount[]" autocomplete="false">
			    							</td>
			    						</tr>
		    						<?php
		    						}
		    						?>
		    						</table>
		    						<input type="hidden" name="haspayments" value="1">
					            <?php }else if($tSaleType == 9){//cotizacion ?>

					                <div class="text-center font-bold">Facturas asociadas</div>
					                <table class="table">
					                <?php
					                  $cotiz = $db->Execute('SELECT transactionId, transactionTotal, transactionDate, invoiceNo, invoicePrefix FROM transaction WHERE transactionParentId = ? AND transactionType IN(0,3) AND '.$SQLcompanyId,array(dec($id)));
					                  
					                   while (!$cotiz->EOF) {
					                    $cotiza = $cotiz->fields;
					                  ?>
					                    <tr data-id="<?=enc($cotiza['transactionId'])?>" data-ro="1">
					                      <td><?=$cotiza['invoicePrefix'] . $cotiza['invoiceNo']?></td>
					                      <td><?=niceDate($cotiza['transactionDate'])?></td>
					                      <td class="text-right"><?=formatCurrentNumber($cotiza['transactionTotal'])?></td>
					                    </tr>
					                  <?php
					                    $cotiz->MoveNext(); 
					                  }
					                  $cotiz->Close();
					                ?>
					                </table>

			    				<?php }else if($tSaleType == 3){ 

			                    	$credit = ncmExecute('SELECT * FROM transaction WHERE transactionParentId = ? AND transactionType = 5 AND ' . $SQLcompanyId,[dec($id)],false,true);
			                    	$totalPaid = 0;
			                    	?>

				                    <table class="table">
				                      <tr class="font-bold text-u-c">
				                        <td colspan="4" class="text-center">
				                          Pagos
				                        </td>
				                      </tr>
				                      <?php 
				                      if($credit){
					                      while (!$credit->EOF) {
					                        $crediDate = explodes(' ',$credit->fields['transactionDate']);
					                        $crediDate = $crediDate[0];
					                        $totalPaid += $credit->fields['transactionTotal'];

					                        //payment methods
											$paymentType 	= getPaymentMethodsInArray($credit->fields['transactionPaymentType']);
											$pMethods 		= '';

											if(validity($paymentType,'array')){
												foreach($paymentType as $pType){		
													$pMethods .= '<span class="label bg-light m-r-xs" data-toggle="tooltip" data-placement="top" title="'.formatCurrentNumber($pType['price']).'">' . iftn(getPaymentMethodName($pType['type']),$pType['name']) . '</span>';
												}
											}
											//
					                      ?>
					                        <tr>
					                          <td class="text-left">
					                            <?=niceDate($credit->fields['transactionDate'])?>  
					                          </td>
					                          <td class="text-left">
					                            <?=$pMethods?>  
					                          </td>
					                          <td class="text-right">
					                            <?=CURRENCY . formatCurrentNumber($credit->fields['transactionTotal'])?>
					                          </td>
					                          <td class="text-right">
					                            <?php
					                            if(isInvoiceEditable()){
					                            ?>
					                              <a href="?action=delete&outlet=<?=enc($tOutlet)?>&id=<?=enc($credit->fields['transactionId']);?>&type=<?=enc($credit->fields['transactionType'])?>&parent=<?=$id?>" class="hidden-print deleteTransaction <?=(ROLE_ID > 1)?'disabled':''?>" data-payment="<?=enc($credit->fields['transactionId']);?>"><i class="material-icons text-danger">close</i></a>
					                            <?php
					                            }
					                            ?>
					                          </td>
					                        </tr>
					                        <?php 
					                          $credit->MoveNext(); 
					                        }
					                        $credit->Close();
					                    }else{
					                    	echo '<tr><td class="text-center">No hay pagos asociados</td></tr>';
					                    }
				                      ?>
				                    </table>

				                    <?php
				                    	$deudaTotal = $totalSale-$totalPaid;
				                    ?>

				                    <div class="col-xs-12 no-padder text-center m-b m-t">
				                      <div class="col-sm-4 no-padder b-r b-light">
				                        <div class="h4 font-bold">
				                          <?=formatCurrentNumber($totalSale);?>
				                        </div>
				                        Total
				                      </div>
				                      <div class="col-sm-4 no-padder">
				                        <div class="h4 font-bold">
				                          <?=formatCurrentNumber($totalPaid);?>
				                        </div>
				                        Pagado
				                      </div>
				                      <div class="col-sm-4 no-padder b-l b-light">
				                        <div class="h4 font-bold">
				                          <?=formatCurrentNumber($deudaTotal);?>
				                        </div>
				                        Deuda
				                      </div>
				                    </div>

									<?php
										if($deudaTotal < 0.01 && $tComplete < 1){//si el total pagado es mayor a la cuenta total cierro la factura a credito
											$db->AutoExecute('transaction', ['transactionComplete' => '1'], 'UPDATE', 'transactionId = ' . dec($id));
										}

									} 
									?>
		    				</div>

		    				<?php
		    				$returns = ncmExecute('SELECT * FROM transaction WHERE transactionType = 6 AND transactionParentId = ? AND companyId = ?',[$result['transactionId'],COMPANY_ID],false,true);
		    				if($returns){
		    				?>
		    				<div class="text-center m-b-md no-border panel bg-light lter r-3x col-xs-12 table-responsive">
		    					<table class="table text-left">
		    						<tr class="font-bold text-u-c">
				                        <td colspan="3" class="text-center">
				                          Notas de Crédito
				                        </td>
				                    </tr>
		    					<?php
		    					while (!$returns->EOF) {
		    						?>
		    						
					                    <tr class="subModal pointer" data-id="<?=enc($returns->fields['transactionId'])?>">
					                    	<td>#<?=formatQty($returns->fields['invoiceNo'])?></td>
	    	    							<td><?=niceDate($returns->fields['transactionDate'])?></td>
	    	    							<td class="text-right font-bold"><?=formatCurrentNumber($returns->fields['transactionTotal'])?></td>
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

		    				<!--TUS FACTURAS-->
		    				<?php
		    				if($_modules['tusfacturas'] && isInvoiceEditable(['blockOnRO'=>true])){
		    				?>
		    				<div class="col-xs-12 no-padder hidden-print" id="holaFacturaForm">
		    					<div class="col-xs-12 text-center">
		    						<img src="https://vousys.nyc3.digitaloceanspaces.com/tusfacturas/web/images/logo-tus-facturas-electronicas-color.png" height="60">
		    					</div>

		    					<?php
		    					$TFisPDF = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyType = \'tusFacturas\' AND sourceId = ? AND companyId = ? LIMIT 1',[$result['transactionId'],COMPANY_ID]);

		    					if($TFisPDF){
		    						?>
		    						<div class="col-xs-12 m-b-lg">
		    							<div class="col-xs-12 wrapper gradBgYellow b r-3x m-t text-center">
		    								<div class="text-u-c font-bold m-b text-default">Esta transacción ya fue enviada a TusFacturas</div>
		    								<a href="<?=$TFisPDF['taxonomyName']?>" class="btn btn-lg rounded btn-info font-bold text-u-c" target="_blank">Ver PDF</a>
		    							</div>
		    						</div>
		    						<?php
		    					}else{
		    					?>

		    					<div class="col-xs-6">
		    						<!--Tipo de Impuesto-->
		    						<?php
		    						//echo selectInputGenerator(['0' => 'IVA RG 3337','1' => 'Impuesto Nacional','2' => 'Percepción de IIBB','3' => 'Impuesto Municipal','4' => 'Impuesto Interno','99' => 'Otro Impuesto'],['name'=>'IdTipoImpuesto','class'=>'m-b']);
		    						?>
		    						<label class="font-bold text-u-c text-xs">Tipo de IVA</label>
		    						<?php
		    						echo selectInputGenerator(['CF' => 'Consumidor Final','E' => 'Exento','M' => 'Responsable Monotributo','RI' => 'Responsable Inscripto','CDEX' => 'Cliente del Exterior'],['name'=>'IdTipoIVA','class'=>'m-b no-bg no-border b-b']);
		    						?>

		    						<label class="font-bold text-u-c text-xs">Fecha</label>
		    						<input type="text" class="form-control datepicker no-border b-b m-b text-center pointer" name="tfdate" value="<?=$tDate?>" autocomplete="off" />

		    						<label class="font-bold text-u-c text-xs">Nro. Factura</label>
		    						<input type="text" name="tfinvoiceno" value="<?=$result['invoiceNo']?>" class="form-control text-right no-bg no-border b-b">
		    						
		    					</div>
		    					<div class="col-xs-6">
		    						<label class="font-bold text-u-c text-xs">Tipo de Comprobante</label>
		    						<?php
		    						echo selectInputGenerator(['FACTURA B' => 'FACTURA B','FACTURA A' => 'FACTURA A','NOTA DE DÉBITO A' => 'NOTA DE DÉBITO A','NOTA DE CRÉDITO A' => 'NOTA DE CRÉDITO A','RECIBO A' => 'RECIBO A','NOTA DE DÉBITO B' => 'NOTA DE DÉBITO B','NOTA DE CRÉDITO B' => 'NOTA DE CRÉDITO B','RECIBO B' => 'RECIBO B','FACTURA C' => 'FACTURA C','NOTA DE DÉBITO C' => 'NOTA DE DÉBITO C','NOTA DE CRÉDITO C' => 'NOTA DE CRÉDITO C','RECIBO C' => 'RECIBO C','FACTURA M' => 'FACTURA M','NOTA DE DÉBITO M' => 'NOTA DE DÉBITO M','NOTA DE CRÉDITO M' => 'NOTA DE CRÉDITO M','RECIBO M' => 'RECIBO M'],['name'=>'IdTipoComprobante','class'=>'m-b no-bg no-border b-b']);
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
		    						echo selectInputGenerator(['DNI' => 'DNI','CUIL' => 'CUIL','CUIT' => 'CUIT','CDI' => 'CDI','LE' => 'LE','LC' => 'LC','CI Extranjera' => 'CI Extranjera','Pasaporte' => 'Pasaporte','Doc. (Otro)' => 'Doc. (Otro)'],['name'=>'IdTipoDocumento','class'=>'m-b no-bg no-border b-b']);
		    						?>
		    					</div>
		    					<div class="text-center col-xs-12 m-b-md m-t-md">
		    						<a href="#" class="btn btn-primary btn-lg text-u-c btn-rounded font-bold" data-url="/thirdparty/tusfacturas/tusfacturas?s=<?=base64_encode(enc(COMPANY_ID) . ',' . $id)?>" id="sendToHolaFactura" data-id="<?=$id;?>">Enviar a TusFacturas</a>
		    					</div>

		    					<div id="TFerrorbox" style="display: none;" class="col-xs-12 wrapper bg-light b r-3x m-t"></div>
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
							if($tLocation){
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

					<?php
			    	if(isInvoiceEditable()){
			    	?>
			    	<div class="col-xs-12 hidden-print wrapper bg-light lter" style="margin-top:110px;">
			    		<button class="btn btn-info btn-lg btn-rounded font-bold text-u-c pull-right">Guardar</button>
			    		<a href="#" class="m-t cancelItemView m-r-lg pull-right">Cerrar</a>
			    		<a href="#" class="m-t print m-r-lg pull-right hidden-xs" data-element="#factura">Imprimir</a>
							    
					    <a href="?action=delete&outlet=<?=enc($tOutlet)?>&id=<?=$id;?>&type=<?=enc($result['transactionType'])?>" class="m-t m-r pull-left deleteTransaction <?=(ROLE_ID > 1)?'disabled':''?>"><span class="text-danger">Eliminar</span></a>
					    <input type="hidden" name="id" value="<?=$id;?>">
					    <input type="hidden" name="type" value="<?=enc($tSaleType);?>">
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

if(validateHttp('action') == 'paymentForm'){
	if(!validateHttp('id')){
		dai('false');
	}

	$id 		= validateBool('id');
	
	$result 	= ncmExecute("SELECT * FROM transaction WHERE transactionId = ? LIMIT 1",[dec($id)]);

	$deuda 		= $result['transactionTotal'];
	$payments 	= ncmExecute('SELECT * FROM transaction WHERE transactionParentId = ? AND companyId = ?',[$result['transactionId'],COMPANY_ID],false,true,true);

	if(validity($payments,'array')){
		$totalPaid = 0;
		foreach($payments as $key => $paymnt){
			$totalPaid += $paymnt['transactionTotal'];
		}
		$deuda = $result['transactionTotal']-$totalPaid;					
	}
	
    ?>
    
    <div class="col-xs-12 no-padder bg-white r-3x clear">
	    <form action="/report_purchases?action=addPayment" method="POST" id="addPaymentForm" name="addPaymentForm">
			<div class="col-xs-12 text-center m-t">
				<div class="">Deuda Total</div>
		        <div class="font-bold h2"><?=CURRENCY . formatCurrentNumber($deuda)?></div>
		    </div>

		    <div class="col-xs-12 m-t m-b">
				<label class="text-u-c font-bold">Monto a abonar</label>
		        <input type="text" class="maskCurrency form-control input-lg" name="payAmount" value="<?=formatCurrentNumber($deuda)?>" id="payAmountField">

			    <!--<label class="font-bold m-t text-u-c">Método de Pago</label>
	            <?php $pM = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = \'paymentMethod\' AND ' . $SQLcompanyId . ' ORDER BY taxonomyName ASC',[],false,true); ?>
	            <select id="paymentMethod" name="paymentMethod" tabindex="1" data-placeholder="Seleccione" class="form-control search" autocomplete="off">
	               <option value="cash">Efectivo</option>
	               <option value="creditcard">T. Crédito</option>
	               <option value="debitcard">T. Débito</option>
	               <option value="check">Cheque</option>
	              <?php 
	                if($pM){
	                  while (!$pM->EOF) {
	                    $pMId = enc($pM->fields['taxonomyId']);
	              ?>
	                    <option value="<?=$pMId;?>">
	                      <?=$pM->fields['taxonomyName'];?>
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
	    	if(isInvoiceEditable()){
	    	?>

	    	<div class="col-xs-12 wrapper bg-light lter text-center">
				<button class="btn btn-info btn-lg btn-rounded text-u-c font-bold">Pagar</button>
				<input type="hidden" name="id" value="<?=$id;?>">
				<input type="hidden" name="debt" value="<?=$deuda;?>">
			</div>

			<?php
			}
			?>
	    </form>
    </div>

    <?php
	dai();
}

if(validateHttp('action') == 'download-report'){
	
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
	if(validateHttp('cusId')){
		$cid = $db->Prepare(dec(validateHttp('cusId')));
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
								DESC", [$startDate,$endDate],false,true);

	$var 		= [];
	$array 		= [];

	$isCreditSale 		= false;
	$No 				= validateResultFromDB($saleDay,true);

	if($saleDay){
		$excellRow[]  = ['ID','# DOCUMENTO','FECHA','CLIENTE','USUARIO','SUCURSAL','CAJA','M. DE PAGO','NOTA','ETIQUETAS','TIPO','DESCUENTO','SUBTOTAL',TAX_NAME,'TOTAL'];

		//$allToPayTransactions 	= getAllToPayTransactions();
		$getAllOutlets 			= getAllOutlets();
		$getAllRegisters 		= getAllRegisters();
		//$payment 				= getAllTransactionPayments();
		$cachedContact 			= [];
		$cachedUser 			= [];

		while (!$saleDay->EOF) {
			$fields 				= $saleDay->fields;
			$tTotal 				= ($fields['transactionTotal']<=0)?0:$fields['transactionTotal'];

			if(!$cachedContact[$fields['customerId']]){
				$customer 				= getContactData($fields['customerId'],'uid',true);
				$cachedContact[$fields['customerId']] = $customer;
			}else{
				$customer = $cachedContact[$fields['customerId']];
			}
			
			$custName 				= $customer['name'];
			$user 					= getContactData($fields['userId'],false,true);
			$paymentType 			= json_decode($fields['transactionPaymentType'],true);
			$pMethods 				= '';

			if(validity($paymentType)){
				foreach ($paymentType as $value) {
					$pMethods .= $value['name'].': '.formatCurrentNumber($value['price']).' | ';
				}
			}

			$excellRow[]  = [
                            enc($fields['transactionId']),
                            $fields['invoicePrefix'] . $fields['invoiceNo'],
                            $fields['transactionDate'],
                            ($customer == '0') ? '' : $custName,
                            $user['name'],
                            $getAllOutlets[$fields['outletId']]['name'],
                            $getAllRegisters[$fields['registerId']]['name'],
                            $pMethods,
                            $fields['transactionNote'],
                            printOutTags(json_decode($fields['tags']),'',true),
                            ($fields['transactionType'] == '3') ? 'Credito' : 'Contado',
                            formatCurrentNumber($fields['transactionDiscount']),
                            formatCurrentNumber($tTotal),
                            formatCurrentNumber($fields['transactionTax']),
                            formatCurrentNumber($tTotal-$fields['transactionDiscount'])
                          ];

			$netTotal = $tTotal - $fields['transactionDiscount'];

			//Products
			$excellRow[] = ['CANTIDAD','ARTICULO','PRECIO','','','','','','','','','','','',''];

			$arr 		= json_decode($fields['transactionDetails'],true);
			foreach ($arr as $value) {
				$excellRow[] = [$value['count'],$value['name'],$value['price'] * $value['count'],'','','','','','','','','','','',''];
			}
			//Products

			if($fields['transactionType'] == '3'){
				$excellRow[] = ['FECHA DE COBRO','USUARIO','METODOS DE PAGO','MONTO COBRADO','','','','','','','','','','',''];

				$pay = $payment[$fields['transactionId']];

				if($pay){
					$totalPaid = 0;
					
					foreach($pay as $key => $paymnt){
						

						if(!$cachedContact[$fields['customerId']]){
							$userIs 		= getContactData($paymnt['userid'],false,true);
							$cachedUser[$paymnt['userid']] = $userIs;
						}else{
							$userIs = $cachedUser[$paymnt['userid']];
						}

						$userNameIs 	= $userIs['name'];
						$totalPaid 		+= $paymnt['total'];
						$pMethods  		= '';
						$paymentType 	= json_decode($paymnt['methods'],true);
						
						if(validity($paymentType)){
							foreach ($paymentType as $value) {
								$pMethods .= $value['name'].': '.formatCurrentNumber($value['price']).' | ';
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
									formatCurrentNumber($netTotal - $totalPaid),'','','','','','','','','','',''
								];
				}else{
					$excellRow[] = ['SIN PAGOS AUN','','','','','','','','','','','','','','',''];
				}
			}
			$excellRow[] = ['','','','','','','','','','','','','','',''];

			$saleDay->MoveNext();
		}

		if(!$_GET['test']){
			generateXLSfromArray($excellRow,'full_sales_reports_' . date("d-m-Y"));
		}else{
			print_r($array);
		}

		$saleDay->Close();
	}

	dai();
}

if(validateHttp('action') == 'tusFacturas'){

	if(validateHttp('url') && validateHttp('id')){
		$record['taxonomyName'] = validateHttp('url');
		$record['taxonomyType'] = 'tusFacturas';
		$record['sourceId'] 	= dec(validateHttp('id'));
		$record['companyId'] 	= COMPANY_ID;
		$record['outletId'] 	= OUTLET_ID;

		$insert 	= $db->AutoExecute('taxonomy', $record, 'INSERT'); 
	}

	dai();
}
?>
<!DOCTYPE html>
<html class="no-js clear">
<head>
<!-- meta -->
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
<title>Transacciones y Pagos</title>

<?php //echo maintaining()?>
<?php
loadCDNFiles([
			'/assets/vendor/css/bootstrap-datetimepicker-4.17.45.min.css',
			'/assets/vendor/css/select2-4.0.6.min.css',
			'/assets/vendor/css/select2-bootstrap-0.1.0.min.css'
			],'css');
?>

</head>
<body class="bg-light lter">

	<?=menuFrame('top',true);?>
	<?=menuReports('',false);?>
    
  	<?php
  	if(validateHttp('ci')){
  		$cId 	= $db->Prepare(dec(validateHttp('ci')));
  		$cData 	= getContactData($cId, 'uid');
  		$name 	= getCustomerName($cData);
  		echo reportsTitle('Historial de ' . $name, true,false,false,true);
  	}else{
  		echo reportsTitle('Transacciones y Pagos', true,'/panel-de-control/reportes/reporte-de-ventas-1/transacciones',false);
  	}
	
  	?>

  	<div class="col-xs-12 no-padder m-t m-b-lg b-t push-chat-down">

        <section class="col-xs-12 no-padder" id="">

        	<ul class="nav nav-tabs padder hidden-print">
	            <li class="active">
	                <a href="#tab1" data-toggle="tab">Transacciones</a>
	            </li>
	            <li class="" id="cobrosTab">
	                <a href="#tab2" data-toggle="tab">Pagos Recibidos</a>
	            </li>
	        </ul>

	        <section class="panel r-3x">
	            <div class="panel-body table-responsive">

	                <div class="tab-content m-b-lg">
	                    <div class="tab-pane overflow-auto active" id="tab1">
	                    	<div id="detailTable">                             	
					        	<table class="table m-t table1 table-hover hover" id="tableTransactions">
					            	<?=placeHolderLoader('table')?>
					            </table>
					        </div>
	                    </div>

	                    <div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab2">
	                    	<div id="cobrosTable">
                            	<table class="table table2 table-hover hover col-xs-12 no-padder" id="tablePayments">
                            		<?=placeHolderLoader('table')?>
                            	</table>
                            </div>
	                    </div>
	                </div>

	            </div>
	        </section>

        </section>
	</div>

	<?=menuFrame('bottom');?>

	<div class="modal fade" id="modalDetails" role="dialog">
	  <div class="modal-dialog modal-lg">
	    <div class="modal-content r-3x no-bg no-border all-shadows">
	      <div class="modal-body wrapper">
	        
	      </div>
	    </div>
	  </div>
	</div>
    
  <?php
  footerInjector();
  loadCDNFiles([
				'/assets/vendor/js/jQuery.print-1.5.1.min.js',
				'/assets/vendor/js/select2-4.1.0.min.js',
				'/assets/vendor/js/select2-i18n-es.min.js',
				'/assets/vendor/js/Chart-2.9.4.min.js',
				'/assets/vendor/js/bootstrap-datetimepicker-4.17.47.min.js',
				'/assets/vendor/js/simpleStorage-0.2.1.min.js'
				],'js');
  ?>

<script id="ncmScript">
$(document).ready(function(){

	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>",false,true);

	var rawUrl 		= "?action=detailTable";
	var loadUrl 	= rawUrl + "&from=<?=$startDate?>&to=<?=$endDate?>&cusId=<?=validateHttp('ci')?>";

	$.get(loadUrl,function(result){
		
		window.info2 = {
					"container" 	: "#detailTable",
					"url" 			: loadUrl,
					"rawUrl" 		: rawUrl,
					"table" 		: ".table1",
					"iniData" 		: result.table,
					"sort" 			: 2,
					"footerSumCol" 	: [15,16,17,18,19],
					"currency" 		: "<?=CURRENCY?>",
					"decimal" 		: decimal,
					"thousand" 		: thousandSeparator,
					"offset" 		: <?=$offsetDetail?>,
					"limit" 		: <?=$limitDetail?>,
					"nolimit" 		: true,
					"noMoreBtn" 	: <?=validateHttp('ci') ? 'true' : 'false'?>,
					"ncmTools"			: {
											left 	: 	'<a href="#" class="btn btn-default exportTable" data-table="tableTransactions" data-name="Transacciones">Exportar Listado</a>' +
														'<a class="btn btn-default" id="reportDownload" href="#" data-toggle="tooltip" data-placement="bottom" title="Descargar reporte detallado completo"><i class="material-icons">file_download</i></a>',
											right 	: '<input type="text" class="form-control rounded no-border bg-light lter <?=validateHttp('ci')?'hidden':'';?>" placeholder="Buscar por Nombre, <?=TIN_NAME?> o # Doc." id="detailTableSearch" data-url="' + rawUrl + '&qry=">'
										  },
					"colsFilter"		: {
											name 		: 'reportTransactions1',
											menu 		:  [
															{"index":0,"name":'ID',"visible":false},
															{"index":1,"name":'Nro. Autorización',"visible":false},
															{"index":2,"name":'Nro. Documento',"visible":false},
															{"index":3,"name":'Fecha',"visible":true},
															{"index":4,"name":'Hora',"visible":false},
															{"index":5,"name":'Vencimiento',"visible":false},
															{"index":6,"name":'Cliente',"visible":true},
															{"index":7,"name":'<?=TIN_NAME?>',"visible":false},
															{"index":8,"name":'Usuario',"visible":false},
															{"index":9,"name":'Sucursal',"visible":false},
															{"index":10,"name":'Caja',"visible":false},
															{"index":11,"name":'M. de Pagos',"visible":false},
															{"index":12,"name":'Nota',"visible":false},
															{"index":13,"name":'Etiquetas',"visible":false},
															{"index":14,"name":'Tipo',"visible":true},
															{"index":15,"name":'Descuento',"visible":false},
															{"index":16,"name":'Subtotal',"visible":false},
															{"index":17,"name":'<?=TAX_NAME?>',"visible":false},
															{"index":18,"name":'Total Gravado',"visible":false},
															{"index":19,"name":'Total',"visible":true}
															]
										  }
		};

		manageTableLoad(window.info2,function(oTable){
			loadTheTable(window.info2,oTable);
		});

		onClickWrap('.cancelItemView',function(event,tis){
			$('#modalDetails').modal('hide');
		});

		onClickWrap('.toggleDates',function(event,tis){
			var target = tis.attr('data-target');
			$(target).toggle();
		});

		onClickWrap('#reportDownloadGeneral',function(event,tis){
			var url = '?action=generalTable&download-report=true&from=<?=$startDate?>&to=<?=$endDate?>&cusId=<?=validateHttp('ci')?>';
			window.open(url);
		});

		onClickWrap('.switchToInput',function(event,tis){
			var switchit = tis.data('switch');
			$(switchit).show().removeClass('hidden');
			tis.hide().addClass('hidden');
		});

		onClickWrap('.filterTypes',function(event,tis){
			var filter 	= tis.data('filter');
			var $input 	= $('#detailTable .dataTables_filter input');
			autoFilterInputTable($input,filter);
		});

		onClickWrap('.print',function(event,tis){
			var type = tis.data('type');
			if(type == 'paymentmethod'){
				$('#modalItemsTable .select2').hide();
				$('#paymentMethodList').print();
				$('#modalItemsTable .select2').show();
			}
		});	
	});

	var loadTheTable = function(tableOps,otable){
		var filterDetails = "<?=validateBool('detail') ? $_GET['detail'] : 'false'?>";

		if(filterDetails != 'false'){
			var e = jQuery.Event("keyup");
			e.which = 50; // # Some key code value
			$('#DataTables_Table_1_filter input').val(filterDetails).trigger(e);
		}

		onClickWrap('.table1 tbody tr,.table2 tbody tr',function(event,tis){
			var ro 		= iftn(tis.data('ro'),'','&ro=1');
			var load 	= '?action=edit&id=' + tis.data('id') + ro;
			$('.editting').removeClass('editting');

			tis.addClass('editting');
			loadForm(load,'#modalDetails .modal-content',function(){
				$('#modalDetails').modal('show');
			});
		},false,true);

		onClickWrap('.subModal',function(event,tis){
			var ro 		= iftn(tis.data('ro'),'','&ro=1');
			var load 	= '?action=edit&id=' + tis.data('id') + ro;

			$('#modalDetails').modal('hide');

			loadForm(load,'#modalDetails .modal-content',function(){
				$('#modalDetails').modal('show');
			});
		},false,true);

		onClickWrap('#reportDownload',function(event,tis){
			var url = '?action=download-report&from=<?=$startDate?>&to=<?=$endDate?>';
			window.open(url);
		},false,true);

		onClickWrap('.deleteTransaction',function(event,tis){
			var url        = tis.attr('href');
			var r          = confirm("Desea eliminar esta transacción?.");
			var payment    = tis.data('payment');
			var $row       = $('.editting');
			if (r == true) {

				oTable.row($row).remove().draw();
                $('#modalDetails').modal('hide');

				$.get(url, function( data ) {
					if(data == 'true'){
						message('La transacción fue eliminada con éxito.','success');
	                    if(payment){
	                      $.get('?action=detailTable&part=1&singleRow=' + $row.data('id'),function(data){
	                        oTable.row($row).remove();
	                        if(data){
		                        oTable.row.add($(data));
		                    }
		                    oTable.draw();
	                      });
	                    }
					}else{
						message('Error, no pudimos eliminar la transacción','danger');
					}
				});
			}
		},false,true);

		onClickWrap('#sendToHolaFactura',function(event,tis){
			tis.addClass('disabled').text('Enviando...');

			var formData = {
		        'IdTipoImpuesto'        : $('select[name=IdTipoImpuesto]').val(),
		        'IdTipoIVA'             : $('select[name=IdTipoIVA]').val(),
		        'IdTipoComprobante'    	: $('select[name=IdTipoComprobante]').val(),
		        'IdConcepto'    		: $('select[name=IdConcepto]').val(),
		        'IdCondicionVenta'    	: $('select[name=IdCondicionVenta]').val(),
		        'IdTipoDocumento'    	: $('select[name=IdTipoDocumento]').val(),
		        'PuntoDeVenta' 			: $('input[name=tfpointofsale]').val(),
		        'NroFactura' 			: $('input[name=tfinvoiceno]').val(),
		        'Fecha' 				: $('input[name=tfdate]').val()
		    };

		    $('#TFerrorbox').hide();

		    var id 	= tis.data('id');
		    var url = tis.data('url');
		    //var tis = $('#holaFacturaForm');
			
			console.log('enviando a',url);
			$.ajax({
		        type        : 'POST',
		        url         : url, // the url where we want to POST
		        data        : formData, // our data object
		        //dataType    : 'json'
		    }).done(function(data) {
		        console.log(data);
		        var out = '';
		        if(data.error == 'S'){
		        	$.each(data.errores,function(k,v){
		        		out += '- ' + v + '<br>';
		        	});

		        	$('#TFerrorbox').html(out).show();
		        }else{
		        	if(data.comprobante_pdf_url){
			        	$('#TFerrorbox').html('<a href="' + data.comprobante_pdf_url + '" target="_blank">Facturada enviada</a>').show();
			        	$.get('?action=tusFacturas&url=' + data.comprobante_pdf_url + '&id=' + id);
			        }else{
			        	$('#TFerrorbox').html('No se pudo generar el PDF').show();
			        }
		        	//
		        }

		        tis.removeClass('disabled').text('Enviar a tusfacturas');
		    });
		},false,true);

		submitForm('#editSale',function(tis,result){
			if(result){
				$('#modalDetails').modal('hide');
				var $row = $('.editting');
				$.get('?action=detailTable&part=1&singleRow=' + result,function(data){
					oTable.row($row).remove();
					if(data){
						oTable.row.add($(data));
					}
					oTable.draw();
				});
			}
		});

		$('#modalDetails').on('shown.bs.modal', function() {
			var where = '.loadCustomerInput';
			var match = $(where).data('match');
			select2Ajax({element:'.selectCustomer',url:'/contacts?action=searchCustomerInputJson',type:'contact'});
			select2Ajax({
							element 	: '.selectItem', 
							url 		: '/items?action=searchItemInputJson',
							type 		: 'item', 
							onChange 	: function(tis,data){
								var id 	= tis.data('id');
								var uid = tis.val();
								if(id){
									var url = '?action=updateItem&id=' + id + '&uid=' + uid;
									$.get(url,function(result){
										if(result){
											message('Actualizado','success');
										}else{
											message('Error al actualizar','danger');
										}
									});
								}
							}
						});
			
			$('.datepicker').datetimepicker({
		    	format            : 'YYYY-MM-DD HH:mm:ss',
		        showClear         : true,
		    	ignoreReadonly    : true
		    });

		    select2Simple('.selectSimple',$('#modalDetails'));
		    select2Simple('.selectUser',$('#modalDetails'),function(tis,data){
				var id 	= tis.data('id');
				var uid = tis.val();
				if(id){
					var url = '?action=updateItemUser&id=' + id + '&uid=' + uid;
					$.get(url,function(result){
						if(result){
							message('Actualizado','success');
						}else{
							message('Error al actualizar','danger');
						}
					});
				}
			});

		    select2Simple($('select.selectTags'),$('#modalDetails'));

		    masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
		    masksCurrency($('.maskQty'),thousandSeparator,'yes',false,'2');

		    $('.changeItem').on('change',function(){
		    	var tis = $(this);
		    	var id 	= tis.data('id');
				var uid = tis.val();
				if(id){
					var url = '?action=updateItemUser&id=' + id + '&uid=' + uid;
					$.get(url,function(result){
						if(result){
							message('Actualizado','success');
						}else{
							message('Error al actualizar','danger');
						}
					});
				}
		    });

	  		onClickWrap('.print',function(event,tis){
				var el = tis.data('element');
				$(el).print();
			},false,true);
		});

	    var srcValCache = '';
	    $('#detailTableSearch').on('keyup',function(e){
	    	var $tis 	= $(this);
	    	var value 	= $tis.val();
	    	var tmout 	= 800;
	    	var code 	= e.keyCode || e.which;

	    	if(code == 13) { //Enter keycode
		    	if(value.length > 3){
		    		if(!$.trim(value) || srcValCache == value){
		    			return false;
		    		}

	    			spinner(tableOps.container, 'show');
	    			$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1',function(result){
	    				oTable.rows().remove();
	    				if(result){
	    					var line 	= explodes('[@]',result);
	    					$.each(line,function(i,data){
	    						if(data){
	                    			oTable.row.add($(data));
	                    		}
	    					});
	    				}

	    				oTable.draw();

	    				$('.lodMoreBtnHolder').addClass('hidden');
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
	};

	//Tabla de cobros
	window.cobrosTableOpen = false;
	$('#cobrosTab').on('shown.bs.tab', function (e) {
	    if(!window.cobrosTableOpen){
	    	var rawUrl2 	= "?action=cobrosTable";
			var loadUrl2 	= rawUrl2 + "&from=<?=$startDate?>&to=<?=$endDate?>&sale=<?=$saletype?>&cusId=<?=validateHttp('ci')?>";
	    	$.get(loadUrl2,function(result){
	    		window.info3 = {
					"container" 	: "#cobrosTable",
					"url" 			: loadUrl2,
					"rawUrl" 		: rawUrl2,
					"table" 		: ".table2",
					"iniData" 		: result.table,
					"sort" 			: 0,
					"footerSumCol" 	: [8],
					"currency" 		: "<?=CURRENCY?>",
					"decimal" 		: decimal,
					"thousand" 		: thousandSeparator,
					"offset" 		: <?=$offsetCobros?>,
					"limit" 		: <?=$limitCobros?>,
					"nolimit" 		: true,
					"noMoreBtn" 	: <?=validateHttp('ci') ? 'true' : 'false'?>,
					"ncmTools"		: {
										left 	: '<a href="#" class="btn btn-default exportTable" data-table="tablePayments" data-name="Pagos">Exportar Listado</a>',
										right 	: '<input type="text" class="form-control rounded no-border bg-light lter <?=validateHttp('ci')?'hidden':'';?>" placeholder="Buscar por Nombre o <?=TIN_NAME?>" id="paymentTableSearch" data-url="' + rawUrl2 + '&qry=">'
									  }
				};

				manageTableLoad(window.info3,function(oTable){
					loadTheTable2(window.info3,oTable);
				});
			});
			
			var loadTheTable2 = function(tableOps,oTable){
				onClickWrap('.deletePayment',function(event,tis){
					var url        = tis.attr('href');
					var r          = confirm("Desea eliminar este pago?.");
					var $row       = tis.closest('tr');

					if (r == true) {
					   $.get(url, function( data ) {
							if(data == 'true'){
								message('Pago eliminado.','success');
			                    oTable.row($row).remove().draw();
							}else{
								message('Error, no pudimos eliminar el pago','danger');
							}
						});
					}
				});

				var timout 		= false;
			    var srcValCache = '';
			    $('#paymentTableSearch').on('keyup',function(e){
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
			    				oTable.rows().remove();
			    				if(result){
			    					var line 	= explodes('[@]',result);
			    					$.each(line,function(i,data){
			    						if(data){
			                    			oTable.row.add($(data));
			                    		}
			    					});
			    				}

			    				oTable.draw()

			    				$('.lodMoreBtnHolder').addClass('hidden');
			    				spinner(tableOps.container, 'hide');
				    		});
				    		

				    		srcValCache = value;

				    	}else if(value.length < 1 || !value){
				    		srcValCache = '';
			    			manageTableLoad(tableOps,function(oTable){
								loadTheTable2(tableOps,oTable);
							});
				    	}
				    }
			    });
			};

			window.cobrosTableOpen = true;
		}
	});

});

</script>

</body>
</html>
<?php
include_once('includes/compression_end.php');
dai();
?>