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

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 			= getROC(1);
$isdata 		= true;

$table 			= '';
$limitDetail	= validateHttp('limit') ? validateHttp('limit') : 100;
$offsetDetail	= validateHttp('offset') ? validateHttp('offset') : 0;
$userId 		= validateHttp('ui');
$table 			= '';
$jsonResult 	= [];

if($userId){
	$user 		= getContactData(dec($userId));
	$USER_NAME 	= $user['name'];
}

if(validateHttp('action') == 'generalTable' && validateHttp('ui')){
	theErrorHandler('json');

	$head 	= 	'<thead>' .
		  		'	<tr>' .
		  		'		<th>Fecha de ingreso</th>' .
		  		'		<th>Hora de ingreso</th>' .
		    	'		<th>Fecha de salida</th>' .
		    	'		<th>Hora de salida</th>' .
		    	'		<th class="text-center">Horas</th>' .
		    	'		<th class="text-center">Minutos</th>' .
		    	'		<th class="text-center">Salario</th>' .
		    	' 		<th class="noxls"></th>' .
		    	'	</tr>' .
		  		'</thead>' .
		  		'<tbody>';

	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'from' 			=> $startDate,
			    'to' 			=> $endDate,
			    'limit' 		=> $limitDetail,
			    'offset' 		=> $offsetDetail,
			    'outlet' 		=> enc(OUTLET_ID),
			    'user' 			=> $userId
			  ];

	$result = curlContents('https://api.encom.app/get_attendance','POST',$data);
	

	if($_GET['debug']){
		print_r([$data,$result]);
		die();
	}

	$result = json_decode($result,true);

	if(!$result['error'] && validity($result,'array')){
		$orderDetails = [];
	
	  	foreach ($result as $key => $fields) {
	  		
	    	$name 		= $user['name'];
	    	$in     	= niceDate($fields['in']);
	    	$out     	= niceDate($fields['out']);
	    	$hours 		= $fields['hours'];
	    	$minutes	= $fields['minutes'];

	    	$inH 		= date('H:i',strtotime($fields['in']));
	    	$outH 		= date('H:i',strtotime($fields['out']));

	    	$table .=	'<tr>' .
	    				'	<td data-order="' . $fields['in'] . '">' .
	         					$in .
	         			'	</td>' .
	         			'	<td>' .
	         					$inH .
	         			'	</td>' .
	         			'	<td data-order="' . $fields['out'] . '">' .
	         					$out .
	         			'	</td>' .
	         			'	<td>' .
	         					$outH .
	         			'	</td>' .
	         			'	<td class="text-right bg-light lter tdNumeric" data-order="' . $hours . '">' .
	         					$hours .
	         			'	</td>' .
	         			'	<td class="text-right bg-light lter tdNumeric" data-order="' . $minutes . '">' .
	         					$minutes .
	         			'	</td>' .
	         			'	<td class="text-right bg-light lter tdNumeric" data-order="' . ($fields['hourSalary'] * $hours) . '" data-format="money">' .
	         					formatCurrentNumber($fields['hourSalary'] * $hours) .
	         			'	</td>' .
	         			'	<td class="text-center noxls">' .
	         			'		<a class="delete hidden-print" href="/a_report_working_hours?action=delete&id=' . $fields['id'] . '">' . 
    					'			<i class="text-danger material-icons">close</i>' . 
    					'		</a>' .
	         			'	</td>' .
	        			'</tr>';

	        if(validateHttp('part') && !validateHttp('singleRow')){
	        	$table .= '[@]';
	        }
		}
	}

	$foot = 	'</tbody>' .
				'<tfoot>' .
				'	<tr>' .
				'		<th>TOTALES</th>' .
				'		<th></th>' .
				'		<th></th>' .
				'		<th></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="text-right"></th>' .
				'		<th class="noxls"></th>' .
				'	</tr>' .
				'</tfoot>';

	if(validateHttp('part')){
		jsonDieResult($table);
	}else{
		$fullTable 				= $head . $table . $foot;
		$jsonResult['table'] 	= $fullTable;

		jsonDieResult($jsonResult);
	}
}

if(validateHttp('action') == 'delete'){
	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'id' 			=> validateHttp('id')
			  ];

	$result = curlContents('https://api.encom.app/delete_attendance','POST',$data);

	header('Content-Type: application/json'); 
	dai($result);
}

?>

	<?=menuReports('');?>
    
  	<?=reportsTitle('<div class="text-md text-right font-default">Horas trabajadas de</div> ' . $USER_NAME, true);?>

  	<div class="col-xs-12 no-padder m-t m-b-lg b-t push-chat-down">
	    <section class="col-sm-12 no-padder" id="reportsTablesAndTabs">
	        
	        <section class="panel r-24x">
	            <div class="panel-body">
	                <div id="generalTable">                             	
                    	<table class="table table1 col-xs-12 no-padder" id="tableWorkingHours"><?=placeHolderLoader('table')?></table>
                    </div>
	            </div>
	        </section>
	    </section>
	</div>

	<script>
	$(document).ready(function(){
		var baseUrl 	= '<?=$baseUrl?>';
		var currency 	= '<?=CURRENCY?>';
		var userName 	= '<?=$USER_NAME?>';

		dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");
		FastClick.attach(document.body);

		var url 	= baseUrl + "?action=generalTable&ui=<?=validateHttp('ui')?>";
		var rawUrl 	= url;
		$.get(url,function(result){

			var options = {
					"container" 	: "#generalTable",
					"url" 			: url,
					"iniData" 		: result.table,
					"table" 		: ".table1",
					"sort" 			: 0,
					"footerSumCol" 	: [4,5,6],
					"currency" 		: currency,
					"decimal" 		: decimal,
					"thousand" 		: thousandSeparator,
					"noMoreBtn" 	: false,
					"tableName" 	: 'tableWorkingHours',
					"fileTitle" 	: 'Horas trabajadas de ' + userName,
					"ncmTools"		: 	{
											left 	: '',
											right 	: ''
										},
					"colsFilter"	: 	{
											name 		: 'tableWorkingHours1',
											menu 		:  [
																{"index":0,"name":'Fecha de ingreso',"visible":true},
																{"index":1,"name":'Hora de ingreso',"visible":true},
																{"index":2,"name":'Fecha de salida',"visible":true},
																{"index":3,"name":'Hora de salida',"visible":true},
																{"index":4,"name":'Total de Horas',"visible":true},
																{"index":5,"name":'Total de Minutos',"visible":true},
																{"index":6,"name":'Salario',"visible":true},
																{"index":7,"name":'Acciones',"visible":false}
															]
										},
					"clickCB" 		: function(event,tis){ }
			};

			ncmDataTables(options,function(oTable){
				loadTheTable(options,oTable);
			});
			
		});

		var loadTheTable = function(tableOps,oTable){

			onClickWrap('.delete',function(event,tis){

				ncmDialogs.confirm("¿Realmente quiere eliminar?",'','question',function(really){
					if(really){
						var load 	= tis.attr('href');
						var $row  	= tis.closest('tr');
						oTable.row($row).remove().draw();
						$.get(load,function(result){
							if(result.success){
								message('Eliminado','success');
							}else{
								message('No se pudo eliminar','danger');
							}
						});
					}
				});
				
			});

			ncmHelpers.defaultEvents();

		};
	});
	</script>

<?php
include_once('includes/compression_end.php');
dai();
?>