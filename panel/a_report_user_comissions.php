<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
topHook();
allowUser('sales','view');

$baseUrl = '/' . basename(__FILE__,'.php');
$MAX_DAYS_RANGE = 31;

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 				= getROC(1);
$limitDetail		= 102;
$offsetDetail		= 0;

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

if(validateHttp('action') == 'delete' && validateHttp('id')){
	if(!allowUser('sales','delete',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$id 		= dec(validateHttp('id'));

	$data 	= [
					    'api_key'     	=> API_KEY,
					    'company_id'  	=> enc( COMPANY_ID ),
					    'id' 						=> validateHttp('id'),
					    'type' 					=> validateHttp('type')
					  ];

	//print_r($data);

	$result = curlContents(API_URL . '/delete_user_comission.php','POST',$data);
	
	header('Content-Type: application/json'); 
	dai($result);

}

if(validateHttp('action') == 'generalTable'){

	if(!validateHttp('uId')){
		dai(json_encodes(['error'=>'No User ID']));
	}

	$userId 	= $db->Prepare(dec(validateHttp('uId')));
	$body 		= '';
	$pMArray 	= getAllPaymentMethodsArray(true);

	$result     = ncmExecute( " SELECT  *
                              FROM itemSold
                              WHERE userId = ?
                              AND itemSoldDate BETWEEN ? AND ?
                              AND itemSoldComission > 0
                              ORDER BY itemSoldDate DESC LIMIT 5000
                            "
                          , [$userId,$startDate,$endDate], false, true);


	/*echo " SELECT  *
                              FROM itemSold
                              WHERE userId = $userId
                              AND itemSoldDate BETWEEN $startDate AND $endDate
                              AND itemSoldComission > 0
                              ORDER BY itemSoldDate DESC LIMIT 5000
                            ";

                            print_r($result);
	dai();*/

  	$comresult  = ncmExecute( " SELECT  *
                              FROM comission
                              WHERE userId = ?
                              AND comissionDate BETWEEN ? AND ?
                              AND comissionTotal > 0
                              ORDER BY comissionDate DESC
                            "
                          , [$userId,$startDate,$endDate], false, true);


	$head = 	'<thead class="text-u-c">' .
				'	<tr>' .
				'		<th># Documento</th>' .
				'		<th>Fecha</th>' .
				'		<th>Cliente</th>' .
				'		<th>Producto/Servicio</th>' .
				'		<th>Sesión</th>';

				foreach ($pMArray as $key => $name) {
	$head .=	'		<th class="text-right">' . $name . '</th>';
				}
				//
	$head .=	'<th class="text-right">Cantidad</th>' .
						'		<th class="text-right">Precio Uni.</th>' .
						'		<th class="text-right">Descuento</th>' .
						'		<th class="text-right">Total</th>' .
						'		<th class="text-right">Comisión Uni.</th>' .
						'		<th class="text-right">Comisión Adquirida</th>' .
						'		<th>Acciones</th>' .
						'	</tr>' .
						'</thead>' .
						'<tbody>';

	//armo un array con los pagos asociados a cada método sumando los repetidos

	if($result){
		while (!$result->EOF) {
			$fields 				= $result->fields;
      $itm        		= getItemData($fields['itemId']);

      //if(in_array($itm['type'], ['combo', 'precombo'])){
      //	$result->MoveNext();
      //}

      $doc        		= ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);

      $comission  		= $fields['itemSoldComission'];
      $comissionID  	= enc( $fields['itemSoldId'] );
      $units      		= $fields['itemSoldUnits'];
      $total      		+= $comission;
      $totalU     		+= $units;
      $paymentType  	= json_decode(iftn($doc['transactionPaymentType'],'{}'),true);
      $pMethods   		= [];


      $customerName 	= 'Sin Cliente';
      if($doc['customerId']){
        $cusData      = getCustomerData($doc['customerId'],'uid');
        $cusName 			= getCustomerName($cusData);
      }

      $pMAdded 				= [];

			if(validity($paymentType)){ //veo si tiene pagos 
      	foreach($paymentType as $pType){ //inicio el loop de pagos por transacción
      		$tisType 	= getPaymentMethodDecoded($pType['type']);
      		$key 		= $tisType;

      		if(array_key_exists($key, $pMArray)){//verifico si el medio de pago existe en la lista de pagos de la empresa
        			if($pMAdded[$key]){//si ya fue procesado sumo
        				$pMAdded[$key] += $pType['price'];
        			}else{//si no añado
        				$pMAdded[$key] = $pType['price'];
        			}
        		} 
       	}
      }

      $body .= 	'<tr class="pointer clickrow" data-url="/a_report_transactions?action=edit&id=' . enc($fields['transactionId']) . '&ro=1">' .
      			'	<td>' . $doc['invoiceNo'] . $doc['invoicePrefix'] . '</td>' .
      			'	<td>' . niceDate($fields['itemSoldDate']) . '</td>' .
      			'	<td>' . $cusName . '</td>' .
      			'	<td>' . $itm['itemName'] . '</td>' .
      			'	<td></td>';

      			foreach ($pMArray as $ptKey => $ptName) {
      				$pAmount = $pMAdded[$ptKey];
      				if($pAmount){
      					$body .=	'	<td class="text-right" data-order="' . $pAmount . '" data-format="money">' . formatCurrentNumber($pAmount) . '</td>';
      				}else{
      					$body .=	'	<td></td>';
      				}
      			}

      $body .=	'	<td class="text-right" data-order="' . $units . '">' . formatQty($units) . '</td>' .
      			'	<td class="text-right" data-order="' . $itm['itemPrice'] . '" data-format="money">' . formatCurrentNumber($itm['itemPrice']) . '</td>' .
      			'	<td class="text-right" data-order="' . $doc['transactionDiscount'] . '" data-format="money">' . formatCurrentNumber($doc['transactionDiscount']) . '</td>' .
      			//'	<td class="text-right" data-order="' . (($doc['transactionTotal'] - $doc['transactionDiscount']) * $units) . '" data-format="money">' . formatCurrentNumber(($doc['transactionTotal'] - $doc['transactionDiscount']) * $units) . '</td>' .
      			'	<td class="text-right" data-order="' . $fields['itemSoldTotal'] . '" data-format="money">' . formatCurrentNumber($fields['itemSoldTotal']) . '</td>' .

      			'	<td class="text-right" data-order="' . $itm['itemComissionPercent'] . '">' . formatCurrentNumber($itm['itemComissionPercent']) . '</td>' .
      			'	<td class="text-right" data-order="' . $comission . '" data-format="money">' . formatCurrentNumber($comission) . '</td>' .
      			'	<td class="text-center"><a data-id="' . $comissionID . '" data-source="item" class="delete"><i class="material-icons text-danger">close</i></a></td>' .
      			'</tr>';

			$result->MoveNext();	
		}

	}

	if($comresult){
		while (!$comresult->EOF) {
			$fields     = $comresult->fields;
			$itm        = $fields['comissionSource'];

			if($itm == 'session'){
			  $itm            = 'Sesión';
			  $parent         = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1',[$fields['transactionId']]);
			  $doc            = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1',[$parent['transactionParentId']]);
			  $cusData 		  	= getContactData($doc['customerId'],'uid');
        $cusName 		  	= getCustomerName($cusData);
			  $itmsInSession  = json_decode($parent['transactionDetails'],true);
			  $servData       = getItemData(dec($itmsInSession[0]['itemId']));
			  //$itm            = '<span class="text-muted font-bold">' . $itm . '/' . $parent['invoiceNo'] . '</span> ' . $servData['itemName'];
			  $itm 			  		= $servData['itemName'];
			  $session		  	= $parent['invoiceNo'];
			}else{
			  $doc 			  		= [];
			  $cusName 		  	= '';
			  $session		  	= '';
			}

			$comission    = $fields['comissionTotal'];
			$comissionID  = enc( $fields['comissionId'] );
			$units        = 1;
			$total        += $comission;
			$totalU       += $units;
			$paymentType  = json_decode(iftn($doc['transactionPaymentType'],'{}'),true);
			$pMethods     = [];

			$body .= 	'<tr class="pointer clickrow" data-url="/a_report_transactions?action=edit&id=' . enc($parent['transactionParentId']) . '&ro=1">' .
						'	<td>' . $doc['invoiceNo'] . $doc['invoicePrefix'] . '</td>' .
						'	<td>' . niceDate($fields['comissionDate']) . '</td>' .
						'	<td>' . $cusName . '</td>' .
						'	<td>' . $itm . '</td>' .
						'	<td>' . $session . '</td>';

						foreach ($pMArray as $key => $name) {
		 	$body .=	'	<td data-order="0" data-format="money"></td>';			
						}

			$body .=	'	<td class="text-right" data-order="' . $units . '">' . formatCurrentNumber($units) . '</td>' .
								'	<td class="text-right" data-order="' . $servData['itemPrice'] . '" data-format="money">' . formatCurrentNumber($servData['itemPrice']) . '</td>' .
								'	<td class="text-right" data-order="" data-format="money"></td>' .
								'	<td class="text-right" data-order="' . ($doc['transactionTotal'] - $doc['transactionDiscount']) . '" data-format="money">' . formatCurrentNumber($doc['transactionTotal'] - $doc['transactionDiscount']) . '</td>' .
								'	<td class="text-right" data-order="' . $servData['itemComissionPercent'] . '">' . formatCurrentNumber($servData['itemComissionPercent']) . '</td>' .
		            '	<td class="text-right" data-order="' . $comission . '" data-format="money">' . formatCurrentNumber($comission) . '</td>' .
		            '	<td class="text-center"><a data-id="' . $comissionID . '" data-source="comission" class="delete"><i class="material-icons text-danger">close</i></a></td>' .
		            '</tr>';

			$comresult->MoveNext();
		}

    }

	$foot = 	'</tbody>' .
						'<tfoot>'.
						'	<tr>'.
						'		<th>TOTALES:</th>' .
						'		<th></th>' .
						'		<th></th>' .
						'		<th></th>' .
						'		<th></th>';

				foreach ($pMArray as $y) {
	$foot .=	'		<th class="text-right"></th>';
				}

	$foot .=	'		<th class="text-right"></th>'.
						'		<th class="text-right"></th>'.
						'		<th class="text-right"></th>'.
						'		<th class="text-right"></th>'.
						'		<th class="text-right"></th>'.
						'		<th class="text-right"></th>'.
						'		<th></th>' .
						'	</tr>'.
					'	</tfoot>';



	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $body . $foot;
		$jsonResult['global'] 	= ['sales' => formatQty($tTotal), 'qty' => formatQty($tCount), 'discount' => CURRENCY . formatCurrentNumber($tDiscount), 'total' => CURRENCY . formatCurrentNumber($tTotal)];
		$jsonResult['table'] 	= $fullTable;

		header('Content-Type: application/json'); 
		dai(json_encodes($jsonResult,true));
	}
}
?>

<?=menuReports('',false);?>

<?php $userName = ncmExecute('SELECT contactName FROM contact WHERE contactId = ?',[dec(validateHttp('ui'))]); ?>

<?php
echo reportsDayAndTitle([
							'title' 		=> '<div class="text-md text-right font-default">Comisiones de</div> ' . $userName['contactName'],
							'hideChart' 	=> true,
							'maxDays' 		=> $MAX_DAYS_RANGE
						]);
?>

<div class="col-xs-12 wrapper panel r-24x bg-white push-chat-down">
	<div id="tableContainer">
		<div class="table-responsive no-border">                                  	
		    <table class="table table1 table-hover col-xs-12 no-padder" id="tableComissions">
		    	<?=placeHolderLoader('table')?>
		    </table>
		</div>
		<?=footerPrint(['signatures'=>2]);?>
	</div>
</div>

<script>
var baseUrl  = '<?=$baseUrl;?>';
$(document).ready(function(){
	FastClick.attach(document.body);
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");

	var rawUrl 		= baseUrl + "?action=generalTable";
	var loadUrl 	= rawUrl + "&uId=<?=validateHttp('ui')?>";
	<?php $pMArray 	= getAllPaymentMethodsArray(); ?>

	$.get(loadUrl,function(result){
		var info1 = {
						"container" 	: "#tableContainer",
						"url" 			: loadUrl,
						"rawUrl" 		: rawUrl,
						"iniData" 		: result.table,
						"table" 		: "#tableComissions",
						"sort" 			: 0,
						"footerSumCol" 	: [<?php
											$i 			= 5;
											foreach ($pMArray as $key => $name) {
												echo $i . ',';
												$i++;
											}

											echo $i . ',';
											echo $i + 1 . ',';
											echo $i + 2 . ',';
											echo $i + 3 . ',';
											echo $i + 4 . ',';
											echo $i + 5;
											?>],
						"currency" 		: "<?=CURRENCY?>",
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 			: <?=$offsetDetail?>,
						"limit" 			: <?=$limitDetail?>,
						"nolimit" 		: true,
						"noMoreBtn" 	: true,
						"tableName" 	: 'tableComissions',
						"fileTitle" 	: 'Comisiones de <?=$userName['contactName'];?>',
						"ncmTools"		: {
											left 	: '',
											right 	: ''
										  },
						"colsFilter"	: {
											name 		: 'reportComissions3',
											menu 		:  [
															{"index":0,"name":'# Documento',"visible":true},
															{"index":1,"name":'Fecha',"visible":true},
															{"index":2,"name":'Cliente',"visible":false},
															{"index":3,"name":'Producto/Servicio',"visible":true},
															{"index":4,"name":'Sesión',"visible":false},

															<?php
															$i = 5;
															foreach ($pMArray as $key => $name) {
															?>
																{"index":<?=$i;?>,"name":'<?=$name;?>',"visible":false},
															<?php
																$i++;
															}
															?>

															{"index":<?=$i;?>,"name":'Cantidad',"visible":false},
															{"index":<?=$i + 1;?>,"name":'Precio Uni.',"visible":false},
															{"index":<?=$i + 2;?>,"name":'Descuento',"visible":false},
															{"index":<?=$i + 3;?>,"name":'Total',"visible":false},
															{"index":<?=$i + 4;?>,"name":'Comisión Uni.',"visible":false},
															{"index":<?=$i + 5;?>,"name":'Comisión Adquirida',"visible":true},
															{"index":<?=$i + 6;?>,"name":'Acciones',"visible":false}
															]
										  },
						"clickCB" 		: function(event,tis){
													  	var load = tis.data('url');
															loadForm(load,'#modalLarge .modal-content',function(){
																$('#modalLarge').modal('show');
															});
						  							}
					};

		ncmDataTables(info1, (oTable) => {

			onClickWrap('#tableComissions .delete',function(event,tis){

				ncmDialogs.confirm('¿Desea eliminar la comisión?','Esta acción no se puede revertir.','warning',function(conf){
					if(conf){
						//refrescar tabla
						var dataID	 = tis.data('id');
						var dataType = tis.data('source');

						$.get(baseUrl + "?action=delete&id=" + dataID + '&type=' + dataType, function( data ) {
							if(data.success){
								message('Eliminado','success');
							}else{
								message('No se pudo eliminar','danger');
							}
						});

					}
				});

			});

		});

		$('#globalSales').text(result.global.sales);
		$('#globalQty').text(result.global.qty);
		$('#globalDiscount').text(result.global.discount);
		$('#globalTotal').text(result.global.total);		

	});
});
</script>
<?php
include_once('includes/compression_end.php');
dai();
?>