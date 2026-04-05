<?php
include_once('includes/compression_start.php');
require_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/db.php");
include_once("includes/config.php");
include_once("languages/" . LANGUAGE . ".php");
include_once("includes/functions.php");
theErrorHandler(); //error handler

if (COMPANY_IS_PARENT == 1) {
	header('location:franchiser');
	dai();
}

topHook();

list($calendar, $startDate, $endDate, $lessDays) = datesForGraphs(7);

if (!empty($_GET['update'])) {

	$js = 'scripts/initials.js';
	minifyJS([
		'https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js' => $js,
		'$.widget.bridge("uitooltip", $.ui.tooltip); $.widget.bridge("uibutton", $.ui.button);' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/js/bootstrap.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/df-number-format/2.1.5/jquery.number.min.js' => $js,
		'https://cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.11/jquery.mask.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.19.1/moment.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/locale/es.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-daterangepicker/3.1/daterangepicker.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.45/js/bootstrap-datetimepicker.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jquery.form/4.2.1/jquery.form.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/fastclick/1.0.6/fastclick.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/ismobilejs/0.4.1/isMobile.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jquery.finger/0.1.6/jquery.finger.min.js' => $js
	], $js);

	$js = 'scripts/tdp.js';
	minifyJS([
		'https://cdnjs.cloudflare.com/ajax/libs/snap.js/1.9.3/snap.min.js' => $js,
		'https://ncmaspace.nyc3.digitaloceanspaces.com/panel/js/fileReader.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.16.2/xlsx.full.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jquery.matchHeight/0.7.2/jquery.matchHeight-min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jquery-fullscreen-plugin/1.1.5/jquery.fullscreen-min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jQuery.print/1.5.1/jQuery.print.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-annotation/0.5.7/chartjs-plugin-annotation.min.js' => $js,
		'https://cdn.jsdelivr.net/npm/chartjs-chart-treemap@0.2.3/dist/chartjs-chart-treemap.min.js' => $js,
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/Chart.roundedBarCharts.min.js' => $js,
		'https://cdn.jsdelivr.net/simplestorage/0.2.1/simpleStorage.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.full.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/i18n/es.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/mustache.js/4.0.1/mustache.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jquery.lazy/1.7.10/jquery.lazy.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/jquery.businessHours/1.0.1/jquery.businessHours.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/offline.min.js' => $js,
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/panel/js/iguider.theme-material.js' => $js,
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/panel/js/iguider.js' => $js,
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/panel/js/iguider.locale-es.js' => $js,
		'https://panel.encom.app/scripts/color-selector-2.js' => $js,
		'https://cdnjs.cloudflare.com/ajax/libs/push.js/1.0.8/push.min.js' => $js,
		'https://js.pusher.com/7.2/pusher.min.js' => $js,
		'https://panel.encom.app/scripts/written-number.min.js' => $js,
		'https://unpkg.com/leaflet@1.7.1/dist/leaflet.js' => $js,
		'https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js' => $js,
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/hereRouting.min.js' => $js,
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/leaflet-heat.min.js' => $js,
		'https://browser.sentry-cdn.com/5.15.4/bundle.min.js' => $js
	], $js);

	$js = 'scripts/ncm.js';
	minifyJS([
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/ncmMaps.min.js' => $js,
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/ncmDropbox.min.js' => $js,
		'https://panel.encom.app/scripts/documentPrintBuilder.source.js?' . rand() => $js,
		//'https://panel.encom.app/scripts/dpb.min.js?' . rand() => $js,
		'https://panel.encom.app/scripts/rb.min.js?' . rand() => $js,
		'https://panel.encom.app/scripts/common.js?' . rand() => $js
	], $js);

	$cs = 'css/ncm.css';
	minifyCSS([
		'https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;900&display=swap' => $cs,
		'https://panel.encom.app/css/font.css' => $cs,
		'https://fonts.googleapis.com/icon?family=Material+Icons' => $cs,
		'https://fonts.googleapis.com/css?family=VT323' => $cs,
		'https://code.jquery.com/ui/jquery-ui-git.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/css/bootstrap.min.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-daterangepicker/3.1/daterangepicker.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/animate.css/3.5.2/animate.min.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/jquery-toast-plugin/1.3.2/jquery.toast.min.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.45/css/bootstrap-datetimepicker.min.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.1/css/select2.min.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-theme/0.1.0-beta.10/select2-bootstrap.min.css' => $cs,
		'https://panel.encom.app/css/color-selector-2.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/themes/offline-language-spanish.min.css' => $cs,
		'https://cdnjs.cloudflare.com/ajax/libs/offline-js/0.7.19/themes/offline-theme-dark.min.css' => $cs,
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/panel/css/iguider.css' => $cs,
		'https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/panel/css/iguider.theme-material.css' => $cs,
		'https://panel.encom.app/css/app.css?' . rand() => $cs,
		'https://panel.encom.app/css/style.css?' . rand() => $cs,
		'https://unpkg.com/leaflet@1.7.1/dist/leaflet.css' => $cs
	], $cs);
}

?>
<!DOCTYPE html>
<html class="no-js">

<head>
	<!-- meta -->
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
	<title>Panel de Control - ENCOM</title>

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
	<meta name="application-name" content="ENCOM" />
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
							<img src="https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/images/upgrade.jpg" height="180" class="m-b">
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
				<img src="https://assets.encom.app/images/partialLock.png" width="100%">
			</div>
			<div class="col-md-6 no-padder">
				<div class="font-bold h1 text-dark m-b-lg m-t-md">
					Acceso bloqueado
				</div>
				<?php
				if ($_cmpSettings['settingBlocked']) {
				?>
					<p class="text-lg m-b-md">Su cuenta se encuentra <strong>temporalmente bloqueada</strong> por falta de pago, no podrá acceder al panel de control ni a la caja hasta regularizar el pago.</p>
					<p class="text-lg">Por favor contáctenos y le asistiremos.</p>
				<?php
				} else {
				?>
					<p class="text-lg m-b-md">El acceso al panel se encuentra <strong>temporalmente bloqueado</strong> por falta de pago, podrá seguir utilizando <a href="https://app.encom.app" target="_blank">la caja</a> con normalidad y sus ventas no se verán afectadas.</p>
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
	<?php
	// if(defined('USER_ID')){
	?>
	<script>
		//Sentry.init({ dsn: 'https://0f6266003a674548806e65e9c25d2908@sentry.io/5186241' });
		window.intercomSettings = {
			"app_id": "uvb2fg2w",
			"name": "<?php echo USER_NAME ?>",
			"email": "<?php echo USER_EMAIL ?>",
			"user_id": "<?php echo enc(USER_ID) ?>",
			"phone": "<?php echo USER_PHONE ?>",
			"job_title": "<?php echo $_SESSION['user']['roleName']; ?>",
			"user_hash": "<?php echo hash_hmac('sha256', enc(USER_ID), INTERCOM_IDENTITY_SECRET); ?>",
			"source": "Panel",
			"company": {
				"id": "<?php echo enc(COMPANY_ID) ?>",
				"avatar": "<?php echo companyLogo() ?>",
				"name": "<?php echo COMPANY_NAME ?>",
				"created_at": "<?php echo strtotime(COMPANY_DATE) ?>",
				"SMS Credit": "<?= SMS_CREDIT ?>",
				"plan": "<?= $plansValues[PLAN]['name'] ?>",
				"monthly_spend": "<?= ($plansValues[PLAN]['price'] * OUTLETS_COUNT) ?>",
				"upgraded_at": null,
				"outlets_count": "<?= OUTLETS_COUNT ?>",
				"Currency": "<?= CURRENCY ?>",
				"Extra Users": "<?= $_modules['extraUsers'] ?>",
				"Mod Calendar": "<?= $_modules['calendar'] ?>",
				"Mod Ecommerce": "<?= $_modules['ecom'] ?>",
				"Mod Ecom URL": "https://<?= $_cmpSettings['settingSlug'] ?>.encom.site",
				"Mod Spotify": "<?= $_modules['spotify'] ?>",
				"Mod Loyalty": "<?= $_modules['loyalty'] ?>",
				"Mod Feedback": "<?= $_modules['feedback'] ?>",
				"Mod Tables": "<?= $_modules['tables'] ?>",
				"Mod Production": "<?= $_modules['production'] ?>",
				"Mod KDS": "<?= $_modules['kds'] ?>",
				"Mod Orders": "<?= $_modules['ordersPanel'] ?>",
				"Mod Newton": "<?= $_modules['newton'] ?>",
				"Mod Recurring": "<?= $_modules['recurring'] ?>",
				"Mod Dunning": "<?= $_modules['dunning'] ?>",
				"Mod TusFacturas": "<?= $_modules['tusfacturas'] ?>",
				"Mod Reminders": "<?= $_modules['reminder'] ?>",
				"Mod CRM": "<?= $_modules['crm'] ?>",
				"Mod Summary": "<?= $_modules['salesSummaryDaily'] ?>"

			}
		};
	</script>

	<?php if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false): ?>
	<script src="https://cdn.onesignal.com/sdks/OneSignalSDK.js" async=""></script>
	<script>
		var OSuserId = "<?= enc(COMPANY_ID) ?>";
		var OneSignal = window.OneSignal || [];
		OneSignal.push(function() {
			OneSignal.init({
				appId: "cd135ef0-2abc-4a20-a7e4-9783824e33b0"
			});
		});

		OneSignal.push(function() {
			OneSignal.sendTags({
				'companyId': "<?= enc(COMPANY_ID) ?>",
				'outletId': "<?= (OUTLET_ID) ? enc(OUTLET_ID) : false ?>",
				'userId': "<?= enc(USER_ID) ?>",
				'isResource': "false"
			}).then(function(tagsSent) {
				// Callback called when tags have finished sending
			});
		});

		OneSignal.push(function() {
			OneSignal.setExternalUserId(OSuserId);
		});
	</script>
	<?php endif; ?>

	<?php
	if (USER_ID > 0 && COMPANY_ID && COMPANY_ID != ENCOM_COMPANY_ID && in_array(PLAN, [1, 2, 3, 4, 9, 10])) {
	?>

		<script>
			(function(w, d, u) {
				var s = d.createElement('script');
				s.async = true;
				s.src = u + '?' + (Date.now() / 60000 | 0);
				var h = d.getElementsByTagName('script')[0];
				h.parentNode.insertBefore(s, h);
			})(window, document, 'https://cdn.bitrix24.com/b11821471/crm/site_button/loader_4_1xmwxy.js');

			$(window).on('onBitrixLiveChat', (event) => {
				var widget = event.detail.widget;
				widget.setUserRegisterData({
					//'hash' 		: window.intercomSettings.user_hash,
					'name': window.intercomSettings.name,
					'email': window.intercomSettings.email,
					'avatar': window.intercomSettings.company.avatar,
					'www': "<?= $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['SERVER_NAME'] ?>",
					'position': window.intercomSettings.job_title
				});
				widget.setCustomData([{
						"USER": {
							"NAME": window.intercomSettings.name,
							"AVATAR": window.intercomSettings.company.avatar,
						}
					},
					{
						"GRID": [{
								"NAME": "Empresa",
								"VALUE": window.intercomSettings.company.name,
								"DISPLAY": "LINE"
							},
							{
								"NAME": "Plan",
								"VALUE": window.intercomSettings.company.plan,
								"DISPLAY": "LINE"
							},
							{
								"NAME": "Fuente",
								"VALUE": window.intercomSettings.source,
								"DISPLAY": "LINE"
							},
							{
								"NAME": "Rol",
								"VALUE": window.intercomSettings.job_title,
								"DISPLAY": "LINE"
							},
							{
								"NAME": "E-mail",
								"VALUE": window.intercomSettings.email,
								"DISPLAY": "LINE",
							},
							{
								"NAME": "Teléfono",
								"VALUE": window.intercomSettings.phone,
								"DISPLAY": "LINE",
							},
							{
								"NAME": "ID",
								"VALUE": window.intercomSettings.user_id,
								"DISPLAY": "LINE"
							}
						]
					}
				]);
			});
		</script>

		<script>
			/*(function(){var w=window;var ic=w.Intercom;if(typeof ic==="function"){ic('reattach_activator');ic('update',intercomSettings);}else{var d=document;var i=function(){i.c(arguments)};i.q=[];i.c=function(args){i.q.push(args)};w.Intercom=i;function l(){var s=d.createElement('script');s.type='text/javascript';s.async=true;s.src='https://widget.intercom.io/widget/uvb2fg2w';var x=d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);}if(w.attachEvent){w.attachEvent('onload',l);}else{w.addEventListener('load',l,false);}}})();*/

			! function(f, b, e, v, n, t, s) {
				if (f.fbq) return;
				n = f.fbq = function() {
					n.callMethod ?
						n.callMethod.apply(n, arguments) : n.queue.push(arguments)
				};
				if (!f._fbq) f._fbq = n;
				n.push = n;
				n.loaded = !0;
				n.version = '2.0';
				n.queue = [];
				t = b.createElement(e);
				t.async = !0;
				t.src = v;
				s = b.getElementsByTagName(e)[0];
				s.parentNode.insertBefore(t, s)
			}(window, document, 'script',
				'https://connect.facebook.net/en_US/fbevents.js');
			fbq('init', '474288730518931');
			fbq('track', 'PageView');
		</script>

	<?php
	}
	// }
	?>
	<script>
		var mainAlerts = '<?= mainAlerts() ?>';
		var isLockedOut = <?= ($_cmpSettings['settingPartialBlock'] || $_cmpSettings['settingBlocked']) ? 'true' : 'false' ?>;


		<?php
		if ($_cmpSettings['settingPartialBlock'] || $_cmpSettings['settingBlocked']) {
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

</body>

</html>
<?php
include_once('includes/compression_end.php');
dai();
?>