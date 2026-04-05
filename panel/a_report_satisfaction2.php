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

$MAX_DAYS_RANGE = 31;
$baseUrl = '/' . basename(__FILE__,'.php');

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate,$endDate,$MAX_DAYS_RANGE);
if(!$maxDate){
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

$roc 				= getROC(1);
$limitDetail		= 100;
$offsetDetail		= 0;
$jsonResult 		= [];
$table 				= '';

if(validateHttp('action') == 'delete' && validateHttp('id')){
	$delete = $db->Execute('DELETE FROM satisfaction WHERE satisfactionId = ? LIMIT 1', array(dec($_GET['id']))); 
	if($delete === false){
		echo 'false';
	}else{
		echo 'true';
	}
	dai();
}

if(validateHttp('action') == 'generalTable'){

	$table 		= '';
	$badChart 	= 0;
	$medChart 	= 0;
	$goodChart 	= 0;

	$result 	= ncmExecute("SELECT 
								*
							FROM
								satisfaction 
							WHERE 
							 satisfactionDate BETWEEN ? AND ? 
							" . $roc,
							[$startDate,$endDate],false,true);

	if($_GET['debug']){
		echo "SELECT 
					*
				FROM
					satisfaction 
				WHERE 
				 satisfactionDate BETWEEN " . $startDate . " AND " . $endDate . " 
				" . $roc;
	}

	
	
		$head = '<thead class="text-u-c">'.
				'	<tr>'.
				'		<th>Nivel</th>'.
				'		<th>Fecha</th>'.
				'		<th>Cliente</th>'.
				'		<th>Comentario</th>'.
				'		<th>Sucursal</th>'.
				'		<th></th>'.
				'	</tr>'.
				'</thead>'.
				'<tbody>';

	if($result){
		$label 		= '';
		$data 		= '';
		$outlets 	= getAllOutlets();
		while (!$result->EOF) {
			$fields 	= $result->fields;
			$date 		= timeago($fields['satisfactionDate'],false);
			$type		= $fields['satisfactionLevel'];
			$note		= unXss($fields['satisfactionComment']);
			$parentSale	= enc($fields['transactionId']);
			$outlet 	= $outlets[$fields['outletId']]['name'];

			$customerData   = getCustomerData($fields['customerId'], 'uid');

			if($type == 1){
				$bg = 'danger';
				$badChart++;
				$name = 'Malo';
			}else if($type == 2){
				$bg = 'warning';
				$medChart++;
				$name = 'Bueno';
			}else{
				$bg = 'success';
				$goodChart++;
				$name = 'Excelente';
			}

			$table .= 	'<tr class="clickrow pointer no-border b-l b-5x b-' . $bg . '" data-id="'.$parentSale.'" data-load="/a_report_transactions?action=edit&id='.$parentSale.'&ro=true">'.
						' 	<td data-order="'.$type.'">' .
						' 		<span class="label bg-light">' . $name . ' </span>' .
						'	</td>' .
						'	<td data-order="'.$fields['satisfactionDate'].'">' .
						' 		<span class="label bg-light" data-toggle="tooltip" data-placement="top" title="'.$fields['satisfactionDate'].'"> Hace '.$date.' </span>' .
						'	</td>' .
						'	<td> '.$customerData['name'].' </td>' .
						'	<td> '.$note.' </td>' .
						'	<td> '.$outlet.' </td>' .
						'	<td class="text-right">' .
						' 		<a href="' . $baseUrl . '?action=delete&id='.enc($fields['satisfactionId']).'" class="deleteItem"><i class="text-danger material-icons">close</i></a>' .
						' 	</td>' .
						'</tr>';
			
			$result->MoveNext();	
		}

	}
		
		$foot = 	'</tbody>' .
					'<tfoot>' .
					'	<tr>' .
					' 		<td colspan="6"></td>' .
					'	</tr>' . 
					'</tfoot>';

	

	$totalC 				= $badChart + $medChart + $goodChart;

	$badChartP 				= round( divider($badChart,$totalC,true) * 100 );
	$medChartP 				= round( divider($medChart,$totalC,true) * 100 );//round(@($medChart/$totalC)*100);
	$goodChartP 			= round( divider($goodChart,$totalC,true) * 100 );//round(@($goodChart/$totalC)*100);

	$fullTable 				= $head . $table . $foot;
	$jsonResult['table'] 	= $fullTable;
	$jsonResult['chart'] 	= ['bad'=>$badChart,'med'=>$medChart,'good'=>$goodChart];
	$jsonResult['chartpercent'] = ['bad'=>$badChartP,'med'=>$medChartP,'good'=>$goodChartP];
	$jsonResult['detractors'] 	= ['count' => $badChart, 'percent' => $badChartP];
	$jsonResult['passives'] 	= ['count' => $medChart, 'percent' => $medChartP];
	$jsonResult['promoters'] 	= ['count' => $goodChart, 'percent' => $goodChartP];



	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));
}
?>
	<?=menuReports();?>

  	<?php
    echo reportsDayAndTitle([
    							'title' 		=> '<div class="text-md text-right font-default">Reporte de</div> Satisfacción (NPS)',
    							'maxDays' 		=> $MAX_DAYS_RANGE,
    							'hideChart' 	=> true
    						]);
    ?>

  	<div class="col-xs-12 no-padder text-center m-b-lg">

		<div class="col-xs-12 no-bg no-padder" id="customerSatisfactionLevel">
			<div class="progress progress-xs dker rounded progress-striped m-n"> 
			  <div class="progress-bar gradBgRed satisfactionBarDetractors" data-toggle="tooltip" data-original-title="0%" style="width: 0%"></div> 

			  <div class="progress-bar gradBgYellow satisfactionBarPassives" data-toggle="tooltip" data-original-title="0%" style="width: 0%"></div> 

			  <div class="progress-bar gradBgGreen satisfactionBarPromoters" data-toggle="tooltip" data-original-title="0%" style="width: 0%"></div> 

			</div>
		</div>

  		<div class="col-xs-4 no-padder">
	        <div class="h2 m-t creditoCount font-bold" id="badP"><?=placeHolderLoader()?></div>
	        <span>Malo (Detractores)</span>
	        <div class="m-t h4"><span id="badC"><?=placeHolderLoader()?></span> voto(s)</div>
  		</div>
  		<div class="col-xs-4 no-padder">
	        <div class="h2 m-t creditoCount font-bold" id="medP"><?=placeHolderLoader()?></div>
	        <span>Bueno (Pasivos)</span>
	        <div class="m-t h4"><span id="medC"><?=placeHolderLoader()?></span> voto(s)</div>
  		</div>
  		<div class="col-xs-4 no-padder">
	        <div class="h2 m-t creditoCount font-bold" id="goodP"><?=placeHolderLoader()?></div>
	        <span>Excelente (Promotores)</span>
	        <div class="m-t h4"><span id="goodC"><?=placeHolderLoader()?></span> voto(s)</div>
  		</div>
  	</div>

	<div class="col-xs-12 wrapper panel push-chat-down r-24x">
        <table class="table table-hover table1 col-xs-12 no-padder" id="tableTransactions"><?=placeHolderLoader('table')?></table>
    </div>

<script>
<?php
if($_GET['update']){
	ob_start();
?>

var baseUrl = '<?=$baseUrl?>';
$(document).ready(function(){
	FastClick.attach(document.body);
	dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");

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
						"currency" 		: "<?=CURRENCY?>",
						"decimal" 		: decimal,
						"thousand" 		: thousandSeparator,
						"offset" 		: <?=$offsetDetail?>,
						"limit" 		: <?=$limitDetail?>,
						"ncmTools"		: {
											left 	: '<a href="#" class="btn btn-default exportTable" data-table="tableTransactions" data-name="Satisfaccion">Exportar Listado</a>',
											right 	: ''
										  }
		};

		manageTableLoad(options,function(oTable){
			loadTheTable(options,oTable);
		});

		$('#badP').text(result.chartpercent.bad + '%');
		$('#badC').text(result.chart.bad);

		$('#medP').text(result.chartpercent.med + '%');
		$('#medC').text(result.chart.med);

		$('#goodP').text(result.chartpercent.good + '%');
		$('#goodC').text(result.chart.good);

		//charts(result.chart,result.chartpercent);
	});

	if(isMobile.phone){
		$('.progress-xs').height('30px');
	}else{
		$('.progress-xs').height('100px');
	}

	var xhr = $.get('a_dashboard?widget=satisfaction',function(result){
      $('.satisfactionBarDetractors').attr('data-original-title', 'Detractores: ' + result.detractors.count + ' voto(s)');
      $('.satisfactionBarDetractors').addClass(result.detractors.percent).css('width',result.detractors.percent + '%');

      $('.satisfactionBarPassives').attr('data-original-title', 'Pasivos: ' + result.passives.count + ' voto(s)');
      $('.satisfactionBarPassives').addClass(result.passives.percent).css('width',result.passives.percent + '%');

      $('.satisfactionBarPromoters').attr('data-original-title', 'Promotores: ' + result.promoters.count + ' voto(s)');
      $('.satisfactionBarPromoters').addClass(result.promoters.percent).css('width',result.promoters.percent + '%');
    });

    window.xhrs.push(xhr);

	var loadTheTable = function(tableOps,oTable){
		$('[data-toggle="tooltip"]').tooltip();
		onClickWrap('.deleteItem',function(event,tis){
			$('table tbody tr').removeClass('editting');
			tis.closest('tr').addClass('editting');

			ncmDialogs.confirm("Realmente desea eliminar?",'','question',function(r){
				if (r == true) {
				    var target = tis.attr('href');
					$.get(target, function(response) {
						if(response == 'true'){
							$('.editting').remove();
							message('Dato eliminado','success');
							manageTable(info1);
						}else{
							message('Error al eliminar','danger');
						}
					});
				}
			});
			
		},false,true);

		onClickWrap('.clickrow',function(event,tis){
			var load = tis.data('load');
			loadForm(load,'#modalLarge .modal-content',function(){
				$('#modalLarge').modal('show');
			});
		},false,true);
	};

	var charts = function(chartCount,chartPercent){

		Chart.defaults.global.responsive           = true;
        Chart.defaults.global.maintainAspectRatio  = false;
        Chart.defaults.global.legend.display       = false;
        Chart.defaults.global.tooltips.enabled 	   = false;
        Chart.defaults.global.elements.arc.borderWidth 	= 0;

		var badL 	= (chartPercent.bad < 1) ? 100 : (100 - chartPercent.bad);
		var medL 	= (chartPercent.med < 1) ? 100 : (100 - chartPercent.med);
		var goodL 	= (chartPercent.good < 1) ? 100 : (100 - chartPercent.good);

		var secondColor = (ncmUI.setDarkMode.isSet ? '#3b464d' : '#e8eff0');

		var data1 = {
			labels: [],
		    datasets: [{
		            data: [badL,chartPercent.bad],
		            backgroundColor: [
		                secondColor,'#f06a6a'
		            ],
		            hoverBackgroundColor: [secondColor, "#f06a6a"]
		        }]
		};
		var data2 = {
			labels: [],
		    datasets: [{
		            data: [medL,chartPercent.med],
		            backgroundColor: [
		                secondColor,'#f7de69'
		            ],
		            hoverBackgroundColor: [secondColor, "#f7de69"]
		        }]
		};
		var data3 = {
			labels: [],
		    datasets: [{
		            data: [goodL,chartPercent.good],
		            backgroundColor: [
		                secondColor,'#20c974'
		            ],
		            hoverBackgroundColor: [secondColor, "#20c974"]
		        }]
		};
		
		var bad 	= new Chart($('#bad'), {
		    type: 'doughnut',
		    data: data1,
	        segmentShowStroke: false,
	        animation : true,
            options   : {
              cutoutPercentage:85
            }
		});

		setTimeout(function(){
			var medium 	= new Chart($('#medium'), {
			    type: 'doughnut',
			    data: data2,
		        segmentShowStroke: false,
		        animation : true,
	            options   : {
	              cutoutPercentage:85
	            }
			});
		}, 300);

		setTimeout(function(){
			var good 	= new Chart($('#good'), {
			    type: 'doughnut',
			    data: data3,
		        segmentShowStroke: false,
		        animation : true,
	            options   : {
	              cutoutPercentage:85
	            }
			});

			Chart.defaults.global.tooltips.enabled 	   		= true;
			Chart.defaults.global.elements.arc.borderWidth 	= 2;
		}, 500);
		
	};

});

<?php
	$script = ob_gets_contents();

	minifyJS([$script => 'scripts' . $baseUrl . '.js']);
}
?>
</script>
<script src="scripts<?=$baseUrl?>.js?<?=date('d.i')?>"></script>

</body>
</html>
<?php
include_once('includes/compression_end.php');
dai();
?>