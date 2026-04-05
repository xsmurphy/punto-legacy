<?php
include_once('includes/top_includes.php');
topHook();
allowUser('sales','view');

$MAX_DAYS_RANGE = 31;
$baseUrl 		= '/' . basename(__FILE__,'.php');

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
		  		'		<th>Estado</th>' .
		    	'		<th>Fecha</th>' .
		    	'		<th>Para el</th>' .
		    	'		<th>Fuente</th>' .
		    	'		<th>Usuario</th>' .
		    	'		<th>Sucursal</th>' .
		    	'		<th>Cliente</th>' .
		    	'		<th class="text-center"># Orden</th>' .
		    	' 		<th>Etiquetas</th>' .
		    	' 		<th>Nota</th>' .
		    	'		<th class="text-center"># Documento</th>' .
		    	' 		<th>Total</th>' .
		    	' 		<th class="noxls"></th>' .
		    	'	</tr>' .
		  		'</thead>' .
		  		'<tbody>';

	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'from' 			=> $startDate,
			    'to' 			=> $endDate,
			    'duedate' 		=> false,
			    'limit' 		=> $limitDetail,
			    'offset' 		=> $offsetDetail,
			    'cache' 		=> 60,
			    'type' 			=> 12
			  ];

	$result = curlContents(API_URL. '/get_orders','POST',$data, false, false, 30);

	if(!empty($_GET['debug'])){
		print_r($result);

		die();
	}

	$result = json_decode($result,true);

	$orderDetails = [];
	if(empty($result['error']) && validity($result,'array')){
	
	  	foreach ($result as $key => $fields) {
	  		
	    	$name 			= $fields['order_name'];
	    	$source     	= validity($name,'numeric') ? 'table' : $name;
	    	$customer 		= getContactData(dec($fields['customer_id']),'uid');
	    	$customerName 	= getCustomerName($customer);

	    	switch ($fields['order_status']) {
	          case '2':
	            $statusColor  = 'warning';
	            $statusName   = 'En Espera';
	            $statusKName  = 'waiting';
	            break;
	          case '3':
	            $statusColor  = 'info';
	            $statusName   = 'En Proceso';
	            $statusKName  = 'in_progress';
	            break;
	          case '4':
	            $statusColor  = 'success';
	            $statusName   = 'Finalizado';
	            $statusKName  = 'completed';

	            break;
	          case '5':
	            $statusColor  = 'dark';
	            $statusName   = 'Enviado';
	            $statusKName  = 'sent';
	            break;
	          case '6':
	            $statusColor  = 'danger';
	            $statusName   = 'Cancelado';
	            $statusKName  = 'cancelled';
	            break;
	          default:
	            $statusColor  = 'light';
	            $statusName   = 'Pendiente';
	            $statusKName  = 'pending';
	            break;
	        }

	        if($fields['parent_sale_no']){
				$statusName = '<i class="text-success material-icons">check</i> ' . $statusName;	            	
            }

            $nameSort = $name;
	        if($source == 'ecom'){
	        	$name = 'eCommerce';
	        }else if($source == 'order'){
	        	$name = 'Orden';
	        }else if($source == 'table'){
	        	$name 		= 'Espacio ' . $name;
	        	$nameSort 	= filter_var($name, FILTER_SANITIZE_NUMBER_INT);
	        }else{
	        	$name = 'Orden';
	        }

	        $detailsArr 	= 	[
	        						'data' 		=> $fields['order_details'],
	        						'status' 	=> $statusKName
	        					];

	        $orderDetails[] = $detailsArr;

	    	$table .=	'<tr data-load="/a_report_transactions?action=edit&id=' . $fields['transaction_id'] . '&ro=1" class="clickrow pointer" data-parent="' . $fields['parent_sale_id'] . '">' .
	    				'	<td class="no-border font-bold b-l b-5x b-' . $statusColor . '">' .
	         					$statusName .
	         			'	</td>' .
	         			'	<td data-order="' . $fields['date'] . '">' .
	         					niceDate($fields['date'],true) .
	         			'	</td>' .
	         			'	<td data-order="' . $fields['due_date'] . '">' .
	         					niceDate($fields['due_date'],true) .
	         			'	</td>' .
	         			'	<td data-sort="' . $nameSort . '">' .
	         					$name .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['user_name'] .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['outlet'] .
	         			'	</td>' .
	         			'	<td>' .
	         					$customerName .
	         			'	</td>' .
	         			'	<td class="text-right">' .
	         					$fields['number_id'] .
	         			'	</td>' .
	         			'	<td>' .
	         					printOutTags($fields['order_tags'],'bg-light dk') .
	         			'	</td>' .
	         			'	<td class="text-right">' .
	         					$fields['order_note'] .
	         			'	</td>' .
	         			'	<td class="text-right">' .
	         					$fields['parent_sale_no'] .
	         			'	</td>' .
	         			'	<td class="text-right bg-light lter" data-order="' . $fields['order_total'] . '" data-format="money">' .
	         					formatCurrentNumber($fields['order_total']) .
	         			'	</td>' .
	         			'	<td class="text-center noxls">' .
	         			'		<a class="delete hidden-print" href="/a_report_orders?action=delete&id=' . $fields['transaction_id'] . '">' . 
    					'			<i class="text-danger material-icons">close</i>' . 
    					'		</a>' .
	         			'	</td>' .
	        			'</tr>';

	        if(validateHttp('part') && !validateHttp('singleRow')){
	        	$table .= '[@]';
	        }
		}
	}

	$foot = 	'</tbody>' .
				'<tfoot>' .
				'	<tr>' .
				'		<th colspan="10" class="font-bold">Total</th>' .
				'		<th class="font-bold text-right"></th>' .
				'		<th></th>' .
				'		<th></th>' .
				'	</tr>' .
				'</tfoot>';

	if(validateHttp('part')){
		jsonDieResult($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] 	= $fullTable;
		$jsonResult['details'] 	= $orderDetails;
		

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
    							'title' 		=> '<div class="text-md text-right font-default">Listado de</div> Órdenes',
    							'maxDays' 		=> $MAX_DAYS_RANGE,
    							'hideChart' 	=> true
    						]);
	?>

  	<div class="col-xs-12 no-padder m-t m-b-lg push-chat-down">
	    <section class="col-sm-12 no-padder" id="reportsTablesAndTabs">
	        <ul class="nav nav-tabs padder hidden-print">
	            <li class="active">
	                <a href="#tab1" data-toggle="tab">Órdenes</a>
	            </li>
	            <li>
	                <a href="#tab2" data-toggle="tab">Artículos</a>
	            </li>
	            <li class="hidden-xs pull-right">
	            	<div class="m-t-sm m-l">
	            		<i class="material-icons m-r-xs text-muted">info</i>
	            		El listado está filtrado por fecha de vencimiento
	            	</div>
	            </li>
	        </ul>
	        <section class="panel r-24x">
	            <div class="panel-body">
	                <div class="tab-content m-b-lg table-responsive">
	                    <div class="tab-pane overflow-auto active" id="tab1" style="min-height:500px">
	                    	<div id="generalTable">                             	
	                        	<table class="table table1 col-xs-12 no-padder" id="tableTransactions"><?=placeHolderLoader('table')?></table>
	                        </div>
	                    </div>

	                    <div class="tab-pane overflow-auto col-xs-12 no-padder" id="tab2" style="min-height:500px">
	                    	<div id="detailTable">
                            	<table class="table table2 table-hover col-xs-12 no-padder" id="tableDetail"><?=placeHolderLoader('table')?></table>
                            </div>
	                    </div>
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
		$.get(url,function(result){

			var options = {
					"container" 	: "#generalTable",
					"url" 			: url,
					"iniData" 		: result.table,
					"table" 		: "#tableTransactions",
					"sort" 			: 2,
					"footerSumCol" 	: [11],
					"currency" 		: "<?=CURRENCY?>",
					"decimal" 		: decimal,
					"thousand" 		: thousandSeparator,
					"offset" 		: <?=$offsetDetail?>,
					"limit" 		: <?=$limitDetail?>,
					"nolimit" 		: false,
					"noMoreBtn" 	: false,
					"tableName" 	: 'tableTransactions',
					"fileTitle" 	: 'Listado de Órdenes',
					"ncmTools"		: {
											left 	: '',
											right 	: ''
										  },
					"colsFilter"	: {
											name 		: 'tableOrders6',
											menu 		:  [
															{"index":0,"name":'Estado',"visible":true},
															{"index":1,"name":'Fecha',"visible":true},
															{"index":2,"name":'Para el',"visible":true},
															{"index":3,"name":'Fuente',"visible":true},
															{"index":4,"name":'Usuario',"visible":false},
															{"index":5,"name":'Sucursal',"visible":false},
															{"index":6,"name":'Cliente',"visible":true},
															{"index":7,"name":'# Orden',"visible":true},
															{"index":8,"name":'Etiquetas',"visible":false},
															{"index":9,"name":'Nota',"visible":false},
															{"index":10,"name":'# Documento',"visible":true},
															{"index":11,"name":'Total',"visible":false},
															{"index":12,"name":'Acciones',"visible":false}
															]
										  },
					"clickCB" 		: (event,tis) => {
									  	var load = tis.data('load');
										loadForm(load,'#modalLarge .modal-content', () => {
											$('#modalLarge').modal('show');
										});
		  							}
			};

			ncmDataTables(options,function(oTable){
				loadTheTable(options,oTable);
			});

			var itemsTable 	= 	'<thead>' 											+
								'	<tr>' 											+
								'		<th>Artículo</th>' 							+
								'		<th class="text-center">Pendientes</th>' 	+
								'		<th class="text-center">En Espera</th>' 	+
								'		<th class="text-center">En Proceso</th>' 	+
								'		<th class="text-center">Enviados</th>' 		+
								'		<th class="text-center">Finalizados</th>' 	+
								'		<th class="text-center">Cancelados</th>' 	+
								'	</tr>' 											+
								'</thead>' 											+
								'<tfoot>' 											+
								'	<tr>' 											+
								'		<th class="text-u-c">Total</th>'			+
								'		<th class="text-right"></th>' 				+
								'		<th class="text-right"></th>' 				+
								'		<th class="text-right"></th>' 				+
								'		<th class="text-right"></th>' 				+
								'		<th class="text-right"></th>' 				+
								'		<th class="text-right"></th>' 				+
								'	</tr>' 											+
								'</tfoot><tbody>';

			var itemList 	= {};
			var itmDetails  = {};

			$.each(result.details, function(d, data) {
				$.each(data.data, function(i, item) {
					
					if(typeof itemList[item.itemId] === 'undefined') {
						itemList[item.itemId] = {id : item.itemId, name : item.name, waiting : 0, in_progress : 0, completed : 0, sent : 0, cancelled : 0, pending : 0};
					}

					itemList[item.itemId][data.status] += parseFloat(item.count);

				});
			});

			$.each(itemList, function(i, data){
				itemsTable += 	'<tr>' +
								'	<td class="font-bold">' + data.name + '</td>' +
								'	<td class="bg-light lter text-right" data-order="' + data.pending + '">' + data.pending + '</td>' +
								'	<td class="bg-light lter text-right" data-order="' + data.waiting + '">' + data.waiting + '</td>' +
								'	<td class="bg-light lter text-right" data-order="' + data.in_progress + '">' + data.in_progress + '</td>' +
								'	<td class="bg-light lter text-right" data-order="' + data.sent + '">' + data.sent + '</td>' +
								'	<td class="bg-light lter text-right" data-order="' + data.completed + '">' + data.completed + '</td>' +
								'	<td class="bg-light lter text-right" data-order="' + data.cancelled + '">' + data.cancelled + '</td>' +
								'</tr>';
			});

			itemsTable += '</tbody>';

			var options2 		= Object.assign({}, options);
			options2.iniData 	= itemsTable;
			options2.container 	= "#detailTable";
			options2.table 		= ".table2";
			options2.sort 		= 0;
			options2.noMoreBtn 	= true;
			options2.footerSumCol = [1,2,3,4,5,6];
			options2.colsFilter	= {
									name 		: 'tableOrdersItems1',
									menu 		:  [
														{"index":0,"name":'Artículo',"visible":true},
														{"index":1,"name":'Pendientes',"visible":true},
														{"index":2,"name":'En Espera',"visible":true},
														{"index":3,"name":'En Proceso',"visible":true},
														{"index":4,"name":'Enviados',"visible":true},
														{"index":5,"name":'Finalizados',"visible":true},
														{"index":6,"name":'Cancelados',"visible":true},
													]
								  }

			
			ncmDataTables(options2);
			
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

		onClickWrap('.subModal', function(event, tis) {
			var ro = iftn(tis.data('ro'), '', '&ro=1');
			var load = '/a_report_transactions?action=edit&id=' + tis.data('id') + ro;

			$('#modalLarge').modal('hide').one('hidden.bs.modal', function() {
				loadForm(load, '#modalXLarge .modal-content', function() {
					$('#modalXLarge').modal('show');
				});
			});

		});

		onClickWrap('.cancelItemView', function(event, tis) {
			$('#modalXLarge').modal('hide');
		});
		
	});
	</script>

<?php
include_once('includes/compression_end.php');
dai();
?>