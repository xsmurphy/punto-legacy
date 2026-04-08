<?php
include_once('includes/top_includes.php');

topHook();//error handler
allowUser('sales','view');
$MAX_DAYS_RANGE = 31;

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);
$summaryCache = [];
if(array_key_exists("ncmCache",$_SESSION)){
	$summaryCache = $_SESSION['ncmCache']['summary'][OUTLET_ID][$startDate . $endDate];
}

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

$roc = getROC(1);

$maxItemsInGraph 	= 20;
$itemsArray 		= [];
$itemsArrayDetail 	= [];
$isdata 			= false;
$limitDetail		= 100;
$offsetDetail		= 0;

$jsonResult 		= [];

if(validateHttp('action') == 'getSales'){
	//aqui sumo todo lo vendido y le resto todos los pagos hechos con gift cards
	//obtengo
	//total vendido (vendido + descuentos) - pagos con giftcards - pagos con credito - pagos con loyalty

	if(!empty($summaryCache) && array_key_exists('getSales',$summaryCache) && validity($summaryCache['getSales']) ){
      jsonDieResult($summaryCache['getSales']);
    }

	$grossSales 		= 0;
	$totalDiscounts 	= 0;
	$totalReturned 		= 0;
	$netSales 			= 0;
	$totalTax 			= 0;
	$paysArray 			= [];
	$totalPays 			= 0;
	$grossSalesB 		= 0;
	$totalDiscountsB 	= 0;
	$totalReturnedB		= 0;
	$netSalesB 			= 0;
	$totalTaxB 			= 0;
	$paysArrayB			= [];
	$totalPaysB			= 0;

	list($startDateBack,$endDateBack) = getPreviousPeriod($startDate,$endDate);

	//TOTAL SALES
	$sql 	= 	'SELECT 
				SUM(transactionUnitsSold) as usold, 
				COUNT(transactionDate) as count, 
				SUM(transactionDiscount) as discount, 
				SUM(transactionTax) as tax, 
				SUM(transactionTotal) as total
				FROM transaction USE INDEX(transactionDate,transactionType)
				WHERE transactionType IN(0,3) 
				AND transactionDate >= ?
				AND transactionDate <= ?' . $roc;


	if(!empty($_GET['debug'])){
		dai($sql . $startDate . ' ' . $endDate);
	}

	$totalSales		= ncmExecute($sql,[$startDate,$endDate],true);

	if($totalSales){
		$totalSalesB 	= ncmExecute($sql,[$startDateBack,$endDateBack],true);
		
		//TOTAL RETURNS
		$sql 	= 	"SELECT 
					SUM(transactionTotal) as returned
					FROM transaction USE INDEX(transactionDate,transactionType)
					WHERE transactionType IN(6) 
					AND transactionDate >= ?
					AND transactionDate <= ?" . $roc;

		$totalReturn	= ncmExecute($sql,[$startDate,$endDate],true);
		$totalReturnB 	= ncmExecute($sql,[$startDateBack,$endDateBack],true);

		//TOTAL PAYMENS METHODS
		$sql 	= 	'SELECT 
					transactionPaymentType as pays
					FROM transaction USE INDEX(transactionDate,transactionType)
					WHERE transactionType IN(0,3) 
					AND transactionDate >= ?
					AND transactionDate <= ?' . $roc;
		$totalPaysAmount = 0;
		// $totalPays	= ncmExecute($sql,[$startDate,$endDate],true,true);
		// $totalPaysB = ncmExecute($sql,[$startDateBack,$endDateBack],true,true);
		
		$pmnts    			= getSalesByPayment($startDate,$endDate,$roc);
		
		foreach($pmnts as $methd){
			$totalPaysAmount += (float)$methd['price'];
			$paysArray[] = ['name' => getPaymentMethodName($methd['type']), 'total' => formatCurrentNumber($methd['price'])];
		}

		$nonAddingToSales 	= getNonAddingToSales(['startDate'=>$startDate,'endDate'=>$endDate,'roc'=>$roc,'backThen'=>true]);

		$paymentsLess 		= $nonAddingToSales['total'];
		$paymentsLessB 		= $nonAddingToSales['totalB'];
		
		//ACTUAL
		$grossSales 		= $totalSales['total'];
		$totalDiscounts 	= $totalSales['discount'];
		$totalReturned 		= abs($totalReturn['returned'] ?? 0);
		$totalTax 			= ($totalSales['tax'] < 0) ? 0 : $totalSales['tax'];
		$netSales 			= (($grossSales - $totalDiscounts) - $totalReturned) - $paymentsLess;

		//ANTERIOR
		$grossSalesB 		= $totalSalesB['total'];
		$totalDiscountsB 	= $totalSalesB['discount'];
		$totalReturnedB 	= abs($totalReturnB['returned'] ?? 0);
		$totalTaxB 			= ($totalSalesB['tax'] < 0) ? 0 : $totalSalesB['tax'];
		$netSalesB 			= (($grossSalesB - $totalDiscountsB) - $totalReturnedB) - $paymentsLessB;
	}

	$jsonResult = [
					'grossSales' 		=> formatCurrentNumber($grossSales),
					'totalDiscounts'	=> formatCurrentNumber($totalDiscounts),
					'totalReturns'		=> formatCurrentNumber($totalReturned),
					'netSales' 			=> formatCurrentNumber($netSales),
					'totalTax' 			=> formatCurrentNumber($totalTax),
					'payments' 			=> $paysArray,
					'totalPayments'     => formatCurrentNumber($totalPaysAmount),
					'totalGiftcardUsed' => formatCurrentNumber($nonAddingToSales['totalGiftCards']),
					'creditPays' 		=> formatCurrentNumber($nonAddingToSales['total']),
					'grossSalesB' 		=> comparePeriodsArrowsPercent($grossSales,$grossSalesB,formatCurrentNumber($grossSalesB),false,true),
					'totalDiscountsB'	=> comparePeriodsArrowsPercent($totalDiscounts,$totalDiscountsB,formatCurrentNumber($totalDiscountsB),true,true),
					'totalReturnsB'		=> comparePeriodsArrowsPercent($totalReturned,$totalReturnedB,formatCurrentNumber($totalReturnedB),true,true),
					'netSalesB' 		=> comparePeriodsArrowsPercent($netSales,$netSalesB,formatCurrentNumber($netSalesB),false,true),
					'totalTaxB' 		=> comparePeriodsArrowsPercent($totalTax,$totalTaxB,formatCurrentNumber($totalTaxB),false,true),
					// 'totalPaymentsB'    => comparePeriodsArrowsPercent($totalPaysAmount,$totalPaysB,formatCurrentNumber($totalPaysB),false,true)
					];

	$summaryCache['getSales'] = $jsonResult;

	jsonDieResult($jsonResult);
}

if(validateHttp('action') == 'getTypeSales'){
	$cashSales 		= 0;
	$creditSales 	= 0;

	$sql 	= 	'SELECT 
				transactionId as id,
				SUM(transactionUnitsSold) as usold, 
				COUNT(transactionDate) as count, 
				SUM(transactionDiscount) as discount, 
				SUM(transactionTax) as tax, 
				SUM(transactionTotal) as total
				FROM transaction USE INDEX(transactionType,transactionDate)
				WHERE transactionType = 0 
				AND transactionDate >= ?
				AND transactionDate <= ?' . $roc;
	$cashSales	= ncmExecute($sql,[$startDate,$endDate],true);

	if($cashSales){
		$totalCash = $cashSales['total'] - $cashSales['discount'];
	}

	$sqlc 	= 	'SELECT 
				transactionId as id,
				SUM(transactionUnitsSold) as usold, 
				COUNT(transactionDate) as count, 
				SUM(transactionDiscount) as discount, 
				SUM(transactionTax) as tax, 
				SUM(transactionTotal) as total
				FROM transaction USE INDEX(transactionType,transactionDate)
				WHERE transactionType = 3 
				AND transactionDate >= ?
				AND transactionDate <= ?' . $roc;
	$creditSales	= ncmExecute($sqlc,[$startDate,$endDate],true);

	if($creditSales){
		$totalCredit = $creditSales['total'] - $creditSales['discount'];
	}

	$jsonResult = [
					'cashSales' 	=> formatCurrentNumber($totalCash),
					'creditSales' 	=> formatCurrentNumber($totalCredit),
					'totalSold' 	=> formatCurrentNumber($totalCredit + $totalCash)
					];

	header('Content-Type: application/json');
	dai(json_encode($jsonResult));
}

if(validateHttp('action') == 'getGiftcards'){
	//gift cards vendidas
	//gift cards usadas (sumo total de medio de pagos)
	//gift cards sin uso
	$jsonResult = [];
	$totalSold 	= 0;
	$totalCount = 0;
	$result 	= ncmExecute('SELECT 
								SUM(b.itemSoldTotal) as total,
								SUM(b.itemSoldUnits) as count
							FROM item a, itemSold b
							WHERE
								a.itemType = \'giftcard\'
							AND a.itemId = b.itemId
							AND a.companyId = ?
							AND b.itemSoldDate 
							BETWEEN ?
							AND ?', [COMPANY_ID,$startDate,$endDate],true);

	if($result){
		$totalSold		= formatCurrentNumber($result['total']);
		$totalCount 	= formatQty($result['count']);
	}

	$jsonResult = [
					'totalSold' 	=> $totalSold,
					'totalCount' 	=> $totalCount
					];

	header('Content-Type: application/json');
	dai(json_encode($jsonResult));
}

if(validateHttp('action') == 'getChartSales'){
	if(array_key_exists('getChartSales',($summaryCache ?? [])) && validity($summaryCache['getChartSales']) ){
      jsonDieResult($summaryCache['getChartSales']);
    }

	//CHARTS
	$startDateCompare 	= getDateTimeByPieces($startDate)['date'];//date('Y-m-d',strtotime($startDate));
	$endDateCompare 	= getDateTimeByPieces($endDate)['date'];//date('Y-m-d',strtotime($endDate));
	$hoursArray 		= ['0','1','2','3','4','5','6','7','8','9','10','11','12','13','14','15','16','17','18','19','20','21','22','23'];
	$barsLabel 	= [];
	$barsData 	= [];
	$unitsLabel = [];
	$unitsData 	= [];
	$hoursLabel = [];
	$hoursData 	= [];
	$glabels 	= [];
	$gGross 	= [];
	$gGrossBack = [];
	$chartBack 	= false;
	$chartExp 	= false;
	$totalSold 	= 0;
	$totalCount = 0;
	$annotations = [];

	list($startDateBack,$endDateBack) = getPreviousPeriod($startDate,$endDate);

	if($startDateCompare == $endDateCompare){ //si es el mismo dia

		$sql = "SELECT 
                  COUNT(transactionId) AS count,
					SUM(transactionUnitsSold) AS units,
					SUM(transactionTotal) AS total,
					SUM(transactionDiscount) AS discount,
					SUM(transactionTax) AS tax,
					HOUR(transactionDate) AS date
                  FROM transaction FORCE INDEX(transactionType,transactionDate)
                  WHERE transactionType IN (0,3)
                  AND transactionDate 
                  BETWEEN ?
                  AND ? 
                   " . $roc . "
                  GROUP BY date
                  ORDER BY date DESC";

		//gastos
		$sqlExp = 		"SELECT 
						COUNT(transactionId) AS count,
						SUM(transactionUnitsSold) AS units,
						SUM(transactionTotal) AS total,
						SUM(transactionDiscount) AS discount,
						SUM(transactionTax) AS tax,
						HOUR(transactionDate) AS date
					FROM transaction USE INDEX(transactionType,transactionDate)
					WHERE transactionType IN (1,4)
						AND transactionDate 
						BETWEEN ?
						AND ?
						" . $roc . "
					GROUP BY date
					ORDER BY date ASC";

		$calendar 			= $hoursArray;
		$isDay 				= true;
		$nodaycharts 		= 'hidden';
		$yesdaycharts 		= 'col-sm-12';
	}else{ //si NO es el mismo dia

		$sql 	= 	"SELECT transactionDate as date, 
						SUM(transactionUnitsSold) as units, 
						SUM(transactionDiscount) as discount, 
						SUM(transactionTotal) as total,
						transactionType as type
					FROM transaction USE INDEX(transactionType,transactionDate)
					WHERE transactionType IN (0,3,6)
					AND transactionDate 
					BETWEEN ?
					AND ? 
					" . $roc . "
					GROUP BY DATE(date)
					ORDER BY date ASC";


		$sqlExp	= 	"SELECT transactionDate as date,
						SUM(transactionUnitsSold) as units, 
						SUM(transactionDiscount) as discount, 
						SUM(transactionTotal) as total,
						transactionType as type
					FROM transaction USE INDEX(transactionType,transactionDate)
					WHERE transactionType IN (1,4)
					AND transactionDate 
					BETWEEN ?
					AND ? 
					" . $roc . "
					GROUP BY DATE(date)
					ORDER BY date ASC";

		$isDay 				= false;
		$nodaycharts 		= '';
		$yesdaycharts 		= '';
	}

	$chart 		= ncmExecute($sql,[$startDate,$endDate],false,true);

	if(!validateHttp('noBack')){
		$chartBack 	= ncmExecute($sql,[$startDateBack,$endDateBack],true,true);
	}

	if(validateHttp('expenses') && !$isdata){
		$chartExp 	= ncmExecute($sqlExp,[$startDate,$endDate],true,true);
	}	

	$tArray = [];
	if($chart){
		$tCount 	= 0;
		$tDiscount 	= 0;
		$tTax 		= 0;
		$tTotal		= 0;
		while (!$chart->EOF) {
			$field 			= $chart->fields;

			$date 			= date('Y-m-d',strtotime($field['date']));

			if($isDay){
				$date 		= $field['date'];
			}

			$count 			= $field['count'] ?? 0;
			$discount 		= $field['discount'];
			$tax 			= $field['tax'] ?? 0;
			$total 			= $field['total'];

			$tCount 		+= $count;
			$tDiscount 		+= $discount;
			$tTax 			+= $tax;
			$tTotal			+= $total - $discount;

			if(!empty($tArray[$date])){
				$tArray[$date]['count'] 	+= $count;
				$tArray[$date]['discount'] 	+= $discount;
				$tArray[$date]['tax'] 		+= $tax;
				$tArray[$date]['total'] 	+= $total;
			}else{
				$tArray[$date]['count'] 	= $count;
				$tArray[$date]['discount'] 	= $discount;
				$tArray[$date]['tax'] 		= $tax;
				$tArray[$date]['total'] 	= $total;
			}

			$chart->MoveNext();
		}
	}

	$tBackArray = [];
	$calendarB 	= [];
	if($chartBack){
		$tCountB 		= 0;
		$tDiscountB 	= 0;
		$tTaxB 			= 0;
		$tTotalB		= 0;
		$calendarB 		= dateRange($startDateBack, $endDateBack);
		while (!$chartBack->EOF) {
			$field 			= $chartBack->fields;

			$date 			= date('Y-m-d',strtotime($field['date']));
			if($isDay){
				$date 		= $field['date'];
			}

			$count 			= isset($field['count']) ? $field['count'] : 0;
			$discount 		= $field['discount'];
			$tax 			= isset($field['tax']) ? $field['tax'] : 0;
			$total 			= $field['total'] - $discount;

			$tCountB 		+= $count;
			$tDiscountB		+= $discount;
			$tTaxB 			+= $tax;
			$tTotalB		+= $total - $discount;

			if(isset($tBackArray[$date]) && $tBackArray[$date]){
				$tBackArray[$date]['count'] 	+= $count;
				$tBackArray[$date]['discount'] 	+= $discount;
				$tBackArray[$date]['tax'] 		+= $tax;
				$tBackArray[$date]['total'] 	+= $total;
			}else{
				$tBackArray[$date]['count'] 	= $count;
				$tBackArray[$date]['discount'] 	= $discount;
				$tBackArray[$date]['tax'] 		= $tax;
				$tBackArray[$date]['total'] 	= $total;
			}

			$chartBack->MoveNext();
		}
	}

	$expArray = [];
	if($chartExp){
		$tCountE 		= 0;
		$tDiscountE 	= 0;
		$tTaxE 			= 0;
		$tTotalE		= 0;
		while (!$chartExp->EOF) {
			$field 			= $chartExp->fields;

			$date 			= date('Y-m-d',strtotime($field['date']));
			if($isDay){
				$date 		= $field['date'];
			}

			$count 			= isset($field['count']) ? $field['count'] : 0;
			$discount 		= $field['discount'];
			$tax 			= isset($field['tax']) ? $field['tax'] : 0;
			$total 			= $field['total'];

			$tCountE 		+= $count;
			$tDiscountE 	+= $discount;
			$tTaxE 			+= $tax;
			$tTotalE		+= $total - $discount;

			if(isset($expArray[$date]) && $expArray[$date]){
				$expArray[$date]['count'] 		+= $count;
				$expArray[$date]['discount'] 	+= $discount;
				$expArray[$date]['tax'] 		+= $tax;
				$expArray[$date]['total'] 		+= $total;
			}else{
				$expArray[$date]['count'] 		= $count;
				$expArray[$date]['discount'] 	= $discount;
				$expArray[$date]['tax'] 		= $tax;
				$expArray[$date]['total'] 		= $total;
			}

			$chartExp->MoveNext();
		}
	}

	$byweek 	= [];
	$ubyweek 	= [];
	$uByHour 	= [];

	$z 			= 0;

	while($z < counts($calendar)){
		$current 		= $calendar[$z];
		$currentB 		= $isDay ? $current : (array_key_exists($z,$calendarB) && $calendarB[$z]);

		$discount 		= 0;
		$total 			= 0;
		$units 			= 0;
		$dayTotal 		= 0;
		$totalBack 		= 0;
		$totalExp 		= 0;
		if(array_key_exists($current,$tArray)){
			$discount 		= ($tArray[$current]['discount'] 	? (float) $tArray[$current]['discount'] 	: 0);
			$total 			= ($tArray[$current]['total'] 		? (float) $tArray[$current]['total'] 		: 0);
			$units 			= (($tArray[$current]['units'] ?? false) 		? (float) $tArray[$current]['units'] 		: 0);
		}
		if(!empty($tBackArray[$currentB])){
			$totalBack 		= ($tBackArray[$currentB]['total'] 	? (float) $tBackArray[$currentB]['total'] 	: 0);
		}
		if(array_key_exists($current,$expArray)){
			$totalExp 		= ($expArray[$current]['total']		? (float) $expArray[$current]['total']		: 0);
		}

		$dayTotal 		= $total - $discount;
		$totalSold 		+= $dayTotal;
		$totalCount++;

		$gGross[] 		= $dayTotal;
		$gGrossBack[] 	= $totalBack;

		if($isDay){ //si es el mismo dia
			$t 				= getDateTimeByPieces($startDate);
			$tb 			= getDateTimeByPieces($startDateBack);
			
			if(!validateHttp('noBack')){
				$glabels[] 		= $current . 'h del ' . niceDate($startDate,false,false,true) . ' vs ' . niceDate($startDateBack,false,false,true);
			}else{
				$glabels[] 		= $current . 'h del ' . niceDate($startDate,false,false,true);
			}

			$gGrossExp[] 	= 0;
			$gMargin[] 		= 0;

			$z++;
		}else{
			$t 				= getDateTimeByPieces($current);
			$tb 			= getDateTimeByPieces($current);

			if(array_key_exists($t['w'],$byweek)){
				$byweek[$t['w']]  += $dayTotal;
			}else{
				$byweek[$t['w']] = $dayTotal;
			}
			if(array_key_exists($t['w'],$ubyweek)){
				$ubyweek[$t['w']] += $units;
			}else{
				$ubyweek[$t['w']] = $units;
			}
			if(array_key_exists($t['w'],$uByHour)){
				$uByHour[$t['h']] += $dayTotal;
			}else{
				$uByHour[$t['h']] = $dayTotal;
			}

			if(!validateHttp('noBack')){
				$glabels[] 		= niceDate($current,false,false,true) . ' vs ' . niceDate($currentB,false,false,true);
			}else{
				$glabels[] 		= niceDate($current,false,false,true);
			}

			$tMargin 		= $dayTotal - $totalExp;

			$gGrossExp[] 	= $totalExp;//-1 * abs($totalExp);
			$gMargin[] 		= ($tMargin > 0) ? $tMargin : 0;

			$z++;
		}
	}

	if(!$isDay){ 
		@ksort($byweek);
		@ksort($ubyweek);

		$days 		= ['1' => 'Lunes', '2' => 'Martes', '3' => 'Miércoles', '4' => 'Jueves', '5' => 'Viernes', '6' => 'Sábado', '7' => 'Domingo'];

		$week 		= $byweek + array_fill(1, 7, 0);
		$uweek 		= $ubyweek + array_fill(1, 7, 0);
		$unitsdona 	= '';
		$bars 	 	= '';

		for($i = 1; $i < counts($days) + 1; $i++){
			$barsLabel[] 	= $days[$i];
			$barsData[] 	= $week[$i];
			$unitsLabel[] 	= $days[$i];
			$unitsData[] 	= $uweek[$i];
		}
	}
	
	if($totalSold){
		$average = divider($totalSold, $totalCount,true);
		$annotations[]    = ['value' => $average, 'orientation' => 'horizontal', 'text' => 'Promedio ' . formatCurrentNumber($average), 'color' => '#1ab667','position' => 'left'];
	}

	$jsonResult['chart'] = [
							'sales' => [
										'labels' 	=> $glabels,
										'gross' 	=> $gGross,
										'grossB'	=> $gGrossBack,
										'grossE'	=> $gGrossExp,
										'margin'	=> $gMargin
										],
							'days' => [
										'labels' 	=> $barsLabel,
										'data' 		=> $barsData
										],
							'annotations' 			=> $annotations,
							'noDayShow' 			=> $nodaycharts

							];

	$out = $jsonResult;

	$summaryCache['getChartSales'] = $out;

	jsonDieResult($out);
}

if(validateHttp('action') == 'topHours'){

	$result = ncmExecute("SELECT 
                  HOUR(transactionDate) as hora,
                  COUNT(transactionId) as total,
                  SUM(transactionUnitsSold) as units
                  FROM transaction FORCE INDEX(transactionType,transactionDate)
                  WHERE transactionType IN (0,3)
                  AND transactionDate BETWEEN ? AND ? " . $roc . "
                  GROUP BY hora
                  ORDER BY hora", [$startDate, $endDate], true, true);

    $hourGroup = [];

    if ($result) {
        while (!$result->EOF) {
			
            $hour = $result->fields['hora'];

            $hourGroup[$hour] = round($result->fields['total'], 2);

            $result->MoveNext();
        }
    }	
	
	// Crear un array con todas las horas del día
    $hoursArray = array_map(function ($hour) {
        return $hour < 10 ? '0' . $hour . ' h' : $hour . ' h';
    }, range(0, 23));

    $label = array_values($hoursArray);
    $total = array_map(function ($hour) use ($hourGroup) {
        return array_key_exists($hour, $hourGroup) ? (float) $hourGroup[$hour] : 0;
    }, array_keys($hoursArray));
	

	$jsonResult = 	[
						'hour' 	=> $label,
						'total'	=> $total
					];

	$dashCache['topHours'] = $jsonResult;
    jsonDieResult($jsonResult);
}

if(validateHttp('action') == 'salesListByDay'){
	$limits 	= getTableLimits($limitDetail,$offsetDetail);
	$fullTable 	= [];

	$sql = "SELECT transactionDate as date, 
				SUM(transactionUnitsSold) as usold, 
				COUNT(transactionDate) as count, 
				SUM(transactionDiscount) as discount, 
				SUM(transactionTax) as tax, 
				SUM(transactionTotal) as total 
			FROM transaction 
			WHERE transactionType IN (0,3,6)
			AND transactionDate 
			BETWEEN ?
			AND ? 
			   " . $roc . "
		  	GROUP BY DATE(transactionDate)
		  	ORDER BY transactionDate DESC" . $limits;


	$result 	= ncmExecute($sql,[$startDate,$endDate],$cache,true);
	$table 		= '';

	if($result){
		$head 	= 	'	<thead class="text-u-c">'.
					'		<tr>'.
					'			<th>Fecha</th>'.
					'			<th class="text-center">Nro. de Ventas</th>'.
					'			<th class="text-center">Descuentos</th>'.
					'			<th class="text-center">' . TAX_NAME . '</th>'.
					'			<th class="text-center">Gravado</th>'.
					'			<th class="text-center">Total</th>'.
					'		</tr>'.
					'	</thead>'.
					'<tbody>';

		while (!$result->EOF) {
			
			list($from,$to) = dateToStartAndEnd($fechUgly);
			$internals 		= lessInternalTotals($roc,$from,$to);
			
			$fechUgly 		= $result->fields['date'];
			$fecha 			= niceDate($fechUgly);
			$usold 			= $result->fields['usold'] 		- $internals['qty'];
			$sales 			= $result->fields['count'] 		- $internals['count'];
			$discount 		= $result->fields['discount'] 	- $internals['discount'];
			$tax 			= $result->fields['tax'] 		- $internals['tax'];
			$total 			= ($result->fields['total'] < 1) ? 0 : $result->fields['total'] - $internals['total'];

			$tax 			= ($tax < 0) ? 0 : $tax;

			if($total < 0){
				$total 		= $total;
			}else{
				$total 		= $total - $discount;
				$total 		= $total;
			}
			
			$subtotal 		= $total - $tax;
			$subtotal 		= ($subtotal < 0) ? 0 : $subtotal;

			$table .= '<tr class="clickrow">' . 
		              	' <td data-order="'.$fechUgly.'"> '.$fecha.' </td>' .
		          		' <td class="text-right" data-order="'.$sales.'"> '.formatQty($sales).' </td>' .
		          		' <td class="text-right bg-light lter" data-order="'.$discount.'" data-format="money"> '.formatCurrentNumber($discount).' </td>' .
		          		' <td class="text-right bg-light lter" data-order="'.$tax.'" data-format="money"> '.formatCurrentNumber($tax).' </td>' .
		          		' <td class="text-right bg-light lter" data-order="'.$subtotal.'" data-format="money"> '.formatCurrentNumber($subtotal).' </td>' .
		          		' <td class="text-right bg-light lter" data-order="'.$total.'" data-format="money"> '.formatCurrentNumber($total).' </td>' .
		              '</tr>';

		    if(validateHttp('part')){
	        	$table .= '[@]';
	        }
			
			$result->MoveNext();
		}

		$foot .= '</tbody>'.
					  '  <tfoot>'.
				      '   <tr>'.
				      '     <th class="">TOTALES:</th>'.
					  '		<th class="text-right"></th>'.
					  '		<th class="text-right"></th>'.
					  '		<th class="text-right"></th>'.
				      '     <th class="text-right"></th>'.
				      '     <th class="text-right"></th>'.
				      '   </tr>'.
				      '  </tfoot>';

		if(validateHttp('part')){
			dai($table);
		}else{
			$fullTable = $head . $table . $foot;
		}
	}

	$jsonResult['table'] 		= $fullTable;

	header('Content-Type: application/json');
	dai(json_encode($jsonResult));
}
?>
	<?=menuReports('',false);?>

  	<?php
    echo reportsDayAndTitle([
    							'title' 		=> '<div class="text-md text-right font-default">Resumen de</div> Ingresos',
    							'maxDays' 		=> $MAX_DAYS_RANGE,
    							'chartId' 		=> 'summaryChart'
    						]);
    ?>

  	<div class="col-xs-12 m-b-lg no-padder text-center">

  		<section class="col-md-3 col-sm-6 col-xs-12">
            <div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalSubtotal"><?=placeHolderLoader()?></span></div>
				Total <span class="text-xs text-muted">(Bruto)</span> <span id="globalSubtotalB">...</span>
			</div>
        </section>

        <section class="col-md-3 col-sm-6 col-xs-12">
            <div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalCogs"><?=placeHolderLoader()?></span></div>
				Devoluciones <span id="globalCogsB">...</span>
			</div>
        </section>    

        <section class="col-md-3 col-sm-6 col-xs-12">
            <div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalDiscount"><?=placeHolderLoader()?></span></div>
				Descuentos <span id="globalDiscountB">...</span>
			</div>
        </section>

  		<section class="col-md-3 col-sm-6 col-xs-12">
            <div class="b-b text-center wrapper-md">
				<div class="h1 m-t m-b-xs total font-bold"><span class="text-muted text-lg"><?=CURRENCY?></span> <span id="globalUtility"><?=placeHolderLoader()?></span></div>
				Total  <span class="text-xs text-muted">(Neto)</span> <span id="globalUtilityB">...</span>
			</div>
        </section>
  	</div>

  	<div class="row m-t noDayHolder">
		<div class="col-sm-5 col-xs-12 text-center m-b">
			<h4 class="font-bold">Día de la semana</h4>
			<canvas id="days" height="200" style="width:100%; max-height:200px;"></canvas>
		</div>

		<div class="col-sm-7 col-xs-12 text-center m-b">
			<h4 class="font-bold">Ventas por Hora</h4>
			<canvas id="hours" height="200" style="width:100%; max-height:200px;"></canvas>
		</div>
	</div>

  	<div class="col-xs-12 no-padder m-t m-b-lg push-chat-down">

  		<section class="col-xs-12 no-padder" id="reportsTablesAndTabs">
	        <ul class="nav nav-tabs padder hidden-print">
	            <li class="active">
	                <a href="#tab1" data-toggle="tab">Resumen</a>
	            </li>
	            <li id="byDayTabLink">
	                <a href="#tab2" data-toggle="tab">Por Día</a>
	            </li>
	        </ul>
	        <section class="panel r-24x">
	            <div class="panel-body table-responsive">
	                <div class="tab-content m-b-lg">
	                    <div class="tab-pane overflow-auto active" id="tab1">
	                    	<div class="row">
						  		<div class="col-sm-6">
							        <section class="panel r-24x bg-white wrapper b">
							        	<div class="col-xs-12 no-padder m-b">
							        		<span class="text-md font-bold text-u-c pull-left">Ventas</span>
							        		<a href="#" data-table="salesTable" data-name="ventas" class="btn r-3x font-bold text-u-c b b-light pull-right export hidden-print">Excel</a>
							        	</div>
							        	<table class="table" id="salesTable">
							            	<?=placeHolderLoader('table-sm')?>
							            </table>
							        </section>
						        </div>

						        <div class="col-sm-6">
							        <section class="panel r-24x bg-white wrapper b">
							        	<div class="col-xs-12 no-padder m-b">
							        		<span class="text-md font-bold text-u-c pull-left">Medios de Pago</span>
							        		<a href="#" data-table="paymentsMethodsTable" data-name="medios_de_pago" class="btn r-3x font-bold text-u-c b b-light pull-right export hidden-print">Excel</a>
							        	</div>
							        	<table class="table" id="paymentsMethodsTable">
							            	<?=placeHolderLoader('table-sm')?>
							            </table>
							        </section>
						        </div>
						    </div>

						    <div class="row">
						    	<div class="col-sm-6">
							        <section class="panel r-24x bg-white wrapper b">
							        	<div class="col-xs-12 no-padder m-b">
							        		<span class="text-md font-bold text-u-c pull-left">Tipos</span>
							        		<a href="#" data-table="typeSalesTable" data-name="tipos" class="btn r-3x font-bold text-u-c b b-light pull-right export hidden-print">Excel</a>
							        	</div>
							        	<table class="table" id="typeSalesTable">
							            	<?=placeHolderLoader('table-sm')?>
							            </table>
							        </section>
						        </div>

						        <div class="col-sm-6">
							        <section class="panel r-24x bg-white wrapper b">
							        	<div class="col-xs-12 no-padder m-b">
							        		<span class="text-md font-bold text-u-c pull-left">Gift Cards</span>
							        		<a href="#" data-table="giftcardsTabe" data-name="gift_cards" class="btn r-3x font-bold text-u-c b b-light pull-right export hidden-print">Excel</a>
							        	</div>
							        	<table class="table" id="giftcardsTabe">
							            	<?=placeHolderLoader('table-sm')?>
							            </table>
							        </section>
						        </div>
						    </div>
	                    </div>

	                    <div class="tab-pane overflow-auto" id="tab2">
	                    	<div id="byDayTable">
                            	<table class="table table1 table-hover col-xs-12 no-padder" id="tableTransactions">
                            		<?=placeHolderLoader('table')?>
                            	</table>
                            </div>
	                    </div>
	                </div>
	            </div>
	        </section>
	    </section>
	</div>


<script>
$(document).ready(function(){

	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>",false,true);
	var baseUrl = '<?='/' . basename(__FILE__,'.php');?>';

	var xhr = ncmHelpers.load({
			url 		: baseUrl + "?action=getSales",
			httpType 	: 'GET',
			hideLoader 	: true,
			type 		: 'json',
			success 	: function(result){

				$('#globalSubtotal').html(result.grossSales);
				$('#globalSubtotalB').html(result.grossSalesB);

				$('#globalCogs').html(result.totalReturns);
				$('#globalCogsB').html(result.totalReturnsB);

				$('#globalDiscount').html(result.totalDiscounts);
				$('#globalDiscountB').html(result.totalDiscountsB);

				$('#globalUtility').html(result.netSales);
				$('#globalUtilityB').html(result.netSalesB);

				//ventas
				var table 		= 	'<tr class="bg-light lter">' + 
									'	<td class="font-bold">' +
									' 		Ventas Brutas' +	
									'	</td>' +
									'	<td class="text-right font-bold">' +
										result.grossSales +
									'	</td>' +
									'</tr>' +
									'<tr>' + 
									'	<td class="">' +
									' 		<span class="text-u-l pointer" data-toggle="tooltip" title="Pagos realizados con créditos de la empresa, Gift Cards, Crédito Interno o Puntos Loyalty">Pagos con créditos</span>' +	
									'	</td>' +
									'	<td class="text-right">' +
										'-' + result.creditPays +
									'	</td>' +
									'</tr>' +
									'<tr>' + 
									'	<td class="">' +
									' 		Devoluciones' +	
									'	</td>' +
									'	<td class="text-right">' +
										'-' + result.totalReturns +
									'	</td>' +
									'</tr>'+
									'<tr>' + 
									'	<td class="">' +
									' 		Descuentos' +	
									'	</td>' +
									'	<td class="text-right">' +
										'-' + result.totalDiscounts +
									'	</td>' +
									'</tr>' +
									'<tr class="bg-light lter">' + 
									'	<td class="font-bold">' +
									' 		Ventas Netas' +	
									'	</td>' +
									'	<td class="text-right font-bold">' +
										result.netSales +
									'	</td>' +
									'</tr>' +
									'<tr>' + 
									'	<td class="">' +
									' 		<?=TAX_NAME?>' +	
									'	</td>' +
									'	<td class="text-right">' +
										result.totalTax +
									'	</td>' +
									'</tr>';

				$('#salesTable').html(table);

				//m de pagos
				if(result.payments){
					var table = '';
					$.each(result.payments,function(i,k){
						table += 	'<tr>' + 
									'	<td>' +
									k.name +
									'	</td>' +
									'	<td class="text-right">' +
									k.total +
									'	</td>' +
									'</tr>';
					});

					table += '<tr class="font-bold text-u-c"><td>Total</td><td class="text-right">' + result.totalPayments + '</td></tr>';

					$('#paymentsMethodsTable').html(table);
				}

				ncmHelpers.load({
					url 		: baseUrl + '?action=getGiftcards',
					httpType 	: 'GET',
					hideLoader 	: true,
					type 		: 'json',
					warnTimeout : false,
					success 	: function(resultg){
						//$.get(baseUrl + '?action=getGiftcards',function(resultg){
							var table 		= 	'<tr>' + 
												'	<td class="font-bold text-u-c">' +
												' 		Vendido' +	
												'	</td>' +
												'	<td class="text-right font-bold">' +
													resultg.totalSold +
												'	</td>' +
												'</tr>'+
												'<tr>' + 
												'	<td>' +
												' 		Cantidad' +	
												'	</td>' +
												'	<td class="text-right">' +
													resultg.totalCount +
												'	</td>' +
												'</tr>' +
												'<tr>' + 
												'	<td>' +
												' 		Canjeado' +	
												'	</td>' +
												'	<td class="text-right">' +
													result.totalGiftcardUsed +
												'	</td>' +
												'</tr>';

							$('#giftcardsTabe').html(table);
						}
				});
				
				$('[data-toggle="tooltip"]').tooltip();
			}
	});

	window.xhrs.push(xhr);

	var xhr = ncmHelpers.load({
		url 		: baseUrl + "?action=getTypeSales",
		httpType 	: 'GET',
		hideLoader 	: true,
		type 		: 'json',
		warnTimeout : false,
		success 	: function(result){
			var table 		= 	'<tr>' + 
								'	<td>' +
								' 		Ventas al Contado' +	
								'	</td>' +
								'	<td class="text-right">' +
									result.cashSales +
								'	</td>' +
								'</tr>'+
								'<tr>' + 
								'	<td>' +
								' 		Ventas a Crédito' +	
								'	</td>' +
								'	<td class="text-right">' +
									result.creditSales +
								'	</td>' +
								'</tr>' +
								'<tr>' + 
								'	<td class="font-bold text-u-c">' +
								' 		Total Bruto' +	
								'	</td>' +
								'	<td class="text-right font-bold">' +
									result.totalSold +
								'	</td>' +
								'</tr>';

			$('#typeSalesTable').html(table);
		}
	});

	window.xhrs.push(xhr);

	var xhr = ncmHelpers.load({
		url 		: baseUrl + "?action=getChartSales&expenses=1",
		httpType 	: 'GET',
		hideLoader 	: true,
		type 		: 'json',
		warnTimeout : false,
		success 	: function(result){
			if(result.chart.sales.gross.length){
				$('#summaryChart').removeClass('hidden');
				$('#loadingChart').addClass('hidden');
				drawChart(result);
			}else{
				$('#summaryChart').addClass('hidden');
			}

			$('.noDayHolder').addClass(result.chart.noDayShow);
		}
	});

	window.xhrs.push(xhr);

	var xhr = ncmHelpers.load({
		url 		: baseUrl + '?action=topHours',
		httpType 	: 'GET',
		hideLoader 	: true,
		type 		: 'json',
		warnTimeout : false,
		success 	: function(result){
			if(result.total.length){
				chartByHours(result);
			}else{
				$('#hours').addClass('hidden');
			}
		}
	});

	window.xhrs.push(xhr);

	var tableViewed = false;

	$('#byDayTabLink').on('shown.bs.tab', function (e) {
		if(!tableViewed){
			var url       = baseUrl + "?action=salesListByDay";
		    var rawUrl    = url;

		    var xhr = ncmHelpers.load({
				url 		: url,
				httpType 	: 'GET',
				hideLoader 	: true,
				type 		: 'json',
				warnTimeout : false,
				success 	: function(result){

					tableViewed = true;

					var info1 = {
								"container" 	: "#byDayTable",
								"url" 			: url,
								"rawUrl"      	: rawUrl,
								"iniData" 		: result.table,
								"table" 		: ".table1",
								"sort" 			: 0,
								"footerSumCol" 	: [1,2,3,4,5],
								"currency" 		: "<?=CURRENCY?>",
								"decimal" 		: decimal,
								"thousand" 		: thousandSeparator,
								"offset" 		: <?=$offsetDetail?>,
								"limit" 		: <?=$limitDetail?>,
								"noMoreBtn" 	: true,
								"tableName" 	: 'tableTransactions',
								"fileTitle" 	: 'Resumen Por Día',
								"ncmTools"    	: {
						                          left  : '',
						                          right   : ''
						                          }
					};

					ncmDataTables(info1);
				}
			});

			window.xhrs.push(xhr);

		}
	});

	ncmHelpers.onClickWrap('.export',function(event,tis){
		var table 	= tis.data('table');
		var name 	= tis.data('name');
		table2Xlsx(table,name);
	});

});

var drawChart = function(result){
	var charter = result.chart;

	Chart.defaults.global.legend.display 		= true;
	Chart.defaults.global.responsive 			= true;
	Chart.defaults.global.maintainAspectRatio 	= false;

	var myChart 		= $('#summaryChart')[0].getContext("2d");
	var gradientStroke 	= myChart.createLinearGradient(1600, 0, 0, 0);
	gradientStroke.addColorStop(0, "#4cb6cb");
	gradientStroke.addColorStop(0.5, "#54cfc7");
	gradientStroke.addColorStop(1, "#54cfc7");

	var annots    = charter.annotations;
    var recAnnots = [];

    if(ncmHelpers.validity(annots)){
      recAnnots = annots.map(function(val, index) {
        var id        = 'vline' + index;
        var mode      = 'vertical';
        var scaleId   = "x-axis-0";
        var position  = iftn(val.position,'center');

        if(val.orientation == 'horizontal'){
          id        = 'hline' + index;
          mode      = 'horizontal';
          scaleId   = "y-axis-0";
        }

        var value = val.value;

        return {
          type      : "line",
          id        : id,
          mode      : mode,
          scaleID   : scaleId,
          value     : value.toFixed(2),
          borderColor: val.color,
          borderWidth: 2,
          borderDash : [2, 7],
          borderDashOffset : 5,
          label     : {
            backgroundColor: 'rgba(77,93,110,0.6)',
            enabled: true,
            position: position,
            content: val.text,
            font: {
	            size: 7
	        }
          }
        };
      });
    }


    chartBarStackedGraphOptions.annotation = {
                                              drawTime    : "afterDatasetsDraw",
                                              annotations : recAnnots
                                            };

	var data = {
	    labels 	: charter.sales.labels,
	    datasets: [
	    	{
                label                     : "Margen",
                data                      : charter.sales.margin,
                type                      : 'line',
                borderColor               : '#FF9469',

                pointColor                : '#FF9469',
                pointHoverRadius          : 8,
                pointHoverBorderColor     : "#fff",
                pointHoverBackgroundColor : '#FF9469',
                pointBorderColor          : '#FF9469',
                pointBackgroundColor      : '#FF9469',
                pointRadius               : 3,
                pointHoverBorderWidth     : 3,
                pointBorderWidth          : 1,
                pointHitRadius            : 20,
                borderWidth               : 3,
                fill                      : false
            },
	        {
	        	
	            label 					  	: "Ingreso Anterior",
	            data 						: charter.sales.grossB,
	            type                      	: 'line',
	            borderColor 				: chartSecondColor,

	            pointColor 					: chartSecondColor,
	            pointHoverRadius 			: 8,
	            pointHoverBorderColor 		: "#fff",
	            pointHoverBackgroundColor 	: chartSecondColor,
	            pointBorderColor 			: chartSecondColor,
	            pointBackgroundColor 		: chartSecondColor,
	            pointRadius 				: 3,
	            pointHoverBorderWidth 		: 3,
	            pointBorderWidth 			: 3,
	            pointHitRadius 				: 20,
	            borderDash 					: [10,5],
	            borderWidth 				: 3,
	            fill 						: false
	        },
	        {
	        	type 						: 'bar',
	            label 						: "Ingreso Actual",
	            backgroundColor 			: gradientStroke,
	            data 						: charter.sales.gross
	        },
	        {
	        	type 						: 'bar',
	            label 						: "Egresos",
	            backgroundColor 			: chartSecondColor,
	            data 						: charter.sales.grossE
	        }
	    ]
	};

	chartBarStackedGraphOptions.scales.xAxes[0].stacked = false;
    chartBarStackedGraphOptions.scales.yAxes[0].stacked = false;

	var chart = new Chart(myChart, { 
		type        : 'bar',
	    data 		: data,
	    animation 	: true,
	    options 	: chartBarStackedGraphOptions 
	});

	
	chart.getDatasetMeta(3).hidden = true;
	chart.update(); 

	chartBarStackedGraphOptions.annotation = {};
	

	var days 			= $('#days')[0].getContext("2d");
	var gradientStroke 	= days.createLinearGradient(300, 0, 100, 0);
	gradientStroke.addColorStop(0, "#4cb6cb");
	gradientStroke.addColorStop(1, "#54cfc7");

	Chart.defaults.global.responsive 			= true;
	Chart.defaults.global.maintainAspectRatio 	= false;
	Chart.defaults.global.legend.display       	= false;

	var dataD = {
	    labels: charter.days.labels, 
	    datasets: [
	        {
	        	label: "Total <?=CURRENCY?>",
	            data: charter.days.data,
	            backgroundColor: gradientStroke
	        }]
	};

	chartBarStackedGraphOptions.scales.xAxes[0].display = true;
	
	var methods = new Chart(days, {
	    type      : 'bar',
	    data      : dataD,
	    animation : true,
	    options   : chartBarStackedGraphOptions
	});
	
	chartBarStackedGraphOptions.scales.xAxes[0].display = false;

};

var chartByHours = function(result){

	Chart.defaults.global.responsive 			= true;
	Chart.defaults.global.maintainAspectRatio 	= false;
	Chart.defaults.global.legend.display       	= false;

	var hoursChart 		=$('#hours')[0].getContext("2d");
	var gradientStroke 	= hoursChart.createLinearGradient(300, 0, 100, 0);
	gradientStroke.addColorStop(0, "#4cb6cb");
	gradientStroke.addColorStop(1, "#54cfc7");

	var dataH = {
	    labels 		: result.hour,
	    datasets 	: [
	    				{
			                label                     : "Ventas",
			                data                      : result.total,
			                type                      : 'line',
			                borderColor               : '#FF9469',

			                pointColor                : '#FF9469',
			                pointHoverRadius          : 8,
			                pointHoverBorderColor     : "#fff",
			                pointHoverBackgroundColor : '#FF9469',
			                pointBorderColor          : '#FF9469',
			                pointBackgroundColor      : '#FF9469',
			                pointRadius               : 3,
			                pointHoverBorderWidth     : 3,
			                pointBorderWidth          : 3,
			                pointHitRadius            : 20,
			                borderWidth               : 5,
			                fill                      : false
			            }
	    			]
	};

	chartLineGraphOptions.scales.xAxes[0].display = true;

	var methods = new Chart(hoursChart, {
	    type 		: 'line',
	    data 		: dataH,
	    animation 	: true,
	    options   	: chartLineGraphOptions
	 }); 

	chartLineGraphOptions.scales.xAxes[0].display = false;
};


</script>

</body>
</html>
<?php

include_once('includes/compression_end.php');
dai();

?>