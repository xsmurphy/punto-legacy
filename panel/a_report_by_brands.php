<?php
include_once('includes/top_includes.php');

topHook();
allowUser('sales','view');
$baseUrl = '/' . basename(__FILE__,'.php');
$MAX_DAYS_RANGE = 31;

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 				= str_replace(['outletId','registerId','companyId'],['c.outletId','c.registerId','c.companyId'],getROC(1));

$itemsArray 		= [];
$maxItemsInGraph 	= 50;
$limitDetail		= 500;
$offsetDetail		= 0;

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

if( $_GET['doit'] == 'beibe'){


	error_reporting(E_ALL);
	ini_set('display_errors', 'On');
	ini_set('memory_limit', '256M');

	/*$sql = "SELECT a.customerId as cliente, a.transactionId as transId, SUM(b.itemSoldTotal) as total
			FROM transaction a USE INDEX(transactionDate,transactionType), itemSold b
			WHERE a.transactionDate
			BETWEEN '2020-08-01 00:00:00'
			AND '2021-06-13 23:59:59'
			AND a.transactionType IN(0,3)
			AND (a.customerId IS NOT NULL AND a.customerId > 0)
			AND a.outletId = 4058
			AND a.transactionId = b.transactionId
			AND b.itemId IN(206374,206375,206376,206377,206378,206379,206380,206381,206382,206383,206384,206385,206386,206387,206388,206389,206390,206391,206392,206393,206394,206395,206396,206397,206398,206399,206400,206401,206402,206403,206404,206405,206406,206407,206408,206409,206410,206411,206412,206413,206414,206415,206416,206417,206418,206419,206420,206421,206422,206423,206424,206425,206426,206427,206428,206429,206430,206431,206432,206433,206434,206435,206436,206437,206438,206439,206440,206441,206442,206443,206444,206445,206446,206447,206448,206449,206450,206451,206452,206453,206454,206455,206456,206457,206458,206459,206460,206461,206462,206463,206464,206465,206519,206528,206529,206530,206531,206532,206533,206534,206535,206536,206537,206538,206539,206540,206541,206542,206543,206544,206545,206546,206547,206548,206549,206550,206551,206552,206553,206554,206555,206556,206557,206558,206559,206560,206694,206695,206696,206697,206698,206699,206700,206701,207674,207682,207683,207961,207964,208002,208003,208004,209376,209377,209378,209379,209380,215307,215309,217240,217241,217272,217273,224497,234069,234070,234071,234072,234073,234074,234075,234076,234077,234078,234079)
			AND b.itemSoldTotal > 0
			GROUP BY a.customerId
			ORDER BY cliente DESC LIMIT 100;";*/
	$sql = "SELECT 
transactionId, customerId
FROM transaction a USE INDEX(transactionDate,transactionType)
WHERE transactionDate
BETWEEN '2020-08-01 00:00:00'
AND '2021-06-13 23:59:59'
AND transactionType IN(0,3)
AND (customerId IS NOT NULL AND customerId > 0)
AND outletId = 4058 ORDER BY transactionDate DESC LIMIT 5000";

$results = ncmExecute($sql,[],false,true);


if($results){
	$transC 		= [];
	$transData 	= [];

	while (!$results->EOF) {
		$fields 	= $results->fields;
		$transC[] 	= $fields['transactionId'];
		$transData[$fields['transactionId']] = ['customer' => $fields['customerId']];

		$results->MoveNext();
	}

	$results->Close();
}


	/*$sql = "SELECT a.customerId as cliente, a.transactionId as transId, b.itemSoldUnits as qty, b.itemSoldTotal as total, b.itemId as item
			FROM transaction a USE INDEX(transactionDate,transactionType), itemSold b
			WHERE a.transactionDate
			BETWEEN '2020-08-01 00:00:00'
			AND '2021-06-13 23:59:59'
			AND a.transactionType IN(0,3)
			AND a.outletId = 4058
			AND a.transactionId = b.transactionId
			AND b.itemSoldTotal > 0
			ORDER BY cliente DESC LIMIT 20000;";*/
	$sql = "SELECT itemId as item, itemSoldTotal as total, itemSoldUnits as qty, transactionId as transId FROM itemSold WHERE transactionId IN(" . implode(',', $transC) . ")";

$result = ncmExecute($sql,[],true,true);

if($result){

		$contacts 		= getAllContacts(1,true,'contactId',false,true);
		$contacts 		= $contacts[0];
		$arr 			= [];
		$trans 			= [];
		$total 			= [];
		$allItems 		= getAllItems(false, true);
		$allData 		= [];
		$allowedItems 	= [206374,206375,206376,206377,206378,206379,206380,206381,206382,206383,206384,206385,206386,206387,206388,206389,206390,206391,206392,206393,206394,206395,206396,206397,206398,206399,206400,206401,206402,206403,206404,206405,206406,206407,206408,206409,206410,206411,206412,206413,206414,206415,206416,206417,206418,206419,206420,206421,206422,206423,206424,206425,206426,206427,206428,206429,206430,206431,206432,206433,206434,206435,206436,206437,206438,206439,206440,206441,206442,206443,206444,206445,206446,206447,206448,206449,206450,206451,206452,206453,206454,206455,206456,206457,206458,206459,206460,206461,206462,206463,206464,206465,206519,206528,206529,206530,206531,206532,206533,206534,206535,206536,206537,206538,206539,206540,206541,206542,206543,206544,206545,206546,206547,206548,206549,206550,206551,206552,206553,206554,206555,206556,206557,206558,206559,206560,206694,206695,206696,206697,206698,206699,206700,206701,207674,207682,207683,207961,207964,208002,208003,208004,209376,209377,209378,209379,209380,215307,215309,217240,217241,217272,217273,224497,234069,234070,234071,234072,234073,234074,234075,234076,234077,234078,234079];

		while (!$result->EOF) {
			$fields 	= $result->fields;

			if(in_array($fields['item'], $allowedItems) && $fields['qty'] > 0){
				$cusId 		= $transData[$fields['transId']]['customer'];

				$qty 		= $fields['qty'] > 20 ? $fields['qty'] / 100 : $fields['qty'];
				$tItm 		= $allItems[$fields['item']]['price'];

				$total 		= ($tItm * $qty);

				if( validInArray($allData,$cusId) ){
					$allData[$cusId]['total'] 	+= $total;
					$allData[$cusId]['trans'][] = $fields['transId'];
				}else{
					$allData[$cusId] = [
						'total' => $total,
						'trans' => [$fields['transId']]
					];
				}

			}

			$result->MoveNext();
		}

		$result->Close();

		

		foreach($allData as $cliente => $fields){
			$name 		= $contacts[$cliente]['contactName'];
			$loyalty 	= $contacts[$cliente]['contactLoyaltyAmount'];
			$arrCounts 	= array_unique($fields['trans']);

			echo $name . ';' . counts($arrCounts) . ';' . $fields['total'] . ';' . $loyalty . '<br>';
		}
}else{
	echo 'no results ';
}

	dai('end');
}


if(validateHttp('action') == 'generalTable'){

	$result   	= ncmExecute("	SELECT a.itemId as id, 
								SUM(a.itemSoldUnits) as usold,
								SUM(a.itemSoldTotal) as total,
								SUM(a.itemSoldCOGS) as cogs, 
								SUM(a.itemSoldTax) as tax, 
								SUM(a.itemSoldDiscount) as discount,
								b.brandId as brand
						FROM itemSold a, 
								item b, 
								transaction c 
						WHERE a.itemId = b.itemId 
						AND a.itemSoldDate 
						BETWEEN ? 
						AND ? 
						AND a.transactionId = c.transactionId 
						" . $roc . "
						AND c.transactionType IN(0,3)
						GROUP BY brand
						ORDER BY usold DESC",[$startDate,$endDate],true,true);


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
				'		<th class="text-center">Subtotal</th>'.
				'		<th class="text-center">Total</th>'.
				'		<th class="text-center" style="max-width:15%">Porcentaje</th>'.
		    	'	</tr>' .
		  		'</thead>' .
		  		'<tbody>';

	if($result){

		$taxes 		= getAllTax();
		$brand 		= getAllItemBrands();
		
		while (!$result->EOF) {
			$id 		= $result->fields['id'];
			$uSold 		= $result->fields['usold'];
			$tax 		= $result->fields['tax'];
			$discount	= $result->fields['discount'];
			$subtotal	= $result->fields['total'] + $discount;
			$total 		= $result->fields['total'];
			$cogs 		= $result->fields['cogs'];
			$percent 	= 0;
			$name		= iftn($brand[$result->fields['brand']]['name'],'Sin Marca');

			if(array_key_exists($id, $itemsArray)){
				$itemsArray[$id]['usold'] 		+= $uSold;
				$itemsArray[$id]['tax'] 		+= $tax;
				$itemsArray[$id]['discount'] 	+= $discount;
				$itemsArray[$id]['subtotal'] 	+= $subtotal;
				$itemsArray[$id]['total'] 		+= $total;
				$itemsArray[$id]['cogs'] 		+= $cogs;
			}else{
				$itemsArray[$id]['usold'] 		= $uSold;
				$itemsArray[$id]['tax'] 		= $tax;
				$itemsArray[$id]['discount'] 	= $discount;
				$itemsArray[$id]['subtotal'] 	= $subtotal;
				$itemsArray[$id]['total'] 		= $total;
				$itemsArray[$id]['name'] 		= $name;
				$itemsArray[$id]['id'] 			= $id;
				$itemsArray[$id]['cogs'] 		= $cogs;
			}

			$isdata = true;

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
		$tSubTotal 	= 0;
		$tTotal 	= 0;

		for($x=0;$x<counts($itemsArray);$x++){
			$fields 	= $itemsArray[$x];

			$id 		= $fields['id'];
			$uSold 		= $fields['usold'];
			$discount	= $fields['discount'];
			$cogs		= $fields['cogs'];
			$subtotal	= $fields['subtotal'];
			$total 		= $fields['total'];
			$name		= $fields['name'];
		    $tax 		= $fields['tax'];
		    $taxName 	= $fields['taxName'];
		    $percent 	= floor(($total*100)/$allTotal);

		    $barColor 	= ($percent > 50) ? 'success' : 'warning';

		    $bar 		= 	'<div class="progress progress-xs dker progress-striped m-b-n m-t-sm">' .
                  			'	<div class="progress-bar bg-' . $barColor . '" data-toggle="tooltip" data-original-title="' . (($percent<1)?'<1':$percent) . '%" style="width: ' . $percent . '%"></div>' .
                			'</div>' . '<span class="hidden">' . (($percent<1)?'<1':$percent) . '%</span>';

			$table .= 	'<tr> <td class="bg-light lter"> '.$name.' </td>' .
						'	<td class="text-right" data-order="'.$uSold.'"> '.formatCurrentNumber($uSold).' </td>' .
						'	<td class="text-right" data-order="'.$cogs.'" data-format="money"> '.formatCurrentNumber($cogs).' </td>' .
						'	<td class="text-right" data-order="'.$tax.'" data-format="money"> ' . formatCurrentNumber($tax) .
						' 		<span class="hidden">'.$taxName.'</span>' .
						'	</td>' .
						'	<td class="text-right" data-order="'.$discount.'" data-format="money"> '.formatCurrentNumber($discount).' </td>' .
						'	<td class="text-right" data-order="'.$subtotal.'" data-format="money"> '.formatCurrentNumber($subtotal).' </td>' .
						'	<td class="text-right" data-order="'.$total.'" data-format="money"> '.formatCurrentNumber($total).' </td>' .
						'	<td class="text-right" data-order="'.$percent.'"> ' . $bar . ' </td> ' .
						'</tr>';

			$tUsold 	+= $uSold;
			$tTax 		+= $tax;
			$tDiscount 	+= $discount;
			$tSubTotal 	+= $subtotal;
			$tTotal 	+= $total;
		}
			

		$uSold 		= 0;
		$tax 		= 0;
		$discount	= 0;
		$subtotal	= 0;
		$total 		= 0;

		$barLabel 	= [];
		$barData 	= [];

		for($x=0;$x<count($itemsArray);$x++){
			$fields 	= $itemsArray[$x];
			
			$uSold 		+= $fields['usold'];
			$tax 		+= $fields['tax'];
			$discount	+= $fields['discount'];
			$subtotal	+= $fields['subtotal'];
			$total 		+= $fields['total'];
			$name		= $fields['name'];

			if($x < $maxItemsInGraph){
				//$barLabel[] = $name;
				//$barData[] 	= $fields['usold'];

				$name 		= ($name == 'None') ? 'Sin categoría' : $name;
      			$barData[] 	= ['title' => $name, 'total' => $fields['usold']];
			}
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
									'subtotal' 	=> $currency . formatCurrentNumber($subtotal),
									'total' 	=> $currency . formatCurrentNumber($total)
								];

	$jsonResult['chart'] 	= $barData;

	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));
}

echo reportsDayAndTitle([
							'title' 		=> '<div class="text-md text-right font-default">Ventas por</div> Marca',
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
        	<table class="table table1 col-xs-12 no-padder" id="tableBrands">
        		<?=placeHolderLoader('table')?>
        	</table>
        </div>
    </div>

<script>
$(function(){
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
						"footerSumCol" 	: [1,2,3,4,5,6],
						"currency" 		: "<?=CURRENCY?>",
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 		: <?=$offsetDetail?>,
						"limit" 		: <?=$limitDetail?>,
						"nolimit" 		: true,
						"tableName" 	: 'tableBrands',
						"fileTitle" 	: 'Ranking de Marcas',
						"ncmTools"		: {
											left 	: '',
											right 	: ''
										  },
						"colsFilter"	: {
											name 	: 'brands',
											menu 	:  [
															{"index":0,"name":"Nombre","visible":true},
															{"index":1,"name":"Unidades","visible":true},
															{"index":2,"name":"Costo","visible":false},
															{"index":3,"name":"<?=TAX_NAME?>","visible":false},
															{"index":4,"name":'Descuentos',"visible":false},
															{"index":5,"name":'Subtotal',"visible":false},
															{"index":6,"name":'Total',"visible":true},
															{"index":7,"name":'Porcentaje',"visible":true}
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

</body>
</html>
<?php
dai();
?>