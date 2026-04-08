<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
theErrorHandler();//error handler

limitReportAccess();

list($calendar,$startDate,$endDate,$lessDays) = datesForGraphs(7);

$roc 			= getROC(1);
$isdata 		= true;

$table 			= '';
$limitDetail	= 2000;
$offsetDetail	= 0;
$table 			= '';
$jsonResult 	= [];

if(validateHttp('action') == 'generalTable'){

	$head 	= 	'<thead>' .
		  		'	<tr>' .
		    	'		<th>Fecha</th>' .
		    	'		<th>Título</th>' .
		    	'		<th>Descripción</th>' .
		    	'		<th>Sucursal</th>' .
		    	' 		<th>En Caja</th>' .
		    	' 		<th></th>' .
		    	'	</tr>' .
		  		'</thead>' .
		  		'<tbody>';

	$data 	= [
			    'api_key'     => API_KEY,
			    'company_id'  => enc(COMPANY_ID)
			  ];

	$result = curlContents(API_URL . '/get_notifications_all','POST',$data);

	if($result){
		$result 		= json_decode($result,true);
	  	
	  	foreach ($result as $key => $fields) {
	  		$delete		= '';
	    	$outletName = getCurrentOutletName($fields['outlet']);

	    	if($fields['mode'] == 1){
	    		$delete = 	'<a class="delete hidden-print" href="/report_notifications?action=delete&id=' . $fields['id'] . '">' . 
	    					'	<i class="text-danger material-icons">close</i>' . 
	    					'</a>';
	    	}

	    	if($fields['register']){
	    		$regIcon = '<i class="material-icons text-success">check</i>';
	    	}else{
	    		$regIcon = '-';
	    	}

	    	if($fields['link']){
	    		$message = '<a href="' . $fields['link'] . '" target="_blank">' . $fields['message'] . '</a>';
	    	}else{
	    		$message = $fields['message'];
	    	}

	    	$table .=	'<tr>' .
	         			'	<td data-order="' . $fields['date'] . '">' .
	         					niceDate($fields['date'],true) .
	         			'	</td>' .
	         			'	<td>' .
	         					$fields['title'] .
	         			'	</td>' .
	         			'	<td>' .
	         					$message .
	         			'	</td>' .
	         			'	<td>' .
	         					$outletName .
	         			'	</td>' .
	         			'	<td class="text-center">' .
	         					$regIcon .
	         			'	</td>' .
	         			'	<td class="text-center">' .
	         					$delete .
	         			'	</td>' .
	        			'</tr>';
		}
	}

	$foot = 	'</tbody>' .
				'<tfoot>' .
				'	<tr>' .
				'		<th colspan="6"></th>' .
				'	</tr>' .
				'</tfoot>';

	$fullTable = $head . $table . $foot;
	$jsonResult['table'] = $fullTable;

	header('Content-Type: application/json'); 
	dai(json_encode($jsonResult));
}

if(validateBool('action') == 'delete'){
	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'id' 			=> validateHttp('id')
			  ];

	$result = curlContents(API_URL . '/delete_notification','POST',$data);

	header('Content-Type: application/json'); 
	dai($result);
}

$sU = $_SESSION['user'];
if(isset($_SESSION['user']['companySettings'])){
	$_cmpSettings 	= $_SESSION['user']['companySettings'];
	print_r($_cmpSettings);
}

?>
<!DOCTYPE html>
<html class="no-js">
<head>
<!-- meta -->
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
<title>Notificaciones</title>

<?php
	loadCDNFiles([],'css');
?>

</head>
<body class="bg-light lter">
	<?=menuFrame('top');?>
	<?=menuReports('');?>
    
  	<div class="col-xs-12 m-t-sm m-b">
  		<?=headerPrint();?>
		<div class="pull-right">
			<h2 class="no-padder m-n font-bold">Notificaciones</h2>
		</div>
	</div>
  	
	<div class="col-xs-12 wrapper panel r-3x bg-white push-chat-down tableContainer table-responsive" style="min-height:500px">
	    <table class="table table1 col-xs-12 no-padder" id="tableTransactions">
	        <?=placeHolderLoader('table')?>
	    </table>
    </div>

	<?=menuFrame('bottom');?>

	<div class="modal fade" tabindex="-1" id="modalDetails" role="dialog">
	  <div class="modal-dialog">
	    <div class="modal-content r-3x no-bg no-border all-shadows">
	      <div class="modal-body wrapper">
	        
	      </div>
	    </div>
	  </div>
	</div>

	<?php
	footerInjector();
	loadCDNFiles([],'js');
	?>

	<script>
	$(document).ready(function(){

		FastClick.attach(document.body);		
		var url 	= "?action=generalTable";
		var rawUrl 	= url;
		$.get(url,function(result){

			var options = {
							"container" 	: ".tableContainer",
							"url" 			: url,
							"rawUrl" 		: rawUrl,
							"iniData" 		: result.table,
							"table" 		: ".table1",
							"sort" 			: 0,
							"currency" 		: "<?=CURRENCY?>",
							"decimal" 		: decimal,
							"thousand" 		: thousandSeparator,
							"offset" 		: <?=$offsetDetail?>,
							"limit" 		: <?=$limitDetail?>,
							"nolimit" 		: true,
							"noMoreBtn" 	: true,
							"ncmTools"		: {
												left 	: '<a href="#" class="btn btn-default exportTable" data-table="tableTransactions" data-name="Transacciones">Exportar Listado</a>',
												right 	: ''
											  }
			};

			manageTableLoad(options,function(oTable){
				loadTheTable(options,oTable);
			});
		});

		var loadTheTable = function(tableOps,oTable){
			onClickWrap('.delete',function(event,tis){
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
			});


		};
	});
	</script>

</body>
</html>
<?php
include_once('includes/compression_end.php');
dai();
?>