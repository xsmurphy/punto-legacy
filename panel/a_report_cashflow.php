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
allowUser('sales','view');

$baseUrl = '/' . basename(__FILE__,'.php');

$startPageLoad 	= startPageLoadTimeCalculator();

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 				= getROC(1);

$maxItemsInGraph 	= 20;
$itemsArray 		= [];
$itemsArrayDetail 	= [];
$isdata 			= false;
$limitDetail		= 100;
$offsetDetail		= 0;

$jsonResult = [];

if(validateHttp('action') == 'getCashFlow'){

	list($startDateBack,$endDateBack) = getPreviousPeriod($startDate,$endDate);
	
	$totalCash 		= 0;
	$totalPayments 	= 0;
	$totalPurchase 	= 0;
	$totalPPayment 	= 0;
	$totalExpenses 	= 0;
	$totalEPayment 	= 0;

	$sql 	= 	'SELECT 
				SUM(transactionDiscount) as discount, 
				SUM(transactionTotal) as total
				FROM transaction 
				WHERE transactionType IN(0,6)
				AND transactionDate 
				BETWEEN ?
				AND ?' . $roc;
	$cashSales	= ncmExecute($sql,[$startDate,$endDate]);
	
	if($cashSales){
		$cashSalesB	= ncmExecute($sql,[$startDateBack,$endDateBack]);
		$totalCash 	= $cashSales['total'] - $cashSales['discount'];
		$totalCashB = $cashSalesB['total'] - $cashSalesB['discount'];
	}

	$totalPayments 		= getCashFlowReceivedPayments(5,3,$roc,$startDate,$endDate);
	$totalPaymentsB = 0;
	if($totalPayments){
		$totalPaymentsB = getCashFlowReceivedPayments(5,3,$roc,$startDateBack,$endDateBack);
	}

	//EGRESOS
	//Compras de mercaderia contado
	$sql 	= 	'SELECT 
				SUM(b.itemSoldTotal) as total
				FROM transaction a, itemSold b
				WHERE a.transactionType = 1
				AND a.transactionDate 
				BETWEEN ?
				AND ?
				AND a.transactionId = b.transactionId
				AND b.itemId > 0
				' . $roc;

	$stockPurchase	= ncmExecute($sql,[$startDate,$endDate]);

	if($stockPurchase){
		$stockPurchaseB	= ncmExecute($sql,[$startDateBack,$endDateBack]);
		$totalPurchase 	= $stockPurchase['total'];
		$totalPurchaseB = $stockPurchaseB['total'];
	}

	///Obtengo todas las compras y pagos a credito

	$totalPPayment 	= getCashFlowReceivedPayments(5,4,$roc,$startDate,$endDate);
	$totalPPaymentB = getCashFlowReceivedPayments(5,4,$roc,$startDateBack,$endDateBack);

	///Compras servicios contado
	$sql 	= 	'SELECT 
				SUM(b.itemSoldTotal) as total
				FROM transaction a, itemSold b
				WHERE a.transactionType = 1 
				AND a.transactionDate 
				BETWEEN ?
				AND ?
				AND a.transactionId = b.transactionId
				AND (b.itemId IS NULL OR b.itemId = 0)
				' . $roc;
	$expPurchase	= ncmExecute($sql,[$startDate,$endDate]);
	$expPurchaseB	= ncmExecute($sql,[$startDateBack,$endDateBack]);

	if($expPurchase){
		$totalExpenses 	= $expPurchase['total'];
		$totalExpensesB = $expPurchaseB['total'];
	}

	$initialCash = ($totalCashB + $totalPaymentsB) - ($totalPurchaseB + $totalPPaymentB + $totalExpensesB);

	$outcomeTotal 	= $totalPurchase + $totalPPayment + $totalExpenses;
	$incomeTotal 	= $totalPayments + $totalCash;
	$remains 		= $incomeTotal - $outcomeTotal;
	$remainstatus 	= ($remains <= 0) ? 'text-danger' : '';
	$initialstatus 	= ($initialCash <= 0) ? 'text-danger' : '';

	$jsonResult = 	[
						'cashSales' 			=> formatCurrentNumber($totalCash),
						'cashPayments' 			=> formatCurrentNumber($totalPayments),
						'incomeTotal'			=> formatCurrentNumber($incomeTotal),
						'stockPurchase'			=> formatCurrentNumber($totalPurchase),
						'expensesPurchase' 		=> formatCurrentNumber($totalExpenses),
						'outPayment'			=> formatCurrentNumber($totalPPayment),
						'outcomeTotal'			=> formatCurrentNumber($outcomeTotal),
						'remains' 				=> formatCurrentNumber($remains),
						'initialCash' 			=> formatCurrentNumber($initialCash),
						'accumulated' 			=> formatCurrentNumber($initialCash + $remains),
						'initialStatus' 		=> $initialstatus,
						'remainsStatus' 		=> $remainstatus
					];

	header('Content-Type: application/json');
	dai(json_encode($jsonResult));
}


if(validateHttp('action') == 'getChartSales'){
	//CHARTS
	$startDateCompare 	= date('Y-m-d',strtotime($startDate));
	$endDateCompare 	= date('Y-m-d',strtotime($endDate));
	$hoursArray 		= ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24'];
	$barsLabel 	= [];
	$barsData 	= [];
	$unitsLabel = [];
	$unitsData 	= [];
	$hoursLabel = [];
	$hoursData 	= [];
	$glabels 	= [];
	$gGross 	= [];
	$gGrossBack = [];

	list($startDateBack,$endDateBack) = getPreviousPeriod($startDate,$endDate);


	$chart 		= ncmExecute($sql,[$startDate,$endDate],true,true);
	$chartBack 	= ncmExecute($sql,[$startDateBack,$endDateBack],true,true);

	if($chartBack){
		$tCountB 		= 0;
		$tDiscountB 	= 0;
		$tTaxB 			= 0;
		$tTotalB		= 0;
		while (!$chartBack->EOF) {
			$tCountB 		+= $chartBack->fields['count'];
			$tDiscountB 	+= $chartBack->fields['discount'];
			$tTaxB 			+= $chartBack->fields['tax'];
			$tTotalB		+= $chartBack->fields['total'] - $chartBack->fields['discount'];
			$chartBack->MoveNext();
		}
	}

	if($chart){

		$byweek 	= [];
		$ubyweek 	= [];
		$uByHour 	= [];

		$z 			= 0;
		$x 			= 0;

		if($chartBack){
			$chartBack->MoveFirst();
		}

		while($z < counts($calendar)){
			$fields 		= $chart->fields;
			if($chartBack){
				$fieldsB 		= $chartBack->fields;
			}
			
			$discount 		= 0;
			$total 			= 0;
			$units 			= 0;
			$dayTotal 		= 0;
			$totalBack 		= 0;

			if($isDay){ //si es el mismo dia
				$t 	= getDateTimeByPieces($fields['date']);
				$tb = getDateTimeByPieces($fieldsB['date']);
				if($calendar[$z] == $t['h']){
					
					$discount 		= $fields['discount'];
					$total 			= $fields['total'];
					$units 			= $fields['units'];
					$dayTotal 		= $total - $discount;
					$totalBack 		= $fieldsB['total'] - $fieldsB['discount'];

					$glabels[] 		= $t['d'] . '/' . $t['m'] . '/' . $t['y'] . ' vs ' . $tb['d'] . '/' . $tb['m'] . '/' . $tb['y'] . ': ' . $t['h'] . 'h';

					if($fields['type'] == '6'){ //esto es para no mostrar las devoluciones en los graficos solo en las tablas
						$gGross[] 		= '0';
						$gGrossBack[] 	= '0';
					}else{
						$gGross[] 		= $dayTotal;
						$gGrossBack[] 	= $totalBack;
					}
					
					$chart->MoveNext();
					if($chartBack){
						$chartBack->MoveNext();
					}
					$x++;

				}else{
					$t 				= getDateTimeByPieces($calendar[$z]);
					$glabels[] 		= $t['d'] . '/' . $t['m'] . '/' . $t['y'] . ' vs ' . $tb['d'] . '/' . $tb['m'] . '/' . $tb['y'] . ': ' . $t['h'] . 'h';
					$gGross[] 		= '0';
					$gGrossBack[] 	= '0';
				}
			
				$z++;
			
			}else{

				$t 				= getDateTimeByPieces($fields['date']);
				$tb 			= getDateTimeByPieces($fieldsB['date']);
				
				$units 			= $fields['units'];
				$dayTotal 		= $fields['total'] - $fields['discount'];
				$totalBack 		= $fieldsB['total'] - $fieldsB['discount'];

				if($t['date'] == $calendar[$z]){

					$byweek[$t['w']]  += $dayTotal;
					$ubyweek[$t['w']] += $units;
					$uByHour[$t['h']] += $dayTotal;

					
					$gGross[] 		= $dayTotal;
					$gGrossBack[] 	= $totalBack;
					
					
					$glabels[] 			= $t['d'] . '/' . $t['m'] . '/' . $t['y'] . ' vs ' . $tb['d'] . '/' . $tb['m'] . '/' . $tb['y'];

					$chart->MoveNext();
					if($chartBack){
						$chartBack->MoveNext();
					}
				}else{
					$t 				= getDateTimeByPieces($calendar[$z]);

					$glabels[] 		= $t['d'] . '/' . $t['m'] . '/' . $t['y'];
					$gGross[] 		= '0';
					$gGrossBack[] 	= '0';
					$gNet[] 		= '0';
				}

				$z++;

			}
		}

		$chart->Close();
		if($chartBack){
			$chartBack->Close();
		}

		@ksort($byweek);
		@ksort($ubyweek);
		@ksort($uByHour);

		$days 		= array('1'=>'Lunes','2'=>'Martes','3'=>'Miercoles','4'=>'Jueves','5'=>'Viernes','6'=>'Sabado','7'=>'Domingo');

		$week 		= $byweek + array_fill(1, 7, 0);
		$uweek 		= $ubyweek + array_fill(1, 7, 0);
		$unitsdona 	= '';
		$bars 	 	= '';

		for($i = 1; $i < counts($days) + 1; $i++){
			$barsLabel[] 	= $days[$i] . ": Cant. " . $uweek[$i];
			$barsData[] 	= $week[$i];
			$unitsLabel[] 	= $days[$i];
			$unitsData[] 	= $uweek[$i];
		}

		if(counts($uByHour) > 0){
			foreach($uByHour as $hour => $amount){
				$hoursLabel[] 	= $hour . " hs.";
				$hoursData[] 	= $amount;
			}
		}
	}

	$jsonResult['chart'] = [
							'sales' => [
										'labels' 	=> $glabels,
										'gross' 	=> $gGross,
										'grossB'	=> $gGrossBack
										],
							'days' => [
										'labels' 	=> $barsLabel,
										'data' 		=> $barsData
										]

							];

	header('Content-Type: application/json');
	dai(json_encode($jsonResult));
}

?>
    
<?php
echo reportsTitle('Flujo de Caja', true);
?>

<div class="col-xs-12 no-padder text-center hidden-print">

	<section class="col-md-3 col-sm-6">
	    <div class="b-b text-center wrapper-md">
			<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalUtility"><?=placeHolderLoader()?></span></div>
			Saldo Inicial
		</div>
	</section>

	<section class="col-md-3 col-sm-6">
	    <div class="b-b text-center wrapper-md">
			<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalSubtotal"><?=placeHolderLoader()?></span></div>
			Ingresos
		</div>
	</section>

<section class="col-md-3 col-sm-6">
    <div class="b-b text-center wrapper-md">
		<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalCogs"><?=placeHolderLoader()?></span></div>
		Egresos
	</div>
</section>    

<section class="col-md-3 col-sm-6">
    <div class="b-b text-center wrapper-md">
		<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalDiscount"><?=placeHolderLoader()?></span></div>
		Saldo Final
	</div>
</section>

</div>

<div class="col-xs-12 no-padder m-t m-b-lg push-chat-down">
	<section class="col-xs-12 panel r-24x bg-white wrapper">
		<a class="btn r-3x b b-light font-bold hidden-print" id="export" href="#" data-toggle="tooltip" data-placement="right" title="Exportar listado a Excel"><i class="material-icons">file_download</i></a>
		<table class="table m-t" id="salesTable">
	    	<?=placeHolderLoader('table')?>
	    </table>
	</section>
</div>

<script>
$(document).ready(function(){
	var baseUrl = '<?=$baseUrl?>';
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>",false,true);

	$.get(baseUrl + "?action=getCashFlow",function(result){

		$('#globalSubtotal').html(result.incomeTotal);
		//$('#globalSubtotalB').html(result.grossSalesB);

		$('#globalCogs').html(result.outcomeTotal);
		//$('#globalCogsB').html(result.totalReturnsB);

		$('#globalDiscount').html(result.remains);
		//$('#globalDiscountB').html(result.totalDiscountsB);

		$('#globalUtility').html(result.initialCash);
		//$('#globalUtilityB').html(result.netSalesB);

		//ventas
		var table 		= 	
							'<tr class="bg-light bg">' + 
							'	<td class="font-bold text-u-c">' +
							' 		Saldo Inicial' +	
							'	</td>' +
							'	<td class="text-right font-bold initialStatus">' +
									result.initialCash +
							'	</td>' +
							'</tr>' +

							'<tr>' + 
							'	<th class="text-u-c">' +
							' 		Ingresos' +	
							'	</th>' +
							'	<th></th>' +
							'</tr>' +

							'<tr>' + 
							'	<td>' +
							' 		Ingresos por Ventas' +	
							'	</td>' +
							'	<td class="text-right">' +
									result.cashSales +
							'	</td>' +
							'</tr>' +

							'<tr>' + 
							'	<td>' +
							' 		Cobros de deudas' +	
							'	</td>' +
							'	<td class="text-right">' +
									result.cashPayments +
							'	</td>' +
							'</tr>' +

							'<tr class="bg-light lter">' + 
							'	<td class="font-bold">' +
							' 		Total de Ingresos' +	
							'	</td>' +
							'	<td class="text-right font-bold">' +
									result.incomeTotal +
							'	</td>' +
							'</tr>' +

							'<tr>' + 
							'	<th class="text-u-c">' +
							' 		Egresos' +	
							'	</th>' +
							'	<th></th>' +
							'</tr>' +

							'<tr>' + 
							'	<td>' +
							' 		Compra de mercadería' +	
							'	</td>' +
							'	<td class="text-right">' +
									result.stockPurchase +
							'	</td>' +
							'</tr>' +

							'<tr>' + 
							'	<td>' +
							' 		Gastos' +	
							'	</td>' +
							'	<td class="text-right">' +
									result.expensesPurchase +
							'	</td>' +
							'</tr>' +

							'<tr>' + 
							'	<td>' +
							' 		Pagos de deudas' +	
							'	</td>' +
							'	<td class="text-right">' +
									result.outPayment +
							'	</td>' +
							'</tr>' +

							'<tr class="bg-light lter">' + 
							'	<td class="font-bold">' +
							' 		Total de Egresos' +	
							'	</td>' +
							'	<td class="text-right font-bold">' +
									result.outcomeTotal +
							'	</td>' +
							'</tr>' +

							'<tr class="bg-light bg">' + 
							'	<td class="font-bold text-u-c">' +
							' 		Saldo Final' +	
							'	</td>' +
							'	<td class="text-right font-bold remainsStatus">' +
									result.remains +
							'	</td>' +
							'</tr>' +

							'<tr class="bg-light dk">' + 
							'	<td class="font-bold text-u-c">' +
							' 		Saldo Acumulado' +	
							'	</td>' +
							'	<td class="text-right font-bold remainsStatus">' +
									result.accumulated +
							'	</td>' +
							'</tr>'
							;

		$('#salesTable').html(table);

		$('.initialStatus').addClass(result.initialStatus);
		$('.remainsStatus').addClass(result.remainsStatus);
		
		$('[data-toggle="tooltip"]').tooltip();
	});

	onClickWrap('#export',function(event,tis){
		table2Xlsx('salesTable','flujo_de_caja');
	});

});
</script>

<?php
include_once('includes/compression_end.php');
dai();
?>