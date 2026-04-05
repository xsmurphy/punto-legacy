<?php
include_once('includes/top_includes.php');
topHook();
allowUser('sales','view');

$baseUrl = '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7,true);

$roc 				= getRoc(1);
$limitDetail		= 100;
$offsetDetail		= 0;

if(validateHttp('action') == 'delete' && validateHttp('id')){
	if(!allowUser('sales','delete',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$id 		= dec($_GET['id']);
	
	$delete 	= $db->Execute('DELETE FROM drawer WHERE drawerId = ? LIMIT 1', array($id)); 

	if(!$delete){
		echo 'false';
	}else{
		echo 'true';
	}
	dai();
}

if(validateHttp('action') == 'viewData' && validateHttp('d')){

	$data 		= json_decode( base64_decode( validateHttp('d') ), true );

	$dOpen 		= $data['openDate'];
	$dClose 	= $data['closeDate'];
	$register 	= iftn($data['register'],false,dec( $data['register'] ));
	$outlet 	= iftn($data['outlet'],false,dec($data['outlet']));
	$oName 		= iftn($data['outletName'],'');
	$rName 		= iftn($data['registerName'],'');
	$uOName 	= iftn($data['userOpenName'],'');
	$uCName 	= iftn($data['userCloseName'],'');
	$oAmount 	= (float)iftn($data['openAmount'],0);
	$cAmount 	= (float)iftn($data['closeAmount'],0);
	$drawer 	= iftn($data['drawer'],false,dec($data['drawer']));
	$sold 		= (float)$data['sold'];
	$expense 	= (float)$data['expense'];
	$income 	= (float)$data['income'];
	$return		= $data['return'] ?? false;

	if(!validity($dOpen)){
		dai(false);
	}

	if(!validity($sold) < 1 && !validity($dClose)){
		$sold 		= getSalesByDrawerPeriod($dOpen,false,$register,false);
	}
 
	$detailArray 	= getSalesByPayment($dOpen,iftn($dClose,false),$register,false);

	$totalDrawer 	= 0;
	$difference 	= 0;
	$counter 		= 0;
	$details 		= false;
	$details 		.= '<div class="i' . enc($drawer) . ' text-left">';
	if(validity($detailArray,'array')){
		$details .= '<div class="visible-print">' .
					'	<div class="text-center h3">'.$oName.'</div>' .
					'	<div class="text-center text-sm">'.$rName.'</div>' .
					'	<div class="m-t text-center">Apertura realizada por '.$uOName.' el '.niceDate($dOpen,true).' con '.CURRENCY.formatCurrentNumber($oAmount).'</div>' .
					'	<div class="m-b text-center">Cierre realizado por '.$uCName.' el '.niceDate($dClose,true).' con '.CURRENCY.formatCurrentNumber($cAmount).'</div>' .
					'	<div class="wrapper text-center h4">Detalles</div>' .
					'</div>' .
					'<div class="wrapper-sm b-b font-bold">Caja inicial<span class="pull-right">' . CURRENCY . formatCurrentNumber($oAmount) . '</span></div>';

		$totalCash = 0;
		$divReturn = '';
		if($return){
			$totalCash = $return;
			$divReturn = '<div class="wrapper-sm b-b font-bold">' .
							'	Nota de Crédito <span class="pull-right font-bold">'.CURRENCY.formatCurrentNumber($return).'</span>' .
						'</div>';
		}

		foreach ($detailArray as $arr){
			$details .= '<div class="wrapper-sm b-b">' . 
							getPaymentMethodName($arr['type']) .
						' 	<span class="pull-right font-bold">' . 
								CURRENCY . formatCurrentNumber($arr['price']) . 
						'	</span>' . 
						'</div>';

			if($arr['type'] == 'cash'){
				$totalCash += $arr['price'];
			}
		}

		$totalDrawer = (float)($sold - $expense);

		$details .= '<div class="wrapper-sm b-b">' .
					'	Extracciones <span class="pull-right font-bold">'.CURRENCY.formatCurrentNumber($expense).'</span>' .
					'</div>' .
					'<div class="wrapper-sm b-b b-dark">' .
					'	Ingresos <span class="pull-right font-bold">'.CURRENCY.formatCurrentNumber($income).'</span>' .
					'</div>' .
					'<div class="wrapper-sm b-b font-bold">' .
					'	Ventas <span class="pull-right font-bold">'.CURRENCY.formatCurrentNumber($sold + abs($return)).'</span>' .
					'</div>' .
					$divReturn .
					'<div class="wrapper-sm b-b font-bold">' .
					'	Total de Efectivo <span class="pull-right font-bold">'.CURRENCY.formatCurrentNumber(($oAmount + $totalCash + $income) - $expense ).'</span>' .
					'</div>';
		
			//a partir de ahora total drawer va sumado con el monto de apertura
			$totalDrawer = (float)(($sold + $oAmount + $income) - $expense);

			$details .= '<div class="wrapper-sm b-b bg-light lt font-bold">' .
						'	TOTAL <span class="pull-right font-bold">'.CURRENCY.formatCurrentNumber($totalDrawer).'</span>' .
						'</div>' .
						'<div class="text-left hidden-print">';


					if(validity($dClose)){
						$details .= '<div class="text-center m-b font-bold text-u-c m-t">Corregir Cierre</div>' .
									'<label class="text-xs font-bold text-u-c">Fecha de apertura</label>' .
									'<input type="text" class="form-control datepicker m-b-sm no-border b-b openDate" name="date" value="' . $dOpen . '" autocomplete="off" />' .
									'<label class="text-xs font-bold text-u-c">Fecha de cierre</label>' .
									'<input type="text" class="form-control datepicker m-b-sm no-border b-b closeDate" name="date" value="' . $dClose . '" autocomplete="off" />' .
									'<label class="text-xs font-bold text-u-c">Monto de apertura</label>' .
									'<input value="' . formatCurrentNumber($oAmount) . '" class="form-control no-border b-b maskCurrency m-b-sm openAmount">' .
									'<label class="text-xs font-bold text-u-c">Monto de cierre</label>' .
									'<input value="' . formatCurrentNumber($cAmount) . '" class="form-control no-border b-b maskCurrency m-b-sm rightAmount">' .
									'<a href="#" class="modifyClosure btn btn-info rounded text-u-c font-bold btn-block" data-id="' . enc($drawer) . '" data-register="' . enc($register) . '">Guardar</a>';
					}else{
						$details .= '<div class="text-center m-b font-bold text-u-c m-t">Cerrar Caja</div>' .
									'<label class="text-xs font-bold text-u-c">Fecha de cierre</label>' .
									'<input type="text" class="form-control datepicker m-b-sm no-border b-b closeDate" name="date" value="" autocomplete="off" />' .
									'<label class="text-xs font-bold text-u-c">Monto de cierre</label>' .
									'<input value="" class="form-control maskCurrency m-b-sm no-border b-b closeAmount">' .
									'<a href="javascript:;" class="closeRegister btn btn-info rounded text-u-c font-bold btn-block" data-id="' . enc($drawer) . '" data-register="' . enc($register) . '">Guardar</a>';
					}
				

				if(validity($dClose)){
					$details .= '<a href="#" class="printList hidden-print block text-center m-t" data-id="' . enc($drawer) . '">Imprimir</a>';
				}

				$details .= '<a href="https://public.encom.app/closedRegister?s=' . base64_encode(enc(COMPANY_ID) . ',' . enc($drawer)) . '" class="hidden-print block text-center m-t" target="_blank">Vista externa</a>';


		    	if($_modules['dropbox']){
		    		$details .= '<div class="text-center m-b-md no-border panel no-padder r-24x col-xs-12 table-responsive" id="DBFiles"></div>' .
		    					'<script type="text/javascript">' .
		    					'$(document).ready(function(){' .
		    					'	var opts = {' .
								'	  "loadEl" : "#DBfileInput,#DBFiles",' .
								'	  "listEl" : "#DBFiles",' .
								'	  "token"  : "' . $_modules['dropboxToken'] . '",' .
								'	  "folder" : "/drawers/' . enc($drawer) . '"' .
								'	};' .
								'	ncmDropbox(opts);' .
		    					'});' .
		    					'</script>';
		    	}

			$details .= '</div>';

		if($cAmount < $totalDrawer){
			$difference = '<span class="text-danger">'.'-'.formatCurrentNumber($totalDrawer - $cAmount).'</span>';
		}else if($cAmount > $totalDrawer){
			$difference = '<span class="">'.formatCurrentNumber($cAmount-$totalDrawer).'</span>';
		}else{
			$difference = '<i class="material-icons text-success">check</i>';
		}
		$details .= '<div class="visible-print">Cierre <span class="pull-right">'.formatCurrentNumber($cAmount).'</span></div>' .
					'<div class="visible-print">Diferencia <span class="pull-right">'.$difference.'</span></div>';
	}else{

		$details .= '<div class="col-xs-12 bg-white">' . 
						noDataMessage('Sin Transacciones','No se registraron transacciones en este periodo') . 
					'</div>';//'<h4 class="block text-center text-info">Sin transacciones</h4>';
		
		//if(!validity($dClose)){
			$details .= '<div class="text-center m-b font-bold text-u-c m-t">Corregir Cierre</div>' .
						'<label class="text-xs font-bold text-u-c">Fecha de apertura</label>' .
						'<input type="text" class="form-control datepicker m-b-sm no-border b-b openDate" name="date" value="' . $dOpen . '" autocomplete="off" />' .
						'<label class="text-xs font-bold text-u-c">Fecha de cierre</label>' .
						'<input type="text" class="form-control datepicker m-b-sm no-border b-b closeDate" name="date" value="' . $dClose . '" autocomplete="off" />' .
						'<label class="text-xs font-bold text-u-c">Monto de apertura</label>' .
						'<input value="' . formatCurrentNumber($oAmount) . '" class="form-control no-border b-b maskCurrency m-b-sm openAmount">' .
						'<label class="text-xs font-bold text-u-c">Monto de cierre</label>' .
						'<input value="' . formatCurrentNumber($cAmount) . '" class="form-control no-border b-b maskCurrency m-b-sm rightAmount">' .
						'<a href="#" class="modifyClosure btn btn-info rounded text-u-c font-bold btn-block" data-id="' . enc($drawer) . '" data-register="' . enc($register) . '">Guardar</a>';
		//}
	}

	echo '<div class="col-xs-12 wrapper bg-white">' . $details . '</div> </div>';

	dai();
}

if(validateHttp('action') == 'table'){
	$limits = getTableLimits($limitDetail,$offsetDetail);
	$singleRow = "";
	if(validateHttp('singleRow')){
		$singleRow = ' AND drawerId = ' . dec(validateHttp('singleRow'));
	}

	$query = "SELECT 	drawerId, 
						drawerUID, 
						outletId as outlet,
						registerId as register,
						drawerOpenDate as open,
						drawerOpenAmount as openamount,
						drawerCloseDate as close,
						drawerCloseAmount as closeamount,
						drawerUserOpen as useropen,
						drawerUserClose as userclose,
						drawerCloseDetails as details
			FROM drawer
			WHERE drawerOpenDate >= ?
			AND (drawerOpenDate <= ?)
			" . $singleRow . "
			" . $roc . "
			ORDER BY drawerUID ASC" . $limits;

	//echo $query;
	$result 			= ncmExecute($query,[$startDate,$endDate],false,true);

	$arrayUID 			= [];
	$table 				= '';

	$head = 	'<thead class="text-u-c">'.
				'	<tr>'.
				'		<th>Sucursal</th>'.
				'		<th>Caja</th>'.
				'		<th>Apertura</th>'.
				'		<th>Por</th>'.
				'		<th>Monto</th>'.
				'		<th>Cierre</th>'.
				'		<th>Por</th>'.
				'		<th>Monto</th>'.
				'		<th class="text-center">Diferencia</th>'.
				'		<th></th>'.
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	$outlets 			= getAllOutlets();
	$registers 			= getAllRegisters();
	$getAllContacts 	= getAllContacts('0');
	$allSales 			= getAllSalesByDrawerPeriod($startDate,$endDate);
	$counter 			= 0;

	if($result){
		$isdata = true;

		while (!$result->EOF) {
			$fields = $result->fields;
			//Drawer data
			$outlet 		= $outlets[$fields['outlet']]['name'];
			$register		= $registers[$fields['register']]['name'];
			$opendate		= niceDate2($fields['open']);
			$openamount		= formatCurrentNumber($fields['openamount']);
			$uOpen 			= getTheContactField($fields['useropen'],$getAllContacts,'id');
			$openName 		= $getAllContacts[1][$uOpen]['name'];
			$isClosed		= validity($fields['close']) ? true : false;
			$closeamount	= formatCurrentNumber($fields['closeamount']);
			$uClose 		= getTheContactField($fields['userclose'],$getAllContacts,'id');
			$closeName 		= $getAllContacts[1][$uClose]['name'] ?? "";
			//drawer data end

			$openDateEnd 	= date('Y-m-d 23:59:59',strtotime(TODAY));//date('Y-m-d 23:59:59',strtotime($fields['open']));
			$valCloseDate 	= validity($fields['close']) ? $fields['close'] : $openDateEnd;



			$totalSold 		= (float)sumTotalBetweenDateRanges($allSales, $fields['register'], $fields['open'], iftn($valCloseDate,$endDate));

			$exp 			= ncmExecute("SELECT SUM(expensesAmount) as expense FROM expenses WHERE expensesDate > ? AND expensesDate < ? AND type IS NULL AND registerId = ?",[$fields['open'],$valCloseDate,$fields['register']]);
			$totalExpense 	= 0;

			if($exp){
				$expVal 		= abs($exp['expense'] ?? 0); 
				$totalExpense 	= iftn($expVal,0);
			}

			$inc 			= ncmExecute("SELECT SUM(expensesAmount) as income FROM expenses WHERE expensesDate > ? AND expensesDate < ? AND type IS NOT NULL AND registerId = ?",[$fields['open'],$valCloseDate,$fields['register']]);
			$totalIncome 	= 0;
			$return 		= ncmExecute("SELECT SUM(transactionTotal) as totalReturn, SUM(transactionDiscount) as totalDiscount FROM transaction WHERE transactionDate BETWEEN ? AND ? AND registerId = ? AND transactionType = 6 AND companyId = ?",[$fields['open'],$valCloseDate,$fields['register'], COMPANY_ID]);

			if(isset($return['totalReturn']) && isset($return['totalDiscount'])){
				$return = $return['totalReturn'] - $return['totalDiscount'];
			}else{
				$return = $return['totalReturn'] ?? false;
			}

			if($inc){
				$incVal 		= abs($inc['income'] ?? 0); 
				$totalIncome 	= iftn($incVal,0);
			}
			
			//total o texto ver detalles LINK para abrir popup
			$url = [
						"openDate" 		=> $fields['open'],
						"closeDate" 	=> $fields['close'],
						"register" 		=> enc($fields['register']),
						"outlet" 		=> enc($fields['outlet']),
						"registerName" 	=> $register,
						"outletName" 	=> $outlet,
						"userOpenName" 	=> $openName,
						"userCloseName" => $closeName,
						"openAmount" 	=> $fields['openamount'],
						"closeAmount" 	=> $fields['closeamount'],
						"drawer" 		=> enc($fields['drawerId']),
						"sold" 			=> $totalSold,
						"expense" 		=> $totalExpense,
						"income" 		=> $totalIncome,
						"return"		=> $return ?? false
					 ];

			$url = base64_encode( json_encode($url) );


			$details = 	($isClosed) ? $closeamount : '-';

			$table .= 	'<tr class="clickrow pointer" data-date="' . $fields['close'] . '" data-load="' . $baseUrl . '?action=viewData&d=' . $url . '">' .
						'	<td> '.$outlet.' </td>' .
						'	<td> '.$register.' </td>' .
						'	<td data-order="' . $fields['open'] . '"> ' .
								niceDate($fields['open'],true) .
						' 	</td>' .
						'	<td>' . $openName . ' </td>' .
						'	<td class="text-right bg-light lt">' . $openamount . '</td>';

			if($isClosed){
				$totalDrawer 	= ( ( (float)$totalSold + (float)$totalIncome ) - (float)$totalExpense ) + $fields['openamount'];
				if($fields['closeamount'] < $totalDrawer){
					$difference = '<span class="text-danger">'.'-'.formatCurrentNumber($totalDrawer-$fields['closeamount']).'</span>';
				}else if($fields['closeamount'] > $totalDrawer){
					$difference = '<span class="">' . formatCurrentNumber($fields['closeamount'] - $totalDrawer) . '</span>';
				}else{
					$difference = '<i class="material-icons text-success">check</i>';
				}

				$table .= 	'	<td data-order="' . $fields['close'] . '">' .
									niceDate($fields['close'],true) .
							'	</td>' .
							'	<td class="">'.$closeName.' </td>' .
							'	<td class="text-right lter">'.$details.' </td>' .
							'	<td class="text-right lter">'.$difference.' </td>';
			}else{
				$table .= 	'	<td> <span class="label bg-success text-white text-u-c" title="'.$fields['close'].'">En curso</span> </td>' .
							'	<td class="">- </td>' .
							'	<td class="text-right lter">'.$details.' </td>' .
							'	<td class="text-right lter">- </td>';
			}


				$table .= 	'	<td class="text-center">' .
							'		<a href="' . $baseUrl . '?action=delete&id='.enc($fields['drawerId']).'" class="deleteItem"><i class="text-danger material-icons">close</span></a>' .
							'	</td>' .
							'</tr>';


			if(validateHttp('part')){
	        	$table .= '[@]';
	        }

			$counter++;
			
			$result->MoveNext();	
		}
		$result->Close();
	}

	$foot = '</tbody>' . '<tfoot> <tr> <td colspan="10"></td> </tr></tfoot>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		header('Content-Type: application/json');
		dai(json_encode($jsonResult));
	}
}

if(validateHttp('action') == 'closeRegister'){
	if(!allowUser('sales','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$id 		= validateHttp('i','post');
	$val 		= validateHttp('val','post');
	$register 	= validateHttp('r','post');
	$date 		= validateHttp('date','post');

	if($id && $register){
		$id 							= dec($id);
		$regId 							= dec($register);
		$record 						= [];

		$record['drawerCloseAmount'] 	= formatNumberToInsertDB($val);
		$record['drawerCloseDate'] 		= $date;
		$record['drawerUserClose']    	= USER_ID;

		$update = $db->AutoExecute('drawer', $record, 'UPDATE', 'drawerId = ' . $id . ' AND companyId = ' . COMPANY_ID);
		
		if($update !== false){
        	dai('true');
		}else{
			dai('false');
		}
	}

	dai('false');
}

if(validateHttp('action') == 'correctClosure'){
	if(!allowUser('sales','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	if(validateHttp('i','post')){
		$id 							= dec( validateHttp('i','post') );
		$record['drawerOpenDate']		= validateHttp('o','post');
		$record['drawerCloseDate']		= validateHttp('c','post');
		$record['drawerOpenAmount'] 	= formatNumberToInsertDB( validateHttp('oa','post') );
		$record['drawerCloseAmount'] 	= formatNumberToInsertDB( validateHttp('val','post') );
		$update = $db->AutoExecute('drawer', $record, 'UPDATE', 'drawerId = ' . $id . ' AND ' . $SQLcompanyId);
		
		if($update !== false){
			dai('true');
		}else{
			dai('false');
		}
	}
	dai('false');
}
?>

<?=menuReports();?>
<?=reportsTitle('Reporte de Cajas',true,'https://docs.encom.app/panel-de-control/reportes/otros/control-de-caja');?>
	
<section class="panel col-xs-12 wrapper push-chat-down r-24x tableContainer table-responsive" style="min-height:400px;">
    <table class="table table1 table-hover col-xs-12 no-padder" id="tableDrawers">
    	<?=placeHolderLoader('table')?>
    </table>
</section>

<script>
$(document).ready(function(){
	var baseUrl = '<?=$baseUrl?>';
	FastClick.attach(document.body);
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>",false);

	var rawUrl 	= baseUrl + "?action=table";
	var url 	= rawUrl,
	currency 	= "<?=CURRENCY?>",
	offset 		= <?=$offsetDetail?>,
	limit 		= <?=$limitDetail?>

	$.get(url,function(result){
		var tableOps = {
						"container" 	: ".tableContainer",
						"url" 			: url,
						"rawUrl" 		: rawUrl,
						"iniData" 		: result.table,
						"table" 		: ".table1",
						"sort" 			: 2,
						"footerSumCol" 	: false,
						"currency" 		: currency,
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 		: offset,
						"limit" 		: limit,
						"nolimit" 		: true,
						"ncmTools"		: {
											left 	: '<a href="#" class="btn btn-default exportTable" data-table="tableDrawers" data-name="control_de_cajas">Exportar Listado</a>',
											right 	: ''
										  },
						"colsFilter"	: {
											name 	: 'drawers',
											menu 	:  [
															{"index":0,"name":"Sucursal","visible":false},
															{"index":1,"name":"Caja","visible":false},
															{"index":2,"name":'Fecha de Apertura',"visible":true},
															{"index":3,"name":'Apertura Por',"visible":true},
															{"index":4,"name":'Monto de Apertura',"visible":true},
															{"index":5,"name":'Fecha de Cierre',"visible":true},
															{"index":6,"name":'Cerrado Por',"visible":true},
															{"index":7,"name":'Monto de Cierre',"visible":true},
															{"index":8,"name":'Diferencia',"visible":true},
															{"index":9,"name":'Acciones',"visible":false}
														]
										  }
		};

		manageTableLoad(tableOps,function(oTable){
			$('[data-toggle="tooltip"]').tooltip();
			
			onClickWrap('#tableDrawers .clickrow',function(event,tis){
		    	var url = tis.data('load');
		    	$('.editting').removeClass('editting');
				tis.addClass('editting');
				spinner('body', 'show');
		    	$.get(url,function(data){
			    	$('#modalTiny .modal-content').html(data);
			    	$('#modalTiny').modal('show');
					$('.datepicker').datetimepicker({
					  format: 'YYYY-MM-DD HH:mm:ss'
					});
					masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
					
					spinner('body', 'hide');
		    	});

		    });

			$('#modalTiny').on('shown.bs.modal',function(){
				
				onClickWrap('.modifyClosure',function(event,tis){
					var val 	= tis.data('val');
					var id 		= tis.data('id');
					var open	= $('.modal-content input.openDate').val();
					var close	= $('.modal-content input.closeDate').val();
					var oAmount	= $('.modal-content input.openAmount').val();
					var cAmount	= $('.modal-content input.rightAmount').val();

					var url = baseUrl + '?action=correctClosure';
				    $.post(url,{i : id, o : open, c : close, oa : oAmount, val : cAmount},function(result){
				    	$('.modal').modal('hide');
				    	if(result == 'true'){
				    		message('Monto Actualizado','success');
				    		$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
		                        oTable.row('.editting').remove();
		                        if(data){
			                        oTable.row.add($(data));
			                    }
			                    oTable.draw();
		                    });
				    	}else{
				    		message('No se pudo actualizar el monto','danger');
				    	}
				    });
					
				});

				onClickWrap('a.closeRegister',function(event,tis){
					var val 	= tis.data('val');
					var id 		= tis.data('id');
					var reg 	= tis.data('register');
					var date 	= $('input.closeDate').val();
					var newVal 	= $('input.closeAmount').val();

					if (date) {
						var url = baseUrl + '?action=closeRegister&debug=1';
						
						$.post(url,{i : id, r : reg, date : date, val : newVal},function(result){
					    //$.get(url,function(result){
					    	$('.modal').modal('hide');
					    	if(result == 'true'){
					    		message('Caja Cerrada','success');
					    		$.get(tableOps.rawUrl + '&part=1&singleRow=' + id,function(data){
			                        oTable.row('.editting').remove();
			                        if(data){
				                        oTable.row.add($(data));
				                    }
				                    oTable.draw();
			                    });
					    	}else{
					    		message('No se pudo cerrar la caja','danger');
					    	}
					    });
					}else{
						alert('Debe ingresar un monto de cierre y fecha');
					}
				});

				onClickWrap('.printList',function(event,tis){
					var id 		= tis.data('id');
					var data 	= $('.i' + id).html();
					data 		= "<div class='wrapper-md'>" + data + "</div>";
					$(data).print();
				});

				onClickWrap('.deleteItem',function(event,tis){
					var r = confirm("Realmente desea continuar?");
					if (r == true) {
					    var target = tis.attr('href');
						$.get(target, function(response) {
							$('.openPop').popover('hide');
							if(response == 'true'){
								message('Artículo eliminado','success');
								info1.iniData = false;//force new data reload
								manageTable(info1,function(){
									
									$('[data-toggle="tooltip"]').tooltip();
								});
							}else{
								message('Error al eliminar','danger');
							}
						});
					}
				});
			});

		});
	});
});
</script>

<?php
dai();
?>