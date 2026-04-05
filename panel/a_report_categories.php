<?php
include_once('includes/top_includes.php');

topHook();
allowUser('sales','view');

$baseUrl = '/' . basename(__FILE__,'.php');
$MAX_DAYS_RANGE = 31;

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 				= str_replace(['outletId','registerId','companyId'],['b.outletId','b.registerId','b.companyId'],getROC(1));

$itemsArray 		= [];
$maxItemsInGraph 	= 50;
$limitDetail		= 500;
$offsetDetail		= 0;


if(validateHttp('action') == 'generalTable'){
	$sql = "	SELECT a.itemId as id, 
								SUM(a.itemSoldUnits) as usold,
								SUM(a.itemSoldTotal) as total,
								SUM(a.itemSoldTax) as tax, 
								SUM(a.itemSoldCOGS * a.itemSoldUnits) as cogs,
								SUM(a.itemSoldComission) as comission,
								SUM(a.itemSoldDiscount * a.itemSoldUnits) as discount,
								c.categoryId as category
						FROM 	itemSold a, 
								transaction b, 
								item c 
								
						WHERE a.itemId = c.itemId 
						AND a.itemSoldDate 
						BETWEEN ? 
						AND ? 
						AND a.transactionId = b.transactionId 
						" . $roc . "
						AND b.transactionType IN(0,3)
						GROUP BY category
						ORDER BY usold DESC";

	$result   	= ncmExecute($sql,[$startDate,$endDate],true,true);

	$array 			= [];
	$table 			= '';
	$jsonResult 	= [];

	$head 	= 	'<thead>' .
		  		'	<tr>' .
		    	'		<th>Nombre</th>'.
				'		<th class="text-center">Unidades</th>'.
				'		<th class="text-center">Costo</th>'.
				'		<th class="text-center">'.TAX_NAME.'</th>'.
				'		<th class="text-center">Descuentos</th>'.
				'		<th class="text-center">Total</th>'.
				'		<th class="text-center" style="max-width:15%">Porcentaje</th>'.
		    	'	</tr>' .
		  		'</thead>' .
		  		'<tbody>';

	if($result){

		$taxes 				= getAllTax();
		$category 			= getAllItemCategories();
		$compoundsDiscount 	= getAllCombosCompoundsDiscount($roc,$startDate,$endDate);
		
		while (!$result->EOF) {
			$fields 	= $result->fields;
			$id 		= $fields['id'];
			$lessComp 	= $compoundsDiscount[$id];

			$uSold 		= $fields['usold'] 		- $lessComp['itemSoldUnits'];
			$tax 		= $fields['tax'] 		- $lessComp['itemSoldTax'];
			$discount	= $fields['discount'] 	- $lessComp['itemSoldDiscount'];
			$total 		= $fields['total'] 		- $lessComp['itemSoldTotal'];
			$cogs 		= $fields['cogs'] 		- $lessComp['itemSoldCOGS'];
			$percent 	= 0;
			$name		= iftn($category[$fields['category']]['name'],'Sin Categoría');

			if(array_key_exists($id, $itemsArray)){
				$itemsArray[$id]['usold'] 		+= $uSold;
				$itemsArray[$id]['tax'] 		+= $tax;
				$itemsArray[$id]['discount'] 	+= $discount;
				$itemsArray[$id]['total'] 		+= $total;
				$itemsArray[$id]['cogs'] 		+= $cogs;
			}else{
				$itemsArray[$id]['usold'] 		= $uSold;
				$itemsArray[$id]['tax'] 		= $tax;
				$itemsArray[$id]['discount'] 	= $discount;
				$itemsArray[$id]['total'] 		= $total;
				$itemsArray[$id]['name'] 		= $name;
				$itemsArray[$id]['id'] 			= $id;
				$itemsArray[$id]['cogs'] 		= $cogs;
			}

			$allTotal += $total;

			$result->MoveNext();
		}

		$result->Close();

		usort($itemsArray, function($a, $b) {
		    return $b['usold'] - $a['usold'];
		});

		$label 		= '';
		$data 		= '';

		$tUsold 	= 0;
		$tTax 		= 0;
		$tDiscount 	= 0;
		$tTotal 	= 0;

		for($x = 0; $x < counts($itemsArray); $x++){
			$fields 	= $itemsArray[$x];

			$id 		= $fields['id'];
			$uSold 		= $fields['usold'];
			$discount	= $fields['discount'];
			$cogs		= $fields['cogs'];
			$total 		= $fields['total'];
			$name		= $fields['name'];
		    $tax 		= $fields['tax'];
		    $taxName 	= $fields['taxName'];
		    $percent 	= ($total && $allTotal) ? floor( divider( ($total * 100), $allTotal, true ) ) : 100;

		    $barColor 	= ($percent > 50) ? 'success' : 'warning';

		    $bar 		= 	'<div class="progress progress-xs dker progress-striped m-b-n m-t-sm">' .
                  			'	<div class="progress-bar bg-' . $barColor . '" data-toggle="tooltip" data-original-title="' . (($percent<1)?'<1':$percent) . '%" style="width: ' . $percent . '%"></div>' .
                			'</div>' . '<span class="hidden">' . (($percent<1)?'<1':$percent) . '%</span>';

			$table .= 	'<tr data-load="' . $baseUrl . '?action=viewData"> <td class="bg-light lter"> '.$name.' </td>' .
						'	<td class="text-right" data-order="'.$uSold.'"> '.formatCurrentNumber($uSold).' </td>' .
						'	<td class="text-right" data-order="'.$cogs.'" data-format="money"> '.formatCurrentNumber($cogs).' </td>' .
						'	<td class="text-right" data-order="'.$tax.'" data-format="money"> ' . formatCurrentNumber($tax) .
						' 		<span class="hidden">'.$taxName.'</span>' .
						'	</td>' .
						'	<td class="text-right" data-order="'.$discount.'" data-format="money"> '.formatCurrentNumber($discount).' </td>' .
						'	<td class="text-right" data-order="'.$total.'" data-format="money"> '.formatCurrentNumber($total).' </td>' .
						'	<td class="text-right" data-order="'.$percent.'"> ' . $bar . ' </td> ' .
						'</tr>';
 
			$tUsold 	+= $uSold;
			$tTax 		+= $tax;
			$tDiscount 	+= $discount;
			$tTotal 	+= $total;
		}
			

		$uSold 		= 0;
		$tax 		= 0;
		$discount	= 0;
		$total 		= 0;

		$barLabel 	= [];
		$barData 	= [];

		for($x=0;$x<count($itemsArray);$x++){
			$fields 	= $itemsArray[$x];
			
			$uSold 		+= $fields['usold'];
			$tax 		+= $fields['tax'];
			$discount	+= $fields['discount'];
			$total 		+= $fields['total'];
			$name		= $fields['name'];

			if($x < $maxItemsInGraph){
				//$barLabel[] = $name;
				//$barData[] 	= $fields['usold'];

				$name 		= ($name == 'None') ? 'Sin categoría' : $name;
      			$barData[] 	= ['title' => $name, 'total' => $fields['usold']];
			}
		}

		$internals 			= lessInternalTotals(getROC(1),$startDate,$endDate);
		if($internals['total']){
			$table .= 	'<tr>' .
						'	<td class="font-bold">INTERNAS</td>' .
						'	<td class="text-right" data-order="-' . $internals['qty'] . '">' . 
								'-' . formatQty($internals['qty']) . 
						' 	</td>' .
						'	<td class="text-right" data-format="money">0</td>' .
						'	<td class="text-right" data-order="' . $internals['tax'] . '" data-format="money"> ' . 
								'-' . formatCurrentNumber($internals['tax']) .
						'	</td>' .
						'	<td class="text-right" data-order="-' . $internals['discount'] . '" data-format="money"> ' .
								'-' . formatCurrentNumber($internals['discount']) . 
						' 	</td>' .
						'	<td class="text-right" data-order="-' . $internals['total'] . '" data-format="money"> ' .
								'-' . formatCurrentNumber($internals['total']) .
						' 	</td>' .
						'	<td class="text-right">-</td> ' .
						'</tr>';

			$uSold 		-= $internals['qty'];
			$tax 		-= $internals['tax'];
			$discount 	-= $internals['discount'];
			$total 		-= $lessInternalTotal;
		}

	}

	$foot = 	'</tbody>' .
				'<tfoot>' .
				'	<tr>' .
				'		<th>TOTALES</th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right">100%</th>'.
				'	</tr>' .
				'</tfoot>';

	$currency 				= '<span class="text-muted text-lg">' . CURRENCY . '</span> ';

	$fullTable 				= $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;
	$jsonResult['data'] 	= [
									'usold' 	=> formatQty($uSold),
									'tax' 		=> $currency . formatCurrentNumber($tax),
									'discount' 	=> $currency . formatCurrentNumber($discount),
									'total' 	=> $currency . formatCurrentNumber($total)
								];

	$jsonResult['chart'] 	= $barData;

	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));
}

echo reportsDayAndTitle([
					'title' 		=> '<div class="text-md text-right font-default">Ventas por</div> Categorías',
					'maxDays' 		=> $MAX_DAYS_RANGE
				]);

?>

<div class="col-xs-12 no-padder text-center">
	<section class="col-md-3 col-sm-6">
	    <div class="text-center wrapper-md">
			<div class="h1 m-t total font-bold globalUsold"><?=placeHolderLoader()?></div>
			Unidades
		</div>
	</section>
	<section class="col-md-3 col-sm-6">
	    <div class="text-center wrapper-md">
			<div class="h1 m-t total font-bold globalDiscount"><?=placeHolderLoader()?></div>
			Descuentos
		</div>
	</section>
	<section class="col-md-3 col-sm-6">
	    <div class="text-center wrapper-md">
			<div class="h1 m-t total font-bold globalTotal"><?=placeHolderLoader()?></div>
			Total
		</div>
	</section>
	<section class="col-md-3 col-sm-6">
	    <div class="text-center wrapper-md">
			<div class="h1 m-t total font-bold globalTax"><?=placeHolderLoader()?></div>
			Impuestos (<?=TAX_NAME?>)
		</div>
	</section>
</div>

<div class="col-xs-12 clear wrapper panel r-24x bg-white push-chat-down">
	<div class="tableContainer">
    	<table class="table table1 col-xs-12 no-padder" id="tableCategories">
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
						"sort" 			: 1,
						"footerSumCol" 	: [1,2,3,4,5],
						"currency" 		: "<?=CURRENCY?>",
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 		: <?=$offsetDetail?>,
						"limit" 		: <?=$limitDetail?>,
						"nolimit" 		: false,
						"noMoreBtn" 	: true,
						"tableName" 	: 'tableCategories',
						"fileTitle" 	: 'Ranking de Categorías',
						"ncmTools"		: {
											left 	: '',
											right 	: ''
										  },
						"colsFilter"	: {
											name 	: 'categories1',
											menu 	:  [
															{"index":0,"name":"Nombre","visible":true},
															{"index":1,"name":"Unidades","visible":true},
															{"index":2,"name":'Costo',"visible":false},
															{"index":3,"name":'IVA',"visible":false},
															{"index":4,"name":'Descuentos',"visible":false},
															{"index":5,"name":'Total',"visible":true},
															{"index":6,"name":'Porcentaje',"visible":true}
														]
										  }
		};

		ncmDataTables(options);

		$('.globalUsold').html(result.data.usold);
		$('.globalDiscount').html(result.data.discount);
		$('.globalTotal').html(result.data.total);
		$('.globalTax').html(result.data.tax);

		if(result.chart){

			$('#myChart').removeClass('hidden');
			$('#loadingChart').addClass('hidden');

			var myChart 		= document.getElementById('myChart').getContext("2d");

			var gradientStroke 	= myChart.createLinearGradient(300, 0, 100, 0);
			gradientStroke.addColorStop(0, "#4cb6cb");
			gradientStroke.addColorStop(1, "#54cfc7");

			setTimeout(function(){

				var catsToolTips = ncmHelpers.cloneObj(chartTooltipStyle);

				catsToolTips.tooltips.callbacks.title = function(){
	              return false;
	            };
	            catsToolTips.tooltips.callbacks.label = function(item, data) {
	              var dataset   = data.datasets[item.datasetIndex];
	              var dataItem  = dataset.data[item.index];
	              return dataItem.g + ': ' + dataItem.v;
	            }

				var categories = new Chart(myChart, {
					type  : 'treemap',
					data  : {
						datasets: [{
							tree    		: result.chart,
							backgroundColor	: gradientStroke,
							spacing     : 3,
							borderWidth : 0,
							borderColor : "rgba(180,180,180, 0.15)",
							key         : 'total',
							groups      : ['title'],
							fontColor   : '#fff',
							fontFamily  : 'Source Sans Pro',
						}]
					},

					options: {
						maintainAspectRatio: false,
						title: {
							display: false
						},
						legend: {
							display: false
						},
						tooltips : catsToolTips.tooltips,
					}

	            });
				
			}, 200);
		}
	});

	

});



</script>

<?php
dai();
?>