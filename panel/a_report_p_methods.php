<?php

include_once('includes/top_includes.php');
topHook();
allowUser('sales','view');

$baseUrl = '/' . basename(__FILE__,'.php');

$startPageLoad 	= startPageLoadTimeCalculator();

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 				= getROC(1);
$isdata 			= false;

$itemsArray 		= [];
$maxItemsInGraph 	= 50;
$limitDetail		= 500;
$offsetDetail		= 0;

if(validateHttp('action') == 'generalTable'){

	ini_set('memory_limit', '256M');

	if(validateHttp('debug') == '1'){
		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		error_reporting(E_ALL);
	}

	$result   	= ncmExecute("SELECT * FROM transaction WHERE transactionType IN(0,5) AND transactionDate BETWEEN ? AND ? " . $roc, [$startDate, $endDate], false, true);
	$table 		= '';

	$head 		=	'<thead class="text-u-c">'.
					'	<tr>'.
					'		<th>Documento No.</th>'.
					'		<th>Cliente</th>'.
					'		<th>' . TIN_NAME . '</th>'.
					'		<th>Método</th>'.
					'		<th>Detalle</th>'.
					'		<th>Sucursal</th>'.
					'		<th class="text-center">Entregado</th>'.
					'		<th class="text-center">Total</th>'.
					'		<th class="text-center">Vendido</th>'.
					'	</tr>'.
					'</thead>'.
					'<tbody>';

	$table2 = '';
	$label 	= [];
	$data 	= [];
	if($result){

		$group 		= [];
		$custCache 	= [];

		while (!$result->EOF) {
			$fields 		= $result->fields;
			$new 			= json_decode($fields['transactionPaymentType'],true);
			$outletName 	= getCurrentOutletName($fields['outletId']);

			$customer 		= getCustomerData($fields['customerId'],'uid',true);

			//print_r($customer);

			$customerTin 	=  isset($customer['ruc']) ? $customer['ruc'] : '-';
			$invoicePrefix 	= '';

			if(!$invoicePrefix){
				$registerData 		= ncmExecute('SELECT * FROM register WHERE registerId = ? AND companyId = ?',[$fields['registerId'],COMPANY_ID],true);
				$invoicePrefix  	= $registerData['registerInvoicePrefix'];
			}

			if(is_array($new) && !empty($new)){
				foreach($new as $method => $meth){

					if($meth['type'] == 'check'){
						$extra = csvToBankData($meth['extra']);
					}else{
						$extra = $meth['extra'];
					}

					$pName 	= iftn(getPaymentMethodName($meth['type']),$meth['name']);

					$table .= 	'<tr data-load="/a_report_transactions?action=edit&id=' . enc($fields['transactionId']) . '&ro=1" class="clickrow" data-type="' . iftn($meth['type'],$meth['name']) . '">' .
								'	<td class="font-bold">'. $invoicePrefix . $fields['invoiceNo'] . '</td>' .
								'	<td>' . (isset($customer['name']) ? $customer['name'] : '') . '</td>' .
								'	<td>' . $customerTin . '</td>' .
								'	<td>' . $pName . '</td>' .
								'	<td>' . $extra . '</td>' .
								'	<td>' . $outletName . '</td>' .
								'	<td class="text-right bg-light lter" data-order="' . $meth['price'] . '" data-format="money">' . formatCurrentNumber($meth['price']) . '</td>' .
								'	<td class="text-right bg-light lter" data-order="' . $meth['total'] . '" data-format="money">' . formatCurrentNumber($meth['total']) . '</td>' .
								'	<td class="text-right bg-light lter" data-order="' . $fields['transactionTotal'] . '" data-format="money">' . formatCurrentNumber($fields['transactionTotal']) . '</td>' .
								'</tr>';
				}

				$group 	= groupByPaymentMethod($new,$group);
			}

			$result->MoveNext();
		}

		$label 	= [];
		$data 	= [];
		//asort($group);
		usort($group, function($a, $b) {
		    return $b['price'] - $a['price'];
		});
		$table2 = '';
		
		foreach($group as $dat){
			$name 		= iftn(getPaymentMethodName($dat['type'],true),getPaymentMethodName($dat['type']));
			$label[] 	= $name;
			$data[] 	= $dat['price'];
			$table2 	.= '<tr data-type="' . $dat['type'] . '"> <td>' . $name . '</td> <td class="text-right bg-light lter" data-order="' . $dat['price'] . '" data-format="money">' . formatCurrentNumber($dat['price']) . '</td> </tr>';
		}

		

		$result->Close();
	}

	$head2 = '<thead class="text-u-c"> <tr> <th>Método</th> <th class="text-center">Total</th> </tr> </thead> <tbody>';

	$foot2 = '</tbody> <tfoot class="text-u-c"> <tr> <th>Total</th> <th class="text-right"></th> </tr> </tfoot>';

	$foot = 	'</tbody>'.
				'<tfoot>'.
				'	<tr>'.
				'		<th colspan="3">TOTALES</th>'.
				'		<th></th>'.
				'		<th></th>'.
				'		<th></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'	</tr>'.
				'</tfoot>';

	$fullTable 				= $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;
	$jsonResult['table2'] 	= $head2 . $table2 . $foot2;
	$jsonResult['chart'] 	= ['labels' => $label,'data' => $data];

	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));

}

echo reportsDayAndTitle([
							'title' 		=> '<div class="text-md text-right font-default">Ventas por</div> Medios de Pago',
							'maxDays' 		=> $MAX_DAYS_RANGE
						]);

?>

<div class="col-xs-12 no-padder m-t m-b-lg push-chat-down">
	<section class="col-sm-12 no-padder">
	    <ul class="nav nav-tabs wrap-l-lg wrap-r-lg">
	        <li class="active">
	            <a href="#tab1" data-toggle="tab">Resumido</a>
	        </li>
	        <li>
	            <a href="#tab2" data-toggle="tab">Detallado</a>
	        </li>
	    </ul>
	    <section class="panel r-24x">
	        <div class="panel-body table-responsive">
	            <div class="tab-content m-b-lg">
	                <div class="tab-pane overflow-auto active" id="tab1" style="min-height:500px">
	                	<div class="tableGeneralContainer">
				        	<table class="table table2 col-xs-12 no-padder" id="tableMethodsGeneral">
				        		<?=placeHolderLoader('table')?>
				        	</table>
				        </div>
	                </div>

	                <div class="tab-pane overflow-auto" id="tab2" style="min-height:500px">
	                	<div class="tableContainer">
				        	<table class="table table1 hover col-xs-12 no-padder" id="tableMethods">
				        		<?=placeHolderLoader('table')?>
				        	</table>
				        </div>
	                </div>
	            </div>
	        </div>
	    </section>
	</section>
</div>

<script>
var baseUrl   	= "<?=$baseUrl?>",
startDate 		= "<?=$startDate?>",
endDate 		= "<?=$endDate?>",
tin_name  		= '<?=TIN_NAME?>',
rawUrl 			= baseUrl + "?action=generalTable",
url 			= rawUrl,
offset 			= <?=$offsetDetail?>,
limit 			= <?=$limitDetail?>,
currency 		= "<?=CURRENCY?>";

$(document).ready(function(){

	<?php
	if($_GET['update']){
	  ob_start();
	?>	

	dateRangePickerForReports(startDate,endDate);

	$.get(url,function(result){
		var options = {
						"container" 		: ".tableContainer",
						"url" 				: url,
						"rawUrl" 			: rawUrl,
						"iniData" 			: result.table,
						"table" 			: ".table1",
						"sort" 				: 0,
						"footerSumCol" 		: [6],
						"currency" 			: currency,
						"decimal" 			: decimal,
						"thousand" 			: thousandSeparator,
						"offset" 			: offset,
						"limit" 			: limit,
						"nolimit" 			: true,
						"tableName" 		: 'tableMethods',
						"fileTitle" 		: 'Medios de Pago Detallado',
						"ncmTools"			: {
													left 	: '',
													right 	: ''
											  },
						"colsFilter"		: {
												name 	: 'methodsDetails2',
												menu 	:  [
																{"index":0,"name":"# Documento","visible":true},
																{"index":1,"name":"Cliente","visible":true},
																{"index":2,"name":tin_name,"visible":true},
																{"index":3,"name":"Medio","visible":true},
																{"index":4,"name":'Detalle',"visible":true},
																{"index":5,"name":'Sucursal',"visible":true},
																{"index":6,"name":'Entregado',"visible":true},
																{"index":7,"name":'Total',"visible":true},
																{"index":8,"name":'Vendido',"visible":true}
															]
											  },
						"clickCB" 		: function(event,tis){
								var load = tis.data('load');
								loadForm(load,'#modalLarge .modal-content',function(){
									$('#modalLarge').modal('show');
								});
						}
		};

		ncmDataTables(options);

		if(result.chart.data){
			$('#myChart').removeClass('hidden');
			$('#loadingChart').addClass('hidden');

			var myChart = document.getElementById('myChart').getContext("2d");

			var gradientStroke = myChart.createLinearGradient(300, 0, 100, 0);
			gradientStroke.addColorStop(0, "#4cb6cb");
			gradientStroke.addColorStop(1, "#54cfc7");

			Chart.defaults.global.responsive = true;
			Chart.defaults.global.maintainAspectRatio = false;
			Chart.defaults.global.legend.display       = false;

			var dataD = {
			    labels: result.chart.labels,
			    datasets: [
			        {
			        	label: "Total",
			            data: result.chart.data,
			            backgroundColor: gradientStroke
			        }]
			};

			setTimeout(function(){
				var methods = new Chart(myChart, {
				    type: 'bar',
				    data: dataD,
				    animation: true,
				    options:chartBarStackedGraphOptions
				});
			}, 200);
		}

		var options = {
						"container" 		: ".tableGeneralContainer",
						"url" 				: url,
						"rawUrl" 			: rawUrl,
						"iniData" 			: result.table2,
						"table" 			: ".table2",
						"sort" 				: 0,
						"footerSumCol" 		: [1],
						"currency" 			: currency,
						"decimal" 			: decimal,
						"thousand" 			: thousandSeparator,
						"offset" 			: offset,
						"limit" 			: limit,
						"nolimit" 			: true,
						"tableName" 		: 'tableMethodsGeneral',
						"fileTitle" 		: 'Ranking de Medios de Pago',
						"ncmTools"		: {
											left 	: '',
											right 	: ''
										  },
						"colsFilter"	: {
											name 	: 'methodsGeneral',
											menu 	:  [
															{"index":0,"name":"Medio","visible":true},
															{"index":1,"name":"Total","visible":true}
														]
										  }
		};

		ncmDataTables(options);
	});
	
	<?php
	  $script = ob_gets_contents();
	  minifyJS([$script => 'scripts' . $baseUrl . '.js']);
	}
	?>	

});

</script>
<script src="scripts<?=$baseUrl?>.js?<?=date('d.i')?>"></script>


<?php
include_once('includes/compression_end.php');
dai();
?>