<?php
require_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
topHook();
allowUser('sales','view');

$baseUrl = '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 	= getROC(1);
$limitDetail		= 500;
$offsetDetail		= 0;

if(validateHttp('action') && validateHttp('i')){
	if(!allowUser('sales','edit',true)){
	    jsonDieResult(['error'=>'No permissions']);
	}

	$action = validateHttp('action');
	$id 	= dec($db->Prepare(validateHttp('i')));
	if($action == 'pause'){
		$done = $db->AutoExecute('recurring', ['recurringStatus'=>"2"], 'UPDATE','recurringId = ' . $id . ' AND companyId = ' . COMPANY_ID);
	}else if($action == 'activate'){
		$done = $db->AutoExecute('recurring', ['recurringStatus'=>"1"], 'UPDATE','recurringId = ' . $id . ' AND companyId = ' . COMPANY_ID);
	}else if($action == 'remove'){
		$done = $db->Execute('DELETE FROM recurring WHERE recurringId = ' . $id . ' AND companyId = ' . COMPANY_ID);
	}

	if($done !== false){
		echo validateHttp('i');
	}else{
		echo false;
	}

	dai();
}

if(validateHttp('action') == 'generalTable'){
	$limits = getTableLimits($limitDetail,$offsetDetail);
	if(validateHttp('singleRow')){
		$singleRow = ' AND giftCardSoldId = ' . $db->Prepare(dec(validateHttp('singleRow')));
	}

	$result 	= ncmExecute("	SELECT * 
								FROM recurring 
								WHERE companyId = ? " . 
								$singleRow . 
								$limits, [COMPANY_ID],false,true);

	$head = 	'<thead class="text-u-c">'.
				'	<tr>'.
				'		<th>Cliente</th>' .
				'		<th>Documento Inicial</th>' .
				'		<th>Próxima</th>' .
				'		<th>Finalización</th>' .
				'		<th>Frecuencia</th>' .
				'		<th>Estado</th>' .
				'		<th>Total</th>' .
				'		<th>Acciones</th>' .
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	$label 	= '';
	$table 	= '';

	$allCustomersArray 		= getAllContacts(1);

	if($result){
		while (!$result->EOF) {
			$fields 		= $result->fields;
			$data 			= json_decode($fields['recurringSaleData'],true);
			$total 			= $data['total'];

			$cId 			= getCustomerIdDecoded($data['client']);
			$customer		= $allCustomersArray[0][$cId];
			$cName 			= $customer['name'] . ' <span class="text-muted">' . $customer['secondName'] . '</span>';//getCustomerName($customer);

			$next 			= $fields['recurringNextDate'];
			$end 			= $fields['recurringEndDate'];
			$frecuency 		= $fields['recurringFrecuency'];
			$status 		= $fields['recurringStatus'];
			$id 			= enc($fields['recurringId']);

			if($frecuency == 'daily'){
				$frecuence = 'Diaria';
			}else if($frecuency == 'weekly'){
				$frecuence = 'Semanal';
			}else if($frecuency == 'monthly'){
				$frecuence = 'Mensual';
			}else if($frecuency == 'quarterly'){
				$frecuence = 'Trimestral';
			}else if($frecuency == 'anual'){
				$frecuence = 'Anual';
			}

			if($status == 0){
				$state = 'Finalizado';
				$bg = 'bg-success';
			}else if($status == 1){
				$state = 'Activo';
				$bg = 'bg-light';
			}else if($status == 2){
				$state = 'Pausado';
				$bg = 'bg-warning';
			}

			$table .= 	'<tr id="' . enc($cId) . '">' .
						'	<td> <a href="#" class="clickrow" data-load="/a_contacts?action=form&id=' . enc($cId) . '&type=wl&ro=1">' . $cName . '</a> </td>' .
						'	<td> <a href="#" class="clickrow" data-load="/a_report_transactions?action=edit&uid=' . enc($data['uid']) . '&ro=true"><span class="text-info text-u-l">' . $data['invoiceno'] . '</span></a> </td>' .
						'	<td data-sort="' . $next . '"> ' . niceDate($next) . ' </td>' .
						'	<td data-sort="' . $end . '"> ' . niceDate($end) . ' </td>' .
						'	<td> <span class="label bg-info">'.$frecuence.'</span> </td>' .
						'	<td> <span class="label '.$bg.'">'.$state.'</span> </td>' .
						'	<td class="text-right bg-light lter" data-order="'.$total.'"  data-format="money"> '.formatCurrentNumber($total).' </td>' .
						'	<td class="text-center">';
						if($status == 2){
							$table .= ' 		<a href="#" class="m-r action" data-type="activate" data-id="' . $id . '"><i class="material-icons text-info">play_circle_outline</i></a>' .
							' 		<a href="#" class="action" data-type="remove" data-id="' . $id . '"><i class="material-icons text-danger">close</i></a>';
						}else if($status == 1){
							$table .= ' 		<a href="#" class="m-r action" data-type="pause" data-id="' . $id . '"><i class="material-icons text-info">pause</i></a>' .
							' 		<a href="#" class="action" data-type="remove" data-id="' . $id . '"><i class="material-icons text-danger">close</i></a>';
						}else{
							$table .= '			<a href="#" class="action" data-type="remove" data-id="' . $id . '"><i class="material-icons text-danger">close</i></a>';
						}
						
						
						' 	</td>' .
						'</tr>';

				if(validateHttp('part') && !validateHttp('singleRow')){
		        	$table .= '[@]';
		        }

			$result->MoveNext();

		}
		$result->Close();
	}
	
	$foot = 	'</tbody>'.
			  	'<tfoot>'.
				'	<tr>'.
				'		<th>TOTALES:</th>'.
				'		<th></th>'.
				'		<th></th>'.
				'		<th></th>'.
				'		<th></th>'.
				'		<th></th>'.
				'		<th class="text-right"></th>'.
				'		<th></th>'.
				'	</tr>'.
				'</tfoot>';
	
	$fullTable 				= $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;

	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));

}

?>

<?=menuReports('',false);?>

<div class="col-xs-12 m-t-sm">
	<div class="pull-right">
		<h1 class="no-padder m-n font-bold">Facturas Recurrentes</h1>
	</div>
</div>


<div class="col-xs-12 wrapper m-t bg-white panel r-24x push-chat-down tableContainer" id="tableRecurring">
    <table class="table table1 col-xs-12 no-padder">
        <?=placeHolderLoader('table')?>
    </table>
</div>

<script>
var baseUrl = '<?=$baseUrl?>';
FastClick.attach(document.body);
dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");

$(document).ready(function(){

	var rawUrl 	= baseUrl + "?action=generalTable";
	var url 	= rawUrl;

	$.get(url,function(result){
		var options = {
						"container" 	: ".tableContainer",
						"url" 			: url,
						"rawUrl" 		: rawUrl,
						"iniData" 		: result.table,
						"table" 		: ".table1",
						"sort" 			: 2,
						"footerSumCol" 	: [6],
						"currency" 		: "<?=CURRENCY?>",
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 		: <?=$offsetDetail?>,
						"limit" 		: <?=$limitDetail?>,
						"nolimit" 		: true,
						"ncmTools"		: {
											left 	: '<a href="#" class="btn btn-default exportTable" data-table="tableRecurring" data-name="transacciones_recurrentes">Exportar Listado</a>',
											right 	: ''
										  },
						"colsFilter"	: {
											name 	: 'recurring',
											menu 	:  [
															{"index":0,"name":"Cliente","visible":true},
															{"index":1,"name":"Doc. Inicial","visible":false},
															{"index":2,"name":"Próxima","visible":true},
															{"index":3,"name":"Finaliza","visible":false},
															{"index":4,"name":'Frecuencia',"visible":true},
															{"index":6,"name":'Estado',"visible":false},
															{"index":7,"name":'Total',"visible":true},
															{"index":8,"name":'Acciones',"visible":true}
														]
										  }
		};

		manageTableLoad(options,function(oTable){
			onClickWrap('.clickrow',function(event,tis){
				var load = tis.data('load');
				var type = tis.data('type');

				loadForm(load,'#modalLarge .modal-content',function(){
					$('#modalLarge').modal('show');
				});
			},false,true);

			onClickWrap('.action',function(event,tis){
				var id 		= tis.data('id');
				var type 	= tis.data('type');

				if(type == 'pause'){
					var msg = 'Realmente quiere pausar?';
				}else if(type == 'activate'){
					var msg = 'Realmente quiere activar?';
				}else{
					var msg = 'Realmente quiere eliminar?';
				}

				ncmDialogs.confirm(msg,'','question',function(confirme){
					if(confirme){
						$.get(baseUrl + '?action=' + type + '&i=' + id,function(result){
							$.get(options.url + '&part=1&singleRow=' + result,function(data){
								oTable.row('#' + id).remove();
								oTable.row.add($(data));
								oTable.draw();
							});
						});
					}	
				});

				
			},false,true);
		});

	});	

});

</script>

<?php
dai();
?>