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

allowUser('expenses','view');

$baseUrl = '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 				= getROC(1);
$isdata 			= false;

$export 			= validateHttp('export') ? true : false;
$itemsArray 		= [];
$totalSold 			= 0;
$totalSales			= 0;
$totalToExpire 		= 0;
$totalExpired 		= 0;
$totalOthers		= 0;
$toExpire 			= strtotime('+5 days');
$expired 			= strtotime('today');

if(validateHttp('action') == 'generalTable'){

	if(OUTLET_ID < 2){
		$jsonResult['table'] = '<tbody><tr><td><div class="text-center"><img src="https://assets.encom.app/images/emptystate2.png" width="130" class="m-b-md"><div class="font-bold h3"> Debe seleccionar una sucursal</div><div>Por favor seleccione una sucursal y vuelva a intentar</div></div></td></tr></tbody>';

		header('Content-Type: application/json');
		dai(json_encode($jsonResult));
	}
	
	$sql =		"SELECT * FROM item WHERE itemTrackInventory = 1 AND itemStatus = 1 AND itemType IN('product','compound') AND companyId = ? LIMIT 2000";


	$head = 	'<thead class="text-u-c">'.
				'	<tr>'.
				'		<th>Artículo</th>'.
				'		<th>SKU/Código</th>'.
				'		<th class="text-center">P. Costo</th>'.
				'		<th class="text-right">Stock Total</th>'.
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	$result 	= ncmExecute($sql,[COMPANY_ID],false,true);

	if($result){

		$fullTable 	= [];
		$table 		= '';
		$allStock 	= getAllItemStock();
		$i 			= 0;

		$locations = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'location' " . $roc . " ORDER BY taxonomyName ASC",[],false,true,true);

		while (!$result->EOF) {
			$fields 		= $result->fields;
			$id 			= $fields['itemId'];
			$eid 			= enc($id);
			$name 			= $fields['itemName'];
			$sku	 		= $fields['itemSKU'];
			$stock	 		= $allStock[$id]['onHand'];
			$cogs	 		= $allStock[$id]['cogs'];
			$subStock 		= 0;//sumo las cantidades en depositos para restarle al total
			$brdr 			= 'b-l b-light b-5x';
			$losStock 		= 0;

			//check even or odd
			$remainder = $i % 2;
			if($remainder == 0){
				//$brdr 			= '';
			}

			$table 	.= 	'<tr data-load="" class="clickrow itemInCat" style="page-break-after: always;" data-family="' . $eid . '">'.
						'	<th>' . $name . '</th>'.
						'	<th class="text-muted">' . $sku . '</th>'.
						'	<th class="text-right bg-light bg">' . CURRENCY . ' ' . formatCurrentNumber($cogs) . '</th>'.
						'	<th class="text-right bg-light bg">' . formatQty($stock) . '</th>'.
						'</tr>';

			$table 	.= 	'<tr data-family="' . $eid . '" class="fullSearchHide">'.
						'	<th style="max-width:80px!important;"></th>' .
						'	<th class="text-muted">Depósito</th>'.
						'	<th class="text-center text-muted">Stock Mínimo</th>'.
						'	<th class="text-center text-muted">Cantidad</th>'.
						'</tr>';

			if($locations){
				foreach ($locations as $key => $fields) {
					
					$depCount 	= ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',[$fields['taxonomyId'],$id]);
					$sTrigger 	= (OUTLET_ID < 2) ? ['stockTriggerCount'=>0] : ncmExecute('SELECT * FROM stockTrigger WHERE outletId = ? AND itemId = ? LIMIT 1',[$fields['taxonomyId'],$id]);

					if($depCount){
						$losStock 	= $depCount['toLocationCount'];
					}
					
					$subStock 	+= $losStock;

					if($sTrigger['stockTriggerCount'] > 1){
						if($losStock > $sTrigger['stockTriggerCount']){
							$barBg 		= 'bg-success';
							$barPercent = 100;
							$textClr 	= 'font-bold text-success';
						}else if($losStock <= $sTrigger['stockTriggerCount'] && $losStock > 0){
							$barBg 		= 'bg-warning';
							$barPercent = 50;
							$textClr 	= 'font-bold text-warning';
						}else{
							$barBg 		= 'bg-danger';
							$barPercent = 20;
							$textClr 	= 'font-bold text-danger';
						}
					}else{
						if($sTrigger['stockTriggerCount'] < 2 && $losStock > 1){
							$barBg 		= 'bg-success';
							$barPercent = 100;
							$textClr 	= 'font-bold text-success';
						}

						if($losStock < 1){
							$barBg 		= 'bg-danger';
							$barPercent = 20;
							$textClr 	= 'font-bold text-danger';
						}
					}

					$table 	.= 	'<tr data-family="' . $eid . '" class="fullSearchHide">' .
								'	<td>' . 
								' 		<div class="progress progress-xs dker progress-striped hidden" style="max-width:80px!important;">' .
		                  		'			<div class="progress-bar ' . $barBg . '" style="width: ' . $barPercent . '%"></div>' .
		                		'		</div>' .
								'	</td>' .
								'	<td class="">' . unXss($fields['taxonomyName']) . '</td>' .
								'	<td class="bg-light lter text-right">' . formatQty($sTrigger['stockTriggerCount']) . '</td>' .
								'	<td class="text-right bg-light lter ' . $textClr . '">' . formatQty($losStock) . '</td>' .
								'</tr>';
				}
			}

			$sTrigger 	= (OUTLET_ID < 2) ? ['stockTriggerCount'=>0] : ncmExecute('SELECT * FROM stockTrigger WHERE outletId = ? AND itemId = ? LIMIT 1',[OUTLET_ID,$id]);

			$losStock = ($stock - $losStock);

			if($sTrigger['stockTriggerCount'] > 1){
				if($losStock > $sTrigger['stockTriggerCount']){
					$barBg 		= 'bg-success';
					$barPercent = 100;
					$textClr 	= 'font-bold text-success';
				}else if($losStock <= $sTrigger['stockTriggerCount'] && $losStock > 0){
					$barBg 		= 'bg-warning';
					$barPercent = 50;
					$textClr 	= 'font-bold text-warning';
				}else{
					$barBg 		= 'bg-danger';
					$barPercent = 20;
					$textClr 	= 'font-bold text-danger';
				}
			}else{
				if($sTrigger['stockTriggerCount'] < 2 && $losStock > 1){
					$barBg 		= 'bg-success';
					$barPercent = 100;
					$textClr 	= 'font-bold text-success';
				}

				if($losStock < 1){
					$barBg 		= 'bg-danger';
					$barPercent = 20;
					$textClr 	= 'font-bold text-danger';
				}
			}

			$table 	.= 	'<tr data-family="' . $eid . '" class="fullSearchHide">' .
						'	<td>' . 
						' 		<div class="progress progress-xs dker progress-striped hidden" style="max-width:80px!important;">' .
                  		'			<div class="progress-bar ' . $barBg . '" style="width: ' . $barPercent . '%"></div>' .
                		'		</div>' .
						'	</td>' .
						'	<td class="">Principal</td>' .
						'	<td class="bg-light lter text-right">' . formatQty($sTrigger['stockTriggerCount']) . '</td>' .
						'	<td class="text-right bg-light lter ' . $textClr . '">' . formatQty($losStock) . '</td>' .
						'</tr>';

			$table 	.= 	'<tr class="fullSearchHide" data-family="' . $eid . '"><td colspan="4" class="hidden-print noxls">&nbsp;</td></tr>';
			

			$i++;
			$result->MoveNext();
		}

	}

	$foot = 	'</tbody>'.
				'<tfoot>'.
				'	<tr>'.
				'		<th colspan="7"></th>'.
				'	</tr>'.
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

?>

    
  	<div class="col-xs-12 m-t-sm">
  		<?=headerPrint();?>
  		<div class="pull-left">
  			<a href="/@#report_stock_day" class="btn dropdown-toggle b b-light bg-white font-bold r-3x">Por Día</a>
  		</div>
		<div class="pull-right">
			<span class="no-padder h1 m-n font-bold" id="pageTitle"><div class="text-md text-right font-default">Niveles de</div> Stock</span>
		</div>
	</div>

  	<div class="col-xs-12 wrapper m-b hidden-print hidden" id="paymentStatusWidget">	
		<section class="col-sm-3">
			<div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold globalPay"><?=placeHolderLoader()?></div>
				Total
			</div>
		</section>
		<section class="col-sm-3">
			<div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold globalAccounts"><?=placeHolderLoader()?></div>
				Cuentas
			</div>
		</section>
		<section class="col-sm-3">
			<div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold globalDue"><?=placeHolderLoader()?></div>
				Vencidas
			</div>
		</section>
		<section class="col-sm-3">
			<div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold globalToDue"><?=placeHolderLoader()?></div>
				Por Vencer
			</div>
		</section>
	</div>

  	<div class="col-xs-12 panel wrapper r-24x m-t push-chat-down tableContainer">
    	<div class="table-responsive">
    		<div class="col-xs-12 no-padder m-b hidden-print">
    			<div class="col-sm-9 no-padder">
    				<span class="dropdown" title="Opciones" data-placement="right">
						<a href="#" class="btn dropdown-toggle b b-light r-3x" data-toggle="dropdown">
					   	<span class="material-icons">more_horiz</span>
						</a>
						<ul class="dropdown-menu animated fadeIn speed-4x" role="menu">
					  		<li>
								<a class="exportTable text-default" data-table="tableSummary" data-name="Niveles de Stock" href="#">
									<span class="material-icons m-r-sm">get_app</span> A Excel
								</a>
							</li>
					   	<li> 
								<a class="hideSelectedRows text-default hidden-xs" href="#">
									<span class="material-icons m-r-sm selectCounter">visibility_off</span> Mostrar/Ocultar
								</a>
							</li>
					   	<li> 
								<a class="text-default" href="javascript:window.print();">
									<span class="material-icons m-r-sm">print</span> Imprimir
								</a>
							</li>
					   	<li> 
								<a class="text-default manualSort hidden-xs" href="#">
									<span class="material-icons m-r-sm">drag_indicator</span> Orden Manual
								</a>
							</li>
						</ul>
					</span>
    			</div>
    			<div class="col-sm-3 no-padder">
    				<input type="text" class="form-control no-bg rounded pull-right" placeholder="Filtrar listado" id="textSearch" />
    			</div>    			
    		</div>

        	<table class="table table-hover table1 col-xs-12 no-padder" id="tableSummary">
        		<?=placeHolderLoader('table')?>
        	</table>
        </div>
	</div>

<script>
FastClick.attach(document.body);
var baseUrl = '<?=$baseUrl?>';
dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");
$(document).ready(function(){

	var rawUrl 		= baseUrl + "?action=generalTable";
	var loadUrl 	= rawUrl + "&noDate=true&cusId=<?=validateHttp('ci')?>&state=<?=validateHttp('state')?>";

	$.get(loadUrl,function(result){

		$('.table1').html(result.table);

		ncmHelpers.fullScreenTextSearch('.itemInCat','#textSearch');

		onClickWrap('.exportTable',function(event,tis){
			var theTable 	= tis.data('table');
			var name 		= tis.data('name');

			table2Xlsx(theTable,name);
		},false,true);

		var shiftKeyRow = {
			shiftKeyCallBack : function(event,tis){
				console.log('shift click');
				var family = tis.data('family');
				console.log('family',family);
				var $family = $('[data-family="' + family + '"]');
				var classes = 'selected bg-light dker b-l b-3x b-info';

				$family.each(function(){
					var $tis = $(this);

					if(!$tis.hasClass('selected')){
						$tis.addClass(classes);
					}else{
						$tis.removeClass(classes);
					}

				});

				
				
				//more events on shiftclick
			}
		};

		
		ncmHelpers.onClickWrap('tr.clickrow',function(event,tis){
			/*var load = tis.data('load');
			loadForm(load,'#modalLarge .modal-content',function(){
				$('#modalLarge').modal('show');
			});*/
		},false,shiftKeyRow);

		ncmHelpers.onClickWrap('.hideSelectedRows',function(event,tis){
			var $selected 	= $('table#tableSummary tbody tr.selected');
			var hideClasses = 'text-l-t text-muted hidden-print noxls b-danger';

			if($selected.length > 0){
				$selected.each(function(i,v){
					var tiss = $(this);
					tiss.toggleClass(hideClasses);
				});

				$selected.each(function(){
					$(this).removeClass('selected bg-light dker');
				});
				$('.hideSelectedRows .selectCounter').addClass('material-icons').html('visibility_off');
			}else{
				$('table#tableSummary tbody tr').removeClass(hideClasses + ' b-info b-l');
			}

		});

		onClickWrap('.loadCustomer',function(event,tis){
			var load = tis.data('load');
			loadForm(load,'#modalLarge .modal-content',function(){
				$('#modalLarge').modal('show');
			});
		},false,true);

		onClickWrap('#reportExport',function(event,tis){
			var url = '?export=true<?=($_GET['state']) ? '&state=outcome' : ''?>';
			window.open(url);
		},false,true);

		onClickWrap('.addPayment',function(event,tis){
			var id = tis.data('id');
			$('.editting').removeClass('editting');
			tis.closest('tr').addClass('editting');
			loadForm('/a_report_purchases?action=paymentForm&id=' + id,'#modalTiny .modal-content',function(){
				$('#modalTiny').modal('show');
				masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
				$('#payAmountField').focus();
			});
		},false,true);

		<?php
		autoFilterInputTable('.tableContainer .dataTables_filter input');
		?>
		
		$('#modalTiny').off('shown.bs.modal').on('shown.bs.modal', function() {
			$('.datetimepicker').datetimepicker({
				format            : 'YYYY-MM-DD HH:mm:ss',
				showClear         : true,
				ignoreReadonly    : true
			});

			submitForm('#addPaymentForm',function(tis,result){
				if(result){
					$.get(baseUrl + '?action=getRowPaid&singleRow=' + result,function(data){
						if(data != 'false'){
							$('.editting').html(data);
							$('#modalTiny').modal('hide');
						}
					});
				}
			});
		});

		$('.globalPay').text(result.globalPay);
		$('.globalAccounts').text(result.globalAccounts);
		$('.globalDue').text(result.globalDue);
		$('.globalToDue').text(result.globalToDue);

	});

});

</script>

<?php
include_once('includes/compression_end.php');
dai();
?>