<?php
include_once('sa_head.php');
include_once('../libraries/enLetras.class.php');

$data = explodes(',', base64_decode($_GET['s']));

define('TRANS_ID', dec($data[0]));
define('COMPANY_ID', dec($data[1]));

//verifico si existe la factura
$exists = ncmExecute('SELECT transactionId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[TRANS_ID,COMPANY_ID]);
 
if(!$exists){
	include_once('/home/encom/public_html/panel/includes/404.inc.php');
	die();
}

$setting = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('TAX_NAME', $setting['settingTaxName']);
define('TIN_NAME', $setting['settingTIN']);
define('COMPANY_NAME', $setting['settingName']);
define('TODAY', date('Y-m-d'));
define('LANGUAGE', $setting['settingLanguage']);

loadLanguage(LANGUAGE);
date_default_timezone_set(TIMEZONE);

$_modules 	= ncmExecute('SELECT digitalInvoice, digitalInvoiceData FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$_template 	= ncmExecute('SELECT taxonomyExtra as template FROM taxonomy WHERE taxonomyType = ? AND taxonomyId = ? AND companyId = ? LIMIT 1',['printTemplate','183036',COMPANY_ID]);

if(!$_modules['digitalInvoice']){
	include_once('/home/encom/public_html/panel/includes/404.inc.php');
	die();
}

if(validateHttp('pdf')){
	$apikey 	= PDF_API_KEY;
	$value 		= 'https://public.encom.app/digitalInvoice?s=' . $_GET['s'] . '&secret=iwfyita'; 
	$result 	= file_get_contents("http://api.html2pdfrocket.com/pdf?apikey=" . urlencode($apikey) . "&value=" . urlencode($value));

	header('Content-Description: File Transfer');
	header('Content-Type: application/pdf');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	header('Content-Length: ' . strlen($result));
	header('Content-Disposition: attachment; filename=' . COMPANY_NAME . ' ' . TODAY . '.pdf' );
	 
	echo $result;

	dai();
}

if(validateHttp('secret') != 'iwfyita'){
	include_once('/home/encom/public_html/panel/includes/404.inc.php');
	die();
}

$result 		= ncmExecute('SELECT * FROM transaction WHERE transactionType IN(0,3,5) AND transactionId = ? AND companyId = ? LIMIT 1',[TRANS_ID,COMPANY_ID]);

if(!$result){
	header('location: https://encom.app');
	dai();
}

$contact = ncmExecute('SELECT * FROM contact WHERE contactUID = ? AND companyId = ? LIMIT 1',[$result['customerId'],COMPANY_ID]);

$customerName 	= iftn($contact['contactName'],'Sin cliente');
$customerRuc 		= iftn($contact['contactTIN'],'');

$array = [	
			'total' 			=> formatCurrentNumber($result['transactionTotal'] - $result['transactionDiscount']),
			'totalRaw' 		=> ($result['transactionTotal'] - $result['transactionDiscount']),
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
			'companyName' 	=> COMPANY_NAME,
			'outlet' 			=> $outletName,
			'sale' 				=> json_decode(stripslashes($result['transactionDetails']),true)
		];


$register 		= ncmExecute('SELECT * FROM register WHERE registerId = ? AND companyId = ?',[$result['registerId'],COMPANY_ID]);

$docName = '';
if(in_array($result['transactionType'], [0,3])){
	$docName = L_INVOICE;
}else if($result['transactionType'] == 5){
	$docName = L_RECEIPT;
}


?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Factura <?=COMPANY_NAME;?></title>
  <meta property="og:title" content="Comprobante de <?=COMPANY_NAME;?>" />
  <meta property="og:image" content="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />

  <?php
  loadCDNFiles([],'css');
  ?>

<style type="text/css">
	.svg {
	cursor: pointer;
	filter: invert(.3) sepia(1) saturate(1) hue-rotate(175deg);
	}

	html {
		background-color: #fff !important;
	}
	.hidden-print{
		display: none !important;
	}

	.visible-print{
		display: block !important;
	}

	.md-whiteframe-16dp{
		box-shadow:none !important;
	}

	.col-sm-1, .col-sm-2, .col-sm-3, .col-sm-4, .col-sm-5, .col-sm-6, .col-sm-7, .col-sm-8, .col-sm-9, .col-sm-10, .col-sm-11, .col-sm-12 {
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
  *{
    color: #000000 !important;
  }
  .select2-selection__rendered,.text-white,.select2-selection,.bg-info .select2-selection__rendered{
    color: #000000 !important;  
    border:none !important;
  }
  input,select,textarea{
    border:none !important;
  }
  .wrapper-print{
    padding: 50px !important;
  }
  table,tr,th,td,.bg-white,.bg-default,.gradBgBlue,.table,.bg-light,.panel,.panel-body{
    background:transparent !important; 
  }

  ::-webkit-input-placeholder { /* WebKit browsers */
      color: transparent !important;
  }
  :-moz-placeholder { /* Mozilla Firefox 4 to 18 */
      color: transparent !important;
  }
  ::-moz-placeholder { /* Mozilla Firefox 19+ */
      color: transparent !important;
  }
  :-ms-input-placeholder { /* Internet Explorer 10+ */
      color: transparent !important;
  }

  table.table td, table.table th{
    padding: 10px !important;
  }

  .pagebreak { page-break-before: always; } /* page-break-after works, as well */
  section#content section.scrollable {
    border: none!important;
    height: auto!important;
  }

</style>

</head>
<body class="bg-light lter col-xs-12 no-padder">
	<section class="vbox" id="content">
	<?php
	$z 			= 0;
	$length 	= counts($array);
	$tr 		= '';
	$label 		= 'bg-light';

	if($result['transactionComplete'] == '1'){
		$labelText 	= 'Pagado';
	}else{
		$label 		= 'bg-danger lt';
		$labelText 	= 'Pendiente';
		if(strtotime($result['transactionDueDate']) < time()){
			$labelText 	= 'Vencido';
		}
	}

	if($result['transactionType'] == '3'){
		$condicion 	= L_SA_CREDIT;
		$template 	= '1';
	}else{
		$condicion 	= L_SA_CASH_SALE;
		$template 	= '0';
	}

	if($result['transactionType'] == '7'){
		$label = 'bg-dark';
		$labelText = 'Anulada';
	}

	?>
	<div class="wrapper col-lg-8 col-lg-offset-2 col-xs-12 m-t-md">
		
		<div class="col-xs-6">
			<img height="60" width="60" src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg?<?=date('h')?>" class="img-circle pull-left" />
			<h3 class="h3 pull-left m-t m-l font-bold"><?=COMPANY_NAME;?></h3>
			<div class="col-xs-12 wrapper-sm text-sm text-left" id="companyData">
				<?=($setting['settingBillingName']) ? '<strong>' . $setting['settingBillingName'] . '</strong> ' : ''?>
				<?=($setting['settingRUC']) ? TIN_NAME . ' ' . $setting['settingRUC'] . '<br>' : ''?>

				<?=($setting['settingBillDetail']) ? $setting['settingBillDetail'] . '<br>' : ''?>
				<?=($setting['settingAddress']) ? $setting['settingAddress'] . '<br>' : ''?>
				<?=($setting['settingPhone']) ? '<a href="tel:">' . $setting['settingPhone'] . '</a> | ' : ''?>
				<?=($setting['settingCity']) ? $setting['settingCity'] . '<br>' : ''?>
			</div>
		</div>
 
		<div class="text-right col-xs-6">
			<h4 class="text-u-c font-bold"><?=$docName?></h4>
			<div class="text-sm">
				<?php
				if(in_array($result['transactionType'], [0,3])){
					$docAuthExpires = ($register['registerInvoiceAuthExpiration']) ? explode(' ', $register['registerInvoiceAuthExpiration'])[0] : '';

					echo 	'<b>' . L_SA_TYPE . '</b> ' . $condicion . '<br>' .

							'<b>' . $docName . ' ' . L_NUM . '</b> ' . iftn($result['invoicePrefix'],$register['registerInvoicePrefix']) . leadingZeros($result['invoiceNo'], $register['registerDocsLeadingZeros']) . 
							'<br>' .

							(($register['registerInvoiceAuth']) ? '<b>' . L_INVOICE_AUTH_NO . '</b> ' . $register['registerInvoiceAuth'] . '<br>' : '') .

							(($register['registerInvoiceAuthExpiration']) ? '<b>' . L_VALID_TILL . '</b> ' . $docAuthExpires . '<br>' : '') .

							(($result['transactionDueDate']) ? '<b>' . L_DUE_DATE . '</b> ' . date(L_DATE_FORMAT,strtotime($result['transactionDueDate'])) : '' );


				}else if($result['transactionType'] == 5){
					echo '<b>' . L_NUM . '</b> ' . $result['invoiceNo'] . '<br>' .
					'<b>' . L_DATE . '</b> ' . date(L_DATE_FORMAT,strtotime($result['transactionDate']));
				}
				?>
				
				<br>

			</div>
			
		</div>

		<div class="col-xs-12 m-t wrapper">
			<span class="font-bold"><?=L_DATE?></span> <?=date(L_DATE_FORMAT,strtotime($result['transactionDate']));?>
			<span class="font-bold m-l"><?=L_CUSTOMER?></span> <?=$customerName?> 
			<span class="font-bold m-l"><?=TIN_NAME?></span> <?=$customerRuc?>
		</div>

		<div class="col-xs-12 no-padder clear m-t" id="list">
			<div class="col-xs-12 h4 font-bold text-u-c"><?=L_DETAIL?></div>
			<div class="panel no-border col-xs-12 wrapper m-n">
				<div class="col-xs-12 no-padder">
					<?php
					$taxesValues = [];

					$tr = '';
					
					$tr .= '<tr class="font-bold">';
						$tr .= '<td class="text-right" style="width:10%;">' . L_QTY . '</td>';
						$tr .= '<td>' . L_DESCRIPTION . '</td>';
						$tr .= '<td class="text-right">' . L_PRICE . '</td>';
						$tr .= '<td class="text-right">' . L_TOTAL . '</td>';
					$tr .= '</tr>';

					foreach($array['sale'] as $key => $val){
						if($val['name'] != 'Descuento' && $val['name'] != 'Discount'){
							$name 	= $val['name'];

							$itm 	= ncmExecute('SELECT taxId, itemName FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[dec($val['itemId']),COMPANY_ID]);

							if(!validity($name)){
								$name = $itm['itemName'];
							}

							$count 	= $val['count'];
							$price 	= formatCurrentNumber($val['price']);
							$total 	= formatCurrentNumber($val['total']);
							$tax 	= getTaxOfPrice( getTaxValue($itm['taxId']), $val['total'] );

							if($val['type'] == 'inCombo' || $val['type'] == 'inComboAddons'){
								$name 	= str_replace('u21b3','\u21b3',$name);
								$name  	= '<span class="text-muted">' . json_decode('"' . $name . '"') . '</span>';
								$count = '';
								$total = '';
							}else if($val['type'] == 'combo'){
							}

							if($taxesValues[$itm['taxId']]){
								$taxesValues[$itm['taxId']] += $tax;
							}else{
								$taxesValues[$itm['taxId']] = $tax;
							}

							$tr .= '<tr>';
								$tr .= '<td class="text-right">' . $count . '</td>';
								$tr .= '<td>' . $name . '</td>';
								$tr .= '<td class="text-right">' . $price . '</td>';
								$tr .= '<td class="text-right">' . $total . '</td>';
							$tr .= '</tr>';
						}
					}
					?>
					<table class="table text-left" id="saleDetails">
						<?=$tr;?>
						<?php
						if($array['subtotal'] > 0){
						?>
						<tr class="text-success">
							<td></td>
							<td></td>
							<td class="text-right font-bold"><?=L_SUBTOTAL?></td>
							<td class="font-bold text-u-c text-right"><?=$array['subtotal']?></td>
						</tr>
						<?php
						}
						?>
						<?php
						if($array['discount'] > 0){
						?>
						<tr>
							<td></td>
							<td></td>
							<td class="text-right font-bold"><?=L_DISCOUNT?></td>
							<td class="font-bold text-u-c text-right">-<?=$array['discount']?></td>
						</tr>
						<?php
						}
						?>
						<?php
						if(in_array($result['transactionType'], [0,3])){
						?>
						<tr>
							<td></td>
							<td></td>
							<td class="text-right font-bold"><?=L_TOTAL?> <?=$setting['settingTaxName']?></td>
							<td class="font-bold text-u-c text-right"><?=$array['tax']?></td>
						</tr>
						<?php
						}
						?>
						
						<tr style="">
							<td colspan="2">
								<div class="text-u-c">
									<?php
									$V 			= new EnLetras();
									$totalRaw 	= (DECIMAL == 'no') ? (int)$array['totalRaw'] : (float)$array['totalRaw'];
	 								echo '<div class="text-xs font-bold">' . L_ITS . '</div> ' . CURRENCY . ' ' . $V->ValorEnLetras($totalRaw,"") . '.-';
									?>
								</div>
							</td>
							<td class="text-right font-bold text-u-c h3" style="color:#fff!important;background-color:#000!important;"> <?=($result['transactionType'] == 3) ? L_BALANCE_DUE : L_TOTAL?></td>
							<td class="font-bold text-u-c text-right h3" style="color:#fff!important;background-color:#000!important;"><?=CURRENCY?> <?=$array['total']?></td>
						</tr>
					</table>
				</div>

				<div class="col-sm-6 col-xs-12 no-padder">
					<div class="col-xs-12 no-padder m-b">
						<?php
						$url = urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '&pdf=1');
						?>
						<img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?=$url?>" class="pull-left m-r">
						<em>

						<?php 
						echo validateHttp('isCopy') ? L_COPY : L_ORIGINAL;
						$modData = json_decode($_modules['digitalInvoiceData'],true);
						echo ' - ' . $modData['disclousure'];
						?>
						</em>
					</div>
					

					<div class="col-xs-12 no-padder">
					    <div class="font-bold">
					    	<?=L_POWERED_BY?> <a href="https://encom.app">WWW.ENCOM.APP</a>
					    </div>
					</div>
				</div>

				<div class="col-sm-6 col-xs-12">
					<div class="col-xs-12 no-padder">
						<div class="col-xs-12 text-u-c font-bold m-b"><?=L_TAXES?></div>
						<table class="table text-sm text-left">
						<?php
						$taxes 	= ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'tax' AND companyId = ? ORDER BY taxonomyId DESC LIMIT 20",[COMPANY_ID],false,true);

						if($taxes){
				        	while (!$taxes->EOF){
				            	$taxess   	= $taxes->fields;
				            	$taxName 	= $taxess['taxonomyName'] . '%';

				            	if($taxesValues[$taxess['taxonomyId']]){
				            	?>
				            	<tr>
									<td><?=$taxName;?></td>
									<td class="text-right"><?=formatCurrentNumber($taxesValues[$taxess['taxonomyId']])?></td>
								</tr>
				            	<?php
				            	}
				        		$taxes->MoveNext(); 
				        	}
				        }
						?>
						</table>
					</div>

					<?php
					if($result['transactionType'] == '0'){
					?>
					<div class="col-xs-12 no-padder">
						<div class="col-xs-12 text-u-c font-bold m-b"><?=L_PAYMENT_METHODS?></div>
						<table class="table text-sm text-left">
						<?php
						$paymentType 	= json_decode($array['payment'],true);
						
						if(validity($paymentType)){
							foreach($paymentType as $key => $val){
							?>
								<tr>
									<td><?=getPaymentMethodName($val['type'])?></td>
									<td class="text-right"><?=formatCurrentNumber($val['price'])?></td>
								</tr>
							<?php
							}
						}
						?>
						</table>
					</div>
					<?php
					}
					?>
				</div>

			</div>
		</div>

		
	</div>

	</section>
	<script src="https://panel.encom.app/scripts/dpb.min.js"></script>

	<script type="text/javascript">
		<?php
		if(COMPANY_ID == '4055'){
			$jsonSale = [
							
							"outletName" 						=> $array['outlet'],
							"companyName" 					=> $array['companyName'],
							"company_billing_name" 	=> $setting['settingBillingName'],
							"companyTIN" 						=> $setting['settingRUC'],
							"companyAddress" 				=> $setting['settingAddress'],
							"companyEmail" 					=> $setting['settingEmail'],
							"companyPhone" 					=> $setting['settingPhone'],
							"total" 								=> $array['total'],
							"rawtotal" 							=> $array['totalRaw'],
							"subtotal" 							=> $array['subtotal'],
							"discount" 							=> $array['discount'],
							"tax" 									=> $array['tax'],
							"customerName" 					=> $contact['contactName'],
							"customerFullName" 			=> $contact['contactSecondName'],
							"customerTIN" 					=> $contact['contactTIN'],
							"customerCI" 						=> $contact['contactCI'],
							"customerAddress" 			=> $contact['contactAddress'],
							"customerEmail" 				=> $contact['contactEmail'],
							"customerPhone" 				=> $contact['contactPhone'],
							"customerCity" 					=> $contact['contactCity'],
							"customerLocation" 			=> $contact['contactLocation'],
							"note" 									=> $result['transactionNote'],
							"date" 									=> date(L_DATE_FORMAT,strtotime($array['date'])),
							"dueDate" 							=> date(L_DATE_FORMAT,strtotime($array['expires'])),
							"type" 									=> $result['transactionType'],
							"typeDocument" 					=> $condicion,
							"authExpiration" 				=> $docAuthExpires,
							"invoiceAuthNo" 				=> $register['registerInvoiceAuth'],
							"invoicePrefix" 				=> $register['registerInvoicePrefix'],
							"invoiceSufix" 					=> $result['invoiceSufix'],
							"invoiceNo" 						=> leadingZeros($result['invoiceNo'], $register['registerDocsLeadingZeros']),
							"sale" 									=> $array['sale']
						];
		?>



		$(document).ready(function(){
			var saleArray 				= <?=json_encode($jsonSale);?>;
			var template 					= <?=$_template['template']?>;
			var receiptConf 			= {};
	    receiptConf.isTicket  = false;
	    receiptConf.isHTML    = false;
	    receiptConf.chars     = 0;
	    receiptConf.space     = ' ';
	    receiptConf.EOL       = '<br>';

	    /*if(isTicket(conf.page_size)){
	      receiptConf.isTicket  = conf.page_size;
	      receiptConf.isHTML    = true;
	      receiptConf.space     = ' ';

	      if(conf.page_size == 'receipt57'){
	        receiptConf.chars     = 35;
	      }else if(conf.page_size == 'receipt76'){
	        receiptConf.chars     = 42;
	      }else{
	        receiptConf.chars     = 50;
	      }
	    }*/

	    var rows      = ducumentPrintBuilder.build(template,saleArray,receiptConf);

	    if(!receiptConf.isTicket){
	      var html    = '<html><head><meta charset="utf-8"> <style type="text/css" media="print"> @page{size:auto;margin:0;padding:0;border:0;}*{padding: 0; margin: 0;border:0;font-family:'+window.fontFamily+'!important;font-size:'+window.fontSize+'!important; color: black;}</style></head><body>' +rows+ '</body></html>';
	    }else{
	      if(receiptConf.isHTML){
	        var html  = '<html><head><meta charset="utf-8"> <style type="text/css" media="print"> @page{size:auto;margin:0;padding:0;border:0;}pre,*{padding: 0; margin: 0;border:0;font-family:'+window.fontFamily+'!important; font-size:'+window.fontSize+'!important; text-transform: uppercase!important;}</style></head><body><pre style="margin-left:'+window.receipt_left_margin+'mm;">' +rows+ '</pre></body></html>';
	      }else{
	        var html = rows;
	      }
	    }

	    $('body').html(html);



		});
		<?php 
		}
		?>
	</script>
	
</body>
</html>

<?php
dai();
?>
