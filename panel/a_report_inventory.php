<?php
include_once('includes/compression_start.php');
require_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
topHook();
allowUser('items','view');

$MAX_DAYS_RANGE = 31;
$baseUrl 				= '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

//DATE RANGE LIMITS FOR REPORTS
$maxDate 			= dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate 	= date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

$roc 					= getROC(1);
$isdata 			= true;

$table 				= '';
$tDifference 	= 0;
$tIn  				= 0;
$tOut  				= 0;

$limitDetail	= validateHttp('action') ? 200 : 100;
$offsetDetail	= 0;

$table 				= '';
$jsonResult 	= [];

if(validateHttp('action') == 'generalTable'){

	$head 	= 	'<thead>' .
				  		'	<tr>' .
				    	'		<th>Fecha</th>' .
				    	'		<th>Artículo</th>' .
				    	'		<th>Código</th>' .
				    	'		<th>Sucursal</th>' .
				    	'		<th>Depósito</th>' .
				    	'		<th>Usuario</th>' .
				    	' 	<th>Fuente</th>' .
				    	'		<th class="text-center">Ingreso</th>' .
				    	'		<th class="text-center">Egreso</th>' .
				    	'		<th class="text-center">Existencia</th>' .
				    	'		<th class="text-center">Costo Uni.</th>' .
				    	'	</tr>' .
				  		'</thead>' .
				  		'<tbody>';

	$limits 	= getTableLimits($limitDetail,$offsetDetail);
	$item 		= '';
	$iId 			= db_prepare( dec(validateHttp('ii')) );
	$between 	= ' BETWEEN "' . $startDate . '" AND "' . $endDate . '"';

	if(validateHttp('ii')){
		$item 		= ' AND itemId = ' . $iId;
		$between 	= '';
	}

	$stock 	= ncmExecute('SELECT * 
												FROM stock 
												WHERE stockDate
												' . $between . '
												' . $item . '
												' . $roc . '
												ORDER BY stockId DESC
												' . $limits,
												[],false,true
											);
		      	
	if($stock){
	  	$isdata 				= true;
	  	$allUsers 			= getAllUsers();
	  	$translateFrom 	= ['production','adjustment','transfer','void','sale','purchase','other','return','count'];
	  	$translateTo 		= ['Producción','Ajuste','Transferencia','Anulación','Venta','Compra','Otro','Devolución','Conteo'];

	  	$itmIDS = [];
	  	while (!$stock->EOF) {
				$itmIDS[] = $stock->fields['itemId'];
				$stock->MoveNext();
			}
			$stock->MoveFirst();
			$item 					= getAllItems(false,false, implode(',', $itmIDS) );
	  	
	  	while (!$stock->EOF) {
	    	$fields 			= $stock->fields;
	    	$source 			= str_replace($translateFrom, $translateTo, $fields['stockSource']);
	    	$user 				= iftn($allUsers[$fields['userId']]['name'] ?? false,'Sin Usuario');
	    	$outletName 	= getCurrentOutletName($fields['outletId']);
	    	$locationName = getLocationName($fields['locationId']);
	    	$itemName 		= $item[$fields['itemId']]['name'];
	    	$itemSKU 			= $item[$fields['itemId']]['sku'];
	    	$arrow 				= ($fields['stockCount'] > 0) ? '+' : '';
	    	$transaction 	= ($fields['transactionId']) ? enc($fields['transactionId']) : false;
	    	$note 				= unXss($fields['stockNote']);
	    	$eId 					= enc($fields['stockId']);

	    	if($note){
	    		$source 		= '<span class="text-u-l pointer" data-toggle="tooltip" title="' . $note . '">' . $source . '</span>';
	    	}

	    	if($transaction){
	    		if($fields['stockSource'] == 'purchase'){
	    			$source = '<a class="doc hidden-print" href="/a_report_purchases?action=edit&id=' . $transaction . '&ro=1"><span class="text-info">' . $source .'</a></a>';
	    		}else{
	    			$source = '<a class="doc hidden-print" href="/a_report_transactions?action=edit&id=' . $transaction . '&ro=1"><span class="text-info">' . $source .'</a></a>';
	    		}
	    	}

	    	if($fields['stockCount'] > 0){
	    		$tIn += $fields['stockCount'];
	    	}else{
	    		$tOut += $fields['stockCount'];
	    	}

	    	$table .=	'<tr>' .
		         			'	<td data-order="' . $fields['stockDate'] . '" data-filter="' . $fields['stockDate'] . '">' .
		         					niceDate($fields['stockDate'],true) .
		         			'	</td>' .
		         			'	<td>' .
		         			' 		<a href="/@#items&i=' . enc($fields['itemId']) . '" target="_blank" class="hidden-print">' .
		         						$itemName .
		         			' 		</a>' .		
		         			' 		<span class="visible-print">' . $itemName . '</span>' .
		         			'	</td>' .
		         			'	<td>' .
		         					$itemSKU .
		         			'	</td>' .
		         			'	<td>' .
		         					$outletName .
		         			'	</td>' .
		         			'	<td>' .
		         					$locationName .
		         			'	</td>' .
		         			'	<td>' .
		         					$user .
		         			'	</td>' .
		         			'	<td>' .
		         					$source .
		         			'	</td>' .
		         			'	<td class="bg-light lter text-right" data-order="' . (($fields['stockCount'] > 0) ? $fields['stockCount'] : 0) . '">' .
		         					( ($fields['stockCount'] > 0) ? $arrow . formatQty($fields['stockCount'],3) : '-' ) .
		         			'	</td>' .
		         			'	<td class="bg-light lter text-right" data-order="' . (($fields['stockCount'] < 0) ? $fields['stockCount'] : 0) . '">' .
		         					( ($fields['stockCount'] < 0) ? $arrow . formatQty($fields['stockCount'],3) : '-' ) .
		         			'	</td>' .
		         			'	<td class="bg-light lter text-right ' . ( ($fields['stockOnHand'] <= 0) ? 'text-danger' : '' ) . '" data-filter="' . ( ($fields['stockOnHand'] == 0) ? 'quiebre' : '' ) . '">' .
		         					formatQty($fields['stockOnHand'],3) .
		         			'	</td>' .
		         			'	<td class="bg-light lter text-right" data-order="' . $fields['stockCOGS'] . '" data-format="money">' .
		         					formatCurrentNumber($fields['stockCOGS']) .
		         			'	</td>' .
		        			'</tr>';

	        if(validateHttp('part')){
	        	$table .= '[@]';
	        }

			$stock->MoveNext();  
			// $totalRows++;
		}
	    $stock->Close();
	}

	$foot = 	'</tbody>' .
						'<tfoot>' .
						'	<tr>' .
						'		<th colspan="7">TOTAL</th>' .
						'		<th class="text-right"></th>' .
						'		<th class="text-right"></th>' .
						'		<th class="text-right"></th>' .
						'		<th class="text-right"></th>' .
						'	</tr>' .
						'</tfoot>';


	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable 						= $head . $table . $foot;
		$jsonResult['table'] 	= $fullTable;

		header('Content-Type: application/json'); 
		dai(json_encode($jsonResult));
	}
}

if(validateHttp('action') == 'generalTableByDay'){

	$head 	= 	'<thead>' .
		  		'	<tr>' .
		    	'		<th>Fecha</th>' .
		    	'		<th>Artículo</th>' .
		    	'		<th>Código</th>' .
		    	'		<th>Sucursal</th>' .
		    	'		<th>Usuario</th>' .
		    	'		<th class="text-center">Existencia</th>' .
		    	'		<th class="text-center">Costo Uni.</th>' .
		    	'	</tr>' .
		  		'</thead>' .
		  		'<tbody>';

	$limits = getTableLimits($limitDetail,$offsetDetail);

	$item 	= '';
	$iId 	= $db->Prepare(dec(validateHttp('ii')));
	$between = ' BETWEEN "' . $startDate . '" AND "' . $endDate . '"';

	if(validateHttp('ii')){
		$item 		= ' AND itemId = ' . $iId;
		$between 	= '';
	}

	$stock 	= ncmExecute('SELECT * 
							FROM stock 
							WHERE stockDate
							' . $between . '
							' . $item . '
							' . $roc . '
							ORDER BY stockId ASC
							' . $limits,
							[],false,true
						);
		      	
	if($stock){
	  	$isdata 		= true;
	  	$allUsers 		= getAllUsers();
	  	$item 			= getAllItems();
	  	$translateFrom 	= ['production','adjustment','transfer','void','sale','purchase','other','return','count'];
	  	$translateTo 	= ['Producción','Ajuste','Transferencia','Anulación','Venta','Compra','Otro','Devolución','Conteo'];

	  	$dated 			= [];
	  	while (!$stock->EOF) {
	  		$fields 		= $stock->fields;
	  		$source 		= str_replace($translateFrom, $translateTo, $fields['stockSource']);
	    	$user 			= iftn($allUsers[$fields['userId']]['name'],'Sin Usuario');
	    	$outletName 	= getCurrentOutletName($fields['outletId']);
	    	$locationName 	= getLocationName($fields['locationId']);
	    	$itemName 		= $item[$fields['itemId']]['name'];
	    	$itemSKU 		= $item[$fields['itemId']]['sku'];
	    	$arrow 			= ($fields['stockCount'] > 0) ? '+' : '';
	    	$transaction 	= ($fields['transactionId']) ? enc($fields['transactionId']) : false;
	    	$note 			= unXss($fields['stockNote']);
	    	$eId 			= enc($fields['stockId']);

	  		$date 			= date('Y-m-d',strtotime($fields['stockDate'])) . enc($fields['itemId']);
	  		$dated[$date] 	= [
	  							'date' 		=> $fields['stockDate'],
	  							'id' 		=> $fields['itemId'],
	  							'name' 		=> $itemName,
	  							'sku' 		=> $itemSKU,
	  							'outlet' 	=> $outletName,
	  							'user' 		=> $user,
	  							'onHand' 	=> $fields['stockOnHand'],
	  							'COGS' 		=> $fields['stockCOGS']
	  						];

	  		$stock->MoveNext();  
	  	}
	  	$stock->Close();

	  	foreach ($dated as $date => $fields) {	    	

	    	$table .=	'<tr>' .
	         			'	<td data-order="' . $fields['date'] . '">' .
	         					niceDate($fields['date']) .
	         			'	</td>' .
	         			'	<td>' .
	         			' 		<a href="/@#items&i=' . enc($fields['id']) . '" target="_blank" class="hidden-print">' .
	         						$fields['name'] .
	         			' 		</a>' .		
	         			' 		<span class="visible-print">' . $fields['name'] . '</span>' .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['sku'] .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['outlet'] .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['user'] .
	         			'	</td>' .
	         			'	<td class="bg-light lter text-right ' . ( ($fields['onHand'] <= 0) ? 'text-danger' : '' ) . '" data-filter="' . ( ($fields['onHand'] == 0) ? 'quiebre' : '' ) . '">' .
	         					formatQty($fields['onHand'],3) .
	         			'	</td>' .
	         			'	<td class="bg-light lter text-right" data-order="' . $fields['COGS'] . '" data-format="money">' .
	         					formatCurrentNumber($fields['COGS']) .
	         			'	</td>' .
	        			'</tr>';

	        if(validateHttp('part')){
	        	$table .= '[@]';
	        }

			$totalRows++;
		}
	    
	}

	$foot = 	'</tbody>' .
				'<tfoot>' .
				'	<tr>' .
				'		<th colspan="5">TOTAL</th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'	</tr>' .
				'</tfoot>';


	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		header('Content-Type: application/json'); 
		dai(json_encode($jsonResult));
	}
}

if(validateHttp('widget') == 'inventory'){
  theErrorHandler('json');
  $db->cacheSecs  = 3600;
  $inventario     = getAllInventoryAndItemsModule();
  $out = json_encode([
                      'total' 	=> formatQty($inventario[2]),
                      'cost' 		=> @formatCurrentNumber($inventario[0]),
                      'sell' 		=> formatCurrentNumber($inventario[1])
                    ]);

  header('Content-Type: application/json'); 
  dai($out);
}

?>

<?=menuReports('');?>

	<?php
  	if(validateHttp('ii')){
  		$iId 			= db_prepare( dec(validateHttp('ii')) );
  		$iData 		= ncmExecute('SELECT itemName FROM item WHERE itemId = ? LIMIT 1',[$iId]);
  		$name 		= unXss($iData['itemName']);

  		$repTitle = [
										'title' 			=> '<div class="text-md text-right font-default">Historial de</div> ' . $name,
										'maxDays' 		=> $MAX_DAYS_RANGE,
										'hideDate' 		=> true,
										'hideChart' 	=> true
									];
  	}else{
  		$repTitle = [
										'title' 			=> '<div class="text-md text-right font-default">Historial</div> general del stock',
										'maxDays' 		=> $MAX_DAYS_RANGE,
										'hideChart' 	=> true
									];
  	}

  	echo reportsDayAndTitle($repTitle);
  	?>
   
  	<?php
  	if(!validateHttp('ii')){
  	?>
  	<div class="col-xs-12 no-padder text-center hidden-print">
  		<section class="col-sm-4">
            <div class="text-center wrapper-md">
				<div class="h1 m-t m-b-xs total font-bold" id="stockCOGS"><?=placeHolderLoader()?></div>
				Valor al costo
			</div>
        </section>
  		<section class="col-sm-4">
            <div class="text-center wrapper-md">
				<div class="h1 m-t m-b-xs total font-bold" id="stockSell"><?=placeHolderLoader()?></div>
				Valor de venta
			</div>
        </section>
        <section class="col-sm-4">
            <div class="text-center wrapper-md">
				<div class="h1 m-t m-b-xs total font-bold" id="stockTotal"><?=placeHolderLoader()?></div>
				Total en stock
			</div>
        </section>
  	</div>
  	<?php
  	}
  	?>

	<div class="col-xs-12 wrapper panel r-24x bg-white push-chat-down tableContainer table-responsive" style="min-height:500px">
	    <table class="table table1 col-xs-12 no-padder" id="tableTransactions">
	        <?=placeHolderLoader('table')?>
	    </table>
	    <div class="col-xs-4 visible-print b-t b-dark text-center font-bold text-u-c m-t-lg">
	    	Firma
	    </div>
    </div>

	<script>
	$(document).ready(() => {
		var baseUrl = '<?=$baseUrl?>';
		FastClick.attach(document.body);
		dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");

		<?php
		if(validateHttp('bd')){
		?>
		var rawUrl 	= baseUrl + "?action=generalTableByDay";
		var url 		= rawUrl + "&ii=<?=validateHttp('ii')?>";

		$.get(url, (result) => {

			var options = {
														"container" 	: ".tableContainer",
														"url" 				: url,
														"rawUrl" 			: rawUrl,
														"iniData" 		: result.table,
														"table" 			: ".table1",
														"sort" 				: 0,
														"footerSumCol": [5,6],
														"currency" 		: "<?=CURRENCY?>",
														"decimal" 		: decimal,
														"thousand" 		: thousandSeparator,
														"offset" 			: <?=$offsetDetail?>,
														"limit" 			: <?=$limitDetail?>,
														"nolimit" 		: true,
														"ncmTools"		: {
																							left 		: '<a href="#" class="btn btn-default exportTable" data-table="tableTransactions" data-name="Inventario por día">Exportar Listado</a>',
																							right 	: ''
																		  			},
														"colsFilter"	: {
																							name 	: 'inventoryHistoryByDay',
																							menu 	:  [
																													{"index":0,"name":"Fecha","visible":true},
																													{"index":1,"name":"Artículo","visible":true},
																													{"index":2,"name":"Código / SKU","visible":false},
																													{"index":3,"name":'Sucursal',"visible":false},
																													{"index":4,"name":'Usuario',"visible":false},
																													{"index":5,"name":'Existencia',"visible":true},
																													{"index":6,"name":'Costo Uni.',"visible":true}
																												]
																						}
										    				
										};

			manageTableLoad(options,function(oTable){
				loadTheTable(options,oTable);
			});

		});
		<?php
		}else{
		?>
		
		var rawUrl 	= baseUrl + "?action=generalTable";
		var url 	= rawUrl + "&ii=<?=validateHttp('ii')?>";

		$.get(url,function(result){
			//var result = JSON.parse(result);

			var options = {
							"container" 	: ".tableContainer",
							"url" 			: url,
							"rawUrl" 		: rawUrl,
							"iniData" 		: result.table,
							"table" 		: ".table1",
							"sort" 			: 0,
							"footerSumCol" 	: [7,8,9,10],
							"currency" 		: "<?=CURRENCY?>",
							"decimal" 		: decimal,
							"thousand" 		: thousandSeparator,
							"offset" 		: <?=$offsetDetail?>,
							"limit" 		: <?=$limitDetail?>,
							"nolimit" 		: true,
							"ncmTools"		: {
												left 	: '<a href="#" class="btn btn-default exportTable" data-table="tableTransactions" data-name="Inventario">Exportar Listado</a><a href="/@#report_inventory?<?=validateHttp('ii') ? 'ii=' . validateHttp('ii') . '&bd=1' : 'bd=1'?>" class="btn btn-default hidden">Por Día</a>',
												right 	: ''
											  },
							"colsFilter"		: {
												name 	: 'inventoryHistory2',
												menu 	:  [
																{"index":0,"name":"Fecha","visible":true},
																{"index":1,"name":"Artículo","visible":true},
																{"index":2,"name":"Código / SKU","visible":false},
																{"index":3,"name":'Sucursal',"visible":false},
																{"index":4,"name":'Depósito',"visible":false},
																{"index":5,"name":'Usuario',"visible":false},
																{"index":6,"name":'Fuente',"visible":true},
																{"index":7,"name":'Ingreso',"visible":true},
																{"index":8,"name":'Egreso',"visible":true},
																{"index":9,"name":'Existencia',"visible":false},
																{"index":10,"name":'Costo Uni.',"visible":true}
															]
											  }
			    				
			};

			manageTableLoad(options,function(oTable){
				loadTheTable(options,oTable);
			});
		});

		<?php
		}
		?>

		<?php
	  if(!validateHttp('ii')){
	  ?>
		$.get(baseUrl + '?widget=inventory',function(result){
			$('#stockCOGS').text(result.cost);
			$('#stockSell').text(result.sell);
			$('#stockTotal').text(result.total);
		});
		<?php
	  }
	  ?>

		var loadTheTable = function(tableOps,oTable){
			onClickWrap('.doc',function(event,tis){
				var load = tis.attr('href');
				loadForm(load,'#modalLarge .modal-content',function(){
					$('#modalLarge').modal('show');
				});
			},false,true);


		};
	});
	</script>

<?php
include_once('includes/compression_end.php');
dai();
?>