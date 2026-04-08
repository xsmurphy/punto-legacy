<?php
include_once('sa_head.php');

require '../vendor/autoload.php';

use Mailgun\Mailgun;

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[1]));
define('TRANS_ID', dec($data[0]));
define('CLIENT_COMPANY_ID', dec($data[2] ?? 0));


$setting = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('TAX_NAME', $setting['settingTaxName']);
define('TIN_NAME', $setting['settingTIN']);
define('COMPANY_NAME', $setting['settingName']);
define('LANGUAGE', $setting['settingLanguage']);

loadLanguage(LANGUAGE);
date_default_timezone_set(TIMEZONE);

define('TODAY', date('Y-m-d H:i:s'));

$result 		= ncmExecute('SELECT * FROM transaction WHERE transactionType IN(0,3,5) AND transactionId = ? AND companyId = ? LIMIT 1', [TRANS_ID, COMPANY_ID]);

if (!$result) {
	header('location: /');
	dai();
}

$contact 					= ncmExecute('SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1', [$result['customerId'], COMPANY_ID]);

$customerName 		= iftn($contact['contactName'], '');
$customerRuc 			= iftn($contact['contactTIN'], '');

$total =  $result['transactionTotal'] - $result['transactionDiscount'];

$_modules = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [COMPANY_ID]);

if ($_modules['epos'] && $result['transactionType'] == 3) {
	$credit = ncmExecute('SELECT * FROM transaction WHERE transactionParentId = ? AND companyId = ? AND transactionType IN(5,6)', [TRANS_ID, COMPANY_ID], false, true);
	$totalPaid = 0;

	if ($credit) {
		while (!$credit->EOF) {
			$trsTotal   = abs($credit->fields['transactionTotal']);
			$totalPaid += $trsTotal;
			$credit->MoveNext();
		}
		$credit->Close();
	}

	$deudaTotal = ($result['transactionTotal'] - $result['transactionDiscount']) - $totalPaid;
}

$array = [
	'total' 			=> formatCurrentNumber($total),
	'deuda' 			=> formatCurrentNumber($deudaTotal),
	'subtotal' 		=> formatCurrentNumber($result['transactionTotal']),
	'discount' 		=> formatCurrentNumber($result['transactionDiscount']),
	'tax' 				=> formatCurrentNumber($result['transactionTax']),
	'companyId' 	=> enc($result['companyId']),
	'outletId' 		=> enc($result['outletId']),
	'transactionId'	=> enc($result['transactionId']),
	'date' 				=> $result['transactionDate'],
	'payment' 		=> $result['transactionPaymentType'],
	'expires' 		=> ($result['transactionDueDate']) ? $result['transactionDueDate'] : $result['transactionDate'],
	'taxName' 		=> TAX_NAME,
	'companyName' => COMPANY_NAME,
	'outlet' 			=> $outletName,
	'sale' 				=> json_decode(stripslashes($result['transactionDetails']), true)
];

$feed 				= base64_encode(enc(COMPANY_ID) . ',' . enc($result['outletId']) . ',' . enc($result['customerId']) . ',' . enc(TRANS_ID));
$feedbackUrl 	= '/screens/feedback?s=' . $feed;

$template 		= $setting['settingInvoiceTemplate'];

$register 		= ncmExecute('SELECT * FROM register WHERE registerId = ? AND companyId = ?', [$result['registerId'], COMPANY_ID]);

$docName = '';
if (in_array($result['transactionType'], [0, 3])) {
	$docName = L_INVOICE;
} else if ($result['transactionType'] == 5) {
	$docName = L_RECEIPT;
}

if ($_GET['template']) {
	$template 		= 1;
}

define('WHATSAPP', getValue('outlet', 'outletWhatsApp', 'WHERE outletId = ' . $result['outletId']));

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (COMPANY_ID === 4456) { // TODO: replace integer 4456 with the company UUID once data is migrated

		$fileTmpPath = $_FILES['transferImage']['tmp_name'];
		$fileName = $_FILES['transferImage']['name'];

		$file = false;

		// Verificar si el archivo tiene contenido
		if (filesize($fileTmpPath) > 0) {
			// Obtener la extensión del archivo
			$extension = pathinfo($fileName, PATHINFO_EXTENSION);

			// Verificar si es una imagen o un pdf
			if (in_array($extension, array('jpg', 'jpeg', 'png'))) {
				$file = true;
			} elseif (in_array($extension, array('pdf'))) {
				$file = true;
			}
		}

		if ($file) {

			/// Envio de correo con Mailgun
			$mgClient = Mailgun::create(MAILGUN_TOKEN);
			$domain = MAILGUN_DOMAIN;

			$mailBody = "RUC: $customerRuc<br>";
			$mailBody .= "Cliente: $customerName<br>";
			$docType = 'del comprobante: ';
			if (in_array($result['transactionType'], [0, 3])) {
				$mailBody .= "Factura Nro.: " . iftn($result['invoicePrefix'], $register['registerInvoicePrefix']) . str_pad($result['invoiceNo'], 7, "0", STR_PAD_LEFT) . "<br>";
				$docType = 'de la factura: ';
			} else {
				$mailBody .= "Comprobante Nro.: " . ($result['invoiceNo'] ?? '') . "<br>";
			}
			$totalAmount = formatCurrentNumber($result['transactionTotal'] - $result['transactionDiscount']);
			$mailBody .= "Monto $totalAmount<br>";
			$mailBody .= "<p>Datos De Transferencia Bancaria Adjuntos</p>";

			# Make the call to the client.
			try {
				$resultMail = $mgClient->messages()->send($domain, [
					'from'    => 'Comprobante <' . EMAIL_NOTIFICATION . '>',
					'to'      => EMAIL_NOTIFICATION_TO,
					'subject' => toUTF8('Comprobante transferencia bancaria'),
					'html'    => toUTF8($mailBody),
					'attachment' => [
						[
							'filePath' => $fileTmpPath,
							'filename' => $fileName
						]
					]
				]);

				// Verificar el estado del envío
				if ($resultMail->getId()) {
					//error_log("Correo enviado exitosamente. ID: " . $resultMail->getId(), 3, './error_log');
					$recordUpdate['settingPartialBlock'] = 0;
					$recordUpdate['blocked'] = 0;
					$recordUpdate['planExpired'] = 0;
					$update = $db->AutoExecute('setting', $recordUpdate, 'UPDATE', 'companyId = ' . CLIENT_COMPANY_ID);
					header('Location: ' . APP_URL . '/@#dashboard');
				} else {
					error_log("No se pudo enviar el correo.", 3, './error_log');
				}
			} catch (\Exception $e) {
				// Manejo de errores
				error_log("Error al enviar el correo: " . $e->getMessage(), 3, './error_log');
			}
		}
	}
}

?>
<!DOCTYPE html>
<html class="no-js">

<head>
	<!-- meta -->
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
	<title><?= L_SA_RECEIPT_TITLE ?? 'RECIBO' ?> <?= COMPANY_NAME; ?></title>
	<meta property="og:title" content="<?= L_SA_RECEIPT_TITLE ?? 'RECIBO' ?> <?= COMPANY_NAME; ?>" />
	<meta property="og:image" content="/assets/150-150/0/<?= enc(COMPANY_ID) ?>.jpg" />
	<link rel="stylesheet" href="/assets/vendor/css/bootstrap-4.5.2.min.css">
	<script src="/assets/vendor/js/jquery-3.6.3.min.js"></script>
	<script src="/assets/vendor/js/bootstrap-4.5.2.min.js"></script>

	<?php
	loadCDNFiles([], 'css');
	?>

	<style type="text/css">
		.svg {
			cursor: pointer;
			filter: invert(.3) sepia(1) saturate(1) hue-rotate(175deg);
		}

		<?php
		if ($_GET['printMode']) {
		?>html {
			background-color: #fff !important;
		}

		.hidden-print {
			display: none !important;
		}

		.visible-print {
			display: block !important;
		}

		.md-whiteframe-16dp {
			box-shadow: none !important;
		}

		.col-sm-1,
		.col-sm-2,
		.col-sm-3,
		.col-sm-4,
		.col-sm-5,
		.col-sm-6,
		.col-sm-7,
		.col-sm-8,
		.col-sm-9,
		.col-sm-10,
		.col-sm-11,
		.col-sm-12 {
			float: left;
		}

		.col-sm-12 {
			width: 100%;
		}

		.col-sm-11 {
			width: 91.66666667%;
		}

		.col-sm-10 {
			width: 83.33333333%;
		}

		.col-sm-9 {
			width: 75%;
		}

		.col-sm-8 {
			width: 66.66666667%;
		}

		.col-sm-7 {
			width: 58.33333333%;
		}

		.col-sm-6 {
			width: 50%;
		}

		.col-sm-5 {
			width: 41.66666667%;
		}

		.col-sm-4 {
			width: 33.33333333%;
		}

		.col-sm-3 {
			width: 25%;
		}

		.col-sm-2 {
			width: 16.66666667%;
		}

		.col-sm-1 {
			width: 8.33333333%;
		}

		.col-sm-pull-12 {
			right: 100%;
		}

		.col-sm-pull-11 {
			right: 91.66666667%;
		}

		.col-sm-pull-10 {
			right: 83.33333333%;
		}

		.col-sm-pull-9 {
			right: 75%;
		}

		.col-sm-pull-8 {
			right: 66.66666667%;
		}

		.col-sm-pull-7 {
			right: 58.33333333%;
		}

		.col-sm-pull-6 {
			right: 50%;
		}

		.col-sm-pull-5 {
			right: 41.66666667%;
		}

		.col-sm-pull-4 {
			right: 33.33333333%;
		}

		.col-sm-pull-3 {
			right: 25%;
		}

		.col-sm-pull-2 {
			right: 16.66666667%;
		}

		.col-sm-pull-1 {
			right: 8.33333333%;
		}

		.col-sm-pull-0 {
			right: auto;
		}

		.col-sm-push-12 {
			left: 100%;
		}

		.col-sm-push-11 {
			left: 91.66666667%;
		}

		.col-sm-push-10 {
			left: 83.33333333%;
		}

		.col-sm-push-9 {
			left: 75%;
		}

		.col-sm-push-8 {
			left: 66.66666667%;
		}

		.col-sm-push-7 {
			left: 58.33333333%;
		}

		.col-sm-push-6 {
			left: 50%;
		}

		.col-sm-push-5 {
			left: 41.66666667%;
		}

		.col-sm-push-4 {
			left: 33.33333333%;
		}

		.col-sm-push-3 {
			left: 25%;
		}

		.col-sm-push-2 {
			left: 16.66666667%;
		}

		.col-sm-push-1 {
			left: 8.33333333%;
		}

		.col-sm-push-0 {
			left: auto;
		}

		.col-sm-offset-12 {
			margin-left: 100%;
		}

		.col-sm-offset-11 {
			margin-left: 91.66666667%;
		}

		.col-sm-offset-10 {
			margin-left: 83.33333333%;
		}

		.col-sm-offset-9 {
			margin-left: 75%;
		}

		.col-sm-offset-8 {
			margin-left: 66.66666667%;
		}

		.col-sm-offset-7 {
			margin-left: 58.33333333%;
		}

		.col-sm-offset-6 {
			margin-left: 50%;
		}

		.col-sm-offset-5 {
			margin-left: 41.66666667%;
		}

		.col-sm-offset-4 {
			margin-left: 33.33333333%;
		}

		.col-sm-offset-3 {
			margin-left: 25%;
		}

		.col-sm-offset-2 {
			margin-left: 16.66666667%;
		}

		.col-sm-offset-1 {
			margin-left: 8.33333333%;
		}

		.col-sm-offset-0 {
			margin-left: 0%;
		}

		.visible-xs {
			display: none !important;
		}

		.hidden-xs {
			display: block !important;
		}

		table.hidden-xs {
			display: table;
		}

		tr.hidden-xs {
			display: table-row !important;
		}

		th.hidden-xs,
		td.hidden-xs {
			display: table-cell !important;
		}

		.hidden-xs.hidden-print {
			display: none !important;
		}

		.hidden-sm {
			display: none !important;
		}

		.visible-sm {
			display: block !important;
		}

		table.visible-sm {
			display: table;
		}

		tr.visible-sm {
			display: table-row !important;
		}

		th.visible-sm,
		td.visible-sm {
			display: table-cell !important;
		}

		* {
			color: #000000 !important;
		}

		.select2-selection__rendered,
		.text-white,
		.select2-selection,
		.bg-info .select2-selection__rendered {
			color: #000000 !important;
			border: none !important;
		}

		input,
		select,
		textarea {
			border: none !important;
		}

		.wrapper-print {
			padding: 50px !important;
		}

		table,
		tr,
		th,
		td,
		.bg-white,
		.bg-default,
		.gradBgBlue,
		.table,
		.bg-light,
		.panel,
		.panel-body {
			background: transparent !important;
		}

		::-webkit-input-placeholder {
			/* WebKit browsers */
			color: transparent !important;
		}

		:-moz-placeholder {
			/* Mozilla Firefox 4 to 18 */
			color: transparent !important;
		}

		::-moz-placeholder {
			/* Mozilla Firefox 19+ */
			color: transparent !important;
		}

		:-ms-input-placeholder {
			/* Internet Explorer 10+ */
			color: transparent !important;
		}

		table.table td,
		table.table th {
			padding: 10px !important;
		}

		.pagebreak {
			page-break-before: always;
		}

		/* page-break-after works, as well */
		section#content section.scrollable {
			border: none !important;
			height: auto !important;
		}

		.centered-container {
			display: flex;
			justify-content: center;
			align-items: center;
			flex-direction: column;
			height: 100vh;
			text-align: center;
		}

		<?php
		}
		?>
	</style>
</head>

<body class="bg-light lter col-xs-12 no-padder">
	<section class="vbox" id="content">
		<?php
		$tr = '';
		$label = 'bg-light';

		if ($result['transactionComplete'] == '1') {
			$labelText 	= L_SA_PAID ?? 'PAGADO';
		} else {
			$label = 'bg-danger lt';
			$labelText = L_SA_PENDING ?? 'PENDIENTE';
			if (strtotime($result['transactionDueDate']) < time()) {
				$labelText 	= L_SA_DUE ?? 'VENCIMIENTO';
			}
		}

		if ($result['transactionType'] == '3') {
			$condicion = L_SA_CREDIT ?? 'CREDITO';
			$template = '1';
		} else {
			$condicion 	= L_SA_CASH_SALE ?? 'CONTADO';
			$template = '0';
		}

		if ($result['transactionType'] == '7') {
			$label = 'bg-dark';
			$labelText = L_SA_VOID;
		}

		$displayNone = ($_GET['printMode']) ? '' : 'display:none;';
		?>

		<div class="wrapper text-center col-md-4 col-md-offset-4 col-sm-6 col-sm-offset-3 col-xs-12">
			<div class="visible-print"><br><br></div>
			<img height="80" width="80" src="/assets/150-150/0/<?= enc(COMPANY_ID) ?>.jpg" class="img-circle animated bounceIn" id="logo" style="<?= $displayNone; ?>" />
			<h3 class="h3 m-t-xs m-b-md font-bold"><?= COMPANY_NAME; ?></h3>
			<div class="visible-print text-left col-xs-12">
				<h4 class="text-u-c font-bold"><?= $docName ?></h4>
				<div class="text-sm">
					<?php
					if (in_array($result['transactionType'], [0, 3])) {
						echo (L_NUM ?? 'NUMERO') . ' ' . iftn($result['invoicePrefix'], $register['registerInvoicePrefix']) .  str_pad($result['invoiceNo'], 7, "0", STR_PAD_LEFT) . '<br>' .
							(($register['registerInvoiceAuth']) ? (L_INVOICE_AUTH_NO ?? 'AUTORIZACION') . ': ' . $register['registerInvoiceAuth'] . '<br>' : '') .
							(($register['registerInvoiceAuthExpiration']) ? (L_VALID_TILL ?? 'VALIDO') . ': ' . $register['registerInvoiceAuthExpiration'] . '<br>' : '') .
							(L_SA_TYPE ?? 'TIPO') . ': ' . $condicion . '<br>';
					} else if ($result['transactionType'] == 5) {
						echo L_NUM . ' ' . $result['invoiceNo'] . '<br>';
					}
					?>
					<?= (L_DATE ?? 'FECHA') . ': ' . nicedate($result['transactionDate'], true); ?>
					<br>

				</div>

			</div>

			<div class="animated fadeInUp speed-3x r-24x md-whiteframe-16dp col-xs-12 no-padder clear" style="<?= $displayNone; ?>" id="list">
				<?php
				if ($_modules['feedback'] > 0) {
				?>
					<div class="col-xs-12 wrapper hidden-print bg-white">
						<div class="col-xs-12 text-center wrapper bg r-24x">
							<div><?= L_SA_QUALIFY_YOUR_EXP_TEXT ?? 'CALIFICANOS' ?></div>
							<a href="<?= $feedbackUrl; ?>"><img src="/images/facesgroup.png" height="80"></a>
						</div>
					</div>
				<?php
				}
				?>

				<?php
				if ($_modules['loyaltyData']['customerVisible']) {
					if ($_modules['loyalty'] && $_modules['loyaltyMin'] > 0 && $result['transactionType'] != 5) {
						$loyalty 	= $contact['contactLoyaltyAmount'];
				?>

						<a href="<?= PUBLIC_URL ?>/rewardsView?s=<?= base64_encode(enc(COMPANY_ID) . ',' . enc($contact['contactId'])) ?>" class="col-xs-12 wrapper-xs bg-primary gradBgPurple animateBg hidden-print text-center" id="loyalty">
							<span class="block text-u-c font-bold"><?= L_SA_VIEW_MY_POINTS ?></span>
						</a>

				<?php
					}
				}
				?>

				<div class="panel col-xs-12 wrapper m-n">
					<div class="col-xs-12 no-padder hidden-print">
						<div class="text-xs text-muted"> Deuda <?= L_TOTAL ?? 'TOTAL' ?></div>
						<div class="h1 font-bold text-dark"><?= CURRENCY . $array['deuda'] ?></div>
						<?php
						if ($template == 1) {
						?>
							<div class="m-b">
								<!-- <?= L_DUE_DATE ?> --> <?= nicedate($array['expires']); ?>
								<br>
								<span class="badge <?= $label ?> text-u-c"><?= $labelText ?></span>
							</div>

							<?php
							if ($_modules['epos'] && $result['transactionType'] == 3) {

								$url = ePOSLink([
									'company'   => enc(COMPANY_ID),
									'outlet'    => enc($result['outletId']),
									'amount'    => $deudaTotal,
									'customer'  => enc($contact['contactId']),
									'uid'       => $result['transactionUID'],
									'date'      => TODAY
								]);
							?>
								<div class="col-xs-12 text-center m-b">
									<a href="<?= $url; ?>" class="btn btn-info btn-lg text-u-c font-bold rounded" target="_blank">Pago Online</a>
								</div>
							<?php
							}
							?>

						<?php
						}
						?>
					</div>

					<div>&nbsp;</div>
					<div>

						<div class="text-muted text-sm text-left">
							<?= L_CUSTOMER ?? 'CLIENTE' ?>
							<span class="pull-right"><?= TIN_NAME ?></span>
						</div>
						<div class="text-left font-bold m-b">
							<?= $customerName ?>
							<span class="pull-right text-muted"><?= iftn($customerRuc, '') ?></span>
						</div>
						<?php
						$tr = '';
						if ($_GET['printMode']) {
							$tr .= '<tr>';
							$tr .= '<td class="text-center">' . (L_QTY ?? 'CANTIDAD') . '</td>';
							$tr .= '<td>' . (L_DESCRIPTION ?? 'DESCRIPCION') . '</td>';
							$tr .= '<td class="text-right">' . (L_PRICE ?? 'PRECIO') . '</td>';
							$tr .= '<td class="text-right">' . (L_TOTAL ?? 'TOTAL') . '</td>';
							$tr .= '</tr>';
						}
						foreach ($array['sale'] as $key => $val) {
							if ($val['name'] != 'Descuento' && $val['name'] != 'Discount') {
								$name 	= $val['name'];
								$count 	= $val['count'];
								$price 	= formatCurrentNumber($val['price']);
								$total 	= formatCurrentNumber($val['total']);

								$name 	= str_replace(['Cancelaciu00f3n', 'false'], ['Cancelación', ''], $name);

								if ($val['type'] == 'inCombo' || $val['type'] == 'inComboAddons') {
									$name 	= str_replace(['u21b3', 'false'], ['\u21b3', ''], $name);
									$name  	= '<span class="text-muted">' . json_decode('"' . $name . '"') . '</span>';
									$count 	= '';
									$total 	= '';
								} else if ($val['type'] == 'combo') {
								}

								$tr .= '<tr>';
								$tr .= '<td class="text-right">' . $count . '</td>';
								$tr .= '<td>' . $name . '</td>';
								$tr .= '<td class="visible-print text-right">' . $price . '</td>';
								$tr .= '<td class="text-right">' . $total . '</td>';
								$tr .= '</tr>';
							}
						}
						?>
						<table class="table text-left font-bold" id="saleDetails">
							<?= $tr; ?>
							<?php
							if ($array['discount'] > 0) {
							?>
								<tr class="text-success">
									<td></td>
									<td class="visible-print"></td>
									<td class="text-right font-bold"><?= L_DISCOUNT ?? 'DESCUENTO' ?></td>
									<td class="font-bold text-u-c text-right"><?= CURRENCY . $array['discount'] ?></td>
								</tr>
							<?php
							}
							?>
							<?php
							if (in_array($result['transactionType'], [0, 3])) {
							?>
								<tr>
									<td></td>
									<td class="visible-print"></td>
									<td class="text-right font-bold"><?= $setting['settingTaxName'] ?></td>
									<td class="font-bold text-u-c text-right"><?= $array['tax'] ?></td>
								</tr>
							<?php
							}
							?>
							<tr class="<?= $_GET['printMode'] ? 'h3' : '' ?>" style="<?= $_GET['printMode'] ? 'background-color:#000!important;' : '' ?>">
								<td></td>
								<td class="visible-print"></td>
								<td class="text-right font-bold text-u-c" style="<?= $_GET['printMode'] ? 'color:#fff!important;' : '' ?>"><?= L_TOTAL ?? 'TOTAL' ?></td>
								<td class="font-bold text-u-c text-right" style="<?= $_GET['printMode'] ? 'color:#fff!important;' : '' ?>"><?= $array['total'] ?></td>
							</tr>
						</table>
					</div>

					<div class="col-xs-4 no-padder"></div>
					<div class="col-xs-8 no-padder">
						<table class="table text-sm text-left">
							<?php
							$paymentType 	= json_decode($array['payment'], true);

							if (validity($paymentType)) {
								foreach ($paymentType as $key => $val) {
									$name = str_replace(['Efectivo', 'T. de Crédito', 'T. de Débito', 'Cheque'], [L_CASH, L_CC, L_DC, L_CHECK], $val['name']);
							?>
									<tr>
										<td><?= $name ?></td>
										<td class="text-right"><?= formatCurrentNumber($val['price']) ?></td>
									</tr>
							<?php
								}
							}
							?>
						</table>
					</div>

					<?php
					if (COMPANY_ID === 4456) { // TODO: replace integer 4456 with the company UUID once data is migrated
					?>
						<div class="container mt-5 mb-5">
							<form id="uploadForm" action="receipt.php?s=<?php echo ($_GET['s']); ?>" method="post" enctype="multipart/form-data" style="text-align: left;">
								<div class="form-group"><br>
									<label style="size: 30px; font-weight: bold" for="transferImage">En caso de transferencia por favor seleccione el comprobante para desbloquear su cuenta:</label><br>
									<input type="file" class="form-control-file" id="transferImage" name="transferImage">
								</div>
								<button type="submit" class="btn btn-info btn-lg text-u-c font-bold rounded pay-btn" id="submitButton">Enviar Imagen</button>
							</form>
						</div>
					<?php
					}
					?>

					<div class="col-xs-12 text-center hidden-print m-b">
						<a href="#" id="print" class="btn btn-info btn-rounded text-u-c font-bold"><?= L_PRINT ?? 'IMPRIMIR' ?></a>
					</div>
					<div class="col-xs-12 no-padder hidden-print">
						<div class="badge m-b"><?= nicedate($array['date'], true); ?></div>
					</div>
				</div>




				<div class="text-center col-xs-12 wrapper lter r-3x hidden-print">
					<?php
					echo companySocialSites($setting['settingSocialMedia'], WHATSAPP);
					?>
				</div>

			</div>
		</div>



		<div class="col-xs-12 wrapper text-xs text-muted animated fadeIn <?= $_GET['printMode'] ? 'text-left' : 'text-center' ?>" id="companyData" style="<?= $displayNone; ?>">
			<?= ($setting['settingBillingName']) ? '<strong>' . $setting['settingBillingName'] . '</strong><br>' : '' ?>
			<?= ($setting['settingRUC']) ? $setting['settingRUC'] . '<br>' : '' ?>
			<?= ($setting['settingBillDetail']) ? $setting['settingBillDetail'] . '<br>' : '' ?>
			<?= ($setting['settingAddress']) ? $setting['settingAddress'] . '<br>' : '' ?>
			<?= ($setting['settingPhone']) ? '<a href="tel:">' . $setting['settingPhone'] . '</a><br>' : '' ?>
			<?= ($setting['settingCity']) ? $setting['settingCity'] . '<br>' : '' ?>
			<?php
			if ($result['transactionType'] != 5) { // no muestro en recibos
			?>
				<!-- <div class="font-thin wrapper text-muted">* <?= L_SA_NOT_VALID_FOR_TAX ?> *</div> -->
			<?php
			}
			?>
		</div>

		<div class="m-b-md col-xs-12 animated bounceIn" style="<?= $displayNone; ?>" id="encom">
			<a href="/?utm_source=ENCOM_online_receipt&utm_medium=ENCOM_footer_icon&
utm_campaign=<?= COMPANY_NAME ?>" class="block hidden-print">
				<span class="text-muted"><?= L_POWERED_BY ?></span> <br>
				<img src="/images/incomeLogoLgGray.png" width="80">
			</a>
			<br>
			<div class="visible-print font-bold">
				<span><?= L_POWERED_BY ?? 'ENCUENTRANOS' ?></span> <br>
				<div>WWW.ENCOM.APP</div>
			</div>
		</div>
		</div>

	</section>
	<script type="text/javascript">
		var ese = '<?php echo base64_encode(enc(COMPANY_ID) . ',' . enc($result['transactionUID'])); ?>';
		var noSessionCheck = true;
		window.standAlone = true;
	</script>

	<?php

	loadCDNFiles([], 'js');
	?>

	<script src="/scripts/tdp.js?<?= date('d.H') ?>"></script>
	<link rel="stylesheet" href="/css/ncm.css?<?= date('d.H') ?>" type="text/css" />
	<script type="text/javascript">
		$('#submitButton').click(function(event) {
			event.preventDefault();

			var file = $('#transferImage')[0].files[0];

			// Verificar si se ha seleccionado un archivo
			if (file) {
				// Verificar si el archivo es una imagen o un archivo PDF
				if (file.type.startsWith('image/') || file.type === 'application/pdf') {
					// El archivo es una imagen o un archivo PDF válido
					$('#uploadForm').submit();
				} else {
					// El archivo no es una imagen ni un archivo PDF válido
					ncmDialogs.alert('Por favor selecciona una imagen o un archivo PDF válido.');
				}
			} else {
				// No se ha seleccionado ningún archivo
				ncmDialogs.alert('Por favor selecciona un archivo.');
			}
		});

		$(document).ready(function() {
			<?php
			if (!$_GET['printMode']) {
			?>
				$('#logo').show();
				setTimeout(function() {
					$('#list').show();
				}, 100);
				setTimeout(function() {
					$('#loyalty,#companyData,#encom').show();
				}, 650);

				onClickWrap('#print', function() {
					window.print();
				});
			<?php
			}
			?>

		});

		// 	ncmHelpers.onClickWrap('.makeOrder', async function(event, tis) {
		// 		var type = tis.data('type');

		// 		if (type == 'card') {
		// 			// Este es de dinelco
		// 			//AlignetVPOS2.openModal('','1');

		// 			spinner('body', 'show');
		// 			await FormBancard()
		// 			spinner('body', 'hide');

		// 		} else {
		// 			spinner('body', 'show');
		// 			$.get('?s=' + ese + '&createQR=true', (result) => {
		// 				console.log(result);
		// 				result = JSON.parse(result)
		// 				var imgUrl = result.qr_url;
		// 				var imgName = imgUrl.substring(imgUrl.lastIndexOf('/') + 1);
		// 				var img = '<img src="' + imgUrl + '" width="100%">' +
		// 					'<div class="col-xs-12 text-center m-t m-b-lg">' +
		// 					'Mantenga presionado en la imagen para descargar' +
		// 				'    <a download href="' + imgUrl + '" title="QR" class="btn btn-md btn-rounded btn-lg btn-info text-u-c font-bold hidden">' +
		// 					'    descargar QR' +
		// 					'    </a>' +
		// 					'</div>';

		// 				$('#modalView .modal-content').html(img);
		// 				$('#modalView').modal('show');
		// 				spinner('body', 'hide');
		// 			});
		// 		}
		// 	});

		// 	if(validateHttp('upgraded') == 'pay'){
		//     echo "loadForm('" . $baseUrl . "?action=makePayment&planis=".$_GET['planis']."','#modalLarge .modal-content',function(){
		//       $('#modalLarge').modal('show');
		//       loadBillingTable('".$_GET['planis']."','month'); 
		//     }; window.onbeforeunload = true;"


		// onClickWrap('.createItemBtn',function(event,tis){
		//   loadForm(baseUrl + '?action=makePayment','#modalLarge .modal-content',function(){
		//     $('#modalLarge').modal('show');
		//   });
		// });
	</script>

</body>

</html>

<?php
dai();
?>