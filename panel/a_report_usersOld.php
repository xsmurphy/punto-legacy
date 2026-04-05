<?php
include_once('includes/top_includes.php');
topHook();
allowUser('sales','view');

$MAX_DAYS_RANGE = 31;

$baseUrl = '/' . basename(__FILE__,'.php');

ignore_user_abort(false);

############
## Hacer que al hacer click en un cliente te abra al costado estadisticas del mismo, durante el periodo seleccionado, ej un barchart con total vendido, tootal descuentos
## total ganancias, y desempeño, relacion dividiendo cantidad de ventas realizadas con total vendido y promedio


list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 			= getROC(1);
$limitDetail	= 2000;
$offsetDetail	= 0;

$rocd 				= str_replace(['outletId','companyId'],['b.outletId','b.companyId'],$roc);

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

if(validateHttp('action') == 'generalTable'){

	$allTransactions 	= [];
	$specialOutlet 		= (OUTLET_ID > 0) ? ' AND (outletId = ' . OUTLET_ID . ' OR outletId IS NULL OR outletId = 0)' : '';
	$result 			= ncmExecute('SELECT contactName, contactId FROM contact WHERE type = 0 AND contactStatus = 1 ' . $specialOutlet . ' AND companyId = ' . COMPANY_ID . ' LIMIT 200',[],false,true);


	if($result){

		while (!$result->EOF) { //aquí agrupo los IDS nuevos con los viejos
			$fields 	= $result->fields;
			$id 		= $fields['contactId'];

			//otras comisiones que no sean de ventas, por ej sesiones
			$comissed 	= ncmExecute("SELECT SUM(comissionTotal) as comission FROM comission WHERE comissionDate BETWEEN ? AND ? AND userId = ? " . $roc . " LIMIT 5000",[$startDate,$endDate,$fields['contactId']]);

			//problema: metodo de suma en un solo query no toma en cuenta productos que son combos que deberian ser ignorados
			//selecciono todos los items sold
			//verifico solo sumo combos
			//veo si el user tiene ventas asociadas en la fecha
			//$chkIfSales = ncmExecute('SELECT itemSoldId FROM itemSold WHERE itemSoldDate >= ? AND itemSoldDate <=  ? AND userId = ? LIMIT 2',[$startDate,$endDate,$id],true);

			

			$sales = [];

			//if($chkIfSales){

				$sales = ncmExecute("SELECT
                        SUM(itemSoldUnits) as usold,
                        SUM(itemSoldTotal) as total,
                        SUM(itemSoldComission) as comission,
                        SUM(itemSoldDiscount) as discount,
                        COUNT(transactionId) as count
                    FROM itemSold
                    WHERE transactionId IN (
                        SELECT transactionId
                        FROM transaction
                        WHERE transactionType IN (0,3,6)
                        AND transactionDate >= ?
                        AND transactionDate <= ?
                    )
                    AND userId = ?", [$startDate, $endDate, $id]);
				// $sales 		= ncmExecute("	SELECT
				// 								SUM(a.itemSoldUnits) as usold,
				// 								SUM(a.itemSoldTotal) as total,
				// 								SUM(a.itemSoldComission) as comission,
				// 								SUM(a.itemSoldDiscount) as discount,
				// 								COUNT(b.transactionId) as count
				// 							FROM itemSold a, transaction b USE INDEX(transactionDate), item c
				// 							WHERE b.transactionDate >= ?
				// 							AND b.transactionDate <=  ?
				// 							AND b.transactionType IN (0,3,6)
				// 							" . $rocd . "
				// 							AND a.transactionId = b.transactionId
				// 							AND a.userId = ?
				// 							AND a.itemId = c.itemId
				// 							AND c.itemType IN('product')",[$startDate,$endDate,$id]);

			//}


			$comission 	= iftn($sales['comission'],$comissed['comission'],$sales['comission'] + $comissed['comission']);

			if( !empty($allTransactions[$id]) && validity($allTransactions[$id])){
				$allTransactions[$id]['usold'] 		+= $sales['usold'];
				$allTransactions[$id]['total'] 		+= $sales['total'];
				$allTransactions[$id]['comission'] 	+= $comission;
				$allTransactions[$id]['count'] 		+= $sales['count'];
				$allTransactions[$id]['discount'] 	+= $sales['discount'];
			}else{
				$allTransactions[$id] = 	[
												'name' 		=> $fields['contactName'],
												'usold' 	=> $sales['usold'],
												'total' 	=> $sales['total'],
												'comission' => $comission,
												'count' 	=> $sales['count'],
												'discount' 	=> $sales['discount']
											];
			}	

			$result->MoveNext();	
		}

		$result->Close();
	}

	//esto es para los usuarios que que poseían RealId, duplica cuando se hicieron ventas antes de actualizar de la tabla users a contactos
	$table = '';
	
	$head = '<thead class="text-u-c">'.
			'	<tr>'.
			'		<th>Nombre</th>'.
			'		<th class="text-center">Ventas</th>'.
			'		<th class="text-center">Cantidad</th>'.
			'		<th class="text-center">Comisiones</th>'.
			'		<th class="text-center">Descuentos</th>'.
			'		<th class="text-center">Subtotal</th>'.
			'		<th class="text-center">Total</th>'.
			'		<th></th>'.
			'	</tr>'.
			'</thead>'.
			'<tbody>';

	$label 		= '';
	$data 		= '';

	$tUsold 	= 0;
	$tDiscount 	= 0;
	$tSubTotal 	= 0;
	$tTotal 	= 0;
	$tCount 	= 0;

	$barLabel 	= [];
	$barData 	= [];


	if(validity($allTransactions,'array')){

		foreach ($allTransactions as $id => $fields) {
			$userId 	= enc($id);
			$uSold 		= ($fields['usold']) ? $fields['usold'] : '0';
			$discount	= floatval($fields['discount']);
			$subtotal	= floatval($fields['total']) + $discount;
			$total 		= floatval($fields['total']);
						$count 		= ($fields['count']) ? $fields['count'] : '0';
			$name 		= $fields['name'];
			$comission 	= floatval($fields['comission']);
			$url 		= 'javascript:;';
			$urlColor	= 'text-muted';
			$urlStatus	= 'disabled';

			$tUsold 	+= $uSold;
			$tDiscount 	+= $discount;
			$tSubTotal 	+= $subtotal;
			$tTotal 	+= $total;
			$tCount 	+= $count;

			if($comission > 0){
			$url 		= 'https://public.encom.app/userItemsSold?s=' . base64_encode(enc(COMPANY_ID) . ',' . $userId . ',' . $startDate . ',' . $endDate);
			$urlColor	= 'text-info';
			$urlStatus	= '';
			}

			if($total){
				$barLabel[] = $name;
				$barData[] 	= $total;
			}

			$table .= 	'	<tr class="pointer clickrow" data-url="#report_user_comissions?ui=' . $userId . '">' .
						' 		<td class="font-bold" data-id="' . $userId . '"> ' . $name . ' </td>' .
						'		<td class="text-right" data-order="' . $count . '"> ' . formatQty($count) . ' </td>' .
						' 		<td class="text-right" data-order="' . $uSold . '"> ' . formatQty($uSold) . ' </td>' .
						'		<td class="text-right bg-light lter" data-order="' . $comission . '" data-format="money"> ' . formatCurrentNumber($comission) . ' </td>' .
						'		<td class="text-right bg-light lter" data-order="'.$discount.'" data-format="money"> '.formatCurrentNumber($discount).' </td>'.
						' 		<td class="text-right bg-light lter" data-order="'.$subtotal.'" data-format="money"> '.formatCurrentNumber($subtotal).' </td>'.
						' 		<td class="text-right bg-light lter" data-order="'.$total.'" data-format="money"> '.formatCurrentNumber($total).' </td>' .
						'		<td class="text-center">' .
						'			<a href="' . $url . '" class="openLink hidden-print noxls ' . $urlStatus . '" target="_blank" data-toggle="tooltip" title="Detalle de Comisiones" ' . $urlStatus . '><i class="material-icons ' . $urlColor . '">open_in_new</i></a>' .
						' 		</td>' .
						'	</tr>';		
		}

	}
	
	$foot = '</tbody>' .
			'<tfoot>' .
			'	<tr>' .
			'		<th>TOTALES:</th>' .
			'		<th class="text-right"></th>' .
			'		<th class="text-right"></th>' .
			'		<th class="text-right"></th>' .
			'		<th class="text-right"></th>' .
			'		<th class="text-right"></th>' .
			'		<th class="text-right"></th>' .
			'		<th class="text-right"></th>' .
			'	</tr>' .
			'</tfoot>';


	$fullTable 				= $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;
	$jsonResult['data'] 	= [
								'usold' 	=> formatQty($tUsold),
								'discount' 	=> '<span class="text-muted text-lg">' . CURRENCY . '</span> ' . formatCurrentNumber($tDiscount),
								'count' 	=> formatQty($tCount),
								'total' 	=> '<span class="text-muted text-lg">' . CURRENCY . '</span> ' . formatCurrentNumber($tTotal)
							];

	$jsonResult['chart'] 	= ['labels' => $barLabel,'data' => $barData];

	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));
}

?>
	<? //maintaining();?>
	<?=menuReports('',true);?>
    
    <?php
    echo reportsDayAndTitle([
    							'title' 		=> '<div class="text-md text-right font-default">Ventas por</div> Usuarios <span class="text-muted">/</span> Recursos',
    							'maxDays' 		=> $MAX_DAYS_RANGE
    						]);
							    ?>
  	
  	<div class="col-xs-12 no-padder text-center">
  		<section class="col-sm-6 col-md-3">
            <div class="text-center wrapper-md">
				<div class="h1 m-t font-bold globalSales"><?=placeHolderLoader()?></div>
				Ventas
			</div>
        </section>
  		<section class="col-sm-6 col-md-3">
            <div class="text-center wrapper-md">
				<div class="h1 m-t font-bold globalQty"><?=placeHolderLoader()?></div>
				Cantidad
			</div>
        </section>
        <section class="col-sm-6 col-md-3">
            <div class="text-center wrapper-md">
				<div class="h1 m-t font-bold globalDiscount"><?=placeHolderLoader()?></div>
				Descuentos
			</div>
        </section>
        <section class="col-sm-6 col-md-3">
            <div class="text-center wrapper-md">
				<div class="h1 m-t font-bold globalTotal"><?=placeHolderLoader()?></div>
				Total
			</div>
        </section>
  	</div>

	<div class="col-xs-12 wrapper panel r-24x bg-white push-chat-down tableContainer">
	    <table class="table table1 table-hover col-xs-12 no-padder" id="tableUsers">
	    	<?=placeHolderLoader('table')?>
	    </table>
	    <?=footerPrint([]);?>
    </div>

<script>
var baseUrl = '<?=$baseUrl?>';
$(document).ready(function(){
	FastClick.attach(document.body);
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");

	var rawUrl 	= baseUrl + "?action=generalTable";
	var url 	= rawUrl;

	var xhr = ncmHelpers.load({
		url 		: url,
		httpType 	: 'GET',
		hideLoader 	: true,
		type 		: 'json',
		success 	: function(result){

			var options = {
							"container" 	: ".tableContainer",
							"url" 			: url,
							"rawUrl" 		: rawUrl,
							"iniData" 		: result.table,
							"table" 		: ".table1",
							"sort" 			: 0,
							"footerSumCol" 	: [1,2,3,4,5,6],
							"currency" 		: "<?=CURRENCY?>",
							"decimal" 		: decimal,
							"thousand" 		: thousandSeparator,
							"offset" 		: <?=$offsetDetail?>,
							"limit" 		: <?=$limitDetail?>,
							"nolimit" 		: false,
							"noMoreBtn" 	: true,
							"tableName" 	: 'tableUsers',
							"fileTitle" 	: 'Reporte de Usuarios',
							"ncmTools"		: {
												left 	: '',
												right 	: ''
											  },
							"colsFilter"	: {
												name 	: 'reportUsers',
												menu 	:  [
																{"index":0,"name":"Nombre","visible":true},
																{"index":1,"name":"Ventas","visible":true},
																{"index":2,"name":'Cantidad',"visible":false},
																{"index":3,"name":'Comisiones',"visible":true},
																{"index":4,"name":'Descuentos',"visible":false},
																{"index":5,"name":'Subtotal',"visible":false},
																{"index":6,"name":'Total',"visible":true},
																{"index":7,"name":'Acciones',"visible":true}
															]
											  },
							"clickCB" 		: function(event,tis){
											  	var target = tis.data('url');
												window.location = target;
					  						}
			};

			ncmDataTables(options,function(oTable){
				onClickWrap('.openLink',function(event,tis){
				    var url = tis.attr('href');
				    window.open(url,'_blank');
				},false,true);
			});

			$('.globalSales').text(result.data.count);
			$('.globalQty').text(result.data.usold);
			$('.globalDiscount').html(result.data.discount);
			$('.globalTotal').html(result.data.total);

			if(result.chart.data){
				$('#myChart').removeClass('hidden');
				$('#loadingChart').addClass('hidden');

				Chart.defaults.global.legend.display 		= false;
				Chart.defaults.global.responsive 			= true;
				Chart.defaults.global.maintainAspectRatio 	= false;

				var myChart = $('#myChart')[0].getContext("2d");

				var gradientStroke = myChart.createLinearGradient(300, 0, 100, 0);
				gradientStroke.addColorStop(0, "#4cb6cb");
				gradientStroke.addColorStop(1, "#54cfc7");

				var dataD = {
				    labels: result.chart.labels,
				    datasets: [
				        {
				        	label: "Total",
				            data: result.chart.data,
				            backgroundColor: gradientStroke
				        }]
				};

				setTimeout(function(){
					var methods = new Chart(myChart, {
					    type: 'bar',
					    data: dataD,
					    animation : true,
					    options: chartBarStackedGraphOptions
					});
				}, 200);
			}


		}

	});

	window.xhrs.push(xhr);


});



</script>

<?php
include_once('includes/compression_end.php');
dai();
?>