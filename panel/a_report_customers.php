<?php
include_once('includes/top_includes.php');

topHook();
allowUser('sales','view');

$MAX_DAYS_RANGE = 31;

if(ENCOM_ADM){
	$MAX_DAYS_RANGE = 31 * 12;
}

$baseUrl = '/' . basename(__FILE__,'.php');
list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

$roc 						= getROC(1);
$limitDetail		= 100;
$offsetDetail		= 0;

if(validateHttp('action') == 'generalTable'){

	$limits 	= getTableLimits($limitDetail,$offsetDetail);
	$table 		= '';

	$sql 		= "SELECT 
							customerId as id,
							SUM(transactionUnitsSold) as usold, 
							SUM(transactionTotal) as total, 
							SUM(transactionDiscount) as discount,
							COUNT(transactionId) as count,
							GROUP_CONCAT(tags) as tags
						FROM
							transaction 
						WHERE 
							transactionType IN(0,3)
						AND transactionDate 
						BETWEEN ? AND ? 
						" . $roc . "
						GROUP BY id
						ORDER BY total DESC";
	$result 	= ncmExecute($sql . $limits,[$startDate,$endDate],true,true);

	$head = '<thead class="text-u-c">'.
				'		<tr>'.
				'			<th>Razón Social</th>'.
				'			<th>'.TIN_NAME.'</th>'.
				'			<th>Nombre y Apellido</th>'.
				'			<th>Doc. de Identidad</th>'.
				'			<th>Cumpleaños</th>'.
				'			<th>Email</th>'.
				'			<th>Teléfono</th>'.
				'			<th>Teléfono 2</th>'.
				'			<th>Dirección</th>'.
				'			<th>Localidad</th>'.
				'			<th>Ciudad</th>'.
				'			<th>Etiquetas</th>'.
				'			<th class="text-center">Loyalty</th>'.
				'			<th class="text-center">No. de Compras</th>'.
				'			<th class="text-center">Gasto Promedio</th>'.
				'			<th class="text-center hidden-xs">Unidades Adquiridas</th>'.
				'			<th class="text-center hidden-xs">Descuentos Obtenidos</th>'.
				'			<th class="text-center hidden-xs">Subtotal</th>'.
				'			<th class="text-center">Total</th>'.
				'		</tr>'.
				'	</thead>'.
				'<tbody>';

	if($result){
		if(!validateHttp('part')){
			$resultSum 	= ncmExecute($sql,[$startDate,$endDate],true,true);
		}

		$cutIn = [];
		$label 		= '';
		$data 		= '';

		$tUsold 	= 0;
		$tDiscount 	= 0;
		$tSubTotal 	= 0;
		$tTotal 	= 0;
		$tCount 	= 0;
		$barLabel 	= [];
		$barData 	= [];
		$bars 		= '';
		$maxInGraph = 15;
		$i 			= 0;
		
		while (!$result->EOF) {
			$fields = $result->fields;
			if($fields['id'] && $fields['id'] > 0){
				$cId 				= $fields['id'];
				$uSold 			= $fields['usold'];
				$discount		= $fields['discount'];
				$subtotal		= $fields['total'];
				$total			= ($fields['total']-$discount);
				$compras 		= $fields['count'];

				$tagsAr 		= str_replace(['[',']'],['',''],$fields['tags']); //json_decode($fields['tags'],true);
				$tagsAr 		= array_unique(explodes(',', $tagsAr));
				$tags 		  = printOutTags($tagsAr,'bg-info');

				//$id				= $allCustomersArray[$cId]['contactId'];
				$customer 	= getContactData($cId,'uid',true);
				$name				= $customer['name'];
				$fullname   = $customer['secondName'];
				$ruc 				= $customer['ruc'];
				$ci 				= $customer['ci'];
				$phone 			= $customer['phone'];
				$phone2			= $customer['phone2'];
				$address		= $customer['address'];
				$address2		= $customer['address2'];
				$location		= $customer['location'];
				$loyalty		= $customer['loyalty'];
				$average 		= $total / $compras;

				$bday				= $customer['bday'] ? date('d-m-Y',strtotime($customer['bday'])) : '';
				$city				= $customer['city'];
				$email 			= $customer['email'];

				$arrData 		= [$ruc,$fullname,$phone,$email];
				
				$table .= 	'<tr class="clickrow pointer" data-id="' . enc($cId) . '">' .
									  '	<td>'.$name.'</td>' .
									  '	<td> '.$ruc.' </td>' .
										'	<td> '.$fullname.' </td>' .
										'	<td> '.$ci.' </td>' .
										'	<td> '.$bday.' </td>' .
										'	<td> '.$email.' </td>' .
										'	<td> '.$phone.' </td>' .
										'	<td> '.$phone2.' </td>' .
										'	<td> '.$address.' </td>' .
										'	<td> '.$location.' </td>' .
										'	<td> '.$city.' </td>' .
										'	<td> '.$tags.' </td>' .
										'	<td class="text-right" data-order="'.$loyalty.'"> '.formatCurrentNumber($loyalty).' </td>' .
										'	<td class="text-right" data-order="'.$compras.'"> ' . formatQty($compras) . ' </td>' .
										'	<td class="text-right" data-order="' . $average . '"> ' . formatCurrentNumber($average) . ' </td>' .
										'	<td class="text-right" data-order="'.$uSold.'"> '.$uSold.' </td>' .
										'	<td class="text-right bg-light lter" data-order="'.$discount.'" data-format="money"> '.formatCurrentNumber($discount).' </td>' .
										'	<td class="text-right bg-light lter" data-order="'.$subtotal.'" data-format="money"> '.formatCurrentNumber($subtotal).' </td>' .
										'	<td class="text-right bg-light lter" data-order="'.$total.'" data-format="money"> '.formatCurrentNumber($total).' </td>' .
										'</tr>';


				if($name && $i < $maxInGraph ){
					$barLabel[] = $name;
					$barData[] 	= $fields['total'];
				}

				if(validateHttp('part')){
		        	$table .= '[@]';
		        }

				$i++;
			}
			$result->MoveNext();	
		}

		if(!validateHttp('part')){
			while (!$resultSum->EOF) {
				$fields = $resultSum->fields;

				if($fields['id'] && $fields['id'] > 0){
					$uSold 		= $fields['usold'];
					$discount	= $fields['discount'];
					$subtotal	= $fields['total'];
					$total		= ($fields['total'] - $discount);
					$compras 	= $fields['count'];

					$tUsold 	+= $uSold;
					$tDiscount 	+= $discount;
					$tSubTotal 	+= $subtotal;
					$tTotal 	+= $total;
					$tCount 	+= $compras;
				}


				$resultSum->MoveNext();
			}
		}

	}

	$foot = '</tbody>' .
			'<tfoot>'.
				'	<tr>'.
				'		<th colspan="12">TOTALES:</th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'		<th class="text-right"></th>'.
				'	</tr>'.
			'	</tfoot>';



	if(validateHttp('part')){
		dai($table);
	}else{
		$currency 				= '<span class="text-muted text-lg">' . CURRENCY . '</span> ';
		$fullTable 				= $head . $table . $foot;

		$jsonResult['global'] 	= [
										'sales' 	=> $currency . formatCurrentNumber($tTotal + $tDiscount), 
										'qty' 		=> formatQty($tCount),
										'qtyRaw' 	=> $tCount,
										'discount' 	=> $currency . formatCurrentNumber($tDiscount), 
										'total' 	=> $currency . formatCurrentNumber($tTotal),
										'average' 	=> $currency . formatCurrentNumber( divider($tTotal, $tCount, true) ),
										'averageRaw'=> divider($tTotal, $tCount, true)
									];
		$jsonResult['chart'] 	= ['label' => $barLabel, 'data' => $barData];
		$jsonResult['table'] 	= $fullTable;

		header('Content-Type: application/json'); 
		dai(json_encodes($jsonResult,true));
	}
}

if(validateHttp('action') == 'byAge'){
	$sql = "SELECT 
			CASE WHEN (DATE_FORMAT(NOW(), '%Y') - DATE_FORMAT(contactBirthDay, '%Y') - (DATE_FORMAT(NOW(), '00-%m-%d') < DATE_FORMAT(contactBirthDay, '00-%m-%d'))) <= 20 THEN '1-20'
			     WHEN (DATE_FORMAT(NOW(), '%Y') - DATE_FORMAT(contactBirthDay, '%Y') - (DATE_FORMAT(NOW(), '00-%m-%d') < DATE_FORMAT(contactBirthDay, '00-%m-%d'))) <= 30 THEN '20-30'
			     WHEN (DATE_FORMAT(NOW(), '%Y') - DATE_FORMAT(contactBirthDay, '%Y') - (DATE_FORMAT(NOW(), '00-%m-%d') < DATE_FORMAT(contactBirthDay, '00-%m-%d'))) <= 50 THEN '30-50'
			     WHEN (DATE_FORMAT(NOW(), '%Y') - DATE_FORMAT(contactBirthDay, '%Y') - (DATE_FORMAT(NOW(), '00-%m-%d') < DATE_FORMAT(contactBirthDay, '00-%m-%d'))) <= 50 THEN '50-100' END AS age,
			COUNT(*) total
			FROM contact
			WHERE companyId = ?
			GROUP BY age";
	dai();
}

if(validateHttp('widget') == 'customersRates'){
	$out = getCustomersRate($startDate,$endDate,COMPANY_ID);
	jsonDieResult($out);
}

if(validateHttp('action') == 'getCustomerStatusChartByDay'){
	dai();
	$churnR 		= [];
	$retentionR 	= [];
	$growthR 		= [];
	$labels 		= [];
	foreach ($calendar as $key => $date) {
		$startD = $date . ' 00:00:00';
		$endD 	= $date . ' 23:59:59';

		$performanceArray = getCustomersRate($startD,$endD);

		$churnR[] 		= $performanceArray['churn_rate'];
		$retentionR[] 	= $performanceArray['retention_rate'];
		$growthR[] 		= $performanceArray['customer_growth_rate'];

		$labels[] 		= niceDate($startD,false,false,true);
	}

	jsonDieResult([
					'chart' => [
									'label' 		=> $labels,
									'churnR' 		=> $churnR,
									'retentionR' 	=> $retentionR,
									'growthR' 		=> $growthR
								]
				]);
}

if(validateHttp('widget') == 'customerHeatMap'){
	$cities 	= [];
	$locations 	= [];
	$latLng 	= [];
	$cIn 		= [];

	$result 	= ncmExecute('
								SELECT * 
								FROM customerAddress 
								WHERE customerAddressCity IS NOT NULL 
								AND customerAddressLocation IS NOT NULL 
								AND customerAddressLat IS NOT NULL
								AND customerAddressLng IS NOT NULL
								AND companyId = ? 
								LIMIT 5000',[COMPANY_ID],true,true);

	if($result){
      while (!$result->EOF) {
      	$field 		= $result->fields;

      	$rmv 		= ["á","é","í","ó","ú","Á","É","Í","Ó","Ú"];
      	$with 		= ["a","e","i","o","u","A","E","I","O","U"];
      	
      	$city 		= strtoupper( str_replace($rmv,$with,$field['customerAddressCity']) );
      	$location 	= strtoupper( str_replace($rmv,$with,$field['customerAddressLocation']) );

      	$lat 		= floatval($field['customerAddressLat']);
      	$lng 		= floatval($field['customerAddressLng']);

      	if(validity($city)){
	      	$cities[$city] 			= $cities[$city] + 1;
	    }

	    if(validity($location)){
	      	$locations[$location] 	= $locations[$location] + 1;
	    }

	    if(validity($location)){
	      	$latLng[] 				= [$lat,$lng];
	    }

      	$result->MoveNext();
      }
    }

    arsort($locations);
    arsort($cities);

	jsonDieResult([
					'latLng' 		=> $latLng,
					'location' 		=> $locations,
					'city' 			=> $cities
				]);
}

if(validateHttp('widget') == 'newRecurring'){

    //TENER EN CUENTA QUE AQUI CUENTO TODOS LOS CLIENTES NUEVOS SIN IMPORTAR SI INGRESARON POR ORDEN, COTIZACION O LO QUE SEA NO SOLO POR VENTAS
    $newC 		= ncmExecute("
                        SELECT 
                        	DATE(contactDate) as date,
                        	COUNT(contactId) as new
                        FROM contact 
                        WHERE type = 1 
                        AND contactDate 
                        BETWEEN ? 
                        AND ? 
                        AND companyId = ?
                        GROUP BY date", 
                        [$startDate,$endDate,COMPANY_ID],true,true
                      );
    $new = [];
    if($newC){
      while (!$newC->EOF) {
      	$new[$newC->fields['date']] = $newC->fields['new'];
      	$newC->MoveNext();
      }
    }

    $oldRoc   	= str_replace(['outletId','companyId'], ['a.outletId','a.companyId'], $roc);
    $oldC     	= ncmExecute(
                                "SELECT 
                                	DATE(transactionDate) as date,
									COUNT(a.customerId) as recurring
                                FROM transaction a, contact b
                                WHERE a.transactionDate
                                BETWEEN ?
                                AND ?
                                AND (a.customerId IS NOT NULL AND a.customerId > 1)
                                " . $oldRoc . "
                                AND a.transactionType IN(0,3)
                                AND a.customerId = b.contactUID
                                AND b.contactDate < ?
                                AND b.type = 1
                                GROUP BY date"
                              ,
                              [$startDate,$endDate,$startDate],true,true
                            );

    $recurring = [];
    if($oldC){
      while (!$oldC->EOF) {
      	$recurring[$oldC->fields['date']] = $oldC->fields['recurring'];
      	$oldC->MoveNext();
      }
    }

    $newO 		= [];
    $recurringO = [];
    $labels 	= [];
    foreach ($calendar as $day) {
    	$labels[] 		= niceDate($day,false,false,true);
    	$newO[] 		= $new[$day] ? $new[$day] : 0;
    	$recurringO[] 	= $recurring[$day] ? $recurring[$day] : 0;
    }

    $out = [
    		  'label' => $labels,
              'new'   => $newO,
              'recurring'   => $recurringO
            ];

    jsonDieResult($out);
}

$outlet = ncmExecute("SELECT * FROM outlet WHERE companyId = ? LIMIT 1",[COMPANY_ID],true);

?>

<?=menuReports();?>

<?php
echo reportsDayAndTitle([
							'title' 		=> '<div class="text-md text-right font-default">Análisis de</div> Clientes',
							'maxDays' 		=> $MAX_DAYS_RANGE,
							'hideChart' 	=> true,
							'nextToPicker' 	=> '<span class="btn-group m-l-sm"> 
													<span class="dropdown" title="Menú" data-toggle="tooltip" data-placement="right">   
														<a href="#" class="btn btn-icon dropdown-toggle bg-white font-bold rounded" data-toggle="dropdown" aria-expanded="false" id="menuPickerBtn">     
															<span class="material-icons">expand_more</span>
														</a>   
														<ul class="dropdown-menu animated fadeIn speed-4x" role="menu">     
															<li>       
																<a class="text-default menuPickerBtn" data-type="chart" href="#">Análisis</a>     
															</li>     
															<li>       
																<a class="text-default menuPickerBtn" data-type="list" href="#">Listado</a>     
															</li>   
															<li>       
																<a class="text-default menuPickerBtn" data-type="map" href="#">Geografía</a>     
															</li>     
														</ul> 
													</span>
												</span>'
						]);
?>

<div class="col-xs-12 no-padder viewTab tabchart animated fadeIn speed-4x">
	<div class="row m-b hidden-print">
		<div class="col-md-8 col-sm-7 col-xs-12">
			<div class="col-xs-12 h4 font-bold m-b">
				Nuevos vs Recurrentes
			</div>
			<?=placeHolderLoader('chart',false,'newRecurringL');?>
			<canvas id="newRecurring" class="hidden-print hidden m-b" height="280" style="max-height:280px;"></canvas>
			<div class="col-xs-12 no-padder">
				
			</div>
		</div>
		<div class="col-md-4 col-sm-5 col-xs-12" id="customersWidget">
			<div class="col-xs-12 no-padder hidden-print text-center">
		      <canvas id="chart-customers" height="200" style="max-height:200px;"></canvas>
		      
		      <div class="donut-inner" style=" margin-top: -140px; margin-bottom: 100px;">
		        <div class="h1 m-t total font-bold"><?=placeHolderLoader()?></div>
		        <span>Total de clientes</span>
		      </div>
			</div>

			<div class="col-xs-12 wrapper clear bg-dark r-24x m-t-n m-b">
				<div class="col-xs-6 text-center no-padder b-r b-dark">
				  <div class="h1 font-bold">
				    <span class="customersNew">...</span>
				  </div>
				  <div><span class="customersNewArrow">...</span> Nuevos</div>
				</div>
				<div class="col-xs-6 text-center no-padder">
				  <div class="h1 font-bold">
				    <span class="customersOld">...</span>
				  </div>
				  <div><span class="customersOldArrow">...</span> Recurrentes</div>
				</div>
			</div>
	        
		</div>
	</div>

	<div class="row hidden-print">
		<div class="col-md-4 col-sm-5 col-xs-12">
			<?php
              if($_modules['feedback']){
              ?>
              <div class="col-xs-12 no-bg no-padder" id="customerSatisfactionLevel">
                <div class="h4 font-bold m-b">
                  Nivel de satisfacción de clientes o <a href="https://docs.encom.app/preguntas-frecuentes/panel-de-control/que-es-satisfaccion-del-cliente-o-net-promoter-score-nps" target="_blank"> <span class="font-normal text-u-l">(NPS)</span></a>
                  <a href="/@#report_satisfaction" class="pull-right hidden-print">
                    <i class="material-icons md-24">keyboard_arrow_right</i>
                  </a>
                </div>
                <div class="progress progress-xs dker progress-striped"> 
                  <div class="progress-bar gradBgRed satisfactionBarDetractors" data-toggle="tooltip" data-original-title="0%" style="width: 0%"></div> 

                  <div class="progress-bar gradBgYellow satisfactionBarPassives" data-toggle="tooltip" data-original-title="0%" style="width: 0%"></div> 

                  <div class="progress-bar gradBgGreen satisfactionBarPromoters" data-toggle="tooltip" data-original-title="0%" style="width: 0%"></div> 

                </div>
	          </div>
	        <?php
	        }
	        ?>

			<div class="col-xs-12 wrapper bg-dark m-b r-24x clear">
				<img src="" width="60" class="pull-left m-l-n returnRateImg">
				<span class="pull-left m-t-sm">Tasa de Retorno</span>
				<h3 class="font-bold pull-right m-t-xs m-b-n returnRate">...</h3>
			</div>
			<div class="col-xs-12 wrapper bg-dark m-b r-24x clear">
				<img src="" width="60" class="pull-left m-l-n retentionRateImg">
				<span class="pull-left m-t-sm">Tasa de Retención</span>
				<h3 class="font-bold pull-right m-t-xs m-b-n retentionRate">...</h3>
			</div>
			<div class="col-xs-12 wrapper bg-dark m-b r-24x clear">
				<img src="" width="60" class="pull-left m-l-n growthRateImg">
				<span class="pull-left m-t-sm">Tasa de Crecimiento</span>
				<h3 class="font-bold pull-right m-t-xs m-b-n growthRate">...</h3>
			</div>
			<div class="col-xs-12 wrapper bg-dark m-b r-24x clear">
				<img src="" width="60" class="pull-left m-l-n churnRateImg">
				<span class="pull-left m-t-sm">Tasa de pérdida (Churn)</span>
				<h3 class="font-bold pull-right m-t-xs m-b-n churnRate">...</h3>
			</div>
		</div>
		<div class="col-md-8 col-sm-7 col-xs-12">
			<div class="col-xs-12 h4 font-bold m-b m-t">
				Ranking de Clientes
			</div>
			<?=placeHolderLoader('chart',false,'topCustomersL');?>
			<canvas id="topCustomers" class="hidden-print hidden" height="240" style="max-height:240px;"></canvas>
			<div class="col-xs-12 wrapper r-24x bg-white m-t hidden">
				<p>De los <span class="thisMonthCustomers font-bold">00</span> clientes que compraron entre el <em><?=niceDate($startDate,false,false,true)?></em> y el <em><?=niceDate($endDate,false,false,true)?></em>, en promedio hicieron <span class="purchaseFrecuency font-bold">00</span> compras con un intervalo de <span class="purchaseInterval font-bold">00</span> días gastando <span class="customerAverageRaw font-bold">00</span> aproximados por transacción.</p>
			</div>
			<div class="col-xs-12 no-padder text-center m-t">
				<section class="col-sm-4 col-xs-12 m-b">
				    <div class="text-center">
						<div class="h1 m-b-xs font-bold customerAverage" id="customerAverage"><?=placeHolderLoader()?></div>
						Promedio por Cliente
					</div>
				</section>
				<section class="col-sm-4 col-xs-6 m-b">
				    <div class="text-center">
						<div class="h1 m-b-xs font-bold purchaseFrecuency" id="purchaseFrecuency"><?=placeHolderLoader()?></div>
						Frecuencia de Compra
					</div>
				</section>
				<section class="col-sm-4 col-xs-6 m-b">
				    <div class="text-center">
						<div class="h1 m-b-xs font-bold purchaseInterval" id="purchaseInterval"><?=placeHolderLoader()?></div>
						Intervalo entre Compras
					</div>
				</section>
			</div>
		</div>
	</div>
</div>

<div class="col-xs-12 no-padder viewTab tablist hidden animated fadeIn speed-4x">
	<div class="col-xs-12 no-padder text-center">
		<section class="col-md-3 col-sm-12">
		    <div class="text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold" id="globalSales"><?=placeHolderLoader()?></div>
				Total de Ventas
			</div>
		</section>
			<section class="col-md-3 col-sm-12">
		    <div class="text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold" id="globalQty"><?=placeHolderLoader()?></div>
				Cantidad
			</div>
		</section>
		<section class="col-md-3 col-sm-6">
		    <div class="text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold" id="globalDiscount"><?=placeHolderLoader()?></div>
				Descuentos
			</div>
		</section>
		<section class="col-md-3 col-sm-6">
		    <div class="text-center wrapper-md">
				<div class="h1 m-t m-b-xs font-bold" id="globalTotal"><?=placeHolderLoader()?></div>
				Total
			</div>
		</section>
	</div>
	<div class="col-xs-12 wrapper panel r-24x bg-white push-chat-down">
		<div id="tableContainer">
			<div class="table-responsive no-border">                                  	
			    <table class="table table-hover col-xs-12 no-padder" id="tableTransactions">
			    	<?=placeHolderLoader('table')?>
			    </table>
			</div>
		</div>

	    <div class="col-xs-12 bg-warning lter wrapper text-center text-dark r-24x">
	    	<strong>Importante:</strong> El reporte no incluye ventas que no poseen un cliente asociado.
	    </div>
	</div>
</div>

<div class="col-xs-12 no-padder viewTab tabmap hidden animated fadeIn speed-4x">

	<div class="col-xs-12 no-padder m-b">
		<div class="col-xs-12 h4 font-bold m-b">
			Top 30 Localidades
		</div>
		<?=placeHolderLoader('chart',false,'topLocationsL');?>
		<canvas id="topLocations" class="hidden-print hidden" height="250" style="max-height:250px;"></canvas>
	</div>

	<div class="col-sm-8 col-xs-12 no-padder">
		<div class="col-xs-12 h4 font-bold m-b">
			Concentración geográfica de sus clientes
		</div>
		<div class="customersMap r-24x clear m-b" id="map" style="height: 600px; width: 100%;">
			
		</div>
	</div>
	<div class="col-sm-4 col-xs-12" id="geoLists">
		
	</div>

	
</div>

<script type="text/html" id="geoListTpl">
	<div class="col-xs-12 wrapper panel m-b r-24x scrollable" style="{{style}}">
        <div class="h4 font-bold m-b">{{title}}</div>
        <table class="table no-border">
            <tbody>
            	{{#data}}
    	        <tr>
                  <td>
                    {{name}}
                  </td>
                  <td class="font-bold text-right">
                    {{value}}
                  </td>
                </tr>
                {{/data}}
            </tbody>
        </table>
    </div>
</script>

<script>

$(document).ready(function(){
	$('[data-toggle="tooltip"]').tooltip();
	FastClick.attach(document.body);
	var baseUrl = '<?=$baseUrl?>';
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");

	var customerGlobals = {
		totalSalesQty : -1,
		recurringCount: -1
	};

	var rawUrl 		= baseUrl + "?action=generalTable";
	var loadUrl 	= rawUrl + "&cusId=<?=validateHttp('ci')?>";
	var offset 		= <?=$offsetDetail?>;
	var limit 		= <?=$limitDetail?>;
	var currency 	= "<?=CURRENCY?>";
	var tinName 	= "<?=TIN_NAME?>";

	var xhr = ncmHelpers.load({
		url 				: loadUrl,
		httpType 		: 'GET',
		hideLoader 	: true,
		type 				: 'json',
		success 		: function(result){

			var options = {
						"container" 	: "#tableContainer",
						"url" 				: loadUrl,
						"rawUrl" 			: rawUrl,
						"iniData" 		: result.table,
						"table" 			: "#tableTransactions",
						"sort" 				: 0,
						"footerSumCol": [12,13,14,15,16,17,18],
						"currency" 		: currency,
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 			: offset,
						"limit" 			: limit,
						"nolimit" 		: true,
						"tableName" 	: 'tableTransactions',
						"fileTitle" 	: 'Ranking de Clientes',
						"ncmTools"		: {
												left 	: '',
												right 	: ''
											  },
						"colsFilter"	: {
												name 		: 'reportCustomers6',
												menu 		:  [
																{"index":0,"name":'Razón Social',"visible":true},
																{"index":1,"name":tinName,"visible":false},
																{"index":2,"name":'Nombre y Apellido',"visible":false},
																{"index":3,"name":'Doc. de identidad',"visible":false},
																{"index":4,"name":'Cumpleaños',"visible":false},
																{"index":5,"name":'Email',"visible":false},
																{"index":6,"name":'Teléfono',"visible":true},
																{"index":7,"name":'Teléfono 2',"visible":false},
																{"index":8,"name":'Dirección',"visible":false},
																{"index":9,"name":'Localidad',"visible":false},
																{"index":10,"name":'Ciudad',"visible":false},
																{"index":11,"name":'Etiquetas',"visible":false},
																{"index":12,"name":'Loyalty',"visible":false},
																{"index":13,"name":'Cant. de compras',"visible":true},
																{"index":14,"name":'Gasto Promedio',"visible":false},
																{"index":15,"name":'Uni. adquiridas',"visible":false},
																{"index":16,"name":'Descuentos',"visible":false},
																{"index":17,"name":'Subtotal',"visible":false},
																{"index":18,"name":'Total',"visible":true}
																]
											  },
						"clickCB" 		: function(event,tis){
							var id 		= tis.data('id');
							var load 	= '/a_contacts?action=form&id=' + id + '&type=wl&ro=1';
							if(id != 'vy'){
								loadForm(load,'#modalLarge .modal-content',function(){
									$('#modalLarge').modal('show');
								});
							}
						}
			};

			var tableShown 	= false;
			var mapShown 	= false;

			ncmHelpers.onClickWrap('.menuPickerBtn',function(event,tis){
				var type = tis.data('type');

				$('.viewTab').addClass('hidden');

				$('.tab' + type).removeClass('hidden');

				if(!tableShown && type == 'list'){
					tableShown = true;
					ncmDataTables(options);	
				}

				if(!mapShown && type == 'map'){
					mapShown = true;
					var xhr = $.get(baseUrl + '?widget=customerHeatMap',function(result){
						var latLng 		= result.latLng;
						var locations 	= result.location;
						var cities 		= result.city;
						var locationData = {};

						locationData.label 	= [];
						locationData.data 	= [];

						var i 		= 0;
						var maxLocs = 30;
						$.each(locations,function(name,qty){
							if(i > maxLocs){
								return false;
							}

							if(qty > 1){
								locationData.label.push(name);
								locationData.data.push(qty);
								i++;
							}
						});

						var cityData = [];
						$.each(cities,function(name,qty){
							cityData.push({
								name 	: name,
								value 	: qty
							});
						});

						var cityBuilt 		= ncmHelpers.mustacheIt($('#geoListTpl'),{"style" : "height:632px;","title" : "Ciudades", "data" : cityData},false,true);

						$('#geoLists').html(cityBuilt);

						chartLocations(locationData);

						<?php
						$lat = explode(',', $outlet['outletLatLng'])[0];
            			$lng = explode(',', $outlet['outletLatLng'])[1];
						?>

						var mapOps = {
					      location  : ['<?=$lat?>','<?=$lng?>'],
					      zoom      : 13,
					      zoomCtrl 	: true,
					      zoomWheel : true,
					      ui 		: ncmUI.setDarkMode.isSet ? 'dark' : 'light',
					      icon 		: 'store',
					      padding   : 50,
					      heatMap   : latLng
					    };

						ncmMaps.map(mapOps);
					});
				}
		    });

		    customerGlobals.totalSalesQty = result.global.qtyRaw;

			$('.customerAverage').html(result.global.average);
			$('.customerAverageRaw').html(formatNumber(result.global.averageRaw,'',decimal,thousandSeparator));
			$('#globalSales').html(result.global.sales);
			$('#globalQty').html(result.global.qty);
			$('#globalDiscount').html(result.global.discount);
			$('#globalTotal').html(result.global.total);

			drawChart(result);
		}	
	});
	window.xhrs.push(xhr);

	var xhr = $.get('/a_dashboard?widget=customersRates',function(result){

      var retentionRate = '.retentionRate';
      var growthRate    = '.growthRate';
      var churnRate     = '.churnRate';

      var retentionRateImg = 'https://quickchart.io/chart?backgroundColor=transparent&c={ type: "doughnut", data: { datasets: [ { data: [' + result.retention_rate + ', ' + (result.retention_rate - 100) + '], backgroundColor: [ "%2362bcce", "%23e8eff0" ] } ] }, options: { rotation: 16, plugins: { datalabels: { display: false } }, cutoutPercentage:80 }}';

      var growthRateImg = 'https://quickchart.io/chart?backgroundColor=transparent&c={ type: "doughnut", data: { datasets: [ { data: [' + result.customer_growth_rate + ', ' + (result.customer_growth_rate - 100) + '], backgroundColor: [ "%2362bcce", "%23e8eff0" ] } ] }, options: { rotation: 16, plugins: { datalabels: { display: false } }, cutoutPercentage:80 }}';

      var churnRateImg = 'https://quickchart.io/chart?backgroundColor=transparent&c={ type: "doughnut", data: { datasets: [ { data: [' + result.churn_rate + ', ' + (result.churn_rate - 100) + '], backgroundColor: [ "%23f06a6a", "%23e8eff0" ] } ] }, options: { rotation: 16, plugins: { datalabels: { display: false } }, cutoutPercentage:80 }}';//"https://quickchart.io/chart?c={type:'radialGauge',data:{datasets:[{data:[" + result.churn_rate + "],backgroundColor:['%23f06a6a','%23e8eff0'],borderWidth:0}]}}";

      $(retentionRate).text(result.retention_rate + '%');
      $(growthRate).text(result.customer_growth_rate + '%');
      $(churnRate).text(result.churn_rate + '%');

      $(retentionRate + 'Img').attr('src',retentionRateImg);
      $(growthRate + 'Img').attr('src',growthRateImg);
      $(churnRate + 'Img').attr('src',churnRateImg);
    });

    window.xhrs.push(xhr);

	var xhr = $.get('/a_dashboard?widget=customers',function(result){
        if(validityChecker(result)){
          $('.customersTotal').text(result.total);
          $('.customersNew').text(result.new);
          $('.customersOld').text(result.old);

          $('.thisMonthCustomers').text(result.totalPeriod);

          customerGlobals.recurringCount = result.old;
          
          var returnRate     	= '.returnRate';
          var returnRateImg 	= 'https://quickchart.io/chart?backgroundColor=transparent&c={ type: "doughnut", data: { datasets: [ { data: [' + result.returnRate + ', ' + (result.returnRate - 100) + '], backgroundColor: [ "%2362bcce", "%23e8eff0" ] } ] }, options: { rotation: 16, plugins: { datalabels: { display: false } }, cutoutPercentage:80 }}';

          $('.returnRate').text(result.returnRate + '%');
          $(returnRate + 'Img').attr('src',returnRateImg);

          $('#customersWidget .average div span.h1').text(result.average);

          var xhr = $.get('/a_dashboard?widget=customers&prev=true',function(result2){
              var wrap      = '#customersWidget';
              var success   = '<span class="text-success m-r-xs m-l-xs"><i class="material-icons">trending_up</i></span>';
              var fail      = '<span class="text-danger m-r-xs m-l-xs"><i class="material-icons">trending_down</i></span>';
              var even      = '<span class="font-bold m-r-xs m-l-xs"><i class="material-icons">trending_flat</i></span>';
              
              if(result2.new > result.new){
                var news = fail;
              }else if(result2.new < result.new){
                var news = success;
              }else{
                var news = even;
              }
              $('.customersNewArrow').html(news);

              if(result2.old > result.old){
                var old = fail;
              }else if(result2.old < result.old){
                var old = success;
              }else{
                var old = even;
              }

              $('.customersOldArrow').html(old);

              if(result2.churn > result.churn){
                var churn = fail;
              }else if(result2.churn < result.churn){
                var churn = success;
              }else{
                var churn = even;
              }

              $('.customersChurnArrow').html(churn);

              $('#customersWidget .total').text(result.total);

              var news 		= (result.new > 0) ? result.new : result.total;
	          var newsTitle = (result.new > 0) ? 'Nuevos' : 'Clientes';
	          
	          var customers	= new Chart($('#chart-customers'), {
	            type      : 'doughnut',
	            data      : {
	              labels: [newsTitle,'Recurrentes'],
	              datasets: [
	              {
	                label: "Clientes",
	                data: [news,result.old],
	                backgroundColor: ['#4cb6cb','#778490']
	              }]
	            },
	            animation : true,
	            options   : {
	              cutoutPercentage 	: 85,
	              tooltips 			: chartTooltipStyle.tooltips
	            }
	          });
          });     
        }
    });

    window.xhrs.push(xhr);

	var xhr = ncmHelpers.load({
		url 		: baseUrl + "?widget=newRecurring",
		httpType 	: 'GET',
		hideLoader 	: true,
		type 		: 'json',
		warnTimeout : false,
		success 	: function(result){
			Chart.defaults.global.responsive 			= true;
			Chart.defaults.global.maintainAspectRatio 	= false;
			Chart.defaults.global.legend.display 		= false;

			$('#newRecurring').removeClass('hidden');
			$('#newRecurringL').addClass('hidden');

			var myChart 		= $('#newRecurring')[0].getContext("2d");
			var gradientStroke 	= myChart.createLinearGradient(300, 0, 100, 0);
			gradientStroke.addColorStop(0, "#4cb6cb");
			gradientStroke.addColorStop(1, "#54cfc7");

			var allData = {
								labels 		: 	result.label,
								datasets 	: 	[
													
													{
														label 			: 'Nuevos',
														backgroundColor : gradientStroke,
														data 			: result.new
													},
													{
														label 			: 'Recurrentes',
														backgroundColor : chartSecondColor,
														data 			: result.recurring
													}
												]
							  };

			var rates = new Chart(myChart, {
			    type 		: 'bar',
			    data 		: allData,
			    animation 	: true,
			    options 	: chartBarStackedGraphOptions
			});
		}
	});
	window.xhrs.push(xhr);

	<?php
    if($_modules['feedback']){
    ?>
	    var xhr = $.get('a_dashboard?widget=satisfaction',function(result){
	      $('.satisfactionBarDetractors').attr('data-original-title', 'Detractores: ' + result.detractors.count + ' voto(s)');
	      $('.satisfactionBarDetractors').addClass(result.detractors.percent).css('width',result.detractors.percent + '%');

	      $('.satisfactionBarPassives').attr('data-original-title', 'Pasivos: ' + result.passives.count + ' voto(s)');
	      $('.satisfactionBarPassives').addClass(result.passives.percent).css('width',result.passives.percent + '%');

	      $('.satisfactionBarPromoters').attr('data-original-title', 'Promotores: ' + result.promoters.count + ' voto(s)');
	      $('.satisfactionBarPromoters').addClass(result.promoters.percent).css('width',result.promoters.percent + '%');
	    });

	    window.xhrs.push(xhr);
    <?php
    }
    ?>
	
	var checkExist = setInterval(function() {
	   if (customerGlobals.totalSalesQty > 0 && customerGlobals.recurringCount > 0 ) {
	   		var purchaseFrecuency = customerGlobals.totalSalesQty / customerGlobals.recurringCount;
	   		$('.purchaseFrecuency').text(formatNumber(purchaseFrecuency,'','yes',thousandSeparator));

	   		var purchaseInterval = '<?=counts($calendar)?>' / purchaseFrecuency;
	    	$('.purchaseInterval').text(formatNumber(purchaseInterval,'','yes',thousandSeparator));
	    	clearInterval(checkExist);
	   }
	}, 500); // check every 100ms
});


var drawChart = function(result){
	var chart = result.chart;
	Chart.defaults.global.responsive = true;
	Chart.defaults.global.maintainAspectRatio = false;
	Chart.defaults.global.legend.display       = false;

	$('#topCustomers').removeClass('hidden');
	$('#topCustomersL').addClass('hidden');

	var myChart = $('#topCustomers')[0].getContext("2d");

	var gradientStroke = myChart.createLinearGradient(300, 0, 100, 0);
	gradientStroke.addColorStop(0, "#4cb6cb");
	gradientStroke.addColorStop(1, "#54cfc7");

	var barData = chart.data.map(function(item) {
	    return parseInt(item);
	});

	var dataD = {
	    labels: chart.label,
	    datasets: [
	        {
	        	label: "Total",
	            data: barData,
	            backgroundColor: gradientStroke
	        }]
	};

	var methods = new Chart(myChart, {
	    type 		: 'bar',
	    data 		: dataD,
	    animation 	: true,
	    options 	: chartBarStackedGraphOptions
	});

};

var chartLocations = function(chart){
	Chart.defaults.global.responsive 			= true;
	Chart.defaults.global.maintainAspectRatio 	= false;
	Chart.defaults.global.legend.display       	= false;

	$('#topLocations').removeClass('hidden');
	$('#topLocationsL').addClass('hidden');

	var myChart 		= $('#topLocations')[0].getContext("2d");

	var gradientStroke 	= myChart.createLinearGradient(300, 0, 100, 0);
	gradientStroke.addColorStop(0, "#4cb6cb");
	gradientStroke.addColorStop(1, "#54cfc7");

	var barData = chart.data.map(function(item) {
	    return parseInt(item);
	});

	var dataD = {
	    labels: chart.label,
	    datasets: [
	        {
	        	label: "Total",
	            data: barData,
	            backgroundColor: gradientStroke
	        }]
	};

	var methods = new Chart(myChart, {
	    type 		: 'bar',
	    data 		: dataD,
	    animation 	: true,
	    options 	: chartBarStackedGraphOptions
	});

};
</script>

<?php
include_once('includes/compression_end.php');
dai();
?>