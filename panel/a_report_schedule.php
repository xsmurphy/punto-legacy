<?php
include_once('includes/top_includes.php');
topHook();
allowUser('sales','view');

$MAX_DAYS_RANGE = 31;
$baseUrl 		= '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

//DATE RANGE LIMITS FOR REPORTS
$maxDate 		= dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate 	= date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

$roc 				= getROC(1);

$maxItemsInGraph 	= 30;
$isdata 			= false;
$limitDetail		= 100;
$offsetDetail		= 0;
$limitDetailS		= 50;
$offsetDetailS		= 0;
$jsonResult 		= [];

if(validateHttp('action') == 'detailTable'){

	$limits = getTableLimits($limitDetail,$offsetDetail);
	$userId = '';
	$usrId 	= false;

	if(validateHttp('ui')){
		$usrId 	= dec(validateHttp('ui'));
		$userId = ' AND userId = ' . $usrId . ' ';
		$roc 	= ' AND companyId = ' . COMPANY_ID . ' ';
	}

	$sql = "SELECT *
			FROM transaction
			WHERE transactionType = 13 
			" . $userId . "
			AND fromDate
			BETWEEN ?
			AND ?
			" . $roc . $limits;

	if($_GET['test']){
		dai($sql);
	}

	$result 	= ncmExecute($sql,[$startDate,$endDate],true,true);

	$head = '	<thead class="text-u-c">'.
			 '		<tr>'.
			 '			<th>Estado</th>'.
			 '			<th># Doc. Venta</th>'.
			 '			<th># Agendamiento</th>'.
			 '			<th>Agendado</th>'.
			 '			<th>Sucursal</th>'.
			 '			<th>Asignado</th>'.
			 '			<th>Responsable</th>'.
			 '			<th>Cliente</th>'.
			 '			<th>Motivo</th>'.
			 '			<th>Descripción</th>'.
			 '			<th>Fecha</th>'.
			 '			<th>Inicio</th>'.
			 '			<th>Fin</th>'.
			 '			<th>Duración</th>'.
			 '			<th class="text-center">Valor</th>'.
			 '			<th class="text-center">Asistencia</th>' .
			 '			<th></th>' .
			 '		</tr>'.
			 '	</thead>'.
			 '<tbody>';
	$table = '';

	$cancelado 	= 0;
	$finalizado = 0;
	$noshow 	= 0;
	$nuevas 	= 0;
	$other 		= 0;

	if($result){
		
		$totalCitas 	= getTotalScheduleByStatus(false,$startDate,$endDate,$roc,$usrId);
		$nuevas 		= getTotalScheduleByStatus('0',$startDate,$endDate,$roc,$usrId);
		$cancelado 		= getTotalScheduleByStatus('4',$startDate,$endDate,$roc,$usrId);
		$noshow 		= getTotalScheduleByStatus('5',$startDate,$endDate,$roc,$usrId);
		$finalizado 	= getTotalScheduleByStatus('6',$startDate,$endDate,$roc,$usrId);
		$blocked 		= getTotalScheduleByStatus('7',$startDate,$endDate,$roc,$usrId);
		
		while (!$result->EOF) {
			$fields = $result->fields;

			if($fields['transactionStatus'] == 0){
				$name 	= 'Nuevo';
				$color 	= 'b-l b-light b-5x';
				$icon 	= '<i class="material-icons">stars</i>';
			}else if($fields['transactionStatus'] == 1){
				$name 	= 'Confirmado';
				$color 	= 'b-l b-info b-5x';
				$icon 	= '<i class="material-icons">thumb_up</i>';
			}else if($fields['transactionStatus'] == 2){
				$name 	= 'Llegó';
				$color 	= 'b-l b-warning b-5x';
				$icon 	= '<i class="material-icons">keyboard_arrow_down</i>';
			}else if($fields['transactionStatus'] == 3){
				$name 	= 'En proceso';
				$color 	= 'b-l b-success b-5x';
				$icon 	= '<i class="material-icons">keyboard_arrow_right</i>';
			}else if($fields['transactionStatus'] == 4){
				$name 	= 'Cancelado';
				$color 	= 'b-l b-danger b-5x';
				$icon 	= '<i class="material-icons">block</i>';
			}else if($fields['transactionStatus'] == 5){
				$name 	= 'No show';
				$color 	= 'b-l b-dark b-5x';
				$icon 	= '<i class="material-icons">person_add_disabled</i>';
			}else if($fields['transactionStatus'] == 6){
				$name 	= 'Finalizado';
				$color 	= 'b-l b-dark b-5x';
				$icon 	= '<i class="material-icons">check</i>';
			}else{
				$name 	= 'Bloqueado';
				$color 	= 'b-l b-dark b-5x';
				$icon 	= '<i class="material-icons">block</i>';
			}

			$itemId 	= enc($fields['transactionId']);
			$username 	= getContactData($fields['userId'],false,true)['name'];

			if($fields['responsibleId']){
				$respname 	= getContactData($fields['responsibleId'],false,true)['name'];
			}else{
				$respname 	= $username;
			}
			
			$customer 	= getContactData($fields['customerId'],'uid',true);
			$customer 	= getCustomerName($customer);

			$outlet 	= $allOutletsArray[$fields['outletId']]['name'];
			$fecha 		= ($fields['fromDate']) ? explodes(' ',$fields['fromDate'],true,0) : 'Sin fecha';
			$inicio		= ($fields['fromDate']) ? explodes(' ',$fields['fromDate'],true,1) : 'Sin fecha';
			$fin		= ($fields['toDate']) ? explodes(' ',$fields['toDate'],true,1) : 'Sin fecha';
			$duration  	= '00:00';

			$docn 			= '-';
			$allowDelete 	= '';
			$doc 			= false;

			if($fields['transactionParentId']){
				$doc 		= ncmExecute('SELECT invoiceNo,invoicePrefix,transactionId FROM transaction WHERE transactionId = ? LIMIT 1',[$fields['transactionParentId']]);
				$docn 		= '<a href="#" class="clickrow" data-load="/a_report_transactions?action=edit&id=' . enc($doc['transactionId']) . '&ro=true"><span class="text-info text-u-l">' . $doc['invoicePrefix'] . $doc['invoiceNo'] . '</span></a>';
				$allowDelete= 'hidden';
				if($_GET['test']){
					echo 'packet: ' . $doc['invoiceNo'] . ' ' . $fields['transactionId'] . '<br>';
				}
			}else{
				$toSchedul 	= ncmExecute('SELECT transactionUID FROM toScheduleUID WHERE scheduleId = ? LIMIT 1',[$fields['transactionId']]);
				if($toSchedul){
					$doc 	= ncmExecute('SELECT invoiceNo,invoicePrefix,transactionId FROM transaction WHERE transactionUID = ? LIMIT 1',[$toSchedul['transactionUID']]);
					$docn 	= '<a href="#" class="clickrow" data-load="/a_report_transactions?action=edit&id=' . enc($doc['transactionId']) . '&ro=true"><span class="text-info text-u-l">' . $doc['invoicePrefix'] . $doc['invoiceNo'] . '</span></a>';
					if($_GET['test']){
						echo 'non packet: ' . $doc['invoiceNo'] . ' ' . $doc['transactionId'] . '<br>';
					}
				}
			}

			if($inicio && $fin){
				$startTime 	= new DateTime($fields['fromDate']);
				$endTime 	= new DateTime($fields['toDate']);
				$interval 	= date_diff($startTime,$endTime);
				$duration 	= $interval->format('%h:%I');
			}

			$asisted 		= ncmExecute('SELECT * FROM taxonomy WHERE sourceId = ? LIMIT 1',[$fields['transactionId']],true);
			$asistIco 		= '';
			if($asisted){
				$asistIco 	= '<i class="material-icons text-success">check</i>';
			}

			$tDetail 		= json_decode( $fields['transactionDetails'], true );
			$itemsList 		= [];
			$allItems 		= getItemData($id,true);
			$itemsTXTList 	= '';

			if( validity($tDetail) ){
				foreach ($tDetail as $key => $value) {
					$itemName 		= getItemName( dec($value['itemId']) );
					$itemsList[] 	= $itemName;
				}

				$itemsTXTList = implode(' <strong>-</strong> ', $itemsList);
			}
			
			$table .= 	'<tr class="clickrow pointer row' . $itemId . '" data-id="'.$itemId.'" data-load="/a_report_transactions?action=edit&id=' . $itemId . '&ro=true">'.
						'	<td data-filter="'.$name.'" data-order="'.$fields['transactionStatus'].'" class="'.$color.'"> <span data-toggle="tooltip" data-placement="right" title="'.$name.'">'.$icon.'</span></td>'.
						'	<td>' . $docn . '</td>'. 
						'	<td>' . $fields['invoiceNo'] . '</td>'. 
						'	<td data-order="'.$fields['transactionDate'].'">'.niceDate($fields['transactionDate'],true).'</td>'. 
						'	<td>'.$outlet.'</td>'.
						'	<td>'.$username.'</td>'.
						'	<td>'.$respname.'</td>'.
						'	<td>'.$customer.'</td>'.
						'	<td>'.$fields['transactionNote'].'</td>'.
						'	<td>' . $itemsTXTList . '</td>'.
						'	<td data-order="'.$fields['fromDate'].'">'.$fecha.'</td>'.
						'	<td data-order="'.$fields['fromDate'].'">'.$inicio.'</td>'.
						'	<td data-order="'.$fields['toDate'].'">'.$fin.'</td>'.
						'	<td><span class="label bg-light">'.$duration.'</span></td>'.
						'	<td class="text-right bg-light lter" data-order="'.$fields['transactionTotal'].'">'.
								formatCurrentNumber($fields['transactionTotal']) .
						'	</td>'.
						'	<td class="text-center">' . $asistIco . '</td>' .
						'	<td class="text-center"><a href="' . $baseUrl . '?action=delete&id=' . $itemId . '" data-id="' . $itemId . '" class="delete"><i class="material-icons text-danger">close</i></a></td>' .
						'</tr>';

			if(validateHttp('part') && !validateHttp('singleRow')){
	        	$table .= '[@]';
	        }

			$result->MoveNext();
			$x++;
		}
		
	}

	$foot 	= 	'</tbody>' .
			  	'<tfoot>' .
				'	<tr>' .
				'		<th colspan="13">TOTALES:</th>' .
				'		<th class="text-right"></th>' .
				'	</tr>' . 
			  	'</tfoot>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] 	= $fullTable;
		$jsonResult['summary'] 	= 	[	
										'new' 		=> $nuevas,
										'ended' 	=> $finalizado,
										'cancelled'	=> $cancelado,
										'noshow' 	=> $noshow,
										'totals' 	=> $totalCitas,
										'blocked' 	=> $blocked,
										'newF' 		=> formatCurrentNumber($nuevas),
										'endedF' 	=> formatCurrentNumber($finalizado),
										'cancelledF'=> formatCurrentNumber($cancelado),
										'noshowF' 	=> formatCurrentNumber($noshow),
										'totalsF' 	=> formatCurrentNumber($totalCitas),
										'blockedF' 	=> formatCurrentNumber($blocked)
									];

		if($_GET['debug']){
			echo $fullTable;
			dai();
		}

		//header('Content-Type: application/json'); 
		dai(json_encodes($jsonResult,true));
	}
}

if(validateHttp('action') == 'stats'){

	$limits 	= getTableLimits($limitDetail,$offsetDetail);
	$contact 	= '';
	$table 		= '';

	if(OUTLET_ID > 2){
		$roc 	= ' AND (outletId = ' . OUTLET_ID . ' OR outletId < 1) AND companyId = ' . COMPANY_ID . ' '; //si o si companyId o muestra usuarios de todas las empresas
	}else{
		$roc 	= ' AND companyId = ' . COMPANY_ID . ' ';
	}

	if(validateHttp('uit') == 'usr'){
		$type 		= 0;
		$contact 	= ' AND contactInCalendar = 1 ';
		if(validateHttp('ui')){
			$contact .= ' AND userId = ' . $contactId;
		}
	}else if(validateHttp('uit') == 'cus'){
		$type 		= 1;
		if(validateHttp('ui')){
			$contact = ' AND customerId = ' . $contactId;
		}
	}

	$sql 	= 'SELECT * FROM contact WHERE type = ' . $type . $contact . $roc . $limits;

	$result = ncmExecute($sql,[],false,true);

	$head = '	<thead class="text-u-c '.OUTLET_ID.'">'.
			 '		<tr>'.
			 '			<th>Usuario</th>'.
			 '			<th class="text-center">Pendientes</th>'.
			 '			<th class="text-center">Finalizados</th>'.
			 '			<th class="text-center">Cancelados/Re Agendados</th>'.
			 '			<th class="text-center">No Shows</th>'.
			 '			<th class="text-center">Bloqueos de Agenda</th>'.
			 '		</tr>'.
			 '	</thead>'.
			 '<tbody>';

	if($result){
		while (!$result->EOF) {
			$fields = $result->fields;

			$totalCitas 	= getTotalScheduleByStatus(false,$startDate,$endDate,$roc,$fields['contactId']);
			$pending 		= getTotalScheduleByStatus('0',$startDate,$endDate,$roc,$fields['contactId']);
			$canceled 		= getTotalScheduleByStatus('4',$startDate,$endDate,$roc,$fields['contactId']);
			$noshow 		= getTotalScheduleByStatus('5',$startDate,$endDate,$roc,$fields['contactId']);
			$ended 			= getTotalScheduleByStatus('6',$startDate,$endDate,$roc,$fields['contactId']);
			$blocked 		= getTotalScheduleByStatus('7',$startDate,$endDate,$roc,$fields['contactId']);

			$table .= 	'<tr>'.
						'	<td>' . $fields['contactName'] . '</td>'. 
						'	<td class="text-right">' . $pending . '</td>'. 
						'	<td class="text-right">' . $ended . '</td>'. 
						'	<td class="text-right">' . $canceled . '</td>'. 
						'	<td class="text-right">' . $noshow . '</td>'. 
						'	<td class="text-right">' . $blocked . '</td>'. 
						'</tr>';


			$result->MoveNext();
		}
	}

	$foot 	= 	'</tbody>' .
			  	'<tfoot>' .
				'	<tr>' .
				'		<th>TOTALES:</th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'	</tr>' . 
			  	'</tfoot>';

	$fullTable = $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;
	dai(json_encodes($jsonResult,true));
}

if(validateHttp('action') == 'sessions'){
    $limits     = getTableLimits($limitDetailS,$offsetDetailS);

    $sql = "SELECT a.invoiceNo, a.customerId, contact.contactName , b.itemSoldDate,b.itemId, b.itemSoldId , c.itemName ,c.itemSessions, tDones.transactionId as tId ,count(tDones.transactionId) as dones FROM transaction a
    left join contact on contact.contactUID = a.customerId
    left join itemSold b on b.transactionId = a.transactionId
    left join item c on c.itemId = b.itemId
    left join transaction tDones on tDones.transactionId = a.transactionId and tDones.transactionStatus = 6 and tDones.transactionType = 13
    WHERE a.companyId = ? AND a.transactionDate BETWEEN ? AND ? AND c.itemSessions > 1
    GROUP by a.invoiceNo, a.customerId, contact.contactName , b.itemSoldDate,b.itemId, b.itemSoldId , c.itemName ,c.itemSessions, tDones.transactionId " . $limits;
    $result = ncmExecute($sql,[COMPANY_ID,$startDate,$endDate],false,true);
    $jsonResult = [];

    $head = '    <thead class="text-u-c">'.
             '        <tr>'.
             '            <th># Documento</th>'.
             '            <th>Adquirido</th>'.
             '            <th>Artículo</th>'.
             '            <th>Cliente</th>'.
             '            <th class="text-center">Sesiones</th>'.
             '            <th class="text-center">Realizadas</th>'.
             '            <th class="text-center">Pendientes</th>'.
             '        </tr>'.
             '    </thead>'.
             '    <tbody>';

    $table 				= '';
    $transactionsArray 	= array();
    $itemSoldIds 		= array();
    if($result){
        while (!$result->EOF) {
            $fields = $result->fields;
            $fields['itemSoldDate']         = niceDate($fields['itemSoldDate']);

            //obtengo sesiones

            // $sessions = ncmExecute("SELECT COUNT(transactionId) as dones FROM transaction WHERE companyId = ? AND transactionType = 13 AND packageId = ? AND transactionStatus = 6 LIMIT " . $fields['itemSessions'],[ COMPANY_ID, $fields['itemSoldId'] ]);

            $transactionsArray[] = $fields;
            $itemSoldIds[$fields['itemSoldId']] = 0;
            $result->MoveNext();
        }
        $result->Close();
    }

    $itemSoldIdsString 	= implode(",", array_keys($itemSoldIds));

	$result 			= ncmExecute("SELECT packageId, COUNT(transactionId) as dones FROM transaction WHERE companyId = " . COMPANY_ID . " AND transactionType = 13 AND transactionStatus = 6 AND packageId IN (" . $itemSoldIdsString . ") GROUP BY packageId", [],true,true);
	    
    if($result){
        while(!$result->EOF){
            $fields = $result->fields;
            $itemSoldIds[$fields['packageId']] = $fields['dones'];

            $result->MoveNext();
        }
        $result->Close();
    }

    if(count($transactionsArray) > 0){
        foreach($transactionsArray as $fields){
            $fields['dones'] = $itemSoldIds[$fields['itemSoldId']];
            //obtengo sesiones

            // $sessions = ncmExecute("SELECT COUNT(transactionId) as dones FROM transaction WHERE companyId = ? AND transactionType = 13 AND packageId = ? AND transactionStatus = 6 LIMIT " . $fields['itemSessions'],[ COMPANY_ID, $fields['itemSoldId'] ]);
			
            $table .=     '<tr class="pointer clickrowSession" data-id="' . enc($fields['itemSoldId']) . '" data-load="' . $baseUrl . '?action=detail&id=' . enc($fields['itemSoldId']) . '&cid=' . enc($fields['customerId']) . '">'.
                        '    <td>' . $fields['invoiceNo'] . '</td>'.
                        '    <td data-sort="' . $fields['itemSoldDate'] . '">' . (isset($date) ? $date : '') . '</td>'.
                        '    <td>' . $fields['itemName'] . '</td>'.
                        '    <td>' . $fields['contactName'] . '</td>'.
                        '    <td class="text-right">' . $fields['itemSessions'] . '</td>'.
                        '    <td class="text-right">' . $fields['dones'] . '</td>'.
                        '    <td class="text-right">' . ($fields['itemSessions'] - $fields['dones']) . '</td>'.
                        '</tr>';

            if(validateHttp('part') && !validateHttp('singleRow')){
                $table .= '[@]';
            }
        }
    }

    $foot     =     '</tbody>' .
                 '<tfoot>' .
                '    <tr>' .
                '        <th colspan="7"></th>' .
                '    </tr>' .
                 '</tfoot>';

    if(validateHttp('part')){
        dai($table);
    }else{
        $fullTable = $head . $table . $foot;
        $jsonResult['table'] = $fullTable;
        header('Content-Type: application/json');
        dai(json_encodes($jsonResult,true));
    }
}
if(validateHttp('action') == 'detail' && validateHttp('id')){
	$id 		= dec(validateHttp('id'));
	$cusId 		= dec(validateHttp('cid'));
	$result   	= ncmExecute("SELECT * FROM transaction WHERE companyId = ? AND transactionType = 13 AND packageId = ? LIMIT 50",[ COMPANY_ID, $id ],false,true);

	if($result){
		$contact 		= getContactData($cusId,'uid',true);
		$contactName 	= getCustomerName($contact);
	}
	?>
	<div class="col-xs-12 no-padder bg-white clear r-24x">
	   <div class="bg-light dk col-xs-12 wrapper text-left">
	      <div class="col-sm-6 h2 b-l b-3x b-light wrapper-sm m-b font-bold" id="getSaleTitle">
	         <div class="text-sm">Sesiones <span class="font-normal text-xs m-l-sm"></span></div>
	         <span class="text-dark"><?=$contactName?></span>
	      </div>
	      <div class="col-sm-6 text-right no-padder" id="getSaleDate">
	         
	      </div>
	   </div>
	   <div class="col-xs-12 wrapper text-left">
	      <div class="col-xs-12 text-center h3 m-b no-padder hidden" id="getSaleCreditTable">
	         <div class="col-xs-6 gscp"></div>
	         <div class="col-xs-6 gscd"></div>
	      </div>
	      <div class="col-xs-12 momentumit panel r-24x">
	         <table class="table text-left font-bold">
	            <tbody>
	            	<?php
	            	if($result){
	            		$s = 1;
	            		while (!$result->EOF) {
							$fields = $result->fields;
							list($dateS,$startH,$endH) = dateStartEndTime($fields['fromDate'],$fields['toDate']);
	            	?>
	               <tr>
	                  <td>Sesión <?=$s?></td>
	                  <td><?=($dateS) ? niceDate($dateS) : ''?></td>
	                  <td>
	                  	<?php
	                  	if(counts($startH) > 1){
	                  	?>
	                  	<span class="label bg-light dk"><?=$startH?> a <?=$endH?></span>
	                  	<?php
	                  	}
	                  	?>
	                  </td>
	                  <td class="text-right">
	                  	<?php
	                  	if($fields['transactionStatus'] == 6){
	                  		echo '<i class="material-icons text-success">check</i>';
	                  	}else if(counts($startH) > 1){
	                  		echo '<i class="material-icons text-primary">timelapse</i>';
	                  	}
	                  	?>
	                  </td>
	               </tr>
	               <?php
		               		$s++;
		               		$result->MoveNext();
						}
						$result->Close();
					}
	               ?>
	            </tbody>
	         </table>
	      </div>
	      
	   </div>
	</div>

	<?

	dai();
}

if(validateHttp('action') == 'delete'){
	if(!validateHttp('id')){
		dai('false');
	}

	$id 	= dec(validateHttp('id'));

	//$isSession = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[$id,COMPANY_ID]);

	/*if($isSession['transactionParentId']){// si es una sesion cambio su estado a 0
		$db->Execute('UPDATE transaction SET transactionStatus = 0 WHERE transactionId = ? AND companyId = ?',[$id,COMPANY_ID]);
	}else{// si es una cita elimino*/
		$result = $db->Execute('DELETE FROM transaction WHERE transactionId = ? AND companyId = ?',[$id,COMPANY_ID]);
	//}

	if($result !== false){
		echo 'true';
	}else{
		echo 'false';
	}

	dai();
}
?>
    
  	<?=reportsTitle('Agendamientos',true);?>

  	<div class="col-xs-12 no-padder text-center hidden-print">
  		<div class="col-md-4 col-sm-12 text-center no-padder">
           <canvas id="chart-contado" class="" height="200" style="max-height:200px;"></canvas>
           <div class="donut-inner" style=" margin-top: -140px; margin-bottom: 100px;">
             <div class="h1 m-t creditoCount font-bold totals"><?=placeHolderLoader()?></div>
             <span>Total</span>
           </div>
           <div class="m-t-n h4">&nbsp;</div>
        </div>


        <div class="col-md-8 no-padder hidden-print">

	        <div class="col-xs-12 no-padder text-center font-bold h4 m-b">
				Resumen del periodo actual
			</div>

	  		<section class="col-md-3 col-sm-6">
	  			<div class="b-b text-center wrapper-md">
					<div class="h1 m-b-xs m-t total font-bold new"><?=placeHolderLoader()?></div>
					Pendientes
				</div>
	        </section>
	  		<section class="col-md-3 col-sm-6">
	            <div class="b-b text-center wrapper-md">
					<div class="h1 m-b-xs m-t total font-bold ended"><?=placeHolderLoader()?></div>
					Finalizados
				</div>
	        </section>
	  		<section class="col-md-3 col-sm-6">
	            <div class="b-b text-center wrapper-md">
					<div class="h1 m-b-xs m-t total font-bold cancelled"><?=placeHolderLoader()?></div>
					Cancelados
				</div>
	        </section>
	        <section class="col-md-3 col-sm-6">
	            <div class="b-b text-center wrapper-md">
					<div class="h1 m-b-xs m-t total font-bold noshow"><?=placeHolderLoader()?></div>
					No shows
				</div>
	        </section>
        </div>
  	</div>

	<div class="col-sm-12 no-padder push-chat-down">
		<ul class="nav nav-tabs padder hidden-print">
            <li class="active">
                <a href="#tableContainer" data-toggle="tab">Detallado</a>
            </li>
            <li id="sessionsTab">
                <a href="#sessionsList" data-toggle="tab">Sesiones</a>
            </li>
            <li id="statsTab">
                <a href="#statsList" data-toggle="tab">Estadísticas</a>
            </li>
            <li id="cusStatsTab" class="hidden">
                <a href="#cusStatsList" data-toggle="tab">Clientes</a>
            </li>
        </ul>

        <section class="panel r-24x">
        	<section class="panel-body">
        		<div class="tab-content m-b-lg table-responsive">
        			<div class="tab-pane active" id="tableContainer">
        				<table class="table col-xs-12 no-padder table-hover" id="tableSchedule">
					    	<?=placeHolderLoader('table')?>
					    </table>
        			</div>
        			<div class="tab-pane" id="sessionsList">
        				<table class="table col-xs-12 no-padder table-hover" id="tableSessions">
					    	<?=placeHolderLoader('table')?>
					    </table>
        			</div>
        			<div class="tab-pane" id="statsList">
        				<table class="table col-xs-12 no-padder table-hover" id="tableStats">
					    	<?=placeHolderLoader('table')?>
					    </table>
        			</div>
        			<div class="tab-pane" id="cusStatsList">
        				<table class="table col-xs-12 no-padder table-hover" id="tableCusStats">
					    	<?=placeHolderLoader('table')?>
					    </table>
        			</div>
        		</div>
        	</section>
        </section>
		
    </div>

<script>
var baseUrl = '<?=$baseUrl?>';
$(document).ready(function(){
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");
	window.sessionsTab 	= false;
	window.secondTab  	= false;

	var rawUrl 	= baseUrl + "?action=detailTable",
	loadUrl 	= rawUrl + "&ui=<?=validateHttp('ui')?>",
	currency 	= "<?=CURRENCY?>",
	offsetD		= <?=$offsetDetail?>,
	limitD 		= <?=$limitDetail?>;

	$.get(loadUrl,function(result){

		var info1 = {
					"container" 	: "#tableContainer",
					"url" 			: loadUrl,
					"rawUrl" 		: rawUrl,
					"table" 		: "#tableSchedule",
					"iniData" 		: result.table,
					"sort" 			: 1,
					"footerSumCol" 	: [14],
					"currency" 		: currency,
					"decimal" 		: decimal,
					"thousand" 		: thousandSeparator,
					"offset" 		: offsetD,
					"limit" 		: limitD,
					"nolimit" 		: true,
					"ncmTools"			: {
												left 	: 	'<a href="#" class="btn btn-default exportTable" data-table="tableSchedule" data-name="Agenda">Exportar Listado</a>',
												right 	: 	''
											  },
					"colsFilter"		: {
											name 	: 'reportSchedule3',
											menu 	:  [
															{"index":0,"name":"Estado","visible":true},
															{"index":1,"name":"# Doc. Venta","visible":false},
															{"index":2,"name":"# Agendamiento","visible":false},
															{"index":3,"name":'Agendado',"visible":false},
															{"index":4,"name":'Sucursal',"visible":false},
															{"index":5,"name":'Asignado',"visible":true},
															{"index":6,"name":'Responsable',"visible":true},
															{"index":7,"name":'Cliente',"visible":true},
															{"index":8,"name":'Motivo',"visible":false},
															{"index":9,"name":'Descripción',"visible":false},
															{"index":10,"name":'Fecha',"visible":true},
															{"index":11,"name":'Inicio',"visible":false},
															{"index":12,"name":'Fin',"visible":false},
															{"index":13,"name":'Duración',"visible":true},
															{"index":14,"name":'Valor',"visible":true},
															{"index":15,"name":'Asistencia',"visible":false},
															{"index":16,"name":'Acciones',"visible":false},
														]
										  }
		};

		manageTableLoad(info1,function(oTable){
			$('[data-toggle="tooltip"]').tooltip();

			onClickWrap('.clickrow',function(event,tis){
				var load = tis.data('load');
				loadForm(load,'#modalLarge .modal-content',function(){
					$('#modalLarge').modal('show');
				});
			});

			onClickWrap('.delete',function(event,tis){
				var load 	= tis.attr('href');
				var id 		= tis.data('id');
				var $row 	= $('.row' + id);

				ncmDialogs.confirm('Realmente desea eliminar la transacción?','','warning',function(conf){
					if(conf){
						oTable.row($row).remove().draw();
						$.get(load,function(result){
							if(result == 'true'){
								message('La transacción fue eliminada con éxito.','success');
							}else{
								message('No se pudo eliminar la transacción.','danger');
							}
						});
					}
				});
			});
		});

		$('.new').text(result.summary.newF);
		$('.ended').text(result.summary.endedF);
		$('.cancelled').text(result.summary.cancelledF);
		$('.noshow').text(result.summary.noshowF);
		$('.new').text(result.summary.newF);
		$('.totals').text(result.summary.totalsF);

		Chart.defaults.global.responsive 			= true;
		Chart.defaults.global.maintainAspectRatio 	= false;
		Chart.defaults.global.legend.display 		= false;

		var chartContado = document.getElementById('chart-contado').getContext("2d");

	    var methods = new Chart(chartContado, {
	      type      : 'doughnut',
	      data      : {
	        labels: ['Nuevos','Finalizados','Cancelados','No show'],
	        datasets: [
	        {
	          data: [result.summary.new,result.summary.ended,result.summary.cancelled,result.summary.noshow],
	          backgroundColor: ['#d9e4e6','#778490','#f26767','#657789']
	        }]
	      },
	      animation : true,
	      options   : {
	        cutoutPercentage:85
	      }
	    });
	});

	onClickWrap('#statsTab',function(event,tis){
		if(!window.secondTab){
			var rawUrl2 	= baseUrl + "?action=stats";
			var loadUrl2 	= rawUrl2 + "&ui=<?=validateHttp('ui')?>&uit=usr";

			$.get(loadUrl2,function(result){

				window.secondTab = true;

				var info2 = {
							"container" 	: "#statsList",
							"url" 			: loadUrl2,
							"rawUrl" 		: rawUrl2,
							"table" 		: "#tableStats",
							"iniData" 		: result.table,
							"sort" 			: 2,
							"footerSumCol" 	: [1,2,3,4,5],
							"currency" 		: "<?=CURRENCY?>",
							"decimal" 		: decimal,
							"thousand" 		: thousandSeparator,
							"offset" 		: <?=$offsetDetail?>,
							"limit" 		: <?=$limitDetail?>,
							"nolimit" 		: true,
							"ncmTools"			: {
														left 	: 	'<a href="#" class="btn btn-default exportTable" data-table="tableStats" data-name="Estadisticas">Exportar Listado</a>',
														right 	: 	''
													  },
							"colsFilter"		: {
													name 	: 'statsSchedule',
													menu 	:  [
																	{"index":0,"name":"Usuario","visible":true},
																	{"index":1,"name":"Pendientes","visible":false},
																	{"index":2,"name":"Finalizados","visible":true},
																	{"index":3,"name":'Cancelados/Re Agendados',"visible":false},
																	{"index":4,"name":'No Shows',"visible":true},
																	{"index":5,"name":'Bloqueos de Agenda',"visible":true}
																]
												  }
				};

				manageTableLoad(info2);

			});
		}
	});

	onClickWrap('#sessionsTab',function(event,tis){
		if(!window.sessionsTab){
			var rawUrl3 	= baseUrl + "?action=sessions";
			var loadUrl3 	= rawUrl3 + "&ui=<?=validateHttp('ui')?>&uit=usr";

			$.get(loadUrl3,function(result){

				window.sessionsTab = true;

				var info3 = {
							"container" 	: "#sessionsList",
							"url" 			: loadUrl3,
							"rawUrl" 		: rawUrl3,
							"table" 		: "#tableSessions",
							"iniData" 		: result.table,
							"sort" 			: 1,
							"footerSumCol" 	: [],
							"currency" 		: "<?=CURRENCY?>",
							"decimal" 		: decimal,
							"thousand" 		: thousandSeparator,
							"offset" 		: <?=$offsetDetailS?>,
							"limit" 		: <?=$limitDetailS?>,
							"ncmTools"			: {
														left 	: 	'<a href="#" class="btn btn-default exportTable" data-table="tableSessions" data-name="Sesiones">Exportar Listado</a>',
														right 	: 	''
													  },
							"colsFilter"		: {
													name 	: 'sessionsSchedule',
													menu 	:  [
																	{"index":0,"name":"Adquirido","visible":true},
																	{"index":1,"name":"Artículo","visible":true},
																	{"index":2,"name":"Cliente","visible":true},
																	{"index":3,"name":'Sesiones',"visible":true},
																	{"index":4,"name":'Realizadas',"visible":true},
																	{"index":5,"name":'Pendientes',"visible":true}
																]
												  }
				};

				manageTableLoad(info3,function(){
					onClickWrap('.clickrowSession',function(event,tis){
						var load = tis.data('load');
						loadForm(load,'#modalSmall .modal-content',function(){
							$('#modalSmall').modal('show');
						});
					});
				});

			});
		}
	});

	onClickWrap('#cusStatsTab',function(event,tis){
		if(!window.thirdTab){
			var rawUrl3 	= baseUrl + "?action=stats";
			var loadUrl3 	= rawUrl3 + "&ui=<?=validateHttp('ui')?>&uit=cus";

			$.get(loadUrl3,function(result){

				window.thirdTab = true;

				var info3 = {
							"container" 	: "#cusStatsList",
							"url" 			: loadUrl3,
							"rawUrl" 		: rawUrl3,
							"table" 		: "#tableCusStats",
							"iniData" 		: result.table,
							"sort" 			: 2,
							"footerSumCol" 	: [1,2,3,4,5],
							"currency" 		: "<?=CURRENCY?>",
							"decimal" 		: decimal,
							"thousand" 		: thousandSeparator,
							"offset" 		: <?=$offsetDetail?>,
							"limit" 		: <?=$limitDetail?>,
							"nolimit" 		: true,
							"ncmTools"			: {
														left 	: 	'<a href="#" class="btn btn-default exportTable" data-table="tableCusStats" data-name="Estadisticas">Exportar Listado</a>',
														right 	: 	''
													  },
							"colsFilter"		: {
													name 	: 'statsSchedule2',
													menu 	:  [
																	{"index":0,"name":"Cliente","visible":true},
																	{"index":1,"name":"Pendientes","visible":false},
																	{"index":2,"name":"Finalizados","visible":true},
																	{"index":3,"name":'Cancelados/Re Agendados',"visible":false},
																	{"index":4,"name":'No Shows',"visible":true},
																	{"index":5,"name":'Bloqueos de Agenda',"visible":false}
																]
												  }
				};

				manageTableLoad(info3);

			});
		}
	});

});

</script>
<?php
include_once('includes/compression_end.php');
dai();
?>