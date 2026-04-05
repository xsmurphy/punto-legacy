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

$isToPay 			= (validateBool('state') == 'outcome') ? true : false;

if($isToPay){
	allowUser('expenses','view');
}else{
	allowUser('sales','view');
}

$baseUrl = '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 				= getROC(1,1);
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

if(validateHttp('action') == 'getRowPaid'){

	if(!validateHttp('singleRow')){
		dai('false');
	}

	$tId 		= dec(validateHttp('singleRow'));
	$sale 		= ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ?',[$tId,COMPANY_ID]);

	if($result){
		$loadUrl 		= '/a_report_purchases?action=edit&id=' . enc($sale['saleId']) . '&ro=true';
		$allPayments 	= getAllToPayTransactions(false, ' AND transactionParentId = ' . $sale['transactionId']);

		$payed 			= $allPayments[$sale['transactionId']];
		$topay  		= $sale['transactionTotal'] - $payed;
		if($topay > 0){
			$needsTopay 	= true;
		}

		$sldDate		= strtotime($sale['transactionDate']);
		$strDue 		= strtotime($sale['transactionDueDate']);
		$today 			= strtotime(TODAY);
		$halfDue 		= rester($strDue,$sldDate);

		if($strDue <= $today){
			//ya vencio
			$dueTextColor = 'text-danger font-bold';
			$totalExpired++;
		}else if($strDue > $halfDue){
			//por vencer
			$dueTextColor = 'text-warning font-bold';
			$totalToExpire++;
		}else{
			//no vencio aun
			$dueTextColor = '';
		}

		
		$td 	= 	'	<td class="bg-light dk"> ' . $sale['invoicePrefix'] . $sale['invoiceNo'] . ' </td>' .
					'	<td> '.niceDate($sale['transactionDate']).' </td>' .
					'	<td class="' . $dueTextColor . '"> '.niceDate($sale['transactionDueDate']).' </td>' .
					'	<td class="text-right bg-light lter"> '.formatCurrentNumber($sale['transactionTotal']).' </td>' .
					'	<td class="text-right bg-light lter"> '.formatCurrentNumber($payed).' </td>' .
					'	<td class="text-right bg-light lter"> '.formatCurrentNumber($topay).' </td>' .
					'	<td class="text-center hidden-print noxls">' . 
					'		<a href="#" class="addPayment" data-toggle="tooltip" data-placement="left" title="Añadir Pago" data-id="' . enc($sale['transactionId']) . '">' . 
					'			<i class="material-icons text-success">payment</i>' . 
					'		</a>' . 
					'	</td>';

		dai($td);
	}

	dai('false');
}


if(validateHttp('action') == 'generalTable'){

	theErrorHandler('json');
	
	ini_set('memory_limit', '256M');

	$dateTwoY = date('Y-m-d 00:00:00', strtotime(TODAY . ' -2 year') );


	if($isToPay){
		$sql =					"SELECT 	supplierId as id, 
										transactionId as saleId,
										transactionDate as date,
										transactionDueDate as dueDate,
										invoiceNo as invoice,
										invoicePrefix as prefix,
										transactionTotal as total,
										transactionDiscount as discount,
										transactionParentId as parent,
										transactionComplete as complete
								FROM transaction
								WHERE transactionComplete < 1
								AND transactionType = 4
								AND companyId = " . COMPANY_ID . "
								ORDER BY dueDate DESC LIMIT 5000"; 


		$head = 	'<thead class="text-u-c">'.
					'	<tr>'.
					'		<th>Proveedor</th>'.
					'		<th>' . TIN_NAME . '</th>'.
					'		<th>Teléfono</th>'.
					'		<th>Email</th>'.
					'		<th>Total Comprado</th>'.
					'		<th>Total Pagado</th>'.
					'		<th>Deuda Total</th>'.
					'		<th class="hidden">a1</th>'.
					'	</tr>'.
					'</thead>'.
					'<tbody>';

		$csv[] 	= ['PROVEEDOR',TIN_NAME,'TELEFONO','EMAIL','TOTAL COMPRADO','DEUDA TOTAL','DETALLES'];
	}else{

		$sql = 					"SELECT 	customerId as id, 
										transactionId as saleId,
										transactionDate as date,
										transactionDueDate as dueDate,
										invoiceNo as invoice,
										invoicePrefix as prefix,
										transactionTotal as total,
										transactionDiscount as discount,
										transactionParentId as parent,
										transactionComplete as complete
								FROM transaction
								WHERE transactionComplete < 1
								AND transactionType = 3
								AND companyId = " . COMPANY_ID . "
								ORDER BY dueDate DESC LIMIT 5000";


		$head = 	'<thead class="text-u-c">'.
					'	<tr>'.
					'		<th>Cliente</th>'.
					'		<th>' . TIN_NAME . '</th>'.
					'		<th>Teléfono</th>'.
					'		<th>Email</th>'.
					'		<th>Total Comprado</th>'.
					'		<th>Total Pagado</th>'.
					'		<th>Deuda Total</th>'.
					'	</tr>'.
					'</thead>'.
					'<tbody>';

		$csv[] 	= ['CLIENTE',TIN_NAME,'TELEFONO','EMAIL','TOTAL COMPRADO','TOTAL PAGADO','DEUDA TOTAL'];
	}

	$result 	= ncmExecute($sql,[],false,true);

	if($result){

		$totalContado     		= 0;
		$totalCocount     		= 0;
		$totalCredito     		= 0;
		$totalCcount      		= 0;
		$totalPorCobrar   		= 0;
		$totalPorcount    		= 0;
		$totalCobrado     		= 0;
		$totalCobcount    		= 0;
		$parents 				= [];
		$csv 					= [];

		$fullTable 				= [];
		$table = "";

		while (!$result->EOF) {
			$fields 		= $result->fields;
			$cId 			= $fields['id'];
			//$total 			= $fields['total'] - $fields['discount'];
			$total 			= $isToPay ? $fields['total'] : $fields['total'] - $fields['discount'];
			$parents[]	 	= $fields['saleId'];

			$fullTable[$cId][] = [
									'invoiceNo' 	=> $fields['prefix'] . $fields['invoice'],
									'saleId' 		=> $fields['saleId'],
									'date' 			=> $fields['date'],
									'dueDate'		=> $fields['dueDate'],
									'total' 		=> $total
								];
			

			$result->MoveNext();
		}

		$allPayments 			= getAllToPayTransactions(1300, ' AND transactionParentId IN(' . implode(',', $parents) . ')');

		

		foreach($fullTable as $contactId => $transaction){
			$customerTabe 	= getContactData($contactId, ($isToPay) ? 'id' : 'uid', true);
			$customerName	= iftn(getCustomerName($customerTabe),'Sin Contacto Asociado');
			$customerTIN	= iftn($customerTabe['ruc'] ?? "-",'-');
			$customerPhone	= iftn(array_key_exists('phone', $customerTabe) && $customerTabe['phone'],array_key_exists("phone2",$customerTabe) ? $customerTabe['phone2'] : "");
			$td 			= '';

			$customerName .= ' (' . (array_key_exists('name',$customerTabe) ? $customerTabe['name'] : "") . ')';

			$totalPaid 		= 0;
			$totalSales 	= 0;
			$totalDebt 		= 0;
			$needsTopay 	= false;

			foreach($transaction as $key => $sale){
				$payed 			= array_key_exists($sale['saleId'],$allPayments) ? $allPayments[$sale['saleId']] : 0;
				$topay  		= $sale['total'] - $payed;

				$totalSales += $sale['total'];
				$totalPaid 	+= $payed;
				$totalDebt 	+= $topay;
			}

			$csv[] 			= [$customerName,$customerTIN,$customerPhone,array_key_exists('email',$customerTabe) ? $customerTabe['email'] : "",$totalSales,$totalPaid,$totalDebt];

			$csv[]			= ['# DOCUMENTO','EMISION','VENCIMIENTO','TOTAL COMPRADO','TOTAL PAGADO','DEUDA TOTAL'];//factura header

			$totalPaid 		= 0;
			$totalSales 	= 0;
			$totalDebt 		= 0;
			$needsTopay 	= false;

			foreach($transaction as $key => $sale){
				$payed 			= array_key_exists($sale['saleId'],$allPayments) ? $allPayments[$sale['saleId']] : 0;
				$topay  		= $sale['total'] - $payed;
				if($topay > 0){
					$needsTopay 	= true;
				}

				$sldDate		= !empty($sale['date']) ? strtotime($sale['date']) : "";
				$strDue 		= !empty($sale['dueDate']) ? strtotime($sale['dueDate']) : "";
				$today 			= strtotime(TODAY);
				$halfDue 		= rester($strDue,$sldDate);

				if($strDue <= $today){
					//ya vencio
					$dueTextColor = 'text-danger font-bold';
					$totalExpired++;
				}else if($strDue > $halfDue){
					//por vencer
					$dueTextColor = 'text-warning font-bold';
					$totalToExpire++;
				}else{
					//no vencio aun
					$dueTextColor = '';
				}

				$loadUrl 		= '/a_report_transactions?action=edit&id=' . enc($sale['saleId']) . '&ro=true';
				if($isToPay){
					$loadUrl 	= '/a_report_purchases?action=edit&id=' . enc($sale['saleId']) . '&ro=true';
				}

				$td 	.= 	'<tr data-load="' . $loadUrl . '" class="clickrow pointer OIDetails" data-family="' . enc($contactId) . '">' .
							'	<td class="bg-light dk"> '.$sale['invoiceNo'].' </td>' .
							'	<td> '.niceDate($sale['date']).' </td>' .
							'	<td class="' . $dueTextColor . '"> '.niceDate($sale['dueDate']).' </td>' .
							'	<td class="text-right bg-light lter"> '.formatCurrentNumber($sale['total']).' </td>' .
							'	<td class="text-right bg-light lter"> '.formatCurrentNumber($payed).' </td>' .
							'	<td class="text-right bg-light lter"> '.formatCurrentNumber($topay).' </td>';

						if($isToPay){ 
				$td 	.= 	'	<td class="text-center hidden-print">' . 
							'		<a href="#" class="addPayment" data-toggle="tooltip" data-placement="left" title="Añadir Pago" data-id="' . enc($sale['saleId']) . '">' . 
							'			<i class="material-icons text-success">payment</i>' . 
							'		</a>' . 
							'	</td>';
						}

				$td 	.= 	'</tr>';

				$csv[] 	= [$sale['invoiceNo'],$sale['date'],$sale['dueDate'],$sale['total'],$payed,$topay];
				
				$totalSales += $sale['total'];
				$totalPaid 	+= $payed;
				$totalDebt 	+= $topay;

				$totalPorcount++;			
			}

			$totalPorCobrar += $totalDebt;

			$tr 	= 	'<tr data-load="/a_contacts?action=form&id=' . enc($contactId) . '&type=wl&ro=true" class="loadCustomer pointer" data-family="' . enc($contactId) . '">'.
						'	<td class="font-bold">' . $customerName . '</td>'.
						'	<td>'.$customerTIN.'</td>'.
						'	<td>'.$customerPhone.'</td>'.
						'	<td>'.(array_key_exists("email",$customerTabe) ? $customerTabe['email'] : "").'</td>' .
						' 	<td class="font-bold bg-light bg text-right" data-filter="' . $totalSales . '">' . formatCurrentNumber($totalSales) . '</td>' . 
						' 	<td class="font-bold bg-light bg text-right" data-filter="' . $totalPaid . '">' . formatCurrentNumber($totalPaid) . '</td>' . 
						' 	<td class="font-bold bg-light bg text-right" data-filter="' . $totalDebt . '">' . formatCurrentNumber($totalDebt) . '</td>' .
						'</tr>' .
						'<tr class="text-u-c font-bold OIDetails" data-family="' . enc($contactId) . '">' . 
						'	<th># Documento</th>' . 
						'	<th>Emisión</th>' . 
						'	<th>Vencimiento</th>' . 
						'	<th class="text-center">Total Comprado</th>' .
						'	<th class="text-center">Total Pagado</th>' .
						'	<th class="text-center">Total Adeudado</th>' .
						'	<th></th>' .
						'</tr>' . 
							$td;

						if(!$isToPay){ 
			$tr .= 		'<tr data-family="' . enc($contactId) . '" class="noxls">' .
						'	<td colspan="7" class="text-center">' .
						'	<a href="https://public.encom.app/customerAccountStatus?s=' . base64_encode(enc(COMPANY_ID).','.enc($contactId)) . '" class="text-info text-md font-bold text-u-c hidden-print">Ver detalles</a>' .
						'	</td>' .
						'</tr>';
						}						

			if($totalDebt < 0.01 && isset($sale['complete']) && $sale['complete'] < 1){//si el total pagado es mayor a la cuenta total cierro la factura a credito
				$db->AutoExecute('transaction', ['transactionComplete' => '1'], 'UPDATE', 'transactionId = ' . $sale['saleId']);
			}

			$csv[]			= ['','','','','','',''];//linea de separacion
			
			if($needsTopay){
				$table .= $tr;
			}

			$result->MoveNext();
		}

		if($export){
			echo generateXLSfromArray($csv,"report_open_invoices");
			dai();
		}
	}

	$foot = 	'</tbody>'.
				'<tfoot>'.
				'	<tr>'.
				'		<th colspan="7"></th>'.
				'	</tr>'.
				'</tfoot>';

		
	$jsonResult = [];


	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;

		

		$jsonResult['table'] 			= $fullTable;

		$jsonResult['globalPay'] 		= CURRENCY . formatCurrentNumber($totalPorCobrar);
		$jsonResult['globalAccounts'] 	= formatQty($totalPorcount);
		$jsonResult['globalDue'] 		= formatQty($totalExpired);
		$jsonResult['globalToDue'] 		= formatQty($totalToExpire);

		header('Content-Type: application/json');
		dai( json_encode($jsonResult) );
	}

}

?>

    
  	<div class="col-xs-12 m-t-sm">
  		<?=headerPrint();?>
		<div class="pull-right">
			<span class="no-padder h1 m-n font-bold" id="pageTitle">Cuentas por <?=($isToPay) ? 'Pagar' : 'Cobrar'?></span>
		</div>
	</div>

  	<div class="col-xs-12 wrapper m-b hidden-print" id="paymentStatusWidget">	
		<section class="col-sm-3">
			<div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold globalPay"><?=placeHolderLoader()?></div>
				Total por <?=($isToPay) ? 'Pagar' : 'Cobrar'?>
			</div>
		</section>
		<section class="col-sm-3">
			<div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold globalAccounts"><?=placeHolderLoader()?></div>
				Cuentas por <?=($isToPay) ? 'Pagar' : 'Cobrar'?>
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
    		<div class="col-xs-12 no-padder m-b">
    			<div class="col-sm-9 no-padder">
    				<a href="#" class="btn btn-default hidden-print exportTable pull-left" data-table="tableSummary" data-name="Cuentas por <?=($isToPay) ? 'Pagar' : 'Cobrar'?>">Exportar Listado</a>
    				<a href="#" class="btn btn-default hidden-print hideDetail pull-left">Ocultar/Mostrar Detalle</a>
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

		fullScreenTextSearch('#tableSummary tr','#textSearch');

		onClickWrap('.exportTable',function(event,tis){
			var theTable 	= tis.data('table');
			var name 		= tis.data('name');

			table2Xlsx(theTable,name);
		});

		onClickWrap('.hideDetail',function(event,tis){
			$('.OIDetails').toggleClass('hidden');
		});
		
		onClickWrap('.clickrow',function(event,tis){
			var load = tis.data('load');
			loadForm(load,'#modalLarge .modal-content',function(){
				$('#modalLarge').modal('show');
			});
		});

		onClickWrap('.loadCustomer',function(event,tis){
			var load = tis.data('load');
			loadForm(load,'#modalLarge .modal-content',function(){
				$('#modalLarge').modal('show');
			});
		});

		onClickWrap('#reportExport',function(event,tis){
			var url = '?export=true<?=($_GET['state']) ? '&state=outcome' : ''?>';
			window.open(url);
		});

		onClickWrap('.addPayment',function(event,tis){
			var id = tis.data('id');
			$('.editting').removeClass('editting');
			tis.closest('tr').addClass('editting');
			loadForm('/a_report_purchases?action=paymentForm&id=' + id,'#modalTiny .modal-content',function(){
				$('#modalTiny').modal('show');
				masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
				$('#payAmountField').focus();
			});
		});

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