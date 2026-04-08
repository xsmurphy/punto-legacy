<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once('includes/simple.config.php');
include_once("includes/db.php");
include_once("includes/config.php");
include_once("languages/" . LANGUAGE . ".php");
include_once("includes/functions.php");
theErrorHandler(); //error handler

if (SAAS_ADM && COMPANY_ID == ENCOM_COMPANY_ID) {
	echo '<script>window.location.replace("/main")</script>';
	dai();
}

if (COMPANY_IS_PARENT == 1) {
	header('location:franchiser');
	dai();
}

topHook();

list($calendar, $startDate, $endDate, $lessDays) = datesForGraphs(7);

if (!empty($_GET['update'])) {

	$_v = '/assets/vendor';
	$js = 'scripts/initials.js';
	minifyJS([
		"$_v/js/jquery-3.6.3.min.js" => $js,
		"$_v/js/jquery-ui-1.12.1.min.js" => $js,
		'$.widget.bridge("uitooltip", $.ui.tooltip); $.widget.bridge("uibutton", $.ui.button);' => $js,
		"$_v/js/bootstrap-3.4.1.min.js" => $js,
		"$_v/js/jquery.number-2.1.6.min.js" => $js,
		"$_v/js/jquery.dataTables-1.10.22.min.js" => $js,
		"$_v/js/jquery.mask-1.14.11.js" => $js,
		"$_v/js/moment-2.24.0-with-locales.min.js" => $js,
		"$_v/js/moment-locale-es.js" => $js,
		"$_v/js/daterangepicker-3.1.min.js" => $js,
		"$_v/js/bootstrap-datetimepicker-4.17.47.min.js" => $js,
		"$_v/js/jquery.form-4.2.1.min.js" => $js,
		"$_v/js/fastclick-1.0.6.min.js" => $js,
		"$_v/js/isMobile-0.4.1.min.js" => $js,
		"$_v/js/jquery.finger-0.1.6.min.js" => $js
	], $js);

	$js = 'scripts/tdp.js';
	minifyJS([
		"$_v/js/snap-1.9.3.min.js" => $js,
		'/assets/panel/js/fileReader.min.js' => $js,
		"$_v/js/xlsx-0.16.2.full.min.js" => $js,
		"$_v/js/jquery.matchHeight-0.7.2.min.js" => $js,
		"$_v/js/jquery.toast-1.3.2.min.js" => $js,
		"$_v/js/jquery.fullscreen-1.1.5.min.js" => $js,
		"$_v/js/jQuery.print-1.5.1.min.js" => $js,
		"$_v/js/Chart-2.9.4.min.js" => $js,
		"$_v/js/chartjs-plugin-annotation-0.5.7.min.js" => $js,
		"$_v/js/chartjs-chart-treemap-0.2.3.min.js" => $js,
		'/assets/scripts/Chart.roundedBarCharts.min.js' => $js,
		"$_v/js/simpleStorage-0.2.1.min.js" => $js,
		"$_v/js/select2-4.1.0.min.js" => $js,
		"$_v/js/select2-i18n-es.min.js" => $js,
		"$_v/js/mustache-4.0.1.min.js" => $js,
		"$_v/js/jquery.lazy-1.7.10.min.js" => $js,
		"$_v/js/jquery.businessHours-1.0.1.min.js" => $js,
		"$_v/js/sweetalert2-7.33.1.min.js" => $js,
		"$_v/js/offline-0.7.19.min.js" => $js,
		'/assets/panel/js/iguider.theme-material.js' => $js,
		'/assets/panel/js/iguider.js' => $js,
		'/assets/panel/js/iguider.locale-es.js' => $js,
		'/scripts/color-selector-2.js' => $js,
		"$_v/js/push-1.0.8.min.js" => $js,
		'/screens/scripts/ncm-ws.js' => $js,
		'/scripts/written-number.min.js' => $js,
		"$_v/js/leaflet-1.7.1.js" => $js,
		"$_v/js/leaflet-routing-machine-3.2.12.js" => $js,
		'/assets/scripts/hereRouting.min.js' => $js,
		'/assets/scripts/leaflet-heat.min.js' => $js,
		// Sentry removed
	], $js);

	$js = 'scripts/ncm.js';
	minifyJS([
		'/assets/scripts/ncmMaps.min.js' => $js,
		'/assets/scripts/ncmDropbox.min.js' => $js,
		'/scripts/documentPrintBuilder.source.js?' . rand() => $js,
		//'/scripts/dpb.min.js?' . rand() => $js,
		'/scripts/rb.min.js?' . rand() => $js,
		'/scripts/common.js?' . rand() => $js
	], $js);

	$cs = 'css/ncm.css';
	minifyCSS([
		'https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;900&display=swap' => $cs,
		'/css/font.css' => $cs,
		'https://fonts.googleapis.com/icon?family=Material+Icons' => $cs,
		'https://fonts.googleapis.com/css?family=VT323' => $cs,
		"$_v/css/jquery-ui-git.css" => $cs,
		"$_v/css/bootstrap-3.4.1.min.css" => $cs,
		"$_v/css/daterangepicker-3.1.css" => $cs,
		"$_v/css/animate-3.5.2.min.css" => $cs,
		"$_v/css/jquery.toast-1.3.2.min.css" => $cs,
		"$_v/css/bootstrap-datetimepicker-4.17.45.min.css" => $cs,
		"$_v/css/select2-4.0.6.min.css" => $cs,
		"$_v/css/select2-bootstrap-0.1.0.min.css" => $cs,
		'/css/color-selector-2.css' => $cs,
		"$_v/css/sweetalert2-7.33.1.min.css" => $cs,
		"$_v/css/offline-language-spanish.min.css" => $cs,
		"$_v/css/offline-theme-dark.min.css" => $cs,
		'/assets/panel/css/iguider.css' => $cs,
		'/assets/panel/css/iguider.theme-material.css' => $cs,
		'/css/app.css?' . rand() => $cs,
		'/css/style.css?' . rand() => $cs,
		"$_v/css/leaflet-1.7.1.css" => $cs
	], $cs);
}

?>
<!DOCTYPE html>
<html class="no-js">

<head>
	<!-- meta -->
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
	<title>Panel de Control - <?= APP_NAME ?></title>

	<link rel="apple-touch-icon-precomposed" sizes="57x57" href="<?= APP_URL ?>/apple-touch-icon-57x57.png" />
	<link rel="apple-touch-icon-precomposed" sizes="114x114" href="<?= APP_URL ?>/apple-touch-icon-114x114.png" />
	<link rel="apple-touch-icon-precomposed" sizes="72x72" href="<?= APP_URL ?>/apple-touch-icon-72x72.png" />
	<link rel="apple-touch-icon-precomposed" sizes="144x144" href="<?= APP_URL ?>/apple-touch-icon-144x144.png" />
	<link rel="apple-touch-icon-precomposed" sizes="60x60" href="<?= APP_URL ?>/apple-touch-icon-60x60.png" />
	<link rel="apple-touch-icon-precomposed" sizes="120x120" href="<?= APP_URL ?>/apple-touch-icon-120x120.png" />
	<link rel="apple-touch-icon-precomposed" sizes="76x76" href="<?= APP_URL ?>/apple-touch-icon-76x76.png" />
	<link rel="apple-touch-icon-precomposed" sizes="152x152" href="<?= APP_URL ?>/apple-touch-icon-152x152.png" />
	<link rel="icon" type="image/png" href="<?= APP_URL ?>/favicon-196x196.png" sizes="196x196" />
	<link rel="icon" type="image/png" href="<?= APP_URL ?>/favicon-96x96.png" sizes="96x96" />
	<link rel="icon" type="image/png" href="<?= APP_URL ?>/favicon-32x32.png" sizes="32x32" />
	<link rel="icon" type="image/png" href="<?= APP_URL ?>/favicon-16x16.png" sizes="16x16" />
	<link rel="icon" type="image/png" href="<?= APP_URL ?>/favicon-128.png" sizes="128x128" />
	<meta name="application-name" content=APP_NAME />
	<meta name="msapplication-TileColor" content="#FFFFFF" />
	<meta name="msapplication-TileImage" content="<?= APP_URL ?>/mstile-144x144.png" />
	<meta name="msapplication-square70x70logo" content="<?= APP_URL ?>/mstile-70x70.png" />
	<meta name="msapplication-square150x150logo" content="<?= APP_URL ?>/mstile-150x150.png" />
	<meta name="msapplication-wide310x150logo" content="<?= APP_URL ?>/mstile-310x150.png" />
	<meta name="msapplication-square310x310logo" content="<?= APP_URL ?>/mstile-310x310.png" />

	<link rel="preconnect" href="https://fonts.gstatic.com">
	<link rel="stylesheet" href="/css/ncm.css?<?= date('d.H') ?>" type="text/css" />
	<link rel="manifest" href="/manifest.json" />

</head>

<body class="bg-light lter">
	<?= menuFrame('top', true); ?>

	<?= menuFrame('bottom'); ?>

	<div class="modal fade" tabindex="-1" id="modalXLarge" role="dialog">
		<div class="modal-dialog modal-xl">
			<div class="modal-content r-24x clear no-bg no-border all-shadows">

			</div>
		</div>
	</div>

	<div class="modal fade" tabindex="-1" id="modalLarge" role="dialog">
		<div class="modal-dialog modal-lg">
			<div class="modal-content r-24x clear no-bg no-border all-shadows">

			</div>
		</div>
	</div>

	<div class="modal fade" tabindex="-1" id="modalSmall" role="dialog">
		<div class="modal-dialog">
			<div class="modal-content r-24x clear no-bg no-border all-shadows">

			</div>
		</div>
	</div>

	<div class="modal fade" tabindex="-1" id="modalTiny" role="dialog">
		<div class="modal-dialog modal-sm">
			<div class="modal-content r-24x clear no-bg no-border all-shadows">

			</div>
		</div>
	</div>

	<script>
		<?php
		$dS 	= '.';
		$tsS 	= ',';
		if (THOUSAND_SEPARATOR == 'dot') {
			$dS 	= ',';
			$tsS 	= '.';
		}
		?>
		var noSessionCheck = false;
		window.decimal = "<?= DECIMAL ?>";
		window.thousandSeparator = "<?= THOUSAND_SEPARATOR ?>";
		window.decimalSymbol = "<?= $dS ?>";
		window.thousandSeparatorSymbol = "<?= $tsS ?>";
		window.currency = "<?= CURRENCY ?>";
		window.companyId = "<?= COMPANY_ID ?>";
		window.startDate = "<?= $startDate ?>";
		window.endDate = "<?= $endDate ?>";
	</script>

	<div class="modal fade" tabindex="-1" id="maxReached" role="dialog">
		<div class="modal-dialog">
			<div class="modal-content r-24x clear no-bg no-border all-shadows">
				<div class="modal-body col-xs-12 no-padder bg-white text-center">
					<div class="col-xs-12 wrapper">
						<div class="m-t m-b">
							<img src="/assets/images/upgrade.jpg" height="180" class="m-b">
							<div class="h1 font-bold m-b text-dark">¡Es tiempo de crecer!</div>
							<div class="m-b-md text-md block">
								Há alcanzado el límite permitido para su plan. <br>
								Contáctenos y le asistiremos para incrementar el límite.
							</div>
							<a href="#" class="btn btn-info btn-lg btn-rounded text-u-c font-bold" data-dismiss="modal">Cerrar</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script src="/scripts/initials.js?<?= date('d.H') ?>"></script>
	<script src="/scripts/tdp.js?<?= date('d.H') ?>"></script>
	<script src="/scripts/ncm.js?<?= date('d.h.s') ?>"></script>

	<script type="text/html" id="lockedOut">
		<div class="col-xs-12 wrapper-lg bg-white">
			<div class="col-md-6 no-padder">
				<img src="/assets/images/partialLock.png" width="100%">
			</div>
			<div class="col-md-6 no-padder">
				<div class="font-bold h1 text-dark m-b-lg m-t-md">
					Acceso bloqueado
				</div>
				<?php
				if ($_cmpSettings['blocked']) {
				?>
					<p class="text-lg m-b-md">Su cuenta se encuentra <strong>temporalmente bloqueada</strong> por falta de pago, no podrá acceder al panel de control ni a la caja hasta regularizar el pago.</p>
					<p class="text-lg">Por favor contáctenos y le asistiremos.</p>
				<?php
				} else {
				?>
					<p class="text-lg m-b-md">El acceso al panel se encuentra <strong>temporalmente bloqueado</strong> por falta de pago, podrá seguir utilizando <a href="" target="_blank">la caja</a> con normalidad y sus ventas no se verán afectadas.</p>
					<p class="text-lg">Por favor pongase al día o contáctenos para evitar el bloqueo total de la cuenta.</p>
				<?php
				}
				?>
				<div class="col-xs-12 m-t-md no-padder">
					<a href="/billing" target="_blank" class="btn btn-lg btn-info rounded text-u-c font-bold">Realizar Pago</a>
					<a href="/logout" class="pull-right m-t"><span class="text-danger">Cerrar Sesión</span></a>
				</div>
			</div>
		</div>
	</script>
	<script>
		var mainAlerts = '<?= mainAlerts() ?>';
		var isLockedOut = <?= ($_cmpSettings['settingPartialBlock'] || $_cmpSettings['blocked']) ? 'true' : 'false' ?>;


		<?php
		if ($_cmpSettings['settingPartialBlock'] || $_cmpSettings['blocked']) {
		?>
			var carryOn = function() {
				$(window).off('hashchange hashcheck').on('hashchange hashcheck', function() {
					ncmHelpers.load({
						url: '/a_dashboard?locked=1',
						container: '#bodyContent',
						success: function(data) {
							$('#bodyContent').html(data);
							$('#modalLarge').modal({
								backdrop: 'static',
								keyboard: false
							});
							ncmHelpers.mustacheIt($('#lockedOut'), {}, $('#modalLarge .modal-content'));
							$('section.hbox.stretch').addClass('justblured');
						}
					});
				});
			};
		<?php
		} else {
		?>
			var carryOn = function() {
				ncmHelpers.loadPageOnHashChange({
					'onAfter': function() {
						$('#nav section.vbox').removeClass('hidden');
						setTimeout(function() {
							$('#nav section.vbox').removeClass('animated');
							$('#bodyContent').prepend(mainAlerts);
						}, 300);
						//prefetch popular pages
						ncmHelpers.preCachePages(['reports', 'items', 'contacts', 'purchase']);
					}
				});
			};
		<?php
		}
		?>

		<?php
		if (!empty($_GET['update'])) {
			ob_start();
		?>
			$(function() {
				window.xhrs = [];
				if (isMobile.phone) {
					var wH = $(window).height();
					$('#bodyContent').css({
						height: (wH - 50) + 'px'
					});
				}

				carryOn();

				$(window).trigger('hashchange');

				$(document).off('shown.bs.modal', '.modal').on('shown.bs.modal', '.modal', function(e) {
					ncmHelpers.onClickWrap('.print', function(event, tis) {
						var el = tis.data('element');
						$(el).print();
					});
				});

				onClickWrap('.print', function(event, tis) {
					var el = tis.data('element');
					$(el).print();
				});
			});

		<?php
			$script = ob_gets_contents();
			minifyJS([$script => 'scripts/at.js']);
		}
		?>
	</script>
	<script src="scripts/at.js?<?= date('d.i') ?>"></script>
	<?php include_once('includes/webpush_init.php'); ?>

</body>

</html>
<?php
include_once('includes/compression_end.php');
dai();
?>