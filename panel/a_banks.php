<?php
include_once('includes/top_includes.php');
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

$roc 			= getROC(1);
$isdata 		= true;

$table 			= '';
$limitDetail	= validateHttp('limit') ? validateHttp('limit') : 500;
$offsetDetail	= validateHttp('offset') ? validateHttp('offset') : 0 ;
$table 			= '';
$jsonResult 	= [];

if(validateHttp('action') == 'list'){
	theErrorHandler('json');

	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID)
			  ];

	$result = curlContents(API_URL . '/get_banks','POST',$data);
	
	http_response_code(200);
	header('Content-Type: application/json');
	dai($result);
}

if(validateHttp('action') == 'delete' && validateHttp('id')){
	if(!allowUser('sales','delete',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$id 	= dec(validateHttp('id'));

	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'ID' 			=> validateHttp('id')
			  ];

	$result = curlContents(API_URL . '/delete_bank','POST',$data);
	
	header('Content-Type: application/json'); 
	dai($result);
}

if(validateHttp('action') == 'view' && validateHttp('id')){
	if(!allowUser('sales','delete',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$id 	= dec(validateHttp('id'));

	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'ID' 			=> validateHttp('id')
			  ];

	$result = curlContents(API_URL . '/get_banks','POST',$data);
	
	header('Content-Type: application/json'); 
	dai($result);
}

if(validateHttp('action') == 'add' && validateHttp('id')){
	if(!allowUser('sales','add',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$id 	= dec(validateHttp('id'));

	$data 	= [
			    'api_key'     	=> API_KEY,
			    'company_id'  	=> enc(COMPANY_ID),
			    'data' 			=> json_decode(validateHttp('data'), true)
			  ];

	$result = curlContents(API_URL . '/add_bank','POST',$data);
	
	header('Content-Type: application/json'); 
	dai($result);
}

?>

	<?=menuReports('');?>

	<div class="col-xs-12 h1 font-bold m-b" id="pageTitle">
		Bancos
		<a href="#" id="addBank" class="btn btn-info font-bold text-u-c text-white pull-right btn-rounded">Agregar</a>
	</div>

  	<div class="col-xs-12 no-padder m-t m-b-lg push-chat-down">
	    <section class="col-xs-12 no-padder" id="reportsTablesAndTabs">
	        
	    </section>
	</div>

	<script type="text/html" id="bankBlockTpl">
		{{#data}}
		<div class="col-md-4 col-sm-6 col-xs-12 wrapper ">
		  <div class="col-xs-12 no-padder r-24x clear bg-white" style="min-height:180px;">
		    <div class="text-left wrapper col-xs-12 b-b">
				<div class="btn-group m-r-xs pull-right"> 
					<button class="btn btn-info btn-rounded bg-info dk dropdown-toggle" data-toggle="dropdown">
						<span class="m-r-sm font-bold text-u-c">Crear</span><span class="caret"></span>
					</button> 
					<ul class="dropdown-menu"> 
						<li class="create" data-type="<?=enc(0);?>"><a href="#" class="editBank" data-id="{{ID}}">Editar</a></li> 
						<li class="create" data-type="<?=enc(1);?>"><a href="#">Detalle</a></li> 
					</ul>  
				</div>

		     	<div class="m-t h1 font-bold col-xs-12 no-padder text-dark">
		      		<div class="text-md font-default">{{name}}</div>
		      		{{fBalance}}
		     	</div>
		    </div>
		    <div class="col-md-9 col-xs-12 wrapper">{{description}}</div>
		    <div class="col-md-3 col-xs-12 wrapper text-right">
		      
		    </div>
		    <div id="ecomModal" class="hidden" data-load="/a_modules?action=loadModal&amp;type=ecom"></div>
		  </div>
		</div>
		{{/data}}
	</script>

	<script type="text/html" id="bankAddTpl">
		<div class="modal-body modal-body no-padder clear r-24x">

			<div class="col-xs-12 no-padder bg-white">
				<div class="wrapper-md font-bold text-center h2">{{title}}</div>
				<div class="text-md wrapper-md">
					<div class="font-bold text-u-c text-xs">Nombre</div>
					<input type="text" name="bankName" class="form-control no-bg no-border b-b m-b" placeholder="Banco Nacional" value="{{name}}">

					<div class="font-bold text-u-c text-xs">Balance</div>
					<input type="text" name="bankBalance" class="form-control no-bg no-border b-b text-right maskInteger" placeholder="1.000.000" value="{{fBalance}}">
				</div>
			</div>
			<div class="col-xs-12 wrapper bg-light lt text-right text-center">
				<a href="#" class="btn btn-info pull-left btn-rounded btn-lg font-bold text-u-c" data-id="{{ID}}">{{btn}}</a>
			</div>
			
		</div>
	</script>

	<script>
	$(document).ready(function(){
		var baseUrl 		= '<?=$baseUrl?>';
		var decimal 		= '<?=DECIMAL?>';
		var thouSeparator 	= '<?=THOUSAND_SEPARATOR?>';
		var currency 		= '<?=CURRENCY?>';
		dateRangePickerForReports("<?=$startDate?>","<?=$endDate?>");
		FastClick.attach(document.body);		
		var url 	= baseUrl + "?action=list";
		var rawUrl 	= url;
		$.get(url, (result) => {

			$.each(result,function(i, val){
				val.fBalance = formatNumber(val.balance,currency,decimal,thouSeparator);
			});

			ncmHelpers.mustacheIt($('#bankBlockTpl'), {data : result}, $('#reportsTablesAndTabs'));
		});

		ncmHelpers.onClickWrap('#addBank', (e, tis) => {
			ncmHelpers.mustacheIt($('#bankAddTpl'), {title : 'Agregar cuenta', btn : 'Agregar'}, $('#modalTiny .modal-content'));
			$('#modalTiny').modal('show');
			$('#modalTiny').off('shown.bs.modal').on('shown.bs.modal', function () {
				masksCurrency($('.maskInteger'),thouSeparator,'no');
			});
		});

		ncmHelpers.onClickWrap('.editBank', (e, tis) => {
			var ID = tis.data('id');
			$.get(baseUrl + '?action=view&id=' + ID, (result) => {
				result.fBalance 	= formatNumber(result.balance,currency,decimal,thouSeparator);
				result.title 		= 'Editar ' + result.name;
				result.btn 			= 'Guardar';

				ncmHelpers.mustacheIt($('#bankAddTpl'), result, $('#modalTiny .modal-content'));
				$('#modalTiny').modal('show');
				$('#modalTiny').off('shown.bs.modal').on('shown.bs.modal', function () {
					masksCurrency($('.maskInteger'),thouSeparator,'no');
				});
			});
		});
		
	});
	</script>

<?php
include_once('includes/compression_end.php');
dai();
?>