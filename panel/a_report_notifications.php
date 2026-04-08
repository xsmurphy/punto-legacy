<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/" . LANGUAGE . ".php");
include_once("includes/functions.php");
theErrorHandler(); //error handler

$baseUrl = '/' . basename(__FILE__, '.php');

list($calendar, $startDate, $endDate, $lessDays) = datesForGraphs(7);

$roc 			= getROC(1);
$isdata 		= true;

$table 			= '';
$limitDetail	= 2000;
$offsetDetail	= 0;
$table 			= '';
$jsonResult 	= [];

if (validateHttp('action') == 'generalTable') {

	$head 	= 	'<tbody>';

	$data 	= [
		'api_key'     => API_KEY,
		'company_id'  => enc(COMPANY_ID)
	];

	$result = curlContents(API_URL . '/get_notifications_all', 'POST', $data);

	if ($result) {
		$result 		= json_decode($result, true);

		foreach ($result as $key => $fields) {
			$delete		= '';

			if ($fields['mode'] == 1) {
				$delete = 	'<a class="delete hidden-print" href="' . $baseUrl . '?action=delete&id=' . $fields['id'] . '">' .
					'	<i class="text-danger material-icons">close</i>' .
					'</a>';
			}

			if ($fields['register']) {
				$regIcon = '<span class="badge">En Caja <i class="material-icons text-success">check</i></span>';
			} else {
				$regIcon = '';
			}

			if (OUTLETS_COUNT > 1) {
				$outletName = '<span class="badge">' . getCurrentOutletName($fields['outlet']) . '</span> ';
			} else {
				$outletName = '';
			}

			if ($fields['link']) {
				$message = '<a href="' . $fields['link'] . '" target="_blank">' . $fields['message'] . '</a>';
			} else {
				$message = $fields['message'];
			}

			$table .=	'<tr>' .
				'	<td>' .
				'		<div class="font-bold text-u-c">' . $fields['title'] . '</div>' .
				'		<div class="text-muted text-xs">' . niceDate2($fields['date']) . '</div>' .
				'		<p>' . $message . '</p>' .
				' 		<div>' . $outletName . ' ' . $regIcon .
				'	</td>' .
				'	<td class="text-center">' .
				$delete .
				'	</td>' .
				'</tr>';
		}
	}

	$foot = 	'</tbody>';

	$fullTable = $head . $table . $foot;
	$jsonResult['table'] = $fullTable;

	header('Content-Type: application/json');
	dai(json_encode($jsonResult));
}

if (validateBool('action') == 'delete') {
	$data 	= [
		'api_key'     	=> API_KEY,
		'company_id'  	=> enc(COMPANY_ID),
		'id' 			=> validateHttp('id')
	];

	$result = curlContents(API_URL . '/delete_notification', 'POST', $data);

	header('Content-Type: application/json');
	dai($result);
}

?>

<?= menuReports(''); ?>

<div class="col-xs-12 m-t-sm m-b">
	<?= headerPrint(); ?>
	<div class="pull-right">
		<span class="no-padder m-n h1 font-bold">Notificaciones</span>
	</div>
</div>

<div class="col-xs-12 wrapper panel r-24x bg-white push-chat-down tableContainer" style="min-height:500px">
	<table class="table table1 col-xs-12 no-padder" id="tableTransactions">
		<?= placeHolderLoader('table') ?>
	</table>
</div>

<script>
	$(document).ready(function() {
		FastClick.attach(document.body);

		var baseUrl = '<?= '/' . basename(__FILE__, '.php'); ?>';
		var url = baseUrl + "?action=generalTable";
		var rawUrl = url;
		$.get(url, function(result) {
			$('#tableTransactions').html(result.table);

			onClickWrap('.delete', function(event, tis) {
				var load = tis.attr('href');
				var $row = tis.closest('tr');
				$row.remove();
				$.get(load, function(result) {
					if (result.success) {
						message('Eliminado', 'success');
					} else {
						message('No se pudo eliminar', 'danger');
					}
				});
			});
		});

	});
</script>

<?php
include_once('includes/compression_end.php');
dai();
?>