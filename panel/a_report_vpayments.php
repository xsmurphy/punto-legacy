<?php
include_once('includes/top_includes.php');
topHook();
allowUser('sales', 'view');

$MAX_DAYS_RANGE = 31;

$baseUrl = '/' . basename(__FILE__, '.php');

list($calendar, $startDate, $endDate, $lessDays) = datesForGraphs(7);

//DATE RANGE LIMITS FOR REPORTS
$maxDate = dateRangeLimits($startDate, $endDate, $MAX_DAYS_RANGE);
if (!$maxDate) {
	$startDate = date('Y-m-d 00:00:00', strtotime('-' . $MAX_DAYS_RANGE . ' days'));
}
//

$roc 					= getROC(1);
$isdata 			= true;

$table 				= '';
$limitDetail	= validateHttp('limit') ? validateHttp('limit') : 500;
$offsetDetail	= validateHttp('offset') ? validateHttp('offset') : 0;
$table 				= '';
$jsonResult 	= [];

if (validateHttp('action') == 'generalTable') {
	theErrorHandler('json');

	$head 	= 	'<thead>' .
		'	<tr>' .
		'		<th>Estado</th>' .
		'		<th>Fecha</th>' .
		'		<th>Fecha de Acreditación</th>' .
		'		<th>Cod. de Autorización</th>' .
		'		<th>Nro. de Operación</th>' .
		'		<th>Fuente</th>' .
		'		<th>Medio</th>' .
		'		<th>Sucursal</th>' .
		'		<th class="text-center">Total</th>' .
		'		<th class="text-center">Acreditar</th>' .
		'	</tr>' .
		'</thead>' .
		'<tbody>';

	$data 	= [
		'api_key'     	=> API_KEY,
		'company_id'  	=> enc(COMPANY_ID),
		'from' 					=> $startDate,
		'to' 						=> $endDate,
		'cache' 				=> 60
	];

	$result 				= curlContents(API_URL . '/get_vpayments', 'POST', $data);
	$totalApproved 	= 0;
	$totalDeposited	= 0;
	$totalSales 		= 0;
	$totalSold 			= 0;
	$result 				= json_decode($result, true);

	if (!empty($result['success']) && validity($result, 'array')) {
		$orderDetails = [];

		foreach ($result['success'] as $key => $fields) {
			if ($fields['status'] !== 'DENIED') {

				$statusName   		= 'Pendiente';
				$statusColor 			= 'light';

				if ($fields['deposited']) {
					$statusColor 		= 'info';
					$statusName 		= 'Acreditado';
					$totalDeposited += $fields['payoutAmount'];
				}

				if ($fields['status'] == 'REVIEW') {
					$statusName     = 'En Revisión';
				}

				$ePOSPayData 	= $fields['data'];
				$medio      	= '-';

				if (isset($ePOSPayData['account_type'])) {
					if ($ePOSPayData['account_type'] == 'TC') {
						$medio = 'T. Crédito';
					} else if ($ePOSPayData['account_type'] == 'TD') {
						$medio = 'T. Débito';
					} else if ($ePOSPayData['account_type'] == 'DC') {
						$medio = 'Débito';
					}
				} else if (isset($ePOSPayData['brand'])) {
					$medio = $ePOSPayData['brand'];
				}

				$source 					= '<span class="badge bg-light lter">Físico</span>';

				if (in_array($fields['source'], ['bancardQROnline', 'dinelcoVPOS'])) {
					$source 					= '<span class="badge bg-light lter">Online</span>';
				}

				if (in_array($fields['source'], ['bancardQR'])) {
					$source 					= '<span class="badge bg-light lter">QR</span>';
				}

				$pType       			= '<span class="badge bg-light lter">' . $medio . '</span>';

				$orderDetails[] 	= $fields['data'];

				$pDate 						= validity($fields['payoutDate']) ? date('Y-m-d', strtotime($fields['payoutDate'])) : '-';
				$dDate 						= validity($fields['depositedDate']) ? ($fields['depositedDate']) : '-';

				$invoiceUrl 			= '';

				if (strlen($fields['eUID']) > 3) {
					$invoiceUrl 		= '/a_report_transactions?action=edit&uid=' . $fields['eUID'] . '&ro=1';
				}

				$table 						.=	'<tr data-load="' . $invoiceUrl . '" class="clickrow pointer">' .
					'	<td>' .
					' 		<span class="label bg-' . $statusColor . ' lter text-u-c">' . $statusName . '</span>' .
					'	</td>' .
					'	<td data-order="' . $fields['date'] . '">' .
					$fields['date'] .
					'	</td>' .
					'	<td data-order="' . $fields['payoutDate'] . '">' .
					$pDate .
					'	</td>' .
					'	<td>' .
					$fields['authCode'] .
					'	</td>' .
					'	<td>' .
					$fields['operationNo'] .
					'	</td>' .
					'	<td>' .
					$source .
					'	</td>' .
					'	<td>' .
					$pType .
					'	</td>' .
					'	<td>' .
					$fields['outletName'] .
					'	</td>' .
					'	<td class="text-right" data-order="' . $fields['amount'] . '" data-format="money">' .
					formatCurrentNumber($fields['amount']) .
					'	</td>' .
					'	<td class="text-right" data-order="' . $fields['payoutAmount'] . '" data-format="money">' .
					formatCurrentNumber($fields['payoutAmount']) .
					'	</td>' .
					'</tr>';

				$totalSales++;
				$totalSold 			+= $fields['amount'];
				$totalApproved 	+= $fields['payoutAmount'];

				if (validateHttp('part') && !validateHttp('singleRow')) {
					$table .= '[@]';
				}
			}
		}
	}

	$foot = 	'</tbody>' .
		'<tfoot>' .
		'	<tr>' .
		'		<th colspan="8" class="font-bold">Total</th>' .
		'		<th class="font-bold text-right"></th>' .
		'		<th class="font-bold text-right"></th>' .
		'	</tr>' .
		'</tfoot>';

	if (validateHttp('part')) {
		jsonDieResult($table);
	} else {
		$fullTable 							= $head . $table . $foot;
		$jsonResult['table'] 		= $fullTable;
		$jsonResult['details'] 	= 	[
			'approvedR' 				=> $totalSold, //vendido
			'depositedR' 				=> $totalDeposited, //acreditado
			'pendingDepositR' 	=> ($totalApproved - $totalDeposited), //pendiente
			'approved' 					=> formatCurrentNumber($totalSold),
			'deposited' 				=> formatCurrentNumber($totalDeposited),
			'pendingDeposit' 		=> formatCurrentNumber($totalApproved - $totalDeposited),
			'totalSales' 				=> formatCurrentNumber($totalSales)
		];


		jsonDieResult($jsonResult);
	}
}

?>

<?= menuReports(''); ?>

<?php
echo reportsDayAndTitle([
	'title' 		=> '<div class="text-md text-right font-default">Pagos</div> ePOS',
	'maxDays' 		=> $MAX_DAYS_RANGE,
	'hideChart' 	=> true
]);
?>

<div class="col-xs-12 no-padder text-center hidden-print">

	<div class="col-md-4 col-sm-12 col-xs-12 text-center no-padder">
		<canvas id="chart-contado" class="" height="200" style="max-height:200px;"></canvas>
		<div class="donut-inner" style=" margin-top: -140px; margin-bottom: 100px;">
			<div class="h1 m-t creditoCount font-bold totalsChart"><?= placeHolderLoader() ?></div>
			<span>Ventas</span>
		</div>
		<div class="m-t-n h4">&nbsp;</div>
	</div>


	<div class="col-md-8 col-sm-12 col-xs-12 no-padder hidden-print">

		<div class="col-xs-12 no-padder text-center font-bold h4 m-b">
			Resumen del periodo
		</div>

		<section class="col-md-4 col-sm-6 col-xs-12">
			<div class="b-b text-center wrapper-md">
				<div class="h1 m-b-xs m-t total font-bold approvedChart"><?= placeHolderLoader() ?></div>
				Vendido
			</div>
		</section>
		<section class="col-md-4 col-sm-6 col-xs-12">
			<div class="b-b text-center wrapper-md">
				<div class="h1 m-b-xs m-t total font-bold depositedChart"><?= placeHolderLoader() ?></div>
				Acreditado
			</div>
		</section>
		<section class="col-md-4 col-sm-6 col-xs-12">
			<div class="b-b text-center wrapper-md">
				<div class="h1 m-b-xs m-t total font-bold pendingDepositChart"><?= placeHolderLoader() ?></div>
				Pendiente
			</div>
		</section>

	</div>
</div>

<div class="col-xs-12 no-padder m-t m-b-lg push-chat-down">
	<section class="col-sm-12 no-padder" id="reportsTablesAndTabs">

		<section class="panel r-24x">
			<div class="panel-body">

				<div id="generalTable">
					<table class="table table1 col-xs-12 no-padder" id="tableVPayments"><?= placeHolderLoader('table') ?></table>
				</div>

			</div>
		</section>
	</section>
</div>

<script>
	$(document).ready(function() {
		var baseUrl = '<?= $baseUrl ?>';
		var url = baseUrl + '?action=generalTable';
		var offset = <?= $offsetDetail ?>;
		var limit = <?= $limitDetail ?>;
		var currency = '<?= CURRENCY ?>';
		var startD = '<?= $startDate ?>';
		var endD = '<?= $endDate ?>';

		dateRangePickerForReports(startD, endD);
		FastClick.attach(document.body);

		$.get(url, (result) => {

			var options = {
				"container": "#generalTable",
				"url": url,
				"iniData": result.table,
				"table": ".table1",
				"sort": 1,
				"footerSumCol": [8, 9],
				"currency": currency,
				"decimal": decimal,
				"thousand": thousandSeparator,
				"offset": offset,
				"limit": limit,
				"nolimit": false,
				"noMoreBtn": false,
				"tableName": 'tableVPayments',
				"fileTitle": 'Listado ePOS',
				"ncmTools": {
					left: '',
					right: ''
				},
				"colsFilter": {
					name: 'vPayments7',
					menu: [{
							"index": 0,
							"name": 'Estado',
							"visible": true
						},
						{
							"index": 1,
							"name": 'Fecha',
							"visible": true
						},
						{
							"index": 2,
							"name": 'Fecha de Acreditación',
							"visible": false
						},
						{
							"index": 3,
							"name": 'Cod. Autorización',
							"visible": true
						},
						{
							"index": 4,
							"name": 'Nro. Operación',
							"visible": false
						},
						{
							"index": 5,
							"name": 'Fuente',
							"visible": false
						},
						{
							"index": 6,
							"name": 'Medio',
							"visible": true
						},
						{
							"index": 7,
							"name": 'Sucursal',
							"visible": false
						},
						{
							"index": 8,
							"name": 'Venta',
							"visible": true
						},
						{
							"index": 9,
							"name": 'Acreditación',
							"visible": false
						}
					]
				},
				"clickCB": (event, tis) => {
					var load = tis.data('load');
					loadForm(load, '#modalLarge .modal-content', () => {
						$('#modalLarge').modal('show');
					});
				}
			};

			ncmDataTables(options, (oTable) => {});

			$('.depositedChart').text(result.details.deposited);
			$('.approvedChart').text(result.details.approved);
			$('.pendingDepositChart').text(result.details.pendingDeposit);
			$('.totalsChart').text(result.details.totalSales);

			Chart.defaults.global.responsive = true;
			Chart.defaults.global.maintainAspectRatio = false;
			Chart.defaults.global.legend.display = false;

			var chartContado = document.getElementById('chart-contado').getContext("2d");

			var methods = new Chart(chartContado, {
				type: 'doughnut',
				data: {
					labels: ['Vendido', 'Acreditado', 'Pendiente'],
					datasets: [{
						data: [result.details.approvedR, result.details.depositedR, result.details.pendingDepositR],
						backgroundColor: ['#6BC0D1', '#778490', '#d9e4e6']
					}]
				},
				animation: true,
				options: {
					cutoutPercentage: 85
				}
			});

		});

	});
</script>

<?php
include_once('includes/compression_end.php');
dai();
?>