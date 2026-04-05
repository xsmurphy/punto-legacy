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
allowUser('expenses','view'); 

$baseUrl = '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 				= getROC(1);
$limitDetail		= 100;
$offsetDetail		= 0;


/*if(validateHttp('action') == 'update' && validateHttp('id','post')){

	if(!allowUser('expenses','edit',true)){
	    jsonDieResult(['error'=>'No permissions']);
	}

	//if(empty($_POST['amount']) || empty($_POST['type']) || empty($_POST['outlet'])){dai('La fecha, monto, tipo y sucursal son obligatorios');}

	$record = array();
  	$date = explodes('/',$_POST['date']);
  	$date = $date[2].'-'.$date[0].'-'.$date[1].' 00:00:01';

	$record['expensesNameId'] 		= dec($_POST['type']);
	$record['expensesAmount'] 		= formatNumberToInsertDB($_POST['amount']);
  	$record['expensesDate']         = $date;
	$record['expensesDescription'] 	= $_POST['note'];
	$record['outletId'] 			= dec($_POST['outlet']);

	$update = $db->AutoExecute('expenses', $record, 'UPDATE', 'expensesId = '.$db->Prepare(dec($_POST['id']))); 
	if($update === false){
		echo 'false|0|'.$_POST['id'];
	}else{
		echo 'true|0|'.$_POST['id'];
	}
	dai();
}
*/

if(validateHttp('action') == 'generalTable'){
	$limits = getTableLimits($limitDetail,$offsetDetail);
	if(validateHttp('singleRow')){
		$singleRow = ' AND expensesId = ' . dec(validateHttp('singleRow'));
	}

	$result 	= ncmExecute("	SELECT *
								FROM 
									expenses
								WHERE 
								expensesDate BETWEEN ? AND ? 
								" . $singleRow . "
								" . $roc . "
								AND " . $SQLcompanyId . "
								ORDER BY expensesDate DESC " . $limits,[$startDate,$endDate],false,true);

	$table = '';
	$head = '<thead class="text-u-c">' .
			'	<tr>' .
			'		<th>Fecha</th>' .
			'		<th>Sucursal</th>' .
			'		<th>Caja</th>' .
			'		<th>Usuario</th>' .
			'		<th>Nota</th>' .
			'		<th>Tipo</th>' .
			'		<th class="text-center">Total</th>' .
			'		<th class="text-center"></th>' .
			'	</tr>' .
			'</thead>' .
			'<tbody>';

	$label 	= '';
	$data 	= '';

	$allRegisters 	= getAllRegisters();
	$allUsers 		= getAllUsers();

	if($result){

		while (!$result->EOF) {
			$fields 	= $result->fields;
			$date 		= niceDate($fields['expensesDate'],true);
			$type		= $expenses[$fields['expensesNameId']];
			$total 		= $fields['expensesAmount'];
			$note		= toUTF8($fields['expensesDescription']);
			$outlet 	= getCurrentOutletName($fields['outletId']);
			$register 	= $allRegisters[$fields['registerId']]['name'];
			$user 		= $allUsers[$fields['userId']]['name'];
			$type 		= 'Extracción';

			if($fields['type']){
				$type = 'Ingreso';
			}

			$table .= 	'<tr id="' . enc($fields['expensesId']) . '" data-id="' . enc($fields['expensesId']) . '" class="clickrow">' .
						'	<td> ' . $date . ' </td>' .
						'	<td> ' . $outlet . ' </td>' .
						'	<td> ' . $register . ' </td>' .
						'	<td> ' . $user . ' </td>' .
						'	<td> ' . $note . ' </td>' .
						'	<td> <span class="badge bg-info"> ' . $type . ' </span> </td>' .
						'	<td class="text-right bg-light lter" data-order="' . $total . '" data-format="money"> ' .formatCurrentNumber($total) . ' </td>' .
						'	<td class="text-center"> ' .
						'		<a href="' . $baseUrl . '?action=delete&id=' . enc($fields['expensesId']) . '" data-id="' . enc($fields['expensesId']) . '" class="deleteItem hidden-print">' .
						' 			<i class="material-icons text-danger">close</i>' .
						'		</a>' .
						'	</td>' .
						'</tr>';

			if(validateHttp('part') && !validateHttp('singleRow')){
	        	$table .= '[@]';
	        }
			
			$result->MoveNext();
		}
		$result->Close();
	}
	
	$foot 	= 	'</tbody>' .
				'<tfoot>' .
				'	<tr>' .
				'		<th>TOTALES:</th>' .
				'		<th></th>' .
				'		<th></th>' .
				'		<th></th>' .
				'		<th></th>' .
				'		<th></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'	</tr>' .
				'</tfoot>';

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable 				= $head . $table . $foot;
		$jsonResult['table'] 	= $fullTable;

		header('Content-Type: application/json'); 
		dai(json_encodes($jsonResult,true));
	}
}

if(validateHttp('action') == 'update'){
	if(!validateHttp('id')){
		dai('false');
	}

	$id 							= validateHttp('id');

	$record = [];
	$record['expensesDate'] 		= validateHttp('date','post');
	$record['expensesAmount'] 		= formatNumberToInsertDB(validateHttp('total','post'));
	$record['expensesDescription'] 	= validateHttp('note','post');
	$record['userId'] 				= dec(validateHttp('user','post'));

	$update = $db->AutoExecute('expenses', $record, 'UPDATE', 'expensesId = ' . dec($id) . ' AND companyId = ' . COMPANY_ID);

	if($update === false){
		echo 'false|0|' . $id;
	}else{
		updateLastTimeEdit();
		echo 'true|0|' . $id;
	}

	dai();
}

if(validateHttp('action') == 'edit'){
	if(!validateHttp('id')){
		dai('false');
	}

	$id 		= validateHttp('id');
	$result 	= ncmExecute("SELECT * FROM expenses WHERE expensesId = ? LIMIT 1",[dec($id)]);

    ?>
    
    <div class="col-xs-12 no-padder bg-white r-24x clear">
	    <form action="/a_report_expenses?action=update&id=<?=$id;?>" method="POST" id="updateForm" name="updateForm">
			
		    <div class="col-xs-12 m-t m-b-lg">

		    	<label class="font-bold text-u-c text-xs">Fecha</label>
        		<input type="text" class="form-control no-border rounded bg-light datetimepicker pointer text-center" name="date" value="<?=$result['expensesDate']?>" autocomplete="off" readonly />

        		<label class="font-bold text-u-c text-xs m-t">Usuario</label>
				<?=selectInputUser($result['userId'],false,'no-border b-b text-white select2');?>

		    	<label class="text-u-c font-bold text-xs m-t">Total</label>
		        <input type="text" class="maskCurrency no-border b-b form-control font-bold input-lg" name="total" value="<?=formatCurrentNumber($result['expensesAmount'])?>" id="payAmountField">

		        <label class="font-bold text-u-c text-xs m-t">Nota</label>
			    <textarea class="form-control no-bg no-border b-b" name="note" autocomplete="off" placeholder="Nota"><?=$result['expensesDescription']?></textarea>

			    <?php
			    if(!$result['type']){
			    ?>
			    <div class="text-center m-t">
				    <a href="/@#purchase?i=<?=$id;?>" target="_blank">Convertir a Gasto</a>
				</div>
				<?php
				}
				?>

		    </div>

	    	<div class="col-xs-12 wrapper bg-light lter text-center">
				<button class="btn btn-info btn-lg btn-rounded text-u-c font-bold">Guardar</button>
				<input type="hidden" name="id" value="<?=$id;?>">
				<input type="hidden" name="debt" value="<?=$deuda;?>">
			</div>

	    </form>
    </div>

    <?php
	dai();
}

if(validateHttp('action') == 'delete' && validateHttp('id')){
	if(!allowUser('expenses','delete',true)){
	    jsonDieResult(['error'=>'No permissions']);
	}

	$delete = $db->Execute('DELETE FROM expenses WHERE expensesId = ? LIMIT 1', array(dec($_GET['id']))); 
	if($delete === false){
		echo 'false';
	}else{
		echo 'true';
		updateLastTimeEdit();
	}
	dai();
}


?>

<?=menuReports('',true);?>
<?=reportsTitle('Movimientos de Caja',true);?>
<div class="col-xs-12 clear wrapper panel r-24x bg-white push-chat-down">  	
	<div class="tableContainer">
	    <table class="table table1 hover col-xs-12 no-padder" id="tableExtractions">
	    	<?=placeHolderLoader('table')?>
	    </table>
	</div>
</div>
<script>

$(document).ready(function(){

	FastClick.attach(document.body);
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>",true);

	var baseUrl = '<?=$baseUrl?>';
	var rawUrl 	= baseUrl + "?action=generalTable";
	var url 	= rawUrl;

	$.get(url,function(result){
		var options = {
						"container" 	: ".tableContainer",
						"url" 			: url,
						"rawUrl" 		: rawUrl,
						"iniData" 		: result.table,
						"table" 		: ".table1",
						"sort" 			: 0,
						"footerSumCol" 	: [6],
						"currency" 		: "<?=CURRENCY?>",
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 		: <?=$offsetDetail?>,
						"limit" 		: <?=$limitDetail?>,
						"nolimit" 		: true,
						"ncmTools"		: {
											left 	: '<a href="#" class="btn btn-default exportTable" data-table="tableExtractions" data-name="extracciones_de_caja">Exportar Listado</a>',
											right 	: ''
										  },
						"colsFilter"	: {
											name 	: 'extracciones1',
											menu 	:  [
															{"index":0,"name":"Fecha","visible":true},
															{"index":1,"name":"Sucursal","visible":false},
															{"index":2,"name":"Caja","visible":false},
															{"index":3,"name":"Usuario","visible":true},
															{"index":4,"name":'Nota',"visible":true},
															{"index":5,"name":'Tipo',"visible":true},
															{"index":6,"name":'Total',"visible":true},
															{"index":7,"name":'Acciones',"visible":false}
														]
										  }
		};

		manageTableLoad(options,function(oTable){
			onClickWrap('.deleteItem',function(event,tis){
				confirmation("Realmente desea eliminar?",function(r){
					if (r) {
						$row = $('#' + tis.data('id'));
						oTable.row($row).remove().draw();

					    var target = tis.attr('href');
						$.get(target, function(response) {
							if(response == 'true'){
								message('Eliminado','success');
							}else{
								message('Error al eliminar','danger');
							}
						});

					}
				});
				
			});

			$('#modalTiny').off('shown.bs.modal').on('shown.bs.modal', function() {
				select2Simple('.select2');
				submitForm('#updateForm',function(tis,result){
					if(result){
						$.get(options.rawUrl + '&part=1&singleRow=' + result,function(data){
							oTable.row('.editting').remove().draw();
							if(data){
								oTable.row.add($(data)).draw();
							}
							$('#modalTiny').modal('hide');
						});
					}
				});
			});

			onClickWrap('#tableExtractions tr.clickrow',function(event,tis){
				var id = tis.attr('id');
				$('.editting').removeClass('editting');
				tis.closest('tr').addClass('editting');
				loadForm(baseUrl + '?action=edit&id=' + id,'#modalTiny .modal-content',function(){
					$('#modalTiny').modal('show');
					masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
					$('.datetimepicker').datetimepicker({
						format            : 'YYYY-MM-DD HH:mm:ss',
						showClear         : true,
						ignoreReadonly    : true
					});
					$('#payAmountField').focus();
				});
			});


		});

	});

	
});
</script>
<?php
dai();
?>