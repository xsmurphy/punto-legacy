<?php
include_once('includes/top_includes.php');
topHook();
allowUser('sales','view');

$MAX_DAYS_RANGE = 31;

$baseUrl = '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

$roc 			= getROC(1);
$isdata 		= true;

$table 			= '';
$limitDetail	= validateHttp('limit') ? validateHttp('limit') : 500;
$offsetDetail	= validateHttp('offset') ? validateHttp('offset') : 0 ;
$table 			= '';
$jsonResult 	= [];

if(validateHttp('action') == 'generalTable'){
	theErrorHandler('json');

	$head 	= 	'<thead>' .
		  		'	<tr>' .
		  		' 		<th>Acreditado</th>' .
		  		'		<th>Estado</th>' .
		    	'		<th>Fecha</th>' .
		    	'		<th>Fuente</th>' .
		    	'		<th>Nro. de Orden</th>' .
		    	'		<th>Cod. de Autorización</th>' .
		    	'		<th>Nro. de Operación</th>' .
		    	'		<th>Sucursal</th>' .
		    	'		<th class="text-center">Total</th>' .
		    	'	</tr>' .
		  		'</thead>' .
		  		'<tbody>';

	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'from' 			=> $startDate,
			    'to' 			=> $endDate,
			    'cache' 		=> 60
			  ];

	$result = curlContents('https://api.encom.app/get_vpayments','POST',$data);
	

	if($_GET['debug']){
		print_r($result);

		die();
	}

	$result = json_decode($result,true);

	if(!$result['error'] && validity($result,'array')){
		$orderDetails = [];

		$totalApproved 	= 0;
		$totalDeposited	= 0;
		$totalSales 	= 0;
	
	  	foreach ($result['success'] as $key => $fields) {

	  		$depositedTxt 	= '';
	  		$depositedIcon 	= '<i class="material-icons">history_toggle_off</i>';

	  		if($fields['deposited']){
	  			$depositedTxt 	= 'Depositado';
	  			$depositedIcon 	= '<i class="material-icons text-success">check</i>';
	  			$totalDeposited += $fields['amount'];
	  		}

	    	switch ($fields['status']) {
	          case 'PENDING':
	            $statusColor  = 'light';
	            $statusName   = 'Pendiente';
	            break;
	          case 'DENIED':
	            $statusColor  = 'danger';
	            $statusName   = 'Rechazado';
	            break;
	          case 'APPROVED':
	            $statusColor  = 'success';
	            $statusName   = 'Aceptado';
	            $totalApproved += $fields['amount'];
	            break;
	        }

	        $source     	= 'QR';

	        $orderDetails[] = $fields['data'];

	    	$table .=	'<tr data-load="/a_report_transactions?action=edit&uid=' . $fields['eUID'] . '&ro=1" class="clickrow pointer">' .
	    				'	<td data-filter="' . $depositedTxt . '">' .
	         					$depositedIcon .
	         			'	</td>' .
	    				'	<td>' .
	         			' 		<span class="label bg-' . $statusColor . ' lter text-u-c">' . $statusName . '</span>' .
	         			'	</td>' .
	         			'	<td data-order="' . $fields['date'] . '">' .
	         					niceDate($fields['date'],true) .
	         			'	</td>' .
	         			'	<td>' .
	         					$source .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['orderNo'] .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['authCode'] .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['operationNo'] .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['outletName'] .
	         			'	</td>' .
	         			'	<td class="text-right" data-order="' . $fields['amount'] . '" data-format="money">' .
	         					formatCurrentNumber($fields['amount']) .
	         			'	</td>' .
	        			'</tr>';

	        $totalSales++;

	        if(validateHttp('part') && !validateHttp('singleRow')){
	        	$table .= '[@]';
	        }
		}
	}

	$foot = 	'</tbody>' .
				'<tfoot>' .
				'	<tr>' .
				'		<th colspan="8" class="font-bold">Total</th>' .
				'		<th class="font-bold text-right"></th>' .
				'	</tr>' .
				'</tfoot>';

	if(validateHttp('part')){
		jsonDieResult($table);
	}else{
		$fullTable 				= $head . $table . $foot;
		$jsonResult['table'] 	= $fullTable;
		$jsonResult['details'] 	= 	[
										'depositedR' 		=> $totalDeposited, 
										'approvedR' 		=> $totalApproved,
										'pendingDepositR' 	=> ($totalApproved - $totalDeposited),
										'deposited' 		=> formatCurrentNumber($totalDeposited),
										'approved' 			=> formatCurrentNumber($totalApproved),
										'pendingDeposit' 	=> formatCurrentNumber($totalApproved - $totalDeposited),
										'totalSales' 		=> formatCurrentNumber($totalSales)
									];
		

		jsonDieResult($jsonResult);
	}
}

if(validateHttp('action') == 'delete' && validateHttp('id')){
	if(!allowUser('sales','delete',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$id 	= dec(validateHttp('id'));

	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'id' 			=> validateHttp('id'),
			    'parent' 		=> validateHttp('parent')
			  ];

	$result = curlContents('https://api.encom.app/delete_transaction','POST',$data);
	
	header('Content-Type: application/json'); 
	dai($result);
}

?>

	<?=menuReports('');?>

	<?php
	echo reportsDayAndTitle([
    							'title' 		=> '<div class="text-md text-right font-default">Pagos</div> ePOS',
    							'maxDays' 		=> $MAX_DAYS_RANGE,
    							'hideChart' 	=> true
    						]);
	?>

	<div class="col-xs-12 no-padder text-center hidden-print">

  		<div class="col-md-4 col-sm-12 col-xs-12 text-center no-padder">
           <canvas id="chart-contado" class="" height="200" style="max-height:200px;"></canvas>
           <div class="donut-inner" style=" margin-top: -140px; margin-bottom: 100px;">
             <div class="h1 m-t creditoCount font-bold totalsChart"><?=placeHolderLoader()?></div>
             <span>Ventas</span>
           </div>
           <div class="m-t-n h4">&nbsp;</div>
        </div>


        <div class="col-md-8 col-sm-12 col-xs-12 no-padder hidden-print">

	        <div class="col-xs-12 no-padder text-center font-bold h4 m-b">
				Resumen del periodo
			</div>

	  		<section class="col-md-4 col-sm-6 col-xs-12">
	  			<div class="b-b text-center wrapper-md">
					<div class="h1 m-b-xs m-t total font-bold pendingDepositChart"><?=placeHolderLoader()?></div>
					A Depositar
				</div>
	  		</section>
	  		<section class="col-md-4 col-sm-6 col-xs-12">
	            <div class="b-b text-center wrapper-md">
					<div class="h1 m-b-xs m-t total font-bold depositedChart"><?=placeHolderLoader()?></div>
					Depositado
				</div>
	        </section>
	  		<section class="col-md-4 col-sm-6 col-xs-12">
	            <div class="b-b text-center wrapper-md">
					<div class="h1 m-b-xs m-t total font-bold approvedChart"><?=placeHolderLoader()?></div>
					Cobrados
				</div>
	        </section>

        </div>
  	</div>

  	<div class="col-xs-12 no-padder m-t m-b-lg push-chat-down">
	    <section class="col-sm-12 no-padder" id="reportsTablesAndTabs">
	        
	        <section class="panel r-24x">
	            <div class="panel-body">
	                
	            	<div id="generalTable">                             	
	                	<table class="table table1 col-xs-12 no-padder" id="tableTransactions"><?=placeHolderLoader('table')?></table>
	                </div>

	            </div>
	        </section>
	    </section>
	</div>

	<script>
	$(document).ready(function(){
		var baseUrl = '<?=$baseUrl?>';
		dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");
		FastClick.attach(document.body);		
		var url 	= baseUrl + "?action=generalTable";
		var rawUrl 	= url;
		$.get(url, (result) => {

			var options = {
					"container" 	: "#generalTable",
					"url" 			: url,
					"iniData" 		: result.table,
					"table" 		: ".table1",
					"sort" 			: 2,
					"footerSumCol" 	: [8],
					"currency" 		: "<?=CURRENCY?>",
					"decimal" 		: decimal,
					"thousand" 		: thousandSeparator,
					"offset" 		: <?=$offsetDetail?>,
					"limit" 		: <?=$limitDetail?>,
					"nolimit" 		: false,
					"noMoreBtn" 	: false,
					"tableName" 	: 'tableOrders',
					"fileTitle" 	: 'Listado de Pagos Online',
					"ncmTools"		: {
											left 	: '',
											right 	: ''
										  },
					"colsFilter"	: {
											name 		: 'vPayments1',
											menu 		:  [
																{"index":0,"name":'Acreditado',"visible":true},
																{"index":1,"name":'Estado',"visible":true},
																{"index":2,"name":'Fecha',"visible":true},
																{"index":3,"name":'Fuente',"visible":false},
																{"index":4,"name":'Nro. Orden',"visible":true},
																{"index":5,"name":'Cod. Autorización',"visible":true},
																{"index":6,"name":'Nro. Operación',"visible":false},
																{"index":7,"name":'Sucursal',"visible":false},
																{"index":8,"name":'Total',"visible":true}
															]
										  },
					"clickCB" 		: (event,tis) => {
										
									  	var load = tis.data('load');
										loadForm(load,'#modalLarge .modal-content', () => {
											$('#modalLarge').modal('show');
										});
		  							}
			};

			ncmDataTables(options, (oTable) => {
				loadTheTable(options,oTable);
			});

			var itemsTable 	= '<thead><tr><th>Artículo</th><th class="text-center">Cantidad</th></tr></thead><tfoot></tfoot><tbody>';
			var itemList 	= {};
			var itmDetails 	= Object.keys(result.details).reduce(function (r, k) {
							        return r.concat(result.details[k]);
							    }, []);

			$.each(itmDetails,function(i,item){
				var itemId = item.itemId;
				
				if(typeof itemList[itemId] === 'undefined') {
					itemList[itemId] = {name : item.name, qty : parseFloat(item.count)};
				} else {
				    itemList[itemId].qty = parseFloat(itemList[itemId].qty) + parseFloat(item.count);
				}
			});

			$.each(itemList,function(itemId,data){
				itemsTable += 	'<tr>' +
								'	<td class="font-bold">' + data.name + '</td>' +
								'	<td class="bg-light lter text-right" data-sort="' + data.qty + '">' + data.qty + '</td>' +
								'</tr>';
			});

			itemsTable += '</tbody>';

			var options2 		= Object.assign({}, options);
			options2.iniData 	= itemsTable;
			options2.container 	= "#detailTable";
			options2.table 		= ".table2";
			options2.sort 		= 1;
			options2.noMoreBtn 	= true;
			options2.colsFilter	= {
									name 		: 'tableOrdersItems',
									menu 		:  [
													{"index":0,"name":'Artículo',"visible":true},
													{"index":1,"name":'Cantidad',"visible":true}
													]
								  }

			
			ncmDataTables(options2);

			$('.depositedChart').text(result.details.deposited);
			$('.approvedChart').text(result.details.approved);
			$('.pendingDepositChart').text(result.details.pendingDeposit);
			$('.totalsChart').text(result.details.totalSales);

			Chart.defaults.global.responsive 			= true;
			Chart.defaults.global.maintainAspectRatio 	= false;
			Chart.defaults.global.legend.display 		= false;

			var chartContado = document.getElementById('chart-contado').getContext("2d");

		    var methods = new Chart(chartContado, {
		      type      : 'doughnut',
		      data      : {
		        labels 	: ['Depositado','Aprovado','A depositar'],
		        datasets: [
		        {
		          data: [result.details.deposited,result.details.approved,result.details.pendingDeposit],
		          backgroundColor: ['#6BC0D1','#778490','#d9e4e6']
		        }]
		      },
		      animation : true,
		      options   : {
		        cutoutPercentage:85
		      }
		    });
			
		});

		var loadTheTable = function(tableOps,oTable){
			onClickWrap('.delete',function(event,tis){
				ncmDialogs.confirm("Realmente quiere eliminar esta orden?",'','question',function(really){
					if(really){
						var load 	= tis.attr('href');
						var $row  	= tis.closest('tr');
						oTable.row($row).remove().draw();
						$.get(load,function(result){
							if(result.success){
								message('Eliminado','success');
							}else{
								message('No se pudo eliminar','danger');
							}
						});
					}
				});
				
			},false,true);

			ncmHelpers.defaultEvents();


		};
	});
	</script>

<?php
include_once('includes/compression_end.php');
dai();
?>