<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
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
$limitDetail		= 3000;
$offsetDetail		= 0;
$MAX_DAYS_RANGE 	= 1;

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

if(validateHttp('action') == 'generalTable'){
	$limits = getTableLimits($limitDetail,$offsetDetail);
	
	$sql =		"SELECT * FROM item WHERE itemTrackInventory = 1 AND itemStatus = 1 AND itemType IN('product','compound') AND companyId = ?" . $limits;


	$head = 	'<thead class="text-u-c">'.
				'	<tr>'.
				'		<th>Artículo</th>'.
				'		<th>SKU/Código</th>'.
				'		<th class="text-center">P. Costo</th>'.
				'		<th class="text-right">Stock Total</th>'.
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	$result 	= ncmExecute($sql,[COMPANY_ID],true,true);

	if($result){

		$fullTable 	= [];
		$table 		= '';
		$allStock 	= [];//getAllItemStock();
		$i 			= 0;

		while (!$result->EOF) {
			$fields 		= $result->fields;
			$id 			= $fields['itemId'];
			$eid 			= enc($id);
			$name 			= $fields['itemName'];
			$sku	 		= $fields['itemSKU'];
			$subStock 		= 0;//sumo las cantidades en depositos para restarle al total
			$brdr 			= 'b-l b-light b-5x';
			$losStock 		= 0;

			//stock
			$stockR = ncmExecute('SELECT stockOnHand,stockOnHandCOGS FROM stock WHERE itemId = ? ' . $roc . ' AND DATE(stockDate) <= ? ORDER BY stockId DESC LIMIT 1',[$id,$startDate],true);

			$table 	.= 	'<tr>'.
						'	<td>' . $name . '</td>'.
						'	<td class="text-muted">' . $sku . '</td>'.
						'	<td class="text-right bg-light bg">' . CURRENCY . ' ' . formatCurrentNumber($stockR['stockOnHandCOGS']) . '</td>'.
						'	<td class="text-right bg-light bg">' . formatQty($stockR['stockOnHand']) . '</td>'.
						'</tr>';

			$losStock = ($stock - $losStock);

			$result->MoveNext();
		}

	}

	$foot = 	'</tbody>'.
				'<tfoot>'.
				'	<tr>'.
				'		<th colspan="2"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
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

<?php
$repTitle = [
				'title' 		=> '<div class="text-md text-right font-default">Niveles de</div> Stock por Día',
				'maxDays' 		=> $MAX_DAYS_RANGE,
				'hideChart' 	=> true
			];

echo reportsDayAndTitle($repTitle);
?>

<div class="col-xs-12 panel wrapper r-24x m-t push-chat-down tableContainer">
	<div class="table-responsive">
    	<table class="table table-hover table1 col-xs-12 no-padder tableContainer" id="tableSummary">
    		<?=placeHolderLoader('table')?>
    	</table>
    </div>
</div>

<script>
var baseUrl = '<?=$baseUrl?>';

$(document).ready(function(){
	FastClick.attach(document.body);
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");

	var rawUrl 		= baseUrl + "?action=generalTable";

	$.get(rawUrl,function(result){

		var options = {
						"container" 	: ".tableContainer",
						"url" 			: rawUrl,
						"rawUrl" 		: rawUrl,
						"iniData" 		: result.table,
						"table" 		: ".table1",
						"sort" 			: 0,
						"footerSumCol" 	: [2,3],
						"currency" 		: "<?=CURRENCY?>",
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 		: <?=$offsetDetail?>,
						"limit" 		: <?=$limitDetail?>,
						"nolimit" 		: false,
						"noMoreBtn" 	: true,
						"tableName" 	: 'tableSummary',
						"fileTitle" 	: 'Stock por Día',
						"ncmTools"		: {
											left 	: '',
											right 	: ''
										  },
						"colsFilter"	: {
											name 	: 'stockbydays',
											menu 	:  [
															{"index" : 0, "name" : "Artículo", "visible" : true},
															{"index" : 1, "name" : "SKU", "visible" : true},
															{"index" : 2, "name" : 'Costo', "visible" : true},
															{"index" : 3, "name" : 'Stock', "visible" : true}
														]
										  }
		};

		ncmDataTables(options);
	});

});

</script>

<?php
include_once('includes/compression_end.php');
dai();
?>